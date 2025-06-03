<?php

namespace TopdataSoftwareGmbh\TableSyncer\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TopdataSoftwareGmbh\TableSyncer\DTO\SyncReportDTO;
use TopdataSoftwareGmbh\TableSyncer\DTO\TableSyncConfigDTO;
use TopdataSoftwareGmbh\TableSyncer\Exception\TableSyncerException;

// ConfigurationException is not directly thrown by GenericTableSyncer anymore for views,
// but it's good to keep if other parts might throw it or for general awareness.
// use TopdataSoftwareGmbh\TableSyncer\Exception\ConfigurationException;

/**
 * 05/2025 created
 * 06/2025 Refactored view creation to GenericViewManager
 */
class GenericTableSyncer
{
    private readonly GenericSchemaManager $schemaManager;
    private readonly GenericIndexManager $indexManager;
    private readonly GenericDataHasher $dataHasher;
    private readonly SourceToTempLoader $sourceToTempLoader;
    private readonly TempToLiveSynchronizer $tempToLiveSynchronizer;
    private readonly GenericViewManager $viewManager; // <-- New
    private readonly LoggerInterface $logger;

    public function __construct(
        ?LoggerInterface        $logger = null,
        ?GenericSchemaManager   $schemaManager = null,
        ?GenericIndexManager    $indexManager = null,
        ?GenericDataHasher      $dataHasher = null,
        ?SourceToTempLoader     $sourceToTempLoader = null,
        ?TempToLiveSynchronizer $tempToLiveSynchronizer = null,
        ?GenericViewManager     $viewManager = null // <-- New
    )
    {
        $this->logger = $logger ?? new NullLogger();

        $this->schemaManager = $schemaManager ?? new GenericSchemaManager($this->logger);
        $this->indexManager = $indexManager ?? new GenericIndexManager($this->logger);
        $this->dataHasher = $dataHasher ?? new GenericDataHasher($this->logger);
        $this->sourceToTempLoader = $sourceToTempLoader ?? new SourceToTempLoader($this->logger, $this->schemaManager);
        $this->tempToLiveSynchronizer = $tempToLiveSynchronizer ?? new TempToLiveSynchronizer($this->logger);
        $this->viewManager = $viewManager ?? new GenericViewManager($this->logger);
    }

    // createSourceView method removed
    // executeViewDependencies method removed

