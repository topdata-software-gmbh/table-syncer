<?php

namespace Tests\Unit\DTO;

use PHPUnit\Framework\TestCase;
use DTO\MetadataColumnNamesDTO;

class MetadataColumnNamesDTOTest extends TestCase
{
    public function testConstructorSetsProperties()
    {
        $id = 'id';
        $name = 'name';
        $description = 'description';

        $dto = new MetadataColumnNamesDTO($id, $name, $description);

        $this->assertSame($id, $dto->getId());
        $this->assertSame($name, $dto->getName());
        $this->assertSame($description, $dto->getDescription());
    }

    public function testDefaults()
    {
        $dto = new MetadataColumnNamesDTO();

        $this->assertNull($dto->getId());
        $this->assertNull($dto->getName());
        $this->assertNull($dto->getDescription());
    }
}
