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

/**
 * Service responsible for loading data from source to temporary table.
 */
class SourceToTempLoader
{
    private readonly GenericSchemaManager $schemaManager;
    private readonly LoggerInterface $logger;

    public function __construct(
        GenericSchemaManager $schemaManager,
        ?LoggerInterface $logger = null
    ) {
        $this->schemaManager = $schemaManager;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Loads data from source table to temp table.
     *
     * @param TableSyncConfigDTO $config The configuration for the data loading.
     * @return void
     * @throws TableSyncerException
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
            'sql'                => $insertSql,
            'targetColumnsCount' => count($targetInsertDataColumns),
            'placeholdersCount'  => count($placeholders)
        ]);

        $rowCount = 0;
        while ($row = $result->fetchAssociative()) {
            $processedRow = $this->ensureDatetimeValues($config, $row);

            $orderedParamValues = [];
            $orderedParamTypes = [];

            foreach ($targetInsertDataColumns as $targetColName) {
                $sourceColName = null;
                try {
                    $sourceColName = $config->getMappedSourceColumnName($targetColName);
                } catch (\InvalidArgumentException $e) {
                    $this->logger->error("Critical error: Target column '{$targetColName}' for INSERT not found in config mappings.", ['target_col' => $targetColName, 'exception_msg' => $e->getMessage()]);
                    throw new TableSyncerException("Configuration error mapping target column '{$targetColName}' back to a source column.", 0, $e);
                }

                if (!array_key_exists($sourceColName, $processedRow)) {
                    $this->logger->error("Value missing for source column '{$sourceColName}' (maps to target '{$targetColName}') in fetched row. This indicates an issue with the source SELECT query or data consistency.", [
                        'source_col'         => $sourceColName,
                        'target_col'         => $targetColName,
                        'available_row_keys' => array_keys($processedRow)
                    ]);
                    $orderedParamValues[] = null;
                    $orderedParamTypes[] = ParameterType::NULL;
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
                    'sql'                        => $insertSql,
                    'param_count'                => count($orderedParamValues),
                    'placeholder_count'          => count($placeholders),
                    'target_insert_cols_for_sql' => $targetInsertDataColumns,
                ]);
                throw new TableSyncerException("Internal error: Parameter count mismatch for INSERT into temp table. Expected " . count($placeholders) . ", got " . count($orderedParamValues));
            }

            try {
                $insertStmt->executeStatement($orderedParamValues, $orderedParamTypes);
                $rowCount++;
            } catch (\Exception $e) {
                $this->logger->error('Failed to execute insert statement for temp table: ' . $e->getMessage(), [
                    'sql'             => $insertSql,
                    'params_count'    => count($orderedParamValues),
                    'types_count'     => count($orderedParamTypes),
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
                        new \DateTimeImmutable($originalValue);
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
        if (in_array($dateString, [
            '0000-00-00',
            '0000-00-00 00:00:00',
            '00:00:00',
            '0',
        ], true)) {
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
     * @param string|\DateTimeInterface $val
     * @return bool
     */
    protected function isDateEmptyOrInvalid($val): bool
    {
        if ($val === null) {
            return true;
        }
        if (is_string($val)) {
            $val = trim($val);
            return $val === '' || $val === '0' || $val === '0000-00-00' || $val === '0000-00-00 00:00:00';
        }
        if ($val instanceof \DateTimeInterface) {
            return $val->format('Y-m-d H:i:s') === '0000-00-00 00:00:00';
        }
        return true;
    }
}
