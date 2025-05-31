<?php

namespace Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use TopdataSoftwareGmbh\TableSyncer\Service\GenericIndexManager;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;

class GenericIndexManagerTest extends TestCase
{
    public function testIndexCreationLogic()
    {
        $connection = $this->createMock(Connection::class);
        $logger = new NullLogger();
        $indexManager = new GenericIndexManager($connection, $logger);

        $tableName = 'test_table';
        $columnName = 'test_column';

        $connection->expects($this->once())
                   ->method('executeQuery')
                   ->with($this->stringContains('CREATE INDEX'));

        $indexManager->createIndex($tableName, $columnName);
    }

    public function testPsr3LoggingCalls()
    {
        $connection = $this->createMock(Connection::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())
               ->method('info')
               ->with('Creating index on table');

        $indexManager = new GenericIndexManager($connection, $logger);
        $indexManager->createIndex('test_table', 'test_column');
    }
}
