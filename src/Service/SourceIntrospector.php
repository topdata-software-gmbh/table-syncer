<?php

/**
 * Service for introspecting database tables and views to extract column definitions.
 * This class provides methods to analyze database objects and return their structure.
 */

namespace TopdataSoftwareGmbh\TableSyncer\Service; // Corrected namespace based on other files

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
use Throwable; // Redundant if you fully qualify \Throwable
use TopdataSoftwareGmbh\TableSyncer\Enum\SourceObjectTypeEnum;
use TopdataSoftwareGmbh\TableSyncer\Exception\ConfigurationException;

// Assuming TopdataSoftwareGmbh\TableSyncer\Service\SourceIntrospection\SourceIntrospector; was a typo in prev. request
// and the class is directly in TopdataSoftwareGmbh\TableSyncer\Service;
// If SourceIntrospector IS in a sub-namespace SourceIntrospection, the namespace declaration above should be:
// namespace TopdataSoftwareGmbh\TableSyncer\Service\SourceIntrospection;
// And the 'use' statement in GenericSchemaManager would be:
// use TopdataSoftwareGmbh\TableSyncer\Service\SourceIntrospection\SourceIntrospector;

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
        $inputIdentifier = new Identifier($sourceName);

        $this->logger->debug(
            "[Introspector] Starting introspection for source '{$inputIdentifier->getName()}' (quoted: '{$inputIdentifier->getQuotedName($platform)}'){$dbInfo}."
        );

        $sourceType = $this->determineInitialSourceObjectType($schemaManager, $inputIdentifier, $platform);
        $nameToIntrospect = $inputIdentifier->getName();
        $schemaForQuery = $inputIdentifier->getNamespaceName();

        if ($sourceType === SourceObjectTypeEnum::VIEW) {
            $this->logger->info("[Introspector] Source '{$sourceName}' identified as a VIEW. Attempting custom introspection via INFORMATION_SCHEMA.COLUMNS.");
            try {
                $columns = $this->introspectViewColumnsFromInformationSchema($sourceConnection, $nameToIntrospect, $schemaForQuery);
                if (empty($columns)) {
                    $this->logger->warning("[Introspector] Custom view introspection for '{$sourceName}' returned no columns. This might indicate an issue or an empty view schema definition.");
                }
                return $columns;
            } catch (\Throwable $e) { // Changed from Throwable to \Throwable for clarity
                $this->logger->error(
                    "[Introspector] Custom view introspection for '{$sourceName}' failed: " . $e->getMessage() .
                    ". Falling back to attempting introspectTable() as a last resort for this view.",
                    ['exception_class' => get_class($e), 'trace' => $e->getTraceAsString()] // Added trace for better debugging
                );
            }
        } elseif ($sourceType === SourceObjectTypeEnum::TABLE) {
            $this->logger->info("[Introspector] Source '{$sourceName}' identified as a TABLE. Using schemaManager->introspectTable().");
        } else {
            $this->logger->info("[Introspector] Source '{$sourceName}' type is undetermined (" . ($sourceType ? $sourceType->value : 'null') ."). Attempting schemaManager->introspectTable().");
        }

        try {
            $this->logger->debug(
                "[Introspector] Using schemaManager->introspectTable() for '{$sourceName}'."
            );
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
            throw new ConfigurationException("Source object '{$sourceName}'{$dbInfo} not found or not introspectable (via introspectTable after other checks): " . $e->getMessage(), 0, $e);
        } catch (\Throwable $e) { // Changed from Throwable to \Throwable
            $this->logger->error(
                "[Introspector] Unexpected error using schemaManager->introspectTable('{$sourceName}'){$dbInfo}: " . $e->getMessage(),
                ['exception_class' => get_class($e), 'trace' => $e->getTraceAsString()]
            );
            throw new ConfigurationException("Error introspecting source '{$sourceName}'{$dbInfo} via introspectTable: " . $e->getMessage(), 0, $e);
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
        Identifier            $inputIdentifier,
        AbstractPlatform      $platform
    ): SourceObjectTypeEnum {
        // Use the quoted name for tablesExist for better compatibility with DBAL's expectations.
        $quotedSourceNameForExistenceCheck = $inputIdentifier->getQuotedName($platform);

        if ($schemaManager->tablesExist([$quotedSourceNameForExistenceCheck])) {
            // It's known as a table.
            // Log if it *also* appears in listViews for ambiguity awareness,
            // but we will still prioritize it as TABLE if tablesExist() is true.
            try {
                $views = $schemaManager->listViews();
                foreach ($views as $viewFromList) {
                    // Pass $inputIdentifier (which might be unquoted original) for matching
                    if ($this->isViewMatchingSource($viewFromList, $inputIdentifier, $platform, true)) {
                        $this->logger->debug(
                            "[Introspector::determineInitial] Object '{$inputIdentifier->getName()}' (quoted: '{$quotedSourceNameForExistenceCheck}') was found by tablesExist() AND also matches view '{$viewFromList->getName()}' in listViews(). Prioritizing as TABLE."
                        );
                        break;
                    }
                }
            } catch (\Throwable $e) { // RECTIFIED: Catch specific \Throwable and log it
                $this->logger->warning(
                    "[Introspector::determineInitial] While checking if '{$quotedSourceNameForExistenceCheck}' (already identified as a table by tablesExist()) is *also* a view, an error occurred trying to listViews(). This check is skipped, proceeding as TABLE.",
                    ['exception_message' => $e->getMessage(), 'exception_class' => get_class($e)]
                );
            }
            return SourceObjectTypeEnum::TABLE;
        }

        // Not found by tablesExist(), now check if it's a view.
        try {
            $views = $schemaManager->listViews();
            if (empty($views) && !$platform->supportsViews()) {
                $this->logger->debug("[Introspector::determineInitial] listViews() returned empty, and platform reports no view support for '{$inputIdentifier->getName()}'. Assuming UNKNOWN.");
                return SourceObjectTypeEnum::UNKNOWN;
            }
            foreach ($views as $viewFromList) {
                if ($this->isViewMatchingSource($viewFromList, $inputIdentifier, $platform, true)) {
                    return SourceObjectTypeEnum::VIEW;
                }
            }
        } catch (\Throwable $e) { // Changed from Throwable to \Throwable
            $this->logger->warning(
                "[Introspector::determineInitial] Could not list views to determine type for '{$inputIdentifier->getName()}': " . $e->getMessage(),
                ['exception_class' => get_class($e)]
            );
            return SourceObjectTypeEnum::UNKNOWN;
        }

        $this->logger->debug("[Introspector::determineInitial] '{$inputIdentifier->getName()}' not found via tablesExist() or in listViews(). Type is UNKNOWN.");
        return SourceObjectTypeEnum::UNKNOWN;
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
        if ($platform instanceof MariaDBPlatform || $platform instanceof MySQLPlatform) {
            $sql .= ", COLUMN_TYPE ";
        }
        $sql .= "FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ?";

        $params = [$viewName];
        $types = [ParameterType::STRING];

        if ($schemaName !== null && $schemaName !== '') {
            $sql .= " AND TABLE_SCHEMA = ?";
            $params[] = $schemaName;
            $types[] = ParameterType::STRING;
        } else {
            if ($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform) {
                $sql .= " AND TABLE_SCHEMA = DATABASE()";
            } elseif ($platform instanceof PostgreSQLPlatform) {
                $sql .= " AND TABLE_SCHEMA = CURRENT_SCHEMA()";
            }
        }
        $sql .= " ORDER BY ORDINAL_POSITION";

        $this->logger->debug("[Introspector::introspectViewColumns] Executing query: {$sql}", ['params' => $params, 'types_for_dbal' => $types]);
        $stmt = $connection->executeQuery($sql, $params, $types);
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
                isset($col['CHARACTER_MAXIMUM_LENGTH']) ? (int)$col['CHARACTER_MAXIMUM_LENGTH'] : null,
                isset($col['NUMERIC_PRECISION']) ? (int)$col['NUMERIC_PRECISION'] : null,
                isset($col['NUMERIC_SCALE']) ? (int)$col['NUMERIC_SCALE'] : null,
                $columnTypeFull
            );

            $isUnsigned = false;
            if ($columnTypeFull && stripos($columnTypeFull, 'unsigned') !== false) {
                $isUnsigned = true;
            }

            $columns[$columnName] = [
                'name'            => $columnName,
                'type'            => $dbalType,
                'length'          => isset($col['CHARACTER_MAXIMUM_LENGTH']) ? (int)$col['CHARACTER_MAXIMUM_LENGTH'] : null,
                'precision'       => isset($col['NUMERIC_PRECISION']) ? (int)$col['NUMERIC_PRECISION'] : null,
                'scale'           => isset($col['NUMERIC_SCALE']) ? (int)$col['NUMERIC_SCALE'] : null,
                'unsigned'        => $isUnsigned,
                'fixed'           => false,
                'notnull'         => isset($col['IS_NULLABLE']) && strtoupper($col['IS_NULLABLE']) === 'NO',
                'default'         => $col['COLUMN_DEFAULT'] ?? null, // Handle if COLUMN_DEFAULT is not present
                'autoincrement'   => false,
                'platformOptions' => $isUnsigned ? ['unsigned' => true] : [],
                'comment'         => null,
            ];
        }
        return $columns;
    }


    /**
     * Maps INFORMATION_SCHEMA data types to DBAL type names.
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
        string           $infoSchemaType, // DATA_TYPE is usually not null
        ?int             $charMaxLength,
        ?int             $numericPrecision,
        ?int             $numericScale,
        ?string          $columnTypeFull = null
    ): string {
        $infoSchemaTypeLower = strtolower($infoSchemaType);

        if ($columnTypeFull && ($platform instanceof MariaDBPlatform || $platform instanceof MySQLPlatform)) {
            $columnTypeFullLower = strtolower($columnTypeFull);
            if ($infoSchemaTypeLower === 'tinyint' && $numericPrecision === 1 && str_contains($columnTypeFullLower, 'tinyint(1)')) {
                return DbalTypes::BOOLEAN;
            }
            if ($infoSchemaTypeLower === 'year' || str_starts_with($columnTypeFullLower, 'year')) {
                return DbalTypes::DATE_MUTABLE;
            }
            if (str_starts_with($columnTypeFullLower, 'enum')) return DbalTypes::STRING;
            if (str_starts_with($columnTypeFullLower, 'set')) return DbalTypes::STRING;
        }

        switch ($infoSchemaTypeLower) {
            case 'char': case 'varchar': case 'character varying': case 'nvarchar': case 'nchar':
            case 'tinytext':
                return DbalTypes::STRING;
            case 'text': case 'ntext': case 'mediumtext': case 'longtext':
            return DbalTypes::TEXT;
            case 'int': case 'integer': case 'mediumint':
            return DbalTypes::INTEGER;
            case 'smallint':
                return DbalTypes::SMALLINT;
            case 'bigint':
                return DbalTypes::BIGINT;
            case 'tinyint':
                // If it's MySQL/MariaDB tinyint(1) has already been mapped to boolean
                // For other platforms, or if not tinyint(1), treat as smallint
                return DbalTypes::SMALLINT;
            case 'bit':
                if ($platform instanceof SQLServerPlatform && $charMaxLength === 1) return DbalTypes::BOOLEAN;
                if (($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform) && $numericPrecision === 1) return DbalTypes::BOOLEAN;
                return DbalTypes::STRING;
            case 'boolean':
                return DbalTypes::BOOLEAN;
            case 'decimal': case 'numeric': case 'dec': case 'money': case 'smallmoney':
            return DbalTypes::DECIMAL;
            case 'float': case 'real': case 'double': case 'double precision':
            return DbalTypes::FLOAT;
            case 'date':
                return DbalTypes::DATE_MUTABLE;
            case 'datetime': case 'datetime2': case 'smalldatetime':
            case 'timestamp': case 'timestamp without time zone':
            return DbalTypes::DATETIME_MUTABLE;
            case 'timestamptz': case 'timestamp with time zone':
            return DbalTypes::DATETIMETZ_MUTABLE;
            case 'time': case 'time without time zone':
            return DbalTypes::TIME_MUTABLE;
            case 'binary': case 'varbinary': case 'image':
            return DbalTypes::BINARY;
            case 'blob': case 'tinyblob': case 'mediumblob': case 'longblob': case 'bytea':
            return DbalTypes::BLOB;
            case 'json': case 'jsonb':
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
    ): bool {
        $viewCanonicalName = $viewFromList->getName();
        $viewQuotedCanonicalName = $viewFromList->getQuotedName($platform);

        $inputOriginalName = $inputIdentifier->getName(); // This is base name if input was "schema.name"
        $inputQuotedFullName = $inputIdentifier->getQuotedName($platform);

        if (!$isDeterminingType) {
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

        $unquotedInputBaseName = $inputIdentifier->getName(); // This is base name from original input
        $unquotedListedBaseName = $listedViewIdentifier->getName(); // This is base name from listed view

        $inputSchema = $inputIdentifier->getNamespaceName();
        $listedSchema = $listedViewIdentifier->getNamespaceName();

        $inputSchemaForLog = $inputSchema ?? 'NULL_SCHEMA';
        $listedSchemaForLog = $listedSchema ?? 'NULL_SCHEMA';

        if (strcasecmp($unquotedListedBaseName, $unquotedInputBaseName) === 0) {
            // Schemas are compatible if:
            // 1. They are identical (e.g., both 'public' or both null).
            // 2. The input name had no schema, allowing it to match a view from any schema (often default).
            // 3. The listed view had no schema (implying default schema), allowing a qualified input to match if it's the default.
            if ($inputSchema === $listedSchema || $inputSchema === null || $listedSchema === null) {
                if (!$isDeterminingType) $this->logger->debug("[isViewMatchingSource] Matched (Rule 2): Base names match case-insensitively ('{$unquotedInputBaseName}' vs '{$unquotedListedBaseName}') and schemas are compatible ('{$inputSchemaForLog}' vs '{$listedSchemaForLog}').");
                return true;
            }
        }

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
        } catch (\Throwable $_) { // Changed from Throwable to \Throwable
            return "";
        }
    }
}
