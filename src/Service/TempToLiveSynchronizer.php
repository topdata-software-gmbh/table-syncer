<?php

declare(strict_types=1);

namespace TopdataSoftwareGmbh\TableSyncer\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TopdataSoftwareGmbh\TableSyncer\DTO\TableSyncConfigDTO;
use TopdataSoftwareGmbh\TableSyncer\DTO\SyncReportDTO;
use TopdataSoftwareGmbh\TableSyncer\Exception\TableSyncerException;

/**
 * Service responsible for synchronizing data from temporary table to live table.
 */
class TempToLiveSynchronizer
{
    private readonly LoggerInterface $logger;

    public function __construct(
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Synchronizes the temp table to the live table.
     * Performs set-based operations for updating, deleting, and inserting records.
     *
     * @param TableSyncConfigDTO $config The configuration for the synchronization.
     * @param int $currentBatchRevisionId The current batch revision ID.
     * @param SyncReportDTO $report The report to populate with synchronization results.
     * @return void
     * @throws TableSyncerException
     */
    public function synchronize(TableSyncConfigDTO $config, int $currentBatchRevisionId, SyncReportDTO $report): void
    {
        $this->logger->debug('Synchronizing temp table to live table', [
            'batchRevisionId' => $currentBatchRevisionId,
            'liveTable'       => $config->targetLiveTableName,
            'tempTable'       => $config->targetTempTableName
        ]);

        $targetConn = $config->targetConnection;
        $meta = $config->metadataColumns;
        $liveTable = $targetConn->quoteIdentifier($config->targetLiveTableName);
        $tempTable = $targetConn->quoteIdentifier($config->targetTempTableName);

        // --- A. Check if the live table is empty ---
        $countResult = $targetConn->fetchOne("SELECT COUNT(*) FROM {$liveTable}");
        $countInt = is_numeric($countResult) ? (int)$countResult : 0;

        if ($countInt === 0) {
            $this->logger->info("Live table '{$config->targetLiveTableName}' is empty, performing initial bulk import from temp table '{$config->targetTempTableName}'.");

            // Columns to insert into the live table: Mapped PKs, Mapped Data, Syncer's Hash, Syncer's CreatedAt (from temp), and new BatchRevision
            $colsToInsertLive = array_unique(array_merge(
                $config->getTargetPrimaryKeyColumns(),
                $config->getTargetDataColumns(),
                [$meta->contentHash, $meta->createdAt, $meta->batchRevision]
            ));
            $quotedColsToInsertLive = array_map(fn($c) => $targetConn->quoteIdentifier($c), $colsToInsertLive);

            // Columns to select from the temp table: Mapped PKs, Mapped Data, Syncer's Hash, Syncer's CreatedAt
            $colsToSelectTemp = array_unique(array_merge(
                $config->getTargetPrimaryKeyColumns(),
                $config->getTargetDataColumns(),
                [$meta->contentHash, $meta->createdAt]
            ));
            $quotedColsToSelectTemp = array_map(fn($c) => $tempTable . "." . $targetConn->quoteIdentifier($c), $colsToSelectTemp);

            if (empty($quotedColsToSelectTemp) || empty($quotedColsToInsertLive)) {
                $this->logger->warning("Cannot perform initial insert: column list for SELECT or INSERT is empty.", [
                    'select_cols_count' => count($quotedColsToSelectTemp),
                    'insert_cols_count' => count($quotedColsToInsertLive),
                ]);
                return;
            }
            if (count($quotedColsToSelectTemp) + 1 !== count($quotedColsToInsertLive)) {
                $this->logger->error("Column count mismatch for initial insert.", [
                    'select_cols' => $quotedColsToSelectTemp,
                    'insert_cols' => $quotedColsToInsertLive,
                ]);
                throw new TableSyncerException("Configuration error: Column count mismatch for initial insert into live table.");
            }

            $sqlInitialInsert = "INSERT INTO {$liveTable} (" . implode(', ', $quotedColsToInsertLive) . ") "
                . "SELECT " . implode(', ', $quotedColsToSelectTemp) . ", ? " // ? for batch_revision
                . "FROM {$tempTable}";

            $this->logger->debug('Executing initial insert SQL for live table', ['sql' => $sqlInitialInsert]);
            $affectedRows = $targetConn->executeStatement($sqlInitialInsert, [$currentBatchRevisionId]);
            $report->initialInsertCount = (int)$affectedRows;
            $report->addLogMessage("Initial import: {$report->initialInsertCount} rows inserted into '{$config->targetLiveTableName}'.");
            return;
        }

        // --- Standard Sync Logic (Live table is not empty) ---
        $joinConditions = [];
        foreach ($config->getTargetPrimaryKeyColumns() as $keyCol) {
            $quotedKeyCol = $targetConn->quoteIdentifier($keyCol);
            $joinConditions[] = "{$liveTable}.{$quotedKeyCol} = {$tempTable}.{$quotedKeyCol}";
        }
        $joinConditionStr = implode(' AND ', $joinConditions);
        if (empty($joinConditionStr)) {
            $this->logger->error("Cannot synchronize: No primary key join conditions defined. Check TableSyncConfigDTO.primaryKeyColumnMap.");
            throw new TableSyncerException("Configuration error: No primary key join conditions for synchronization.");
        }
        $this->logger->debug('Join condition for sync operations', ['condition' => $joinConditionStr]);

        // --- B. Handle Updates ---
        $setClausesForUpdate = [];
        // Data columns to update + contentHash
        $dataColsForUpdate = array_unique(array_merge($config->getTargetDataColumns(), [$meta->contentHash]));
        foreach ($dataColsForUpdate as $col) {
            $qCol = $targetConn->quoteIdentifier($col);
            $setClausesForUpdate[] = "{$liveTable}.{$qCol} = {$tempTable}.{$qCol}";
        }
        $setClausesForUpdate[] = "{$liveTable}." . $targetConn->quoteIdentifier($meta->updatedAt) . " = CURRENT_TIMESTAMP";
        $setClausesForUpdate[] = "{$liveTable}." . $targetConn->quoteIdentifier($meta->batchRevision) . " = ?";

        if (empty($dataColsForUpdate)) {
            $this->logger->warning("No data columns configured for update. Only metadata (updatedAt, batchRevision) will be updated on hash mismatch.");
        }

        $contentHashLiveCol = $targetConn->quoteIdentifier($meta->contentHash);
        $contentHashTempCol = $tempTable . "." . $targetConn->quoteIdentifier($meta->contentHash);

        $sqlUpdate = "UPDATE {$liveTable} "
            . "INNER JOIN {$tempTable} ON {$joinConditionStr} "
            . "SET " . implode(', ', $setClausesForUpdate) . " "
            . "WHERE {$contentHashLiveCol} <> {$contentHashTempCol}";

        $this->logger->debug('Executing update SQL for live table', ['sql' => $sqlUpdate]);
        $affectedRowsUpdate = $targetConn->executeStatement($sqlUpdate, [$currentBatchRevisionId]);
        $report->updatedCount = (int)$affectedRowsUpdate;
        $report->addLogMessage("Rows updated in '{$config->targetLiveTableName}' due to hash mismatch: {$report->updatedCount}.");

        // --- C. Handle Deletes ---
        $targetPkColumns = $config->getTargetPrimaryKeyColumns();
        if (empty($targetPkColumns)) {
            $this->logger->warning("Cannot perform deletes: No target primary key columns defined for LEFT JOIN NULL check.");
        } else {
            $deletePkColForNullCheck = $targetConn->quoteIdentifier($targetPkColumns[0]);
            $sqlDelete = "DELETE {$liveTable} FROM {$liveTable} "
                . "LEFT JOIN {$tempTable} ON {$joinConditionStr} "
                . "WHERE {$tempTable}.{$deletePkColForNullCheck} IS NULL";

            $this->logger->debug('Executing delete SQL for live table', ['sql' => $sqlDelete]);
            $affectedRowsDelete = $targetConn->executeStatement($sqlDelete);
            $report->deletedCount = (int)$affectedRowsDelete;
            $report->addLogMessage("Rows deleted from '{$config->targetLiveTableName}' (not in source/temp): {$report->deletedCount}.");
        }

        // --- D. Handle Inserts ---
        $colsToInsertLive = array_unique(array_merge(
            $config->getTargetPrimaryKeyColumns(),
            $config->getTargetDataColumns(),
            [$meta->contentHash, $meta->createdAt, $meta->batchRevision]
        ));
        $quotedColsToInsertLive = array_map(fn($c) => $targetConn->quoteIdentifier($c), $colsToInsertLive);

        $colsToSelectTemp = array_unique(array_merge(
            $config->getTargetPrimaryKeyColumns(),
            $config->getTargetDataColumns(),
            [$meta->contentHash, $meta->createdAt]
        ));
        $quotedColsToSelectTemp = array_map(fn($c) => $tempTable . "." . $targetConn->quoteIdentifier($c), $colsToSelectTemp);

        if (empty($quotedColsToSelectTemp) || empty($quotedColsToInsertLive)) {
            $this->logger->warning("Cannot perform new inserts: column list for SELECT or INSERT is empty.", [
                'select_cols_count' => count($quotedColsToSelectTemp),
                'insert_cols_count' => count($quotedColsToInsertLive),
            ]);
        } elseif (count($quotedColsToSelectTemp) + 1 !== count($quotedColsToInsertLive)) {
            $this->logger->error("Column count mismatch for new inserts.", [
                'select_cols' => $quotedColsToSelectTemp,
                'insert_cols' => $quotedColsToInsertLive,
            ]);
            throw new TableSyncerException("Configuration error: Column count mismatch for new inserts into live table.");
        } else {
            $insertPkColForNullCheck = $targetConn->quoteIdentifier($targetPkColumns[0]);
            $sqlInsert = "INSERT INTO {$liveTable} (" . implode(', ', $quotedColsToInsertLive) . ") "
                . "SELECT " . implode(', ', $quotedColsToSelectTemp) . ", ? " // ? for batch_revision
                . "FROM {$tempTable} "
                . "LEFT JOIN {$liveTable} ON {$joinConditionStr} "
                . "WHERE {$liveTable}.{$insertPkColForNullCheck} IS NULL";

            $this->logger->debug('Executing insert SQL for new rows in live table', ['sql' => $sqlInsert]);
            $affectedRowsInsert = $targetConn->executeStatement($sqlInsert, [$currentBatchRevisionId]);
            $report->insertedCount = (int)$affectedRowsInsert;
            $report->addLogMessage("New rows inserted into '{$config->targetLiveTableName}': {$report->insertedCount}.");
        }
    }
}
