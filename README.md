# Table Syncer

A generic PHP library for synchronizing table data between two databases using Doctrine DBAL, supporting column name mapping and a staging table approach.

## Features

- Database table synchronization between any two databases supported by Doctrine DBAL
- Column name mapping to handle differences in table schemas
- Staging table approach for efficient data synchronization
- PSR-3 logging support for monitoring and debugging
- Support for non-nullable datetime columns with placeholder values
- Customizable metadata column handling
- Robust error handling with custom exceptions

## Requirements

- PHP 8.0 or higher
- Doctrine DBAL 3.0 or higher
- PSR-3 compatible logger implementation

## Installation

```bash
composer require topdata-software-gmbh/table-syncer
```

## Usage

This library uses PSR-3 logging. You need to provide a logger implementation when using the library.

```php
use Psr\Log\LoggerInterface;
use TopdataSoftwareGmbh\TableSyncer\Service\GenericTableSyncer;
use TopdataSoftwareGmbh\TableSyncer\DTO\TableSyncConfigDTO;
use DateTime;

// Example logger implementation
class ConsoleLogger implements LoggerInterface {
    public function emergency($message, array $context = []) { /*...*/ }
    public function alert($message, array $context = []) { /*...*/ }
    public function critical($message, array $context = []) { /*...*/ }
    public function error($message, array $context = []) { /*...*/ }
    public function warning($message, array $context = []) { /*...*/ }
    public function notice($message, array $context = []) { /*...*/ }
    public function info($message, array $context = []) { /*...*/ }
    public function debug($message, array $context = []) { /*...*/ }
    public function log($level, $message, array $context = []) { /*...*/ }
}

// Create your logger implementation
$logger = new ConsoleLogger();

// Create your sync configuration
$config = new TableSyncConfigDTO([
    'sourceConnection' => $sourceConn,
    'targetConnection' => $targetConn,
    'sourceTable' => 'source_table',
    'targetTable' => 'target_table',
    'primaryKeyColumnMap' => ['id' => 'id'],
    'dataColumnMapping' => ['name' => 'full_name', 'email' => 'email_address'],
    'columnsForContentHash' => ['name', 'email'],
    'nonNullableDatetimeSourceColumns' => ['created_at', 'updated_at'],
    'placeholderDatetime' => new DateTime('1970-01-01'),
    'metadataColumns' => ['created_at', 'updated_at'],
    'targetColumnTypeOverrides' => [],
    'targetColumnLengthOverrides' => [],
]);

// Create the syncer
$syncer = new GenericTableSyncer($logger);

// Run the synchronization
$report = $syncer->sync($config);

// Access the synchronization report
foreach ($report->getLogMessages() as $message) {
    echo $message . PHP_EOL;
}
```

## Configuration

### TableSyncConfigDTO

The `TableSyncConfigDTO` class allows for comprehensive configuration of the synchronization process:

- `sourceConnection`: Doctrine DBAL connection for the source database
- `targetConnection`: Doctrine DBAL connection for the target database
- `sourceTable`: Name of the source table
- `targetTable`: Name of the target table
- `primaryKeyColumnMap`: Mapping of primary key columns between source and target
- `dataColumnMapping`: Mapping of data columns between source and target
- `columnsForContentHash`: Columns to include in the content hash for change detection
- `nonNullableDatetimeSourceColumns`: Datetime columns that cannot be NULL in the source
- `placeholderDatetime`: Datetime value to use for non-nullable datetime columns when source has NULL
- `metadataColumns`: Columns to include in metadata handling
- `targetColumnTypeOverrides`: Overrides for target column types
- `targetColumnLengthOverrides`: Overrides for target column lengths

### Customizing MetadataColumnNamesDTO

The `MetadataColumnNamesDTO` class allows customization of metadata column names:

```php
use TopdataSoftwareGmbh\TableSyncer\DTO\MetadataColumnNamesDTO;

$metadataColumns = new MetadataColumnNamesDTO();
$metadataColumns->id = 'custom_id_column';
$metadataColumns->contentHash = 'custom_hash_column';
```

## PSR-3 Logging

The library relies on a provided `LoggerInterface` implementation for all logging. You must implement and provide a PSR-3 compatible logger when creating the `GenericTableSyncer` instance. The library does not output logs directly to console or files.

## License

MIT