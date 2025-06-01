# Refactoring Plan: Unified Source Introspection for Table Syncer

**Goal:** Refactor the `GenericSchemaManager` to use a new `SourceIntrospector` class that can handle both database tables and views as sources, removing code duplication and improving separation of concerns.

## Phase 1: Create the `SourceIntrospector` Class

1.  **Create a new directory:**
    *   Path: `src/Service/SourceIntrospection/`

2.  **Create the `SourceIntrospector.php` file:**
    *   Path: `src/Service/SourceIntrospection/SourceIntrospector.php`
    *   **Contents:**
        ```php
        <?php

        namespace TopdataSoftwareGmbh\TableSyncer\Service\SourceIntrospection;

        use Doctrine\DBAL\Connection;
        use Doctrine\DBAL\Schema\Exception\TableNotFoundException;
        use Doctrine\DBAL\Types\Type;
        use Psr\Log\LoggerInterface;
        use Psr\Log\NullLogger;
        use TopdataSoftwareGmbh\TableSyncer\Exception\ConfigurationException;
        use Doctrine\DBAL\Schema\Column as DbalColumn; // For the helper method type hint

        class SourceIntrospector
        {
            private readonly LoggerInterface $logger;

            public function __construct(?LoggerInterface $logger = null)
            {
                $this->logger = $logger ?? new NullLogger();
            }

            /**
             * Introspects the source (table or view) and returns its column definitions.
             *
             * @param Connection $sourceConnection
             * @param string $sourceName
             * @return array<string, array<string, mixed>> Column name to definition map.
             * @throws ConfigurationException If the source does not exist or cannot be introspected.
             */
            public function introspectSource(Connection $sourceConnection, string $sourceName): array
            {
                $schemaManager = $sourceConnection->createSchemaManager();
                $sourceTypeForLogging = "UNKNOWN";
                $sourceIdentifierExistsAndIsIntrospectable = false;

                // 1. Check if it's a table
                if ($schemaManager->tablesExist([$sourceName])) {
                    $sourceTypeForLogging = "TABLE";
                    $this->logger->info("Source '{$sourceName}' identified as a {$sourceTypeForLogging}.");
                    $sourceIdentifierExistsAndIsIntrospectable = true;
                } else {
                    $this->logger->debug("Source '{$sourceName}' not found as a TABLE, checking if it's a VIEW.");
                    // 2. Check if it's a view
                    if (method_exists($schemaManager, 'viewsExist')) { // DBAL 3.2.0+
                        if ($schemaManager->viewsExist([$sourceName])) {
                            $sourceTypeForLogging = "VIEW";
                            $this->logger->info("Source '{$sourceName}' identified as a {$sourceTypeForLogging} (using viewsExist).");
                            $sourceIdentifierExistsAndIsIntrospectable = true;
                        }
                    } else { // Fallback for DBAL 3.0.x, 3.1.x
                        try {
                            $views = $schemaManager->listViews();
                            foreach ($views as $view) {
                                if ($view->getName() === $sourceName ||
                                    $view->getQuotedName($sourceConnection->getDatabasePlatform()) === $sourceConnection->quoteIdentifier($sourceName)) {
                                    $sourceTypeForLogging = "VIEW";
                                    $this->logger->info("Source '{$sourceName}' identified as a {$sourceTypeForLogging} (by listing views).");
                                    $sourceIdentifierExistsAndIsIntrospectable = true;
                                    break;
                                }
                            }
                        } catch (\Doctrine\DBAL\Exception $e) {
                            $this->logger->warning(
                                "Could not list views to check for '{$sourceName}'. Error: " . $e->getMessage(),
                                ['source' => $sourceName, 'exception_class' => get_class($e)]
                            );
                        }
                    }
                }

                // 3. If not confirmed by specific table/view checks, try to introspect directly.
                if (!$sourceIdentifierExistsAndIsIntrospectable) {
                    $this->logger->debug("Source '{$sourceName}' not confirmed by tablesExist/viewsExist/listViews. Attempting direct introspection as a final check.");
                    try {
                        $schemaManager->introspectTable($sourceName); // Test introspection
                        $sourceTypeForLogging = "INTROSPECTABLE OBJECT"; // Could be table or view, or other DB object
                        $this->logger->info("Source '{$sourceName}' is introspectable (confirmed by direct introspection attempt), treating as {$sourceTypeForLogging}.");
                        $sourceIdentifierExistsAndIsIntrospectable = true;
                    } catch (TableNotFoundException $e) {
                        throw new ConfigurationException("Source table or view '{$sourceName}' does not exist or is not accessible in the source database `{$sourceConnection->getDatabase()}`.", 0, $e);
                    } catch (\Throwable $e) {
                         throw new ConfigurationException("Error while trying to confirm existence and introspect source '{$sourceName}': " . $e->getMessage(), 0, $e);
                    }
                }
                
                if (!$sourceIdentifierExistsAndIsIntrospectable) { // Should be caught by exceptions above
                     throw new ConfigurationException("Source '{$sourceName}' could not be identified or introspected after all checks in the source database `{$sourceConnection->getDatabase()}`.");
                }

                // Perform the actual introspection to get details
                try {
                    $tableDetails = $schemaManager->introspectTable($sourceName);
                } catch (\Throwable $e) { // Catch any error during the final introspection
                    throw new ConfigurationException("Failed to introspect details for '{$sourceName}' (identified as {$sourceTypeForLogging}): " . $e->getMessage(), 0, $e);
                }
                
                $this->logger->debug("Successfully introspected '{$sourceName}' (as {$sourceTypeForLogging}). Extracting column definitions.");
                return $this->extractColumnDefinitions($tableDetails->getColumns());
            }

            /**
             * Extracts column definitions from an array of DBAL Column objects.
             *
             * @param DbalColumn[] $dbalColumns
             * @return array<string, array<string, mixed>>
             */
            private function extractColumnDefinitions(array $dbalColumns): array
            {
                $columnDefinitions = [];
                foreach ($dbalColumns as $dbalColumn) {
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
        }
        ```

