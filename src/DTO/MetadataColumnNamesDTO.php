<?php

namespace TopdataSoftwareGmbh\TableSyncer\DTO;

use DateTimeInterface;

/**
 * Represents the default column names for metadata in the table sync process.
 */
class MetadataColumnNamesDTO
{
    public string $id = '_syncer_id'; // PK for the target table, managed by syncer
    public string $contentHash = '_syncer_content_hash';
    public string $createdAt = '_syncer_created_at'; // When syncer inserted this row
    public string $updatedAt = '_syncer_updated_at'; // When syncer last updated this row
    public string $batchRevision = '_syncer_revision_id'; // Or _syncer_revision_id if you prefer

    public function __construct(
        string $id = '_syncer_id',
        string $contentHash = '_syncer_content_hash',
        string $createdAt = '_syncer_created_at',
        string $updatedAt = '_syncer_updated_at',
        string $batchRevision = '_syncer_revision_id' // Consistent naming
    ) {
        $this->id = $id;
        $this->contentHash = $contentHash;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
        $this->batchRevision = $batchRevision;
    }
}
