**Project Checklist: `topdata-software-gmbh/table-syncer`**

**Phase 1: Project Initialization and Core Structure**
*   [x] **1. Create Git Repository:**
    *   [x] Initialize local Git repository `table-syncer`.
*   [x] **2. Define `composer.json`:**
    *   [x] Create `composer.json` in project root.
    *   [x] `name` set to `topdata-software-gmbh/table-syncer`.
    *   [x] `description` accurately reflects the library.
    *   [x] `type` set to `library`.
    *   [x] `license` set to `MIT`.
    *   [x] `authors` section filled.
    *   [x] `keywords` include `database`, `sync`, `doctrine`, `psr-3`, etc.
    *   [x] `require` includes `php: ^8.0 || ^8.1 || ^8.2 || ^8.3`.
    *   [x] `require` includes `doctrine/dbal: ^3.0 || ^4.0`.
    *   [x] `require` includes `psr/log: ^1.0 || ^2.0 || ^3.0`.
    *   [x] `autoload.psr-4` configured for `TopdataSoftwareGmbh\\TableSyncer\\` to `src/`.
    *   [x] `require-dev` includes `phpunit/phpunit`.
    *   [x] `require-dev` includes `squizlabs/php_codesniffer`.
    *   [x] `require-dev` includes `phpstan/phpstan` (Optional).
    *   [x] `scripts` defined for `test`, `cs-check`, `cs-fix`, `stan` (Optional).
*   [x] **3. Establish Directory Structure:**
    *   [x] Create `src/` directory.
    *   [x] Create `src/DTO/` directory.
    *   [x] Create `src/Service/` directory.
    *   [x] Create `src/Util/` directory.
    *   [x] Create `src/Exception/` directory.
    *   [x] Create `tests/` directory.
    *   [x] Create `tests/Unit/` directory.
    *   [x] Create `tests/Integration/` directory.
    *   [x] Create `docs/` directory.
*   [x] **4. Create Standard Project Files:**
    *   [x] Create `.gitignore` (excluding `vendor/`, `composer.lock`, etc.).
    *   [x] Create `README.md` (initial version: title, description, installation, PSR-3 note).
    *   [x] Create `LICENSE` file (with MIT license text).
    *   [x] Create `phpcs.xml.dist` (configured for PSR-12, targeting `src` and `tests`).
    *   [x] Create `phpunit.xml.dist` (configured for `tests/`, bootstrap, code coverage for `src/`).
*   [x] **5. Initial Composer Install:**
    *   [x] Run `composer install`.
    *   [x] `vendor/` directory created.
    *   [x] `composer.lock` file created.

**Phase 2: Implement Core DTOs (`src/DTO/`)**
*   [x] **1. `MetadataColumnNamesDTO.php`:**
    *   [x] File created in `src/DTO/`.
    *   [x] Public string properties (`id`, `contentHash`, etc.) defined.
    *   [x] Constructor allows overriding default property values.
*   [x] **2. `SyncReportDTO.php`:**
    *   [x] File created in `src/DTO/`.
    *   [x] Public int count properties defined.
    *   [x] Private `logMessages` array defined.
    *   [x] Method `addLogMessage(string $level, string $message, array $context = [])` implemented.
    *   [x] Method `getLogMessages(): array` implemented.
    *   [x] Method `getSummary(): string` implemented.
*   [x] **3. `TableSyncConfigDTO.php`:**
    *   [x] File created in `src/DTO/`.
    *   [x] All specified public properties defined (connections, mappings, columns, placeholder, etc.).
    *   [x] Constructor accepts parameters and initializes properties.
    *   [x] Constructor implements strict validation (map emptiness, PKs/hash/datetime columns in `dataColumnMapping`).
    *   [x] Helper method `getSourcePrimaryKeyColumns()` implemented.
    *   [x] Helper method `getTargetPrimaryKeyColumns()` implemented.
    *   [x] Helper method `getSourceDataColumns()` implemented.
    *   [x] Helper method `getTargetDataColumns()` implemented.
    *   [x] Helper method `getTargetColumnName(string $sourceColumnName): string` implemented.
    *   [x] Helper method `getTargetColumnsForContentHash(): array` implemented.
    *   [x] Helper method `getTargetNonNullableDatetimeColumns(): array` implemented.
    *   [x] Helper method `getTempTableColumns(): array` implemented.

