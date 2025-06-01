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

    public function __construct(
        GenericSchemaManager $schemaManager,
        ?LoggerInterface     $logger = null
    )
    {
        $this->schemaManager = $schemaManager;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Loads data from source table to temp table.
     *
     * @param TableSyncConfigDTO $config The configuration for the data loading.
     * @return void
     * @throws TableSyncerException|\Doctrine\DBAL\Exception
     */
    public function load(TableSyncConfigDTO $config): void
    {
        $this->logger->debug('Loading data from source to temp table', [
            'sourceTable' => $config->sourceTableName,
            'tempTable'   => $config->targetTempTableName
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
        // Ensure this list is 0-indexed and ordered for SQL construction and parameter binding.
        $targetInsertDataColumnsOriginal = array_unique(array_merge(
            $config->getTargetPrimaryKeyColumns(), // Mapped PKs
            $config->getTargetDataColumns()      // Mapped data columns
        ));

        if (empty($targetInsertDataColumnsOriginal)) {
            $this->logger->warning("No target columns defined for INSERT into '{$config->targetTempTableName}' based on mappings. Cannot load data from source.");
            return;
        }
        // This $orderedTargetColumnNamesList is crucial for DBAL 4.x parameter binding loop
        $orderedTargetColumnNamesList = array_values($targetInsertDataColumnsOriginal);

        $quotedTargetInsertDataColumnsForSql = array_map(fn($col) => $targetConn->quoteIdentifier($col), $orderedTargetColumnNamesList);

        // Get source column types for proper parameter binding
        $sourceColumnTypes = $this->schemaManager->getSourceColumnTypes($config);

        // Get data from source table
        $selectSql = "SELECT " . implode(", ", $quotedSourceSelectColumns) . " FROM {$sourceTableIdentifier}";
        $this->logger->debug("Executing source data SELECT SQL", ['sql' => $selectSql]);
        $stmt = $sourceConn->prepare($selectSql);
        $result = $stmt->executeQuery();

        // Prepare INSERT statement for temp table
        // Placeholders count should match the number of columns in $orderedTargetColumnNamesList
        $placeholders = array_fill(0, count($orderedTargetColumnNamesList), '?');
        $insertSql = "INSERT INTO {$tempTableIdentifier} (" . implode(", ", $quotedTargetInsertDataColumnsForSql) . ") VALUES (" . implode(", ", $placeholders) . ")";

        $insertStmt = $targetConn->prepare($insertSql);

        $this->logger->debug('Prepared insert statement for temp table', [
            'sql'                => $insertSql,
            'targetColumnsCount' => count($orderedTargetColumnNamesList),
            'placeholdersCount'  => count($placeholders)
        ]);

        $rowCount = 0;
        while ($row = $result->fetchAssociative()) {
            $processedRow = $this->ensureDatetimeValues($config, $row);

            // Bind parameters one by one for DBAL 4.x
            $paramIndex = 1; // Positional placeholders are 1-indexed for bindValue

            // Temporary arrays for logging if needed for the first row
            $firstRowParamValuesForLog = [];
            $firstRowParamTypesForLog = [];

            foreach ($orderedTargetColumnNamesList as $targetColName) {
                $sourceColName = null;
                try {
                    $sourceColName = $config->getMappedSourceColumnName($targetColName);
                } catch (\InvalidArgumentException $e) {
                    $this->logger->error("Critical error: Target column '{$targetColName}' for INSERT not found in config mappings.", ['target_col' => $targetColName, 'exception_msg' => $e->getMessage()]);
                    throw new TableSyncerException("Configuration error mapping target column '{$targetColName}' back to a source column.", 0, $e);
                }

                $valueToBind = null;
                $dbalBindingType = ParameterType::NULL; // Default to NULL

                if (!array_key_exists($sourceColName, $processedRow)) {
                    $this->logger->error("Value missing for source column '{$sourceColName}' (maps to target '{$targetColName}') in fetched row. This indicates an issue with the source SELECT query or data consistency.", [
                        'source_col'         => $sourceColName,
                        'target_col'         => $targetColName,
                        'available_row_keys' => array_keys($processedRow)
                    ]);
                    // Value is already null, type is ParameterType::NULL
                } else {
                    $valueToBind = $processedRow[$sourceColName];
                    $sourceDbalTypeName = $sourceColumnTypes[$sourceColName] ?? null;

                    if ($sourceDbalTypeName) {
                        $dbalBindingType = $this->dbalTypeToParameterType($sourceDbalTypeName);
                    } else {
                        $this->logger->warning("DBAL type not found for source column '{$sourceColName}', falling back to runtime type detection for binding.", ['source_col' => $sourceColName]);
                        $dbalBindingType = $this->getDbalParamType($sourceColName, $valueToBind);
                    }
                }

                // Collect for logging if it's the first row
                if ($rowCount === 0) {
                    $firstRowParamValuesForLog[] = $valueToBind;
                    $firstRowParamTypesForLog[] = $dbalBindingType;
                }

                try {
                    $insertStmt->bindValue($paramIndex, $valueToBind, $dbalBindingType);
                } catch (\Doctrine\DBAL\Exception $e) {
                    $this->logger->error("Failed to bind value for paramIndex {$paramIndex}", [
                        'target_column' => $targetColName,
                        'source_column' => $sourceColName,
                        // 'value' => $valueToBind, // Be careful logging sensitive data here
                        'type'          => $dbalBindingType,
                        'exception'     => $e->getMessage(),
                    ]);
                    throw $e; // Re-throw DBAL exception
                }
                $paramIndex++;
            }

            // ---- Log the first row attempt only (after binding) ----
            if ($rowCount === 0) {
                $this->logger->debug('First row insert details (DBAL 4.x style after bindValue):', [
                    'sql'                       => $insertSql,
                    'target_columns_for_insert' => $orderedTargetColumnNamesList, // Log the ordered list
                    'parameter_values_bound'    => $firstRowParamValuesForLog,     // Log collected values
                    'parameter_types_mapped'    => array_map(fn($type) => match ($type) { // Log collected types
                        ParameterType::NULL         => 'NULL',
                        ParameterType::INTEGER      => 'INTEGER',
                        ParameterType::STRING       => 'STRING',
                        ParameterType::LARGE_OBJECT => 'LARGE_OBJECT',
                        ParameterType::BOOLEAN      => 'BOOLEAN',
                        ParameterType::BINARY       => 'BINARY',
                        ParameterType::ASCII        => 'ASCII',
                        default                     => 'UNKNOWN_TYPE_NO:' . (is_object($type) ? get_class($type) : $type),
                    }, $firstRowParamTypesForLog),
                    'source_row_processed'      => $processedRow,
                ]);
            }

            try {
                // ExecuteStatement now takes no arguments in DBAL 4.x
                $insertStmt->executeStatement();
                $rowCount++;
            } catch (\Exception $e) { // Catch generic Exception, could be DBAL\Exception or other
                $this->logger->error('Failed to execute insert statement for temp table: ' . $e->getMessage(), [
                    'sql'             => $insertSql,
                    // 'bound_params' => $insertStmt->params, // $params is protected in DBAL Statement
                    'exception_class' => get_class($e),
                    'exception_trace' => $e->getTraceAsString()
                ]);
                throw $e;
            }

            if ($rowCount > 0 && $rowCount % 1000 === 0) {
                $this->logger->debug("Loaded {$rowCount} rows into temp table '{$config->targetTempTableName}' so far...");
            }
        }
        $this->logger->info("Successfully loaded {$rowCount} rows from source '{$config->sourceTableName}' to temp table '{$config->targetTempTableName}'.");
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
                $this->logger->debug("Setting placeholder date for empty/NULL value in non-nullable datetime column: {$sourceColumnName}");
                $row[$sourceColumnName] = $specialDate;
                $valueChanged = true;
            } elseif ($originalValue instanceof \DateTimeInterface) {
                $dateStr = $originalValue->format('Y-m-d H:i:s');
                if ($this->isDateEffectivelyZeroOrInvalid($dateStr)) {
                    $this->logger->debug("Setting placeholder date for effectively zero/invalid DateTimeInterface in column {$sourceColumnName}: {$dateStr}");
                    $row[$sourceColumnName] = $specialDate;
                    $valueChanged = true;
                }
            } elseif (is_string($originalValue)) {
                if ($this->isDateEffectivelyZeroOrInvalid($originalValue)) {
                    $this->logger->debug("Setting placeholder date for effectively zero/invalid date string in column {$sourceColumnName}: '{$originalValue}'");
                    $row[$sourceColumnName] = $specialDate;
                    $valueChanged = true;
                } else {
                    try {
                        // Check if it's a valid date string, if not, replace.
                        // DBAL might handle string dates, but this ensures only valid ones pass or get placeholder.
                        if (new \DateTimeImmutable($originalValue) === false) { // Should not happen, would throw
                            // This path might be redundant due to try-catch
                        }
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
        // Default to string for other types (float, string, etc.)
        // DBAL will handle string conversion for types like float.
        return ParameterType::STRING;
    }

    /**
     * Converts a DBAL Type name (string) to a DBAL ParameterType constant (integer).
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
                return ParameterType::LARGE_OBJECT;

            // Add more explicit mappings if necessary, otherwise default to STRING
            case Types::DATE_MUTABLE:
            case Types::DATE_IMMUTABLE:
            case Types::DATETIME_MUTABLE:
            case Types::DATETIME_IMMUTABLE:
            case Types::DATETIMETZ_MUTABLE:
            case Types::DATETIMETZ_IMMUTABLE:
            case Types::TIME_MUTABLE:
            case Types::TIME_IMMUTABLE:
            case Types::DECIMAL: // Decimals are often passed as strings
            case Types::FLOAT:  // Floats are often passed as strings or locale-dependent
            case Types::TEXT:
            case Types::STRING:
            case Types::GUID:
                // case Types::JSON: // JSON can be string
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
        if (in_array(trim($dateString), [ // trim here as well
            '0000-00-00',
            '0000-00-00 00:00:00',
            '00:00:00', // This might be too broad if time-only columns are valid with this
            '0',
        ], true)) {
            return true;
        }
        // Check if it's a date starting with many zeros, like "0001-01-01" might be invalid in some contexts
        // but "0000-..." is definitely an issue for most DBs as a valid timestamp.
        if (str_starts_with($dateString, '0000-')) {
            return true;
        }
        if (str_starts_with($dateString, '-')) { // Negative dates are usually not valid
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
    protected function isDateEmptyOrInvalid($val): bool // Changed type hint for $val to mixed
    {
        if ($val === null) {
            return true;
        }
        if (is_string($val)) {
            $trimmedVal = trim($val);
            return $trimmedVal === '' || $trimmedVal === '0' || $this->isDateEffectivelyZeroOrInvalid($trimmedVal);
        }
        if ($val instanceof \DateTimeInterface) {
            // Check if the formatted date string is effectively zero
            return $this->isDateEffectivelyZeroOrInvalid($val->format('Y-m-d H:i:s'));
        }
        // For other types, consider them invalid for a date context by default
        return true;
    }
}