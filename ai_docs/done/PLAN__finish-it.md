# Project Plan: Complete `topdata-software-gmbh/table-syncer`

**Objective:** Finalize the implementation of the `topdata-software-gmbh/table-syncer` PHP library, focusing on completing the core service logic according to the existing `PLAN.md`, `CHECKLIST.md`, and recent clarifications.

**Primary Target Database:** MySQL / MariaDB. PostgreSQL support is a future consideration.
**Error Handling Philosophy:** Fail loudly and early. Do not suppress errors with try-catch blocks unless recovery is meaningful. Exceptions should propagate or be re-thrown as custom `TableSyncerException` or `ConfigurationException`.
**Hashing Algorithm:** `SHA2(..., 256)` is fixed for now.

---

## Phase 0: Critical Alignment & Prerequisite Fixes

**Goal:** Ensure DTOs and core syncer class structure are correct and ready for service implementation.

1.  **`src/DTO/TableSyncConfigDTO.php` Correction:**
    *   **Action:** Add the public property `public string $placeholderDatetime = '2222-02-22 00:00:00';` (or a similar, clearly non-standard valid datetime string).
    *   **Action:** Update the constructor to accept an optional `$placeholderDatetime` string parameter and initialize the property.
    *   **Rationale:** This property is crucial for consistent handling of non-nullable datetime columns as described in project documentation and expected by other components.

2.  **`src/Service/GenericTableSyncer.php` - Dependency Injection & Core DTO Usage:**
    *   **Action:** Modify the constructor of `GenericTableSyncer` to accept `GenericSchemaManager`, `GenericIndexManager`, and `GenericDataHasher` as injected dependencies (using constructor property promotion: `private readonly`). Remove internal instantiation (`new ...()`) of these services.
    *   **Action:** Review and update all internal method calls within `GenericTableSyncer` that access properties of `$config` (the `TableSyncConfigDTO` instance). Replace direct access to non-existent properties (e.g., `$config->dataColumnsToSync`, `$config->targetMatchingKeyColumns`) with the correct public helper methods provided by `TableSyncConfigDTO` (e.g., `$config->getSourceDataColumns()`, `$config->getTargetPrimaryKeyColumns()`, `$config->getTargetDataColumns()`, `$config->getTargetColumnName()`).
    *   **Action:** In `ensureDatetimeValues` and `loadDataFromSourceToTemp`, replace any hardcoded placeholder date values (like from `GlobalAppConstants`) with `$config->placeholderDatetime`.
    *   **Action:** In `ensureDatetimeValues`, ensure it uses `$config->nonNullableDatetimeSourceColumns` (as defined in `TableSyncConfigDTO`) not an undefined `$config->nonNullableDatetimeColumns`.
    *   **Action (Optional but Recommended):** If `UtilDoctrineDbal` is an external application-specific class, consider replacing its usage (e.g., `UtilDoctrineDbal::getDatabasePlatformName()`) with methods from the library's own `TopdataSoftwareGmbh\TableSyncer\Util\DbalHelper.php` or direct DBAL platform methods for better self-containment.

---

## Phase 1: Implement `src/Service/GenericSchemaManager.php`

**Goal:** Complete all methods to manage database schema for live and temp tables.

1.  **`ensureLiveTable(TableSyncConfigDTO $config): void`:**
    *   Log entry: "Ensuring live table '{$config->targetLiveTableName}' schema."
    *   Get target connection: `$targetConn = $config->targetConnection;`
    *   Check if the live table exists: `$targetConn->createSchemaManager()->tablesExist([$config->targetLiveTableName])`.
    *   **If table does not exist:**
        *   Log: "Live table '{$config->targetLiveTableName}' does not exist. Creating..."
        *   Call `_createTable()` to create it. Pass necessary parameters: target connection, live table name, column definitions (combining data columns and live-specific metadata columns), primary key columns for the live table (which is `$config->metadataColumns->id`), and any specific index definitions for the live table.
    *   **If table *does* exist:**
        *   Log: "Live table '{$config->targetLiveTableName}' exists. Validating schema..."
        *   Introspect its schema using `$targetConn->createSchemaManager()->introspectTable($config->targetLiveTableName)`.
        *   Define expected columns:
            *   Data columns: from `$config->getTargetDataColumns()`.
            *   Metadata columns: from `getLiveTableSpecificMetadataColumns($config)`.
        *   For each expected data column and metadata column:
            *   Verify its existence in the introspected table.
            *   If a column is missing, throw a `ConfigurationException` (e.g., "Live table '{$config->targetLiveTableName}' is missing expected column '{$columnName}'.")
            *   (Optional, basic check): Briefly check type compatibility (e.g., a string type for a string, numeric for numeric). Drastic mismatches could also throw a `ConfigurationException`.
        *   Log: "Live table '{$config->targetLiveTableName}' schema validated."

