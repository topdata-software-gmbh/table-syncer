<?php

namespace TopdataSoftwareGmbh\TableSyncer\Service\SourceIntrospection;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
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

        // Attempt to introspect. If introspectTable works, we get details regardless of whether
        // tablesExist or viewsExist would have categorized it.
        // introspectTable is the ultimate test of whether DBAL can "see" and describe it like a table.
        try {
            $this->logger->debug("Attempting to introspect source '{$sourceName}' directly.");
            $tableDetails = $schemaManager->introspectTable($sourceName);
            // If successful, we need to determine if it was a table or view for logging.
            // This is a bit tricky post-hoc without re-querying, but we can make an educated guess.
            if ($schemaManager->tablesExist([$sourceName])) {
                $sourceTypeForLogging = "TABLE";
            } elseif (method_exists($schemaManager, 'viewsExist') && $schemaManager->viewsExist([$sourceName])) {
                $sourceTypeForLogging = "VIEW";
            } elseif (!method_exists($schemaManager, 'viewsExist')) { // Fallback for DBAL < 3.2
                try {
                    $views = $schemaManager->listViews();
                    foreach ($views as $view) {
                        if ($view->getName() === $sourceName ||
                            $view->getQuotedName($sourceConnection->getDatabasePlatform()) === $sourceConnection->quoteIdentifier($sourceName)) {
                            $sourceTypeForLogging = "VIEW";
                            break;
                        }
                    }
                    if ($sourceTypeForLogging === "UNKNOWN") $sourceTypeForLogging = "INTROSPECTABLE OBJECT (type undetermined)";

                } catch (\Doctrine\DBAL\Exception $e) {
                    $this->logger->warning("Could not list views to determine type for '{$sourceName}' after successful introspection. Error: " . $e->getMessage());
                    $sourceTypeForLogging = "INTROSPECTABLE OBJECT (type undetermined)";
                }
            } else {
                $sourceTypeForLogging = "INTROSPECTABLE OBJECT (type undetermined)";
            }

            $this->logger->info("Successfully introspected '{$sourceName}' (identified as {$sourceTypeForLogging}). Extracting column definitions.");
            return $this->extractColumnDefinitions($tableDetails->getColumns());

        } catch (TableNotFoundException $e) {
            // This means introspectTable failed to find it as a table or a view it can describe.
            $this->logger->error("Source '{$sourceName}' not found or not introspectable (via introspectTable). Error: " . $e->getMessage(), ['exception_class' => get_class($e)]);
            throw new ConfigurationException("Source table or view '{$sourceName}' does not exist or is not accessible/introspectable in the source database `{$sourceConnection->getDatabase()}`.", 0, $e);
        } catch (\Throwable $e) {
            // Other unexpected error during introspection
            $this->logger->error("Unexpected error while introspecting source '{$sourceName}'. Error: " . $e->getMessage(), ['exception_class' => get_class($e)]);
            throw new ConfigurationException("Error while trying to introspect source '{$sourceName}': " . $e->getMessage(), 0, $e);
        }
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
