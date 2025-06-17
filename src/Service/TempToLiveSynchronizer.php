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
     * This method manages its own transaction for atomicity.
     *
     * @param TableSyncConfigDTO $config The configuration for the synchronization.
     * @param int $currentBatchRevisionId The current batch revision ID.
     * @param SyncReportDTO $report The report to populate with synchronization results.
     * @return void
     * @throws TableSyncerException|\Doctrine\DBAL\Exception
     */
    public function synchronize(TableSyncConfigDTO $config, int $currentBatchRevisionId, SyncReportDTO $report): void
    {
        $this->logger->debug('Synchronizing temp table to live table (transaction managed internally)', [
            'batchRevisionId' => $currentBatchRevisionId,
            'liveTable'       => $config->targetLiveTableName,
            'tempTable'       => $config->targetTempTableName
        ]);

        $targetConn = $config->targetConnection;
        $transactionStartedByThisMethod = false;
        $meta = $config->metadataColumns;
        $liveTable = $targetConn->quoteIdentifier($config->targetLiveTableName);
        $tempTable = $targetConn->quoteIdentifier($config->targetTempTableName);

        try {
            // Start transaction if one is not already active
            if (!$targetConn->isTransactionActive()) {
                $targetConn->beginTransaction();
                $transactionStartedByThisMethod = true;
                $this->logger->debug('Transaction started within TempToLiveSynchronizer for live table synchronization.');
            }

            // --- A. Check if the live table is empty ---
            $countResult = $targetConn->fetchOne("SELECT COUNT(*) FROM {$liveTable}");
            $countInt = is_numeric($countResult) ? (int)$countResult : 0;

            if ($countInt === 0) {
                $this->logger->info("Live table '{$config->targetLiveTableName}' is empty, performing initial bulk import from temp table '{$config->targetTempTableName}'.");

                $colsToInsertLive = array_unique(array_merge(
                    $config->getTargetPrimaryKeyColumns(),
                    $config->getTargetDataColumns(),
                    [$meta->contentHash, $meta->createdAt, $meta->createdRevisionId, $meta->lastModifiedRevisionId]
                ));
                $quotedColsToInsertLive = array_map(fn($c) => $targetConn->quoteIdentifier($c), $colsToInsertLive);

                $colsToSelectTemp = array_unique(array_merge(
                    $config->getTargetPrimaryKeyColumns(),
                    $config->getTargetDataColumns(),
                    [$meta->contentHash, $meta->createdAt]
                ));
                $quotedColsToSelectTemp = array_map(fn($c) => $tempTable . "." . $targetConn->quoteIdentifier($c), $colsToSelectTemp);

                if (count($quotedColsToSelectTemp) + 2 !== count($quotedColsToInsertLive)) {
                    throw new TableSyncerException("Configuration error: Column count mismatch for initial insert into live table.");
                }

                $sqlInitialInsert = "INSERT INTO {$liveTable} (" . implode(', ', $quotedColsToInsertLive) . ") "
                    . "SELECT " . implode(', ', $quotedColsToSelectTemp) . ", ?, ? " // createdRevisionId and lastModifiedRevisionId
                    . "FROM {$tempTable}";

                $affectedRows = $targetConn->executeStatement($sqlInitialInsert, [$currentBatchRevisionId, $currentBatchRevisionId]);
                $report->initialInsertCount = (int)$affectedRows;
                $report->addLogMessage("Initial import: {$report->initialInsertCount} rows inserted into '{$config->targetLiveTableName}'.");

                if ($transactionStartedByThisMethod && $targetConn->isTransactionActive()) {
                    $targetConn->commit();
                    $this->logger->debug('Transaction committed within TempToLiveSynchronizer after initial import.');
                }
                return; // Exit after initial import
            }

            // --- Standard Sync Logic (Live table is not empty) ---
            $joinConditions = [];
            foreach ($config->getTargetPrimaryKeyColumns() as $keyCol) {
                $quotedKeyCol = $targetConn->quoteIdentifier($keyCol);
                $joinConditions[] = "{$liveTable}.{$quotedKeyCol} = {$tempTable}.{$quotedKeyCol}";
            }
            $joinConditionStr = implode(' AND ', $joinConditions);
            if (empty($joinConditionStr)) {
                throw new TableSyncerException("Configuration error: No primary key join conditions for synchronization.");
            }

            // --- B. Handle Updates ---
            $setClausesForUpdate = [];
            $dataColsForUpdate = array_unique(array_merge($config->getTargetDataColumns(), [$meta->contentHash]));
            foreach ($dataColsForUpdate as $col) {
                $qCol = $targetConn->quoteIdentifier($col);
                $setClausesForUpdate[] = "{$liveTable}.{$qCol} = {$tempTable}.{$qCol}";
            }
            $setClausesForUpdate[] = "{$liveTable}." . $targetConn->quoteIdentifier($meta->updatedAt) . " = CURRENT_TIMESTAMP";
            $setClausesForUpdate[] = "{$liveTable}." . $targetConn->quoteIdentifier($meta->lastModifiedRevisionId) . " = ?";

            $sqlUpdate = "UPDATE {$liveTable} "
                . "INNER JOIN {$tempTable} ON {$joinConditionStr} "
                . "SET " . implode(', ', $setClausesForUpdate) . " "
                . "WHERE {$liveTable}." . $targetConn->quoteIdentifier($meta->contentHash) . " <> {$tempTable}." . $targetConn->quoteIdentifier($meta->contentHash);

            $affectedRowsUpdate = $targetConn->executeStatement($sqlUpdate, [$currentBatchRevisionId]);
            $report->updatedCount = (int)$affectedRowsUpdate;
            $report->addLogMessage("Rows updated in '{$config->targetLiveTableName}': {$report->updatedCount}.");

            // --- C. Handle Deletes ---
            $targetPkColumns = $config->getTargetPrimaryKeyColumns();
            $deletionsWereLogged = false;

            if (empty($targetPkColumns)) {
                $this->logger->warning("Cannot perform deletes: No target primary key columns defined.");
            } else {
                // --- C.1 Log deletions if enabled (this is the expensive identification step)
                if ($config->enableDeletionLogging && !empty($config->targetDeletedLogTableName)) {
                    $this->logger->debug("Deletion logging enabled. Identifying rows to be deleted from '{$config->targetLiveTableName}'.");

                    $deletedLogTableIdentifier = $targetConn->quoteIdentifier($config->targetDeletedLogTableName);
                    $liveTableIdentifierForLog = $targetConn->quoteIdentifier($config->targetLiveTableName) . ' lt';
                    $tempTableIdentifierForLog = $targetConn->quoteIdentifier($config->targetTempTableName) . ' tt';
                    $liveSyncerIdCol = $targetConn->quoteIdentifier($meta->id);
                    $logJoinConditions = [];
                    foreach ($targetPkColumns as $keyCol) {
                        $quotedKeyCol = $targetConn->quoteIdentifier($keyCol);
                        $logJoinConditions[] = "lt.{$quotedKeyCol} = tt.{$quotedKeyCol}";
                    }
                    $logJoinConditionStr = implode(' AND ', $logJoinConditions);
                    $tempTableBusinessPkColForNullCheck = 'tt.' . $targetConn->quoteIdentifier($targetPkColumns[0]);
                    $logTableInsertCols = [
                        $targetConn->quoteIdentifier('deleted_syncer_id'),
                        $targetConn->quoteIdentifier('deleted_at_revision_id'),
                        $targetConn->quoteIdentifier('deletion_timestamp')
                    ];

                    $sqlLogDeletes = "INSERT INTO {$deletedLogTableIdentifier} (" . implode(', ', $logTableInsertCols) . ") "
                        . "SELECT lt.{$liveSyncerIdCol}, ?, CURRENT_TIMESTAMP "
                        . "FROM {$liveTableIdentifierForLog} "
                        . "LEFT JOIN {$tempTableIdentifierForLog} ON {$logJoinConditionStr} "
                        . "WHERE {$tempTableBusinessPkColForNullCheck} IS NULL";

                    try {
                        $affectedRowsLog = $targetConn->executeStatement($sqlLogDeletes, [$currentBatchRevisionId]);
                        $report->loggedDeletionsCount = (int)$affectedRowsLog;
                        if ($report->loggedDeletionsCount > 0) {
                            $deletionsWereLogged = true;
                        }
                        $report->addLogMessage("Deletions logged to '{$config->targetDeletedLogTableName}': {$report->loggedDeletionsCount}.");
                    } catch (\Throwable $e) {
                        throw new TableSyncerException("Failed to log deletions: " . $e->getMessage(), 0, $e);
                    }
                }

                // --- C.2 Perform the actual delete operation
                $sqlDelete = '';
                $paramsDelete = [];

                if ($deletionsWereLogged) {
                    // OPTIMIZED PATH: Use the log table for a fast delete by joining on indexed PKs
                    $this->logger->debug('Executing optimized delete using the deletion log table.');
                    $deletedLogTableIdentifier = $targetConn->quoteIdentifier($config->targetDeletedLogTableName);
                    $liveTableForDelete = $liveTable . ' live'; // alias
                    $logTableForDelete = $deletedLogTableIdentifier . ' log'; // alias

                    $sqlDelete = "DELETE live FROM {$liveTableForDelete} "
                        . "INNER JOIN {$logTableForDelete} "
                        . "ON live.{$targetConn->quoteIdentifier($meta->id)} = log.{$targetConn->quoteIdentifier('deleted_syncer_id')} "
                        . "WHERE log.{$targetConn->quoteIdentifier('deleted_at_revision_id')} = ?";

                    $paramsDelete = [$currentBatchRevisionId];
                } else {
                    // FALLBACK PATH: Logging is disabled or nothing was logged, use original (slower) method
                    $this->logger->debug('Executing original delete using LEFT JOIN (logging disabled or no deletions found).');
                    $tempTablePkColForNullCheck = $tempTable . "." . $targetConn->quoteIdentifier($targetPkColumns[0]);
                    $sqlDelete = "DELETE {$liveTable} FROM {$liveTable} "
                        . "LEFT JOIN {$tempTable} ON {$joinConditionStr} "
                        . "WHERE {$tempTablePkColForNullCheck} IS NULL";
                }

                if (!empty($sqlDelete)) {
                    $this->logger->debug('Executing delete SQL for live table', ['sql' => $sqlDelete]);
                    $affectedRowsDelete = $targetConn->executeStatement($sqlDelete, $paramsDelete);
                    $report->deletedCount = (int)$affectedRowsDelete;
                    $report->addLogMessage("Rows deleted from '{$config->targetLiveTableName}': {$report->deletedCount}.");

                    if ($config->enableDeletionLogging && $report->loggedDeletionsCount !== $report->deletedCount) {
                        $this->logger->warning('Logged deletion count does not match actual deleted count.', [
                            'logged_count' => $report->loggedDeletionsCount,
                            'deleted_count' => $report->deletedCount,
                        ]);
                    }
                }
            }

            // --- D. Handle Inserts ---
            $colsToInsertLiveForNew = array_unique(array_merge(
                $config->getTargetPrimaryKeyColumns(),
                $config->getTargetDataColumns(),
                [$meta->contentHash, $meta->createdAt, $meta->createdRevisionId, $meta->lastModifiedRevisionId]
            ));
            $quotedColsToInsertLiveForNew = array_map(fn($c) => $targetConn->quoteIdentifier($c), $colsToInsertLiveForNew);

            $colsToSelectTempForNew = array_unique(array_merge(
                $config->getTargetPrimaryKeyColumns(),
                $config->getTargetDataColumns(),
                [$meta->contentHash, $meta->createdAt]
            ));
            $quotedColsToSelectTempForNew = array_map(fn($c) => $tempTable . "." . $targetConn->quoteIdentifier($c), $colsToSelectTempForNew);

            if (count($quotedColsToSelectTempForNew) + 2 !== count($quotedColsToInsertLiveForNew)) {
                throw new TableSyncerException("Configuration error: Column count mismatch for new inserts into live table.");
            }

            $liveTablePkColForNullCheck = $liveTable . "." . $targetConn->quoteIdentifier($targetPkColumns[0]);
            $sqlInsert = "INSERT INTO {$liveTable} (" . implode(', ', $quotedColsToInsertLiveForNew) . ") "
                . "SELECT " . implode(', ', $quotedColsToSelectTempForNew) . ", ?, ? " // createdRevisionId and lastModifiedRevisionId
                . "FROM {$tempTable} "
                . "LEFT JOIN {$liveTable} ON {$joinConditionStr} "
                . "WHERE {$liveTablePkColForNullCheck} IS NULL";

            $affectedRowsInsert = $targetConn->executeStatement($sqlInsert, [$currentBatchRevisionId, $currentBatchRevisionId]);
            $report->insertedCount = (int)$affectedRowsInsert;
            $report->addLogMessage("New rows inserted into '{$config->targetLiveTableName}': {$report->insertedCount}.");

            // Commit transaction if we started it
            if ($transactionStartedByThisMethod && $targetConn->isTransactionActive()) {
                $targetConn->commit();
                $this->logger->debug('Transaction committed within TempToLiveSynchronizer for live table synchronization.');
            }
        } catch (\Throwable $e) {
            // Rollback transaction if we started it
            if ($transactionStartedByThisMethod && $targetConn->isTransactionActive()) {
                try {
                    $targetConn->rollBack();
                    $this->logger->warning('Transaction rolled back within TempToLiveSynchronizer due to an error.', ['exception_message' => $e->getMessage(), 'exception_class' => get_class($e)]);
                } catch (\Throwable $rollbackException) {
                    $this->logger->error('Failed to roll back transaction in TempToLiveSynchronizer: ' . $rollbackException->getMessage());
                }
            } else if ($targetConn->isTransactionActive() && !$transactionStartedByThisMethod) {
                $this->logger->warning('Error in TempToLiveSynchronizer, but transaction was managed externally and remains active.', ['exception_message' => $e->getMessage()]);
            }
            // Re-throw the original exception
            throw $e;
        }
    }
}