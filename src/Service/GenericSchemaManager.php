<?php

namespace TopdataSoftwareGmbh\TableSyncer\Service;

use Doctrine\DBAL\Connection;

// use Doctrine\DBAL\ParameterType; // Not directly used in this snippet
use Doctrine\DBAL\Schema\Column as DbalColumn;

// Alias to avoid confusion
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;

// Import for easier type referencing
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TopdataSoftwareGmbh\TableSyncer\DTO\TableSyncConfigDTO;
use TopdataSoftwareGmbh\TableSyncer\Exception\ConfigurationException;

// For schema validation

class GenericSchemaManager
{
    private readonly LoggerInterface $logger;
    /**
     * @var array<string, array<string, mixed>>|null Cache for detailed source column definitions.
     *                                              Example: ['col_name' => ['type' => 'string', 'length' => 50, 'notnull' => true, ...]]
     */
    private ?array $sourceColumnDefinitionsCache = null;
    private ?string $cachedSourceTableName = null;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Gets detailed source column definitions (type, length, precision, scale, notnull, default).
     *
     * @param TableSyncConfigDTO $config
     * @return array<string, array<string, mixed>> Column name to definition map.
     */
    public function getSourceColumnDefinitions(TableSyncConfigDTO $config): array
    {
        $this->logger->debug('Getting source column definitions');

        if ($this->sourceColumnDefinitionsCache !== null && $this->cachedSourceTableName === $config->sourceTableName) {
            $this->logger->debug('Using cached source column definitions');
            return $this->sourceColumnDefinitionsCache;
        }

        $sourceConn = $config->sourceConnection;
        $schemaManager = $sourceConn->createSchemaManager();
        $sourceTableName = $config->sourceTableName;

        $this->logger->debug('Introspecting source table for detailed column definitions', ['table' => $sourceTableName]);

        if (!$schemaManager->tablesExist([$sourceTableName])) {
            throw new ConfigurationException("Source table '{$sourceTableName}' does not exist in the source database.");
        }

        $tableDetails = $schemaManager->introspectTable($sourceTableName);
        $dbalColumns = $tableDetails->getColumns();

        $columnDefinitions = [];
        foreach ($dbalColumns as $dbalColumn) {
            $columnName = $dbalColumn->getName();
            $columnDefinitions[$columnName] = [
                'name'            => $columnName, // Redundant but good for consistency
                'type'            => $this->getDbalTypeNameFromTypeObject($dbalColumn->getType()),
                'length'          => $dbalColumn->getLength(),
                'precision'       => $dbalColumn->getPrecision(),
                'scale'           => $dbalColumn->getScale(),
                'unsigned'        => $dbalColumn->getUnsigned(),
                'fixed'           => $dbalColumn->getFixed(),
                'notnull'         => $dbalColumn->getNotnull(),
                'default'         => $dbalColumn->getDefault(),
                'autoincrement'   => $dbalColumn->getAutoincrement(),
                'platformOptions' => $dbalColumn->getPlatformOptions(),
                'comment'         => $dbalColumn->getComment(),
                // customSchemaOptions are typically not needed for basic creation
            ];
        }

        $this->sourceColumnDefinitionsCache = $columnDefinitions;
        $this->cachedSourceTableName = $sourceTableName;

        return $columnDefinitions;
    }

    // ... (ensureLiveTable and prepareTempTable need to use getSourceColumnDefinitions)

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
        $dbalSchemaManager = $targetConn->createSchemaManager(); // DBAL SchemaManager
        $tempTableName = $config->targetTempTableName;

        if ($dbalSchemaManager->tablesExist([$tempTableName])) {
            $this->dropTempTable($config); // dropTempTable uses SchemaManager->dropTable
        }

        $this->logger->info('Creating temp table', ['table' => $tempTableName]);

        $table = new Table($tempTableName);
        $sourceColumnDefinitions = $this->getSourceColumnDefinitions($config);

