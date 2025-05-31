**Project Checklist: `topdata-software-gmbh/table-syncer`**

**Phase 1: Project Initialization and Core Structure**
*   [ ] **1. Create Git Repository:**
    *   [ ] Initialize local Git repository `table-syncer`.
*   [ ] **2. Define `composer.json`:**
    *   [ ] Create `composer.json` in project root.
    *   [ ] `name` set to `topdata-software-gmbh/table-syncer`.
    *   [ ] `description` accurately reflects the library.
    *   [ ] `type` set to `library`.
    *   [ ] `license` set to `MIT`.
    *   [ ] `authors` section filled.
    *   [ ] `keywords` include `database`, `sync`, `doctrine`, `psr-3`, etc.
    *   [ ] `require` includes `php: ^8.0 || ^8.1 || ^8.2 || ^8.3`.
    *   [ ] `require` includes `doctrine/dbal: ^3.0 || ^4.0`.
    *   [ ] `require` includes `psr/log: ^1.0 || ^2.0 || ^3.0`.
    *   [ ] `autoload.psr-4` configured for `TopdataSoftwareGmbh\\TableSyncer\\` to `src/`.
    *   [ ] `require-dev` includes `phpunit/phpunit`.
    *   [ ] `require-dev` includes `squizlabs/php_codesniffer`.
    *   [ ] `require-dev` includes `phpstan/phpstan` (Optional).
    *   [ ] `scripts` defined for `test`, `cs-check`, `cs-fix`, `stan` (Optional).
*   [ ] **3. Establish Directory Structure:**
    *   [ ] Create `src/` directory.
    *   [ ] Create `src/DTO/` directory.
    *   [ ] Create `src/Service/` directory.
    *   [ ] Create `src/Util/` directory.
    *   [ ] Create `src/Exception/` directory.
    *   [ ] Create `tests/` directory.
    *   [ ] Create `tests/Unit/` directory.
    *   [ ] Create `tests/Integration/` directory.
    *   [ ] Create `docs/` directory.
*   [ ] **4. Create Standard Project Files:**
    *   [ ] Create `.gitignore` (excluding `vendor/`, `composer.lock`, etc.).
    *   [ ] Create `README.md` (initial version: title, description, installation, PSR-3 note).
    *   [ ] Create `LICENSE` file (with MIT license text).
    *   [ ] Create `phpcs.xml.dist` (configured for PSR-12, targeting `src` and `tests`).
    *   [ ] Create `phpunit.xml.dist` (configured for `tests/`, bootstrap, code coverage for `src/`).
*   [ ] **5. Initial Composer Install:**
    *   [ ] Run `composer install`.
    *   [ ] `vendor/` directory created.
    *   [ ] `composer.lock` file created.

**Phase 2: Implement Core DTOs (`src/DTO/`)**
*   [ ] **1. `MetadataColumnNamesDTO.php`:**
    *   [ ] File created in `src/DTO/`.
    *   [ ] Public string properties (`id`, `contentHash`, etc.) defined.
    *   [ ] Constructor allows overriding default property values.
*   [ ] **2. `SyncReportDTO.php`:**
    *   [ ] File created in `src/DTO/`.
    *   [ ] Public int count properties defined.
    *   [ ] Private `logMessages` array defined.
    *   [ ] Method `addLogMessage(string $level, string $message, array $context = [])` implemented.
    *   [ ] Method `getLogMessages(): array` implemented.
    *   [ ] Method `getSummary(): string` implemented.
*   [ ] **3. `TableSyncConfigDTO.php`:**
    *   [ ] File created in `src/DTO/`.
    *   [ ] All specified public properties defined (connections, mappings, columns, placeholder, etc.).
    *   [ ] Constructor accepts parameters and initializes properties.
    *   [ ] Constructor implements strict validation (map emptiness, PKs/hash/datetime columns in `dataColumnMapping`).
    *   [ ] Helper method `getSourcePrimaryKeyColumns()` implemented.
    *   [ ] Helper method `getTargetPrimaryKeyColumns()` implemented.
    *   [ ] Helper method `getSourceDataColumns()` implemented.
    *   [ ] Helper method `getTargetDataColumns()` implemented.
    *   [ ] Helper method `getTargetColumnName(string $sourceColumnName): string` implemented.
    *   [ ] Helper method `getTargetColumnsForContentHash(): array` implemented.
    *   [ ] Helper method `getTargetNonNullableDatetimeColumns(): array` implemented.
    *   [ ] Helper method `getTempTableColumns(): array` implemented.

