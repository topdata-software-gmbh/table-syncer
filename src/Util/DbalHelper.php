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
        $platform = $connection->getDatabasePlatform();

        return match (get_class($platform)) {
            \Doctrine\DBAL\Platforms\MariaDB1010Platform::class,
            \Doctrine\DBAL\Platforms\MySQLPlatform::class,
            \Doctrine\DBAL\Platforms\MariaDBPlatform::class    => 'mysql', // Treat MariaDB as MySQL
            \Doctrine\DBAL\Platforms\PostgreSQLPlatform::class => 'postgresql',
            \Doctrine\DBAL\Platforms\SQLitePlatform::class     => 'sqlite',
            \Doctrine\DBAL\Platforms\SQLServerPlatform::class  => 'sqlserver',
            \Doctrine\DBAL\Platforms\OraclePlatform::class     => 'oracle',
            default                                            => throw new \RuntimeException("Unknown DB platform: " . get_class($platform)),
        };
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
        return self::getDatabasePlatformName($connection) === $platformName;
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
