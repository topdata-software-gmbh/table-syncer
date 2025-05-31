<?php

namespace TopdataSoftwareGmbh\TableSyncer\DTO;

use App\DTO\TableSync\MetadataColumnNamesDTO;
use DateTimeInterface;
use Doctrine\DBAL\Connection;

/**
 * Configuration DTO for a generic table synchronization operation.
 */
class TableSyncConfigDTO
{
    public Connection $sourceConnection;
    public string $sourceTableName;
    /** @var string[] Primary key column(s) in the source table. */
    public array $sourcePrimaryKeyColumns;
    /** @var string[] Data columns from source to be synced to target. */
    public array $dataColumnsToSync;

    public Connection $targetConnection;
    public string $targetLiveTableName;
    public string $targetTempTableName;

    /**
     * @var string[] Columns in the target table that correspond to sourcePrimaryKeyColumns,
     * used for matching rows. Typically the same names as sourcePrimaryKeyColumns.
     */
    public array $targetMatchingKeyColumns;

    /** @var string[] Subset of dataColumnsToSync used for generating the content hash. */
    public array $columnsForContentHash;

    /** @var string[] Datetime columns that cannot be null and should be handled with special date */
    public array $nonNullableDatetimeColumns = [];

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
        array                   $sourcePrimaryKeyColumns,
        array                   $dataColumnsToSync,
        Connection              $targetConnection,
        string                  $targetLiveTableName,
        array                   $targetMatchingKeyColumns,
        array                   $columnsForContentHash,
        ?MetadataColumnNamesDTO $metadataColumns = null,
        ?string                 $targetTempTableName = null
    )
    {
        $this->sourceConnection = $sourceConnection;
        $this->sourceTableName = $sourceTableName;
        $this->sourcePrimaryKeyColumns = $sourcePrimaryKeyColumns;
        $this->dataColumnsToSync = $dataColumnsToSync; // Ensure PKs are part of this if they are also data

        $this->targetConnection = $targetConnection;
        $this->targetLiveTableName = $targetLiveTableName;
        $this->targetTempTableName = $targetTempTableName ?? $targetLiveTableName . '_temp';
        $this->targetMatchingKeyColumns = $targetMatchingKeyColumns;
        $this->columnsForContentHash = $columnsForContentHash;
        $this->metadataColumns = $metadataColumns ?? new MetadataColumnNamesDTO();

        // Basic validation
        if (empty($this->sourcePrimaryKeyColumns) || empty($this->targetMatchingKeyColumns) ||
            count($this->sourcePrimaryKeyColumns) !== count($this->targetMatchingKeyColumns)) {
            throw new \InvalidArgumentException('Source and Target primary/matching key columns must be defined and have corresponding counts.');
        }
        if (empty($this->dataColumnsToSync)) {
            throw new \InvalidArgumentException('Data columns to sync cannot be empty.');
        }
        if (empty($this->columnsForContentHash)) {
            throw new \InvalidArgumentException('Columns for content hash cannot be empty.');
        }
    }

    /**
     * Gets all columns that should exist in the temp table.
     * This includes target matching keys, data columns, and specific metadata.
     */
    public function getTempTableColumns(): array
    {
        return array_unique(array_merge(
            $this->targetMatchingKeyColumns,
            $this->dataColumnsToSync,
            [$this->metadataColumns->contentHash, $this->metadataColumns->createdAt]
        ));
    }
}