        // ---- Target Primary Key Columns (from source structure) ----
        $targetPkColumnNames = [];
        foreach ($config->getPrimaryKeyColumnMap() as $sourcePkColName => $targetPkColName) {
            if (!isset($sourceColumnDefinitions[$sourcePkColName])) {
                throw new ConfigurationException("Source primary key column '{$sourcePkColName}' not found in introspected schema of '{$config->sourceTableName}'.");
            }
            $def = $sourceColumnDefinitions[$sourcePkColName];
            $options = $this->_extractColumnOptions($def);
            // PKs in temp table are not auto-incrementing, just copies
            $options['autoincrement'] = false;
            // PKs must be notnull
            $options['notnull'] = true;

            $table->addColumn($targetPkColName, $def['type'], $options);
            $targetPkColumnNames[] = $targetPkColName;
        }
        if (!empty($targetPkColumnNames)) {
            $table->setPrimaryKey($targetPkColumnNames);
        }


        // ---- Target Data Columns (from source structure, excluding PKs already added) ----
        foreach ($config->getDataColumnMapping() as $sourceColName => $targetColName) {
            // Skip if this column was already added as part of the primary key
            if (in_array($targetColName, $targetPkColumnNames, true)) {
                continue;
            }
            if (!isset($sourceColumnDefinitions[$sourceColName])) {
                throw new ConfigurationException("Source data column '{$sourceColName}' not found in introspected schema of '{$config->sourceTableName}'.");
            }
            $def = $sourceColumnDefinitions[$sourceColName];
            $options = $this->_extractColumnOptions($def);
            // Data columns in temp table are generally nullable unless source explicitly says notnull.
            // And we don't want autoincrement on data columns in temp table.
            $options['autoincrement'] = false;

            $table->addColumn($targetColName, $def['type'], $options);
        }

        // ---- Temp Table Specific Metadata Columns ----
        $tempMetadataCols = $this->getTempTableSpecificMetadataColumns($config);
        foreach ($tempMetadataCols as $colDef) {
            $options = $this->_extractColumnOptions($colDef);
            $table->addColumn($colDef['name'], $colDef['type'], $options);
        }

