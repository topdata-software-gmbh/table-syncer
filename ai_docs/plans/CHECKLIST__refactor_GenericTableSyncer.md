# Refactoring `GenericTableSyncer` Checklist

**Objective:** Refactor `GenericTableSyncer` into an orchestrator, delegating specific tasks to new `SourceToTempLoader` and `TempToLiveSynchronizer` services.

## Phase 1: Create New Service Class Files

*   [ ] **`src/Service/SourceToTempLoader.php`:**
    *   [ ] Create file `src/Service/SourceToTempLoader.php`.
    *   [ ] Define class `SourceToTempLoader` in `TopdataSoftwareGmbh\TableSyncer\Service` namespace.
    *   [ ] Add basic constructor (accepting `GenericSchemaManager`, `?LoggerInterface`).
    *   [ ] Add `public function load(TableSyncConfigDTO $config): void` method stub.
*   [ ] **`src/Service/TempToLiveSynchronizer.php`:**
    *   [ ] Create file `src/Service/TempToLiveSynchronizer.php`.
    *   [ ] Define class `TempToLiveSynchronizer` in `TopdataSoftwareGmbh\TableSyncer\Service` namespace.
    *   [ ] Add basic constructor (accepting `?LoggerInterface`).
    *   [ ] Add `public function synchronize(TableSyncConfigDTO $config, int $currentBatchRevisionId, SyncReportDTO $report): void` method stub.

## Phase 2: Implement `SourceToTempLoader`

*   [ ] **Constructor Implementation:**
    *   [ ] Implement constructor to accept `GenericSchemaManager $schemaManager` and `?LoggerInterface $logger = null`.
    *   [ ] Store dependencies as `private readonly` properties (`$schemaManager`, `$logger`).
    *   [ ] Initialize `$this->logger` with `$logger ?? new NullLogger()`.
*   [ ] **Move `loadDataFromSourceToTemp` Logic to `SourceToTempLoader::load()`:**
    *   [ ] Copy method body from `GenericTableSyncer::loadDataFromSourceToTemp` to `SourceToTempLoader::load`.
    *   [ ] Ensure correct use of `$this->schemaManager` and `$this->logger`.
*   [ ] **Move Helper Methods to `SourceToTempLoader`:**
    *   [ ] Copy `ensureDatetimeValues()` from `GenericTableSyncer` to `SourceToTempLoader`.
        *   [ ] Set visibility to `protected` (or `private`).
        *   [ ] Verify internal logger usage.
    *   [ ] Copy `isDateEffectivelyZeroOrInvalid()` from `GenericTableSyncer` to `SourceToTempLoader`.
        *   [ ] Set visibility to `protected` (or `private`).
    *   [ ] Copy `isDateEmptyOrInvalid()` from `GenericTableSyncer` to `SourceToTempLoader`.
        *   [ ] Set visibility to `protected` (or `private`).
    *   [ ] Copy `getDbalParamType()` from `GenericTableSyncer` to `SourceToTempLoader`.
        *   [ ] Set visibility to `protected` (or `private`).
    *   [ ] Copy `dbalTypeToParameterType()` from `GenericTableSyncer` to `SourceToTempLoader`.
        *   [ ] Set visibility to `protected` (or `private`).
*   [ ] **Update PHPDoc for `SourceToTempLoader` and its methods.**

## Phase 3: Implement `TempToLiveSynchronizer`

*   [ ] **Constructor Implementation:**
    *   [ ] Implement constructor to accept `?LoggerInterface $logger = null`.
    *   [ ] Store dependency as `private readonly LoggerInterface $logger`.
    *   [ ] Initialize `$this->logger` with `$logger ?? new NullLogger()`.
*   [ ] **Move `synchronizeTempToLive` Logic to `TempToLiveSynchronizer::synchronize()`:**
    *   [ ] Copy method body from `GenericTableSyncer::synchronizeTempToLive` to `TempToLiveSynchronizer::synchronize`.
    *   [ ] Ensure correct use of `$this->logger`.
*   [ ] **Update PHPDoc for `TempToLiveSynchronizer` and its methods.**

## Phase 4: Refactor `GenericTableSyncer`

*   [ ] **Update Constructor:**
    *   [ ] Modify constructor signature to include `SourceToTempLoader $sourceToTempLoader` and `TempToLiveSynchronizer $tempToLiveSynchronizer`.
    *   [ ] Store new dependencies as `private readonly` properties.
*   [ ] **Update `sync()` Method:**
    *   [ ] Replace internal call to `loadDataFromSourceToTemp()` with `$this->sourceToTempLoader->load($config);`.
    *   [ ] Replace internal call to `synchronizeTempToLive()` with `$this->tempToLiveSynchronizer->synchronize($config, $currentBatchRevisionId, $report);`.
*   [ ] **Remove Old Methods from `GenericTableSyncer`:**
    *   [ ] Delete `loadDataFromSourceToTemp()`.
    *   [ ] Delete `synchronizeTempToLive()`.
    *   [ ] Delete `ensureDatetimeValues()`.
    *   [ ] Delete `isDateEffectivelyZeroOrInvalid()`.
    *   [ ] Delete `isDateEmptyOrInvalid()`.
    *   [ ] Delete `getDbalParamType()`.
    *   [ ] Delete `dbalTypeToParameterType()`.
*   [ ] **Update PHPDoc for `GenericTableSyncer` constructor and `sync()` method.**

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

