<?php

namespace TopdataSoftwareGmbh\TableSyncer\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TopdataSoftwareGmbh\TableSyncer\DTO\TableSyncConfigDTO;

class GenericIndexManager
{
    private readonly LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Adds indices to the temp table after data load.
     *
     * @param TableSyncConfigDTO $config
     * @return void
     */
    public function addIndicesToTempTableAfterLoad(TableSyncConfigDTO $config): void
    {
        $this->logger->debug('Adding indices to temp table');

        $connection = $config->getConnection();
        $tempTableName = $config->tempTableName;

        foreach ($config->getIndexDefinitions() as $index) {
            $columns = $index['columns'];
            $unique = $index['unique'] ?? false;
            $name = $index['name'] ?? null;
            $this->addIndexIfNotExists($connection, $tempTableName, $columns, $unique, $name);
        }

        $this->logger->info('Indices added to temp table');
    }

    /**
     * Adds indices to the live table.
     *
     * @param TableSyncConfigDTO $config
     * @return void
     */
    public function addIndicesToLiveTable(TableSyncConfigDTO $config): void
    {
        $this->logger->debug('Adding indices to live table');

        $connection = $config->getConnection();
        $liveTableName = $config->liveTableName;

        foreach ($config->getIndexDefinitions() as $index) {
            $columns = $index['columns'];
            $unique = $index['unique'] ?? false;
            $name = $index['name'] ?? null;
            $this->addIndexIfNotExists($connection, $liveTableName, $columns, $unique, $name);
        }

        $this->logger->info('Indices added to live table');
    }

    /**
     * Adds an index to a table if it doesn't already exist.
     *
     * @param Connection $connection
     * @param string $tableName
     * @param array $columns
     * @param bool $isUnique
     * @param string|null $indexName
     * @return void
     */
    public function addIndexIfNotExists(
        Connection $connection,
        string $tableName,
        array $columns,
        bool $isUnique = false,
        ?string $indexName = null
    ): void {
        $this->logger->debug(
            'Adding index if not exists',
            ['table' => $tableName, 'columns' => implode(', ', $columns)]
        );

        $columnList = implode(', ', $columns);
        $indexType = $isUnique ? 'UNIQUE' : '';

        // Implementation goes here
    }
}
