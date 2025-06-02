## Implementation Plan: Deferred Indexing for Initial Loads

**Goal:** Optimize initial data synchronization by deferring the creation of secondary indexes on the live table until after the bulk data insertion is complete.

**Affected Files:**

*   `src/Service/GenericTableSyncer.php`
*   (Potentially minor logging or helper in `src/Service/GenericSchemaManager.php` - optional)

---

### Step 1: Modify `GenericTableSyncer::sync()`

**File:** `src/Service/GenericTableSyncer.php`

**Current Relevant `sync()` Flow (Simplified):**

```php
// ...
// 0. View creation (if configured)
// 1. $this->schemaManager->ensureLiveTable($config);
// 1.1. $this->schemaManager->ensureDeletedLogTable($config); (if enabled)
// 2. $this->schemaManager->prepareTempTable($config);
// 3. $this->sourceToTempLoader->load($config);
// 4. $this->dataHasher->addHashesToTempTable($config);
// 5. $this->indexManager->addIndicesToTempTableAfterLoad($config);
// 6. $this->indexManager->addIndicesToLiveTable($config); // <--- CURRENTLY ADDS ALL LIVE INDEXES
// 7. $this->tempToLiveSynchronizer->synchronize($config, $currentRevisionId, $report);
// 8. $this->schemaManager->dropTempTable($config);
// ...
```

**Proposed Changes to `GenericTableSyncer::sync()`:**

