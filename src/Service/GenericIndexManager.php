<?php

namespace TopdataSoftwareGmbh\TableSyncer\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Doctrine\DBAL\Connection;
use TopdataSoftwareGmbh\TableSyncer\DTO\TableSyncConfigDTO;
use TopdataSoftwareGmbh\TableSyncer\Exception\TableSyncerException;

class GenericIndexManager
{
    private readonly LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Adds indices to the temporary table after data load.
     *
     * @param TableSyncConfigDTO $config
     * @return void
     */
    public function addIndicesToTempTableAfterLoad(TableSyncConfigDTO $config): void
    {
        $this->logger->debug('Adding indices to temp table after load');

        $connection = $config->getConnection();
        $tempTableName = $config->tempTableName;

        foreach ($config->getIndexDefinitions() as $index) {
            $this->addIndexIfNotExists($connection, $tempTableName, $index['columns'], $index['unique'] ?? false, $index['name'] ?? null);
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
            $this->addIndexIfNotExists($connection, $liveTableName, $index['columns'], $index['unique'] ?? false, $index['name'] ?? null);
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
    public function addIndexIfNotExists(Connection $connection, string $tableName, array $columns, bool $isUnique = false, ?string $indexName = null): void
    {
        $this->logger->debug('Adding index if not exists', ['table' => $tableName, 'columns' => implode(', ', $columns)]);

        $columnList = implode(', ', $columns);
        $indexType = $isUnique ? 'UNIQUE' : '';
        $indexName = $indexName ?: "idx_{$tableName}_{implode('_', $columns)}";

        $query = "CREATE $indexType INDEX $indexName ON $tableName ($columnList)";

        try {
            $connection->executeStatement($query);
            $this->logger->info('Index created', ['index' => $indexName]);
        } catch (TableSyncerException $e) {
            // Re-throw custom exceptions
            throw $e;
        } catch (\Exception $e) {
            // Index might already exist
            $this->logger->debug('Index creation failed, might already exist', ['error' => $e->getMessage()]);
        }
    }
}