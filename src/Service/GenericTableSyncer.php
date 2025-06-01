<?php

namespace TopdataSoftwareGmbh\TableSyncer\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TopdataSoftwareGmbh\TableSyncer\DTO\SyncReportDTO;
use TopdataSoftwareGmbh\TableSyncer\DTO\TableSyncConfigDTO;
use TopdataSoftwareGmbh\TableSyncer\Exception\TableSyncerException;
use TopdataSoftwareGmbH\Util\UtilDebug;

class GenericTableSyncer
{
    private readonly GenericSchemaManager $schemaManager;
    private readonly GenericIndexManager $indexManager;
    private readonly GenericDataHasher $dataHasher;
    private readonly SourceToTempLoader $sourceToTempLoader;
    private readonly TempToLiveSynchronizer $tempToLiveSynchronizer;
    private readonly LoggerInterface $logger;

    public function __construct(
        // Option 1: Only logger, create everything else (simplest for user)
        LoggerInterface $logger,
        // Option 2: Allow full DI by making other services optional
        ?GenericSchemaManager $schemaManager = null,
        ?GenericIndexManager  $indexManager = null,
        ?GenericDataHasher    $dataHasher = null,
        ?SourceToTempLoader   $sourceToTempLoader = null,
        ?TempToLiveSynchronizer $tempToLiveSynchronizer = null
        // Note: No separate $logger parameter if it's always the first and required one
    ) {
        $this->logger = $logger; // Always use the provided logger

        // Instantiate dependencies if not provided
        $this->schemaManager = $schemaManager ?? new GenericSchemaManager($this->logger);
        $this->indexManager = $indexManager ?? new GenericIndexManager($this->logger);
        $this->dataHasher = $dataHasher ?? new GenericDataHasher($this->logger);
        // SourceToTempLoader needs the schemaManager we just ensured exists
        $this->sourceToTempLoader = $sourceToTempLoader ?? new SourceToTempLoader($this->logger, $this->schemaManager);
        $this->tempToLiveSynchronizer = $tempToLiveSynchronizer ?? new TempToLiveSynchronizer($this->logger);
    }

    /**
     * Synchronizes the data between source and target tables.
     *
     * @param TableSyncConfigDTO $config
     * @param int $currentBatchRevisionId
     * @return SyncReportDTO
     * @throws TableSyncerException
     */
    public function sync(TableSyncConfigDTO $config, int $currentBatchRevisionId): SyncReportDTO
    {
        $targetConn = $config->targetConnection;
        // Transaction management for DML operations is now handled by TempToLiveSynchronizer
        // This method now acts as an orchestrator for the overall sync process
        try {
            $report = new SyncReportDTO();
            $this->logger->info('Starting sync process', [
                'source'        => $config->sourceTableName,
                'target'        => $config->targetLiveTableName,
                'batchRevision' => $currentBatchRevisionId
            ]);

            // 1. Ensure live table exists with correct schema
            $this->schemaManager->ensureLiveTable($config);
            
            // 1.1 Ensure deleted log table exists if deletion logging is enabled
            if ($config->enableDeletionLogging) {
                $this->logger->info('Deletion logging is enabled, ensuring deleted log table exists');
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
            $this->tempToLiveSynchronizer->synchronize($config, $currentBatchRevisionId, $report);

            // 8. Drop temp table to clean up
            $this->schemaManager->dropTempTable($config);
            $this->logger->info('Sync completed successfully', [
                'inserted'      => $report->insertedCount,
                'updated'       => $report->updatedCount,
                'deleted'       => $report->deletedCount,
                'initialInsert' => $report->initialInsertCount
            ]);
            return $report;
        } catch (\Throwable $e) {
            // Transaction management for DML operations is now handled by TempToLiveSynchronizer
            // This catch block now only needs to handle logging and cleanup

            $this->logger->error('Sync failed: ' . $e->getMessage(), [
                'exception' => $e,
                'source'    => $config->sourceTableName,
                'target'    => $config->targetLiveTableName
            ]);
            
            // Attempt to clean up temp table even on error
            try {
                $this->schemaManager->dropTempTable($config);
                $this->logger->debug('Temp table dropped during error handling');
            } catch (\Throwable $cleanupException) {
                $this->logger->warning('Failed to drop temp table during error handling: ' . $cleanupException->getMessage());
            }
            
            throw new TableSyncerException('Sync failed: ' . $e->getMessage(), 0, $e);
        }
    }
}