2.  **`prepareTempTable(TableSyncConfigDTO $config): void`:**
    *   Log entry: "Preparing temp table '{$config->targetTempTableName}'."
    *   Call `dropTempTable($config)`.
    *   Define column definitions for the temp table. These should include:
        *   Target primary key columns (from `$config->getTargetPrimaryKeyColumns()`), using types derived from source via `getSourceColumnTypes()`.
        *   Target data columns (from `$config->getTargetDataColumns()`, excluding PKs already added), using types derived from source.
        *   Metadata columns specific to the temp table (from `getTempTableSpecificMetadataColumns($config)`).
    *   Call `_createTable()`: pass target connection, temp table name, the combined column definitions, target primary key columns (for the temp table, these are the mapped business keys), and any specific index definitions for the temp table (initially, just the PKs).

3.  **`getLiveTableSpecificMetadataColumns(TableSyncConfigDTO $config): array`:**
    *   Return an array defining metadata columns for the *live* table. Each element should be an associative array like `['name' => 'col_name', 'type' => Types::INTEGER, 'options' => [...]]`.
        *   `$config->metadataColumns->id`: type from `$config->targetIdColumnType`, options: `['autoincrement' => true, 'notnull' => true]`.
        *   `$config->metadataColumns->contentHash`: type from `$config->targetHashColumnType`, options: `['length' => $config->targetHashColumnLength, 'notnull' => true]`.
        *   `$config->metadataColumns->createdAt`: type `Types::DATETIME_MUTABLE`, options: `['notnull' => true, 'default' => $config->placeholderDatetime]` (or `CURRENT_TIMESTAMP` for MySQL if appropriate).
        *   `$config->metadataColumns->updatedAt`: type `Types::DATETIME_MUTABLE`, options: `['notnull' => true, 'default' => $config->placeholderDatetime]` (or `CURRENT_TIMESTAMP` for MySQL and potentially `ON UPDATE CURRENT_TIMESTAMP` if desired for MySQL).
        *   `$config->metadataColumns->batchRevision`: type `Types::INTEGER` (or `BIGINT`), options: `['notnull' => false]`.

4.  **`getTempTableSpecificMetadataColumns(TableSyncConfigDTO $config): array`:**
    *   Return an array defining metadata columns for the *temp* table:
        *   `$config->metadataColumns->contentHash`: type from `$config->targetHashColumnType`, options: `['length' => $config->targetHashColumnLength, 'notnull' => false]` (allow null initially, will be updated).
        *   `$config->metadataColumns->createdAt`: type `Types::DATETIME_MUTABLE`, options: `['notnull' => true, 'default' => $config->placeholderDatetime]`.

5.  **`private function _createTable(Connection $connection, string $tableName, array $columnDefinitions, array $primaryKeyColumnNames, array $indexDefinitions = []): void` (Refined Signature):**
    *   Log: "Creating table '{$tableName}'..."
    *   Use `$dbalSchemaManager = $connection->createSchemaManager();`
    *   Create `new \Doctrine\DBAL\Schema\Table($tableName);`
    *   Iterate through `$columnDefinitions`:
        *   For each definition (`['name' => 'col', 'type' => 'DBAL_TYPE', 'options' => [...]]`), add column to the table object: `$table->addColumn($def['name'], $def['type'], $def['options']);`
        *   Ensure data columns are generally `nullable` by default in their options unless explicitly configured otherwise (this might need more advanced DTO config or introspection options for `notnull`).
    *   Set primary key: `$table->setPrimaryKey($primaryKeyColumnNames);`
    *   Iterate through `$indexDefinitions` (if any) and add indexes using `$table->addIndex([...])` or `$table->addUniqueIndex([...])`.
    *   Execute: `$dbalSchemaManager->createTable($table);`
    *   Log: "Table '{$tableName}' created successfully."

