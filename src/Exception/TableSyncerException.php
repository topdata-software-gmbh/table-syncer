<?php

namespace TopdataSoftwareGmbh\TableSyncer\Exception;

/**
 * Base exception for TableSyncer
 */
class TableSyncerException extends \Exception
{
    /**
     * Constructor
     *
     * @param string $message
     * @param int $code
     */
    public function __construct(string $message = "", int $code = 0, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}