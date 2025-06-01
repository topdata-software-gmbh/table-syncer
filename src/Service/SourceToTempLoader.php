<?php

declare(strict_types=1);

namespace TopdataSoftwareGmbh\TableSyncer\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TopdataSoftwareGmbh\TableSyncer\DTO\TableSyncConfigDTO;
use TopdataSoftwareGmbh\TableSyncer\Exception\TableSyncerException;

// use TopdataSoftwareGmbH\Util\UtilDebug; // Assuming this is not strictly needed for the fix

/**
 * Service responsible for loading data from source to temporary table.
 */
class SourceToTempLoader
{
    private readonly GenericSchemaManager $schemaManager;
    private readonly LoggerInterface $logger;

    /**
     * Number of rows to include in each batch insert.
     * Adjust based on performance testing and database limits (max query length, max placeholders).
     */
    private const INSERT_BATCH_SIZE = 500;

    public function __construct(
        ?LoggerInterface     $logger = null,
        GenericSchemaManager $schemaManager = null
    )
    {
        $this->logger = $logger ?? new NullLogger();
        $this->schemaManager = $schemaManager ?? new GenericSchemaManager($this->logger);
    }

    /**
     * Loads data from source table to temp table using batch inserts.
     *
     * @param TableSyncConfigDTO $config The configuration for the data loading.
     * @return void
     * @throws TableSyncerException|\Doctrine\DBAL\Exception
     */
    public function load(TableSyncConfigDTO $config): void
    {
        $this->logger->debug('Loading data from source to temp table using batch inserts', [
            'sourceTable' => $config->sourceTableName,
            'tempTable'   => $config->targetTempTableName,
            'batchSize'   => self::INSERT_BATCH_SIZE,
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
        $targetInsertDataColumnsOriginal = array_unique(array_merge(
            $config->getTargetPrimaryKeyColumns(), // Mapped PKs
            $config->getTargetDataColumns()      // Mapped data columns
        ));

        if (empty($targetInsertDataColumnsOriginal)) {
            $this->logger->warning("No target columns defined for INSERT into '{$config->targetTempTableName}' based on mappings. Cannot load data from source.");
            return;
        }
        $orderedTargetColumnNamesList = array_values($targetInsertDataColumnsOriginal);
        $quotedTargetInsertDataColumnsForSql = array_map(fn($col) => $targetConn->quoteIdentifier($col), $orderedTargetColumnNamesList);

        // Get data from source table
        $selectSql = "SELECT " . implode(", ", $quotedSourceSelectColumns) . " FROM {$sourceTableIdentifier}";
        $this->logger->debug("Executing source data SELECT SQL", ['sql' => $selectSql]);
        $stmt = $sourceConn->prepare($selectSql);
        $result = $stmt->executeQuery();

        // Prepare for batch inserts
        $insertSqlColumnsPart = "INSERT INTO {$tempTableIdentifier} (" . implode(", ", $quotedTargetInsertDataColumnsForSql) . ") VALUES ";
        $singleRowPlaceholdersSql = "(" . implode(", ", array_fill(0, count($orderedTargetColumnNamesList), '?')) . ")";

        $batchParameters = [];
        $rowsInCurrentBatch = 0;
        $totalRowsLoaded = 0;

        while ($row = $result->fetchAssociative()) {
            $processedRow = $this->ensureDatetimeValues($config, $row);

            foreach ($orderedTargetColumnNamesList as $targetColName) {
                $sourceColName = null;
                try {
                    $sourceColName = $config->getMappedSourceColumnName($targetColName);
                } catch (\InvalidArgumentException $e) {
                    $this->logger->error("Critical error: Target column '{$targetColName}' for INSERT not found in config mappings. Batch insert cannot proceed.", ['target_col' => $targetColName, 'exception_msg' => $e->getMessage()]);
                    throw new TableSyncerException("Configuration error mapping target column '{$targetColName}' back to a source column.", 0, $e);
                }

                $valueToBind = null;
                if (!array_key_exists($sourceColName, $processedRow)) {
                    $this->logger->warning("Value missing for source column '{$sourceColName}' (maps to target '{$targetColName}') in fetched row. Using NULL for this value in the batch.", [
                        'source_col'         => $sourceColName,
                        'target_col'         => $targetColName,
                        'available_row_keys' => array_keys($processedRow)
                    ]);
                } else {
                    $valueToBind = $processedRow[$sourceColName];
                }
                $batchParameters[] = $valueToBind;
            }
            $rowsInCurrentBatch++;
            $totalRowsLoaded++;

            if ($rowsInCurrentBatch >= self::INSERT_BATCH_SIZE) {
                $multiRowPlaceholdersSql = implode(', ', array_fill(0, $rowsInCurrentBatch, $singleRowPlaceholdersSql));
                $currentBatchInsertSql = $insertSqlColumnsPart . $multiRowPlaceholdersSql;

                if ($totalRowsLoaded === $rowsInCurrentBatch) { // First batch logging
                    $this->logger->debug('Executing first batch insert:', [
                        'sql_structure' => $insertSqlColumnsPart . "(...multiple rows...)",
                        'rows_in_batch' => $rowsInCurrentBatch,
                        'num_params_total' => count($batchParameters),
                        'params_per_row' => count($orderedTargetColumnNamesList),
                        // 'first_row_params_example' => array_slice($batchParameters, 0, count($orderedTargetColumnNamesList)), // Uncomment for deep debug
                    ]);
                }

                try {
                    $targetConn->executeStatement($currentBatchInsertSql, $batchParameters);
                } catch (\Exception $e) {
                    $this->logger->error('Failed to execute batch insert statement for temp table: ' . $e->getMessage(), [
                        'sql_structure' => $insertSqlColumnsPart . "(...multiple rows...)",
                        'rows_in_batch' => $rowsInCurrentBatch,
                        'exception_class' => get_class($e),
                    ]);
                    throw $e; // Re-throw
                }

                $batchParameters = [];
                $rowsInCurrentBatch = 0;

                // Log progress periodically (e.g., every 10 batches)
                if ($totalRowsLoaded > 0 && ($totalRowsLoaded % (self::INSERT_BATCH_SIZE * 10)) === 0) {
                    $this->logger->debug("Loaded {$totalRowsLoaded} rows into temp table '{$config->targetTempTableName}' so far...");
                }
            }
        }

        // Insert any remaining rows in the last batch
        if ($rowsInCurrentBatch > 0) {
            $multiRowPlaceholdersSql = implode(', ', array_fill(0, $rowsInCurrentBatch, $singleRowPlaceholdersSql));
            $currentBatchInsertSql = $insertSqlColumnsPart . $multiRowPlaceholdersSql;

            $this->logger->debug('Executing final batch insert for remaining rows:', [
                'sql_structure' => $insertSqlColumnsPart . "(...multiple rows...)",
                'rows_in_batch' => $rowsInCurrentBatch,
                'num_params_total' => count($batchParameters),
            ]);

            try {
                $targetConn->executeStatement($currentBatchInsertSql, $batchParameters);
            } catch (\Exception $e) {
                $this->logger->error('Failed to execute final batch insert statement for temp table: ' . $e->getMessage(), [
                    'sql_structure' => $insertSqlColumnsPart . "(...multiple rows...)",
                    'rows_in_batch' => $rowsInCurrentBatch,
                    'exception_class' => get_class($e),
                ]);
                throw $e; // Re-throw
            }
        }

        $this->logger->info("Successfully loaded " . number_format($totalRowsLoaded, 0) . " rows from source '{$config->sourceTableName}' to temp table '{$config->targetTempTableName}'.");
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
                // $this->logger->debug("Setting placeholder date for empty/NULL value in non-nullable datetime column: {$sourceColumnName}"); // Too verbose for batch
                $row[$sourceColumnName] = $specialDate;
                $valueChanged = true;
            } elseif ($originalValue instanceof \DateTimeInterface) {
                $dateStr = $originalValue->format('Y-m-d H:i:s');
                if ($this->isDateEffectivelyZeroOrInvalid($dateStr)) {
                    // $this->logger->debug("Setting placeholder date for effectively zero/invalid DateTimeInterface in column {$sourceColumnName}: {$dateStr}"); // Too verbose
                    $row[$sourceColumnName] = $specialDate;
                    $valueChanged = true;
                }
            } elseif (is_string($originalValue)) {
                if ($this->isDateEffectivelyZeroOrInvalid($originalValue)) {
                    // $this->logger->debug("Setting placeholder date for effectively zero/invalid date string in column {$sourceColumnName}: '{$originalValue}'"); // Too verbose
                    $row[$sourceColumnName] = $specialDate;
                    $valueChanged = true;
                } else {
                    try {
                        new \DateTimeImmutable($originalValue); // Validate if it's a parseable date string
                    } catch (\Exception $e) {
                        // $this->logger->debug("Setting placeholder date for unparseable/invalid date string in column {$sourceColumnName}: '{$originalValue}'. Error: " . $e->getMessage()); // Too verbose
                        $row[$sourceColumnName] = $specialDate;
                        $valueChanged = true;
                    }
                }
            } else {
                $this->logger->warning("Unexpected type for non-nullable datetime column '{$sourceColumnName}'. Value not processed.", ['type' => gettype($originalValue), 'value' => $originalValue]);
            }

            // if ($valueChanged) { // Too verbose for batch logging
            //     $this->logger->debug("Final value for column {$sourceColumnName} after ensureDatetimeValues: " . ($row[$sourceColumnName] instanceof \DateTimeInterface ? $row[$sourceColumnName]->format('Y-m-d H:i:s') : print_r($row[$sourceColumnName], true)));
            // }
        }
        return $row;
    }

