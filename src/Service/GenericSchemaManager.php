<?php

namespace TopdataSoftwareGmbh\TableSyncer\Service;

use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TopdataSoftwareGmbh\TableSyncer\DTO\TableSyncConfigDTO;
use TopdataSoftwareGmbh\TableSyncer\Exception\ConfigurationException;
use TopdataSoftwareGmbh\TableSyncer\Service\SourceIntrospection\SourceIntrospector;

// use Doctrine\DBAL\ParameterType; // Not directly used in this snippet
// Alias to avoid confusion
// Import for easier type referencing
// For schema validation

class GenericSchemaManager
{
    private readonly LoggerInterface $logger;
    private readonly SourceIntrospector $sourceIntrospector;
    /**
     * @var array<string, array<string, mixed>>|null Cache for detailed source column definitions.
     *                                              Example: ['col_name' => ['type' => 'string', 'length' => 50, 'notnull' => true, ...]]
     */
    private ?array $sourceColumnDefinitionsCache = null;
    private ?string $cachedSourceTableName = null;

    public function __construct(
        ?LoggerInterface    $logger = null,
        ?SourceIntrospector $sourceIntrospector = null // Allow injecting for testing
    )
    {
        $this->logger = $logger ?? new NullLogger();
        $this->sourceIntrospector = $sourceIntrospector ?? new SourceIntrospector($this->logger);
    }

