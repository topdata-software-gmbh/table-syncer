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
        $this->logger->debug('Adding indices to temp table', [
            'tempTable' => $config->targetTempTableName
        ]);

        $connection = $config->targetConnection;
        $tempTableName = $config->targetTempTableName;
        $metaColumns = $config->metadataColumns;

        // Add index for primary keys on temp table for faster joins
        $pkColumns = $config->getTargetPrimaryKeyColumns();
        $pkIndexName = 'idx_' . $tempTableName . '_pk';
        $this->addIndexIfNotExists($connection, $tempTableName, $pkColumns, false, $pkIndexName);
        $this->logger->debug('Added PK index to temp table', ['columns' => implode(', ', $pkColumns)]);
        
        // Add index for content hash column on temp table
        if ($metaColumns->contentHash) {
            $hashIndexName = 'idx_' . $tempTableName . '_content_hash';
            $this->addIndexIfNotExists($connection, $tempTableName, [$metaColumns->contentHash], false, $hashIndexName);
            $this->logger->debug('Added content hash index to temp table');
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
        $this->logger->debug('Adding indices to live table', [
            'liveTable' => $config->targetLiveTableName
        ]);

        $connection = $config->targetConnection;
        $liveTableName = $config->targetLiveTableName;
        $metaColumns = $config->metadataColumns;

        // Add index for content hash column on live table
        if ($metaColumns->contentHash) {
            $hashIndexName = 'idx_' . $liveTableName . '_content_hash';
            $this->addIndexIfNotExists($connection, $liveTableName, [$metaColumns->contentHash], false, $hashIndexName);
            $this->logger->debug('Added content hash index to live table');
        }
        
        // Add unique index for business PKs on live table (optional but recommended)
        // Only if PKs are not already the primary key of the table
        $pkColumns = $config->getTargetPrimaryKeyColumns();
        if (!empty($metaColumns->id)) {
            // If we have an auto-increment ID, the business keys need a separate unique index
            $pkIndexName = 'uidx_' . $liveTableName . '_business_pk';
            $this->addIndexIfNotExists($connection, $liveTableName, $pkColumns, true, $pkIndexName);
            $this->logger->debug('Added unique business PK index to live table', ['columns' => implode(', ', $pkColumns)]);
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
        // Generate index name if not provided
        if ($indexName === null) {
            $prefix = $isUnique ? 'uidx' : 'idx';
            $indexName = $prefix . '_' . $tableName . '_' . implode('_', $columns);
            // Ensure index name doesn't exceed maximum length (usually 64 chars in MySQL)
            if (strlen($indexName) > 64) {
                $indexName = substr($indexName, 0, 60) . '_' . substr(md5($indexName), 0, 3);
            }
        }
        
        $this->logger->debug(
            'Adding index if not exists',
            ['table' => $tableName, 'columns' => implode(', ', $columns), 'indexName' => $indexName]
        );

        // Check if index already exists
        $schemaManager = $connection->createSchemaManager();
        $indexes = $schemaManager->listTableIndexes($tableName);
        
        if (isset($indexes[$indexName])) {
            $this->logger->debug('Index already exists, skipping', ['indexName' => $indexName]);
            return;
        }
        
        // Quote table and column names
        $quotedTableName = $connection->quoteIdentifier($tableName);
        $quotedColumns = array_map(fn($col) => $connection->quoteIdentifier($col), $columns);
        $quotedColumnList = implode(', ', $quotedColumns);
        
        // Generate SQL for index creation
        $indexType = $isUnique ? 'UNIQUE' : '';
        $sql = "CREATE {$indexType} INDEX {$connection->quoteIdentifier($indexName)} ON {$quotedTableName} ({$quotedColumnList})";
        
        // Execute the query
        $this->logger->debug('Creating index with SQL', ['sql' => $sql]);
        $connection->executeStatement($sql);
        $this->logger->info('Index created successfully', ['indexName' => $indexName]);
    }
}