**Phase 3: Implement Core Services (`src/Service/`)**
*   [ ] **PSR-3 Logging Standard Adherence (for all services below):**
    *   [ ] Constructor accepts `?Psr\Log\LoggerInterface $logger = null`.
    *   [ ] Stores `private readonly LoggerInterface $logger;`.
    *   [ ] Initializes logger with `$logger ?? new \Psr\Log\NullLogger();`.
    *   [ ] All internal logging uses `$this->logger->debug()`, `info()`, etc.
*   [ ] **1. `GenericDataHasher.php`:**
    *   [ ] File created in `src/Service/`.
    *   [ ] Constructor implements PSR-3 logging.
    *   [ ] Method `public function addHashesToTempTable(TableSyncConfigDTO $config): int` implemented.
    *   [ ] Hashing logic uses `$config->getTargetColumnsForContentHash()`.
    *   [ ] Hashing logic is platform-aware (CONCAT, SHA2, COALESCE).
    *   [ ] Hashing logic uses `$config->placeholderDatetime` for datetime columns as needed.
*   [ ] **2. `GenericIndexManager.php`:**
    *   [ ] File created in `src/Service/`.
    *   [ ] Constructor implements PSR-3 logging.
    *   [ ] Method `addIndicesToTempTableAfterLoad(TableSyncConfigDTO $config): void` implemented.
    *   [ ] Method `addIndicesToLiveTable(TableSyncConfigDTO $config): void` implemented.
    *   [ ] Method `addIndexIfNotExists(Connection $connection, string $tableName, array $columns, bool $isUnique = false, ?string $indexName = null): void` implemented.
*   [ ] **3. `GenericSchemaManager.php`:**
    *   [ ] File created in `src/Service/`.
    *   [ ] Constructor implements PSR-3 logging.
    *   [ ] Fields `sourceTableDetailsCache`, `cachedSourceTableName` defined.
    *   [ ] Method `ensureLiveTable(TableSyncConfigDTO $config): void` implemented.
    *   [ ] Method `prepareTempTable(TableSyncConfigDTO $config): void` implemented.
    *   [ ] Method `getLiveTableSpecificMetadataColumns(TableSyncConfigDTO $config): array` implemented.
    *   [ ] Method `getTempTableSpecificMetadataColumns(TableSyncConfigDTO $config): array` implemented.
    *   [ ] Method `_createTable(...)` implemented (core DDL logic using DTO mappings).
    *   [ ] Method `getSourceColumnTypes(TableSyncConfigDTO $config): array` implemented (with introspection & info_schema fallback).
    *   [ ] Method `getDbalTypeNameFromTypeObject(Type $type): string` implemented.
    *   [ ] Method `mapInformationSchemaType(string $infoSchemaType, ?int $charMaxLength, ?int $numericPrecision, ?int $numericScale): string` implemented.
    *   [ ] Method `dropTempTable(TableSyncConfigDTO $config): void` implemented.
    *   [ ] Uses `$config->placeholderDatetime` for default values in table creation as needed.
*   [ ] **4. `GenericTableSyncer.php`:**
    *   [ ] File created in `src/Service/`.
    *   [ ] Constructor accepts `GenericSchemaManager`, `GenericIndexManager`, `GenericDataHasher`, and `?LoggerInterface`.
    *   [ ] Constructor implements PSR-3 logging.
    *   [ ] Method `public function sync(TableSyncConfigDTO $config, int $currentBatchRevisionId): SyncReportDTO` implemented (orchestration logic).
    *   [ ] Method `private function loadDataFromSourceToTemp(TableSyncConfigDTO $config, int $currentBatchRevisionId): int` implemented.
        *   [ ] Uses `$this->schemaManager` for type lookups.
        *   [ ] Implements parameter building.
        *   [ ] Maps source row values to temp table insert parameters.
        *   [ ] Handles datetimes using `$config->nonNullableDatetimeSourceColumns` and `$config->placeholderDatetime`.
    *   [ ] Method `private function synchronizeTempToLive(TableSyncConfigDTO $config, int $currentBatchRevisionId): array` implemented.
        *   [ ] Uses target column names from DTO helpers for SQL.
        *   [ ] Ensures no redundant datetime processing during sync.
    *   [ ] Method `protected function ensureDatetimeValues(array $row, TableSyncConfigDTO $config): array` implemented (operates on source row, uses `$config->nonNullableDatetimeSourceColumns` and `$config->placeholderDatetime`).
    *   [ ] Helper method `isDateEmptyOrInvalid(?string $dateValue): bool` implemented.
    *   [ ] Helper method `dbalTypeToParameterType(string $dbalType): int` implemented.

