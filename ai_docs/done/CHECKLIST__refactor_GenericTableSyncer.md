# Refactoring `GenericTableSyncer` Checklist

**Objective:** Refactor `GenericTableSyncer` into an orchestrator, delegating specific tasks to new `SourceToTempLoader` and `TempToLiveSynchronizer` services.

## Phase 1: Create New Service Class Files

*   [x] **`src/Service/SourceToTempLoader.php`:**
    *   [x] Create file `src/Service/SourceToTempLoader.php`.
    *   [x] Define class `SourceToTempLoader` in `TopdataSoftwareGmbh\TableSyncer\Service` namespace.
    *   [x] Add basic constructor (accepting `GenericSchemaManager`, `?LoggerInterface`).
    *   [x] Add `public function load(TableSyncConfigDTO $config): void` method stub.
*   [x] **`src/Service/TempToLiveSynchronizer.php`:**
    *   [x] Create file `src/Service/TempToLiveSynchronizer.php`.
    *   [x] Define class `TempToLiveSynchronizer` in `TopdataSoftwareGmbh\TableSyncer\Service` namespace.
    *   [x] Add basic constructor (accepting `?LoggerInterface`).
    *   [x] Add `public function synchronize(TableSyncConfigDTO $config, int $currentBatchRevisionId, SyncReportDTO $report): void` method stub.

## Phase 2: Implement `SourceToTempLoader`

*   [x] **Constructor Implementation:**
    *   [x] Implement constructor to accept `GenericSchemaManager $schemaManager` and `?LoggerInterface $logger = null`.
    *   [x] Store dependencies as `private readonly` properties (`$schemaManager`, `$logger`).
    *   [x] Initialize `$this->logger` with `$logger ?? new NullLogger()`.
*   [x] **Move `loadDataFromSourceToTemp` Logic to `SourceToTempLoader::load()`:**
    *   [x] Copy method body from `GenericTableSyncer::loadDataFromSourceToTemp` to `SourceToTempLoader::load`.
    *   [x] Ensure correct use of `$this->schemaManager` and `$this->logger`.
*   [x] **Move Helper Methods to `SourceToTempLoader`:**
    *   [x] Copy `ensureDatetimeValues()` from `GenericTableSyncer` to `SourceToTempLoader`.
        *   [x] Set visibility to `protected`.
        *   [x] Verify internal logger usage.
    *   [x] Copy `isDateEffectivelyZeroOrInvalid()` from `GenericTableSyncer` to `SourceToTempLoader`.
        *   [x] Set visibility to `protected`.
    *   [x] Copy `isDateEmptyOrInvalid()` from `GenericTableSyncer` to `SourceToTempLoader`.
        *   [x] Set visibility to `protected`.
    *   [x] Copy `getDbalParamType()` from `GenericTableSyncer` to `SourceToTempLoader`.
        *   [x] Set visibility to `protected`.
    *   [x] Copy `dbalTypeToParameterType()` from `GenericTableSyncer` to `SourceToTempLoader`.
        *   [x] Set visibility to `protected`.
*   [x] **Update PHPDoc for `SourceToTempLoader` and its methods.**

## Phase 3: Implement `TempToLiveSynchronizer`

*   [x] **Constructor Implementation:**
    *   [x] Implement constructor to accept `?LoggerInterface $logger = null`.
    *   [x] Store dependency as `private readonly LoggerInterface $logger`.
    *   [x] Initialize `$this->logger` with `$logger ?? new NullLogger()`.
*   [x] **Move `synchronizeTempToLive` Logic to `TempToLiveSynchronizer::synchronize()`:**
    *   [x] Copy method body from `GenericTableSyncer::synchronizeTempToLive` to `TempToLiveSynchronizer::synchronize`.
    *   [x] Ensure correct use of `$this->logger`.
*   [x] **Update PHPDoc for `TempToLiveSynchronizer` and its methods.**

## Phase 4: Refactor `GenericTableSyncer`

*   [x] **Update Constructor:**
    *   [x] Modify constructor signature to include `SourceToTempLoader $sourceToTempLoader` and `TempToLiveSynchronizer $tempToLiveSynchronizer`.
    *   [x] Store new dependencies as `private readonly` properties.
*   [x] **Update `sync()` Method:**
    *   [x] Replace internal call to `loadDataFromSourceToTemp()` with `$this->sourceToTempLoader->load($config);`.
    *   [x] Replace internal call to `synchronizeTempToLive()` with `$this->tempToLiveSynchronizer->synchronize($config, $currentBatchRevisionId, $report);`.
*   [x] **Remove Old Methods from `GenericTableSyncer`:**
    *   [x] Delete `loadDataFromSourceToTemp()`.
    *   [x] Delete `synchronizeTempToLive()`.
    *   [x] Delete `ensureDatetimeValues()`.
    *   [x] Delete `isDateEffectivelyZeroOrInvalid()`.
    *   [x] Delete `isDateEmptyOrInvalid()`.
    *   [x] Delete `getDbalParamType()`.
    *   [x] Delete `dbalTypeToParameterType()`.
*   [x] **Update PHPDoc for `GenericTableSyncer` constructor and `sync()` method.**

## Phase 5: Update Unit Tests

*   [ ] **Create `tests/Unit/Service/SourceToTempLoaderTest.php`:**
    *   [ ] Create test class.
    *   [ ] Add mocks for `GenericSchemaManager`, `LoggerInterface`, `TableSyncConfigDTO`, `Connection`.
    *   [ ] Test `load()` method thoroughly (SQL, datetime, params, iteration, logging).
    *   [ ] Test helper methods (if `protected`).
*   [ ] **Create `tests/Unit/Service/TempToLiveSynchronizerTest.php`:**
    *   [ ] Create test class.
    *   [ ] Add mocks for `LoggerInterface`, `TableSyncConfigDTO`, `SyncReportDTO`, `Connection`.
    *   [ ] Test `synchronize()` method thoroughly (initial import, UPDATE, DELETE, INSERT, report population, logging).
*   [ ] **Update `tests/Unit/Service/GenericTableSyncerTest.php`:**
    *   [ ] Update constructor mocks to include `SourceToTempLoader` and `TempToLiveSynchronizer`.
    *   [ ] Verify `sync()` calls `load()` on `SourceToTempLoader`.
    *   [ ] Verify `sync()` calls `synchronize()` on `TempToLiveSynchronizer`.
    *   [ ] Ensure orchestration and transaction tests are still valid.

## Phase 6: Update Documentation

*   [ ] **`README.md`:**
    *   [ ] Update "Usage" example to show instantiation of new services and passing them to `GenericTableSyncer`.
    *   [ ] Update "Service Architecture" section to mention new services.
*   [ ] **`CHANGELOG.md`:**
    *   [ ] Add entry for refactoring under a new version.
*   [ ] **AI Internal Docs (Reference Only):**
    *   [ ] Note that `CHECKLIST_finish-it.md` and `PLAN__finish-it.md` reflect a pre-refactoring state for `GenericTableSyncer` internals.

## Phase 7: Final Checks & Cleanup

*   [ ] **Verify `use` statements in all modified files.**
*   [ ] **Run `composer dump-autoload` (if applicable).**
*   [ ] **Run all unit tests: `composer test`.** (All tests must pass)
*   [ ] **Run code style check: `composer cs-check`.** (No violations)
*   [ ] **Run code style fix (if needed): `composer cs-fix`.**
*   [ ] **Run static analysis: `composer stan`.** (Address critical issues)
*   [ ] **Review all changed code for correctness and adherence to the plan.**

