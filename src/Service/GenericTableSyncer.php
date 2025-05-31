<?php

namespace TopdataSoftwareGmbh\TableSyncer\Service;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TopdataSoftwareGmbh\TableSyncer\DTO\SyncReportDTO;
use TopdataSoftwareGmbh\TableSyncer\DTO\TableSyncConfigDTO;
// use TopdataSoftwareGmbh\TableSyncer\Util\DbalHelper; // Not used in the provided methods, can be removed if not used elsewhere
use TopdataSoftwareGmbh\TableSyncer\Exception\TableSyncerException;

class GenericTableSyncer
{
    private readonly GenericSchemaManager $schemaManager;
    private readonly GenericIndexManager $indexManager;
    private readonly GenericDataHasher $dataHasher;
    private readonly LoggerInterface $logger;

    public function __construct(
        GenericSchemaManager $schemaManager,
        GenericIndexManager $indexManager,
        GenericDataHasher $dataHasher,
        ?LoggerInterface $logger = null
    ) {
        $this->schemaManager = $schemaManager;
        $this->indexManager = $indexManager;
        $this->dataHasher = $dataHasher;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Synchronizes the data between source and target tables.
     *
     * @param TableSyncConfigDTO $config
     * @param int $currentBatchRevisionId
     * @return SyncReportDTO
     * @throws TableSyncerException
     */
    public function sync(TableSyncConfigDTO $config, int $currentBatchRevisionId): SyncReportDTO
    {
        $targetConn = $config->targetConnection;
        // It's generally better to start the transaction right before DML operations,
        // as DDL operations might implicitly commit. However, for simplicity and if the DB supports DDL in transactions (or handles implicit commits gracefully),
        // starting it here covers the whole sync. The improved catch block handles scenarios where it might have been committed.
        if (!$targetConn->isTransactionActive()) { // Start transaction only if not already in one (e.g. by caller)
            $targetConn->beginTransaction();
        }
        try {
            $report = new SyncReportDTO();
            $this->logger->info('Starting sync process', [
                'source' => $config->sourceTableName,
                'target' => $config->targetLiveTableName,
                'batchRevision' => $currentBatchRevisionId
            ]);

            // 1. Ensure live table exists with correct schema
            $this->schemaManager->ensureLiveTable($config);

            // 2. Prepare temp table (drop if exists, create new)
            $this->schemaManager->prepareTempTable($config);

            // 3. Load data from source to temp
            $this->loadDataFromSourceToTemp($config); // <<<< THIS METHOD IS REPLACED BELOW

            // 4. Add hashes to temp table rows for change detection
            $this->dataHasher->addHashesToTempTable($config);

            // 5. Add indexes to temp table for faster sync
            $this->indexManager->addIndicesToTempTableAfterLoad($config);

            // 6. Add any missing indexes to live table
            $this->indexManager->addIndicesToLiveTable($config);

            // 7. Synchronize temp to live (insert/update/delete)
            $this->synchronizeTempToLive($config, $currentBatchRevisionId, $report);

            // 8. Drop temp table to clean up
            $this->schemaManager->dropTempTable($config);

            if ($targetConn->isTransactionActive()) { // Only commit if we started it and it's still active
                $targetConn->commit();
            }
            $this->logger->info('Sync completed successfully', [
                'inserted' => $report->insertedCount,
                'updated' => $report->updatedCount,
                'deleted' => $report->deletedCount,
                'initialInsert' => $report->initialInsertCount
            ]);
            return $report;
        } catch (\Throwable $e) {
            if ($targetConn->isTransactionActive()) {
                try {
                    $targetConn->rollBack();
                    $this->logger->warning('Transaction rolled back due to an error during sync.', ['exception_message' => $e->getMessage()]);
                } catch (\Throwable $rollbackException) {
                    $this->logger->error('Failed to roll back transaction: ' . $rollbackException->getMessage(), [
                        'original_exception_message' => $e->getMessage(),
                        'rollback_exception' => $rollbackException
                    ]);
                }
            } else {
                $this->logger->info('No active transaction to roll back when error occurred. The error might have happened after an implicit commit caused by DDL or if transaction was managed externally.', ['exception_message' => $e->getMessage()]);
            }

            $this->logger->error('Sync failed: ' . $e->getMessage(), [
                'exception' => $e,
                'source' => $config->sourceTableName,
                'target' => $config->targetLiveTableName
            ]);
            throw new TableSyncerException('Sync failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Loads data from source table to temp table.
     *
     * @param TableSyncConfigDTO $config
     * @return void
     * @throws TableSyncerException
     */
    protected function loadDataFromSourceToTemp(TableSyncConfigDTO $config): void
    {
        $this->logger->debug('Loading data from source to temp table', [
            'sourceTable' => $config->sourceTableName,
            'tempTable' => $config->targetTempTableName
        ]);

        $sourceConn = $config->sourceConnection;
        $targetConn = $config->targetConnection;

        $sourceTableIdentifier = $sourceConn->quoteIdentifier($config->sourceTableName);
        $tempTableIdentifier = $targetConn->quoteIdentifier($config->targetTempTableName);

        // Build column lists for SELECT from source (source column names)
        $sourceSelectColumns = array_unique(array_merge(
            $config->getSourcePrimaryKeyColumns(),
            $config->getSourceDataColumns()
        ));
        if (empty($sourceSelectColumns)) {
            $this->logger->warning("No source columns defined for SELECT from '{$config->sourceTableName}'. Temp table will be empty if it relies on this load.");
            return;
        }
        $quotedSourceSelectColumns = array_map(fn($col) => $sourceConn->quoteIdentifier($col), $sourceSelectColumns);

        // Build column lists for INSERT into temp table (target column names)
        // These are the columns in the temp table that will receive data from the source.
        // This should typically correspond to the data columns mapped from the source.
        // The temp table schema itself is defined by GenericSchemaManager::prepareTempTable,
        // which includes mapped PKs, mapped data columns, and temp-specific metadata.
        // For this INSERT, we only list columns that are being populated from the source.
        $targetInsertDataColumns = array_unique(array_merge(
            $config->getTargetPrimaryKeyColumns(), // Mapped PKs
            $config->getTargetDataColumns()      // Mapped data columns
        ));

        if (empty($targetInsertDataColumns)) {
            $this->logger->warning("No target columns defined for INSERT into '{$config->targetTempTableName}' based on mappings. Cannot load data from source.");
            return;
        }
        $quotedTargetInsertDataColumns = array_map(fn($col) => $targetConn->quoteIdentifier($col), $targetInsertDataColumns);

        // Get source column types for proper parameter binding
        $sourceColumnTypes = $this->schemaManager->getSourceColumnTypes($config);

        // Get data from source table
        $selectSql = "SELECT " . implode(", ", $quotedSourceSelectColumns) . " FROM {$sourceTableIdentifier}";
        $this->logger->debug("Executing source data SELECT SQL", ['sql' => $selectSql]);
        $stmt = $sourceConn->prepare($selectSql);
        $result = $stmt->executeQuery();

        // Prepare INSERT statement for temp table
        $placeholders = array_map(fn() => '?', $targetInsertDataColumns);
        $insertSql = "INSERT INTO {$tempTableIdentifier} (" . implode(", ", $quotedTargetInsertDataColumns) . ") VALUES (" . implode(", ", $placeholders) . ")";
        $insertStmt = $targetConn->prepare($insertSql);

        $this->logger->debug('Prepared insert statement for temp table', [
            'sql' => $insertSql,
            'targetColumnsCount' => count($targetInsertDataColumns),
            'placeholdersCount' => count($placeholders)
        ]);

        $rowCount = 0;
        while ($row = $result->fetchAssociative()) { // $row keys are source column names
            $processedRow = $this->ensureDatetimeValues($config, $row);

            $orderedParamValues = [];
            $orderedParamTypes = [];

            foreach ($targetInsertDataColumns as $targetColName) {
                $sourceColName = null;
                try {
                    $sourceColName = $config->getMappedSourceColumnName($targetColName);
                } catch (\InvalidArgumentException $e) {
                    // This should not happen if $targetInsertDataColumns is built correctly from mappings
                    $this->logger->error("Critical error: Target column '{$targetColName}' for INSERT not found in config mappings.", ['target_col' => $targetColName, 'exception_msg' => $e->getMessage()]);
                    throw new TableSyncerException("Configuration error mapping target column '{$targetColName}' back to a source column.", 0, $e);
                }

                if (!array_key_exists($sourceColName, $processedRow)) {
                    $this->logger->error("Value missing for source column '{$sourceColName}' (maps to target '{$targetColName}') in fetched row. This indicates an issue with the source SELECT query or data consistency.", [
                        'source_col' => $sourceColName,
                        'target_col' => $targetColName,
                        'available_row_keys' => array_keys($processedRow)
                    ]);
                    // Decide how to handle: insert NULL, or error out.
                    // Inserting NULL might be acceptable if the target column is nullable.
                    $orderedParamValues[] = null;
                    $orderedParamTypes[] = ParameterType::NULL; // Use DBAL's NULL type
                    continue;
                }

                $value = $processedRow[$sourceColName];
                $orderedParamValues[] = $value;

                $dbalType = $sourceColumnTypes[$sourceColName] ?? null;
                if ($dbalType) {
                    $orderedParamTypes[] = $this->dbalTypeToParameterType($dbalType);
                } else {
                    $this->logger->warning("DBAL type not found for source column '{$sourceColName}' in schema manager cache, falling back to runtime type detection for binding.", ['source_col' => $sourceColName]);
                    $orderedParamTypes[] = $this->getDbalParamType($sourceColName, $value);
                }
            }

            if (count($orderedParamValues) !== count($placeholders)) {
                $this->logger->error("CRITICAL: Parameter count mismatch before executeStatement for temp table!", [
                    'sql' => $insertSql,
                    'param_count' => count($orderedParamValues),
                    'placeholder_count' => count($placeholders),
                    'target_insert_cols_for_sql' => $targetInsertDataColumns,
                ]);
                // This is a definitive programming error if reached.
                throw new TableSyncerException("Internal error: Parameter count mismatch for INSERT into temp table. Expected " . count($placeholders) . ", got " . count($orderedParamValues));
            }

            try {
                // For DBAL 3+, executeStatement takes an array of values, then an array of types.
                // Positional parameters are 0-indexed in these arrays.
                $insertStmt->executeStatement($orderedParamValues, $orderedParamTypes);
                $rowCount++;
            } catch (\Exception $e) {
                $this->logger->error('Failed to execute insert statement for temp table: ' . $e->getMessage(), [
                    'sql' => $insertSql,
                    'params_count' => count($orderedParamValues),
                    'types_count' => count($orderedParamTypes),
                    // 'params_values' => $orderedParamValues, // Log actual values only if absolutely necessary for debugging and aware of sensitivity
                    'exception_class' => get_class($e),
                    'exception_trace' => $e->getTraceAsString() // Can be verbose
                ]);
                // Re-throw to be caught by the main sync try-catch, which will wrap it in TableSyncerException
                throw $e;
            }

            if ($rowCount > 0 && $rowCount % 1000 === 0) {
                $this->logger->debug("Loaded {$rowCount} rows into temp table '{$config->targetTempTableName}' so far...");
            }
        }
        $this->logger->info("Successfully loaded {$rowCount} rows from source '{$config->sourceTableName}' to temp table '{$config->targetTempTableName}'.");
    }


    /**
     * Synchronizes the temp table to the live table.
     * Performs set-based operations for updating, deleting, and inserting records.
     *
     * @param TableSyncConfigDTO $config
     * @param int $currentBatchRevisionId
     * @param SyncReportDTO $report
     * @return void
     * @throws TableSyncerException
     */
    protected function synchronizeTempToLive(TableSyncConfigDTO $config, int $currentBatchRevisionId, SyncReportDTO $report): void
    {
        $this->logger->debug('Synchronizing temp table to live table', [
            'batchRevisionId' => $currentBatchRevisionId,
            'liveTable' => $config->targetLiveTableName,
            'tempTable' => $config->targetTempTableName
        ]);

        $targetConn = $config->targetConnection;
        $meta = $config->metadataColumns;
        $liveTable = $targetConn->quoteIdentifier($config->targetLiveTableName);
        $tempTable = $targetConn->quoteIdentifier($config->targetTempTableName);

        // --- A. Check if the live table is empty ---
        $countResult = $targetConn->fetchOne("SELECT COUNT(*) FROM {$liveTable}");
        $countInt = is_numeric($countResult) ? (int)$countResult : 0; // Ensure numeric before cast

        if ($countInt === 0) {
            $this->logger->info("Live table '{$config->targetLiveTableName}' is empty, performing initial bulk import from temp table '{$config->targetTempTableName}'.");

            // Columns to insert into the live table: Mapped PKs, Mapped Data, Syncer's Hash, Syncer's CreatedAt (from temp), and new BatchRevision
            $colsToInsertLive = array_unique(array_merge(
                $config->getTargetPrimaryKeyColumns(),
                $config->getTargetDataColumns(),
                [$meta->contentHash, $meta->createdAt, $meta->batchRevision] // Syncer's ID is auto-increment, updatedAt defaults
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
            if (count($quotedColsToSelectTemp) + 1 !== count($quotedColsToInsertLive)) { // +1 for batchRevision placeholder
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
            $report->initialInsertCount = (int)$affectedRows; // Cast to int
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
        $setClausesForUpdate[] = "{$liveTable}." . $targetConn->quoteIdentifier($meta->updatedAt) . " = CURRENT_TIMESTAMP"; // Or platform-specific equivalent
        $setClausesForUpdate[] = "{$liveTable}." . $targetConn->quoteIdentifier($meta->batchRevision) . " = ?";

        if (empty($dataColsForUpdate)) {
            $this->logger->warning("No data columns configured for update. Only metadata (updatedAt, batchRevision) will be updated on hash mismatch.");
        }

        $contentHashLiveCol = $targetConn->quoteIdentifier($meta->contentHash);
        $contentHashTempCol = $tempTable . "." . $targetConn->quoteIdentifier($meta->contentHash); // Qualify temp table column

        $sqlUpdate = "UPDATE {$liveTable} "
            . "INNER JOIN {$tempTable} ON {$joinConditionStr} "
            . "SET " . implode(', ', $setClausesForUpdate) . " "
            . "WHERE {$contentHashLiveCol} <> {$contentHashTempCol}";
        // Add collation if necessary for hash comparison e.g. for MySQL:
        // . "WHERE CONVERT({$contentHashLiveCol} USING utf8mb4) <> CONVERT({$contentHashTempCol} USING utf8mb4)";


        $this->logger->debug('Executing update SQL for live table', ['sql' => $sqlUpdate]);
        $affectedRowsUpdate = $targetConn->executeStatement($sqlUpdate, [$currentBatchRevisionId]);
        $report->updatedCount = (int)$affectedRowsUpdate;
        $report->addLogMessage("Rows updated in '{$config->targetLiveTableName}' due to hash mismatch: {$report->updatedCount}.");

        // --- C. Handle Deletes ---
        $targetPkColumns = $config->getTargetPrimaryKeyColumns();
        if (empty($targetPkColumns)) {
            $this->logger->warning("Cannot perform deletes: No target primary key columns defined for LEFT JOIN NULL check.");
        } else {
            $deletePkColForNullCheck = $targetConn->quoteIdentifier($targetPkColumns[0]); // Use first PK col for NULL check
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
        } elseif (count($quotedColsToSelectTemp) + 1 !== count($quotedColsToInsertLive)) { // +1 for batchRevision placeholder
            $this->logger->error("Column count mismatch for new inserts.", [
                'select_cols' => $quotedColsToSelectTemp,
                'insert_cols' => $quotedColsToInsertLive,
            ]);
            throw new TableSyncerException("Configuration error: Column count mismatch for new inserts into live table.");
        } else {
            $insertPkColForNullCheck = $targetConn->quoteIdentifier($targetPkColumns[0]); // Use first PK from business keys
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

    /**
     * Ensures datetime values are valid and non-null for specified columns.
     * Sets a special date (e.g., from config placeholder) for missing or invalid values.
     *
     * @param TableSyncConfigDTO $config
     * @param array<string, mixed> $row The source row.
     * @return array<string, mixed> The processed row.
     */
    protected function ensureDatetimeValues(TableSyncConfigDTO $config, array $row): array
    {
        if (empty($config->nonNullableDatetimeSourceColumns)) {
            return $row;
        }

        // Ensure placeholderDatetime is a valid datetime string for DateTimeImmutable constructor
        try {
            $specialDate = new \DateTimeImmutable($config->placeholderDatetime);
        } catch (\Exception $e) {
            $this->logger->error("Invalid placeholderDatetime '{$config->placeholderDatetime}' in TableSyncConfigDTO. Cannot process datetime values.", ['exception' => $e]);
            throw new TableSyncerException("Invalid placeholderDatetime '{$config->placeholderDatetime}'.", 0, $e);
        }

        foreach ($config->nonNullableDatetimeSourceColumns as $sourceColumnName) {
            if (!array_key_exists($sourceColumnName, $row)) {
                $this->logger->warning("Non-nullable datetime source column '{$sourceColumnName}' not found in source row. Skipping.", ['row_keys' => array_keys($row)]);
                continue;
            }

            $originalValue = $row[$sourceColumnName];
            $valueChanged = false;

            if ($originalValue === null || (is_string($originalValue) && trim($originalValue) === '')) {
                $this->logger->debug("Setting placeholder date for empty/NULL value in non-nullable datetime column: {$sourceColumnName}");
                $row[$sourceColumnName] = $specialDate;
                $valueChanged = true;
            } elseif ($originalValue instanceof \DateTimeInterface) {
                // For DateTimeInterface, check if it represents an invalid or zero-date that needs replacing
                $dateStr = $originalValue->format('Y-m-d H:i:s');
                if ($this->isDateEffectivelyZeroOrInvalid($dateStr)) {
                    $this->logger->debug("Setting placeholder date for effectively zero/invalid DateTimeInterface in column {$sourceColumnName}: {$dateStr}");
                    $row[$sourceColumnName] = $specialDate;
                    $valueChanged = true;
                }
                // Otherwise, it's a valid DateTimeInterface, keep it.
            } elseif (is_string($originalValue)) {
                if ($this->isDateEffectivelyZeroOrInvalid($originalValue)) {
                    $this->logger->debug("Setting placeholder date for effectively zero/invalid date string in column {$sourceColumnName}: '{$originalValue}'");
                    $row[$sourceColumnName] = $specialDate;
                    $valueChanged = true;
                } else {
                    try {
                        // Attempt to convert to DateTimeImmutable to normalize.
                        // If it's already a valid non-zero date string, this might just reformat it or confirm validity.
                        $dt = new \DateTimeImmutable($originalValue);
                        // Optionally, if you want all non-nullable datetimes to be DateTimeInterface objects:
                        // $row[$sourceColumnName] = $dt;
                        // $valueChanged = true; // If you changed the type
                    } catch (\Exception $e) {
                        $this->logger->debug("Setting placeholder date for unparseable/invalid date string in column {$sourceColumnName}: '{$originalValue}'. Error: " . $e->getMessage());
                        $row[$sourceColumnName] = $specialDate;
                        $valueChanged = true;
                    }
                }
            } else {
                $this->logger->warning("Unexpected type for non-nullable datetime column '{$sourceColumnName}'. Value not processed.", ['type' => gettype($originalValue), 'value' => $originalValue]);
            }

            if ($valueChanged) {
                $this->logger->debug("Final value for column {$sourceColumnName} after ensureDatetimeValues: " . ($row[$sourceColumnName] instanceof \DateTimeInterface ? $row[$sourceColumnName]->format('Y-m-d H:i:s') : print_r($row[$sourceColumnName], true)));
            }
        }
        return $row;
    }


    /**
     * Gets the DBAL parameter type for a value based on runtime type detection.
     *
     * @param string $columnName Column name (for logging/context if needed)
     * @param mixed $value The value to determine type for
     * @return int DBAL ParameterType constant (integer)
     */
    protected function getDbalParamType(string $columnName, $value): ParameterType
    {
        if ($value === null) {
            return ParameterType::NULL;
        }
        if (is_int($value)) {
            return ParameterType::INTEGER;
        }
        if (is_bool($value)) {
            return ParameterType::BOOLEAN;
        }
        // Floats, DateTimeInterface, and everything else default to STRING for binding
        // This is generally safe as DBAL/PDO will handle conversions.
        // Specific types like LOBs might need ParameterType::LARGE_OBJECT but are not common here.
        return ParameterType::STRING;
    }

    /**
     * Converts a DBAL Type name (string) to a DBAL ParameterType constant (integer).
     *
     * @param string $dbalTypeName DBAL Type name (e.g., Types::INTEGER, "string")
     * @return int DBAL ParameterType constant (integer)
     */
    protected function dbalTypeToParameterType(string $dbalTypeName): ParameterType
    {
        // Normalize known DBAL type constants to their string representations if needed,
        // though $dbalTypeName from schemaManager should already be the string name.
        switch (strtolower($dbalTypeName)) {
            case Types::INTEGER: // "integer"
            case Types::BIGINT:  // "bigint"
            case Types::SMALLINT: // "smallint"
                return ParameterType::INTEGER;

            case Types::BOOLEAN: // "boolean"
                return ParameterType::BOOLEAN;

            case Types::BLOB:   // "blob"
            case Types::BINARY: // "binary"
                return ParameterType::LARGE_OBJECT; // More appropriate than STRING for LOBs

            // All other types (string, text, date, datetime, decimal, float, json etc.)
            // are generally safe to bind as STRING.
            // Types::STRING, Types::TEXT, Types::GUID,
            // Types::DATE_MUTABLE, Types::DATETIME_MUTABLE, Types::DATETIMETZ_MUTABLE,
            // Types::TIME_MUTABLE, Types::DATE_IMMUTABLE, etc.
            // Types::DECIMAL, Types::FLOAT
            // Types::JSON, Types::SIMPLE_ARRAY (if serialized to string)
            default:
                return ParameterType::STRING;
        }
    }

    /**
     * Checks if a date string represents an effectively zero or invalid date.
     *
     * @param string|null $dateString
     * @return bool
     */
    protected function isDateEffectivelyZeroOrInvalid(?string $dateString): bool
    {
        if ($dateString === null || trim($dateString) === '') {
            return true;
        }
        // Common zero-date representations
        if (in_array($dateString, [
            '0000-00-00',
            '0000-00-00 00:00:00',
            '00:00:00', // If it's just a time part that's zero
            '0', // Sometimes '0' is stored for dates
        ], true)) {
            return true;
        }
        // Check for dates starting with hyphens (often invalid in some DBs or a sign of corruption)
        if (str_starts_with($dateString, '-')) {
            return true;
        }
        // Could add more checks, e.g., trying to parse with DateTime and checking for warnings/errors,
        // but that can be slow in a loop. This covers common cases.
        return false;
    }

    /**
     * (Original from repomix - a bit different from isDateEffectivelyZeroOrInvalid)
     * Checks if a value is empty or invalid for a date column.
     *
     * @param string|\DateTimeInterface $val
     * @return bool
     */
    protected function isDateEmptyOrInvalid($val): bool
    {
        // Convert DateTimeInterface to string if needed
        if ($val instanceof \DateTimeInterface) {
            $val = $val->format('Y-m-d H:i:s');
        }

        return
            empty($val) || // This will catch null, false, 0, "0", ""
            $val === '0000-00-00 00:00:00' ||
            str_starts_with((string)$val, '-'); // Ensure $val is string for str_starts_with
    }
}