        $dbalSchemaManager->createTable($table); // Use DBAL SchemaManager
        $this->logger->info('Temp table created successfully', ['table' => $tempTableName]);
    }


    /**
     * Ensures the live table exists and has the correct schema.
     *
     * @param TableSyncConfigDTO $config
     * @return void
     */
    public function ensureLiveTable(TableSyncConfigDTO $config): void
    {
        $this->logger->debug('Ensuring live table schema', [
            'liveTable' => $config->targetLiveTableName,
        ]);

        $targetConn = $config->targetConnection;
        $dbalSchemaManager = $targetConn->createSchemaManager();
        $liveTableName = $config->targetLiveTableName;

        $sourceColumnDefinitions = $this->getSourceColumnDefinitions($config);

        if ($dbalSchemaManager->tablesExist([$liveTableName])) {
            $this->logger->debug("Live table '{$liveTableName}' exists. Validating schema...");
            $actualTable = $dbalSchemaManager->introspectTable($liveTableName);

            // Validate PKs (mapped from source)
            foreach ($config->getPrimaryKeyColumnMap() as $sourcePkColName => $targetPkColName) {
                if (!$actualTable->hasColumn($targetPkColName)) {
                    throw new ConfigurationException("Live table '{$liveTableName}' is missing expected business primary key column '{$targetPkColName}'.");
                }
                // Further type/option validation can be added here if needed
            }
            // Validate Data Columns (mapped from source)
            foreach ($config->getDataColumnMapping() as $sourceColName => $targetColName) {
                if (!$actualTable->hasColumn($targetColName)) {
                    throw new ConfigurationException("Live table '{$liveTableName}' is missing expected data column '{$targetColName}'.");
                }
                // Further type/option validation
            }
            // Validate Metadata Columns
            $liveMetadataDefs = $this->getLiveTableSpecificMetadataColumns($config);
            foreach ($liveMetadataDefs as $colDef) {
                if (!$actualTable->hasColumn($colDef['name'])) {
                    throw new ConfigurationException("Live table '{$liveTableName}' is missing expected metadata column '{$colDef['name']}'. Consider running without pre-existing live table for initial setup.");
                }
                // Further type/option validation for metadata columns
            }
            // Validate live table's own primary key (the _syncer_id)
            if (!$actualTable->hasPrimaryKey() || $actualTable->getPrimaryKey()->getColumns() !== [$config->metadataColumns->id]) {
                throw new ConfigurationException("Live table '{$liveTableName}' primary key is not correctly set to '{$config->metadataColumns->id}'.");
            }

            $this->logger->info("Live table '{$liveTableName}' schema appears valid.");
            return;
        }

        $this->logger->info("Live table '{$liveTableName}' does not exist. Creating...");
        $table = new Table($liveTableName);

        // ---- Live Table Specific Metadata Columns (PK first) ----
        $liveMetadataDefs = $this->getLiveTableSpecificMetadataColumns($config);
        $syncerIdColName = $config->metadataColumns->id;
        $addedSyncerId = false;

        foreach ($liveMetadataDefs as $colDef) {
            if ($colDef['name'] === $syncerIdColName) {
                $options = $this->_extractColumnOptions($colDef);
                $table->addColumn($colDef['name'], $colDef['type'], $options);
                $table->setPrimaryKey([$syncerIdColName]);
                $addedSyncerId = true;
                break; // Add PK first
            }
        }
        if (!$addedSyncerId) {
            throw new ConfigurationException("Syncer ID column '{$syncerIdColName}' definition not found in getLiveTableSpecificMetadataColumns.");
        }

        // Add other metadata columns
        foreach ($liveMetadataDefs as $colDef) {
            if ($colDef['name'] === $syncerIdColName) continue; // Already added
            $options = $this->_extractColumnOptions($colDef);
            $table->addColumn($colDef['name'], $colDef['type'], $options);
        }

        // ---- Target Primary Key Columns (from source structure, as data, will get unique index) ----
        foreach ($config->getPrimaryKeyColumnMap() as $sourcePkColName => $targetPkColName) {
            if (!isset($sourceColumnDefinitions[$sourcePkColName])) {
                throw new ConfigurationException("Source primary key column '{$sourcePkColName}' not found in introspected schema of '{$config->sourceTableName}'.");
            }
            $def = $sourceColumnDefinitions[$sourcePkColName];
            $options = $this->_extractColumnOptions($def);
            $options['autoincrement'] = false; // Not auto-incrementing in live table
            // These PKs are data, nullability depends on source or can be made notnull if required by business logic
            // $options['notnull'] = true; // For example
            $table->addColumn($targetPkColName, $def['type'], $options);
        }

        // ---- Target Data Columns (from source structure, excluding PKs already added) ----
        foreach ($config->getDataColumnMapping() as $sourceColName => $targetColName) {
            // Skip if this column was already added as part of the business primary key
            if (isset($config->primaryKeyColumnMap[$sourceColName]) && $config->primaryKeyColumnMap[$sourceColName] === $targetColName) {
                continue;
            }
            if (!isset($sourceColumnDefinitions[$sourceColName])) {
                throw new ConfigurationException("Source data column '{$sourceColName}' not found in introspected schema of '{$config->sourceTableName}'.");
            }
            $def = $sourceColumnDefinitions[$sourceColName];
            $options = $this->_extractColumnOptions($def);
            $options['autoincrement'] = false;
            $table->addColumn($targetColName, $def['type'], $options);
        }

        $dbalSchemaManager->createTable($table);
        $this->logger->info("Live table '{$liveTableName}' created successfully.");
    }


    /**
     * Gets metadata columns for the live table.
     * Returns an array of column definitions.
     *
     * @param TableSyncConfigDTO $config
     * @return array<int, array<string, mixed>>
     */
    private function getLiveTableSpecificMetadataColumns(TableSyncConfigDTO $config): array
    {
        $this->logger->debug('Getting live table specific metadata columns');
        $meta = $config->metadataColumns;

        // Note: The order might matter if one depends on another, but usually not for basic columns.
        // The syncer_id should be defined as the PK.
        return [
            [
                'name'          => $meta->id,
                'type'          => $config->targetIdColumnType, // e.g., Types::INTEGER
                'notnull'       => true,
                'autoincrement' => true,
                // 'primary' => true, // Handled by setPrimaryKey()
            ],
            [
                'name'    => $meta->contentHash,
                'type'    => $config->targetHashColumnType, // e.g., Types::STRING
                'length'  => $config->targetHashColumnLength,
                'notnull' => true, // Or false if rows can exist before hashing
            ],
            [
                'name'    => $meta->createdAt,
                'type'    => Types::DATETIME_MUTABLE,
                'notnull' => true,
                'default' => $config->placeholderDatetime, // Or CURRENT_TIMESTAMP for MySQL
            ],
            [
                'name'    => $meta->updatedAt,
                'type'    => Types::DATETIME_MUTABLE,
                'notnull' => true,
                'default' => $config->placeholderDatetime, // Or CURRENT_TIMESTAMP and ON UPDATE for MySQL
            ],
            [
                'name'    => $meta->batchRevision,
                'type'    => Types::INTEGER, // Or BIGINT
                'notnull' => false, // Nullable is fine
            ],
        ];
    }

    /**
     * Gets metadata columns for the temp table.
     * Returns an array of column definitions.
     *
     * @param TableSyncConfigDTO $config
     * @return array<int, array<string, mixed>>
     */
    private function getTempTableSpecificMetadataColumns(TableSyncConfigDTO $config): array
    {
        $this->logger->debug('Getting temp table specific metadata columns');
        $meta = $config->metadataColumns;
        return [
            [
                'name'    => $meta->contentHash,
                'type'    => $config->targetHashColumnType,
                'length'  => $config->targetHashColumnLength,
                'notnull' => false, // Hash is added later, so nullable initially
            ],
            [
                'name'    => $meta->createdAt, // Copied from source or set during temp load
                'type'    => Types::DATETIME_MUTABLE,
                'notnull' => true, // Assuming it's always set
                'default' => $config->placeholderDatetime,
            ],
        ];
    }

    /**
     * Helper to extract common column options from a definition array.
     *
     * @param array<string, mixed> $columnDef
     * @return array<string, mixed>
     */
    private function _extractColumnOptions(array $columnDef): array
    {
        $options = [];
        if (isset($columnDef['length']) && $columnDef['length'] !== null) {
            $options['length'] = $columnDef['length'];
        }
        if (isset($columnDef['precision']) && $columnDef['precision'] !== null) {
            $options['precision'] = $columnDef['precision'];
        }
        if (isset($columnDef['scale']) && $columnDef['scale'] !== null) {
            $options['scale'] = $columnDef['scale'];
        }
        if (isset($columnDef['unsigned']) && $columnDef['unsigned'] !== null) {
            $options['unsigned'] = $columnDef['unsigned'];
        }
        if (isset($columnDef['fixed']) && $columnDef['fixed'] !== null) {
            $options['fixed'] = $columnDef['fixed'];
        }
        if (isset($columnDef['notnull']) && $columnDef['notnull'] !== null) {
            $options['notnull'] = $columnDef['notnull'];
        }
        // Important: Handle 'default' carefully. If $columnDef['default'] is explicitly NULL,
        // it means the column should have no default or use the DB's default for NULL.
        // If 'default' key is not present, it means no specific default from definition.
        // DBAL handles 'default' => null as "no default" for some types, or explicit NULL default.
        if (array_key_exists('default', $columnDef)) {
            $options['default'] = $columnDef['default'];
        }
        if (!empty($columnDef['autoincrement'])) {
            $options['autoincrement'] = true;
        }
        if (!empty($columnDef['platformOptions'])) {
            $options['platformOptions'] = $columnDef['platformOptions'];
        }
        if (isset($columnDef['comment']) && $columnDef['comment'] !== null) {
            $options['comment'] = $columnDef['comment'];
        }
        return $options;
    }


    // REMOVE/REPLACE old getSourceColumnTypes if it only returned type names
    // public function getSourceColumnTypes(TableSyncConfigDTO $config): array ...

    // ... (getDbalTypeNameFromTypeObject, mapInformationSchemaType, dropTempTable remain the same)
    // ... (The old _createTable method is effectively replaced by direct Table object manipulation in prepare/ensure methods)

    /**
     * Gets the DBAL type name from a Type object.
     * For DBAL 4.x, this uses the static Type::lookupName() method.
     *
     * @param \Doctrine\DBAL\Types\Type $type The Type object.
     * @return string The name of the type (e.g., "integer", "string").
     * @throws \Doctrine\DBAL\Exception If the type is not registered (should not happen for built-in types).
     */
    public function getDbalTypeNameFromTypeObject(\Doctrine\DBAL\Types\Type $type): string
    {
        // For DBAL 4.x, use the static lookupName method from the Type class itself.
        return \Doctrine\DBAL\Types\Type::lookupName($type);
    }


    public function mapInformationSchemaType(
        string $infoSchemaType,
        ?int   $charMaxLength,
        ?int   $numericPrecision,
        ?int   $numericScale
    ): string
    {
        // This method might become less critical if getSourceColumnDefinitions works well,
        // but can be kept as a fallback or utility if needed.
        $infoSchemaType = strtolower($infoSchemaType);
        // ... (implementation as before) ...
        switch ($infoSchemaType) {
            // String types
            case 'char':
            case 'varchar':
            case 'tinytext':
                return Types::STRING;
            case 'text':
            case 'mediumtext':
            case 'longtext':
                return Types::TEXT;

            // Numeric types
            case 'tinyint':
                return ($numericPrecision === 1) ? Types::BOOLEAN : Types::SMALLINT;
            case 'smallint':
                return Types::SMALLINT;
            case 'mediumint':
            case 'int':
            case 'integer':
                return Types::INTEGER;
            case 'bigint':
                return Types::BIGINT;

            // Decimal types
            case 'decimal':
            case 'numeric':
                return Types::DECIMAL;
            case 'float':
                return Types::FLOAT; // DBAL float
            case 'double':
            case 'real': // Some DBs use REAL for double precision
                return Types::FLOAT; // DBAL float often covers double too

            // Date and time types
            case 'date':
                return Types::DATE_MUTABLE;
            case 'datetime':
            case 'timestamp':
                return Types::DATETIME_MUTABLE;
            case 'time':
                return Types::TIME_MUTABLE;
            case 'year': // MySQL YEAR type
                return Types::SMALLINT; // Or Types::STRING depending on how you want to treat it

            // Binary types
            case 'binary':
            case 'varbinary':
                return Types::BINARY;
            case 'tinyblob':
            case 'blob':
            case 'mediumblob':
            case 'longblob':
                return Types::BLOB;

            // Enum type
            case 'enum': // MySQL ENUM
            case 'set':  // MySQL SET
                return Types::STRING;

            // JSON type (MySQL 5.7+, PostgreSQL)
            case 'json':
                return Types::JSON;

            default:
                $this->logger->warning('Unknown data type from information_schema, defaulting to string', [
                    'type' => $infoSchemaType,
                ]);
                return Types::STRING;
        }
    }

    public function dropTempTable(TableSyncConfigDTO $config): void
    {
        $this->logger->debug('Dropping temp table', [
            'tempTable' => $config->targetTempTableName,
        ]);

        $targetConn = $config->targetConnection;
        $schemaManager = $targetConn->createSchemaManager();
        $tempTableName = $config->targetTempTableName;

        // check if table exists before dropping to avoid error
        if ($schemaManager->tablesExist([$tempTableName])) {
            $schemaManager->dropTable($tempTableName);
            $this->logger->info('Temp table dropped successfully', ['table' => $tempTableName]);
        } else {
            $this->logger->debug('Temp table does not exist, nothing to drop', ['table' => $tempTableName]);
        }
    }




}