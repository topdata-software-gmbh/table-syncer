<?php

namespace TopdataSoftwareGmbh\TableSyncer\DTO;

use DateTimeInterface;
use Doctrine\DBAL\Connection;

/**
 * Class TableSyncConfigDTO
 * Represents the configuration for table synchronization.
 */
class TableSyncConfigDTO
{
    public Connection $sourceConnection;
    public Connection $targetConnection;
    public array $primaryKeyColumnMap;
    public array $dataColumnMapping;
    public array $columnsForContentHash;
    public array $nonNullableDatetimeSourceColumns;
    public MetadataColumnNamesDTO $metadataColumns;
    public DateTimeInterface $placeholderDatetime;

    /**
     * Constructor with parameters for required properties and validation.
     *
     * @param Connection $sourceConnection
     * @param Connection $targetConnection
     * @param array $primaryKeyColumnMap
     * @param array $dataColumnMapping
     * @param array $columnsForContentHash
     * @param array $nonNullableDatetimeSourceColumns
     * @param MetadataColumnNamesDTO $metadataColumns
     * @param DateTimeInterface $placeholderDatetime
     *
     * @throws \InvalidArgumentException if validation fails
     */
    public function __construct(
        Connection $sourceConnection,
        Connection $targetConnection,
        array $primaryKeyColumnMap,
        array $dataColumnMapping,
        array $columnsForContentHash = [],
        array $nonNullableDatetimeSourceColumns = [],
        MetadataColumnNamesDTO $metadataColumns = null,
        DateTimeInterface $placeholderDatetime = null
    ) {
        $this->sourceConnection = $sourceConnection;
        $this->targetConnection = $targetConnection;
        $this->primaryKeyColumnMap = $primaryKeyColumnMap;
        $this->dataColumnMapping = $dataColumnMapping;
        $this->columnsForContentHash = $columnsForContentHash;
        $this->nonNullableDatetimeSourceColumns = $nonNullableDatetimeSourceColumns;
        $this->metadataColumns = $metadataColumns ?? new MetadataColumnNamesDTO();
        $this->placeholderDatetime = $placeholderDatetime ?? new \DateTime();

        // Validate primaryKeyColumnMap and dataColumnMapping are not empty
        if (empty($this->primaryKeyColumnMap) || empty($this->dataColumnMapping)) {
            throw new \InvalidArgumentException('Primary key column map and data column mapping must not be empty.');
        }

        // Validate primary keys, hash columns, and datetime columns exist in dataColumnMapping
        foreach (array_merge(
            $this->primaryKeyColumnMap,
            $this->columnsForContentHash,
            $this->nonNullableDatetimeSourceColumns
        ) as $column) {
            if (!isset($this->dataColumnMapping[$column])) {
                throw new \InvalidArgumentException("Column '$column' must exist in data column mapping.");
            }
        }
    }

    /**
     * Get source primary key columns.
     *
     * @return array
     */
    public function getSourcePrimaryKeyColumns(): array
    {
        return array_keys($this->primaryKeyColumnMap);
    }

    /**
     * Get target primary key columns.
     *
     * @return array
     */
    public function getTargetPrimaryKeyColumns(): array
    {
        return array_values($this->primaryKeyColumnMap);
    }

    /**
     * Get source data columns.
     *
     * @return array
     */
    public function getSourceDataColumns(): array
    {
        return array_keys($this->dataColumnMapping);
    }

    /**
     * Get target data columns.
     *
     * @return array
     */
    public function getTargetDataColumns(): array
    {
        return array_values($this->dataColumnMapping);
    }

    /**
     * Get target column name for a given source column name.
     *
     * @param string $sourceColumnName
     * @return string
     */
    public function getTargetColumnName(string $sourceColumnName): string
    {
        return $this->dataColumnMapping[$sourceColumnName] ?? $sourceColumnName;
    }

    /**
     * Get target columns for content hash.
     *
     * @return array
     */
    public function getTargetColumnsForContentHash(): array
    {
        $columns = [];
        foreach ($this->columnsForContentHash as $column) {
            $columns[] = $this->getTargetColumnName($column);
        }
        return $columns;
    }

    /**
     * Get target non-nullable datetime columns.
     *
     * @return array
     */
    public function getTargetNonNullableDatetimeColumns(): array
    {
        $columns = [];
        foreach ($this->nonNullableDatetimeSourceColumns as $column) {
            $columns[] = $this->getTargetColumnName($column);
        }
        return $columns;
    }

    /**
     * Get temporary table columns.
     *
     * @return array
     */
    public function getTempTableColumns(): array
    {
        return array_merge(
            $this->getTargetPrimaryKeyColumns(),
            $this->getTargetDataColumns()
        );
    }
}