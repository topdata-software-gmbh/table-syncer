<?php

namespace TopdataSoftwareGmbh\TableSyncer\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TopdataSoftwareGmbh\TableSyncer\DTO\TableSyncConfigDTO;
use TopdataSoftwareGmbh\TableSyncer\Exception\ConfigurationException;
use TopdataSoftwareGmbh\TableSyncer\Exception\TableSyncerException;

/**
 * Manages the creation and replacement of source views.
 *
 * 06/2025 created
 */
class GenericViewManager
{
    private readonly LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Ensures the source view is created or replaced if configured.
     * This includes executing any view dependencies first.
     * DDL statements in MySQL often cause implicit commits.
     *
     * @param TableSyncConfigDTO $config
     * @return void
     * @throws ConfigurationException If view definition is missing or invalid
     * @throws TableSyncerException If view creation or dependency execution fails
     */
    public function ensureSourceView(TableSyncConfigDTO $config): void
    {
        if (!$config->shouldCreateView) {
            $this->logger->info('View creation/replacement skipped as per configuration (shouldCreateView=false).', [
                'source_table_name_configured_as_view' => $config->sourceTableName,
            ]);
            return;
        }

        $this->logger->info('Starting source view creation/replacement process.', [
            'source_view_name' => $config->sourceTableName,
        ]);

        $sourceConn = $config->sourceConnection;
        $viewName = $config->sourceTableName; // Name of the view to be created/replaced

        try {
            // 1. Execute any view dependencies first.
            $this->executeViewDependencies($config, $sourceConn);

            // 2. Create/Replace the main view.
            $createViewSql = $config->viewDefinition;
            if (empty(trim((string)$createViewSql))) {
                // This should ideally be caught by TableSyncConfigDTO constructor,
                // but as a safeguard here.
                throw new ConfigurationException("View definition is empty for '{$viewName}'. Cannot create view.");
            }

            $this->logger->info('Executing CREATE OR REPLACE VIEW statement for main view.', [
                'view'        => $viewName,
                'sql_preview' => substr($createViewSql, 0, 200) . (strlen($createViewSql) > 200 ? '...' : ''),
            ]);

            $sourceConn->executeStatement($createViewSql); // Execute the DDL

            $this->logger->info(
                'Source view created/replaced successfully.',
                ['view' => $viewName]
            );

        } catch (\Throwable $e) {
            // Catch ConfigurationException specifically if it was thrown by this class's checks
            if ($e instanceof ConfigurationException) {
                $this->logger->error('Configuration error during view creation process.', [
                    'view'  => $viewName,
                    'error' => $e->getMessage(),
                ]);
                throw $e; // Re-throw as is
            }

            // For other throwables, wrap in TableSyncerException
            $this->logger->error('Failed to create/replace source view or its dependencies.', [
                'view'  => $viewName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString() // Full trace for debugging
            ]);
            throw new TableSyncerException(
                "Failed to create or replace source view '{$viewName}' or its dependencies: " . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * Executes SQL statements that the view depends on.
     *
     * @param TableSyncConfigDTO $config
     * @param Connection $connection
     * @return void
     * @throws TableSyncerException If dependency execution fails
     */
    protected function executeViewDependencies(TableSyncConfigDTO $config, Connection $connection): void
    {
        if (empty($config->viewDependencies)) {
            $this->logger->debug('No view dependencies to execute.');
            return;
        }

        $this->logger->debug('Executing view dependencies.', [
            'count' => count($config->viewDependencies),
            'view_name' => $config->sourceTableName,
        ]);

        foreach ($config->viewDependencies as $index => $sql) {
            try {
                $this->logger->debug('Executing dependency SQL.', [
                    'index'       => $index,
                    'sql_preview' => substr($sql, 0, 200) . (strlen($sql) > 200 ? '...' : ''),
                ]);
                $connection->executeStatement($sql); // If SQL is DDL, it will implicitly commit on MySQL
            } catch (\Throwable $e) {
                $this->logger->error('Failed to execute view dependency.', [
                    'index'       => $index,
                    'sql_preview' => substr($sql, 0, 200) . (strlen($sql) > 200 ? '...' : ''),
                    'error'       => $e->getMessage(),
                    'trace'       => $e->getTraceAsString(),
                ]);
                // Wrap in TableSyncerException for consistent error type from this manager
                throw new TableSyncerException(
                    "Failed to execute view dependency #{$index} for view '{$config->sourceTableName}': " . $e->getMessage(),
                    (int)$e->getCode(),
                    $e
                );
            }
        }
        $this->logger->debug('All view dependencies executed successfully.');
    }
}