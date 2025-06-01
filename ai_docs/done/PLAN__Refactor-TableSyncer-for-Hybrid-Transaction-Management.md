# Plan: Refactor TableSyncer for Hybrid Transaction Management

**Goal:** Modify the TableSyncer library so that `TempToLiveSynchronizer` manages its own database transaction for atomicity during the live table synchronization, while `GenericTableSyncer` no longer manages an overarching transaction for DML operations, thereby avoiding conflicts with DDL auto-commits.

**Affected Files:**

1.  `src/Service/TempToLiveSynchronizer.php`
2.  `src/Service/GenericTableSyncer.php`

---

## Part 1: Modify `TempToLiveSynchronizer.php`

**Objective:** Implement transaction management within the `synchronize` method of `TempToLiveSynchronizer`.

**File:** `src/Service/TempToLiveSynchronizer.php`

**Steps:**

1.  **Locate the `synchronize` method:**
    ```php
    public function synchronize(TableSyncConfigDTO $config, int $currentBatchRevisionId, SyncReportDTO $report): void
    {
        // ... existing code ...
    }
    ```

2.  **Initialize `transactionStartedByThisMethod` flag:**
    At the beginning of the `synchronize` method, before any database operations:
    ```php
    $targetConn = $config->targetConnection;
    $transactionStartedByThisMethod = false;
    // (Keep existing logger and variable initializations like $meta, $liveTable, $tempTable)
    ```

3.  **Wrap the core DML logic in a `try...catch` block for transaction management:**
    This block will contain all the logic that performs `INSERT`, `UPDATE`, `DELETE` on the live table (i.e., the existing initial import logic and the standard sync logic for updates, deletes, and inserts).

4.  **Start Transaction:**
    Inside the `try` block, before any DML operations on the live table:
    ```php
    if (!$targetConn->isTransactionActive()) {
        $targetConn->beginTransaction();
        $transactionStartedByThisMethod = true;
        $this->logger->debug('Transaction started within TempToLiveSynchronizer for live table synchronization.');
    }
    ```

5.  **Commit Transaction:**
    At the end of the `try` block, after all DML operations have been successfully prepared and would have been executed:
    ```php
    if ($transactionStartedByThisMethod && $targetConn->isTransactionActive()) {
        $targetConn->commit();
        $this->logger->debug('Transaction committed within TempToLiveSynchronizer for live table synchronization.');
    }
    ```

6.  **Rollback Transaction on Error:**
    In the `catch (\Throwable $e)` block:
    ```php
    if ($transactionStartedByThisMethod && $targetConn->isTransactionActive()) {
        try {
            $targetConn->rollBack();
            $this->logger->warning('Transaction rolled back within TempToLiveSynchronizer due to an error.', ['exception_message' => $e->getMessage(), 'exception_class' => get_class($e)]);
        } catch (\Throwable $rollbackException) {
            $this->logger->error(
                'Failed to roll back transaction in TempToLiveSynchronizer: ' . $rollbackException->getMessage(),
                ['original_exception_message' => $e->getMessage(), 'rollback_exception_class' => get_class($rollbackException)]
            );
        }
    }
    // Re-throw the original exception so GenericTableSyncer or the caller is aware of the failure.
    throw $e;
    ```

