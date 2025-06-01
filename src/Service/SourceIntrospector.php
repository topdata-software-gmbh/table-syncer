<?php

namespace TopdataSoftwareGmbh\TableSyncer\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Identifier;
use Doctrine\DBAL\Schema\Table as DbalTable;
use Doctrine\DBAL\Schema\View as DbalView;
use Doctrine\DBAL\Types\Type;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TopdataSoftwareGmbh\TableSyncer\Enum\SourceObjectTypeEnum;
use TopdataSoftwareGmbh\TableSyncer\Exception\ConfigurationException;

class SourceIntrospector
{
    private readonly LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function introspectSource(Connection $sourceConnection, string $sourceName): array
    {
        $schemaManager = $sourceConnection->createSchemaManager();
        $platform = $sourceConnection->getDatabasePlatform();
        $dbInfo = $this->getDbInfoForError($sourceConnection); // Get DB info for logging

        $this->logger->debug(
            "[Introspector] Starting introspection for source '{$sourceName}'{$dbInfo}."
        );

        /** @var DbalTable|null $tableDetails */
        $tableDetails = null;
        $nameUsedForSuccessfulIntrospection = $sourceName;

        // Attempt 1: Introspect with the original name directly.
        try {
            $this->logger->debug(
                "[Introspector] Attempt 1: Introspecting '{$sourceName}' directly."
            );
            $tableDetails = $schemaManager->introspectTable($sourceName);
            // If successful, $tableDetails->getName() gives the canonical name.
            $nameUsedForSuccessfulIntrospection = $tableDetails->getName();
            $this->logger->info(
                "[Introspector] Successfully introspected '{$nameUsedForSuccessfulIntrospection}' (original input: '{$sourceName}') on first attempt."
            );
        } catch (TableNotFoundException $e) {
            $this->logger->warning(
                "[Introspector] Attempt 1 for '{$sourceName}' failed: " . $e->getMessage() .
                " Attempting to find in listViews() and introspect using its canonical name."
            );

            // Attempt 2: Fallback - list views and try to find a match.
            try {
                $views = $schemaManager->listViews();
                if (empty($views)) {
                    $this->logger->warning("[Introspector] listViews() returned an empty list. Cannot find '{$sourceName}' as a view. Re-throwing original exception.");
                    throw $e; // Re-throw the original TableNotFoundException
                }

                $this->logger->debug("[Introspector] listViews() returned " . count($views) . " views. Searching for a match for '{$sourceName}'.");
                // Enhanced logging for view names - check if your logger is an instance that has isEnabled or similar, or just log if it's not NullLogger
                // A more robust check might be needed depending on the PSR-3 adapter in use.
                // For simplicity, we'll assume debug is generally enabled if not NullLogger.
                if (!($this->logger instanceof NullLogger)) {
                    $listedViewNamesForDebug = [];
                    foreach ($views as $v) {
                        $listedViewNamesForDebug[] = $v->getName() . " (quoted: " . $v->getQuotedName($platform) . ")";
                    }
                    if (!empty($listedViewNamesForDebug)) {
                        $this->logger->debug("[Introspector] Views from listViews(): " . implode(", ", $listedViewNamesForDebug));
                    } else {
                        $this->logger->debug("[Introspector] listViews() seems to have returned views, but processing for debug log resulted in empty list (unexpected).");
                    }
                }


                $inputIdentifier = new Identifier($sourceName);
                $foundMatchingView = null;

                foreach ($views as $viewFromList) {
                    if ($this->isViewMatchingSource($viewFromList, $inputIdentifier, $platform)) {
                        $foundMatchingView = $viewFromList;
                        break;
                    }
                }

                if ($foundMatchingView) {
                    $canonicalViewNameFromList = $foundMatchingView->getName();
                    $this->logger->info(
                        "[Introspector] Found matching view in listViews() for '{$sourceName}': '{$canonicalViewNameFromList}'. Attempting introspection with this canonical name."
                    );
                    try {
                        $tableDetails = $schemaManager->introspectTable($canonicalViewNameFromList);
                        $nameUsedForSuccessfulIntrospection = $tableDetails->getName(); // Canonical name from this success
                        $this->logger->info(
                            "[Introspector] Successfully introspected '{$nameUsedForSuccessfulIntrospection}' (original input: '{$sourceName}', via view list as '{$canonicalViewNameFromList}') on second attempt."
                        );
                    } catch (TableNotFoundException $e2) {
                        $this->logger->error(
                            "[Introspector] Attempt 2 introspection for '{$canonicalViewNameFromList}' (for original '{$sourceName}') also failed: " . $e2->getMessage() .
                            " Re-throwing original exception for '{$sourceName}'."
                        );
                        throw $e; // Re-throw the *original* TableNotFoundException.
                    } catch (\Throwable $eUnexpected2) {
                        $this->logger->error(
                            "[Introspector] Unexpected error introspecting '{$canonicalViewNameFromList}' (for '{$sourceName}'): " . $eUnexpected2->getMessage()
                        );
                        throw new ConfigurationException("Error introspecting confirmed view '{$canonicalViewNameFromList}' (originally '{$sourceName}'{$dbInfo}): " . $eUnexpected2->getMessage(), 0, $eUnexpected2);
                    }
                } else {
                    $this->logger->warning(
                        "[Introspector] '{$sourceName}' not found directly, and no matching view found in listViews(). Re-throwing original exception."
                    );
                    throw $e; // Re-throw the original TableNotFoundException.
                }
            } catch (\Doctrine\DBAL\Exception $listViewsException) {
                $this->logger->error(
                    "[Introspector] Failed to list views while trying to recover for '{$sourceName}': " . $listViewsException->getMessage() .
                    " Re-throwing original exception.",
                    ['original_exception_message' => $e->getMessage()]
                );
                throw $e; // Re-throw the original TableNotFoundException.
            }
        } catch (\Throwable $e) {
            // Catches any other \Throwable from the first introspection attempt (not TableNotFoundException).
            $this->logger->error(
                "[Introspector] Unexpected error during initial introspection of '{$sourceName}'{$dbInfo}: " . $e->getMessage(),
                ['exception_class' => get_class($e)]
            );
            throw new ConfigurationException("Error while trying to introspect source '{$sourceName}'{$dbInfo}: " . $e->getMessage(), 0, $e);
        }

        if ($tableDetails === null) {
            // This path should ideally not be hit if exceptions are properly re-thrown.
            // If it is, it means something went wrong with the exception flow.
            $this->logger->error("[Introspector] CRITICAL: tableDetails is null after introspection attempts for '{$sourceName}'{$dbInfo}. This indicates an issue in the introspector's control flow.");
            throw new ConfigurationException("Failed to introspect source '{$sourceName}'{$dbInfo} after all attempts. Introspection details are missing.");
        }

        $sourceTypeForLogging = $this->determineSourceObjectType($schemaManager, $nameUsedForSuccessfulIntrospection, $platform);

        $this->logger->info(
            "[Introspector] Final success: Introspected '{$nameUsedForSuccessfulIntrospection}' (original input: '{$sourceName}', identified as {$sourceTypeForLogging->value}). Extracting column definitions."
        );
        return $this->extractColumnDefinitions($tableDetails->getColumns());
    }