**Phase 4: Utility and Exception Implementation**
*   [ ] **1. `DbalHelper.php` (Optional - `src/Util/`)**
    *   [ ] File created if chosen.
    *   [ ] Static DBAL helper methods implemented (e.g., `getDatabasePlatformName()`).
*   [ ] **2. Custom Exceptions (`src/Exception/`)**
    *   [ ] `TableSyncerException.php` (base exception) created.
    *   [ ] `ConfigurationException.php` (or similar specific exceptions) created.
    *   [ ] Services updated to throw these custom exceptions where appropriate.

**Phase 5: Unit Testing (`tests/Unit/`)**
*   [ ] **1. Unit Tests for DTOs:**
    *   [ ] Tests for `MetadataColumnNamesDTO` (constructor, defaults).
    *   [ ] Tests for `SyncReportDTO` (constructor, log methods, summary).
    *   [ ] Tests for `TableSyncConfigDTO` (constructor, validation logic, defaults, helper methods).
*   [ ] **2. Unit Tests for Services:**
    *   [ ] Tests for `GenericDataHasher` (mock dependencies, hashing logic, PSR-3 logging calls).
    *   [ ] Tests for `GenericIndexManager` (mock dependencies, index creation logic, PSR-3 logging calls).
    *   [ ] Tests for `GenericSchemaManager` (mock dependencies, DDL logic, type mapping, PSR-3 logging calls).
    *   [ ] Tests for `GenericTableSyncer` (mock dependencies, orchestration, data loading, data sync, datetime handling, PSR-3 logging calls).
*   [ ] **3. Execute Tests:**
    *   [ ] Run `composer test`.
    *   [ ] All unit tests pass.
    *   [ ] Code coverage meets target (review `phpunit.xml.dist` for configuration).

**Phase 6: Documentation and Finalization**
*   [ ] **1. Finalize `README.md`:**
    *   [ ] Comprehensive features section.
    *   [ ] Clear requirements section.
    *   [ ] Detailed installation instructions.
    *   [ ] **Detailed Usage Examples:**
        *   [ ] Example showing `TableSyncConfigDTO` setup.
        *   [ ] Example showing instantiation of `GenericTableSyncer` with dependencies.
        *   [ ] **Crucially, example showing PSR-3 logger injection and usage.**
        *   [ ] Example output or expected behavior.
    *   [ ] Explanation of `placeholderDatetime` configuration.
    *   [ ] Information on customizing `MetadataColumnNamesDTO`.
    *   [ ] License information clearly stated.
    *   [ ] Clarification that library relies on provided `LoggerInterface` for output.
*   [ ] **2. Create `CHANGELOG.md`:**
    *   [ ] `CHANGELOG.md` file created.
    *   [ ] Initialized for `v1.0.0`.
*   [ ] **3. Code Style Adherence:**
    *   [ ] Run `composer cs-check`; ensure no violations.
    *   [ ] Run `composer cs-fix` if necessary.
*   [ ] **4. Static Analysis (Optional):**
    *   [ ] Run `composer stan` (if PHPStan is used).
    *   [ ] Address any critical issues reported.
*   [ ] **5. Final Review:**
    *   [ ] Code reviewed for logic, maintainability, and robustness.
    *   [ ] Documentation reviewed for clarity, accuracy, and completeness.
    *   [ ] Configuration files (`composer.json`, etc.) reviewed.

**Phase 7: Publishing (Conceptual for AI / Actual for Human)**
*   [ ] **1. Tag Initial Release:**
    *   [ ] Create Git tag (e.g., `v1.0.0`).
*   [ ] **2. Push to Remote Git Repository:**
    *   [ ] Push all commits and tags to the remote repository.
*   [ ] **3. Submit to Packagist.org:**
    *   [ ] Package submitted and live on Packagist.org.

