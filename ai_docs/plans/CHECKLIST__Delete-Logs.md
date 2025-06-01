# Checklist: Enhance `table-syncer` for Optional Deletion Logging (v1.1.0)

## Phase 1: Configuration & DTO Updates

### Task 1.1: Modify `src/DTO/TableSyncConfigDTO.php`
- [x] Add `public bool $enableDeletionLogging = false;` property.
- [x] Add `public ?string $targetDeletedLogTableName = null;` property.
- [x] Update `__construct` signature to include new optional parameters:
    - `bool $enableDeletionLogging = false`
    - `?string $targetDeletedLogTableName = null`
    - (Consider placement towards the end of the parameter list)
- [x] Initialize new properties in `__construct`.
- [x] Implement logic in `__construct` to set default `targetDeletedLogTableName` (e.g., `<targetLiveTableName>_deleted_log`) if:
    - `enableDeletionLogging` is `true` AND
    - `targetDeletedLogTableName` is `null`.
- [x] Add validation in `__construct`: If `enableDeletionLogging` is true, `targetLiveTableName` must be set (for default log table name generation).
- [x] Add validation in `__construct`: If `enableDeletionLogging` is true, `targetDeletedLogTableName` (after defaulting) must not be empty.

### Task 1.2: Modify `src/DTO/SyncReportDTO.php`
- [x] Add `public int $loggedDeletionsCount = 0;` property.
- [x] Update `__construct` signature to include `int $loggedDeletionsCount = 0`.
- [x] Initialize `loggedDeletionsCount` in `__construct`.
- [x] Update `getSummary()` method to include `loggedDeletionsCount` in the summary string (e.g., `Logged Deletions: %d`).
- [x] (Optional but Recommended) Consider including `initialInsertCount` in `getSummary()` if non-zero for consistency.

## Phase 2: Schema Management

### Task 2.1: Modify `src/Service/GenericSchemaManager.php`
- [x] Create new public method `ensureDeletedLogTable(TableSyncConfigDTO $config): void`.
- [x] In `ensureDeletedLogTable()`:
    - [x] Add initial check: return if `!$config->enableDeletionLogging` or `empty($config->targetDeletedLogTableName)`.
    - [x] Log method entry and purpose.
    - [x] Get `$targetConn` and `$dbalSchemaManager`.
    - [x] Check if `$logTableName` (from `config->targetDeletedLogTableName`) already exists using `$dbalSchemaManager->tablesExist()`.
        - [x] If exists, log and return (schema validation of existing table is for a future version).
    - [x] If not exists, log creation intent.
    - [x] Create a new `Doctrine\DBAL\Schema\Table` object for `$logTableName`.
    - [x] Define columns for the deletion log table:
        - [x] `log_id`: `Types::BIGINT`, `autoincrement` => true, `notnull` => true.
        - [x] Set `log_id` as the primary key.
        - [x] `deleted_syncer_id`: Use `config->targetIdColumnType`, `notnull` => true.
        - [x] `deleted_at_revision_id`: `Types::INTEGER`, `notnull` => true.
        - [x] `deletion_timestamp`: `Types::DATETIME_MUTABLE`, `notnull` => true, `default` => 'CURRENT_TIMESTAMP'.
    - [x] Add indexes to the log table:
        - [x] Index on `deleted_at_revision_id`.
        - [x] Index on `deleted_syncer_id`.
    - [x] Call `$dbalSchemaManager->createTable($table)`.
    - [x] Log success or failure of table creation (handle exceptions, re-throw).

## Phase 3: Synchronization Logic

