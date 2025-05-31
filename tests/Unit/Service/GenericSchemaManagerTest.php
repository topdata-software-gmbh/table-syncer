<?php

namespace Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Service\GenericSchemaManager;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;

class GenericSchemaManagerTest extends TestCase
{
    public function testDdlLogic()
    {
        $connection = $this->createMock(Connection::class);
        $logger = new NullLogger();
        $schemaManager = new GenericSchemaManager($connection, $logger);

        $tableName = 'test_table';
        $columns = [
            'id' => ['type' => 'integer', 'options' => ['autoincrement' => true]],
            'name' => ['type' => 'string'],
        ];

        $connection->expects($this->once())
                   ->method('executeQuery')
                   ->with($this->stringContains('CREATE TABLE'));

        $schemaManager->createTable($tableName, $columns);
    }

    public function testTypeMapping()
    {
        $connection = $this->createMock(Connection::class);
        $logger = new NullLogger();
        $schemaManager = new GenericSchemaManager($connection, $logger);

        $this->assertSame('integer', $schemaManager->mapType('int'));
        $this->assertSame('string', $schemaManager->mapType('string'));
    }

    public function testPsr3LoggingCalls()
    {
        $connection = $this->createMock(Connection::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())
               ->method('info')
               ->with('Creating table');

        $schemaManager = new GenericSchemaManager($connection, $logger);
        $schemaManager->createTable('test_table', []);
    }
}