<?php

namespace TopdataSoftwareGmbh\TableSyncer\Service;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TopdataSoftwareGmbh\TableSyncer\DTO\SyncReportDTO;
use TopdataSoftwareGmbh\TableSyncer\DTO\TableSyncConfigDTO;
use TopdataSoftwareGmbh\TableSyncer\Util\DbalHelper;
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
    )
    {
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
        $targetConn->beginTransaction();
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
            $this->loadDataFromSourceToTemp($config);

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

            $targetConn->commit();
            $this->logger->info('Sync completed successfully', [
                'inserted' => $report->insertedCount,
                'updated' => $report->updatedCount,
                'deleted' => $report->deletedCount,
                'initialInsert' => $report->initialInsertCount
            ]);
            return $report;
        } catch (\Throwable $e) {
            $targetConn->rollBack();
            $this->logger->error('Sync failed: ' . $e->getMessage(), [
                'exception' => $e,
                'source' => $config->sourceTableName,
                'target' => $config->targetLiveTableName
            ]);
            throw new TableSyncerException('Sync failed: ' . $e->getMessage(), 0, $e);
    }

    /**
     * Loads data from source table to temp table.
     *
     * @param TableSyncConfigDTO $config
     * @return void
     */
    protected function loadDataFromSourceToTemp(TableSyncConfigDTO $config): void
    {
        $this->logger->debug('Loading data from source to temp table', [
            'sourceTable' => $config->sourceTableName,
            'tempTable' => $config->targetTempTableName
        ]);

        $sourceConn = $config->sourceConnection;
        $targetConn = $config->targetConnection;
        
        $sourceTable = $sourceConn->quoteIdentifier($config->sourceTableName);
        $tempTable = $targetConn->quoteIdentifier($config->targetTempTableName);
        
        // Build column lists
        $sourceColumns = array_merge(
            array_keys($config->getPrimaryKeyColumnMap()),
            array_keys($config->getDataColumnMapping())
        );
        
        $targetColumns = array_merge(
            array_values($config->getPrimaryKeyColumnMap()),
            array_values($config->getDataColumnMapping())
        );
        
        $quotedSourceColumns = array_map(fn($col) => $sourceConn->quoteIdentifier($col), $sourceColumns);
        $quotedTargetColumns = array_map(fn($col) => $targetConn->quoteIdentifier($col), $targetColumns);
        
        // Get source column types for proper parameter binding
        $sourceColumnTypes = $this->schemaManager->getSourceColumnTypes($config);
        
        // Get data from source table
        $stmt = $sourceConn->prepare("SELECT " . implode(", ", $quotedSourceColumns) . " FROM {$sourceTable}");
        $result = $stmt->executeQuery();
        
        // Prepare INSERT statement for temp table
        $placeholders = array_map(fn() => '?', $targetColumns);
        $insertSql = "INSERT INTO {$tempTable} (" . implode(", ", $quotedTargetColumns) . ") VALUES (" . implode(", ", $placeholders) . ")";        
        $insertStmt = $targetConn->prepare($insertSql);
        
        $this->logger->debug('Prepared insert statement', ['sql' => $insertSql]);
        
        // Process rows and insert into temp table
        $rowCount = 0;
        while ($row = $result->fetchAssociative()) {
            // Ensure datetime values in the row using configured placeholder
            $row = $this->ensureDatetimeValues($config, $row);
            
            // Map source column names to target column names and prepare values for binding
            $paramValues = [];
            $paramTypes = [];
            
            // Process primary key columns
            foreach ($config->getPrimaryKeyColumnMap() as $sourceCol => $targetCol) {
                $paramValues[] = $row[$sourceCol] ?? null;
                // Use schema type info when available, fall back to runtime type detection
                $paramTypes[] = isset($sourceColumnTypes[$sourceCol]) ? 
                    $this->dbalTypeToParameterType($sourceColumnTypes[$sourceCol]) : 
                    $this->getDbalParamType($sourceCol, $row[$sourceCol] ?? null);
            }
            
            // Process data columns
            foreach ($config->getDataColumnMapping() as $sourceCol => $targetCol) {
                $paramValues[] = $row[$sourceCol] ?? null;
                // Use schema type info when available, fall back to runtime type detection
                $paramTypes[] = isset($sourceColumnTypes[$sourceCol]) ? 
                    $this->dbalTypeToParameterType($sourceColumnTypes[$sourceCol]) : 
                    $this->getDbalParamType($sourceCol, $row[$sourceCol] ?? null);
            }
            
            // Execute insert with bound parameters
            $insertStmt->executeStatement($paramValues, $paramTypes);
            $rowCount++;
            
            // Log progress periodically
            if ($rowCount % 1000 === 0) {
                $this->logger->debug("Processed {$rowCount} rows so far");
            }
        }
        
        $this->logger->info("Loaded {$rowCount} rows from source to temp table");
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
        $count = $targetConn->fetchOne("SELECT COUNT(*) FROM {$liveTable}");
        
        if ((int)$count === 0) {
            // If live table is empty, do a direct insert of all rows from temp
            $this->logger->info('Live table is empty, doing initial import');
            
            $colsToInsertLive = array_merge(
                $config->getTargetPrimaryKeyColumns(),
                $config->getTargetDataColumns(),
                [$meta->contentHash, $meta->createdAt, $meta->batchRevision]
            );
            $quotedColsToInsertLive = array_map(fn($c) => $targetConn->quoteIdentifier($c), $colsToInsertLive);
            
            $colsToSelectTemp = array_merge(
                $config->getTargetPrimaryKeyColumns(), // Assumes names are same in temp
                $config->getTargetDataColumns(),
                [$meta->contentHash, $meta->createdAt]
            );
            $quotedColsToSelectTemp = array_map(fn($c) => $tempTable . "." . $targetConn->quoteIdentifier($c), $colsToSelectTemp);

            // Construct and log the SQL
            $sqlInitialInsert = "INSERT INTO {$liveTable} (" . implode(', ', $quotedColsToInsertLive) . ") "
                . "SELECT " . implode(', ', $quotedColsToSelectTemp) . ", ? " // ? for batch_revision
                . "FROM {$tempTable}";
            
            $this->logger->debug('Executing initial insert SQL', ['sql' => $sqlInitialInsert]);
            $report->initialInsertCount = $targetConn->executeStatement($sqlInitialInsert, [$currentBatchRevisionId]);
            $report->addLogMessage("Initial import: {$report->initialInsertCount} rows inserted.");
            return;
        }

        // Join condition string for matching rows between live and temp
        $joinConditions = [];
        foreach ($config->getTargetPrimaryKeyColumns() as $keyCol) {
            $quotedKeyCol = $targetConn->quoteIdentifier($keyCol);
            $joinConditions[] = "{$liveTable}.{$quotedKeyCol} = {$tempTable}.{$quotedKeyCol}";
        }
        $joinConditionStr = implode(' AND ', $joinConditions);
        $this->logger->debug('Join condition for sync', ['condition' => $joinConditionStr]);

        // --- B. Handle Updates ---
        // (rows in temp that are also in live but content_hash differs)
        $setClausesForUpdate = [];
        // Update data columns and the hash
        foreach (array_unique(array_merge($config->getTargetDataColumns(), [$meta->contentHash])) as $col) {
            $qCol = $targetConn->quoteIdentifier($col);
            $setClausesForUpdate[] = "{$liveTable}.{$qCol} = {$tempTable}.{$qCol}";
        }
        $setClausesForUpdate[] = "{$liveTable}." . $targetConn->quoteIdentifier($meta->updatedAt) . " = CURRENT_TIMESTAMP";
        $setClausesForUpdate[] = "{$liveTable}." . $targetConn->quoteIdentifier($meta->batchRevision) . " = ?";

        // Use explicit collation for the comparison to avoid collation mismatch errors in MySQL
        $contentHashCol = $targetConn->quoteIdentifier($meta->contentHash);
        $sqlUpdate = "UPDATE {$liveTable}
            INNER JOIN {$tempTable} ON {$joinConditionStr}
            SET " . implode(', ', $setClausesForUpdate) . "
            WHERE {$liveTable}.{$contentHashCol} <> {$tempTable}.{$contentHashCol}";
        
        $this->logger->debug('Executing update SQL', ['sql' => $sqlUpdate]);
        $report->updatedCount = $targetConn->executeStatement($sqlUpdate, [$currentBatchRevisionId]);
        $report->addLogMessage("Rows updated due to hash mismatch: {$report->updatedCount}.");

        try {
            // --- C. Handle Deletes ---
            // (rows in live that are NOT in temp table - implies temp table is complete desired state)
            $targetPkColumns = $config->getTargetPrimaryKeyColumns();
            $deletePkColForNullCheck = $targetConn->quoteIdentifier($targetPkColumns[0]); // Use first PK col for NULL check
            
            // MySQL-specific DELETE with JOIN syntax
            $sqlDelete = "DELETE {$liveTable} FROM {$liveTable} "
                . "LEFT JOIN {$tempTable} ON {$joinConditionStr}
                WHERE {$tempTable}.{$deletePkColForNullCheck} IS NULL";
            
            $this->logger->debug('Executing delete SQL', ['sql' => $sqlDelete]);
            $report->deletedCount = $targetConn->executeStatement($sqlDelete);
        $report->addLogMessage("Rows deleted from live (not in source/temp): {$report->deletedCount}.");

        // --- D. Handle Inserts ---
        // (rows in temp that are not in live table)
        $colsToInsertLive = array_merge(
            $config->getTargetPrimaryKeyColumns(),
            $config->getTargetDataColumns(),
            [$meta->contentHash, $meta->createdAt, $meta->batchRevision]
        );
        $quotedColsToInsertLive = array_map(fn($c) => $targetConn->quoteIdentifier($c), $colsToInsertLive);

        $colsToSelectTemp = array_merge(
            $config->getTargetPrimaryKeyColumns(),
            $config->getTargetDataColumns(),
            [$meta->contentHash, $meta->createdAt]
        );
        $quotedColsToSelectTemp = array_map(fn($c) => $tempTable . "." . $targetConn->quoteIdentifier($c), $colsToSelectTemp);

        $insertPkColForNullCheck = $targetConn->quoteIdentifier($targetPkColumns[0]);
        $sqlInsert = "INSERT INTO {$liveTable} (" . implode(', ', $quotedColsToInsertLive) . ") "
            . "SELECT " . implode(', ', $quotedColsToSelectTemp) . ", ? " // ? for batch_revision
            . "FROM {$tempTable}
            LEFT JOIN {$liveTable} ON {$joinConditionStr}
            WHERE {$liveTable}.{$insertPkColForNullCheck} IS NULL";
        
        $this->logger->debug('Executing insert SQL', ['sql' => $sqlInsert]);
        $report->insertedCount = $targetConn->executeStatement($sqlInsert, [$currentBatchRevisionId]);
        $report->addLogMessage("New rows inserted into live: {$report->insertedCount}.");
        } catch (\Throwable $e) {
            $this->logger->error("Error during temp to live synchronization: {$e->getMessage()}", ['exception' => $e]);
            throw new TableSyncerException("Synchronization from temp to live failed: " . $e->getMessage(), 0, $e);
        }
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
        $specialDate = new \DateTimeImmutable($config->placeholderDatetime);

        foreach ($config->nonNullableDatetimeSourceColumns as $column) {
            // Log original value for debugging
            $originalValue = $row[$column] ?? 'NULL';
            $this->logger->debug("Processing datetime column: {$column}, original value: " . print_r($originalValue, true));

            if (empty($row[$column])) {
                $this->logger->debug("Setting placeholder date for NULL value in column: {$column}");
                $row[$column] = $specialDate;
            } elseif ($row[$column] instanceof \DateTimeInterface) {
                // Check if it's an invalid date (like negative year)
                $dateStr = $row[$column]->format('Y-m-d H:i:s');
                if ($this->isDateEmptyOrInvalid($dateStr)) {
                    $this->logger->debug("Setting placeholder date for invalid DateTimeInterface in column {$column}: {$dateStr}");
                    $row[$column] = $specialDate;
                }
            } elseif (is_string($row[$column])) {
                try {
                    $row[$column] = new \DateTimeImmutable($row[$column]);
                } catch (\Exception $e) {
                    $this->logger->debug("Setting placeholder date for invalid string format in column {$column}: " . $e->getMessage());
                    $row[$column] = $specialDate;
                }
            }

            // Log final value after processing
            $this->logger->debug("Final value for column {$column}: " . ($row[$column] instanceof \DateTimeInterface ? $row[$column]->format('Y-m-d H:i:s') : print_r($row[$column], true)));
        }
        return $row;
    }

    /**
     * Gets the DBAL parameter type for a value based on runtime type detection.
     *
     * @param string $columnName Column name (unused, kept for API compatibility)
     * @param mixed $value The value to determine type for
     * @return int DBAL ParameterType constant
     */
    protected function getDbalParamType(string $columnName, $value): int
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
        
        if (is_float($value)) {
            return ParameterType::STRING; // No specific float type in DBAL ParameterType
        }
        
        if ($value instanceof \DateTimeInterface) {
            return ParameterType::STRING;
        }
        
        // Default
        return ParameterType::STRING;
    }
    
    /**
     * Converts a DBAL Type to a DBAL ParameterType.
     *
     * @param string $dbalType DBAL Type constant
     * @return int DBAL ParameterType constant
     */
    protected function dbalTypeToParameterType(string $dbalType): int
    {
        switch ($dbalType) {
            case Types::INTEGER:
            case Types::BIGINT:
            case Types::SMALLINT:
                return ParameterType::INTEGER;
                
            case Types::BOOLEAN:
                return ParameterType::BOOLEAN;
                
            case Types::BLOB:
            case Types::BINARY:
                return ParameterType::BINARY;
                
            // All other types (including dates, strings, decimals, etc.) use STRING parameter type
            default:
                return ParameterType::STRING;
        }
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
