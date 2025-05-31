# Table Syncer Completion Checklist

## Phase 0: Critical Alignment & Prerequisite Fixes

*   [x] **`src/DTO/TableSyncConfigDTO.php` Corrections:**
    *   [x] Add `public string $placeholderDatetime` property with a default value (e.g., `'2222-02-22 00:00:00'`).
    *   [x] Update constructor to accept and initialize `$placeholderDatetime`.
*   [x] **`src/Service/GenericTableSyncer.php` Refactoring:**
    *   [x] Modify constructor for dependency injection of `GenericSchemaManager`, `GenericIndexManager`, `GenericDataHasher` (use `private readonly`).
    *   [x] Remove internal instantiation (`new ...`) of these services.
    *   [x] Replace direct `$config->someProperty` access with calls to `TableSyncConfigDTO` helper methods (e.g., `getSourceDataColumns()`).
    *   [x] Replace hardcoded placeholder dates with `$config->placeholderDatetime` in `ensureDatetimeValues` and `loadDataFromSourceToTemp`.
    *   [x] Ensure `ensureDatetimeValues` uses `$config->nonNullableDatetimeSourceColumns`.
    *   [x] (Optional) Replace `UtilDoctrineDbal` usage with internal helpers or direct DBAL calls.

## Phase 1: Implement `src/Service/GenericSchemaManager.php`

*   [x] **`ensureLiveTable(TableSyncConfigDTO $config)` Implementation:**
    *   [x] Log entry/exit.
    *   [x] Check if live table already exists and return early if so.
    *   [x] Get column definitions for target primary key columns (from source column types).
    *   [x] Get column definitions for target data columns (from source column types).
    *   [x] Add metadata column definitions from `getLiveTableSpecificMetadataColumns()`.
    *   [x] Call internal `_createTable()` method.
*   [x] **`prepareTempTable(TableSyncConfigDTO $config)` Implementation:**
    *   [x] Log entry/exit.
    *   [x] Drop existing temp table if it exists.
    *   [x] Get column definitions for target primary key columns (from source column types).
    *   [x] Get column definitions for target data columns (from source column types).
    *   [x] Add metadata column definitions from `getTempTableSpecificMetadataColumns()`.
    *   [x] Call internal `_createTable()` method.
*   [x] **`getLiveTableSpecificMetadataColumns(TableSyncConfigDTO $config)` Implementation:**
    *   [x] Return array defining `$config->metadataColumns->id` (auto-increment, PK).
    *   [x] Return array defining `$config->metadataColumns->contentHash`.
    *   [x] Return array defining `$config->metadataColumns->createdAt` (with default).
    *   [x] Return array defining `$config->metadataColumns->updatedAt` (with default).
    *   [x] Return array defining `$config->metadataColumns->batchRevision`.
*   [x] **`getTempTableSpecificMetadataColumns(TableSyncConfigDTO $config)` Implementation:**
    *   [x] Return array defining `$config->metadataColumns->contentHash` (nullable initially).
    *   [x] Return array defining `$config->metadataColumns->createdAt` (with default).
*   [x] **`_createTable(...)` Implementation:**
    *   [x] Log table creation.
    *   [x] Use DBAL SchemaManager and `Table` object.
    *   [x] Add columns based on definitions (name, type, options).
    *   [x] Set primary key(s).
    *   [x] Add other specified indexes.
    *   [x] Execute `createTable()`.
*   [x] **`getSourceColumnTypes(TableSyncConfigDTO $config)` Implementation:**
    *   [x] Implement caching.
    *   [x] Use DBAL introspection (`introspectTable()->getColumns()`).
    *   [x] Map `Type` object to DBAL type string using `getDbalTypeNameFromTypeObject()`.
*   [x] **`getDbalTypeNameFromTypeObject(Type $type)` Implementation:**
    *   [x] Implement as `return $type->getName();`.
*   [x] **`mapInformationSchemaType(...)` Implementation:**
    *   [x] Map common MySQL/MariaDB `DATA_TYPE` values to `Doctrine\DBAL\Types\Types`.
*   [x] **`dropTempTable(TableSyncConfigDTO $config)` Implementation:**
    *   [x] Log drop attempt.
    *   [x] Use DBAL `dropTable()`.

## Phase 2: Implement `src/Service/GenericIndexManager.php`

*   [x] **`addIndicesToTempTableAfterLoad(TableSyncConfigDTO $config)` Implementation:**
    *   [x] Log entry.
    *   [x] Define index for target PKs on temp table.
    *   [x] Define index for content hash on temp table.
    *   [x] Call `addIndexIfNotExists()` for each.
*   [x] **`addIndicesToLiveTable(TableSyncConfigDTO $config)` Implementation:**
    *   [x] Log entry.
    *   [x] Define index for content hash on live table.
    *   [x] (Optional) Define unique index for business PKs on live table.
    *   [x] Call `addIndexIfNotExists()` for each.
*   [x] **`addIndexIfNotExists(...)` Implementation:**
    *   [x] Log check/add attempt.
    *   [x] Introspect table to check if index exists by name.
    *   [x] If not exists, execute `CREATE INDEX` or `CREATE UNIQUE INDEX` (MySQL syntax).