7.  **Final Structure of `synchronize` method:**
    ```php
    public function synchronize(TableSyncConfigDTO $config, int $currentBatchRevisionId, SyncReportDTO $report): void
    {
        $this->logger->debug('Synchronizing temp table to live table (transaction managed internally)', [
            'batchRevisionId' => $currentBatchRevisionId,
            'liveTable'       => $config->targetLiveTableName,
            'tempTable'       => $config->targetTempTableName
        ]);

        $targetConn = $config->targetConnection;
        $transactionStartedByThisMethod = false;

        // Existing variable initializations ($meta, $liveTable, $tempTable etc.) remain here

        try {
            if (!$targetConn->isTransactionActive()) {
                $targetConn->beginTransaction();
                $transactionStartedByThisMethod = true;
                $this->logger->debug('Transaction started within TempToLiveSynchronizer for live table synchronization.');
            }

            // --- A. Check if the live table is empty (Initial Import Logic) ---
            // ... (Keep existing code for initial import: SELECT COUNT(*), INSERT INTO live SELECT FROM temp) ...
            // Example:
            // $countResult = $targetConn->fetchOne("SELECT COUNT(*) FROM {$liveTable}");
            // if ($countInt === 0) {
            //     ... (initial insert SQL and execution) ...
            //     $report->addLogMessage(...);
            //     // If initial import is done, we might commit and return, or let it fall through to commit later.
            //     // For simplicity, let the main commit handle it unless there's a specific reason to commit early.
            // }

            // --- Standard Sync Logic (Live table is not empty) ---
            // This section includes B. Updates, C. Deletes, D. Inserts
            // ... (Keep existing code for building join conditions) ...
            // ... (Keep existing code for B. Handle Updates, SQL and execution) ...
            // ... (Keep existing code for C. Handle Deletes, SQL and execution) ...
            // ... (Keep existing code for D. Handle Inserts, SQL and execution) ...
            // All these DML operations should be inside this try block.

            if ($transactionStartedByThisMethod && $targetConn->isTransactionActive()) {
                $targetConn->commit();
                $this->logger->debug('Transaction committed within TempToLiveSynchronizer for live table synchronization.');
            }
        } catch (\Throwable $e) {
            if ($transactionStartedByThisMethod && $targetConn->isTransactionActive()) {
                try {
                    $targetConn->rollBack();
                    $this->logger->warning('Transaction rolled back within TempToLiveSynchronizer due to an error.', ['exception_message' => $e->getMessage(), 'exception_class' => get_class($e)]);
                } catch (\Throwable $rollbackException) {
                    $this->logger->error(
                        'Failed to roll back transaction in TempToLiveSynchronizer: ' . $rollbackException->getMessage(),
                        ['original_exception_message' => $e->getMessage(), 'rollback_exception_class' => get_class($rollbackException)]
                    );
                }
            } else if ($targetConn->isTransactionActive() && !$transactionStartedByThisMethod) {
                // Log if a transaction was active but not started by this method.
                $this->logger->warning('Error in TempToLiveSynchronizer, but transaction was managed externally and remains active.', ['exception_message' => $e->getMessage()]);
            }
            // Re-throw the original exception
            throw $e;
        }
    }
    ```

---

## Part 2: Modify `GenericTableSyncer.php`

**Objective:** Remove overarching transaction management for the DML phase from `GenericTableSyncer`, as `TempToLiveSynchronizer` now handles its own transaction.

**File:** `src/Service/GenericTableSyncer.php`

**Steps:**

1.  **Locate the `sync` method:**
    ```php
    public function sync(TableSyncConfigDTO $config, int $currentBatchRevisionId): SyncReportDTO
    {
        // ... existing code ...
    }
    ```

2.  **Remove `beginTransaction()` call related to DML phase:**
    Delete or comment out the lines that were attempting to start a transaction before calling `tempToLiveSynchronizer->synchronize()`.
    Specifically, remove the logic similar to:
    ```php
    // $transactionStartedBySyncer = false; // Remove this flag
    // if (!$targetConn->isTransactionActive()) { // Remove this block
    //     $targetConn->beginTransaction();
    //     $transactionStartedBySyncer = true;
    //     $this->logger->debug('Transaction started for temp-to-live synchronization.');
    // }
    ```

3.  **Remove `commit()` call:**
    Delete or comment out the lines that were attempting to commit the transaction after `tempToLiveSynchronizer->synchronize()`.
    Specifically, remove logic similar to:
    ```php
    // if ($transactionStartedBySyncer && $targetConn->isTransactionActive()) { // Remove this block
    //    $targetConn->commit();
    //    $this->logger->debug('Transaction committed for temp-to-live synchronization.');
    // }
    ```

4.  **Simplify `catch` block:**
    The `catch (\Throwable $e)` block in `GenericTableSyncer` no longer needs to attempt rollback for the DML phase, as `TempToLiveSynchronizer` handles its own. The main `catch` block in `GenericTableSyncer` will now primarily log the error and re-throw it, or wrap it. The `finally` block for temp table cleanup remains important.

5.  **Adjust `finally` block (if needed):** The `finally` block's primary responsibility here is dropping the temp table. This should remain.

