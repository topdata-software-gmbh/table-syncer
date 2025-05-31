**Project Plan: Create Open-Source Package `topdata-software-gmbh/table-syncer` (Final)**

**Project Name:** `topdata-software-gmbh/table-syncer`
**Primary PHP Namespace:** `TopdataSoftwareGmbh\TableSyncer`

**Phase 1: Project Initialization and Core Structure**

1.  **Create Git Repository:**
    *   Action: Initialize a new local Git repository named `table-syncer`.
    *   Output: Local Git repository.

2.  **Define `composer.json`:**
    *   Action: Create `composer.json` in the project root.
    *   Content:
        ```json
        {
            "name": "topdata-software-gmbh/table-syncer",
            "description": "A generic PHP library for synchronizing table data between two databases using Doctrine DBAL, supporting column name mapping and a staging table approach.",
            "type": "library",
            "license": "MIT",
            "authors": [
                {
                    "name": "Topdata Software GmbH",
                    "email": "dev-contact@topdata.de" // Replace with actual dev contact
                }
            ],
            "keywords": ["database", "sync", "table sync", "table synchronization", "doctrine", "dbal", "etl", "staging", "psr-3"],
            "require": {
                "php": "^8.0 || ^8.1 || ^8.2 || ^8.3",
                "doctrine/dbal": "^3.0 || ^4.0",
                "psr/log": "^1.0 || ^2.0 || ^3.0"
            },
            "autoload": {
                "psr-4": {
                    "TopdataSoftwareGmbh\\TableSyncer\\": "src/"
                }
            },
            "require-dev": {
                "phpunit/phpunit": "^9.5 || ^10.0 || ^11.0",
                "squizlabs/php_codesniffer": "^3.7",
                "phpstan/phpstan": "^1.10" // Optional: For static analysis
            },
            "scripts": {
                "test": "phpunit",
                "cs-check": "phpcs src tests",
                "cs-fix": "phpcbf src tests",
                "stan": "phpstan analyse src --level=max" // Optional: if phpstan is used
            }
        }
        ```
    *   Output: `composer.json` file.

3.  **Establish Directory Structure:**
    *   Action: Create standard project directories (`src/DTO`, `src/Service`, `src/Util`, `src/Exception`, `tests/Unit`, `tests/Integration`, `docs`).
    *   Output: Project directory structure.

4.  **Create Standard Project Files:**
    *   Action: Create `.gitignore` (excluding `vendor/`, `composer.lock`, `phpunit.result.cache`, etc.).
    *   Action: Create `README.md` (initial version: title, brief description, installation, PSR-3 logging note).
    *   Action: Create `LICENSE` (with chosen license text, e.g., MIT).
    *   Action: Create `phpcs.xml.dist` (configured for PSR-12, targeting `src` and `tests`).
    *   Action: Create `phpunit.xml.dist` (configured for tests in `tests/`, bootstrap `vendor/autoload.php`, code coverage for `src/`).
    *   Output: Standard project files.

5.  **Initial Composer Install:**
    *   Action: Run `composer install` from the project root.
    *   Output: `vendor/` directory, `composer.lock`.

---

**Phase 2: Implement Core DTOs**
*   Location: `src/DTO/`
*   Namespace: `TopdataSoftwareGmbh\TableSyncer\DTO`

1.  **Implement `MetadataColumnNamesDTO.php`:**
    *   Action: Create file with public string properties (`id`, `contentHash`, etc.) and a constructor allowing overrides.
    *   Output: `src/DTO/MetadataColumnNamesDTO.php`.

2.  **Implement `SyncReportDTO.php`:**
    *   Action: Create file with public int count properties, private `logMessages` array, and methods `addLogMessage()`, `getLogMessages()`, `getSummary()`.
    *   Output: `src/DTO/SyncReportDTO.php`.

3.  **Implement `TableSyncConfigDTO.php`:**
    *   Action: Create file with all specified public properties (`sourceConnection`, `primaryKeyColumnMap`, `dataColumnMapping`, `targetConnection`, `columnsForContentHash`, `nonNullableDatetimeSourceColumns`, `metadataColumns`, type/length settings, `placeholderDatetime`).
    *   Constructor: Accept parameters, initialize properties, implement strict validation (map emptiness, PKs/hash/datetime columns defined in `dataColumnMapping`).
    *   Helper Methods: `getSourcePrimaryKeyColumns`, `getTargetPrimaryKeyColumns`, `getSourceDataColumns`, `getTargetDataColumns`, `getTargetColumnName`, `getTargetColumnsForContentHash`, `getTargetNonNullableDatetimeColumns`, `getTempTableColumns`.
    *   Output: `src/DTO/TableSyncConfigDTO.php`.

---

**Phase 3: Implement Core Services**
*   Location: `src/Service/`
*   Namespace: `TopdataSoftwareGmbh\TableSyncer\Service`
*   **PSR-3 Logging Standard:**
    *   All services in this phase MUST accept `Psr\Log\LoggerInterface` in their constructor (e.g., `?LoggerInterface $logger = null`).
    *   Store it as `private readonly LoggerInterface $logger;`.
    *   Initialize with `$logger ?? new \Psr\Log\NullLogger();`.
    *   All logging calls MUST use `$this->logger->debug(...)`, `$this->logger->info(...)`, etc.

