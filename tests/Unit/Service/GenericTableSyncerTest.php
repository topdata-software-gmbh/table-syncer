<?php

namespace Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Service\GenericTableSyncer;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;

class GenericTableSyncerTest extends TestCase
{
    public function testOrchestration()
    {
        $connection = $this->createMock(Connection::class);
        $logger = new NullLogger();
        $tableSyncer = new GenericTableSyncer($connection, $logger);

        $config = $this->createMock(\TopdataSoftwareGmbh\TableSyncer\DTO\TableSyncConfigDTO::class);
        $config->method('getTableName')->willReturn('test_table');

        $tableSyncer->sync($config);

        // Add assertions based on the expected behavior
    }

    public function testDataLoading()
    {
        $connection = $this->createMock(Connection::class);
        $logger = new NullLogger();
        $tableSyncer = new GenericTableSyncer($connection, $logger);

        $config = $this->createMock(\TopdataSoftwareGmbh\TableSyncer\DTO\TableSyncConfigDTO::class);
        $config->method('getTableName')->willReturn('test_table');

        $connection->expects($this->once())
                   ->method('fetchAllAssociative')
                   ->with('SELECT * FROM test_table');

        $tableSyncer->loadData($config);
    }

    public function testDataSync()
    {
        $connection = $this->createMock(Connection::class);
        $logger = new NullLogger();
        $tableSyncer = new GenericTableSyncer($connection, $logger);

        $config = $this->createMock(\TopdataSoftwareGmbh\TableSyncer\DTO\TableSyncConfigDTO::class);
        $config->method('getTableName')->willReturn('test_table');

        $connection->expects($this->once())
                   ->method('executeQuery')
                   ->with($this->stringContains('INSERT INTO'));

        $tableSyncer->syncData($config);
    }

    public function testDatetimeHandling()
    {
        $connection = $this->createMock(Connection::class);
        $logger = new NullLogger();
        $tableSyncer = new GenericTableSyncer($connection, $logger);

        $date = new \DateTime();
        $formattedDate = $tableSyncer->formatDateTime($date);

        $this->assertNotEmpty($formattedDate);
    }

    public function testPsr3LoggingCalls()
    {
        $connection = $this->createMock(Connection::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())
               ->method('info')
               ->with('Syncing table');

        $tableSyncer = new GenericTableSyncer($connection, $logger);
        $config = $this->createMock(\TopdataSoftwareGmbh\TableSyncer\DTO\TableSyncConfigDTO::class);
        $config->method('getTableName')->willReturn('test_table');

        $tableSyncer->sync($config);
    }
}