    private function determineSourceObjectType(
        \Doctrine\DBAL\Schema\AbstractSchemaManager $schemaManager,
        string $introspectedName, // This should be the name that succeeded introspection
        AbstractPlatform $platform
    ): SourceObjectTypeEnum {
        // Use the name that was successfully introspected for checks
        if ($schemaManager->tablesExist([$introspectedName])) {
            return SourceObjectTypeEnum::TABLE;
        }
        try {
            $views = $schemaManager->listViews();
            $identifierForIntrospectedName = new Identifier($introspectedName);
            foreach ($views as $viewFromList) {
                // Compare the successfully introspected name against the view list
                if ($this->isViewMatchingSource($viewFromList, $identifierForIntrospectedName, $platform, true)) {
                    return SourceObjectTypeEnum::VIEW;
                }
            }
        } catch (\Doctrine\DBAL\Exception $e) {
            $this->logger->warning(
                "[Introspector::determineSourceObjectType] Could not list views to confirm type for successfully introspected '{$introspectedName}' (it's not a table). Error: " . $e->getMessage()
            );
        }
        // If introspectTable worked, it's not a table, and not found in listViews using the successfully introspected name
        return SourceObjectTypeEnum::INTROSPECTABLE_OBJECT_NOT_IN_LISTVIEWS;
    }

