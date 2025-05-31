<?php

namespace TopdataSoftwareGmbh\TableSyncer\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TopdataSoftwareGmbh\TableSyncer\DTO\TableSyncConfigDTO;

class GenericSchemaManager
{
    private readonly LoggerInterface $logger;
    /**
     * @var array<string, string>|null
     */
    private ?array $sourceTableDetailsCache = null;
    private ?string $cachedSourceTableName = null;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Ensures the live table exists and has the correct schema.
     *
     * @param TableSyncConfigDTO $config
     * @return void
     */
    public function ensureLiveTable(TableSyncConfigDTO $config): void
    {
        $this->logger->debug('Ensuring live table exists', [
            'liveTable' => $config->targetLiveTableName,
        ]);

        $targetConn = $config->targetConnection;
        $schemaManager = $targetConn->createSchemaManager();
        $liveTableName = $config->targetLiveTableName;

        if ($schemaManager->tablesExist([$liveTableName])) {
            $this->logger->debug('Live table already exists, validating schema');
            // Future enhancement: validate the schema matches expected
            return;
        }

        $this->logger->info('Live table does not exist, creating it', [
            'liveTable' => $liveTableName,
        ]);

        // Get all column definitions
        $columnDefinitions = [];

        // Primary key columns
        foreach ($config->getTargetPrimaryKeyColumns() as $columnName) {
            // Get type from source column
            $sourceTypes = $this->getSourceColumnTypes($config);
            $type = $sourceTypes[$config->getMappedSourceColumnName($columnName)] ?? 'string';

            $columnDefinitions[$columnName] = [
                'type' => $type,
                'notnull' => true,
                'primary' => true,
            ];
        }

        // Data columns
        foreach ($config->getTargetDataColumns() as $columnName) {
            // Get type from source column
            $sourceTypes = $this->getSourceColumnTypes($config);
            $type = $sourceTypes[$config->getMappedSourceColumnName($columnName)] ?? 'string';

            $columnDefinitions[$columnName] = [
                'type' => $type,
                'notnull' => false,
            ];
        }

        // Metadata columns (specific to live table)
        $metadataColumns = $this->getLiveTableSpecificMetadataColumns($config);
        foreach ($metadataColumns as $columnName => $columnDef) {
            $columnDefinitions[$columnName] = $columnDef;
        }

        // Define indexes
        $indexes = [
            // Primary key index is automatically created for primary columns
        ];

        // Create the table
        $this->createTable($targetConn, $liveTableName, $columnDefinitions, $indexes);
    }

    /**
     * Prepares the temp table for synchronization.
     *
     * @param TableSyncConfigDTO $config
     * @return void
     */
    public function prepareTempTable(TableSyncConfigDTO $config): void
    {
        $this->logger->debug('Preparing temp table', [
            'tempTable' => $config->targetTempTableName,
        ]);

        $targetConn = $config->targetConnection;
        $schemaManager = $targetConn->createSchemaManager();
        $tempTableName = $config->targetTempTableName;

        // Always drop and recreate the temp table
        if ($schemaManager->tablesExist([$tempTableName])) {
            $this->dropTempTable($config);
        }

        // Get all column definitions
        $columnDefinitions = [];

        // Primary key columns
        foreach ($config->getTargetPrimaryKeyColumns() as $columnName) {
            // Get type from source column
            $sourceTypes = $this->getSourceColumnTypes($config);
            $type = $sourceTypes[$config->getMappedSourceColumnName($columnName)] ?? 'string';

            $columnDefinitions[$columnName] = [
                'type' => $type,
                'notnull' => true,
                'primary' => true,
            ];
        }

        // Data columns
        foreach ($config->getTargetDataColumns() as $columnName) {
            // Get type from source column
            $sourceTypes = $this->getSourceColumnTypes($config);
            $type = $sourceTypes[$config->getMappedSourceColumnName($columnName)] ?? 'string';

            $columnDefinitions[$columnName] = [
                'type' => $type,
                'notnull' => false,
            ];
        }

        // Metadata columns (specific to temp table)
        $metadataColumns = $this->getTempTableSpecificMetadataColumns($config);
        foreach ($metadataColumns as $columnName => $columnDef) {
            $columnDefinitions[$columnName] = $columnDef;
        }

        // Define indexes - will be added later by IndexManager
        $indexes = [];

        // Create the table
        $this->createTable($targetConn, $tempTableName, $columnDefinitions, $indexes);
    }

