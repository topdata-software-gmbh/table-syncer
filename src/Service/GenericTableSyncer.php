<?php

namespace TopdataSoftwareGmbh\TableSyncer\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TopdataSoftwareGmbh\TableSyncer\DTO\SyncReportDTO;
use TopdataSoftwareGmbh\TableSyncer\DTO\TableSyncConfigDTO;
use TopdataSoftwareGmbh\TableSyncer\Exception\TableSyncerException;
use TopdataSoftwareGmbh\TableSyncer\Exception\ConfigurationException;

// use TopdataSoftwareGmbH\Util\UtilDebug; // Assuming this is not used in the current context
use Doctrine\DBAL\Exception as DBALException;

/**
 * 05/2025 created
 */
class GenericTableSyncer
{
    private readonly GenericSchemaManager $schemaManager;
    private readonly GenericIndexManager $indexManager;
    private readonly GenericDataHasher $dataHasher;
    private readonly SourceToTempLoader $sourceToTempLoader;
    private readonly TempToLiveSynchronizer $tempToLiveSynchronizer;
    private readonly LoggerInterface $logger;

    public function __construct(
        ?LoggerInterface        $logger = null,
        ?GenericSchemaManager   $schemaManager = null,
        ?GenericIndexManager    $indexManager = null,
        ?GenericDataHasher      $dataHasher = null,
        ?SourceToTempLoader     $sourceToTempLoader = null,
        ?TempToLiveSynchronizer $tempToLiveSynchronizer = null
    )
    {
        $this->logger = $logger;

        $this->schemaManager = $schemaManager ?? new GenericSchemaManager($this->logger);
        $this->indexManager = $indexManager ?? new GenericIndexManager($this->logger);
        $this->dataHasher = $dataHasher ?? new GenericDataHasher($this->logger);
        $this->sourceToTempLoader = $sourceToTempLoader ?? new SourceToTempLoader($this->logger, $this->schemaManager);
        $this->tempToLiveSynchronizer = $tempToLiveSynchronizer ?? new TempToLiveSynchronizer($this->logger);
    }

    /**
     * Creates or replaces the source view if view creation is enabled in the configuration.
     * DDL statements in MySQL often cause implicit commits, so explicit transaction
     * wrapping for DDLs using DBAL can be problematic. This method executes DDLs
     * assuming the database handles their atomicity or implicit commits.
     *
     * @param TableSyncConfigDTO $config
     * @return void
     * @throws ConfigurationException If view definition is missing
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

        $sourceConn = $config->sourceConnection;
        $viewName = $config->sourceTableName; // Name of the view to be created/replaced

        try {
            // Execute any view dependencies first.
            // These are executed directly; if they are DDL, they will also be subject to
            // the database's implicit commit behavior. If they are DML and require
            // transactional integrity among themselves, that would need separate handling
            // or be part of the SQL definition.
            $this->executeViewDependencies($config, $sourceConn);

            $createViewSql = $config->viewDefinition;

            if (empty(trim((string)$createViewSql))) {
                // This should ideally be caught by TableSyncConfigDTO constructor,
                // but as a safeguard here.
                throw new ConfigurationException("View definition is empty for '{$viewName}'. Cannot create view.");
            }

            // The user's DDL is expected to be `CREATE OR REPLACE VIEW...` which handles existence.
            // If it were just `CREATE VIEW...`, one might need to explicitly drop the view first:
            //
            // $schemaManager = $sourceConn->createSchemaManager();
            // $viewExists = in_array($viewName, $schemaManager->listViews()); // Or listTableNames() if views appear there
            // if ($viewExists) {
            //     $this->logger->debug('Dropping existing view before CREATE', ['view' => $viewName]);
            //     $dropViewSql = $sourceConn->getDatabasePlatform()->getDropViewSQL($viewName);
            //     $sourceConn->executeStatement($dropViewSql); // DDL - implicit commit
            // }
            //
            // However, since `CREATE OR REPLACE VIEW` is used, explicit drop is not necessary.

            $this->logger->info('Executing CREATE OR REPLACE VIEW statement', [
                'view' => $viewName,
                'sql'  => $createViewSql
            ]);

            $sourceConn->executeStatement($createViewSql); // Execute the DDL

            $this->logger->info(
                'View DDL statement executed successfully. MySQL typically handles this with implicit commits.',
                ['view' => $viewName]
            );

        } catch (\Throwable $e) {
            $this->logger->error('Failed to create/replace source view', [
                'view'  => $viewName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString() // Full trace for debugging
            ]);
            // Re-throw as a TableSyncerException for consistent error handling upstream
            throw new TableSyncerException(
                "Failed to create or replace source view '{$viewName}': " . $e->getMessage(),
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
                    'sql'   => $sql
                ]);
                $connection->executeStatement($sql); // If SQL is DDL, it will implicitly commit on MySQL
            } catch (\Throwable $e) {
                $this->logger->error('Failed to execute view dependency', [
                    'index' => $index,
                    'sql'   => $sql,
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

    /**
     * ==== MAIN ====
     *
     * 05/2025 created
     */
    public function sync(TableSyncConfigDTO $config, int $currentBatchRevisionId): SyncReportDTO
    {
        // Handle view creation if configured
        try {
            $this->createSourceView($config);
        } catch (\Throwable $e) {
            // If view creation fails and it's required, rethrow the exception
            // The createSourceView method already logs the detailed error.
            if ($config->shouldCreateView) {
                // The original exception $e already contains detailed info.
                // createSourceView wraps it in TableSyncerException.
                throw $e;
            }
            // If view creation fails but it's not strictly required by config (shouldCreateView=false, though createSourceView checks this),
            // or if we want to be lenient (which is not the case if shouldCreateView=true).
            // This path is less likely if shouldCreateView is true, as createSourceView would throw.
            $this->logger->warning('Optional view creation failed (or was not enabled), but continuing with sync if possible.', [
                'source' => $config->sourceTableName,
                'error'  => $e->getMessage()
            ]);
        }

        // Main synchronization logic (DML operations)
        // Transaction management for these DML operations is handled by TempToLiveSynchronizer
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
                'inserted'        => $report->insertedCount,
                'updated'         => $report->updatedCount,
                'deleted'         => $report->deletedCount,
                'initialInsert'   => $report->initialInsertCount,
                'loggedDeletions' => $report->loggedDeletionsCount,
            ]);
            return $report;
        } catch (\Throwable $e) {
            $this->logger->error('Sync failed: ' . $e->getMessage(), [
                'exception_class' => get_class($e),
                'exception_trace' => $e->getTraceAsString(), // Log full trace for sync errors
                'source'          => $config->sourceTableName,
                'target'          => $config->targetLiveTableName
            ]);

            // Attempt to clean up temp table even on error
            try {
                $this->schemaManager->dropTempTable($config);
                $this->logger->debug('Temp table dropped during error handling');
            } catch (\Throwable $cleanupException) {
                $this->logger->warning('Failed to drop temp table during error handling: ' . $cleanupException->getMessage());
            }

            // Re-throw as a TableSyncerException if it's not already one
            if (!($e instanceof TableSyncerException)) {
                throw new TableSyncerException('Sync failed: ' . $e->getMessage(), 0, $e);
            }
            throw $e;
        }
    }
}