6.  **`getSourceColumnTypes(TableSyncConfigDTO $config): array`:**
    *   Implement caching (`sourceTableDetailsCache`, `cachedSourceTableName`).
    *   Use `$config->sourceConnection->createSchemaManager()->introspectTable($config->sourceTableName)->getColumns()`.
    *   For each DBAL `Column` object, get its `Type` object.
    *   Map the `Type` object to a DBAL type string (e.g., `Types::STRING`) using `getDbalTypeNameFromTypeObject()`.
    *   Return an associative array: `['source_column_name' => 'DBAL_TYPE_STRING', ...]`.
    *   The `information_schema` fallback can be considered for later or specific debugging but primary reliance on DBAL introspection.

7.  **`getDbalTypeNameFromTypeObject(Type $type): string`:**
    *   Implementation: `return $type->getName();`

8.  **`mapInformationSchemaType(string $infoSchemaType, ?int $charMaxLength, ?int $numericPrecision, ?int $numericScale): string`:**
    *   Implement mappings from common MySQL/MariaDB `information_schema.COLUMNS.DATA_TYPE` values (e.g., 'varchar', 'int', 'datetime', 'text', 'decimal') to `Doctrine\DBAL\Types\Types` constants.
    *   Use `$charMaxLength`, `$numericPrecision`, `$numericScale` to differentiate (e.g., `DECIMAL` vs `FLOAT`, `TEXT` vs `STRING`).

9.  **`dropTempTable(TableSyncConfigDTO $config): void`:**
    *   Log: "Dropping temp table '{$config->targetTempTableName}' if exists."
    *   Use `$config->targetConnection->createSchemaManager()->dropTable($config->targetTempTableName);` (DBAL handles `IF EXISTS` internally or this can be checked before calling).

---

## Phase 2: Implement `src/Service/GenericIndexManager.php`

**Goal:** Complete methods for managing database indexes.

1.  **`addIndicesToTempTableAfterLoad(TableSyncConfigDTO $config): void`:**
    *   Log: "Adding post-load indices to temp table '{$config->targetTempTableName}'."
    *   Target connection: `$targetConn = $config->targetConnection;`
    *   Define indices needed:
        *   Index on target primary key columns: `['columns' => $config->getTargetPrimaryKeyColumns(), 'isUnique' => true, 'name' => 'pk_temp_' . $config->targetTempTableName]` (or similar generated name).
        *   Index on content hash column: `['columns' => [$config->metadataColumns->contentHash], 'isUnique' => false, 'name' => 'idx_temp_hash_' . $config->targetTempTableName]`.
    *   For each defined index, call `addIndexIfNotExists($targetConn, $config->targetTempTableName, $indexDef['columns'], $indexDef['isUnique'], $indexDef['name']);`.

2.  **`addIndicesToLiveTable(TableSyncConfigDTO $config): void`:**
    *   Log: "Ensuring/Adding indices to live table '{$config->targetLiveTableName}'."
    *   Target connection: `$targetConn = $config->targetConnection;`
    *   The primary key (`$config->metadataColumns->id`) is typically created with the table by `GenericSchemaManager`.
    *   Define other important indices:
        *   Index on content hash: `['columns' => [$config->metadataColumns->contentHash], 'isUnique' => false, 'name' => 'idx_live_hash_' . $config->targetLiveTableName]`.
        *   (Optional) A unique index on the *original business primary keys* if they are not the syncer's `$config->metadataColumns->id`. This uses `$config->getTargetPrimaryKeyColumns()`. `['columns' => $config->getTargetPrimaryKeyColumns(), 'isUnique' => true, 'name' => 'uniq_live_businesspk_' . $config->targetLiveTableName]`.
    *   For each defined index, call `addIndexIfNotExists($targetConn, $config->targetLiveTableName, $indexDef['columns'], $indexDef['isUnique'], $indexDef['name']);`.

