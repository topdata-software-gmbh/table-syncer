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
    public string $createdRevisionId = '_syncer_created_revision_id'; // When the record was first created
    public string $lastModifiedRevisionId = '_syncer_last_modified_revision_id'; // When the record was last modified

    public function __construct(
        string $id = '_syncer_id',
        string $contentHash = '_syncer_content_hash',
        string $createdAt = '_syncer_created_at',
        string $updatedAt = '_syncer_updated_at',
        string $createdRevisionId = '_syncer_created_revision_id',
        string $lastModifiedRevisionId = '_syncer_last_modified_revision_id'
    ) {
        $this->id = $id;
        $this->contentHash = $contentHash;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
        $this->createdRevisionId = $createdRevisionId;
        $this->lastModifiedRevisionId = $lastModifiedRevisionId;
    }
}
