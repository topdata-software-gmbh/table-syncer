<?php

namespace TopdataSoftwareGmbh\TableSyncer\DTO;

use Doctrine\DBAL\Connection;

/**
 * Configuration DTO for a generic table synchronization operation.
 */
class TableSyncConfigDTO
{
    public Connection $sourceConnection;
    public string $sourceTableName;

    /** @var array<string, string> Maps source primary key column names to target primary key column names. */
    public array $primaryKeyColumnMap;

    /** @var array<string, string> Maps source data column names to target data column names. Primary key columns that are also data should be included here. */
    public array $dataColumnMapping;

    public Connection $targetConnection;
    public string $targetLiveTableName;
    public string $targetTempTableName;

    /** @var string[] Subset of source columns (keys in dataColumnMapping) used for generating the content hash. */
    public array $columnsForContentHash;

    /** @var string[] Source datetime column names that cannot be null and should be handled with special date processing. */
    public array $nonNullableDatetimeSourceColumns = [];

    public MetadataColumnNamesDTO $metadataColumns;

    /** @var string Type for the auto-incrementing ID column in target tables (e.g., Types::INTEGER). */
    public string $targetIdColumnType = \Doctrine\DBAL\Types\Types::INTEGER;
    /** @var string Type for hash column (e.g., Types::STRING) */
    public string $targetHashColumnType = \Doctrine\DBAL\Types\Types::STRING;
    /** @var int Length for hash column if string type */
    public int $targetHashColumnLength = 64; // SHA256 hex output

    public function __construct(
        Connection              $sourceConnection,
        string                  $sourceTableName,
        array                   $primaryKeyColumnMap,
        array                   $dataColumnMapping,
        Connection              $targetConnection,
        string                  $targetLiveTableName,
        array                   $columnsForContentHash,
        ?MetadataColumnNamesDTO $metadataColumns = null,
        array                   $nonNullableDatetimeSourceColumns = [],
        ?string                 $targetTempTableName = null
    )
    {
        $this->sourceConnection = $sourceConnection;
        $this->sourceTableName = $sourceTableName;
        $this->primaryKeyColumnMap = $primaryKeyColumnMap;
        $this->dataColumnMapping = $dataColumnMapping;

        $this->targetConnection = $targetConnection;
        $this->targetLiveTableName = $targetLiveTableName;
        $this->targetTempTableName = $targetTempTableName ?? $targetLiveTableName . '_temp';
        $this->columnsForContentHash = $columnsForContentHash;
        $this->nonNullableDatetimeSourceColumns = $nonNullableDatetimeSourceColumns;
        $this->metadataColumns = $metadataColumns ?? new MetadataColumnNamesDTO();

        // Basic validation
        if (empty($this->primaryKeyColumnMap)) {
            throw new \InvalidArgumentException('Primary key column mapping cannot be empty.');
        }
        if (empty($this->dataColumnMapping)) {
            throw new \InvalidArgumentException('Data column mapping cannot be empty.');
        }
        if (empty($this->columnsForContentHash)) {
            throw new \InvalidArgumentException('Columns for content hash cannot be empty.');
        }

        // Validate that source primary key columns are defined in dataColumnMapping
        foreach (array_keys($this->primaryKeyColumnMap) as $sourcePkColumn) {
            if (!array_key_exists($sourcePkColumn, $this->dataColumnMapping)) {
                throw new \InvalidArgumentException("Source primary key column '{$sourcePkColumn}' must be defined in dataColumnMapping.");
            }
        }

        // Validate that columns for content hash are defined in dataColumnMapping
        foreach ($this->columnsForContentHash as $sourceHashColumn) {
            if (!array_key_exists($sourceHashColumn, $this->dataColumnMapping)) {
                throw new \InvalidArgumentException("Source column '{$sourceHashColumn}' (for content hash) must be defined in dataColumnMapping.");
            }
        }

        // Validate that non-nullable datetime columns are defined in dataColumnMapping
        foreach ($this->nonNullableDatetimeSourceColumns as $sourceDatetimeColumn) {
            if (!array_key_exists($sourceDatetimeColumn, $this->dataColumnMapping)) {
                throw new \InvalidArgumentException("Non-nullable source datetime column '{$sourceDatetimeColumn}' must be defined in dataColumnMapping.");
            }
        }
    }

    /**
     * Gets source primary key column names.
     */
    public function getSourcePrimaryKeyColumns(): array
    {
        return array_keys($this->primaryKeyColumnMap);
    }

    /**
     * Gets target primary key column names.
     */
    public function getTargetPrimaryKeyColumns(): array
    {
        return array_values($this->primaryKeyColumnMap);
    }

    /**
     * Gets source data column names.
     */
    public function getSourceDataColumns(): array
    {
        return array_keys($this->dataColumnMapping);
    }

    /**
     * Gets target data column names.
     */
    public function getTargetDataColumns(): array
    {
        return array_values($this->dataColumnMapping);
    }

    /**
     * Gets the target column name for a given source column name.
     *
     * @param string $sourceColumnName The source column name
     * @return string The corresponding target column name
     * @throws \InvalidArgumentException If the source column is not found in the mapping
     */
    public function getTargetColumnName(string $sourceColumnName): string
    {
        if (!array_key_exists($sourceColumnName, $this->dataColumnMapping)) {
            throw new \InvalidArgumentException("Source column '{$sourceColumnName}' not found in dataColumnMapping.");
        }
        return $this->dataColumnMapping[$sourceColumnName];
    }

    /**
     * Gets target column names for content hash generation.
     */
    public function getTargetColumnsForContentHash(): array
    {
        $targetColumns = [];
        foreach ($this->columnsForContentHash as $sourceColumn) {
            $targetColumns[] = $this->getTargetColumnName($sourceColumn);
        }
        return $targetColumns;
    }

    /**
     * Gets target column names for non-nullable datetime columns.
     */
    public function getTargetNonNullableDatetimeColumns(): array
    {
        $targetColumns = [];
        foreach ($this->nonNullableDatetimeSourceColumns as $sourceColumn) {
            $targetColumns[] = $this->getTargetColumnName($sourceColumn);
        }
        return $targetColumns;
    }

    /**
     * Gets all columns that should exist in the temp table.
     * This includes target primary keys, data columns, and specific metadata.
     */
    public function getTempTableColumns(): array
    {
        return array_unique(array_merge(
            $this->getTargetPrimaryKeyColumns(),
            $this->getTargetDataColumns(),
            [$this->metadataColumns->contentHash, $this->metadataColumns->createdAt]
        ));
    }
}

