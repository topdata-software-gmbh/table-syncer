# Table Syncer

A generic PHP library for synchronizing table data between two databases using Doctrine DBAL, supporting column name mapping and a staging table approach.

## Features

- Database table synchronization between any two databases supported by Doctrine DBAL
- Column name mapping to handle differences in table schemas
- Staging table approach for efficient data synchronization
- PSR-3 logging support for monitoring and debugging
- Support for non-nullable datetime columns with placeholder values
- Customizable metadata column handling
- Optional deletion logging to track deleted records
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
    'placeholderDatetime' => '2222-02-22 00:00:00',  // Placeholder for non-nullable datetime columns
    'metadataColumns' => ['created_at', 'updated_at'],
    'targetColumnTypeOverrides' => [],
    'targetColumnLengthOverrides' => [],
]);

// Create the service dependencies
$schemaManager = new GenericSchemaManager($logger);
$indexManager = new GenericIndexManager($logger);
$dataHasher = new GenericDataHasher($logger);

// Create the syncer with dependency injection
$syncer = new GenericTableSyncer($schemaManager, $indexManager, $dataHasher, $logger);

// Run the synchronization (with batch revision ID)
$batchRevisionId = 1; // Increment this for each batch
$report = $syncer->sync($config, $batchRevisionId);

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
- `placeholderDatetime`: String containing a placeholder datetime value (e.g. '2222-02-22 00:00:00') to use for non-nullable datetime columns when source has NULL
- `metadataColumns`: Columns to include in metadata handling
- `enableDeletionLogging`: Enable logging of deleted records (default: false)
- `targetDeletedLogTableName`: Custom name for the deletion log table (default: <targetLiveTableName>_deleted_log)
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

The library relies on a provided `LoggerInterface` implementation for all logging. You must implement and provide a PSR-3 compatible logger when creating service instances. The library does not output logs directly to console or files.

## Service Architecture

The library uses a service architecture with dependency injection:

- `GenericTableSyncer`: Main orchestrator that manages the synchronization process
- `GenericSchemaManager`: Handles table schema creation and validation
- `GenericIndexManager`: Manages index creation for both temp and live tables
- `GenericDataHasher`: Handles the content hash generation for change detection

## Deletion Logging (Optional)

The library supports optional logging of deleted records. When enabled, a separate table is created to track rows that are deleted from the live target table during synchronization.

### Configuration

To enable deletion logging, set the following properties in your `TableSyncConfigDTO`:

```php
$config = new TableSyncConfigDTO(
    // ... other parameters ...
    enableDeletionLogging: true,
    targetDeletedLogTableName: 'custom_deleted_log_table' // Optional, defaults to <targetLiveTableName>_deleted_log
);
```

### Log Table Schema

When deletion logging is enabled, a table with the following schema is created:

- `log_id`: BIGINT, auto-increment, primary key
- `deleted_syncer_id`: Same type as the `_syncer_id` in the live table, stores the ID of the deleted record
- `deleted_at_revision_id`: INTEGER, stores the batch revision ID when the record was deleted
- `deletion_timestamp`: DATETIME, stores when the deletion occurred

The table includes indexes on `deleted_at_revision_id` and `deleted_syncer_id` for efficient querying.

### Accessing Logged Deletions

The `SyncReportDTO` includes a `loggedDeletionsCount` property that indicates how many deletions were logged during the synchronization process. This count is also included in the summary if greater than zero:

```php
$report = $syncer->sync($config, $batchRevisionId);
echo $report->getSummary(); // Example: "Inserts: 5, Updates: 10, Deletes: 3, Logged Deletions: 3"
```

## License

MIT