<?php

namespace TopdataSoftwareGmbh\TableSyncer\Service;


use App\Constants\GlobalAppConstants;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TopdataSoftwareGmbh\TableSyncer\DTO\SyncReportDTO;
use TopdataSoftwareGmbh\TableSyncer\DTO\TableSyncConfigDTO;

class GenericTableSyncer
{
    private readonly GenericSchemaManager $schemaManager;
    private readonly GenericIndexManager $indexManager;
    private readonly GenericDataHasher $dataHasher;
    private readonly LoggerInterface $logger;

    public function __construct(
        ?LoggerInterface $logger = null
    )
    {
        $this->schemaManager = new GenericSchemaManager($logger);
        $this->indexManager = new GenericIndexManager($logger);
        $this->dataHasher = new GenericDataHasher($logger);
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Synchronizes the data between source and target tables.
     *
     * @param TableSyncConfigDTO $config
     * @param int $currentBatchRevisionId
     * @return SyncReportDTO
     */

    public function sync(TableSyncConfigDTO $config, int $currentBatchRevisionId): SyncReportDTO
    {
        $this->logger->info("Starting sync for table: {$config->sourceTableName} -> {$config->targetLiveTableName}");
        $report = new SyncReportDTO();

        // 1. Prepare Target Tables
        $this->schemaManager->ensureLiveTable($config); // Ensures live table exists
        $this->schemaManager->prepareTempTable($config); // Creates/truncates temp table

        // 2. Load Data from Source to Temp Table
        $this->loadDataFromSourceToTemp($config, $report);

        // 3. Add Content Hash to Temp Table
        $this->dataHasher->addHashesToTempTable($config);

        // 4. Add Post-Load Indices to Temp Table
        $this->indexManager->addIndicesToTempTableAfterLoad($config);
        $this->indexManager->addIndicesToLiveTable($config); // Ensure live table has necessary indices

        // 5. Synchronize Temp to Live
        $this->synchronizeTempToLive($config, $currentBatchRevisionId, $report);

        // Optional: Drop temp table after sync
        // $this->schemaManager->dropTempTable($config);

        $this->logger->info($report->getSummary());
        return $report;
    }

    /**
     * Helper to map DBAL types to ParameterType constants or keep DBAL type strings.
     * DBAL's executeStatement can often work directly with DBAL type strings (like Types::INTEGER).
     * ParameterType constants are more for explicit PDO::PARAM_* types.
     */
    private function dbalTypeToParameterType(string $dbalType): /* ParameterType::* | string */ mixed
    {
        // For many cases, passing the DBAL type string (e.g., Types::INTEGER) is sufficient
        // and DBAL handles the mapping to PDO constants internally.
        return match ($dbalType) {
            Types::INTEGER,
            Types::BIGINT,
            Types::SMALLINT             => ParameterType::INTEGER, // Or just Types::INTEGER
            Types::BOOLEAN              => ParameterType::BOOLEAN, // Or just Types::BOOLEAN
            Types::DATE_MUTABLE,
            Types::DATE_IMMUTABLE       => Types::DATE_MUTABLE, // Pass DBAL type
            Types::DATETIME_MUTABLE,
            Types::DATETIME_IMMUTABLE,
            Types::DATETIMETZ_MUTABLE,
            Types::DATETIMETZ_IMMUTABLE => Types::DATETIME_MUTABLE, // Pass DBAL type
            // For other types like STRING, TEXT, FLOAT, etc., DBAL usually infers correctly
            // or you can pass the DBAL type string itself.
            default                     => ParameterType::STRING, // Fallback, or pass $dbalType
        };
    }

    private function loadDataFromSourceToTemp(TableSyncConfigDTO $config, SyncReportDTO $report): void
    {
        $sourceConn = $config->sourceConnection;
        $targetConn = $config->targetConnection;
        $metaCols = $config->metadataColumns;

        $columnsToSelectFromSource = array_unique(array_merge($config->sourcePrimaryKeyColumns, $config->dataColumnsToSync));
        $quotedColumnsToSelectFromSource = array_map(fn($c) => $sourceConn->quoteIdentifier($c), $columnsToSelectFromSource);

        $selectQb = $sourceConn->createQueryBuilder()
            ->select(...$quotedColumnsToSelectFromSource)
            ->from($sourceConn->quoteIdentifier($config->sourceTableName));

        $sourceStmt = $selectQb->executeQuery();

        $tempTableInsertCols = array_unique(array_merge($config->targetMatchingKeyColumns, $config->dataColumnsToSync, [$metaCols->createdAt]));
        $quotedTempTableInsertCols = array_map(fn($c) => $targetConn->quoteIdentifier($c), $tempTableInsertCols);

        $insertSqlBase = "INSERT INTO " . $targetConn->quoteIdentifier($config->targetTempTableName)
            . " (" . implode(', ', $quotedTempTableInsertCols) . ") VALUES ";

        $batchSize = 500;
        $totalRowsLoaded = 0;
        $valueSets = [];
        $allParamsForBatch = [];
        $allParamTypesForBatch = []; // <<< NEW: Array for parameter types

        // Re-fetch guessed source column types to help with parameter typing
        $allSourceDataColNamesForTypeLookup = array_unique(array_merge($config->sourcePrimaryKeyColumns, $config->dataColumnsToSync));
        // This schema manager instance might not have the getSourceColumnTypes method
        // We should either pass it or make getSourceColumnTypes static/part of config if simple
        // For now, let's assume we can access a similar helper or make a local one.
        $schemaManagerForTypes = new GenericSchemaManager(); // Temporary, ideally inject/reuse
        $sourceColDataTypes = $schemaManagerForTypes->getSourceColumnTypes($config, $allSourceDataColNamesForTypeLookup);

        // Add debug logging for datetime columns
        $this->logger->debug("Detected column types for source table: " . json_encode($sourceColDataTypes));

        while ($sourceRow = $sourceStmt->fetchAssociative()) {
            // Apply datetime validation first
            $validatedRow = $this->ensureDatetimeValues($config, $sourceRow);

            $placeholdersForValues = [];
            $currentParamsForValues = [];
            $currentParamTypesForValues = [];

            // Map source PKs to target matching keys
            foreach ($config->sourcePrimaryKeyColumns as $idx => $srcPkCol) {
                $placeholdersForValues[] = '?';
                $currentParamsForValues[] = $validatedRow[$srcPkCol];
                // Infer type for binding, default to STRING
                $currentParamTypesForValues[] = $this->dbalTypeToParameterType($sourceColDataTypes[$srcPkCol] ?? Types::STRING);
            }

            // Map data columns
            foreach ($config->dataColumnsToSync as $dataCol) {
                if (in_array($dataCol, $config->sourcePrimaryKeyColumns)) continue;
                $placeholdersForValues[] = '?';

                // Convert datetime strings to DateTime objects if the column is a datetime type
                if (($sourceColDataTypes[$dataCol] ?? '') == Types::DATETIME_MUTABLE ||
                    ($sourceColDataTypes[$dataCol] ?? '') == Types::DATETIME_IMMUTABLE) {
                    $this->logger->debug("Processing datetime column: {$dataCol}, value: " . print_r($validatedRow[$dataCol], true));

                    // Handle DateTimeInterface objects first
                    if (!empty($validatedRow[$dataCol]) && $validatedRow[$dataCol] instanceof \DateTimeInterface) {
                        // Check if it's an invalid date (like negative year)
                        $dateStr = $validatedRow[$dataCol]->format('Y-m-d H:i:s');
                        if ($this->isDateEmptyOrInvalid($dateStr)) {
                            $this->logger->debug("Invalid DateTimeInterface detected for column {$dataCol}: '{$dateStr}' - using placeholder date");
                            $specialDate = new \DateTimeImmutable(GlobalAppConstants::PLACEHOLDER_DATETIME); // FIXME
                            $currentParamsForValues[] = $specialDate;
                            $currentParamTypesForValues[] = Types::DATETIME_IMMUTABLE;
                        } else {
                            $this->logger->debug("Column {$dataCol} is already a valid DateTimeInterface");
                            $currentParamsForValues[] = $validatedRow[$dataCol];
                            $currentParamTypesForValues[] = Types::DATETIME_IMMUTABLE;
                        }
                    } else {
                        // Handle string values
                        if ($this->isDateEmptyOrInvalid($validatedRow[$dataCol])) {
                            $this->logger->debug("Invalid date detected for column {$dataCol}: '{$validatedRow[$dataCol]}' - using placeholder date");
                            $specialDate = new \DateTimeImmutable(GlobalAppConstants::PLACEHOLDER_DATETIME); // FIXME
                            $currentParamsForValues[] = $specialDate;
                            $currentParamTypesForValues[] = Types::DATETIME_IMMUTABLE;
                        } else {
                            try {
                                $date = new \DateTimeImmutable($validatedRow[$dataCol]);
                                $this->logger->debug("Converted to DateTimeImmutable for column {$dataCol}");
                                $currentParamsForValues[] = $date;
                                $currentParamTypesForValues[] = Types::DATETIME_IMMUTABLE;
                            } catch (\Exception $e) {
                                // If conversion fails, log and use placeholder date
                                $this->logger->warning("Failed to convert datetime for column {$dataCol}: " . $e->getMessage() . " - Value: " . print_r($validatedRow[$dataCol], true));
                                $specialDate = new \DateTimeImmutable(GlobalAppConstants::PLACEHOLDER_DATETIME);
                                $currentParamsForValues[] = $specialDate;
                                $currentParamTypesForValues[] = Types::DATETIME_IMMUTABLE;
                            }
                        }
                    }
                } else {
                    $currentParamsForValues[] = $validatedRow[$dataCol];
                    $currentParamTypesForValues[] = $this->dbalTypeToParameterType($sourceColDataTypes[$dataCol] ?? Types::STRING);
                }
            }

            // For _created_at
            $placeholdersForValues[] = '?';
            $currentParamsForValues[] = new \DateTimeImmutable();
            $currentParamTypesForValues[] = Types::DATETIME_IMMUTABLE; // <<< Explicitly state the DBAL type

            $valueSets[] = '(' . implode(', ', $placeholdersForValues) . ')';
            $allParamsForBatch = array_merge($allParamsForBatch, $currentParamsForValues);
            $allParamTypesForBatch = array_merge($allParamTypesForBatch, $currentParamTypesForValues); // <<< Add types

            if (count($valueSets) >= $batchSize) {
                $this->logger->debug("Executing batch insert with " . count($allParamsForBatch) . " parameters");
                $this->logger->debug("Parameter types: " . json_encode($allParamTypesForBatch));
                $targetConn->executeStatement($insertSqlBase . implode(', ', $valueSets), $allParamsForBatch, $allParamTypesForBatch); // <<< Pass types
                $totalRowsLoaded += count($valueSets);
                $valueSets = [];
                $allParamsForBatch = [];
                $allParamTypesForBatch = []; // <<< Reset types
            }
        }

        if (!empty($valueSets)) {
            $this->logger->debug("Executing final batch insert with " . count($allParamsForBatch) . " parameters");
            $this->logger->debug("Final parameter types: " . json_encode($allParamTypesForBatch));
            $targetConn->executeStatement($insertSqlBase . implode(', ', $valueSets), $allParamsForBatch, $allParamTypesForBatch); // <<< Pass types
            $totalRowsLoaded += count($valueSets);
        }
        $this->logger->debug("Loaded {$totalRowsLoaded} rows from source {$config->sourceTableName} to temp table {$config->targetTempTableName}.");
    }

    private function synchronizeTempToLive(TableSyncConfigDTO $config, int $currentBatchRevisionId, SyncReportDTO $report): void
    {
        $targetConn = $config->targetConnection;
        $liveTable = $targetConn->quoteIdentifier($config->targetLiveTableName);
        $tempTable = $targetConn->quoteIdentifier($config->targetTempTableName);
        $meta = $config->metadataColumns;

        // Debug: Check collations of contentHash column in both tables
        $schemaManager = $targetConn->createSchemaManager();
        $liveTableName = $config->targetLiveTableName;
        $tempTableName = $config->targetTempTableName;

        if ($schemaManager->tablesExist([$liveTableName])) {
            $liveTableSchema = $schemaManager->introspectTable($liveTableName);
            if ($liveTableSchema->hasColumn($meta->contentHash)) {
                $liveCol = $liveTableSchema->getColumn($meta->contentHash);
                $this->logger->debug("Live table {$liveTableName} column {$meta->contentHash} collation: " . ($liveCol->getPlatformOptions()['collation'] ?? 'Not specified'));
            }
        }

        if ($schemaManager->tablesExist([$tempTableName])) {
            $tempTableSchema = $schemaManager->introspectTable($tempTableName);
            if ($tempTableSchema->hasColumn($meta->contentHash)) {
                $tempCol = $tempTableSchema->getColumn($meta->contentHash);
                $this->logger->debug("Temp table {$tempTableName} column {$meta->contentHash} collation: " . ($tempCol->getPlatformOptions()['collation'] ?? 'Not specified'));
            }
        }

        // --- A. Initial import (if live table is empty) ---
        $liveTableRowTest = $targetConn->fetchOne("SELECT 1 FROM {$liveTable} LIMIT 1");
        if ($liveTableRowTest === false) { // Table is empty
            $this->logger->debug("Live table {$config->targetLiveTableName} is empty. Performing initial bulk insert.");

            $colsToInsertLive = array_merge(
                $config->targetMatchingKeyColumns,
                $config->dataColumnsToSync,
                [$meta->contentHash, $meta->createdAt, $meta->batchRevision] // No updated_at for new rows
            );
            $quotedColsToInsertLive = array_map(fn($c) => $targetConn->quoteIdentifier($c), $colsToInsertLive);

            $colsToSelectTemp = array_merge(
                $config->targetMatchingKeyColumns, // Assumes names are same in temp
                $config->dataColumnsToSync,
                [$meta->contentHash, $meta->createdAt]
            );
            $quotedColsToSelectTemp = array_map(fn($c) => $tempTable . "." . $targetConn->quoteIdentifier($c), $colsToSelectTemp);

            $sqlInitialInsert = "INSERT INTO {$liveTable} (" . implode(', ', $quotedColsToInsertLive) . ") "
                . "SELECT " . implode(', ', $quotedColsToSelectTemp) . ", ? " // ? for batch_revision
                . "FROM {$tempTable}";
            $report->initialInsertCount = $targetConn->executeStatement($sqlInitialInsert, [$currentBatchRevisionId]);
            $report->addLogMessage("Initial import: {$report->initialInsertCount} rows inserted.");
            return;
        }

        // Join condition string for matching rows between live and temp
        $joinConditions = [];
        foreach ($config->targetMatchingKeyColumns as $keyCol) {
            $quotedKeyCol = $targetConn->quoteIdentifier($keyCol);
            $joinConditions[] = "{$liveTable}.{$quotedKeyCol} = {$tempTable}.{$quotedKeyCol}";
        }
        $joinConditionStr = implode(' AND ', $joinConditions);

        // --- B. Handle Updates ---
        // (rows in temp that are also in live but content_hash differs)
        $setClausesForUpdate = [];
        // Update data columns and the hash
        foreach (array_unique(array_merge($config->dataColumnsToSync, [$meta->contentHash])) as $col) {
            $qCol = $targetConn->quoteIdentifier($col);
            $setClausesForUpdate[] = "{$liveTable}.{$qCol} = {$tempTable}.{$qCol}";
        }
        $setClausesForUpdate[] = "{$liveTable}." . $targetConn->quoteIdentifier($meta->updatedAt) . " = CURRENT_TIMESTAMP";
        $setClausesForUpdate[] = "{$liveTable}." . $targetConn->quoteIdentifier($meta->batchRevision) . " = ?";

        // Fetch and process rows to ensure datetime values
        $rows = $targetConn->fetchAllAssociative("SELECT * FROM {$tempTable}");
        foreach ($rows as &$row) {
            $row = $this->ensureDatetimeValues($config, $row);
        }

        // Use explicit collation for the comparison to avoid collation mismatch errors
        $sqlUpdate = "UPDATE {$liveTable}
            INNER JOIN {$tempTable} ON {$joinConditionStr}
            SET " . implode(', ', $setClausesForUpdate) . "
            WHERE CONVERT({$liveTable}." . $targetConn->quoteIdentifier($meta->contentHash) . " USING utf8mb4)
                 COLLATE utf8mb4_unicode_ci
              <> CONVERT({$tempTable}." . $targetConn->quoteIdentifier($meta->contentHash) . " USING utf8mb4)
                 COLLATE utf8mb4_unicode_ci";
        $report->updatedCount = $targetConn->executeStatement($sqlUpdate, [$currentBatchRevisionId]);
        $report->addLogMessage("Rows updated due to hash mismatch: {$report->updatedCount}.");

        // --- C. Handle Deletes ---
        // (rows in live that are NOT in temp table - implies temp table is complete desired state)
        $deletePkColForNullCheck = $targetConn->quoteIdentifier($config->targetMatchingKeyColumns[0]); // Use first PK col for NULL check
        $sqlDelete = "DELETE {$liveTable} FROM {$liveTable} " // Syntax for MySQL, may vary for other DBs
            . "LEFT JOIN {$tempTable} ON {$joinConditionStr}
            WHERE {$tempTable}.{$deletePkColForNullCheck} IS NULL";
        if (UtilDoctrineDbal::getDatabasePlatformName($targetConn) !== 'mysql') { // More standard SQL for DELETE FROM with JOIN
            $sqlDelete = "DELETE FROM {$liveTable}
                WHERE NOT EXISTS (SELECT 1 FROM {$tempTable} WHERE {$joinConditionStr})";
        }
        $report->deletedCount = $targetConn->executeStatement($sqlDelete);
        $report->addLogMessage("Rows deleted from live (not in source/temp): {$report->deletedCount}.");

        // --- D. Handle Inserts ---
        // (rows in temp that are not in live table)
        $colsToInsertLive = array_merge(
            $config->targetMatchingKeyColumns,
            $config->dataColumnsToSync,
            [$meta->contentHash, $meta->createdAt, $meta->batchRevision]
        );
        $quotedColsToInsertLive = array_map(fn($c) => $targetConn->quoteIdentifier($c), $colsToInsertLive);

        $colsToSelectTemp = array_merge(
            $config->targetMatchingKeyColumns,
            $config->dataColumnsToSync,
            [$meta->contentHash, $meta->createdAt]
        );
        $quotedColsToSelectTemp = array_map(fn($c) => $tempTable . "." . $targetConn->quoteIdentifier($c), $colsToSelectTemp);

        // Fetch and process rows to ensure datetime values
        $rows = $targetConn->fetchAllAssociative("SELECT * FROM {$tempTable}");
        foreach ($rows as &$row) {
            $row = $this->ensureDatetimeValues($config, $row);
        }

        $insertPkColForNullCheck = $targetConn->quoteIdentifier($config->targetMatchingKeyColumns[0]);
        $sqlInsert = "INSERT INTO {$liveTable} (" . implode(', ', $quotedColsToInsertLive) . ") "
            . "SELECT " . implode(', ', $quotedColsToSelectTemp) . ", ? " // ? for batch_revision
            . "FROM {$tempTable}
            LEFT JOIN {$liveTable} ON {$joinConditionStr}
            WHERE {$liveTable}.{$insertPkColForNullCheck} IS NULL";
        $report->insertedCount = $targetConn->executeStatement($sqlInsert, [$currentBatchRevisionId]);
        $report->addLogMessage("New rows inserted into live: {$report->insertedCount}.");
    }

    /**
     * Ensures datetime values are valid and non-null for specified columns.
     * Sets a special date (2222-02-22) for missing or invalid values.
     *
     * @param TableSyncConfigDTO $config
     * @param array $row
     * @return array
     */
    protected function ensureDatetimeValues(TableSyncConfigDTO $config, array $row): array
    {
        $specialDate = new \DateTimeImmutable(GlobalAppConstants::PLACEHOLDER_DATETIME);

        foreach ($config->nonNullableDatetimeColumns as $column) {
            // Log original value for debugging
            $originalValue = $row[$column] ?? 'NULL';
            $this->logger->debug("Processing datetime column: {$column}, original value: " . print_r($originalValue, true));

            if (empty($row[$column])) {
                $this->logger->warning("Setting placeholder date for NULL value in column: {$column}");
                $row[$column] = $specialDate;
            } elseif ($row[$column] instanceof \DateTimeInterface) {
                // Check if it's an invalid date (like negative year)
                $dateStr = $row[$column]->format('Y-m-d H:i:s');
                if ($this->isDateEmptyOrInvalid($dateStr)) {
                    $this->logger->warning("Setting placeholder date for invalid DateTimeInterface in column {$column}: {$dateStr}");
                    $row[$column] = $specialDate;
                }
            } elseif (is_string($row[$column])) {
                try {
                    $row[$column] = new \DateTimeImmutable($row[$column]);
                } catch (\Exception $e) {
                    $this->logger->warning("Setting placeholder date for invalid string format in column {$column}: " . $e->getMessage());
                    $row[$column] = $specialDate;
                }
            }

            // Log final value after processing
            $this->logger->debug("Final value for column {$column}: " . ($row[$column] instanceof \DateTimeInterface ? $row[$column]->format('Y-m-d H:i:s') : print_r($row[$column], true)));
        }
        return $row;
    }

    /**
     * Checks if a value is empty or invalid for a date column.
     *
     * @param string|DateTimeInterface $val
     * @return bool
     */
    public function isDateEmptyOrInvalid($val): bool
    {
        // Convert DateTimeInterface to string if needed
        if ($val instanceof \DateTimeInterface) {
            $val = $val->format('Y-m-d H:i:s');
        }

        return
            empty($val) ||
            $val === '0000-00-00 00:00:00' ||
            str_starts_with($val, '-');
    }
}
