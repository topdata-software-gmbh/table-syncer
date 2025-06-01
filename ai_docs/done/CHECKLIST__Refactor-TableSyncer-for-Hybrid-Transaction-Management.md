# Checklist: Refactor TableSyncer for Hybrid Transaction Management

**Goal:** Ensure `TempToLiveSynchronizer` manages its own database transaction for atomicity, and `GenericTableSyncer` no longer manages an overarching DML transaction, avoiding DDL auto-commit conflicts.

**Plan Document:** `ai_docs/plans/Refactor-TableSyncer-for-Hybrid-Transaction-Management.md` (or the markdown plan previously generated)

---

## I. Pre-Refactoring Checks

-   [x] **Understand Current State:** Confirm current transaction logic in both `GenericTableSyncer.php` and `TempToLiveSynchronizer.php`.
-   [x] **Backup Code:** Ensure the current working version of the codebase is backed up or committed to version control.
-   [x] **Review Plan:** Read and understand the full refactoring plan document.
-   [x] **Identify Affected Methods:**
    -   `TempToLiveSynchronizer::synchronize()`
    -   `GenericTableSyncer::sync()`

## II. Phase 1: Modify `src/Service/TempToLiveSynchronizer.php`

-   [x] **Locate `synchronize` method.**
-   [x] **Initialize `transactionStartedByThisMethod` flag:**
    ```php
    $transactionStartedByThisMethod = false;
    ```
    Place this near the beginning of the method, after `$targetConn` is assigned.
-   [x] **Implement `try...catch` block:** Enclose all DML operations (initial import, updates, deletes, inserts on the live table) within this block.
-   [x] **Start Transaction Logic:**
    -   [x] Check `!$targetConn->isTransactionActive()`.
    -   [x] If true, call `$targetConn->beginTransaction()`.
    -   [x] If true, set `$transactionStartedByThisMethod = true;`.
    -   [x] Add debug log: `Transaction started within TempToLiveSynchronizer...`
-   [x] **Commit Transaction Logic:**
    -   [x] Place at the end of the `try` block (after all DML).
    -   [x] Check `$transactionStartedByThisMethod && $targetConn->isTransactionActive()`.
    -   [x] If true, call `$targetConn->commit()`.
    -   [x] Add debug log: `Transaction committed within TempToLiveSynchronizer...`
-   [x] **Rollback Transaction Logic (in `catch (\Throwable $e)`):**
    -   [x] Check `$transactionStartedByThisMethod && $targetConn->isTransactionActive()`.
    -   [x] If true, wrap `$targetConn->rollBack()` in its own `try...catch (\Throwable $rollbackException)`.
    -   [x] Log warning for successful rollback.
    -   [x] Log error if rollback itself fails.
    -   [x] Add log for externally managed transaction if `!$transactionStartedByThisMethod && $targetConn->isTransactionActive()`.
-   [x] **Re-throw Exception:** Ensure the original exception `$e` is re-thrown from the main `catch` block of the `synchronize` method.
-   [x] **Review Logging:** Ensure all new log messages are clear, provide context, and use appropriate log levels.

## III. Phase 2: Modify `src/Service/GenericTableSyncer.php`

-   [x] **Locate `sync` method.**
-   [x] **Remove Orchestrator-Level DML Transaction Logic:**
    -   [x] Delete/comment out `transactionStartedBySyncer` flag initialization.
    -   [x] Delete/comment out the `if (!$targetConn->isTransactionActive()) { $targetConn->beginTransaction(); ... }` block that was previously around the DML phase.
    -   [x] Delete/comment out the `if ($transactionStartedBySyncer && $targetConn->isTransactionActive()) { $targetConn->commit(); ... }` block.
-   [x] **Simplify Main `catch (\Throwable $e)` Block:**
    -   [x] Remove any calls to `$targetConn->rollBack()` from this block (as `TempToLiveSynchronizer` now handles its own rollback if it started the transaction).
    -   [x] Ensure the block logs the error effectively.
    -   [x] Ensure the block re-throws the exception (either as is, or wrapped in `TableSyncerException` if it's not already one).
-   [x] **Verify Temp Table Cleanup:**
    -   [x] Added cleanup attempt in the catch block to ensure `$this->schemaManager->dropTempTable($config)` is called even on error.
    -   [x] Ensure errors during `dropTempTable` are caught and logged but generally do not prevent a previously caught main exception from propagating.
-   [x] **Review Overall Flow:** Confirm that `GenericTableSyncer::sync` now acts as an orchestrator for DDL and setup, and delegates the transactional DML for the live table entirely to `TempToLiveSynchronizer`.
-   [x] **Review Logging:** Ensure log messages in `GenericTableSyncer` clearly distinguish its orchestration role and phases.

## IV. Post-Refactoring Verification

-   [ ] **Static Analysis:** Run PHPStan or other static analysis tools to catch any new type errors or issues.
-   [ ] **Code Formatting/Linting:** Run PHP CS Fixer or similar tools to ensure code style consistency.
-   [ ] **Manual Test: Successful Sync**
    -   [ ] Outcome: Data synchronized, no errors.
    -   [ ] Logs: `TempToLiveSynchronizer` logs transaction start/commit. `GenericTableSyncer` logs orchestration steps. Temp table dropped.
-   [ ] **Manual Test: Error in `TempToLiveSynchronizer` DML**
    -   [ ] Setup: Simulate an error *inside* `TempToLiveSynchronizer::synchronize` DML operations.
    -   [ ] Outcome: Live table data is rolled back (no partial changes). Main error propagated.
    -   [ ] Logs: `TempToLiveSynchronizer` logs transaction start, error, and rollback attempt. `GenericTableSyncer` logs the propagated error. Temp table dropped.
-   [ ] **Manual Test: Error in `GenericTableSyncer` DDL/Setup Phase**
    -   [ ] Setup: Simulate an error during `prepareTempTable` or `load` (before `TempToLiveSynchronizer` is called).
    -   [ ] Outcome: Error propagated.
    -   [ ] Logs: `GenericTableSyncer` logs the error. No DML transaction messages from `TempToLiveSynchronizer`. Temp table (if any) attempted to be dropped.
-   [ ] **(Optional) Manual Test: Externally Managed Transaction**
    -   [ ] Setup: Wrap the call to `GenericTableSyncer::sync()` in an external `$targetConn->beginTransaction()` and `commit()/rollback()`.
    -   [ ] Outcome: Sync completes or fails as expected.
    -   [ ] Logs: `TempToLiveSynchronizer` should log that it detected an existing transaction and did not manage it.

## V. Documentation & Cleanup

-   [ ] **Update Code Comments:** Add/update comments where necessary to explain the new transaction handling logic.
-   [ ] **Review CHANGELOG.md:** Consider if this change warrants an entry (e.g., "Improved transaction handling for DDL compatibility and atomicity of live sync operations").
-   [ ] **Commit Changes:** Commit the refactored code with a clear commit message.

---
**Sign-off:**

-   **Developer:** _________________________ Date: _________
-   **Reviewer (if applicable):** _________ Date: _________

