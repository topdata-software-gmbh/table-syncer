# Checklist: Deferred Indexing for Initial Loads

## Goal:
- [x] Optimize initial data synchronization by deferring the creation of secondary indexes on the live table until after the bulk data insertion is complete.

## Affected Files:
- `src/Service/GenericTableSyncer.php`
- `src/Service/GenericSchemaManager.php` (Review)
- `src/Service/GenericIndexManager.php` (Review)
- `src/Service/TempToLiveSynchronizer.php` (Review)

---

### Step 1: Modify `GenericTableSyncer::sync()` (`src/Service/GenericTableSyncer.php`)
- **Flag for Initial State:**
    - [x] Add `liveTableWasInitiallyEmpty` boolean flag, initialized to `false`.
- **Determine Live Table Emptiness (Pre-Sync):**
    - [x] Before step `1.2` (ensureDeletedLogTable), query `SELECT COUNT(*) FROM <live_table_name>`.
    - [x] If count is 0, set `liveTableWasInitiallyEmpty` to `true`.
    - [x] Log confirmation of empty live table and deferred secondary index creation.
    - [x] Implement robust error handling for the `COUNT(*)` query (e.g., log warning, assume not empty if check fails).
- **Conditional Pre-Sync Indexing:**
    - [x] After determining emptiness and before temp table preparation (between steps `1.2` and `2`), if `!liveTableWasInitiallyEmpty`:
        - [x] Log that live table is not empty (or emptiness check failed) and secondary indexes will be ensured *before* synchronization.
        - [x] Call `this->indexManager->addIndicesToLiveTable($config)`.
- **Move Original Step 6:**
    - [x] Remove or comment out the original unconditional call to `this->indexManager->addIndicesToLiveTable($config)` at its old location (original step 6).
- **Conditional Post-Sync Indexing (New Logic - Original Step 8):**
    - [x] After `this->tempToLiveSynchronizer->synchronize()` (original step 7) and before dropping temp table:
        - [x] **If `liveTableWasInitiallyEmpty` AND `report->initialInsertCount > 0`:**
            - [x] Log that initial load completed and secondary indexes are being created/ensured post-load.
            - [x] Call `this->indexManager->addIndicesToLiveTable($config)`.
            - [x] Log success of post-load index creation.
            - [x] Implement `try-catch` around this call:
                - [x] On error, log a detailed error message (e.g., "Failed to create/ensure secondary indexes... post-initial-load. This may impact future sync performance.").
                - [x] Add an error message to `SyncReportDTO` (`$report->addLogMessage(..., 'error')`).
                - [x] Confirm the sync process itself is not failed by this specific error (data is already loaded).
        - [x] **Else if `liveTableWasInitiallyEmpty` AND `report->initialInsertCount === 0`:**
            - [x] Log that live table was empty, remained empty, and secondary indexes are being ensured.
            - [x] Call `this->indexManager->addIndicesToLiveTable($config)`.
- **Adjust Step Numbering for Dropping Temp Table:**
    - [x] Ensure `this->schemaManager->dropTempTable($config)` is now effectively step 9 (or later if more steps are added).
- **Update Logging:**
    - [x] Adjust final "Sync completed" log message if necessary.
- **Error Handling (Main `try-catch`):**
    - [x] Confirm no changes are needed in the main `catch (\Throwable $e)` block for this feature.

---

### Step 2: Review `GenericSchemaManager::ensureLiveTable()` (`src/Service/GenericSchemaManager.php`)
- [x] Verify `ensureLiveTable()` correctly creates the table structure and its **primary key** (e.g., `_syncer_id`).
- [x] Confirm it does *not* create other secondary/business indexes (responsibility of `GenericIndexManager`).
- [x] (Likely no code changes required here).

---

### Step 3: Review `GenericIndexManager::addIndicesToLiveTable()` (`src/Service/GenericIndexManager.php`)
- [x] Confirm the existing logic for adding content hash index and unique business PK index is sound.
- [x] Verify idempotency: `addIndexIfNotExists` (or similar `isset($indexes[$indexName])` check) correctly prevents duplicate index creation attempts.
- [x] (Likely no code changes required here).

---

### Step 4: Review `TempToLiveSynchronizer::synchronize()` (`src/Service/TempToLiveSynchronizer.php`)
- [x] Confirm its responsibility remains focused on data operations (initial `INSERT INTO ... SELECT ...` or incremental changes).
- [x] Verify that `SyncReportDTO::$initialInsertCount` is correctly populated.
- [x] (Likely no code changes required here).

---

### Step 5: Testing
- **Test Case 1: Initial Sync (Empty Live Table, Source Has Data)**
    - [ ] Setup: Target live table is empty, source has data.
    - [ ] Run sync.
    - [ ] **Verify Logs:**
        - [ ] "Live table ... is confirmed to be empty... Will defer secondary index creation."
        - [ ] `TempToLiveSynchronizer` "initial bulk import" log.
        - [ ] *After* synchronization: "Initial load completed... Creating/ensuring secondary indexes on live table... post-load."
    - [ ] **Verify Database:**
        - [ ] Data correctly inserted.
        - [ ] All required indexes (PK, content hash, business PK unique index) present *after* sync completes.
    - [ ] (Optional) Performance: Compare time taken with the old version.

- **Test Case 2: Initial Sync (Empty Live Table, Empty Source)**
    - [ ] Setup: Target live table is empty, source has no data.
    - [ ] Run sync.
    - [ ] **Verify Logs:**
        - [ ] "Live table ... is confirmed to be empty..."
        - [ ] `report->initialInsertCount` is 0.
        - [ ] "Live table was initially empty and remained empty after sync. Ensuring secondary indexes..."
    - [ ] **Verify Database:**
        - [ ] Live table remains empty.
        - [ ] All required indexes present.

- **Test Case 3: Incremental Sync (Live Table Has Data)**
    - [ ] Setup: Live table has data from a previous sync.
    - [ ] Run sync (with some new/updated/deleted data in source).
    - [ ] **Verify Logs:**
        - [ ] "Live table ... is not empty... Ensuring secondary indexes before synchronization." (or emptiness check failure log).
        - [ ] No log about "post-load" index creation.
    - [ ] **Verify Database:**
        - [ ] Data correctly updated/inserted/deleted.
        - [ ] Indexes remain correct.
    - [ ] **Verify Functionality:** Updates, inserts, and deletes work as expected.

- **Test Case 4: Error during Post-Load Index Creation**
    - [ ] Setup: Simulate an error during `indexManager->addIndicesToLiveTable()` when called *after* an initial load (e.g., temporarily invalid index name, permission issue if testable).
    - [ ] **Verify Logs:**
        - [ ] Sync reports data inserted successfully.
        - [ ] Error message logged about the failure to create/ensure secondary indexes post-load.
        - [ ] Overall sync process still marked as "completed" in final log.
        - [ ] `SyncReportDTO` contains the error log message.
    - [ ] **Verify Database:**
        - [ ] Data is present in the live table.
        - [ ] Secondary indexes related to the error might be missing.

---

### Step 6: Documentation
- [ ] Document the deferred indexing optimization (internal notes or design documents).
- [ ] Update `CHANGELOG.md` to mention the performance improvement for initial synchronizations and the change in index creation timing.