    private function isViewMatchingSource(
        DbalView $viewFromList, // The View object from schemaManager->listViews()
        Identifier $inputIdentifier, // Identifier for the name we are searching for (e.g., originally "_syncer_vw_waregroups")
        AbstractPlatform $platform,
        bool $isDeterminingType = false // Flag to reduce log verbosity when called from determineSourceObjectType
    ): bool {
        $viewCanonicalName = $viewFromList->getName(); // e.g., "schema.viewname" or "viewname"
        $viewQuotedCanonicalName = $viewFromList->getQuotedName($platform);

        $inputOriginalName = $inputIdentifier->getName(); // Base name if input was "schema.name", or full name if unqualified
        $inputQuotedFullName = $inputIdentifier->getQuotedName($platform); // Platform-quoted version of the full input

        if (!$isDeterminingType) {
            $this->logger->debug(
                "[isViewMatchingSource] Comparing input '{$inputOriginalName}' (Full Quoted Input: '{$inputQuotedFullName}') " .
                "with listed view '{$viewCanonicalName}' (Full Quoted Listed: '{$viewQuotedCanonicalName}')"
            );
        }

        // Match 1: Direct match of platform-quoted full names. (e.g. "schema"."view" vs "schema"."view")
        if ($inputQuotedFullName === $viewQuotedCanonicalName) {
            if (!$isDeterminingType) $this->logger->debug("[isViewMatchingSource] Matched (Rule 1): Platform-specific quoted full names are identical.");
            return true;
        }

        // Prepare identifiers for more granular comparison
        $listedViewIdentifier = new Identifier($viewCanonicalName);

        $unquotedInputBaseName = $inputIdentifier->getName();      // Base name of input (e.g., "viewname" from "schema.viewname" or "viewname")
        $unquotedListedBaseName = $listedViewIdentifier->getName(); // Base name of listed view

        $inputSchema = $inputIdentifier->getNamespaceName();    // Schema from input, if any (e.g., "schema" or null)
        $listedSchema = $listedViewIdentifier->getNamespaceName(); // Schema from listed view, if any

        // Match 2: Base names match (case-insensitive), and schemas are compatible.
        if (strcasecmp($unquotedListedBaseName, $unquotedInputBaseName) === 0) {
            // Schemas are compatible if:
            // a) They are exactly the same (both null, or both 'public').
            // b) Input is unqualified, listed is qualified (input 'view' matches listed 'public.view').
            // c) Input is qualified, listed is unqualified (input 'public.view' matches listed 'view', assuming 'view' is in default schema 'public').
            if ($inputSchema === $listedSchema) { // Covers null === null and 'schemaA' === 'schemaA'
                if (!$isDeterminingType) $this->logger->debug("[isViewMatchingSource] Matched (Rule 2a): Base names match case-insensitively, and schemas are identical ('{$inputSchema}' vs '{$listedSchema}').");
                return true;
            }
            if ($inputSchema === null && $listedSchema !== null) { // Input 'viewname', listed 'actual_schema.viewname'
                if (!$isDeterminingType) $this->logger->debug("[isViewMatchingSource] Matched (Rule 2b): Base names match case-insensitively. Input schema is null, listed schema is '{$listedSchema}'. Assumed match.");
                return true;
            }
            if ($inputSchema !== null && $listedSchema === null) { // Input 'intended_schema.viewname', listed 'viewname'
                if (!$isDeterminingType) $this->logger->debug("[isViewMatchingSource] Matched (Rule 2c): Base names match case-insensitively. Input schema is '{$inputSchema}', listed schema is null. Assumed match.");
                return true;
            }
        }

        if (!$isDeterminingType) $this->logger->debug("[isViewMatchingSource] No match for input '{$inputOriginalName}' (Full Quoted Input: '{$inputQuotedFullName}') with listed view '{$viewCanonicalName}' (Full Quoted Listed: '{$viewQuotedCanonicalName}').");
        return false;
    }

    private function getDbInfoForError(Connection $connection): string
    {
        try {
            $dbName = $connection->getDatabase();
            return $dbName ? " in database `{$dbName}`" : "";
        } catch (\Throwable $_) {
            return "";
        }
    }

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
