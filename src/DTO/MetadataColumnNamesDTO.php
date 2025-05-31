<?php

namespace TopdataSoftwareGmbh\TableSyncer\DTO;

use DateTimeInterface;

/**
 * Class MetadataColumnNamesDTO
 * Represents the default column names for metadata in the table sync process.
 */
class MetadataColumnNamesDTO extends \App\DTO\TableSync\MetadataColumnNamesDTO
{
    public string $id;
    public string $contentHash;
    public string $revisionId;
    public string $createdAt;
    public string $updatedAt;
    public string $deletedAt;

    /**
     * Constructor with optional parameters to override default property values.
     *
     * @param string $id
     * @param string $contentHash
     * @param string $revisionId
     * @param string $createdAt
     * @param string $updatedAt
     * @param string $deletedAt
     */
    public function __construct(
        string $id = 'id',
        string $contentHash = 'content_hash',
        string $revisionId = 'revision_id',
        string $createdAt = 'created_at',
        string $updatedAt = 'updated_at',
        string $deletedAt = 'deleted_at'
    ) {
        $this->id = $id;
        $this->contentHash = $contentHash;
        $this->revisionId = $revisionId;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
        $this->deletedAt = $deletedAt;
    }
}
