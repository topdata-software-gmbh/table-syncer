<?php

namespace TopdataSoftwareGmbh\TableSyncer\DTO;

/**
 * Class SyncReportDTO
 * Represents a report of the synchronization process.
 */
class SyncReportDTO
{
    public int $insertedCount = 0;
    public int $updatedCount = 0;
    public int $deletedCount = 0;
    public int $initialInsertCount = 0;
    private array $logMessages = [];

    /**
     * Constructor with parameters for the number of inserts, updates, and deletes.
     *
     * @param int $insertedCount
     * @param int $updatedCount
     * @param int $deletedCount
     * @param int $initialInsertCount
     */
    public function __construct(int $insertedCount = 0, int $updatedCount = 0, int $deletedCount = 0, int $initialInsertCount = 0)
    {
        $this->insertedCount = $insertedCount;
        $this->updatedCount = $updatedCount;
        $this->deletedCount = $deletedCount;
        $this->initialInsertCount = $initialInsertCount;
    }

    /**
     * Add a log message to the report.
     *
     * @param string $message The log message
     * @param string $level Optional log level (default: 'info')
     * @param array $context Optional context data
     */
    public function addLogMessage(string $message, string $level = 'info', array $context = []): void
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
            $this->insertedCount,
            $this->updatedCount,
            $this->deletedCount
        );
    }
}
