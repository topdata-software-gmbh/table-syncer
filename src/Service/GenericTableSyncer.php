<?php

namespace TopdataSoftwareGmbh\TableSyncer\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TopdataSoftwareGmbh\TableSyncer\DTO\SyncReportDTO;
use TopdataSoftwareGmbh\TableSyncer\DTO\TableSyncConfigDTO;
use TopdataSoftwareGmbh\TableSyncer\Exception\TableSyncerException;

class GenericTableSyncer
{
    private readonly GenericSchemaManager $schemaManager;
    private readonly GenericIndexManager $indexManager;
    private readonly GenericDataHasher $dataHasher;
    private readonly SourceToTempLoader $sourceToTempLoader;
    private readonly TempToLiveSynchronizer $tempToLiveSynchronizer;
    private readonly LoggerInterface $logger;

    public function __construct(
        GenericSchemaManager $schemaManager,
        GenericIndexManager  $indexManager,
        GenericDataHasher    $dataHasher,
        ?SourceToTempLoader  $sourceToTempLoader = null,
        ?TempToLiveSynchronizer $tempToLiveSynchronizer = null,
        ?LoggerInterface     $logger = null
    )
    {
        $this->schemaManager = $schemaManager;
        $this->indexManager = $indexManager;
        $this->dataHasher = $dataHasher;
        $this->logger = $logger ?? new NullLogger();
        $this->sourceToTempLoader = $sourceToTempLoader ?? new SourceToTempLoader($schemaManager, $this->logger);
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
        // It's generally better to start the transaction right before DML operations,
        // as DDL operations might implicitly commit. However, for simplicity and if the DB supports DDL in transactions (or handles implicit commits gracefully),
        // starting it here covers the whole sync. The improved catch block handles scenarios where it might have been committed.
        if (!$targetConn->isTransactionActive()) { // Start transaction only if not already in one (e.g. by caller)
            $targetConn->beginTransaction();
        }
        try {
            $report = new SyncReportDTO();
            $this->logger->info('Starting sync process', [
                'source'        => $config->sourceTableName,
                'target'        => $config->targetLiveTableName,
                'batchRevision' => $currentBatchRevisionId
            ]);

            // 1. Ensure live table exists with correct schema
            $this->schemaManager->ensureLiveTable($config);

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

            if ($targetConn->isTransactionActive()) { // Only commit if we started it and it's still active
                $targetConn->commit();
            }
            $this->logger->info('Sync completed successfully', [
                'inserted'      => $report->insertedCount,
                'updated'       => $report->updatedCount,
                'deleted'       => $report->deletedCount,
                'initialInsert' => $report->initialInsertCount
            ]);
            return $report;
        } catch (\Throwable $e) {
            if ($targetConn->isTransactionActive()) {
                try {
                    $targetConn->rollBack();
                    $this->logger->warning('Transaction rolled back due to an error during sync.', ['exception_message' => $e->getMessage()]);
                } catch (\Throwable $rollbackException) {
                    $this->logger->error('Failed to roll back transaction: ' . $rollbackException->getMessage(), [
                        'original_exception_message' => $e->getMessage(),
                        'rollback_exception'         => $rollbackException
                    ]);
                }
            } else {
                $this->logger->info('No active transaction to roll back when error occurred. The error might have happened after an implicit commit caused by DDL or if transaction was managed externally.', ['exception_message' => $e->getMessage()]);
            }

            $this->logger->error('Sync failed: ' . $e->getMessage(), [
                'exception' => $e,
                'source'    => $config->sourceTableName,
                'target'    => $config->targetLiveTableName
            ]);
            throw new TableSyncerException('Sync failed: ' . $e->getMessage(), 0, $e);
        }
    }
}