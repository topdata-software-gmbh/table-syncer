<?php

namespace TopdataSoftwareGmbh\TableSyncer\Util;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;

/**
 * Static helper class for DBAL operations
 */
class DbalHelper
{
    /**
     * Get the name of the database platform
     *
     * @param Connection $connection
     * @return string
     */
    public static function getDatabasePlatformName(Connection $connection): string
    {
        return $connection->getDatabasePlatform()->getName();
    }

    /**
     * Check if the database platform is a specific type
     *
     * @param Connection $connection
     * @param string $platformName
     * @return bool
     */
    public static function isPlatform(Connection $connection, string $platformName): bool
    {
        return $connection->getDatabasePlatform()->getName() === $platformName;
    }

    /**
     * Get the SQL for quoting an identifier
     *
     * @param Connection $connection
     * @param string $identifier
     * @return string
     */
    public static function quoteIdentifier(Connection $connection, string $identifier): string
    {
        return $connection->getDatabasePlatform()->quoteIdentifier($identifier);
    }
}