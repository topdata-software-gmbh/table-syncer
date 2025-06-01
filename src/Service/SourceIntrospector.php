<?php

namespace TopdataSoftwareGmbh\TableSyncer\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Exception\TableDoesNotExist;
// use Doctrine\DBAL\Exception\TableNotFoundException; // Can be removed if TableDoesNotExist is consistently used

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Identifier;
use Doctrine\DBAL\Schema\Table as DbalTable;
use Doctrine\DBAL\Types\Type;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TopdataSoftwareGmbh\TableSyncer\Exception\ConfigurationException;
use Doctrine\DBAL\Schema\Column as DbalColumn;
use TopdataSoftwareGmbh\TableSyncer\Enum\SourceObjectTypeEnum;
use Doctrine\DBAL\Schema\View as DbalView;

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
        $dbInfo = $this->getDbInfoForError($sourceConnection);

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
            $nameUsedForSuccessfulIntrospection = $tableDetails->getName();
            $this->logger->info(
                "[Introspector] Successfully introspected '{$nameUsedForSuccessfulIntrospection}' (original input: '{$sourceName}') on first attempt."
            );
        } catch (TableDoesNotExist $e) {
            $this->logger->warning(
                "[Introspector] Attempt 1 for '{$sourceName}' failed with TableDoesNotExist: " . $e->getMessage() .
                " Attempting to find in listViews() and introspect using its canonical name."
            );

            try {
                $views = $schemaManager->listViews();
                if (empty($views)) {
                    $this->logger->warning("[Introspector] listViews() returned an empty list. Cannot find '{$sourceName}' as a view. Re-throwing original TableDoesNotExist exception.");
                    throw $e;
                }

                $this->logger->debug("[Introspector] listViews() returned " . count($views) . " views. Searching for a match for '{$sourceName}'.");
                if (!($this->logger instanceof NullLogger)) {
                    $listedViewNamesForDebug = [];
                    foreach ($views as $v) {
                        $listedViewNamesForDebug[] = $v->getName() . " (quoted: " . $v->getQuotedName($platform) . ")";
                    }
                    if (!empty($listedViewNamesForDebug)) {
                        $this->logger->debug("[Introspector] Views from listViews(): " . implode(", ", $listedViewNamesForDebug));
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
                        $nameUsedForSuccessfulIntrospection = $tableDetails->getName();
                        $this->logger->info(
                            "[Introspector] Successfully introspected '{$nameUsedForSuccessfulIntrospection}' (original input: '{$sourceName}', via view list as '{$canonicalViewNameFromList}') on second attempt."
                        );
                    } catch (TableDoesNotExist $e2) {
                        $this->logger->error(
                            "[Introspector] Attempt 2 introspection for '{$canonicalViewNameFromList}' (for original '{$sourceName}') also failed with TableDoesNotExist: " . $e2->getMessage() .
                            " Re-throwing original TableDoesNotExist exception for '{$sourceName}'."
                        );
                        throw $e;
                    } catch (\Throwable $eUnexpected2) {
                        $this->logger->error(
                            "[Introspector] Unexpected error during Attempt 2 introspection of '{$canonicalViewNameFromList}' (for '{$sourceName}'): " . $eUnexpected2->getMessage()
                        );
                        throw new ConfigurationException("Error introspecting confirmed view '{$canonicalViewNameFromList}' (originally '{$sourceName}'{$dbInfo}): " . $eUnexpected2->getMessage(), 0, $eUnexpected2);
                    }
                } else {
                    $this->logger->warning(
                        "[Introspector] '{$sourceName}' not found directly (threw TableDoesNotExist), and no matching view found in listViews(). Re-throwing original TableDoesNotExist exception."
                    );
                    throw $e;
                }
            } catch (\Doctrine\DBAL\Exception $listViewsException) {
                $this->logger->error(
                    "[Introspector] Failed to list views while trying to recover for '{$sourceName}': " . $listViewsException->getMessage() .
                    " Re-throwing original TableDoesNotExist exception.",
                    ['original_exception_message' => $e->getMessage()]
                );
                throw $e;
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                "[Introspector] Unexpected error during introspection process for '{$sourceName}'{$dbInfo}: " . $e->getMessage(),
                ['exception_class' => get_class($e)]
            );
            throw new ConfigurationException("Error while trying to introspect source '{$sourceName}'{$dbInfo}: " . $e->getMessage(), 0, $e);
        }

        if ($tableDetails === null) {
            $this->logger->error("[Introspector] CRITICAL: tableDetails is null after introspection attempts for '{$sourceName}'{$dbInfo}. This indicates an issue in the introspector's control flow or exception handling.");
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
        string $introspectedName,
        AbstractPlatform $platform
    ): SourceObjectTypeEnum {
        if ($schemaManager->tablesExist([$introspectedName])) {
            return SourceObjectTypeEnum::TABLE;
        }
        try {
            $views = $schemaManager->listViews();
            $identifierForIntrospectedName = new Identifier($introspectedName);
            foreach ($views as $viewFromList) {
                if ($this->isViewMatchingSource($viewFromList, $identifierForIntrospectedName, $platform, true)) {
                    return SourceObjectTypeEnum::VIEW;
                }
            }
        } catch (\Doctrine\DBAL\Exception $e) {
            $this->logger->warning(
                "[Introspector::determineSourceObjectType] Could not list views to confirm type for successfully introspected '{$introspectedName}' (it's not a table). Error: " . $e->getMessage()
            );
        }
        return SourceObjectTypeEnum::INTROSPECTABLE_OBJECT_NOT_IN_LISTVIEWS;
    }

    private function isViewMatchingSource(
        DbalView $viewFromList,
        Identifier $inputIdentifier,
        AbstractPlatform $platform,
        bool $isDeterminingType = false
    ): bool {
        $viewCanonicalName = $viewFromList->getName();
        $viewQuotedCanonicalName = $viewFromList->getQuotedName($platform);

        $inputOriginalName = $inputIdentifier->getName();
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

        $unquotedInputBaseName = $inputIdentifier->getName();
        $unquotedListedBaseName = $listedViewIdentifier->getName();

        $inputSchema = $inputIdentifier->getNamespaceName();
        $listedSchema = $listedViewIdentifier->getNamespaceName();

        // Prepare schema strings for logging, handling nulls
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