    /**
     * Gets the DBAL parameter type for a value based on runtime type detection.
     * Note: This method is not directly used by the batch insert logic which relies on DBAL's auto-detection for executeStatement.
     * Kept for potential other uses or if explicit type binding per parameter were re-introduced.
     *
     * @param string $columnName Column name (for logging/context if needed)
     * @param mixed $value The value to determine type for
     * @return ParameterType DBAL ParameterType constant (integer)
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
        return ParameterType::STRING;
    }

    /**
     * Converts a DBAL Type name (string) to a DBAL ParameterType constant (integer).
     * Note: This method is not directly used by the batch insert logic. Kept for potential other uses.
     *
     * @param string $dbalTypeName DBAL Type name (e.g., Types::INTEGER, "string")
     * @return ParameterType DBAL ParameterType constant (integer)
     */
    protected function dbalTypeToParameterType(string $dbalTypeName): ParameterType
    {
        switch (strtolower($dbalTypeName)) {
            case Types::INTEGER:
            case Types::BIGINT:
            case Types::SMALLINT:
                return ParameterType::INTEGER;

            case Types::BOOLEAN:
                return ParameterType::BOOLEAN;

            case Types::BLOB:
            case Types::BINARY:
                return ParameterType::LARGE_OBJECT; // or ParameterType::BINARY for smaller ones

            case Types::DATE_MUTABLE:
            case Types::DATE_IMMUTABLE:
            case Types::DATETIME_MUTABLE:
            case Types::DATETIME_IMMUTABLE:
            case Types::DATETIMETZ_MUTABLE:
            case Types::DATETIMETZ_IMMUTABLE:
            case Types::TIME_MUTABLE:
            case Types::TIME_IMMUTABLE:
            case Types::DECIMAL:
            case Types::FLOAT:
            case Types::TEXT:
            case Types::STRING:
            case Types::GUID:
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
        if (in_array(trim($dateString), [
            '0000-00-00',
            '0000-00-00 00:00:00',
            '00:00:00',
            '0',
        ], true)) {
            return true;
        }
        if (str_starts_with($dateString, '0000-')) {
            return true;
        }
        if (str_starts_with($dateString, '-')) {
            return true;
        }
        return false;
    }

    /**
     * Checks if a value is empty or invalid for a date column.
     *
     * @param mixed $val
     * @return bool
     */
    protected function isDateEmptyOrInvalid($val): bool
    {
        if ($val === null) {
            return true;
        }
        if (is_string($val)) {
            $trimmedVal = trim($val);
            return $trimmedVal === '' || $trimmedVal === '0' || $this->isDateEffectivelyZeroOrInvalid($trimmedVal);
        }
        if ($val instanceof \DateTimeInterface) {
            return $this->isDateEffectivelyZeroOrInvalid($val->format('Y-m-d H:i:s'));
        }
        return true;
    }
}