3.  **`addIndexIfNotExists(Connection $connection, string $tableName, array $columns, bool $isUnique = false, ?string $indexName = null): void`:**
    *   Log: "Checking/Adding index '{$indexName}' on table '{$tableName}' for columns: " . implode(', ', $columns).
    *   Use `$dbalSchemaManager = $connection->createSchemaManager();`
    *   Get table object: `$table = $dbalSchemaManager->introspectTable($tableName);`
    *   Generate a default index name if `$indexName` is null (e.g., `($isUnique ? 'uniq_' : 'idx_') . $tableName . '_' . implode('_', $columns)`). Ensure name is not too long for DB limits.
    *   Check if index exists: `$table->hasIndex($indexName)`.
    *   If index does not exist:
        *   Log: "Index '{$indexName}' does not exist. Creating..."
        *   Use `ALTER TABLE` SQL statement (platform-specific, focus on MySQL):
            *   `CREATE INDEX {$quotedIndexName} ON {$quotedTableName} ({$quotedColumnList})`
            *   `CREATE UNIQUE INDEX {$quotedIndexName} ON {$quotedTableName} ({$quotedColumnList})`
        *   `$connection->executeStatement(...)`.
    *   Else log: "Index '{$indexName}' already exists."

---

## Phase 3: Implement `src/Service/GenericDataHasher.php`

**Goal:** Complete the content hashing logic for the temp table.

1.  **`addHashesToTempTable(TableSyncConfigDTO $config): int`:**
    *   Log: "Adding content hashes to temp table '{$config->targetTempTableName}'."
    *   Target connection: `$targetConn = $config->targetConnection;`
    *   Get target columns for hashing: `$hashSourceColumns = $config->getTargetColumnsForContentHash();`
    *   If `$hashSourceColumns` is empty, log a warning and return 0.
    *   Construct the list of columns for concatenation (MySQL syntax):
        *   `$concatArgs = [];`
        *   For each `$columnName` in `$hashSourceColumns`:
            *   `$concatArgs[] = "COALESCE(CAST(" . $targetConn->quoteIdentifier($columnName) . " AS CHAR), '')";`
        *   `$concatExpression = "CONCAT(" . implode(", '-', ", $concatArgs) . ")";` (using a separator like `'-'` is good practice).
    *   SQL Query:
        ```sql
        UPDATE {$targetConn->quoteIdentifier($config->targetTempTableName)}
        SET {$targetConn->quoteIdentifier($config->metadataColumns->contentHash)} = SHA2({$concatExpression}, 256)
        ```
    *   Execute statement: `$affectedRows = $targetConn->executeStatement($updateSql);`
    *   Log: "{$affectedRows} content hashes updated in temp table."
    *   Return `$affectedRows`.

---

## Phase 4: Finalize `src/Service/GenericTableSyncer.php`

**Goal:** Ensure the main syncer orchestration logic is robust, correct, and uses dependencies and DTOs properly.

1.  **Review `loadDataFromSourceToTemp(...)`:**
    *   Verify correct usage of `$config->placeholderDatetime` when processing columns listed in `$config->nonNullableDatetimeSourceColumns`.
    *   Ensure `dbalTypeToParameterType()` is used consistently with types from `GenericSchemaManager::getSourceColumnTypes()` for binding parameters to the temp table insert.
    *   The batching logic here is appropriate.

2.  **Review `synchronizeTempToLive(...)`:**
    *   **Remove Batching Logic:** This method uses set-based SQL. Any loop-based batching variables or logic within this method itself should be removed.
    *   **SQL Dialect:** Ensure UPDATE/DELETE/INSERT statements are standard or use MySQL/MariaDB specific syntax where necessary (e.g., `DELETE live FROM live LEFT JOIN temp ...`). The `CONVERT(... USING utf8mb4) COLLATE utf8mb4_unicode_ci` for hash comparison is MySQL-specific and can remain for now.
    *   **Quoting:** Double-check that ALL table and column identifiers in SQL strings are quoted using `$targetConn->quoteIdentifier($identifier)`.
    *   **Transactionality:** Wrap the three main SQL operations (UPDATE existing, DELETE obsolete, INSERT new) within a database transaction on `$config->targetConnection`.
        ```php
        $targetConn->beginTransaction();
        try {
            // ... SQL for UPDATE matching rows with different hash ...
            // ... SQL for DELETE rows in live not in temp ...
            // ... SQL for INSERT new rows from temp not in live ...
            $targetConn->commit();
        } catch (\Throwable $e) {
            $targetConn->rollBack();
            $this->logger->error("Error during temp to live synchronization, transaction rolled back: {$e->getMessage()}", ['exception' => $e]);
            // Rethrow as TableSyncerException, preserving original exception
            throw new \TopdataSoftwareGmbh\TableSyncer\Exception\TableSyncerException(
                "Synchronization from temp to live failed: " . $e->getMessage(), 0, $e
            );
        }
        ```
    *   **Redundant Datetime Processing:** Re-evaluate calls to `ensureDatetimeValues` on data *from the temp table*. Data in the temp table should already have datetimes correctly processed (including placeholders). This call might be unnecessary here. `ensureDatetimeValues` is primarily for source-to-temp.