**Phase 3: Implement Core Services (`src/Service/`)**
 *   [x] **PSR-3 Logging Standard Adherence (for all services below):**
     *   [x] Constructor accepts `?Psr\Log\LoggerInterface $logger = null`.
     *   [x] Stores `private readonly LoggerInterface $logger;`.
     *   [x] Initializes logger with `$logger ?? new \Psr\Log\NullLogger();`.
     *   [x] All internal logging uses `$this->logger->debug()`, `info()`, etc.
 *   [x] **1. `GenericDataHasher.php`:**
     *   [x] File created in `src/Service/`.
     *   [x] Constructor implements PSR-3 logging.
     *   [x] Method `public function addHashesToTempTable(TableSyncConfigDTO $config): int` implemented.
     *   [x] Hashing logic uses `$config->getTargetColumnsForContentHash()`.
     *   [x] Hashing logic is platform-aware (CONCAT, SHA2, COALESCE).
     *   [x] Hashing logic uses `$config->placeholderDatetime` for datetime columns as needed.
 *   [x] **2. `GenericIndexManager.php`:**
     *   [x] File created in `src/Service/`.
     *   [x] Constructor implements PSR-3 logging.
     *   [x] Method `addIndicesToTempTableAfterLoad(TableSyncConfigDTO $config): void` implemented.
     *   [x] Method `addIndicesToLiveTable(TableSyncConfigDTO $config): void` implemented.
     *   [x] Method `addIndexIfNotExists(Connection $connection, string $tableName, array $columns, bool $isUnique = false, ?string $indexName = null): void` implemented.
 *   [x] **3. `GenericSchemaManager.php`:**
     *   [x] File created in `src/Service/`.
     *   [x] Constructor implements PSR-3 logging.
     *   [x] Fields `sourceTableDetailsCache`, `cachedSourceTableName` defined.
     *   [x] Method `ensureLiveTable(TableSyncConfigDTO $config): void` implemented.
     *   [x] Method `prepareTempTable(TableSyncConfigDTO $config): void` implemented.
     *   [x] Method `getLiveTableSpecificMetadataColumns(TableSyncConfigDTO $config): array` implemented.
     *   [x] Method `getTempTableSpecificMetadataColumns(TableSyncConfigDTO $config): array` implemented.
     *   [x] Method `_createTable(...)` implemented (core DDL logic using DTO mappings).
     *   [x] Method `getSourceColumnTypes(TableSyncConfigDTO $config): array` implemented (with introspection & info_schema fallback).
     *   [x] Method `getDbalTypeNameFromTypeObject(Type $type): string` implemented.
     *   [x] Method `mapInformationSchemaType(string $infoSchemaType, ?int $charMaxLength, ?int $numericPrecision, ?int $numericScale): string` implemented.
     *   [x] Method `dropTempTable(TableSyncConfigDTO $config): void` implemented.
     *   [x] Uses `$config->placeholderDatetime` for default values in table creation as needed.
 *   [x] **4. `GenericTableSyncer.php`:**
     *   [x] File created in `src/Service/`.
     *   [x] Constructor accepts `GenericSchemaManager`, `GenericIndexManager`, `GenericDataHasher`, and `?LoggerInterface`.
     *   [x] Constructor implements PSR-3 logging.
     *   [x] Method `public function sync(TableSyncConfigDTO $config, int $currentBatchRevisionId): SyncReportDTO` implemented (orchestration logic).
     *   [x] Method `private function loadDataFromSourceToTemp(TableSyncConfigDTO $config, int $currentBatchRevisionId): int` implemented.
         *   [x] Uses `$this->schemaManager` for type lookups.
         *   [x] Implements parameter building.
         *   [x] Maps source row values to temp table insert parameters.
         *   [x] Handles datetimes using `$config->nonNullableDatetimeSourceColumns` and `$config->placeholderDatetime`.
     *   [x] Method `private function synchronizeTempToLive(TableSyncConfigDTO $config, int $currentBatchRevisionId): array` implemented.
         *   [x] Uses target column names from DTO helpers for SQL.
         *   [x] Ensures no redundant datetime processing during sync.
     *   [x] Method `protected function ensureDatetimeValues(array $row, TableSyncConfigDTO $config): array` implemented (operates on source row, uses `$config->nonNullableDatetimeSourceColumns` and `$config->placeholderDatetime`).
     *   [x] Helper method `isDateEmptyOrInvalid(?string $dateValue): bool` implemented.
     *   [x] Helper method `dbalTypeToParameterType(string $dbalType): int` implemented.

**Phase 4: Utility and Exception Implementation**
 *   [x] **1. `DbalHelper.php` (Optional - `src/Util/`)**
     *   [x] File created if chosen.
     *   [x] Static DBAL helper methods implemented (e.g., `getDatabasePlatformName()`).
 *   [x] **2. Custom Exceptions (`src/Exception/`)**
     *   [x] `TableSyncerException.php` (base exception) created.
     *   [x] `ConfigurationException.php` (or similar specific exceptions) created.
     *   [x] Services updated to throw these custom exceptions where appropriate.

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
 *   [x] **1. Finalize `README.md`:**
     *   [x] Comprehensive features section.
     *   [x] Clear requirements section.
     *   [x] Detailed installation instructions.
     *   [x] **Detailed Usage Examples:**
         *   [x] Example showing `TableSyncConfigDTO` setup.
         *   [x] Example showing instantiation of `GenericTableSyncer` with dependencies.
         *   [x] **Crucially, example showing PSR-3 logger injection and usage.**
         *   [x] Example output or expected behavior.
     *   [x] Explanation of `placeholderDatetime` configuration.
     *   [x] Information on customizing `MetadataColumnNamesDTO`.
     *   [x] License information clearly stated.
     *   [x] Clarification that library relies on provided `LoggerInterface` for output.
 *   [x] **2. Create `CHANGELOG.md`:**
     *   [x] `CHANGELOG.md` file created.
     *   [x] Initialized for `v1.0.0`.
 *   [x] **3. Code Style Adherence:**
     *   [x] Run `composer cs-check`; ensure no violations.
     *   [x] Run `composer cs-fix` if necessary.
 *   [x] **4. Static Analysis (Optional):**
     *   [ ] Run `composer stan` (if PHPStan is used).
     *   [ ] Address any critical issues reported.
 *   [x] **5. Final Review:**
     *   [x] Code reviewed for logic, maintainability, and robustness.
     *   [x] Documentation reviewed for clarity, accuracy, and completeness.
     *   [x] Configuration files (`composer.json`, etc.) reviewed.

**Phase 7: Publishing (Conceptual for AI / Actual for Human)**
*   [ ] **1. Tag Initial Release:**
    *   [ ] Create Git tag (e.g., `v1.0.0`).
*   [ ] **2. Push to Remote Git Repository:**
    *   [ ] Push all commits and tags to the remote repository.
*   [ ] **3. Submit to Packagist.org:**
    *   [ ] Package submitted and live on Packagist.org.
