# Checklist: Enhance `table-syncer` for Optional Deletion Logging (v1.1.0)

## Phase 1: Configuration & DTO Updates

### Task 1.1: Modify `src/DTO/TableSyncConfigDTO.php`
- [ ] Add `public bool $enableDeletionLogging = false;` property.
- [ ] Add `public ?string $targetDeletedLogTableName = null;` property.
- [ ] Update `__construct` signature to include new optional parameters:
    - `bool $enableDeletionLogging = false`
    - `?string $targetDeletedLogTableName = null`
    - (Consider placement towards the end of the parameter list)
- [ ] Initialize new properties in `__construct`.
- [ ] Implement logic in `__construct` to set default `targetDeletedLogTableName` (e.g., `<targetLiveTableName>_deleted_log`) if:
    - `enableDeletionLogging` is `true` AND
    - `targetDeletedLogTableName` is `null`.
- [ ] Add validation in `__construct`: If `enableDeletionLogging` is true, `targetLiveTableName` must be set (for default log table name generation).
- [ ] Add validation in `__construct`: If `enableDeletionLogging` is true, `targetDeletedLogTableName` (after defaulting) must not be empty.

### Task 1.2: Modify `src/DTO/SyncReportDTO.php`
- [ ] Add `public int $loggedDeletionsCount = 0;` property.
- [ ] Update `__construct` signature to include `int $loggedDeletionsCount = 0`.
- [ ] Initialize `loggedDeletionsCount` in `__construct`.
- [ ] Update `getSummary()` method to include `loggedDeletionsCount` in the summary string (e.g., `Logged Deletions: %d`).
- [ ] (Optional but Recommended) Consider including `initialInsertCount` in `getSummary()` if non-zero for consistency.

## Phase 2: Schema Management

### Task 2.1: Modify `src/Service/GenericSchemaManager.php`
- [ ] Create new public method `ensureDeletedLogTable(TableSyncConfigDTO $config): void`.
- [ ] In `ensureDeletedLogTable()`:
    - [ ] Add initial check: return if `!$config->enableDeletionLogging` or `empty($config->targetDeletedLogTableName)`.
    - [ ] Log method entry and purpose.
    - [ ] Get `$targetConn` and `$dbalSchemaManager`.
    - [ ] Check if `$logTableName` (from `config->targetDeletedLogTableName`) already exists using `$dbalSchemaManager->tablesExist()`.
        - [ ] If exists, log and return (schema validation of existing table is for a future version).
    - [ ] If not exists, log creation intent.
    - [ ] Create a new `Doctrine\DBAL\Schema\Table` object for `$logTableName`.
    - [ ] Define columns for the deletion log table:
        - [ ] `log_id`: `Types::BIGINT`, `autoincrement` => true, `notnull` => true.
        - [ ] Set `log_id` as the primary key.
        - [ ] `deleted_syncer_id`: Use `config->targetIdColumnType`, `notnull` => true.
        - [ ] `deleted_at_revision_id`: `Types::INTEGER`, `notnull` => true.
        - [ ] `deletion_timestamp`: `Types::DATETIME_MUTABLE`, `notnull` => true, `default` => 'CURRENT_TIMESTAMP'.
    - [ ] Add indexes to the log table:
        - [ ] Index on `deleted_at_revision_id`.
        - [ ] Index on `deleted_syncer_id`.
    - [ ] Call `$dbalSchemaManager->createTable($table)`.
    - [ ] Log success or failure of table creation (handle exceptions, re-throw).

## Phase 3: Synchronization Logic

### Task 3.1: Modify `src/Service/TempToLiveSynchronizer.php`
- [ ] Modify `synchronize()` method.
- [ ] Locate the "Handle Deletes" section (`--- C. Handle Deletes ---`).
- [ ] **Before** the actual `DELETE FROM {$liveTable}` SQL execution:
    - [ ] Add a conditional block: `if ($config->enableDeletionLogging && !empty($config->targetDeletedLogTableName))`.
    - [ ] Inside the block:
        - [ ] Log intent to log deletions.
        - [ ] Get quoted identifiers for `deletedLogTableIdentifier`, `liveTableIdentifierForLog` (aliased 'lt'), `tempTableIdentifierForLog` (aliased 'tt').
        - [ ] Get quoted `liveSyncerIdCol` from `config->metadataColumns->id`.
        - [ ] Define `$logTableInsertCols` (quoted: `deleted_syncer_id`, `deleted_at_revision_id`, `deletion_timestamp`).
        - [ ] Construct `$sqlLogDeletes` query:
            - `INSERT INTO {$deletedLogTableIdentifier} (...)`
            - `SELECT lt.{$liveSyncerIdCol}, ?, CURRENT_TIMESTAMP`
            - `FROM {$liveTableIdentifierForLog} lt`
            - `LEFT JOIN {$tempTableIdentifierForLog} tt ON {$logJoinConditionStr}` (ensure `$logJoinConditionStr` uses aliases 'lt' and 'tt' based on `$config->getTargetPrimaryKeyColumns()`).
            - `WHERE {$tempTableBusinessPkColForNullCheck} IS NULL` (ensure this uses alias 'tt' and first PK column).
        - [ ] Prepare `$paramsForLog = [$currentBatchRevisionId]`.
        - [ ] Execute `$targetConn->executeStatement($sqlLogDeletes, $paramsForLog)`.
        - [ ] Update `report->loggedDeletionsCount` with the result.
        - [ ] Call `report->addLogMessage()` for logged deletions.
        - [ ] Log success or failure of logging (handle exceptions, re-throw `TableSyncerException`).
    - [ ] Else (logging not enabled):
        - [ ] Log that deletion logging is skipped.
- [ ] Ensure the logic/conditions for identifying rows for deletion (for logging) are IDENTICAL to the logic for the actual `DELETE` statement.

## Phase 4: Orchestration

### Task 4.1: Modify `src/Service/GenericTableSyncer.php`
- [ ] Modify `sync()` method.
- [ ] After `$this->schemaManager->ensureLiveTable($config);`:
    - [ ] Add conditional call: `if ($config->enableDeletionLogging)`.
    - [ ] Inside the condition:
        - [ ] Log intent to ensure deleted log table.
        - [ ] Call `$this->schemaManager->ensureDeletedLogTable($config);`.

## Phase 5: Documentation & Testing

### Task 5.1: Update `README.md`
- [ ] Add a new section: "Deletion Logging (Optional)".
- [ ] Explain the feature.
- [ ] Detail new `TableSyncConfigDTO` properties:
    - `enableDeletionLogging`
    - `targetDeletedLogTableName` (mention default and override).
- [ ] Provide a code example for configuring deletion logging.
- [ ] Briefly describe the schema of the created deletion log table.

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
- [ ] Add an entry for version `1.1.0` detailing the new "Optional Deletion Logging" feature.
    - [ ] Mention new configuration options in `TableSyncConfigDTO`.
    - [ ] Mention new `loggedDeletionsCount` in `SyncReportDTO`.
    - [ ] Mention creation of `<targetLiveTableName>_deleted_log` table.

## Phase 6: Code Review & Merge
- [ ] Perform a self-review of all changes.
- [ ] (If applicable) Submit for peer review.
- [ ] Merge changes to the main branch.
- [ ] Tag a new release `v1.1.0`.