### Task 3.1: Modify `src/Service/TempToLiveSynchronizer.php`
- [x] Modify `synchronize()` method.
- [x] Locate the "Handle Deletes" section (`--- C. Handle Deletes ---`).
- [x] **Before** the actual `DELETE FROM {$liveTable}` SQL execution:
    - [x] Add a conditional block: `if ($config->enableDeletionLogging && !empty($config->targetDeletedLogTableName))`.
    - [x] Inside the block:
        - [x] Log intent to log deletions.
        - [x] Get quoted identifiers for `deletedLogTableIdentifier`, `liveTableIdentifierForLog` (aliased 'lt'), `tempTableIdentifierForLog` (aliased 'tt').
        - [x] Get quoted `liveSyncerIdCol` from `config->metadataColumns->id`.
        - [x] Define `$logTableInsertCols` (quoted: `deleted_syncer_id`, `deleted_at_revision_id`, `deletion_timestamp`).
        - [x] Construct `$sqlLogDeletes` query:
            - [x] `INSERT INTO {$deletedLogTableIdentifier} (...)`
            - [x] `SELECT lt.{$liveSyncerIdCol}, ?, CURRENT_TIMESTAMP`
            - [x] `FROM {$liveTableIdentifierForLog} lt`
            - [x] `LEFT JOIN {$tempTableIdentifierForLog} tt ON {$logJoinConditionStr}` (ensure `$logJoinConditionStr` uses aliases 'lt' and 'tt' based on `$config->getTargetPrimaryKeyColumns()`).
            - [x] `WHERE {$tempTableBusinessPkColForNullCheck} IS NULL` (ensure this uses alias 'tt' and first PK column).
        - [x] Prepare `$paramsForLog = [$currentBatchRevisionId]`.
        - [x] Execute `$targetConn->executeStatement($sqlLogDeletes, $paramsForLog)`.
        - [x] Update `report->loggedDeletionsCount` with the result.
        - [x] Call `report->addLogMessage()` for logged deletions.
        - [x] Log success or failure of logging (handle exceptions, re-throw `TableSyncerException`).
    - [x] Else (logging not enabled):
        - [x] Log that deletion logging is skipped.
- [x] Ensure the logic/conditions for identifying rows for deletion (for logging) are IDENTICAL to the logic for the actual `DELETE` statement.

## Phase 4: Orchestration

### Task 4.1: Modify `src/Service/GenericTableSyncer.php`
- [x] Modify `sync()` method.
- [x] After `$this->schemaManager->ensureLiveTable($config);`:
    - [x] Add conditional call: `if ($config->enableDeletionLogging)`.
    - [x] Inside the condition:
        - [x] Log intent to ensure deleted log table.
        - [x] Call `$this->schemaManager->ensureDeletedLogTable($config);`.

## Phase 5: Documentation & Testing

### Task 5.1: Update `README.md`
- [x] Add a new section: "Deletion Logging (Optional)".
- [x] Explain the feature.
- [x] Detail new `TableSyncConfigDTO` properties:
    - [x] `enableDeletionLogging`
    - [x] `targetDeletedLogTableName` (mention default and override).
- [x] Provide a code example for configuring deletion logging.
- [x] Briefly describe the schema of the created deletion log table.

### Task 5.2: Implement Unit and Integration Tests
- [ ] **`TableSyncConfigDTOTest.php`**:
    - [ ] Test constructor with new deletion logging parameters.
    - [ ] Test default `targetDeletedLogTableName` generation.
    - [ ] Test validation related to new properties.
- [ ] **`GenericSchemaManagerTest.php`**:
    - [ ] Test `ensureDeletedLogTable()`:
        - [ ] Skips if `enableDeletionLogging` is false.
        - [ ] Calls `createTable()` if table doesn't exist and logging enabled.
        - [ ] Verify `Table` object for `createTable()` has correct schema.
        - [ ] Skips `createTable()` if table already exists.
- [ ] **`TempToLiveSynchronizerTest.php`**:
    - [ ] Test `synchronize()`:
        - [ ] If logging enabled:
            - [ ] Verify `INSERT` to log table *before* `DELETE` from live table.
            - [ ] Verify correct `currentBatchRevisionId` used.
            - [ ] Verify `SyncReportDTO->loggedDeletionsCount` updated.
        - [ ] If logging disabled:
            - [ ] Verify no `INSERT` to log table.
- [ ] **`GenericTableSyncerTest.php` (Integration Style)**:
    - [ ] Test `sync()` with `enableDeletionLogging = true`.
    - [ ] Verify `ensureDeletedLogTable` is called on schema manager mock.
    - [ ] (Full Integration) Run sync involving deletions and verify log table population (e.g., SQLite).

### Task 5.3: Update `CHANGELOG.md`
- [x] Add an entry for version `1.1.0` detailing the new "Optional Deletion Logging" feature.
    - [x] Mention new configuration options in `TableSyncConfigDTO`.
    - [x] Mention new `loggedDeletionsCount` in `SyncReportDTO`.
    - [x] Mention creation of `<targetLiveTableName>_deleted_log` table.

## Phase 6: Code Review & Merge
- [ ] Perform a self-review of all changes.
- [ ] (If applicable) Submit for peer review.
- [ ] Merge changes to the main branch.
- [ ] Tag a new release `v1.1.0`.
