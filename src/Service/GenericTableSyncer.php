<?php

namespace TopdataSoftwareGmbh\TableSyncer\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TopdataSoftwareGmbh\TableSyncer\DTO\SyncReportDTO;
use TopdataSoftwareGmbh\TableSyncer\DTO\TableSyncConfigDTO;
use TopdataSoftwareGmbh\TableSyncer\Exception\TableSyncerException;
use TopdataSoftwareGmbh\TableSyncer\Exception\ConfigurationException;
use TopdataSoftwareGmbH\Util\UtilDebug;
use Doctrine\DBAL\Exception as DBALException;

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
    /**
     * Creates or replaces the source view if view creation is enabled in the configuration.
     *
     * @param TableSyncConfigDTO $config
     * @return void
     * @throws ConfigurationException If view creation is enabled but view definition is missing or invalid
     * @throws TableSyncerException If view creation fails
     */
    protected function createSourceView(TableSyncConfigDTO $config): void
    {
        if (!$config->shouldCreateView) {
            return; // View creation is not enabled
        }

        $this->logger->info('Starting source view creation', [
            'source' => $config->sourceTableName,
            'isView' => true
        ]);

        try {
            $sourceConn = $config->sourceConnection;
            $schemaManager = $sourceConn->createSchemaManager();
            $viewName = $config->sourceTableName;

            // Execute any view dependencies first
            $this->executeViewDependencies($config, $sourceConn);

            // Check if view already exists
            $viewExists = in_array($viewName, $schemaManager->listTableNames(), true);
            $dropViewSql = $sourceConn->getDatabasePlatform()->getDropViewSQL($viewName);
            $createViewSql = $config->viewDefinition;

            // Start transaction for view creation
            $sourceConn->beginTransaction();
            try {
                // Drop existing view if it exists
                if ($viewExists) {
                    $this->logger->debug('Dropping existing view', ['view' => $viewName]);
                    $sourceConn->executeStatement($dropViewSql);
                }

                // Create the view
                $this->logger->info('Creating view', [
                    'view' => $viewName,
                    'sql' => $createViewSql
                ]);
                $sourceConn->executeStatement($createViewSql);

                $sourceConn->commit();
                $this->logger->info('View created successfully', ['view' => $viewName]);
            } catch (\Exception $e) {
                $sourceConn->rollBack();
                throw $e;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create source view', [
                'view' => $config->sourceTableName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new TableSyncerException(
                "Failed to create source view '{$config->sourceTableName}': " . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * Executes SQL statements that the view depends on.
     *
     * @param TableSyncConfigDTO $config
     * @param \Doctrine\DBAL\Connection $connection
     * @return void
     * @throws TableSyncerException If dependency execution fails
     */
    protected function executeViewDependencies(TableSyncConfigDTO $config, \Doctrine\DBAL\Connection $connection): void
    {
        if (empty($config->viewDependencies)) {
            return;
        }

        $this->logger->debug('Executing view dependencies', [
            'count' => count($config->viewDependencies)
        ]);

        foreach ($config->viewDependencies as $index => $sql) {
            try {
                $this->logger->debug('Executing dependency SQL', [
                    'index' => $index,
                    'sql' => $sql
                ]);
                $connection->executeStatement($sql);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to execute view dependency', [
                    'index' => $index,
                    'sql' => $sql,
                    'error' => $e->getMessage()
                ]);
                throw new TableSyncerException(
                    "Failed to execute view dependency #{$index}: " . $e->getMessage(),
                    (int)$e->getCode(),
                    $e
                );
            }
        }
    }

    public function sync(TableSyncConfigDTO $config, int $currentBatchRevisionId): SyncReportDTO
    {
        $targetConn = $config->targetConnection;
        
        // Handle view creation if configured
        try {
            $this->createSourceView($config);
        } catch (\Throwable $e) {
            // If view creation fails and it's required, rethrow the exception
            if ($config->shouldCreateView) {
                throw $e;
            }
            // If view creation fails but it's not required, log and continue
            $this->logger->warning('Optional view creation failed, but continuing with sync', [
                'source' => $config->sourceTableName,
                'error' => $e->getMessage()
            ]);
        }
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