6.  **Revised `sync` method structure in `GenericTableSyncer.php`:**
    ```php
    public function sync(TableSyncConfigDTO $config, int $currentBatchRevisionId): SyncReportDTO
    {
        $targetConn = $config->targetConnection; // Still needed for other parts if any, or can be removed if not used elsewhere in this method
        $report = new SyncReportDTO();

        $this->logger->info('Starting sync process (orchestrator)', [
            'source'        => $config->sourceTableName,
            'target'        => $config->targetLiveTableName,
            'batchRevision' => $currentBatchRevisionId
        ]);

        // The main try-catch is for the overall orchestration.
        // Errors from TempToLiveSynchronizer will propagate here if it couldn't handle them (e.g., rollback failure) or re-throws.
        try {
            // Phase 1: Schema and Temp Table Preparation (DDL heavy, will auto-commit)
            $this->logger->debug('Phase 1: Schema and Temp Table Preparation starting.');
            $this->schemaManager->ensureLiveTable($config);
            $this->schemaManager->prepareTempTable($config);
            $this->sourceToTempLoader->load($config);
            $this->dataHasher->addHashesToTempTable($config);
            $this->indexManager->addIndicesToTempTableAfterLoad($config);
            $this->indexManager->addIndicesToLiveTable($config);
            $this->logger->debug('Phase 1: Schema and Temp Table Preparation completed.');

            // Phase 2: Synchronize Temp to Live
            // TempToLiveSynchronizer now handles its own transaction for atomicity of this step.
            $this->logger->debug('Phase 2: Temp-to-Live Synchronization starting.');
            $this->tempToLiveSynchronizer->synchronize($config, $currentBatchRevisionId, $report);
            $this->logger->debug('Phase 2: Temp-to-Live Synchronization completed.');

        } catch (\Throwable $e) {
            $this->logger->error('Sync failed: ' . $e->getMessage(), [
                'exception' => $e,
                'source'    => $config->sourceTableName,
                'target'    => $config->targetLiveTableName,
                'exception_class' => get_class($e),
                // 'exception_trace_string' => $e->getTraceAsString() // Optional: for very detailed logging
            ]);
            // No specific transaction rollback here, as DDLs auto-commit and TempToLiveSynchronizer handles its own.
            // Re-throw the original exception, or wrap it if preferred.
            // If it's already a TableSyncerException, rethrow. Otherwise, wrap.
            if (!($e instanceof TableSyncerException)) {
                throw new TableSyncerException('Sync orchestration failed: ' . $e->getMessage(), 0, $e);
            }
            throw $e;
        } finally {
            // Phase 3: Cleanup (Always attempt to drop the temp table)
            $this->logger->debug('Phase 3: Cleanup starting.');
            try {
                $this->schemaManager->dropTempTable($config);
            } catch (\Throwable $cleanupException) {
                 $this->logger->error('Failed to drop temp table during final cleanup: ' . $cleanupException->getMessage(), [
                    'exception' => $cleanupException,
                    'exception_class' => get_class($cleanupException)
                ]);
                // Decide if this error should mask the original error. Usually not.
                // If an original error occurred, it would have been thrown from the main try-catch.
            }
            $this->logger->debug('Phase 3: Cleanup completed.');
        }

        $this->logger->info('Sync process completed successfully.', [
            'inserted'      => $report->insertedCount,
            'updated'       => $report->updatedCount,
            'deleted'       => $report->deletedCount,
            'initialInsert' => $report->initialInsertCount
        ]);
        return $report;
    }
    ```

---

**Verification Steps (Manual or Automated):**

1.  **Code Review:** Review the changes against the plan.
2.  **Test Case 1 (Successful Sync):** Run a synchronization that is expected to succeed.
    *   Verify all data is synchronized correctly.
    *   Check logs for correct transaction start/commit messages from `TempToLiveSynchronizer`.
    *   Verify no transaction-related errors from `GenericTableSyncer`.
    *   Verify temp table is dropped.
3.  **Test Case 2 (Error during `TempToLiveSynchronizer::synchronize`)**:
    *   Simulate an error within `TempToLiveSynchronizer` *after* its transaction has started (e.g., by temporarily adding `throw new \Exception("Simulated DML error");` inside one of its DML operations).
    *   Verify that `TempToLiveSynchronizer` attempts to roll back its transaction (check logs).
    *   Verify that the live table state reflects a rollback (i.e., no partial changes from the failed `synchronize` call are present).
    *   Verify `GenericTableSyncer` logs the failure and propagates the exception.
    *   Verify temp table is dropped.
4.  **Test Case 3 (Error during DDL phase in `GenericTableSyncer`, e.g., `prepareTempTable`)**:
    *   Simulate an error (e.g., syntax error in a `CREATE TABLE` modification if possible, or a permissions issue).
    *   Verify `GenericTableSyncer` catches this, logs it, and re-throws.
    *   Verify no transaction rollback is attempted by `GenericTableSyncer` for this phase (as DDL auto-commits).
    *   Verify temp table (if partially created) is attempted to be dropped.
5.  **Test with an existing external transaction:**
    *   If the application might call `GenericTableSyncer::sync()` while a DBAL transaction is already active on the target connection:
        *   Start a transaction manually in your calling code.
        *   Call `GenericTableSyncer::sync()`.
        *   Verify `TempToLiveSynchronizer` detects the active transaction and does *not* try to `beginTransaction` or `commit/rollback` it.
        *   Manually commit/rollback the external transaction in your calling code and verify the outcome.

