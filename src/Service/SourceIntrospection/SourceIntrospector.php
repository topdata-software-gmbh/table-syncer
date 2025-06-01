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
