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

        try {
            $this->logger->info('Starting sync process.', [
                'source'            => $config->sourceTableName,
                'target'            => $config->targetLiveTableName,
                'currentRevisionId' => $currentRevisionId,
                'createViewConfig'  => $config->shouldCreateView,
            ]);

            // 0. Ensure source view exists if configured (DDL - typically implicit commit)
            // This now uses the dedicated GenericViewManager.
            if ($config->shouldCreateView) {
                $report->viewCreationAttempted = true;
                $this->viewManager->ensureSourceView($config); // Throws on failure
                $report->viewCreationSuccessful = true;
            }

            // 1. Ensure live table exists with correct schema
            $this->schemaManager->ensureLiveTable($config);

            // 1.1 Ensure deleted log table exists if deletion logging is enabled
            if ($config->enableDeletionLogging) {
                $this->logger->info('Deletion logging is enabled, ensuring deleted log table exists.');
                $this->schemaManager->ensureDeletedLogTable($config);
            }

            // 2. Prepare temp table (drop if exists, create new)
            $this->schemaManager->prepareTempTable($config);

            // 3. Load data from source to temp
            $this->sourceToTempLoader->load($config);

            // 4. Add hashes to temp table rows for change detection
            $this->dataHasher->addHashesToTempTable($config);

            // 5. Add indexes to temp table for faster sync
            $this->indexManager->addIndicesToTempTableAfterLoad($config);

            // 6. Add any missing indexes to live table
            $this->indexManager->addIndicesToLiveTable($config);

            // 7. Synchronize temp to live (insert/update/delete)
            $this->tempToLiveSynchronizer->synchronize($config, $currentRevisionId, $report);

            // 8. Drop temp table to clean up
            $this->schemaManager->dropTempTable($config);

            $this->logger->info('Sync completed successfully.', [
                'inserted'               => $report->insertedCount,
                'updated'                => $report->updatedCount,
                'deleted'                => $report->deletedCount,
                'initialInsert'          => $report->initialInsertCount,
                'loggedDeletions'        => $report->loggedDeletionsCount,
                'viewCreationAttempted'  => $report->viewCreationAttempted,
                'viewCreationSuccessful' => $report->viewCreationSuccessful,
            ]);
            return $report;

        } catch (\Throwable $e) {
            $this->logger->error('Sync process failed: ' . $e->getMessage(), [
                'exception_class' => get_class($e),
                'exception_trace' => $e->getTraceAsString(),
                'source'          => $config->sourceTableName,
                'target'          => $config->targetLiveTableName,
            ]);

            // Attempt to clean up temp table even on error
            try {
                $this->schemaManager->dropTempTable($config);
                $this->logger->info('Temp table dropped successfully during error handling.');
            } catch (\Throwable $cleanupException) {
                $this->logger->warning('Failed to drop temp table during error handling: ' . $cleanupException->getMessage(), [
                    'cleanup_exception_class' => get_class($cleanupException),
                    'cleanup_exception_trace' => $cleanupException->getTraceAsString(),
                ]);
            }

            // Re-throw as a TableSyncerException if it's not already one or ConfigurationException
            // GenericViewManager will throw TableSyncerException or ConfigurationException
            if (!($e instanceof TableSyncerException) && !($e instanceof \TopdataSoftwareGmbh\TableSyncer\Exception\ConfigurationException)) {
                throw new TableSyncerException('Sync process failed unexpectedly: ' . $e->getMessage(), (int)$e->getCode(), $e);
            }
            throw $e; // Re-throw original TableSyncerException/ConfigurationException or the wrapped one
        }
    }
}