## Phase 3: Implement `src/Service/GenericDataHasher.php`

*   [x] **`addHashesToTempTable(TableSyncConfigDTO $config)` Implementation:**
    *   [x] Log entry.
    *   [x] Get target columns for hashing from `$config->getTargetColumnsForContentHash()`.
    *   [x] Handle empty `$hashSourceColumns` (warn, return 0).
    *   [x] Construct `CONCAT(...)` expression with `COALESCE(CAST(column AS CHAR), '')` for each column (MySQL).
    *   [x] Construct `UPDATE ... SET contentHash = SHA2(concat_expression, 256)` SQL.
    *   [x] Execute and return affected rows.

## Phase 4: Finalize `src/Service/GenericTableSyncer.php`

*   [ ] **Review `loadDataFromSourceToTemp(...)`:**
    *   [ ] Verify correct usage of `$config->placeholderDatetime`.
    *   [ ] Verify consistent use of `dbalTypeToParameterType()` with `getSourceColumnTypes()`.
*   [ ] **Review `synchronizeTempToLive(...)`:**
    *   [ ] Remove any internal batching logic.
    *   [ ] Ensure SQL (UPDATE, DELETE, INSERT SELECT) is MySQL/MariaDB focused.
    *   [ ] Verify all identifiers are quoted (`$targetConn->quoteIdentifier()`).
    *   [ ] Implement transactionality (begin, commit, rollback on error).
    *   [ ] Re-evaluate/remove redundant `ensureDatetimeValues` calls on data from temp table.
*   [ ] **Error Handling:**
    *   [ ] Ensure "fail loudly" principle: critical errors throw `TableSyncerException` (wrapping original if useful).
*   [ ] **Logging:**
    *   [ ] Add/enhance contextual logging (DEBUG for SQL, INFO for counts).

## Phase 5: Comprehensive Unit Testing

*   [ ] **DTO Tests:**
    *   [ ] `MetadataColumnNamesDTOTest.php`: Test constructor, defaults.
    *   [ ] `SyncReportDTOTest.php`: Test constructor, log methods, summary.
    *   [ ] `TableSyncConfigDTOTest.php`: Test constructor, all validation logic, defaults, all helper methods.
*   [ ] **Service Tests:**
    *   [ ] **`GenericDataHasherTest.php`:**
        *   [ ] Mock dependencies.
        *   [ ] Test SQL generation for hash updates.
        *   [ ] Test with various data types.
    *   [ ] **`GenericIndexManagerTest.php`:**
        *   [ ] Mock dependencies.
        *   [ ] Test `addIndexIfNotExists()` (creation/skip logic).
        *   [ ] Test orchestration methods.
    *   [ ] **`GenericSchemaManagerTest.php`:**
        *   [ ] Mock dependencies.
        *   [ ] Test `_createTable()` DDL (column types, PKs, metadata, defaults).
        *   [ ] Test `getSourceColumnTypes()` (with cache).
        *   [ ] Test `ensureLiveTable()` (creation, validation, exception on mismatch).
    *   [ ] **`GenericTableSyncerTest.php`:**
        *   [ ] Mock all dependencies.
        *   [ ] Test `sync()` orchestration.
        *   [ ] Test `loadDataFromSourceToTemp()` (mapping, datetime, batching).
        *   [ ] Test `synchronizeTempToLive()` (SQL for set-based ops, transaction mock).
        *   [ ] Test `ensureDatetimeValues()` thoroughly.

## Phase 6: Documentation and Finalization

*   [ ] **`README.md` Updates:**
    *   [ ] Reflect constructor injection for `GenericTableSyncer`.
    *   [ ] Update `TableSyncConfigDTO` examples (`placeholderDatetime`).
    *   [ ] Explain `GenericSchemaManager`'s role in table handling.
*   [ ] **`CHANGELOG.md` Update:**
    *   [ ] Add entry for the completed/fixed version.
*   [ ] **Code Style & Static Analysis:**
    *   [ ] Run `composer cs-fix`.
    *   [ ] Run `composer stan` and address issues.

## Phase 7: Integration Testing

*   [ ] **Setup MySQL/MariaDB Test Environment.**
*   [ ] **Create `tests/Integration/GenericTableSyncerIntegrationTest.php`.**
*   [ ] **Test Scenarios for `GenericTableSyncer::sync()`:**
    *   [ ] Initial sync (empty target).
    *   [ ] Inserts only.
    *   [ ] Updates only.
    *   [ ] Deletes only.
    *   [ ] Mixed operations.
    *   [ ] Non-nullable datetime columns with `placeholderDatetime`.
*   [ ] **Test `ensureLiveTable()` behavior:**
    *   [ ] Valid existing schema.
    *   [ ] Invalid existing schema (e.g., missing column) -> verify exception.
*   [ ] **(If possible) Test Transaction Rollback in `synchronizeTempToLive()` with simulated mid-process error.**
*   [ ] **Verify data integrity in target database after each test.**

