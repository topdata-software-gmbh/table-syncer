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
                    $this->logger->warning("Cannot perform initial insert: column list for SELECT or INSERT is empty.", [
                        'select_cols_count' => count($quotedColsToSelectTemp),
                        'insert_cols_count' => count($quotedColsToInsertLive),
                    ]);
                    // If we return here, we need to ensure the transaction is handled.
                    // It's better to let it flow to the commit/rollback at the end of the try block.
                    // Or, if this is the ONLY operation, commit here and then return.
                    // For now, let it flow. If it's an actual problem (e.g. no columns), an exception should have been thrown earlier or it's a config issue.
                } elseif (count($quotedColsToSelectTemp) + 1 !== count($quotedColsToInsertLive)) { // Note: elseif to prevent re-evaluation if first was true
                    $this->logger->error("Column count mismatch for initial insert.", [
                        'select_cols' => $quotedColsToSelectTemp,
                        'insert_cols' => $quotedColsToInsertLive,
                    ]);
                    throw new TableSyncerException("Configuration error: Column count mismatch for initial insert into live table.");
                } else { // Only execute if column counts are valid
                    $sqlInitialInsert = "INSERT INTO {$liveTable} (" . implode(', ', $quotedColsToInsertLive) . ") "
                        . "SELECT " . implode(', ', $quotedColsToSelectTemp) . ", ? " // ? for batch_revision
                        . "FROM {$tempTable}";

                    $this->logger->debug('Executing initial insert SQL for live table', ['sql' => $sqlInitialInsert]);
                    $affectedRows = $targetConn->executeStatement($sqlInitialInsert, [$currentBatchRevisionId]);
                    $report->initialInsertCount = (int)$affectedRows;
                    $report->addLogMessage("Initial import: {$report->initialInsertCount} rows inserted into '{$config->targetLiveTableName}'.");
                }
                // After initial import, the subsequent standard sync logic (updates, deletes, inserts) is typically skipped for this run.
                // So, we should commit if we started a transaction and then return.
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
                $this->logger->error("Cannot synchronize: No primary key join conditions defined. Check TableSyncConfigDTO.primaryKeyColumnMap.");
                throw new TableSyncerException("Configuration error: No primary key join conditions for synchronization.");
            }
            $this->logger->debug('Join condition for sync operations', ['condition' => $joinConditionStr]);

            // --- B. Handle Updates ---
            $setClausesForUpdate = [];
            $dataColsForUpdate = array_unique(array_merge($config->getTargetDataColumns(), [$meta->contentHash]));
            foreach ($dataColsForUpdate as $col) {
                $qCol = $targetConn->quoteIdentifier($col);
                $setClausesForUpdate[] = "{$liveTable}.{$qCol} = {$tempTable}.{$qCol}";
            }
            $setClausesForUpdate[] = "{$liveTable}." . $targetConn->quoteIdentifier($meta->updatedAt) . " = CURRENT_TIMESTAMP";
            $setClausesForUpdate[] = "{$liveTable}." . $targetConn->quoteIdentifier($meta->batchRevision) . " = ?";

            if (empty($dataColsForUpdate)) { // Note: $dataColsForUpdate will include at least $meta->contentHash if configured
                $this->logger->warning("No data columns configured for update beyond metadata. Only metadata (updatedAt, batchRevision, contentHash) will be updated on hash mismatch.");
            }

            // FIX: Qualify ambiguous column in WHERE clause
            $liveTableContentHashForWhere = $liveTable . "." . $targetConn->quoteIdentifier($meta->contentHash);
            $tempTableContentHashForWhere = $tempTable . "." . $targetConn->quoteIdentifier($meta->contentHash);

            $sqlUpdate = "UPDATE {$liveTable} "
                . "INNER JOIN {$tempTable} ON {$joinConditionStr} "
                . "SET " . implode(', ', $setClausesForUpdate) . " "
                . "WHERE {$liveTableContentHashForWhere} <> {$tempTableContentHashForWhere}";

            $this->logger->debug('Executing update SQL for live table', ['sql' => $sqlUpdate]);
            $affectedRowsUpdate = $targetConn->executeStatement($sqlUpdate, [$currentBatchRevisionId]);
            $report->updatedCount = (int)$affectedRowsUpdate;
            $report->addLogMessage("Rows updated in '{$config->targetLiveTableName}' due to hash mismatch: {$report->updatedCount}.");

            // --- C. Handle Deletes ---
            $targetPkColumns = $config->getTargetPrimaryKeyColumns(); // This is already available
            if (empty($targetPkColumns)) {
                $this->logger->warning("Cannot perform deletes: No target primary key columns defined for LEFT JOIN NULL check. This implies a configuration issue if deletes are expected.");
            } else {
                // Ensure the column for NULL check is from the TEMP table after the LEFT JOIN
                $tempTablePkColForNullCheck = $tempTable . "." . $targetConn->quoteIdentifier($targetPkColumns[0]);
                
                // --- C.1. Log deletions if enabled ---
                if ($config->enableDeletionLogging && !empty($config->targetDeletedLogTableName)) {
                    $this->logger->debug('Deletion logging is enabled, logging deletions before performing delete operation');
                    
                    // Get quoted identifiers for tables
                    $deletedLogTableIdentifier = $targetConn->quoteIdentifier($config->targetDeletedLogTableName);
                    $liveTableIdentifierForLog = $targetConn->quoteIdentifier($config->targetLiveTableName) . ' lt'; // Alias as 'lt'
                    $tempTableIdentifierForLog = $targetConn->quoteIdentifier($config->targetTempTableName) . ' tt'; // Alias as 'tt'
                    
                    // Get quoted syncer_id column from live table
                    $liveSyncerIdCol = $targetConn->quoteIdentifier($config->metadataColumns->id);
                    
                    // Create join conditions with aliases
                    $logJoinConditions = [];
                    foreach ($config->getTargetPrimaryKeyColumns() as $keyCol) {
                        $quotedKeyCol = $targetConn->quoteIdentifier($keyCol);
                        $logJoinConditions[] = "lt.{$quotedKeyCol} = tt.{$quotedKeyCol}";
                    }
                    $logJoinConditionStr = implode(' AND ', $logJoinConditions);
                    
                    // Define columns for the log table insert
                    $logTableInsertCols = [
                        $targetConn->quoteIdentifier('deleted_syncer_id'),
                        $targetConn->quoteIdentifier('deleted_at_revision_id'),
                        $targetConn->quoteIdentifier('deletion_timestamp')
                    ];
                    
                    // Ensure the column for NULL check is from the TEMP table after the LEFT JOIN with alias
                    $tempTableBusinessPkColForNullCheck = 'tt.' . $targetConn->quoteIdentifier($targetPkColumns[0]);
                    
                    // Construct the SQL to log deletions
                    $sqlLogDeletes = "INSERT INTO {$deletedLogTableIdentifier} (" . implode(', ', $logTableInsertCols) . ") "
                        . "SELECT lt.{$liveSyncerIdCol}, ?, CURRENT_TIMESTAMP "
                        . "FROM {$liveTableIdentifierForLog} "
                        . "LEFT JOIN {$tempTableIdentifierForLog} ON {$logJoinConditionStr} "
                        . "WHERE {$tempTableBusinessPkColForNullCheck} IS NULL";
                    
                    $paramsForLog = [$currentBatchRevisionId];
                    
                    try {
                        $this->logger->debug('Executing log deletions SQL', ['sql' => $sqlLogDeletes]);
                        $affectedRowsLog = $targetConn->executeStatement($sqlLogDeletes, $paramsForLog);
                        $report->loggedDeletionsCount = (int)$affectedRowsLog;
                        $report->addLogMessage("Deletions logged to '{$config->targetDeletedLogTableName}': {$report->loggedDeletionsCount}.");
                    } catch (\Throwable $e) {
                        $this->logger->error("Failed to log deletions: " . $e->getMessage(), ['exception' => $e]);
                        throw new TableSyncerException("Failed to log deletions: " . $e->getMessage(), 0, $e);
                    }
                } else {
                    $this->logger->debug('Deletion logging is not enabled, skipping deletion logging');
                }
                
                // --- C.2. Perform the actual delete operation ---
                $sqlDelete = "DELETE {$liveTable} FROM {$liveTable} " // MySQL specific syntax for aliasing in DELETE with JOIN
                    . "LEFT JOIN {$tempTable} ON {$joinConditionStr} "
                    . "WHERE {$tempTablePkColForNullCheck} IS NULL";

                $this->logger->debug('Executing delete SQL for live table', ['sql' => $sqlDelete]);
                $affectedRowsDelete = $targetConn->executeStatement($sqlDelete);
                $report->deletedCount = (int)$affectedRowsDelete;
                $report->addLogMessage("Rows deleted from '{$config->targetLiveTableName}' (not in source/temp): {$report->deletedCount}.");
            }

            // --- D. Handle Inserts ---
            $colsToInsertLiveForNew = array_unique(array_merge( // Renamed to avoid conflict with initial import var
                $config->getTargetPrimaryKeyColumns(),
                $config->getTargetDataColumns(),
                [$meta->contentHash, $meta->createdAt, $meta->batchRevision]
            ));
            $quotedColsToInsertLiveForNew = array_map(fn($c) => $targetConn->quoteIdentifier($c), $colsToInsertLiveForNew);

            $colsToSelectTempForNew = array_unique(array_merge( // Renamed
                $config->getTargetPrimaryKeyColumns(),
                $config->getTargetDataColumns(),
                [$meta->contentHash, $meta->createdAt]
            ));
            $quotedColsToSelectTempForNew = array_map(fn($c) => $tempTable . "." . $targetConn->quoteIdentifier($c), $colsToSelectTempForNew);

            if (empty($quotedColsToSelectTempForNew) || empty($quotedColsToInsertLiveForNew)) {
                $this->logger->warning("Cannot perform new inserts: column list for SELECT or INSERT is empty.", [
                    'select_cols_count' => count($quotedColsToSelectTempForNew),
                    'insert_cols_count' => count($quotedColsToInsertLiveForNew),
                ]);
            } elseif (count($quotedColsToSelectTempForNew) + 1 !== count($quotedColsToInsertLiveForNew)) {
                $this->logger->error("Column count mismatch for new inserts.", [
                    'select_cols' => $quotedColsToSelectTempForNew,
                    'insert_cols' => $quotedColsToInsertLiveForNew,
                ]);
                throw new TableSyncerException("Configuration error: Column count mismatch for new inserts into live table.");
            } else {
                // Ensure the column for NULL check is from the LIVE table after the LEFT JOIN
                $liveTablePkColForNullCheck = $liveTable . "." . $targetConn->quoteIdentifier($targetPkColumns[0]);
                $sqlInsert = "INSERT INTO {$liveTable} (" . implode(', ', $quotedColsToInsertLiveForNew) . ") "
                    . "SELECT " . implode(', ', $quotedColsToSelectTempForNew) . ", ? " // ? for batch_revision
                    . "FROM {$tempTable} "
                    . "LEFT JOIN {$liveTable} ON {$joinConditionStr} "
                    . "WHERE {$liveTablePkColForNullCheck} IS NULL";

                $this->logger->debug('Executing insert SQL for new rows in live table', ['sql' => $sqlInsert]);
                $affectedRowsInsert = $targetConn->executeStatement($sqlInsert, [$currentBatchRevisionId]);
                $report->insertedCount = (int)$affectedRowsInsert;
                $report->addLogMessage("New rows inserted into '{$config->targetLiveTableName}': {$report->insertedCount}.");
            }

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
                    $this->logger->error(
                        'Failed to roll back transaction in TempToLiveSynchronizer: ' . $rollbackException->getMessage(),
                        ['original_exception_message' => $e->getMessage(), 'rollback_exception_class' => get_class($rollbackException)]
                    );
                }
            } else if ($targetConn->isTransactionActive() && !$transactionStartedByThisMethod) {
                // Log if a transaction was active but not started by this method
                $this->logger->warning('Error in TempToLiveSynchronizer, but transaction was managed externally and remains active.', ['exception_message' => $e->getMessage()]);
            }
            // Re-throw the original exception
            throw $e;
        }
    }
}