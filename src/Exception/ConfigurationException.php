<?php

namespace TopdataSoftwareGmbh\TableSyncer\Exception;

/**
 * Exception for configuration issues
 */
class ConfigurationException extends TableSyncerException
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