    /**
     * Gets detailed source column definitions (type, length, precision, scale, notnull, default).
     *
     * @param TableSyncConfigDTO $config
     * @return array<string, array<string, mixed>> Column name to definition map.
     */
    public function getSourceColumnDefinitions(TableSyncConfigDTO $config): array
    {
        $this->logger->debug('Getting source column definitions via SourceIntrospector');

        if ($this->sourceColumnDefinitionsCache !== null && $this->cachedSourceTableName === $config->sourceTableName) {
            $this->logger->debug('Using cached source column definitions for', ['source' => $config->sourceTableName]);
            return $this->sourceColumnDefinitionsCache;
        }

        $sourceConn = $config->sourceConnection;
        $sourceName = $config->sourceTableName;

        // Delegate to the SourceIntrospector
        try {
            $columnDefinitions = $this->sourceIntrospector->introspectSource($sourceConn, $sourceName);
        } catch (ConfigurationException $e) {
            // Re-throw if needed, or add more context
            $this->logger->error("Failed to get source column definitions for '{$sourceName}': " . $e->getMessage(), ['exception' => $e]);
            throw $e;
        } catch (\Throwable $e) { // Catch any other unexpected error
            $this->logger->error("Unexpected error getting source column definitions for '{$sourceName}': " . $e->getMessage(), ['exception' => $e]);
            throw new ConfigurationException("Unexpected error introspecting source '{$sourceName}': " . $e->getMessage(), 0, $e);
        }

        $this->sourceColumnDefinitionsCache = $columnDefinitions;
        $this->cachedSourceTableName = $sourceName;

        return $columnDefinitions;
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

            // ---- Get expected metadata columns ----
            $liveMetadataDefs = $this->getLiveTableSpecificMetadataColumns($config);
            $syncerIdColName = $config->metadataColumns->id;

            // ---- Check if the table seems to be using the business PK as its main PK ----
            // This logic was an attempt to handle pre-existing tables not using _syncer_id as PK.
            // Since you dropped the table, this specific $isUsingBusinessPkAsMainPk path might not be hit
            // on the first run, but it's good to keep it correct for future scenarios.
            $targetBusinessPkColumns = $config->getTargetPrimaryKeyColumns();
            $isUsingBusinessPkAsMainPk = false;
            if (count($targetBusinessPkColumns) === 1) { // Simplified: only handles single-column business PKs for this specific logic
                $businessPkName = $targetBusinessPkColumns[0];
                $currentPrimaryKey = $actualTable->getPrimaryKey(); // Get PK object
                if ($currentPrimaryKey !== null && // Check if a PK exists
                    $currentPrimaryKey->getColumns() === [$businessPkName] && // Check if it's on the business PK column
                    !$actualTable->hasColumn($syncerIdColName) // And _syncer_id is NOT present
                ) {
                    $this->logger->info(
                        "Live table '{$liveTableName}' appears to use business PK '{$businessPkName}' as its primary key, and '{$syncerIdColName}' is missing. Proceeding with validation for existing _syncer_ columns."
                    );
                    $isUsingBusinessPkAsMainPk = true;
                }
            }

            if (!$isUsingBusinessPkAsMainPk) {
                // Standard validation: expect _syncer_id as PK
                if (!$actualTable->hasColumn($syncerIdColName)) {
                    throw new ConfigurationException("Live table '{$liveTableName}' is missing expected metadata column '{$syncerIdColName}'. If it's a new setup, drop the table. If it's an existing table not managed by syncer's _syncer_id PK, adjust configuration or table structure.");
                }

                $primaryKey = $actualTable->getPrimaryKey(); // Get the PrimaryKey Index object or null
                if ($primaryKey === null) {
                    throw new ConfigurationException("Live table '{$liveTableName}' does not have a primary key defined, but expected '{$config->metadataColumns->id}'.");
                }
                if ($primaryKey->getColumns() !== [$config->metadataColumns->id]) { // Compare array of column names
                    throw new ConfigurationException("Live table '{$liveTableName}' primary key is defined on columns [" . implode(', ', $primaryKey->getColumns()) . "] but expected '{$config->metadataColumns->id}'.");
                }
            }

            // Validate mapped business PK columns (these should always exist as data)
            foreach ($config->getPrimaryKeyColumnMap() as $sourcePkColName => $targetPkColName) {
                if (!$actualTable->hasColumn($targetPkColName)) {
                    throw new ConfigurationException("Live table '{$liveTableName}' is missing expected business primary key column '{$targetPkColName}' (mapped from source).");
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
            // Validate Metadata Columns (excluding _syncer_id if $isUsingBusinessPkAsMainPk)
            foreach ($liveMetadataDefs as $colDef) {
                if ($isUsingBusinessPkAsMainPk && $colDef['name'] === $syncerIdColName) {
                    continue; // Skip _syncer_id validation if business PK is used and _syncer_id is absent
                }
                if (!$actualTable->hasColumn($colDef['name'])) {
                    throw new ConfigurationException("Live table '{$liveTableName}' is missing expected metadata column '{$colDef['name']}'.");
                }
                // Further type/option validation for metadata columns
            }

            $this->logger->info("Live table '{$liveTableName}' schema appears valid " . ($isUsingBusinessPkAsMainPk ? "using business PK." : "using syncer PK."));
            return;
        }

        // ---- If table does NOT exist, create it with _syncer_id as PK ----
        $this->logger->info("Live table '{$liveTableName}' does not exist. Creating with '{$config->metadataColumns->id}' as PK...");
        $table = new Table($liveTableName);
        $liveMetadataDefs = $this->getLiveTableSpecificMetadataColumns($config);
        $syncerIdColName = $config->metadataColumns->id;
        $addedSyncerId = false;

        // Add _syncer_id metadata column AND SET AS PK
        foreach ($liveMetadataDefs as $colDef) {
            if ($colDef['name'] === $syncerIdColName) {
                $options = $this->_extractColumnOptions($colDef);
                $table->addColumn($colDef['name'], $colDef['type'], $options);
                $table->setPrimaryKey([$syncerIdColName]); // Syncer's own ID is the PK
                $addedSyncerId = true;
                break;
            }
        }
        if (!$addedSyncerId) {
            throw new ConfigurationException("Syncer ID column '{$syncerIdColName}' definition not found in getLiveTableSpecificMetadataColumns for new table creation.");
        }

        // Add other metadata columns
        foreach ($liveMetadataDefs as $colDef) {
            if ($colDef['name'] === $syncerIdColName) continue;
            $options = $this->_extractColumnOptions($colDef);
            $table->addColumn($colDef['name'], $colDef['type'], $options);
        }

        // Add Business Primary Key Columns (as data, will get a unique index later by GenericIndexManager)
        foreach ($config->getPrimaryKeyColumnMap() as $sourcePkColName => $targetPkColName) {
            if (!isset($sourceColumnDefinitions[$sourcePkColName])) {
                throw new ConfigurationException("Source primary key column '{$sourcePkColName}' not found in introspected schema of '{$config->sourceTableName}'.");
            }
            $def = $sourceColumnDefinitions[$sourcePkColName];
            $options = $this->_extractColumnOptions($def);
            $options['autoincrement'] = false; // Business PKs are data, not AI in this new table
            $table->addColumn($targetPkColName, $def['type'], $options);
        }

        // Add other Data Columns
        foreach ($config->getDataColumnMapping() as $sourceColName => $targetColName) {
            if (isset($config->primaryKeyColumnMap[$sourceColName]) && $config->primaryKeyColumnMap[$sourceColName] === $targetColName) {
                continue; // Already added as a business PK column
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
        $this->logger->info("Live table '{$liveTableName}' created successfully with '{$syncerIdColName}' as PK.");
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

        return [
            [
                'name'          => $meta->id,
                'type'          => $config->targetIdColumnType,
                'notnull'       => true,
                'autoincrement' => true,
            ],
            [
                'name'    => $meta->contentHash,
                'type'    => $config->targetHashColumnType,
                'length'  => $config->targetHashColumnLength,
                'notnull' => true,
            ],
            [
                'name'    => $meta->createdAt,
                'type'    => Types::DATETIME_MUTABLE,
                'notnull' => true,
                'default' => $config->placeholderDatetime,
            ],
            [
                'name'    => $meta->updatedAt,
                'type'    => Types::DATETIME_MUTABLE,
                'notnull' => true,
                'default' => $config->placeholderDatetime,
            ],
            [
                'name'    => $meta->createdRevisionId,
                'type'    => Types::INTEGER,
                'notnull' => true,
            ],
            [
                'name'    => $meta->lastModifiedRevisionId,
                'type'    => Types::INTEGER,
                'notnull' => true,
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
                'notnull' => false,
            ],
            [
                'name'    => $meta->createdAt,
                'type'    => Types::DATETIME_MUTABLE,
                'notnull' => true,
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


    public function mapInformationSchemaType(
        string $infoSchemaType,
        ?int   $charMaxLength,
        ?int   $numericPrecision,
        ?int   $numericScale
    ): string
    {
        $infoSchemaType = strtolower($infoSchemaType);
        switch ($infoSchemaType) {
            case 'char':
            case 'varchar':
            case 'tinytext':
                return Types::STRING;
            case 'text':
            case 'mediumtext':
            case 'longtext':
                return Types::TEXT;
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
            case 'decimal':
            case 'numeric':
                return Types::DECIMAL;
            case 'float':
                return Types::FLOAT;
            case 'double':
            case 'real':
                return Types::FLOAT;
            case 'date':
                return Types::DATE_MUTABLE;
            case 'datetime':
            case 'timestamp':
                return Types::DATETIME_MUTABLE;
            case 'time':
                return Types::TIME_MUTABLE;
            case 'year':
                return Types::SMALLINT;
            case 'binary':
            case 'varbinary':
                return Types::BINARY;
            case 'tinyblob':
            case 'blob':
            case 'mediumblob':
            case 'longblob':
                return Types::BLOB;
            case 'enum':
            case 'set':
                return Types::STRING;
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

        if ($schemaManager->tablesExist([$tempTableName])) {
            $schemaManager->dropTable($tempTableName);
            $this->logger->info('Temp table dropped successfully', ['table' => $tempTableName]);
        } else {
            $this->logger->debug('Temp table does not exist, nothing to drop', ['table' => $tempTableName]);
        }
    }


    /**
     * Gets the source column DBAL type names.
     * This is primarily for GenericTableSyncer's loadDataFromSourceToTemp for parameter binding.
     * It wraps getSourceColumnDefinitions to extract only the type name.
     *
     * @param TableSyncConfigDTO $config
     * @return array<string, string> Column name to DBAL type name mapping
     */
    public function getSourceColumnTypes(TableSyncConfigDTO $config): array
    {
        $definitions = $this->getSourceColumnDefinitions($config);
        $types = [];
        foreach ($definitions as $columnName => $def) {
            if (isset($def['type']) && is_string($def['type'])) {
                $types[$columnName] = $def['type'];
            } else {
                // Fallback or error if type is not found/string, though getSourceColumnDefinitions should ensure it.
                $this->logger->warning("Type not found or not a string for column '{$columnName}' in definitions from getSourceColumnDefinitions. Defaulting to 'string'.", ['definition' => $def]);
                $types[$columnName] = Types::STRING; // Default to string if type is missing in the definition somehow
            }
        }
        return $types;
    }

    /**
     * Ensures the deletion log table exists with the correct schema.
     * This table will store records of rows deleted from the live table.
     *
     * @param TableSyncConfigDTO $config
     * @return void
     * @throws \Throwable If table creation fails
     */
    public function ensureDeletedLogTable(TableSyncConfigDTO $config): void
    {
        if (!$config->enableDeletionLogging || empty($config->targetDeletedLogTableName)) {
            // Should not happen if config validation is correct, but as a safeguard.
            $this->logger->debug('Deletion logging not enabled or log table name not set, skipping ensureDeletedLogTable.');
            return;
        }

        $this->logger->debug('Ensuring deletion log table schema', [
            'deletedLogTable' => $config->targetDeletedLogTableName,
        ]);

        $targetConn = $config->targetConnection;
        $dbalSchemaManager = $targetConn->createSchemaManager();
        $logTableName = $config->targetDeletedLogTableName; // Already validated to be non-empty

        if ($dbalSchemaManager->tablesExist([$logTableName])) {
            $this->logger->info("Deletion log table '{$logTableName}' already exists. Schema validation for it is not implemented in this version.");
            // Future enhancement: Validate schema of existing log table.
            return;
        }

        $this->logger->info("Deletion log table '{$logTableName}' does not exist. Creating...");

        $table = new Table($logTableName);

        // Define columns for the deletion log table
        $table->addColumn('log_id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull'       => true,
        ]);
        $table->setPrimaryKey(['log_id']);

        // deleted_syncer_id should match the type of the _syncer_id in the live table
        $table->addColumn('deleted_syncer_id', $config->targetIdColumnType, [
            'notnull' => true,
            // Consider length/precision/scale if targetIdColumnType needs it, though typically INTEGER/BIGINT
        ]);

        $table->addColumn('deleted_at_revision_id', Types::INTEGER, [
            'notnull' => true,
        ]);

        $table->addColumn('deletion_timestamp', Types::DATETIME_MUTABLE, [
            'notnull' => true,
            'default' => 'CURRENT_TIMESTAMP', // Relies on DB to handle this; or set explicitly during INSERT
        ]);

        // Add indexes
        $table->addIndex(['deleted_at_revision_id'], 'idx_' . $logTableName . '_revision_id');
        $table->addIndex(['deleted_syncer_id'], 'idx_' . $logTableName . '_syncer_id');

        try {
            $dbalSchemaManager->createTable($table);
            $this->logger->info("Deletion log table '{$logTableName}' created successfully.");
        } catch (\Throwable $e) {
            $this->logger->error("Failed to create deletion log table '{$logTableName}': " . $e->getMessage(), ['exception' => $e]);
            throw $e; // Re-throw
        }
    }
}