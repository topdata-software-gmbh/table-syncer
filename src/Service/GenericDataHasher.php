<?php

namespace TopdataSoftwareGmbh\TableSyncer\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TopdataSoftwareGmbh\TableSyncer\DTO\TableSyncConfigDTO;

class GenericDataHasher
{
    private readonly LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Adds hashes to the temporary table based on the configuration.
     *
     * @param TableSyncConfigDTO $config
     * @return int
     */
    public function addHashesToTempTable(TableSyncConfigDTO $config): int
    {
        $this->logger->debug('Adding hashes to temp table');

        // Get target columns for content hash
        $targetColumns = $config->getTargetColumnsForContentHash();
        if (empty($targetColumns)) {
            $this->logger->info('No target columns for content hash');
            return 0;
        }

        // Platform-aware CONCAT, SHA2, COALESCE for hashing
        $columnList = implode(', ', array_map(fn($col) => "COALESCE($col, '')", $targetColumns));
        $hashQuery = "UPDATE {$config->tempTableName} SET content_hash = SHA2(CONCAT($columnList), 256)";

        // Execute the query
        $result = $config->getConnection()->executeStatement($hashQuery);

        $this->logger->info('Hashes added to temp table', ['affected_rows' => $result]);

        return $result;
    }
}