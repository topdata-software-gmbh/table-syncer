# Table Syncer

A generic PHP library for synchronizing table data between two databases using Doctrine DBAL, supporting column name mapping and a staging table approach.

## Features

- Database table synchronization
- Column name mapping
- Staging table approach
- PSR-3 logging support

## Requirements

- PHP 8.0 or higher
- Doctrine DBAL 3.0 or higher

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

// Create your logger implementation
$logger = new YourLoggerImplementation();

// Create your sync configuration
$config = new TableSyncConfigDTO([
    // configuration options
]);

// Create the syncer
$syncer = new GenericTableSyncer($logger);

// Run the synchronization
$report = $syncer->sync($config);
```

## License

MIT