    /**
     * Gets metadata columns for the live table.
     *
     * @param TableSyncConfigDTO $config
     * @return array<string, array<string, mixed>> Column name to definition mapping
     */
    private function getLiveTableSpecificMetadataColumns(TableSyncConfigDTO $config): array
    {
        $this->logger->debug('Getting live table specific metadata columns');

        $metadataColumns = [];
        $meta = $config->metadataColumns;

        // ID column (auto-increment primary key)
        if ($meta->id) {
            $metadataColumns[$meta->id] = [
                'type' => 'integer',
                'notnull' => true,
                'autoincrement' => true,
                'primary' => true,
            ];
        }

        // Content hash column
        if ($meta->contentHash) {
            $metadataColumns[$meta->contentHash] = [
                'type' => 'string',
                'length' => 64, // SHA-256 hexadecimal output is 64 characters
                'notnull' => true,
                'default' => '',
            ];
        }

        // Created at column
        if ($meta->createdAt) {
            $metadataColumns[$meta->createdAt] = [
                'type' => 'datetime',
                'notnull' => true,
                'default' => 'CURRENT_TIMESTAMP',
            ];
        }

        // Updated at column
        if ($meta->updatedAt) {
            $metadataColumns[$meta->updatedAt] = [
                'type' => 'datetime',
                'notnull' => true,
                'default' => 'CURRENT_TIMESTAMP',
            ];
        }

        // Batch revision column
        if ($meta->batchRevision) {
            $metadataColumns[$meta->batchRevision] = [
                'type' => 'integer',
                'notnull' => true,
                'default' => 0,
            ];
        }

        return $metadataColumns;
    }

    /**
     * Gets metadata columns for the temp table.
     *
     * @param TableSyncConfigDTO $config
     * @return array<string, array<string, mixed>> Column name to definition mapping
     */
    private function getTempTableSpecificMetadataColumns(TableSyncConfigDTO $config): array
    {
        $this->logger->debug('Getting temp table specific metadata columns');

        $metadataColumns = [];
        $meta = $config->metadataColumns;

        // Content hash column (nullable initially, will be filled by DataHasher)
        if ($meta->contentHash) {
            $metadataColumns[$meta->contentHash] = [
                'type' => 'string',
                'length' => 64, // SHA-256 hexadecimal output is 64 characters
                'notnull' => false,
                'default' => null,
            ];
        }

        // Created at column (will be copied to live table)
        if ($meta->createdAt) {
            $metadataColumns[$meta->createdAt] = [
                'type' => 'datetime',
                'notnull' => true,
                'default' => 'CURRENT_TIMESTAMP',
            ];
        }

        return $metadataColumns;
    }

    /**
     * Creates a table with the given columns and indexes.
     *
     * @param Connection $connection
     * @param string $tableName
     * @param array<string, array<string, mixed>> $columns
     * @param array<string, array<string, mixed>> $indexes
     * @return void
     */
    private function createTable(Connection $connection, string $tableName, array $columns, array $indexes = []): void
    {
        $this->logger->debug('Creating table', ['table' => $tableName, 'columns' => array_keys($columns)]);

        $schemaManager = $connection->createSchemaManager();
        $table = new Table($tableName);

        // Add columns
        $primaryKeys = [];
        foreach ($columns as $columnName => $columnDef) {
            $options = [];

            // Extract options
            if (isset($columnDef['length'])) {
                $options['length'] = $columnDef['length'];
            }
            if (isset($columnDef['default'])) {
                $options['default'] = $columnDef['default'];
            }
            if (isset($columnDef['autoincrement']) && $columnDef['autoincrement']) {
                $options['autoincrement'] = true;
            }

            // Add column directly with name, type and options
            $options['notnull'] = $columnDef['notnull'] ?? false;
            $table->addColumn($columnName, $columnDef['type'], $options);

            // Track primary keys
            if (isset($columnDef['primary']) && $columnDef['primary']) {
                $primaryKeys[] = $columnName;
            }
        }

        // Set primary key if any
        if (!empty($primaryKeys)) {
            $table->setPrimaryKey($primaryKeys);
        }

        // Add other indexes
        foreach ($indexes as $indexName => $indexDef) {
            // Ensure columns is an array of strings
            $columns = [];
            if (isset($indexDef['columns']) && is_array($indexDef['columns'])) {
                foreach ($indexDef['columns'] as $col) {
                    if (is_string($col)) {
                        $columns[] = $col;
                    }
                }
            }

            if (!empty($columns)) {
                if ($indexDef['unique'] ?? false) {
                    $table->addUniqueIndex($columns, $indexName);
                } else {
                    $table->addIndex($columns, $indexName);
                }
            } else {
                $this->logger->warning('Skipping index with invalid columns', [
                    'indexName' => $indexName,
                    'columns' => $indexDef['columns'] ?? null
                ]);
            }
        }

        // Create the table
        $schemaManager->createTable($table);
        $this->logger->info('Table created successfully', ['table' => $tableName]);
    }

