<?php

/**
 * Service for introspecting database tables and views to extract column definitions.
 * This class provides methods to analyze database objects and return their structure.
 */

namespace TopdataSoftwareGmbh\TableSyncer\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Exception\TableDoesNotExist;
use Doctrine\DBAL\Schema\Identifier;
use Doctrine\DBAL\Schema\Table as DbalTable;
use Doctrine\DBAL\Schema\View as DbalView;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types as DbalTypes;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use TopdataSoftwareGmbh\TableSyncer\Enum\SourceObjectTypeEnum;
use TopdataSoftwareGmbh\TableSyncer\Exception\ConfigurationException;

/**
 * Introspects database tables and views to extract their column definitions.
 * Supports multiple database platforms and handles both tables and views.
 */
class SourceIntrospector
{
    private readonly LoggerInterface $logger;

    /**
     * Creates a new SourceIntrospector instance.
     */
    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Introspects a database table or view and returns its column definitions.
     *
     * @param Connection $sourceConnection The database connection to use
     * @param string $sourceName The name of the table or view to introspect
     * @return array<string, array<string, mixed>> Column definitions indexed by column name
     * @throws ConfigurationException If the source object cannot be introspected
     */
    public function introspectSource(Connection $sourceConnection, string $sourceName): array
    {
        $schemaManager = $sourceConnection->createSchemaManager();
        $platform = $sourceConnection->getDatabasePlatform();
        $dbInfo = $this->getDbInfoForError($sourceConnection);
        $inputIdentifier = new Identifier($sourceName); // Use Identifier for consistent name handling

        $this->logger->debug(
            "[Introspector] Starting introspection for source '{$inputIdentifier->getName()}' (quoted: '{$inputIdentifier->getQuotedName($platform)}'){$dbInfo}."
        );

        $sourceType = $this->determineInitialSourceObjectType($schemaManager, $inputIdentifier, $platform);
        $nameToIntrospect = $inputIdentifier->getName(); // Default to base name for INFORMATION_SCHEMA queries
        $schemaForQuery = $inputIdentifier->getNamespaceName(); // Schema part, if any

        if ($sourceType === SourceObjectTypeEnum::VIEW) {
            $this->logger->info("[Introspector] Source '{$sourceName}' identified as a VIEW. Attempting custom introspection via INFORMATION_SCHEMA.COLUMNS.");
            try {
                $columns = $this->introspectViewColumnsFromInformationSchema($sourceConnection, $nameToIntrospect, $schemaForQuery);
                if (empty($columns)) {
                    $this->logger->warning("[Introspector] Custom view introspection for '{$sourceName}' returned no columns. This might indicate an issue or an empty view schema definition.");
                    // Fallback or throw? For now, let's try to be resilient if possible, but this is suspicious.
                    // If we absolutely need columns, we should throw here.
                    // However, the calling code in GenericSchemaManager expects an array, even if empty.
                    // The validation there for PKs etc. will catch it if essential columns are missing.
                }
                return $columns;
            } catch (Throwable $e) {
                $this->logger->error(
                    "[Introspector] Custom view introspection for '{$sourceName}' failed: " . $e->getMessage() .
                    ". Falling back to attempting introspectTable() as a last resort for this view.",
                    ['exception_class' => get_class($e)]
                );
                // Fall through to introspectTable as a last resort for views if custom fails
            }
        } elseif ($sourceType === SourceObjectTypeEnum::TABLE) {
            $this->logger->info("[Introspector] Source '{$sourceName}' identified as a TABLE. Using schemaManager->introspectTable().");
        } else { // UNKNOWN or INTROSPECTABLE_OBJECT_UNDETERMINED
            $this->logger->info("[Introspector] Source '{$sourceName}' type is undetermined or not a simple table/view. Attempting schemaManager->introspectTable().");
        }

        // Standard introspectTable attempt (for tables, or as fallback for views if custom failed, or for unknown types)
        try {
            $this->logger->debug(
                "[Introspector] Using schemaManager->introspectTable() for '{$sourceName}'."
            );
            // It's generally safer to pass the original $sourceName (which might be schema.table)
            // to introspectTable(), as it handles qualified names.
            $tableDetails = $schemaManager->introspectTable($sourceName);
            $nameUsedForSuccessfulIntrospection = $tableDetails->getName();
            $this->logger->info(
                "[Introspector] Successfully introspected '{$nameUsedForSuccessfulIntrospection}' (original input: '{$sourceName}') using introspectTable()."
            );
            return $this->extractColumnDefinitionsFromDbalTable($tableDetails);
        } catch (TableDoesNotExist $e) {
            $this->logger->error(
                "[Introspector] schemaManager->introspectTable('{$sourceName}') failed with TableDoesNotExist: " . $e->getMessage(),
                ['exception_class' => get_class($e)]
            );
            // If it was a view and both custom + introspectTable failed, or if it was a table that wasn't found.
            throw new ConfigurationException("Source object '{$sourceName}'{$dbInfo} not found or not introspectable: " . $e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            $this->logger->error(
                "[Introspector] Unexpected error using schemaManager->introspectTable('{$sourceName}'){$dbInfo}: " . $e->getMessage(),
                ['exception_class' => get_class($e)]
            );
            throw new ConfigurationException("Error introspecting source '{$sourceName}'{$dbInfo}: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Attempts to determine if the source is a TABLE or a VIEW primarily.
     *
     * @param AbstractSchemaManager $schemaManager The schema manager to use
     * @param Identifier $inputIdentifier The identifier of the source object
     * @param AbstractPlatform $platform The database platform
     * @return SourceObjectTypeEnum The determined type of the source object
     */
    private function determineInitialSourceObjectType(
        AbstractSchemaManager $schemaManager,
        Identifier            $inputIdentifier, // Use Identifier for consistent name comparison
        AbstractPlatform      $platform
    ): SourceObjectTypeEnum
    {
        $sourceName = $inputIdentifier->getQuotedName($platform); // Use quoted name for tablesExist

        // Check if it's a table first (often more direct)
        if ($schemaManager->tablesExist([$sourceName])) {
            // Further check to ensure it's not *also* a view with the same name (rare, but possible in some DBs if schemas differ)
            try {
                $views = $schemaManager->listViews();
                foreach ($views as $viewFromList) {
                    if ($this->isViewMatchingSource($viewFromList, $inputIdentifier, $platform, true)) {
                        // It's listed as a view and also as a table. This is ambiguous.
                        // However, if tablesExist is true, DBAL usually prioritizes the table.
                        // For our logic, if it's in tablesExist, we'll treat it as a table first.
                        $this->logger->debug("[Introspector::determineInitial] '{$sourceName}' found in tablesExist() and also matches a view in listViews(). Prioritizing as TABLE for introspection method choice.");
                        return SourceObjectTypeEnum::TABLE;
                    }
                }
            } catch (Throwable $_) { /* ignore errors from listViews here */
            }
            return SourceObjectTypeEnum::TABLE;
        }

        // Not found as a table, check if it's a view
        try {
            $views = $schemaManager->listViews();
            foreach ($views as $viewFromList) {
                if ($this->isViewMatchingSource($viewFromList, $inputIdentifier, $platform, true)) {
                    return SourceObjectTypeEnum::VIEW;
                }
            }
        } catch (Throwable $e) {
            $this->logger->warning(
                "[Introspector::determineInitial] Could not list views to determine type for '{$sourceName}': " . $e->getMessage()
            );
            return SourceObjectTypeEnum::UNKNOWN; // Can't determine due to error
        }

        $this->logger->debug("[Introspector::determineInitial] '{$sourceName}' not found in tablesExist() or listViews(). Type is UNKNOWN.");
        return SourceObjectTypeEnum::UNKNOWN; // Not found as a known table or view
    }


    /**
     * Custom introspection for VIEWs using INFORMATION_SCHEMA.COLUMNS.
     * This method is used when standard introspection methods don't work for views.
     *
     * @param Connection $connection The database connection
     * @param string $viewName Base name of the view
     * @param string|null $schemaName Schema name, if any
     * @return array<string, array<string, mixed>> Column definitions indexed by column name
     */
    private function introspectViewColumnsFromInformationSchema(Connection $connection, string $viewName, ?string $schemaName): array
    {
        $platform = $connection->getDatabasePlatform();
        $sql = "SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE, IS_NULLABLE, COLUMN_DEFAULT, ORDINAL_POSITION ";
        // For MariaDB, COLUMN_TYPE is very useful for 'unsigned' and full type details
        if ($platform instanceof MariaDBPlatform || $platform instanceof MySQLPlatform) {
            $sql .= ", COLUMN_TYPE ";
        }
        $sql .= "FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ?";

        $params = [$viewName];
        // USE DBAL ParameterType constants
        $types = [ParameterType::STRING]; // <--- CORRECTED HERE

        if ($schemaName !== null && $schemaName !== '') {
            $sql .= " AND TABLE_SCHEMA = ?";
            $params[] = $schemaName;
            $types[] = ParameterType::STRING; // <--- CORRECTED HERE
        } else {
            if ($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform) {
                $sql .= " AND TABLE_SCHEMA = DATABASE()";
            } elseif ($platform instanceof PostgreSQLPlatform) {
                $sql .= " AND TABLE_SCHEMA = CURRENT_SCHEMA()";
            }
            // ... (other platform logic for default schema if needed) ...
        }
        $sql .= " ORDER BY ORDINAL_POSITION";

        $this->logger->debug("[Introspector::introspectViewColumns] Executing query: {$sql}", ['params' => $params, 'types_for_dbal' => $types]);
        $stmt = $connection->executeQuery($sql, $params, $types); // $types is now correct for DBAL 4
        $schemaColumns = $stmt->fetchAllAssociative();

        if (empty($schemaColumns)) {
            $this->logger->warning("[Introspector::introspectViewColumns] No columns found in INFORMATION_SCHEMA.COLUMNS for view '{$viewName}'" . ($schemaName ? " in schema '{$schemaName}'" : " in current schema") . ".");
            return [];
        }

        $this->logger->debug("[Introspector::introspectViewColumns] Found " . count($schemaColumns) . " columns in INFORMATION_SCHEMA for view '{$viewName}'.");

        $columns = [];
        foreach ($schemaColumns as $col) {
            $columnName = $col['COLUMN_NAME'];
            $columnTypeFull = null;
            if (($platform instanceof MariaDBPlatform || $platform instanceof MySQLPlatform) && isset($col['COLUMN_TYPE'])) {
                $columnTypeFull = $col['COLUMN_TYPE'];
            }

            $dbalType = $this->mapInformationSchemaTypeToDbalType(
                $platform,
                $col['DATA_TYPE'],
                $col['CHARACTER_MAXIMUM_LENGTH'],
                $col['NUMERIC_PRECISION'],
                $col['NUMERIC_SCALE'],
                $columnTypeFull // Pass full column type for more nuanced mapping
            );

            $isUnsigned = false;
            if ($columnTypeFull && stripos($columnTypeFull, 'unsigned') !== false) {
                $isUnsigned = true;
            }

            $columns[$columnName] = [
                'name'            => $columnName,
                'type'            => $dbalType,
                'length'          => $col['CHARACTER_MAXIMUM_LENGTH'] !== null ? (int)$col['CHARACTER_MAXIMUM_LENGTH'] : null,
                'precision'       => $col['NUMERIC_PRECISION'] !== null ? (int)$col['NUMERIC_PRECISION'] : null,
                'scale'           => $col['NUMERIC_SCALE'] !== null ? (int)$col['NUMERIC_SCALE'] : null,
                'unsigned'        => $isUnsigned, // Set based on COLUMN_TYPE for MariaDB/MySQL
                'fixed'           => false,
                'notnull'         => strtoupper($col['IS_NULLABLE']) === 'NO',
                'default'         => $col['COLUMN_DEFAULT'],
                'autoincrement'   => false,
                'platformOptions' => $isUnsigned ? ['unsigned' => true] : [], // Add unsigned to platformOptions
                'comment'         => null,
            ];
        }
        return $columns;
    }


    /**
     * Maps INFORMATION_SCHEMA data types to DBAL type names.
     * This is a critical piece and needs to be reasonably accurate for the targeted platforms.
     * It can leverage or adapt GenericSchemaManager::mapInformationSchemaType.
     *
     * @param AbstractPlatform $platform The database platform
     * @param string $infoSchemaType The data type from INFORMATION_SCHEMA
     * @param int|null $charMaxLength The maximum character length, if applicable
     * @param int|null $numericPrecision The numeric precision, if applicable
     * @param int|null $numericScale The numeric scale, if applicable
     * @param string|null $columnTypeFull The full column type (e.g., "int(11) unsigned")
     * @return string The corresponding DBAL type name
     */
    protected function mapInformationSchemaTypeToDbalType(
        AbstractPlatform $platform,
        string           $infoSchemaType,
        ?int             $charMaxLength,
        ?int             $numericPrecision,
        ?int             $numericScale,
        ?string          $columnTypeFull = null // Added for more context, e.g., "int(11) unsigned"
    ): string
    {
        $infoSchemaTypeLower = strtolower($infoSchemaType);

        // Use $columnTypeFull for MariaDB/MySQL specific checks if available
        if ($columnTypeFull && ($platform instanceof MariaDBPlatform || $platform instanceof MySQLPlatform)) {
            $columnTypeFullLower = strtolower($columnTypeFull);
            if ($infoSchemaTypeLower === 'tinyint' && $numericPrecision === 1 && str_contains($columnTypeFullLower, 'tinyint(1)')) {
                return DbalTypes::BOOLEAN; // Common convention for TINYINT(1)
            }
            if ($infoSchemaTypeLower === 'year' || str_starts_with($columnTypeFullLower, 'year')) { // YEAR or YEAR(4)
                return DbalTypes::DATE_MUTABLE; // DBAL maps YEAR to DateType
            }
            if (str_starts_with($columnTypeFullLower, 'enum')) return DbalTypes::STRING;
            if (str_starts_with($columnTypeFullLower, 'set')) return DbalTypes::STRING;
        }

        // Generic mapping (from previous version, can be further refined)
        switch ($infoSchemaTypeLower) {
            case 'char':
            case 'varchar':
            case 'character varying':
            case 'nvarchar':
            case 'nchar':
            case 'tinytext': // Often better as STRING unless very large
                return DbalTypes::STRING;
            case 'text':
            case 'ntext':
            case 'mediumtext':
            case 'longtext':
                return DbalTypes::TEXT;
            case 'int':
            case 'integer':
            case 'mediumint':
                return DbalTypes::INTEGER;
            case 'smallint':
                return DbalTypes::SMALLINT;
            case 'bigint':
                return DbalTypes::BIGINT;
            case 'tinyint': // Fallback if not TINYINT(1) already handled
                return DbalTypes::SMALLINT; // Or Types::INTEGER if prefer larger default
            case 'bit':
                if ($platform instanceof SQLServerPlatform && $charMaxLength === 1) return DbalTypes::BOOLEAN;
                if (($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform) && $numericPrecision === 1) return DbalTypes::BOOLEAN;
                return DbalTypes::STRING; // Or BLOB, BIT can be tricky
            case 'boolean':
                return DbalTypes::BOOLEAN;
            case 'decimal':
            case 'numeric':
            case 'dec':
            case 'money':
            case 'smallmoney':
                return DbalTypes::DECIMAL;
            case 'float':
            case 'real':
            case 'double':
            case 'double precision':
                return DbalTypes::FLOAT;
            case 'date':
                return DbalTypes::DATE_MUTABLE;
            case 'datetime':
            case 'datetime2':
            case 'smalldatetime':
            case 'timestamp':
            case 'timestamp without time zone':
                return DbalTypes::DATETIME_MUTABLE;
            case 'timestamptz':
            case 'timestamp with time zone':
                return DbalTypes::DATETIMETZ_MUTABLE;
            case 'time':
            case 'time without time zone':
                return DbalTypes::TIME_MUTABLE;
            // YEAR already handled for MySQL/MariaDB if $columnTypeFull is present
            // case 'year': return DbalTypes::DATE_MUTABLE;
            case 'binary':
            case 'varbinary':
            case 'image':
                return DbalTypes::BINARY;
            case 'blob':
            case 'tinyblob':
            case 'mediumblob':
            case 'longblob':
            case 'bytea':
                return DbalTypes::BLOB;
            case 'json':
            case 'jsonb':
                return DbalTypes::JSON;
            case 'uuid':
                return DbalTypes::GUID;
            default:
                $this->logger->warning(
                    "[Introspector::mapInformationSchemaTypeToDbalType] Unknown DATA_TYPE '{$infoSchemaType}' from INFORMATION_SCHEMA. Defaulting to STRING.",
                    ['platform' => get_class($platform), 'column_type_full' => $columnTypeFull]
                );
                return DbalTypes::STRING;
        }
    }


    /**
     * Extracts column definitions from a DBAL Table object (obtained from introspectTable).
     * This is the original way columns were extracted.
     *
     * @param DbalTable $dbalTable The DBAL table object
     * @return array<string, array<string, mixed>> Column definitions indexed by column name
     */
    private function extractColumnDefinitionsFromDbalTable(DbalTable $dbalTable): array
    {
        $columnDefinitions = [];
        foreach ($dbalTable->getColumns() as $dbalColumn) {
            $columnName = $dbalColumn->getName();
            $columnDefinitions[$columnName] = [
                'name'            => $columnName,
                'type'            => Type::lookupName($dbalColumn->getType()),
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
            ];
        }
        return $columnDefinitions;
    }

    /**
     * Determines if a view from the list matches the source we're looking for.
     * Compares names with various rules to handle case sensitivity and schema differences.
     *
     * @param DbalView $viewFromList The view from the list of views
     * @param Identifier $inputIdentifier The identifier of the source we're looking for
     * @param AbstractPlatform $platform The database platform
     * @param bool $isDeterminingType Whether this is being called during type determination
     * @return bool True if the view matches the source
     */
    private function isViewMatchingSource(
        DbalView         $viewFromList,
        Identifier       $inputIdentifier,
        AbstractPlatform $platform,
        bool             $isDeterminingType = false
    ): bool
    {
        $viewCanonicalName = $viewFromList->getName();
        $viewQuotedCanonicalName = $viewFromList->getQuotedName($platform);

        $inputOriginalName = $inputIdentifier->getName();
        $inputQuotedFullName = $inputIdentifier->getQuotedName($platform);

        if (!$isDeterminingType) { // Only log this detailed comparison when not just determining type
            $this->logger->debug(
                "[isViewMatchingSource] Comparing input '{$inputOriginalName}' (Full Quoted Input: '{$inputQuotedFullName}') " .
                "with listed view '{$viewCanonicalName}' (Full Quoted Listed: '{$viewQuotedCanonicalName}')"
            );
        }

        if ($inputQuotedFullName === $viewQuotedCanonicalName) {
            if (!$isDeterminingType) $this->logger->debug("[isViewMatchingSource] Matched (Rule 1): Platform-specific quoted full names are identical.");
            return true;
        }

        $listedViewIdentifier = new Identifier($viewCanonicalName);

        $unquotedInputBaseName = $inputIdentifier->getName();
        $unquotedListedBaseName = $listedViewIdentifier->getName();

        $inputSchema = $inputIdentifier->getNamespaceName();
        $listedSchema = $listedViewIdentifier->getNamespaceName();

        $inputSchemaForLog = $inputSchema ?? 'NULL_SCHEMA';
        $listedSchemaForLog = $listedSchema ?? 'NULL_SCHEMA';

        if (strcasecmp($unquotedListedBaseName, $unquotedInputBaseName) === 0) {
            if ($inputSchema === $listedSchema) {
                if (!$isDeterminingType) $this->logger->debug("[isViewMatchingSource] Matched (Rule 2a): Base names match case-insensitively, and schemas are identical ('{$inputSchemaForLog}' vs '{$listedSchemaForLog}').");
                return true;
            }
            if ($inputSchema === null && $listedSchema !== null) {
                if (!$isDeterminingType) $this->logger->debug("[isViewMatchingSource] Matched (Rule 2b): Base names match case-insensitively. Input schema is null, listed schema is '{$listedSchemaForLog}'. Assumed match.");
                return true;
            }
            if ($inputSchema !== null && $listedSchema === null) {
                if (!$isDeterminingType) $this->logger->debug("[isViewMatchingSource] Matched (Rule 2c): Base names match case-insensitively. Input schema is '{$inputSchemaForLog}', listed schema is null. Assumed match.");
                return true;
            }
        }

        // Only log "No match" if we're not in the quiet "determining type" mode.
        if (!$isDeterminingType) {
            $this->logger->debug("[isViewMatchingSource] No match for input '{$inputOriginalName}' (Full Quoted Input: '{$inputQuotedFullName}') with listed view '{$viewCanonicalName}' (Full Quoted Listed: '{$viewQuotedCanonicalName}').");
        }
        return false;
    }

    /**
     * Gets database information for error messages.
     *
     * @param Connection $connection The database connection
     * @return string A string with database information for error messages
     */
    private function getDbInfoForError(Connection $connection): string
    {
        try {
            $dbName = $connection->getDatabase();
            return $dbName ? " in database `{$dbName}`" : "";
        } catch (Throwable $_) {
            return "";
        }
    }
}