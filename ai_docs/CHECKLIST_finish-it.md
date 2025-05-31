# Table Syncer Completion Checklist

## Phase 0: Critical Alignment & Prerequisite Fixes

*   [ ] **`src/DTO/TableSyncConfigDTO.php` Corrections:**
    *   [ ] Add `public string $placeholderDatetime` property with a default value (e.g., `'2222-02-22 00:00:00'`).
    *   [ ] Update constructor to accept and initialize `$placeholderDatetime`.
*   [ ] **`src/Service/GenericTableSyncer.php` Refactoring:**
    *   [ ] Modify constructor for dependency injection of `GenericSchemaManager`, `GenericIndexManager`, `GenericDataHasher` (use `private readonly`).
    *   [ ] Remove internal instantiation (`new ...`) of these services.
    *   [ ] Replace direct `$config->someProperty` access with calls to `TableSyncConfigDTO` helper methods (e.g., `getSourceDataColumns()`).
    *   [ ] Replace hardcoded placeholder dates with `$config->placeholderDatetime` in `ensureDatetimeValues` and `loadDataFromSourceToTemp`.
    *   [ ] Ensure `ensureDatetimeValues` uses `$config->nonNullableDatetimeSourceColumns`.
    *   [ ] (Optional) Replace `UtilDoctrineDbal` usage with internal helpers or direct DBAL calls.

## Phase 1: Implement `src/Service/GenericSchemaManager.php`

*   [ ] **`ensureLiveTable(TableSyncConfigDTO $config)` Implementation:**
    *   [ ] Log entry for ensuring live table.
    *   [ ] Check if live table exists.
    *   [ ] If not exists: Call `_createTable()` for live table.
    *   [ ] If exists: Introspect, validate against expected data and metadata columns. Throw `ConfigurationException` on critical mismatch (missing columns).
*   [ ] **`prepareTempTable(TableSyncConfigDTO $config)` Implementation:**
    *   [ ] Log entry for preparing temp table.
    *   [ ] Call `dropTempTable($config)`.
    *   [ ] Define column structure (target PKs, target data columns, temp metadata columns).
    *   [ ] Call `_createTable()` for temp table.
*   [ ] **`getLiveTableSpecificMetadataColumns(TableSyncConfigDTO $config)` Implementation:**
    *   [ ] Return array defining `$config->metadataColumns->id` (auto-increment, PK).
    *   [ ] Return array defining `$config->metadataColumns->contentHash`.
    *   [ ] Return array defining `$config->metadataColumns->createdAt` (with default).
    *   [ ] Return array defining `$config->metadataColumns->updatedAt` (with default).
    *   [ ] Return array defining `$config->metadataColumns->batchRevision`.
*   [ ] **`getTempTableSpecificMetadataColumns(TableSyncConfigDTO $config)` Implementation:**
    *   [ ] Return array defining `$config->metadataColumns->contentHash` (nullable initially).
    *   [ ] Return array defining `$config->metadataColumns->createdAt` (with default).
*   [ ] **`_createTable(...)` Implementation:**
    *   [ ] Log table creation.
    *   [ ] Use DBAL SchemaManager and `Table` object.
    *   [ ] Add columns based on definitions (name, type, options).
    *   [ ] Set primary key(s).
    *   [ ] Add other specified indexes.
    *   [ ] Execute `createTable()`.
*   [ ] **`getSourceColumnTypes(TableSyncConfigDTO $config)` Implementation:**
    *   [ ] Implement caching.
    *   [ ] Use DBAL introspection (`introspectTable()->getColumns()`).
    *   [ ] Map `Type` object to DBAL type string using `getDbalTypeNameFromTypeObject()`.
*   [ ] **`getDbalTypeNameFromTypeObject(Type $type)` Implementation:**
    *   [ ] Implement as `return $type->getName();`.
*   [ ] **`mapInformationSchemaType(...)` Implementation:**
    *   [ ] Map common MySQL/MariaDB `DATA_TYPE` values to `Doctrine\DBAL\Types\Types`.
*   [ ] **`dropTempTable(TableSyncConfigDTO $config)` Implementation:**
    *   [ ] Log drop attempt.
    *   [ ] Use DBAL `dropTable()`.

## Phase 2: Implement `src/Service/GenericIndexManager.php`

*   [ ] **`addIndicesToTempTableAfterLoad(TableSyncConfigDTO $config)` Implementation:**
    *   [ ] Log entry.
    *   [ ] Define index for target PKs on temp table.
    *   [ ] Define index for content hash on temp table.
    *   [ ] Call `addIndexIfNotExists()` for each.
*   [ ] **`addIndicesToLiveTable(TableSyncConfigDTO $config)` Implementation:**
    *   [ ] Log entry.
    *   [ ] Define index for content hash on live table.
    *   [ ] (Optional) Define unique index for business PKs on live table.
    *   [ ] Call `addIndexIfNotExists()` for each.
*   [ ] **`addIndexIfNotExists(...)` Implementation:**
    *   [ ] Log check/add attempt.
    *   [ ] Introspect table to check if index exists by name.
    *   [ ] If not exists, execute `CREATE INDEX` or `CREATE UNIQUE INDEX` (MySQL syntax).

## Phase 3: Implement `src/Service/GenericDataHasher.php`

*   [ ] **`addHashesToTempTable(TableSyncConfigDTO $config)` Implementation:**
    *   [ ] Log entry.
    *   [ ] Get target columns for hashing from `$config->getTargetColumnsForContentHash()`.
    *   [ ] Handle empty `$hashSourceColumns` (warn, return 0).
    *   [ ] Construct `CONCAT(...)` expression with `COALESCE(CAST(column AS CHAR), '')` for each column (MySQL).
    *   [ ] Construct `UPDATE ... SET contentHash = SHA2(concat_expression, 256)` SQL.
    *   [ ] Execute and return affected rows.

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