    /**
     * ==== MAIN ====
     *
     * Orchestrates the entire table synchronization process.
     * 05/2025 created
     * 05/2025 refactored view creation handling and reporting
     * 06/2025 delegated view creation to GenericViewManager
     */
    public function sync(TableSyncConfigDTO $config, int $currentRevisionId): SyncReportDTO
    {
        $report = new SyncReportDTO();
        $liveTableWasInitiallyEmpty = false; // Flag to track initial state

        try {
            $this->logger->info('Starting sync process.', [
                'source'            => $config->sourceTableName,
                'target'            => $config->targetLiveTableName,
                'currentRevisionId' => $currentRevisionId,
                'createViewConfig'  => $config->shouldCreateView,
            ]);

            // 0. Ensure source view exists if configured
            if ($config->shouldCreateView) {
                $report->viewCreationAttempted = true;
                $this->viewManager->ensureSourceView($config);
                $report->viewCreationSuccessful = true;
            }

            // 1. Ensure live table exists with correct schema (PK will be created here)
            $this->schemaManager->ensureLiveTable($config);

            // 1.1. Determine if the live table is empty BEFORE any potential secondary index creation
            //      or major data operations.
            try {
                $quotedLiveTableName = $config->targetConnection->quoteIdentifier($config->targetLiveTableName);
                $countResult = $config->targetConnection->fetchOne("SELECT COUNT(*) FROM " . $quotedLiveTableName);
                if (is_numeric($countResult) && (int)$countResult === 0) {
                    $liveTableWasInitiallyEmpty = true;
                    $this->logger->info("Live table '{$config->targetLiveTableName}' is confirmed to be empty before sync operations. Will defer secondary index creation.");
                }
            } catch (\Throwable $e) {
                $this->logger->warning(
                    "Could not definitively determine if live table '{$config->targetLiveTableName}' is empty. Proceeding with standard index creation if necessary. Error: " . $e->getMessage(),
                    ['exception_class' => get_class($e)]
                );
                // If we can't determine, assume it's not empty to be safe and follow old path for indexes.
                // Or, one could choose to throw an exception if this check is critical.
                // For this optimization, proceeding as if not empty is safer.
            }

            // 1.2. Ensure deleted log table exists if deletion logging is enabled
            if ($config->enableDeletionLogging) {
                $this->logger->info('Deletion logging is enabled, ensuring deleted log table exists.');
                $this->schemaManager->ensureDeletedLogTable($config);
            }

            // 1.3. <<<< MODIFIED LOGIC FOR LIVE TABLE INDEXES >>>>
            // If the live table was NOT initially empty, it's an incremental sync.
            // Create/ensure secondary indexes on the live table now, before temp table operations and synchronization.
            if (!$liveTableWasInitiallyEmpty) {
                $this->logger->info("Live table '{$config->targetLiveTableName}' is not empty or emptiness could not be confirmed. Ensuring secondary indexes before synchronization.");
                $this->indexManager->addIndicesToLiveTable($config);
            }

            // 2. Prepare temp table (drop if exists, create new)
            $this->schemaManager->prepareTempTable($config);

            // 3. Load data from source to temp
            $this->sourceToTempLoader->load($config);

            // 4. Add hashes to temp table rows for change detection
            $this->dataHasher->addHashesToTempTable($config);

            // 5. Add indexes to temp table for faster sync
            $this->indexManager->addIndicesToTempTableAfterLoad($config);

            // <<<< STEP 6 (Original $this->indexManager->addIndicesToLiveTable($config);) IS NOW HANDLED CONDITIONALLY ABOVE AND BELOW >>>>

            // 7. Synchronize temp to live (insert/update/delete)
            $this->tempToLiveSynchronizer->synchronize($config, $currentRevisionId, $report);

            // 8. <<<< NEW LOGIC FOR POST-INITIAL-LOAD INDEXING >>>>
            // If the live table was initially empty AND the synchronization resulted in an initial insert,
            // then create the secondary indexes on the live table NOW.
            if ($liveTableWasInitiallyEmpty && $report->initialInsertCount > 0) {
                $this->logger->info(
                    "Initial load completed with {$report->initialInsertCount} rows. Creating/ensuring secondary indexes on live table '{$config->targetLiveTableName}' post-load."
                );
                try {
                    $this->indexManager->addIndicesToLiveTable($config);
                    $this->logger->info("Secondary indexes successfully created/ensured on live table '{$config->targetLiveTableName}' post-initial-load.");
                } catch (\Throwable $e) {
                    // Log the error, but the sync itself was successful.
                    // This is a critical step for performance on subsequent runs, so a warning or error is appropriate.
                    $this->logger->error(
                        "Failed to create/ensure secondary indexes on live table '{$config->targetLiveTableName}' post-initial-load. This may impact future sync performance. Error: " . $e->getMessage(),
                        [
                            'exception_class' => get_class($e),
                            'exception_trace' => $e->getTraceAsString(),
                        ]
                    );
                    // Decide if this should be a critical failure of the sync or just a warning.
                    // For now, let's log as error but not re-throw to fail the whole sync, as data is in.
                    // You might want to add to the report.
                    $report->addLogMessage("Error: Failed to create secondary indexes on live table post-initial-load: " . $e->getMessage(), 'error');
                }
            } elseif ($liveTableWasInitiallyEmpty && $report->initialInsertCount === 0) {
                // This case implies the live table was empty, and after the full source-to-temp-to-live,
                // still no rows were inserted (e.g., source was also empty).
                // We should still ensure indexes are present for future non-empty runs.
                 $this->logger->info(
                    "Live table was initially empty and remained empty after sync. Ensuring secondary indexes on live table '{$config->targetLiveTableName}'."
                );
                $this->indexManager->addIndicesToLiveTable($config); // Create them anyway
            }

            // 9. Drop temp table to clean up (was step 8)
            $this->schemaManager->dropTempTable($config);

            $this->logger->info('Sync completed.', [ // Adjusted message slightly
                'inserted'               => $report->insertedCount,
                'updated'                => $report->updatedCount,
                'deleted'                => $report->deletedCount,
                'initialInsert'          => $report->initialInsertCount,
                'loggedDeletions'        => $report->loggedDeletionsCount,
                'viewCreationAttempted'  => $report->viewCreationAttempted,
                'viewCreationSuccessful' => $report->viewCreationSuccessful,
                'summary'                => $report->getSummary(),
            ]);
            return $report;

        } catch (\Throwable $e) {
            // ... (existing error handling and temp table cleanup) ...
            // No changes needed in the catch block for this specific feature
            $this->logger->error('Sync process failed: ' . $e->getMessage(), [
                'exception_class' => get_class($e),
                'exception_trace' => $e->getTraceAsString(), // Consider logging only in debug mode for production
                'source'          => $config->sourceTableName,
                'target'          => $config->targetLiveTableName,
            ]);

            try {
                $this->schemaManager->dropTempTable($config);
                $this->logger->info('Temp table dropped successfully during error handling.');
            } catch (\Throwable $cleanupException) {
                $this->logger->warning('Failed to drop temp table during error handling: ' . $cleanupException->getMessage(), [
                    'cleanup_exception_class' => get_class($cleanupException),
                ]);
            }

            if (!($e instanceof TableSyncerException) && !($e instanceof \TopdataSoftwareGmbh\TableSyncer\Exception\ConfigurationException)) {
                throw new TableSyncerException('Sync process failed unexpectedly: ' . $e->getMessage(), (int)$e->getCode(), $e);
            }
            throw $e;
        }
    }
}