## Phase 2: Refactor `GenericSchemaManager`

1.  **Modify `src/Service/GenericSchemaManager.php`:**
    *   **Add Import:**
        ```php
        use TopdataSoftwareGmbh\TableSyncer\Service\SourceIntrospector;
        ```
    *   **Update Properties:**
        *   Change `private readonly array $sourceIntrospectors;` to:
            ```php
            private readonly SourceIntrospector $sourceIntrospector;
            ```
    *   **Update Constructor:**
        *   Change the constructor signature and body to:
            ```php
            public function __construct(
                ?LoggerInterface $logger = null,
                ?SourceIntrospector $sourceIntrospector = null // Allow injecting for testing
            ) {
                $this->logger = $logger ?? new NullLogger();
                $this->sourceIntrospector = $sourceIntrospector ?? new SourceIntrospector($this->logger);
            }
            ```
    *   **Update `getSourceColumnDefinitions` method:**
        *   Replace the entire body of `getSourceColumnDefinitions` with the following:
            ```php
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
            ```
    *   **Remove unused method:**
        *   Delete the `getDbalTypeNameFromTypeObject` method if it exists and is no longer used elsewhere in `GenericSchemaManager`.

## Phase 3: Cleanup (If previous intermediary files were created)

1.  If the following files/directories were created in a previous iteration of this refactoring, delete them:
    *   `src/Service/SourceIntrospection/SourceIntrospectorInterface.php`
    *   `src/Service/SourceIntrospection/TableSourceIntrospector.php`
    *   `src/Service/SourceIntrospection/ViewSourceIntrospector.php`
    *   `src/Service/SourceIntrospection/ColumnDefinitionExtractorTrait.php` (if created)

## Phase 4: Verification (Conceptual - AI cannot run tests)

1.  Ensure all existing unit tests for `GenericSchemaManager` (especially those testing `getSourceColumnDefinitions` and `getSourceColumnTypes`) still pass. New tests might be needed to specifically target the `SourceIntrospector` if its logic becomes more complex or to test `GenericSchemaManager`'s interaction with a mocked `SourceIntrospector`.
2.  Conceptually, test the syncer with a source that is a database view to ensure it works correctly.
3.  Review logs to ensure the new logging messages from `SourceIntrospector` appear as expected.

This plan provides a step-by-step guide for the AI agent to perform the refactoring.