```php
public function sync(TableSyncConfigDTO $config, int $currentRevisionId): SyncReportDTO
{
    $report = new SyncReportDTO();
    $liveTableWasInitiallyEmpty = false; // Flag to track initial state

    try {
        $this->logger->info('Starting sync process.', [
            'source'            => $config->sourceTableName,
            'target'            => $config->targetLiveTableName,
            'currentRevisionId' => $currentRevisionId,
            'createViewConfig'  => $config->shouldCreateView,
        ]);

        // 0. Ensure source view exists if configured
        if ($config->shouldCreateView) {
            $report->viewCreationAttempted = true;
            $this->viewManager->ensureSourceView($config);
            $report->viewCreationSuccessful = true;
        }

        // 1. Ensure live table exists with correct schema (PK will be created here)
        $this->schemaManager->ensureLiveTable($config);

        // 1.1. Determine if the live table is empty BEFORE any potential secondary index creation
        //      or major data operations.
        try {
            $quotedLiveTableName = $config->targetConnection->quoteIdentifier($config->targetLiveTableName);
            $countResult = $config->targetConnection->fetchOne("SELECT COUNT(*) FROM " . $quotedLiveTableName);
            if (is_numeric($countResult) && (int)$countResult === 0) {
                $liveTableWasInitiallyEmpty = true;
                $this->logger->info("Live table '{$config->targetLiveTableName}' is confirmed to be empty before sync operations. Will defer secondary index creation.");
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                "Could not definitively determine if live table '{$config->targetLiveTableName}' is empty. Proceeding with standard index creation if necessary. Error: " . $e->getMessage(),
                ['exception_class' => get_class($e)]
            );
            // If we can't determine, assume it's not empty to be safe and follow old path for indexes.
            // Or, one could choose to throw an exception if this check is critical.
            // For this optimization, proceeding as if not empty is safer.
        }

        // 1.2. Ensure deleted log table exists if deletion logging is enabled
        if ($config->enableDeletionLogging) {
            $this->logger->info('Deletion logging is enabled, ensuring deleted log table exists.');
            $this->schemaManager->ensureDeletedLogTable($config);
        }

        // 1.3. <<<< MODIFIED LOGIC FOR LIVE TABLE INDEXES >>>>
        // If the live table was NOT initially empty, it's an incremental sync.
        // Create/ensure secondary indexes on the live table now, before temp table operations and synchronization.
        if (!$liveTableWasInitiallyEmpty) {
            $this->logger->info("Live table '{$config->targetLiveTableName}' is not empty or emptiness could not be confirmed. Ensuring secondary indexes before synchronization.");
            $this->indexManager->addIndicesToLiveTable($config);
        }

        // 2. Prepare temp table (drop if exists, create new)
        $this->schemaManager->prepareTempTable($config);

        // 3. Load data from source to temp
        $this->sourceToTempLoader->load($config);

        // 4. Add hashes to temp table rows for change detection
        $this->dataHasher->addHashesToTempTable($config);

        // 5. Add indexes to temp table for faster sync
        $this->indexManager->addIndicesToTempTableAfterLoad($config);

        // <<<< STEP 6 (Original $this->indexManager->addIndicesToLiveTable($config);) IS NOW HANDLED CONDITIONALLY ABOVE AND BELOW >>>>

        // 7. Synchronize temp to live (insert/update/delete)
        $this->tempToLiveSynchronizer->synchronize($config, $currentRevisionId, $report);

        // 8. <<<< NEW LOGIC FOR POST-INITIAL-LOAD INDEXING >>>>
        // If the live table was initially empty AND the synchronization resulted in an initial insert,
        // then create the secondary indexes on the live table NOW.
        if ($liveTableWasInitiallyEmpty && $report->initialInsertCount > 0) {
            $this->logger->info(
                "Initial load completed with {$report->initialInsertCount} rows. Creating/ensuring secondary indexes on live table '{$config->targetLiveTableName}' post-load."
            );
            try {
                $this->indexManager->addIndicesToLiveTable($config);
                $this->logger->info("Secondary indexes successfully created/ensured on live table '{$config->targetLiveTableName}' post-initial-load.");
            } catch (\Throwable $e) {
                // Log the error, but the sync itself was successful.
                // This is a critical step for performance on subsequent runs, so a warning or error is appropriate.
                $this->logger->error(
                    "Failed to create/ensure secondary indexes on live table '{$config->targetLiveTableName}' post-initial-load. This may impact future sync performance. Error: " . $e->getMessage(),
                    [
                        'exception_class' => get_class($e),
                        'exception_trace' => $e->getTraceAsString(),
                    ]
                );
                // Decide if this should be a critical failure of the sync or just a warning.
                // For now, let's log as error but not re-throw to fail the whole sync, as data is in.
                // You might want to add to the report.
                $report->addLogMessage("Error: Failed to create secondary indexes on live table post-initial-load: " . $e->getMessage(), 'error');
            }
        } elseif ($liveTableWasInitiallyEmpty && $report->initialInsertCount === 0) {
            // This case implies the live table was empty, and after the full source-to-temp-to-live,
            // still no rows were inserted (e.g., source was also empty).
            // We should still ensure indexes are present for future non-empty runs.
             $this->logger->info(
                "Live table was initially empty and remained empty after sync. Ensuring secondary indexes on live table '{$config->targetLiveTableName}'."
            );
            $this->indexManager->addIndicesToLiveTable($config); // Create them anyway
        }


        // 9. Drop temp table to clean up (was step 8)
        $this->schemaManager->dropTempTable($config);

        $this->logger->info('Sync completed.', [ // Adjusted message slightly
            'inserted'               => $report->insertedCount,
            'updated'                => $report->updatedCount,
            'deleted'                => $report->deletedCount,
            'initialInsert'          => $report->initialInsertCount,
            'loggedDeletions'        => $report->loggedDeletionsCount,
            'viewCreationAttempted'  => $report->viewCreationAttempted,
            'viewCreationSuccessful' => $report->viewCreationSuccessful,
            'summary'                => $report->getSummary(),
        ]);
        return $report;

    } catch (\Throwable $e) {
        // ... (existing error handling and temp table cleanup) ...
        // No changes needed in the catch block for this specific feature
        $this->logger->error('Sync process failed: ' . $e->getMessage(), [
            'exception_class' => get_class($e),
            'exception_trace' => $e->getTraceAsString(), // Consider logging only in debug mode for production
            'source'          => $config->sourceTableName,
            'target'          => $config->targetLiveTableName,
        ]);

        try {
            $this->schemaManager->dropTempTable($config);
            $this->logger->info('Temp table dropped successfully during error handling.');
        } catch (\Throwable $cleanupException) {
            $this->logger->warning('Failed to drop temp table during error handling: ' . $cleanupException->getMessage(), [
                'cleanup_exception_class' => get_class($cleanupException),
            ]);
        }

        if (!($e instanceof TableSyncerException) && !($e instanceof \TopdataSoftwareGmbh\TableSyncer\Exception\ConfigurationException)) {
            throw new TableSyncerException('Sync process failed unexpectedly: ' . $e->getMessage(), (int)$e->getCode(), $e);
        }
        throw $e;
    }
}
```

---

### Step 2: Review `GenericSchemaManager::ensureLiveTable()`

**File:** `src/Service/GenericSchemaManager.php`

