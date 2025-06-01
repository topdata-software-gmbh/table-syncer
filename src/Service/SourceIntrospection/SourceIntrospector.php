<?php

namespace TopdataSoftwareGmbh\TableSyncer\Service\SourceIntrospection;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Types\Type;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TopdataSoftwareGmbh\TableSyncer\Exception\ConfigurationException;
use Doctrine\DBAL\Schema\Column as DbalColumn;
use Doctrine\DBAL\Schema\Identifier;

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

        try {
            $this->logger->debug("Attempting to introspect source '{$sourceName}' directly (works for tables and views).");
            $tableDetails = $schemaManager->introspectTable($sourceName); // This works for views too in DBAL

            // If introspectTable succeeded, it's either a table or a view (or similar)
            if ($schemaManager->tablesExist([$sourceName])) {
                $sourceTypeForLogging = "TABLE";
            } else {
                // Not a table, so let's check if it's a known view
                $isConfirmedView = false;
                try {
                    $views = $schemaManager->listViews();
                    $platform = $sourceConnection->getDatabasePlatform();
                    
                    // Normalize the input $sourceName once for comparison
                    $inputIdentifier = new Identifier($sourceName); // Handles if $sourceName is already quoted or contains schema
                    $unquotedInputName = $inputIdentifier->getName(); // Gets the simple unquoted name
                    $quotedInputNamePlatform = $inputIdentifier->getQuotedName($platform); // Gets platform-specific quoted name

                    foreach ($views as $view) {
                        // Compare unquoted names (often sufficient and more robust to case variations if unquoted)
                        // $view->getName() might return 'schema.viewname' or just 'viewname'
                        $viewIdentifier = new Identifier($view->getName());
                        if (strcasecmp($viewIdentifier->getName(), $unquotedInputName) === 0) {
                             // Check if schemas match if both $sourceName and view name include one
                            if ($inputIdentifier->getNamespaceName() === $viewIdentifier->getNamespaceName() ||
                                $inputIdentifier->getNamespaceName() === null || // $sourceName had no schema, view might
                                $viewIdentifier->getNamespaceName() === null  // view had no schema, $sourceName might
                            ) {
                                $isConfirmedView = true;
                                break;
                            }
                        }
                        // Fallback: Compare platform-specific quoted names
                        if ($view->getQuotedName($platform) === $quotedInputNamePlatform) {
                            $isConfirmedView = true;
                            break;
                        }
                    }
                } catch (\Doctrine\DBAL\Exception $e) {
                    $this->logger->warning(
                        "Could not list views to determine type for '{$sourceName}' (after successful introspection, and not a table). Error: " . $e->getMessage(),
                        ['exception_class' => get_class($e)]
                    );
                    // Keep $isConfirmedView = false
                }

                if ($isConfirmedView) {
                    $sourceTypeForLogging = "VIEW";
                } else {
                    // If introspectTable worked, it's not a table, and not found in listViews,
                    // it's an "introspectable object (type undetermined)".
                    $sourceTypeForLogging = "INTROSPECTABLE OBJECT (type undetermined, not in listViews)";
                }
            }

            $this->logger->info("Successfully introspected '{$sourceName}' (identified as {$sourceTypeForLogging}). Extracting column definitions.");
            return $this->extractColumnDefinitions($tableDetails->getColumns());

        } catch (TableNotFoundException $e) {
            $this->logger->error("Source '{$sourceName}' not found or not introspectable (via introspectTable). Error: " . $e->getMessage(), ['exception_class' => get_class($e)]);
            throw new ConfigurationException("Source table or view '{$sourceName}' does not exist or is not accessible/introspectable in the source database `{$sourceConnection->getDatabase()}`.", 0, $e);
        } catch (\Throwable $e) {
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
