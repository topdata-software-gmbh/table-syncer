<?php

namespace Tests\Unit\DTO;

use PHPUnit\Framework\TestCase;
use TopdataSoftwareGmbh\TableSyncer\DTO\MetadataColumnNamesDTO;

class MetadataColumnNamesDTOTest extends TestCase
{
    public function testConstructorWithDefaultValues()
    {
        $dto = new MetadataColumnNamesDTO();

        $this->assertSame('_syncer_id', $dto->id);
        $this->assertSame('_syncer_content_hash', $dto->contentHash);
        $this->assertSame('_syncer_created_at', $dto->createdAt);
        $this->assertSame('_syncer_updated_at', $dto->updatedAt);
        $this->assertSame('_syncer_created_revision_id', $dto->createdRevisionId);
        $this->assertSame('_syncer_last_modified_revision_id', $dto->lastModifiedRevisionId);
    }

    public function testConstructorWithCustomValues()
    {
        $dto = new MetadataColumnNamesDTO(
            'custom_id',
            'custom_hash',
            'custom_created_at',
            'custom_updated_at',
            'custom_created_revision_id',
            'custom_last_modified_revision_id'
        );

        $this->assertSame('custom_id', $dto->id);
        $this->assertSame('custom_hash', $dto->contentHash);
        $this->assertSame('custom_created_at', $dto->createdAt);
        $this->assertSame('custom_updated_at', $dto->updatedAt);
        $this->assertSame('custom_created_revision_id', $dto->createdRevisionId);
        $this->assertSame('custom_last_modified_revision_id', $dto->lastModifiedRevisionId);
    }
}