*   **No changes are strictly required here for Option 1.** `ensureLiveTable()` already focuses on creating the table structure and its primary key (e.g., `_syncer_id`). It does *not* create the other secondary/business indexes; `GenericIndexManager` does that. This separation is good and works well with the proposed plan.

---

### Step 3: Review `GenericIndexManager::addIndicesToLiveTable()`

**File:** `src/Service/GenericIndexManager.php`

*   **No changes are strictly required here for Option 1.** This method is responsible for adding the content hash index and the unique business PK index (if applicable). Its logic remains the same. The change is *when* it's called by `GenericTableSyncer`.
*   **Ensure Idempotency:** The method already checks `isset($indexes[$indexName])` before creating an index. This is good, as it might be called when some indexes already exist (e.g., if the "is empty" check failed and it runs before sync, then again after an initial load if the logic path allows).

---

### Step 4: Review `TempToLiveSynchronizer::synchronize()`

**File:** `src/Service/TempToLiveSynchronizer.php`

*   **No changes are strictly required here for Option 1.** This service's responsibility for the initial load is to perform the `INSERT INTO ... SELECT ...`. It doesn't need to be concerned with when the secondary indexes are created if `GenericTableSyncer` handles the orchestration.
*   The `initialInsertCount` property on `SyncReportDTO` is crucial for `GenericTableSyncer` to know if the post-load indexing step is necessary.

---

### Step 5: Testing

1.  **Test Case 1: Initial Sync (Empty Live Table)**
    *   Ensure the target live table is empty.
    *   Run the sync.
    *   **Verify Logs:**
        *   Log message indicating "Live table ... is confirmed to be empty... Will defer secondary index creation."
        *   Log message indicating `TempToLiveSynchronizer` performed an "initial bulk import".
        *   Log message *after* the `synchronize` call indicating "Initial load completed... Creating/ensuring secondary indexes on live table... post-load."
    *   **Verify Database:**
        *   Data is correctly inserted into the live table.
        *   All indexes (PK, content hash, business PK unique index) are present on the live table *after* the sync completes.
    *   **Performance:** Compare the time taken for this initial sync with the time taken by the old version (if possible). Expect a significant improvement.

2.  **Test Case 2: Initial Sync (Empty Live Table, Empty Source)**
    *   Ensure the target live table is empty.
    *   Ensure the source (e.g., the view `_syncer_vw_distributor_file_artnrs`) returns no rows.
    *   Run the sync.
    *   **Verify Logs:**
        *   Log message indicating "Live table ... is confirmed to be empty..."
        *   `initialInsertCount` in the report should be 0.
        *   Log message indicating "Live table was initially empty and remained empty after sync. Ensuring secondary indexes..."
    *   **Verify Database:**
        *   Live table remains empty.
        *   All indexes are present on the live table.

3.  **Test Case 3: Incremental Sync (Live Table Has Data)**
    *   Ensure the live table has some data from a previous sync.
    *   Run the sync (with some new/updated/deleted data in the source).
    *   **Verify Logs:**
        *   Log message indicating "Live table ... is not empty... Ensuring secondary indexes before synchronization." (or the "emptiness could not be confirmed" message if the `COUNT(*)` query fails).
        *   The log message about "post-load" index creation should *not* appear.
    *   **Verify Database:**
        *   Data is correctly updated/inserted/deleted.
        *   Indexes remain correct.
    *   **Functionality:** Ensure updates, inserts, and deletes work as expected.

4.  **Test Case 4: Error during post-load index creation**
    *   Simulate an error during `indexManager->addIndicesToLiveTable($config)` when called *after* an initial load. (e.g., by temporarily making an index name invalid or a permission issue if testable).
    *   **Verify Logs:**
        *   Sync itself should report data inserted successfully.
        *   An error message should be logged about the failure to create secondary indexes.
        *   The overall sync process should still be marked as completed in the final log, but the report might contain the error.
    *   **Verify Database:** Data should be present in the live table, but secondary indexes might be missing.

---

### Step 6: Documentation (Internal or Changelog)

*   Document this optimization, especially if it changes observable behavior (like the timing of index creationログ).
*   Update CHANGELOG.md mentioning the performance improvement for initial synchronizations.

---

This plan provides a structured way to implement the deferred indexing strategy, primarily by modifying the orchestration logic within `GenericTableSyncer.php`. Remember to handle potential edge cases and ensure robust logging.

