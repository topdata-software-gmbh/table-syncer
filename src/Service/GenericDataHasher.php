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
     * Uses SHA-256 hashing on concatenated column values.
     *
     * @param TableSyncConfigDTO $config
     * @return int Number of rows where hashes were added
     */
    public function addHashesToTempTable(TableSyncConfigDTO $config): int
    {
        $this->logger->debug('Adding hashes to temp table', [
            'tempTable' => $config->targetTempTableName
        ]);

        $targetConn = $config->targetConnection;
        $tempTableName = $targetConn->quoteIdentifier($config->targetTempTableName);
        $contentHashColumn = $targetConn->quoteIdentifier($config->metadataColumns->contentHash);

        // Get target columns for content hash
        $hashSourceColumns = $config->getTargetColumnsForContentHash();
        if (empty($hashSourceColumns)) {
            $this->logger->warning('No columns specified for content hash calculation. Skipping hash generation.');
            return 0;
        }

        $this->logger->debug('Generating content hashes from columns', [
            'columns' => implode(', ', $hashSourceColumns)
        ]);

        // Platform-aware CONCAT, SHA2, COALESCE for hashing
        // Cast each column to CHAR and handle NULL values with COALESCE
        $concatParts = [];
        foreach ($hashSourceColumns as $column) {
            $quotedCol = $targetConn->quoteIdentifier($column);
            $concatParts[] = "COALESCE(CAST({$quotedCol} AS CHAR), '')";
        }

        $columnList = implode(', ', $concatParts);

        // Construct the final hash query with SHA2 (SHA-256)
        $hashQuery = "UPDATE {$tempTableName} SET {$contentHashColumn} = SHA2(CONCAT({$columnList}), 256)";

        $this->logger->debug('Executing hash update query', ['query' => $hashQuery]);

        // Execute the query and get number of affected rows
        $result = $targetConn->executeStatement($hashQuery);

        // Cast the result to an integer
        $affectedRows = (int)$result;

        $this->logger->info('Content hashes added to temp table', ['affected_rows' => number_format($affectedRows)]);

        return $affectedRows;
    }
}