1.  **Implement `GenericDataHasher.php`:**
    *   Action: Create file, implement constructor for `LoggerInterface`.
    *   Method: `public function addHashesToTempTable(TableSyncConfigDTO $config): int`. Logic using `$config->getTargetColumnsForContentHash()`, platform-aware CONCAT, SHA2, COALESCE for hashing on temp table. Uses `$config->placeholderDatetime` as needed.
    *   Output: `src/Service/GenericDataHasher.php`.

2.  **Implement `GenericIndexManager.php`:**
    *   Action: Create file, implement constructor for `LoggerInterface`.
    *   Methods: `addIndicesToTempTableAfterLoad()`, `addIndicesToLiveTable()`, `addIndexIfNotExists()`.
    *   Output: `src/Service/GenericIndexManager.php`.

3.  **Implement `GenericSchemaManager.php`:**
    *   Action: Create file, implement constructor for `LoggerInterface`.
    *   Fields: `sourceTableDetailsCache`, `cachedSourceTableName`.
    *   Methods: `ensureLiveTable()`, `prepareTempTable()`, `getLiveTableSpecificMetadataColumns()`, `getTempTableSpecificMetadataColumns()`, `_createTable()` (core DDL logic using DTO mappings), `getSourceColumnTypes()` (with introspection & info_schema fallback), `getDbalTypeNameFromTypeObject()`, `mapInformationSchemaType()`, `dropTempTable()`. Uses `$config->placeholderDatetime` as needed.
    *   Output: `src/Service/GenericSchemaManager.php`.

4.  **Implement `GenericTableSyncer.php`:**
    *   Action: Create file.
    *   Constructor: Accept `GenericSchemaManager`, `GenericIndexManager`, `GenericDataHasher`, and `?LoggerInterface`.
    *   Method: `public function sync(TableSyncConfigDTO $config, int $currentBatchRevisionId): SyncReportDTO` (orchestration).
    *   Method: `private function loadDataFromSourceToTemp(...)`.
        *   Uses `$this->schemaManager` for type lookups.
        *   Implements parameter building, mapping source row values to temp table insert parameters, handling datetimes using `$config->nonNullableDatetimeSourceColumns` and `$config->placeholderDatetime`.
    *   Method: `private function synchronizeTempToLive(...)`.
        *   Uses target column names from DTO helpers for SQL between temp and live.
        *   Ensures no redundant datetime processing.
    *   Method: `protected function ensureDatetimeValues(...)` (operates on source row, uses `$config->nonNullableDatetimeSourceColumns` and `$config->placeholderDatetime`).
    *   Helper Methods: `isDateEmptyOrInvalid()`, `dbalTypeToParameterType()`.
    *   Output: `src/Service/GenericTableSyncer.php`.

---

**Phase 4: Utility and Exception Implementation**

1.  **Implement `DbalHelper.php` (Optional)**
    *   Location: `src/Util/` | Namespace: `TopdataSoftwareGmbh\TableSyncer\Util`
    *   Action: Create file with static DBAL helper methods (e.g., `getDatabasePlatformName()`).
    *   Output: `src/Util/DbalHelper.php`.

2.  **Implement Custom Exceptions**
    *   Location: `src/Exception/` | Namespace: `TopdataSoftwareGmbh\TableSyncer\Exception`
    *   Action: Create `TableSyncerException.php` (base exception) and specific ones like `ConfigurationException.php`.
    *   Update services to use these custom exceptions.
    *   Output: Exception files.

---

**Phase 5: Unit Testing**

1.  **Write Unit Tests for DTOs (`tests/Unit/DTO/`)**: Test constructors, validation, defaults, helper methods.
2.  **Write Unit Tests for Services (`tests/Unit/Service/`)**:
    *   Mock dependencies (`Connection`, `SchemaManager` (DBAL), `LoggerInterface`). Use `NullLogger` or a mock logger for testing logging calls.
    *   Test core logic, SQL generation, data mapping, and specific behaviors of each service.
3.  **Execute Tests (`composer test`)**: Ensure tests pass and achieve good code coverage.

---

**Phase 6: Documentation and Finalization**

1.  **Finalize `README.md`**:
    *   Comprehensive details: Features, Requirements, Installation, **Detailed Usage Examples (crucially showing PSR-3 logger injection)**, Configuration of `TableSyncConfigDTO` (including `placeholderDatetime`), Customizing `MetadataColumnNamesDTO`, License.
    *   Explain that the library itself does not output logs directly to console/file but relies on the provided `LoggerInterface` implementation.

2.  **Create `CHANGELOG.md`**: Initialize for `v1.0.0`.
3.  **Code Style Adherence (`composer cs-check`, `composer cs-fix`)**.
4.  **Static Analysis (Optional, `composer stan`)**.
5.  **Final Review**: Code, documentation, and configuration.

---

**Phase 7: Publishing (Conceptual for AI)**

1.  **Tag Initial Release (e.g., `v1.0.0`).**
2.  **Push to Remote Git Repository.**
3.  **Submit to Packagist.org.**

---

**Instructions for AI Agent:**

*   Strictly follow phases and steps.
*   Implement all classes and methods within the `TopdataSoftwareGmbh\TableSyncer` namespace.
*   **All logging must utilize an injected `Psr\Log\LoggerInterface` instance.**
*   The `placeholderDatetime` value must be sourced from `$config->placeholderDatetime` where applicable.
*   Ensure the `GenericTableSyncer` uses its injected `$this->schemaManager` for schema operations.
*   Adhere to PSR-12 coding standards.
*   Generated code should be robust, maintainable, and well-documented (PHPDoc blocks).

