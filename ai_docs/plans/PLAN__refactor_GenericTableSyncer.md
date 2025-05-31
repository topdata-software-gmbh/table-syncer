**Project Goal:** Refactor `GenericTableSyncer` to delegate specific tasks to new, specialized service classes: `SourceToTempLoader` and `TempToLiveSynchronizer`. `GenericTableSyncer` will become an orchestrator.

**Key Principles for AI:**

*   Adhere strictly to PSR-12 coding standards.
*   All new classes should be in the `TopdataSoftwareGmbh\TableSyncer\Service` namespace.
*   All services will continue to accept `?Psr\Log\LoggerInterface $logger = null` in their constructors and initialize `$this->logger` with `$logger ?? new NullLogger()`.
*   No external Dependency Injection container is used. Instantiation of these services will be manual (e.g., `new SourceToTempLoader(new GenericSchemaManager(), $logger)`).
*   Ensure all existing functionality is preserved.
*   Update PHPDoc blocks for all changed and new methods/classes.

---

**Phase 1: Create New Service Class Files**

1.  **Create `src/Service/SourceToTempLoader.php`:**
    *   Define the class `SourceToTempLoader` in the `TopdataSoftwareGmbh\TableSyncer\Service` namespace.
    *   It will have a constructor and initially one public method `load()`.
2.  **Create `src/Service/TempToLiveSynchronizer.php`:**
    *   Define the class `TempToLiveSynchronizer` in the `TopdataSoftwareGmbh\TableSyncer\Service` namespace.
    *   It will have a constructor and initially one public method `synchronize()`.

---

**Phase 2: Implement `SourceToTempLoader`**

1.  **Define Constructor:**
    *   The constructor should accept `GenericSchemaManager $schemaManager` and `?LoggerInterface $logger = null`.
    *   Store them as `private readonly GenericSchemaManager $schemaManager;` and `private readonly LoggerInterface $logger;`.
2.  **Move `loadDataFromSourceToTemp` Logic:**
    *   Copy the entire `protected function loadDataFromSourceToTemp(TableSyncConfigDTO $config): void` method from `GenericTableSyncer.php` into `SourceToTempLoader.php`.
    *   Rename it to `public function load(TableSyncConfigDTO $config): void`.
    *   Change visibility to `public`.
    *   Update all internal calls from `$this->schemaManager->...` to `$this->schemaManager->...` (this should remain the same if `schemaManager` property name is kept).
    *   Update all internal calls from `$this->logger->...` to `$this->logger->...`.
3.  **Move Helper Methods for Loading:**
    *   Copy the following `protected` methods from `GenericTableSyncer.php` to `SourceToTempLoader.php`:
        *   `ensureDatetimeValues(TableSyncConfigDTO $config, array $row): array`
        *   `isDateEffectivelyZeroOrInvalid(?string $dateString): bool`
        *   `isDateEmptyOrInvalid($val): bool`
        *   `getDbalParamType(string $columnName, $value): ParameterType`
        *   `dbalTypeToParameterType(string $dbalTypeName): ParameterType`
    *   Change their visibility to `protected` (or `private` if they are only called from within `load()`).
    *   Ensure these methods correctly use `$this->logger` if they have logging calls.

---

**Phase 3: Implement `TempToLiveSynchronizer`**

1.  **Define Constructor:**
    *   The constructor should accept `?LoggerInterface $logger = null`.
    *   Store it as `private readonly LoggerInterface $logger;`.
2.  **Move `synchronizeTempToLive` Logic:**
    *   Copy the entire `protected function synchronizeTempToLive(TableSyncConfigDTO $config, int $currentBatchRevisionId, SyncReportDTO $report): void` method from `GenericTableSyncer.php` into `TempToLiveSynchronizer.php`.
    *   Rename it to `public function synchronize(TableSyncConfigDTO $config, int $currentBatchRevisionId, SyncReportDTO $report): void`.
    *   Change visibility to `public`.
    *   Update all internal calls from `$this->logger->...` to `$this->logger->...`.

---

**Phase 4: Refactor `GenericTableSyncer`**

1.  **Update Constructor:**
    *   Modify the constructor of `GenericTableSyncer` to accept the new services:
        ```php
        public function __construct(
            GenericSchemaManager $schemaManager,
            GenericIndexManager  $indexManager,
            GenericDataHasher    $dataHasher,
            SourceToTempLoader   $sourceToTempLoader, // New
            TempToLiveSynchronizer $tempToLiveSynchronizer, // New
            ?LoggerInterface     $logger = null
        )
        ```
    *   Store `SourceToTempLoader` and `TempToLiveSynchronizer` as `private readonly` properties.
2.  **Update `sync()` Method:**
    *   In the `sync()` method:
        *   Replace the line:
            `$this->loadDataFromSourceToTemp($config);`
            with:
            `$this->sourceToTempLoader->load($config);`
        *   Replace the line:
            `$this->synchronizeTempToLive($config, $currentBatchRevisionId, $report);`
            with:
            `$this->tempToLiveSynchronizer->synchronize($config, $currentBatchRevisionId, $report);`