3.  **Error Handling:**
    *   Reinforce "fail loudly". Critical database errors during any step should typically result in a `TableSyncerException` being thrown, possibly wrapping the original DBAL exception.

4.  **Logging:**
    *   Enhance logging with more context. At DEBUG level, consider logging SQL queries (sanitize or be mindful of sensitive data if logged).
    *   Log counts from `$report` (inserts, updates, deletes) at INFO level.

---

## Phase 5: Comprehensive Unit Testing

**Goal:** Ensure all components are thoroughly tested.

*   **DTOs:** Create/update tests for `MetadataColumnNamesDTO`, `SyncReportDTO`, `TableSyncConfigDTO` to cover constructors, defaults, validation logic (especially in `TableSyncConfigDTO`), and all helper methods.
*   **Services:**
    *   **`GenericDataHasherTest.php`:** Mock `Connection`, `LoggerInterface`. Test SQL generation for hash updates. Test with different data types, including those that might use `$config->placeholderDatetime` conceptually.
    *   **`GenericIndexManagerTest.php`:** Mock `Connection`, `LoggerInterface`. Test `addIndexIfNotExists` (index creation, skipping if exists). Test orchestration methods.
    *   **`GenericSchemaManagerTest.php`:** Mock `Connection`, `LoggerInterface`. Test DDL generation logic in `_createTable` for various column types, PKs, metadata columns (including defaults with `$config->placeholderDatetime`). Test `getSourceColumnTypes` with caching. Test `ensureLiveTable` (creation if not exists, validation if exists, exception on mismatch).
    *   **`GenericTableSyncerTest.php`:**
        *   Mock all dependencies: `GenericSchemaManager`, `GenericIndexManager`, `GenericDataHasher`, `Connection` (source/target), `LoggerInterface`.
        *   Test `sync()` orchestration (correct call order of dependencies).
        *   Test `loadDataFromSourceToTemp` (data mapping, datetime handling with placeholders, batching).
        *   Test `synchronizeTempToLive` (SQL generation for set-based UPDATE, DELETE, INSERT; transaction handling if mocked).
        *   Test `ensureDatetimeValues` extensively.

---

## Phase 6: Documentation and Finalization

*   **`README.md`:**
    *   Update usage examples to reflect constructor injection for `GenericTableSyncer`.
    *   Ensure `TableSyncConfigDTO` examples are accurate, especially concerning `placeholderDatetime`.
    *   Explain how `GenericSchemaManager` handles table creation/validation and metadata columns.
*   **`CHANGELOG.md`:** Update for the completed version.
*   **Code Style & Static Analysis:** Run `composer cs-fix` and `composer stan`; address reported issues.

---

## Phase 7: Integration Testing (New Phase - Crucial)

**Goal:** Test the entire syncer end-to-end against a real database.

*   **Setup:** Use a MySQL/MariaDB instance (e.g., via Docker or local installation).
*   **Tests:**
    *   Create `tests/Integration/GenericTableSyncerIntegrationTest.php`.
    *   Test `GenericTableSyncer::sync()` with various `TableSyncConfigDTO` configurations.
    *   Scenarios:
        *   Initial sync to an empty target table.
        *   Sync with only inserts.
        *   Sync with only updates.
        *   Sync with only deletes.
        *   Sync with a mix of inserts, updates, deletes.
        *   Sync involving non-nullable datetime columns and the `placeholderDatetime`.
        *   Test `ensureLiveTable` behavior when the live table schema is valid, and when it's invalid (e.g., missing column), verifying correct exception.
    *   Verify actual data in the target database after sync operations.
    *   Verify transaction rollback in `synchronizeTempToLive` if a simulated error occurs mid-process.

This detailed plan should provide the AI agent with clear instructions to complete the library.
