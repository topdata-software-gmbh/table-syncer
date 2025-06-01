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

    /** @var bool Whether to enable deletion logging */
    public bool $enableDeletionLogging = false;
    /** @var string|null Name of the deletion log table */
    public ?string $targetDeletedLogTableName = null;

    /** @var string Default placeholder for non-nullable datetime columns */
    public string $placeholderDatetime = '2222-02-22 00:00:00';

    /** @var string|null SQL definition for creating/replacing the source view. */
    public ?string $viewDefinition = null;

    /** @var bool Whether to attempt creation/replacement of the source view before syncing. */
    public bool $shouldCreateView = false;

    /** @var string[] Array of SQL statements to create/replace dependent views. Executed before the main viewDefinition. */
    public array $viewDependencies = [];

    /**
     * @param Connection $sourceConnection
     * @param string $sourceTableName
     * @param array<string, string> $primaryKeyColumnMap
     * @param array<string, string> $dataColumnMapping
     * @param Connection $targetConnection
     * @param string $targetLiveTableName
     * @param array<int, string> $columnsForContentHash
     * @param MetadataColumnNamesDTO|null $metadataColumns
     * @param array<int, string> $nonNullableDatetimeSourceColumns
     * @param string|null $targetTempTableName
     * @param string|null $placeholderDatetime
     */
    public function __construct(
        Connection $sourceConnection,
        string $sourceTableName,
        array $primaryKeyColumnMap,
        array $dataColumnMapping,
        Connection $targetConnection,
        string $targetLiveTableName,
        array $columnsForContentHash,
        ?MetadataColumnNamesDTO $metadataColumns = null,
        array $nonNullableDatetimeSourceColumns = [],
        ?string $targetTempTableName = null,
        ?string $placeholderDatetime = null,
        bool $enableDeletionLogging = false,
        ?string $targetDeletedLogTableName = null,
        ?string $viewDefinition = null,
        bool $shouldCreateView = false,
        array $viewDependencies = []
    ) {
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
        if ($placeholderDatetime !== null) {
            $this->placeholderDatetime = $placeholderDatetime;
        }

        // Set view-related properties
        $this->viewDefinition = $viewDefinition;
        $this->shouldCreateView = $shouldCreateView;
        $this->viewDependencies = $viewDependencies;

        // Set deletion logging properties
        $this->enableDeletionLogging = $enableDeletionLogging;
        $this->targetDeletedLogTableName = $targetDeletedLogTableName;

        // Set default for targetDeletedLogTableName if logging is enabled and no name is provided
        if ($this->enableDeletionLogging && $this->targetDeletedLogTableName === null) {
            if (empty($this->targetLiveTableName)) {
                throw new \InvalidArgumentException('targetLiveTableName must be set if deletion logging is enabled without a specific targetDeletedLogTableName.');
            }
            $this->targetDeletedLogTableName = $this->targetLiveTableName . '_deleted_log';
        }

        // Validate deletion logging configuration
        if ($this->enableDeletionLogging && empty($this->targetDeletedLogTableName)) {
            throw new \InvalidArgumentException('targetDeletedLogTableName cannot be empty if deletion logging is enabled.');
        }

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

        // Validate view-related parameters
        if ($this->shouldCreateView) {
            if (empty(trim((string)$this->viewDefinition))) {
                throw new \InvalidArgumentException('If shouldCreateView is true, viewDefinition cannot be empty.');
            }
            if (empty(trim($this->sourceTableName))) {
                throw new \InvalidArgumentException('If shouldCreateView is true, sourceTableName (representing the view name) cannot be empty.');
            }
        }
    }

    /**
     * Gets source primary key column names.
     *
     * @return array<string> List of source primary key column names
     */
    public function getSourcePrimaryKeyColumns(): array
    {
        return array_keys($this->primaryKeyColumnMap);
    }

    /**
     * Gets target primary key column names.
     *
     * @return array<string> List of target primary key column names
     */
    public function getTargetPrimaryKeyColumns(): array
    {
        return array_values($this->primaryKeyColumnMap);
    }

    /**
     * Gets source data column names.
     *
     * @return array<string> List of source data column names
     */
    public function getSourceDataColumns(): array
    {
        return array_keys($this->dataColumnMapping);
    }

    /**
     * Gets target data column names.
     *
     * @return array<string> List of target data column names
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
     * Gets columns to use for content hash calculation.
     *
     * @return array<string> List of target column names to use for content hash calculation
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
     * Gets non-nullable datetime columns in the target table.
     *
     * @return array<string> List of non-nullable datetime column names in the target table
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
     * Gets temp table columns.
     *
     * @return array<string> List of temp table column names
     */
    public function getTempTableColumns(): array
    {
        return array_unique(array_merge(
            $this->getTargetPrimaryKeyColumns(),
            $this->getTargetDataColumns(),
            [$this->metadataColumns->contentHash, $this->metadataColumns->createdAt]
        ));
    }

    /**
     * Gets the primary key column mapping (source to target).
     *
     * @return array<string, string> Key-value pairs of source to target column names
     */
    public function getPrimaryKeyColumnMap(): array
    {
        return $this->primaryKeyColumnMap;
    }

    /**
     * Gets the data column mapping (source to target).
     *
     * @return array<string, string> Key-value pairs of source to target column names
     */
    public function getDataColumnMapping(): array
    {
        return $this->dataColumnMapping;
    }

    /**
     * Gets the source column name that maps to a given target column name.
     * Useful for reverse mapping during schema operations.
     *
     * @param string $targetColumnName The target column name
     * @return string The corresponding source column name
     * @throws \InvalidArgumentException If the target column is not found in the mapping
     */
    public function getMappedSourceColumnName(string $targetColumnName): string
    {
        // Search in primary key mapping first
        $flipPkMap = array_flip($this->primaryKeyColumnMap);
        if (isset($flipPkMap[$targetColumnName])) {
            return $flipPkMap[$targetColumnName];
        }

        // Then search in data mapping
        $flipDataMap = array_flip($this->dataColumnMapping);
        if (isset($flipDataMap[$targetColumnName])) {
            return $flipDataMap[$targetColumnName];
        }

        throw new \InvalidArgumentException("Target column '{$targetColumnName}' not found in column mappings.");
    }
}