3.  **Remove Old Methods:**
    *   Delete the following methods from `GenericTableSyncer.php` as their logic has been moved:
        *   `loadDataFromSourceToTemp()`
        *   `synchronizeTempToLive()`
        *   `ensureDatetimeValues()`
        *   `isDateEffectivelyZeroOrInvalid()`
        *   `isDateEmptyOrInvalid()`
        *   `getDbalParamType()`
        *   `dbalTypeToParameterType()`

---

**Phase 5: Update Unit Tests**

1.  **Create `tests/Unit/Service/SourceToTempLoaderTest.php`:**
    *   Create a new test class for `SourceToTempLoader`.
    *   Mock `GenericSchemaManager` and `LoggerInterface`.
    *   Mock `TableSyncConfigDTO` and `Connection` (source and target).
    *   Write tests for the `load()` method, covering:
        *   Correct SELECT query construction.
        *   Correct INSERT query construction.
        *   Datetime value processing via `ensureDatetimeValues`.
        *   Parameter type handling.
        *   Looping through source results and inserting into temp.
        *   Logging calls.
    *   Write tests for the moved helper methods if their visibility is `protected`.
2.  **Create `tests/Unit/Service/TempToLiveSynchronizerTest.php`:**
    *   Create a new test class for `TempToLiveSynchronizer`.
    *   Mock `LoggerInterface`.
    *   Mock `TableSyncConfigDTO`, `SyncReportDTO`, and `Connection` (target).
    *   Write tests for the `synchronize()` method, covering:
        *   Initial bulk import logic (when live table is empty).
        *   UPDATE statement logic for existing rows.
        *   DELETE statement logic for obsolete rows.
        *   INSERT statement logic for new rows.
        *   Correct population of the `SyncReportDTO`.
        *   Logging calls.
3.  **Update `tests/Unit/Service/GenericTableSyncerTest.php`:**
    *   Update the mock dependencies for `GenericTableSyncer` to include `SourceToTempLoader` and `TempToLiveSynchronizer`.
    *   Modify existing tests to verify that `GenericTableSyncer::sync()` correctly calls:
        *   `$this->sourceToTempLoader->load(...)`
        *   `$this->tempToLiveSynchronizer->synchronize(...)`
        *   ...in the correct order along with other existing service calls (`schemaManager`, `indexManager`, `dataHasher`).
    *   Ensure transaction management (`beginTransaction`, `commit`, `rollBack`) is still tested appropriately around these calls.

---

**Phase 6: Update Documentation**

1.  **Update `README.md`:**
    *   In the "Usage" section, modify the example to show the instantiation of `SourceToTempLoader` and `TempToLiveSynchronizer`.
    *   Show how these new services are passed to the constructor of `GenericTableSyncer`.
        Example snippet:
        ```php
        // Create the service dependencies
        $logger = new ConsoleLogger(); // Assuming ConsoleLogger is defined
        $schemaManager = new \TopdataSoftwareGmbh\TableSyncer\Service\GenericSchemaManager($logger);
        $indexManager = new \TopdataSoftwareGmbh\TableSyncer\Service\GenericIndexManager($logger);
        $dataHasher = new \TopdataSoftwareGmbh\TableSyncer\Service\GenericDataHasher($logger);
        
        // New services
        $sourceToTempLoader = new \TopdataSoftwareGmbh\TableSyncer\Service\SourceToTempLoader($schemaManager, $logger);
        $tempToLiveSynchronizer = new \TopdataSoftwareGmbh\TableSyncer\Service\TempToLiveSynchronizer($logger);

        // Create the syncer with dependency injection
        $syncer = new \TopdataSoftwareGmbh\TableSyncer\Service\GenericTableSyncer(
            $schemaManager,
            $indexManager,
            $dataHasher,
            $sourceToTempLoader,
            $tempToLiveSynchronizer,
            $logger
        );
        ```
    *   Briefly update the "Service Architecture" section to mention the new `SourceToTempLoader` and `TempToLiveSynchronizer` and their responsibilities.
2.  **Update `CHANGELOG.md`:**
    *   Add a new entry under a future version (e.g., `[1.1.0]`) for these changes.
    *   Under "Changed": "Refactored `GenericTableSyncer` to delegate data loading to `SourceToTempLoader` and temp-to-live synchronization to `TempToLiveSynchronizer` for improved modularity and SRP."
3.  **Review `ai_docs/done/CHECKLIST_finish-it.md` and `ai_docs/done/PLAN__finish-it.md` (For AI's internal reference):**
    *   These documents describe the state *before* this refactoring. The AI should be aware that this plan supersedes parts of those concerning the internal structure of `GenericTableSyncer`. The overall `sync()` orchestration remains similar, but the implementation of sub-steps changes.

---

**Final Check:**

*   Ensure all `use` statements are correct and updated in all modified files.
*   Run `composer dump-autoload` if necessary.
*   Run all tests (`composer test`) to ensure they pass.
*   Run code style checks (`composer cs-check`) and fixes (`composer cs-fix`).
*   Run static analysis (`composer stan`).

