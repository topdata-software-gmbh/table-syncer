<?php

namespace TopdataSoftwareGmbh\TableSyncer\DTO;

/**
 * Class SyncReportDTO
 * Represents a report of the synchronization process.
 */
class SyncReportDTO
{
    public int $numInserts;
    public int $numUpdates;
    public int $numDeletes;
    private array $logMessages = [];

    /**
     * Constructor with parameters for the number of inserts, updates, and deletes.
     *
     * @param int $numInserts
     * @param int $numUpdates
     * @param int $numDeletes
     */
    public function __construct(int $numInserts = 0, int $numUpdates = 0, int $numDeletes = 0)
    {
        $this->numInserts = $numInserts;
        $this->numUpdates = $numUpdates;
        $this->numDeletes = $numDeletes;
    }

    /**
     * Add a log message to the report.
     *
     * @param string $level
     * @param string $message
     * @param array $context
     */
    public function addLogMessage(string $level, string $message, array $context = []): void
    {
        $this->logMessages[] = [
            'level' => $level,
            'message' => $message,
            'context' => $context
        ];
    }

    /**
     * Get all log messages.
     *
     * @return array
     */
    public function getLogMessages(): array
    {
        return $this->logMessages;
    }

    /**
     * Get a summary of the synchronization process.
     *
     * @return string
     */
    public function getSummary(): string
    {
        return sprintf(
            "Inserts: %d, Updates: %d, Deletes: %d",
            $this->numInserts,
            $this->numUpdates,
            $this->numDeletes
        );
    }
}