    /**
     * Gets the source column DBAL types.
     *
     * @param TableSyncConfigDTO $config
     * @return array<string, string> Column name to DBAL type name mapping
     */
    public function getSourceColumnTypes(TableSyncConfigDTO $config): array
    {
        $this->logger->debug('Getting source column types');

        // Check cache
        if ($this->sourceTableDetailsCache !== null && $this->cachedSourceTableName === $config->sourceTableName) {
            $this->logger->debug('Using cached source column types');
            return $this->sourceTableDetailsCache;
        }

        $sourceConn = $config->sourceConnection;
        $schemaManager = $sourceConn->createSchemaManager();
        $sourceTableName = $config->sourceTableName;

        $this->logger->debug('Introspecting source table', ['table' => $sourceTableName]);
        $tableDetails = $schemaManager->introspectTable($sourceTableName);
        $columns = $tableDetails->getColumns();

        $columnTypes = [];
        foreach ($columns as $column) {
            $columnName = $column->getName();
            $type = $this->getDbalTypeNameFromTypeObject($column->getType());
            $columnTypes[$columnName] = $type;
        }

        // Cache the results
        $this->sourceTableDetailsCache = $columnTypes;
        $this->cachedSourceTableName = $sourceTableName;

        return $columnTypes;
    }

    /**
     * Gets the DBAL type name from a Type object.
     *
     * @param Type $type
     * @return string
     */
    public function getDbalTypeNameFromTypeObject(Type $type): string
    {
        // Handle different DBAL versions
        if (method_exists($type, 'getName')) {
            return $type->getName();
        }

        // For newer DBAL versions
        $className = get_class($type);
        $parts = explode('\\', $className);
        $typeName = end($parts);
        return strtolower(str_replace('Type', '', $typeName));
    }

    /**
     * Maps an information schema type to a DBAL type.
     *
     * @param string $infoSchemaType
     * @param int|null $charMaxLength
     * @param int|null $numericPrecision
     * @param int|null $numericScale
     * @return string
     */
    public function mapInformationSchemaType(
        string $infoSchemaType,
        ?int $charMaxLength,
        ?int $numericPrecision,
        ?int $numericScale
    ): string {
        $infoSchemaType = strtolower($infoSchemaType);

        switch ($infoSchemaType) {
            // String types
            case 'char':
            case 'varchar':
            case 'tinytext':
                return 'string';
            case 'text':
            case 'mediumtext':
            case 'longtext':
                return 'text';

            // Numeric types
            case 'tinyint':
                // tinyint(1) is typically used as boolean in MySQL
                if ($numericPrecision === 1) {
                    return 'boolean';
                }
                return 'smallint';
            case 'smallint':
                return 'smallint';
            case 'mediumint':
            case 'int':
            case 'integer':
                return 'integer';
            case 'bigint':
                return 'bigint';

            // Decimal types
            case 'decimal':
            case 'numeric':
                return 'decimal';
            case 'float':
                return 'float';
            case 'double':
            case 'double precision':
                return 'float';

            // Date and time types
            case 'date':
                return 'date';
            case 'datetime':
            case 'timestamp':
                return 'datetime';
            case 'time':
                return 'time';
            case 'year':
                return 'smallint';

            // Binary types
            case 'binary':
            case 'varbinary':
                return 'binary';
            case 'tinyblob':
            case 'blob':
            case 'mediumblob':
            case 'longblob':
                return 'blob';

            // Enum type
            case 'enum':
            case 'set':
                return 'string';

            // JSON type (MySQL 5.7+)
            case 'json':
                return 'json';

            // Default fallback
            default:
                $this->logger->warning('Unknown data type, defaulting to string', [
                    'type' => $infoSchemaType,
                    'charMaxLength' => $charMaxLength,
                    'numericPrecision' => $numericPrecision,
                    'numericScale' => $numericScale
                ]);
                return 'string';
        }
    }

    /**
     * Drops the temp table.
     *
     * @param TableSyncConfigDTO $config
     * @return void
     */
    public function dropTempTable(TableSyncConfigDTO $config): void
    {
        $this->logger->debug('Dropping temp table', [
            'tempTable' => $config->targetTempTableName,
        ]);

        $targetConn = $config->targetConnection;
        $schemaManager = $targetConn->createSchemaManager();
        $tempTableName = $config->targetTempTableName;

        if ($schemaManager->tablesExist([$tempTableName])) {
            $schemaManager->dropTable($tempTableName);
            $this->logger->info('Temp table dropped successfully', ['table' => $tempTableName]);
        } else {
            $this->logger->debug('Temp table does not exist, nothing to drop', ['table' => $tempTableName]);
        }
    }
}
