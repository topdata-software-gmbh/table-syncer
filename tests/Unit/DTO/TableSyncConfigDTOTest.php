<?php

namespace Tests\Unit\DTO;

use PHPUnit\Framework\TestCase;
use DTO\TableSyncConfigDTO;
use App\Exception\ConfigurationException;

class TableSyncConfigDTOTest extends TestCase
{
    public function testConstructorSetsProperties()
    {
        $tableName = 'table_name';
        $primaryKey = 'id';
        $columns = ['col1', 'col2'];
        $hashColumns = ['col3', 'col4'];

        $dto = new TableSyncConfigDTO($tableName, $primaryKey, $columns, $hashColumns);

        $this->assertSame($tableName, $dto->getTableName());
        $this->assertSame($primaryKey, $dto->getPrimaryKey());
        $this->assertSame($columns, $dto->getColumns());
        $this->assertSame($hashColumns, $dto->getHashColumns());
    }

    public function testValidationLogic()
    {
        $this->expectException(ConfigurationException::class);

        new TableSyncConfigDTO('', 'id', [], []);
    }

    public function testDefaults()
    {
        $dto = new TableSyncConfigDTO();

        $this->assertNull($dto->getTableName());
        $this->assertNull($dto->getPrimaryKey());
        $this->assertEmpty($dto->getColumns());
        $this->assertEmpty($dto->getHashColumns());
    }

    public function testHelperMethods()
    {
        $dto = new TableSyncConfigDTO('table_name', 'id', ['col1', 'col2'], ['col3', 'col4']);

        $this->assertTrue($dto->hasColumn('col1'));
        $this->assertFalse($dto->hasColumn('col5'));
        $this->assertTrue($dto->hasHashColumn('col3'));
        $this->assertFalse($dto->hasHashColumn('col5'));
    }
}
