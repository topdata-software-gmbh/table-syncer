# Plan: Enhance `table-syncer` for Optional Deletion Logging

**Goal:** Modify the `topdata-software-gmbh/table-syncer` library to optionally create and populate a "deletion log table". This table will record the `_syncer_id` and `_syncer_revision_id` of rows deleted from the live target table during the synchronization process.

**Version:** `1.1.0` (Suggested next version after these changes)

---

## Phase 1: Configuration & DTO Updates

### Task 1.1: Modify `TableSyncConfigDTO.php`

**File:** `src/DTO/TableSyncConfigDTO.php`

1.  **Add New Public Properties:**
    *   `public bool $enableDeletionLogging = false;`
        *   Purpose: Flag to enable or disable the deletion logging feature.
        *   Default: `false` to ensure backward compatibility for existing users.
    *   `public ?string $targetDeletedLogTableName = null;`
        *   Purpose: Specifies the name of the deletion log table.
        *   Default: `null`. If `enableDeletionLogging` is true and this is `null`, the library will automatically derive a default name.

2.  **Update Constructor (`__construct`):**
    *   After existing property initializations and before basic validation.
    *   Add logic to set a default for `targetDeletedLogTableName` if logging is enabled and no name is provided:
        ```php
        // ... (existing constructor code)

        $this->enableDeletionLogging = $enableDeletionLogging; // Assuming new param added to constructor
        $this->targetDeletedLogTableName = $targetDeletedLogTableName; // Assuming new param

        if ($this->enableDeletionLogging && $this->targetDeletedLogTableName === null) {
            if (empty($this->targetLiveTableName)) {
                // This should ideally be caught by earlier validation or be an impossible state
                // if enableDeletionLogging is true. For safety:
                throw new \InvalidArgumentException('targetLiveTableName must be set if deletion logging is enabled without a specific targetDeletedLogTableName.');
            }
            $this->targetDeletedLogTableName = $this->targetLiveTableName . '_deleted_log';
        }

        // Basic validation (existing)
        // ...
        ```
    *   **Consider adding new parameters to the constructor signature for these options:**
        ```php
        public function __construct(
            // ... existing parameters ...
            bool $enableDeletionLogging = false,
            ?string $targetDeletedLogTableName = null
            // ... existing parameters that might follow ...
        ) {
            // ...
            $this->enableDeletionLogging = $enableDeletionLogging;
            $this->targetDeletedLogTableName = $targetDeletedLogTableName;
            // ...
            // Defaulting logic as above
            // ...
        }
        ```

3.  **Update Validation (in `__construct` or a separate validation method):**
    *   If `enableDeletionLogging` is `true`, ensure `targetDeletedLogTableName` is not empty or null after the defaulting logic.
        ```php
        // ... after defaulting logic for targetDeletedLogTableName ...
        if ($this->enableDeletionLogging && empty($this->targetDeletedLogTableName)) {
            throw new \InvalidArgumentException('targetDeletedLogTableName cannot be empty if deletion logging is enabled.');
        }
        ```

### Task 1.2: Modify `SyncReportDTO.php`

**File:** `src/DTO/SyncReportDTO.php`

1.  **Add New Public Property:**
    *   `public int $loggedDeletionsCount = 0;`
        *   Purpose: To store the number of deletion records written to the deletion log table.

2.  **Update Constructor (`__construct`):**
    *   Initialize the new property.
    *   Add an optional parameter for it.
        ```php
        public function __construct(
            int $insertedCount = 0,
            int $updatedCount = 0,
            int $deletedCount = 0,
            int $initialInsertCount = 0,
            int $loggedDeletionsCount = 0 // New parameter
        ) {
            $this->insertedCount = $insertedCount;
            $this->updatedCount = $updatedCount;
            $this->deletedCount = $deletedCount;
            $this->initialInsertCount = $initialInsertCount;
            $this->loggedDeletionsCount = $loggedDeletionsCount; // Initialize
        }
        ```

3.  **Update `getSummary()` method (Optional but Recommended):**
    *   Include `loggedDeletionsCount` in the summary string if it's greater than 0 or if deletion logging was active.
        ```php
        public function getSummary(): string
        {
            $summary = sprintf(
                "Inserts: %d, Updates: %d, Deletes: %d",
                $this->insertedCount,
                $this->updatedCount,
                $this->deletedCount
            );
            if ($this->loggedDeletionsCount > 0) { // Or a more robust check if logging was enabled
                $summary .= sprintf(", Logged Deletions: %d", $this->loggedDeletionsCount);
            }
            // Potentially also add initialInsertCount if non-zero
            if ($this->initialInsertCount > 0) {
                 $summary .= sprintf(" (Initial Import: %d)", $this->initialInsertCount);
            }
            return $summary;
        }
        ```

---

## Phase 2: Schema Management

### Task 2.1: Modify `GenericSchemaManager.php`

**File:** `src/Service/GenericSchemaManager.php`

1.  **Create New Public Method `ensureDeletedLogTable(TableSyncConfigDTO $config): void`:**
    *   Purpose: Checks for the existence of the deletion log table and creates it if necessary.
    *   This method will be called by `GenericTableSyncer` if `config->enableDeletionLogging` is true.

    ```php
    use Doctrine\DBAL\Schema\Table;
    use Doctrine\DBAL\Types\Types; // Ensure this is imported

    // ... (inside GenericSchemaManager class) ...

    public function ensureDeletedLogTable(TableSyncConfigDTO $config): void
    {
        if (!$config->enableDeletionLogging || empty($config->targetDeletedLogTableName)) {
            // Should not happen if config validation is correct, but as a safeguard.
            $this->logger->debug('Deletion logging not enabled or log table name not set, skipping ensureDeletedLogTable.');
            return;
        }

        $this->logger->debug('Ensuring deletion log table schema', [
            'deletedLogTable' => $config->targetDeletedLogTableName,
        ]);

        $targetConn = $config->targetConnection;
        $dbalSchemaManager = $targetConn->createSchemaManager();
        $logTableName = $config->targetDeletedLogTableName; // Already validated to be non-empty

        if ($dbalSchemaManager->tablesExist([$logTableName])) {
            $this->logger->info("Deletion log table '{$logTableName}' already exists. Schema validation for it is not implemented in this version.");
            // Future enhancement: Validate schema of existing log table.
            return;
        }

        $this->logger->info("Deletion log table '{$logTableName}' does not exist. Creating...");

        $table = new Table($logTableName);

        // Define columns for the deletion log table
        $table->addColumn('log_id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull' => true,
        ]);
        $table->setPrimaryKey(['log_id']);

        // deleted_syncer_id should match the type of the _syncer_id in the live table
        $table->addColumn('deleted_syncer_id', $config->targetIdColumnType, [
            'notnull' => true,
            // Consider length/precision/scale if targetIdColumnType needs it, though typically INTEGER/BIGINT
        ]);

        $table->addColumn('deleted_at_revision_id', Types::INTEGER, [
            'notnull' => true,
        ]);

        $table->addColumn('deletion_timestamp', Types::DATETIME_MUTABLE, [
            'notnull' => true,
            'default' => 'CURRENT_TIMESTAMP', // Relies on DB to handle this; or set explicitly during INSERT
        ]);

        // Add indexes
        $table->addIndex(['deleted_at_revision_id'], 'idx_' . $logTableName . '_revision_id');
        $table->addIndex(['deleted_syncer_id'], 'idx_' . $logTableName . '_syncer_id');

        try {
            $dbalSchemaManager->createTable($table);
            $this->logger->info("Deletion log table '{$logTableName}' created successfully.");
        } catch (\Throwable $e) {
            $this->logger->error("Failed to create deletion log table '{$logTableName}': " . $e->getMessage(), ['exception' => $e]);
            throw $e; // Re-throw
        }
    }
    ```

---

## Phase 3: Synchronization Logic

### Task 3.1: Modify `TempToLiveSynchronizer.php`

**File:** `src/Service/TempToLiveSynchronizer.php`

1.  **Modify `synchronize()` method:**
    *   Locate the section for "Handle Deletes" (typically marked as `--- C. Handle Deletes ---`).
    *   **Before** the SQL query that performs `DELETE FROM {$liveTable} ...`:
        *   Add a check for `config->enableDeletionLogging`.
        *   If `true`, implement the logic to insert records into the deletion log table.

    ```php
    // ... (inside synchronize method, within the transaction) ...

    // --- C. Handle Deletes ---
    if ($config->enableDeletionLogging && !empty($config->targetDeletedLogTableName)) {
        $this->logger->debug("Deletion logging enabled. Logging rows to be deleted from '{$config->targetLiveTableName}'.");

        $deletedLogTableIdentifier = $targetConn->quoteIdentifier($config->targetDeletedLogTableName);
        $liveTableIdentifierForLog = $targetConn->quoteIdentifier($config->targetLiveTableName); // alias as 'lt'
        $tempTableIdentifierForLog = $targetConn->quoteIdentifier($config->targetTempTableName); // alias as 'tt'

        $liveSyncerIdCol = $targetConn->quoteIdentifier($config->metadataColumns->id);

        // Columns for INSERT into deletion log table
        $logTableInsertCols = [
            $targetConn->quoteIdentifier('deleted_syncer_id'),
            $targetConn->quoteIdentifier('deleted_at_revision_id'),
            $targetConn->quoteIdentifier('deletion_timestamp') // Assuming DB default or explicit value
        ];

        // Columns to SELECT from live table for logging
        // The join condition (reusing $joinConditionStr and $tempTablePkColForNullCheck from delete logic)
        // identifies rows in liveTable (lt) that are NOT in tempTable (tt).
        // The $joinConditionStr uses $liveTable and $tempTable aliases without lt/tt.
        // We need to reconstruct the join for aliases here or adapt.
        // Re-using $joinConditionStr directly means the main tables in that string are $liveTable and $tempTable.
        // For clarity, let's reconstruct with aliases.

        $logJoinConditions = [];
        foreach ($config->getTargetPrimaryKeyColumns() as $keyCol) {
            $quotedKeyCol = $targetConn->quoteIdentifier($keyCol);
            $logJoinConditions[] = "lt.{$quotedKeyCol} = tt.{$quotedKeyCol}";
        }
        $logJoinConditionStr = implode(' AND ', $logJoinConditions);

        $tempTableBusinessPkColForNullCheck = "tt." . $targetConn->quoteIdentifier($config->getTargetPrimaryKeyColumns()[0]);


        // Using CURRENT_TIMESTAMP for deletion_timestamp via SQL
        $sqlLogDeletes = "INSERT INTO {$deletedLogTableIdentifier} (" . implode(', ', $logTableInsertCols) . ") "
            . "SELECT lt.{$liveSyncerIdCol}, ?, CURRENT_TIMESTAMP "
            . "FROM {$liveTableIdentifierForLog} lt "
            . "LEFT JOIN {$tempTableIdentifierForLog} tt ON {$logJoinConditionStr} "
            . "WHERE {$tempTableBusinessPkColForNullCheck} IS NULL";

        $paramsForLog = [$currentBatchRevisionId];

        try {
            $this->logger->debug('Executing SQL to log deletes', ['sql' => $sqlLogDeletes, 'params' => $paramsForLog]);
            $loggedCount = $targetConn->executeStatement($sqlLogDeletes, $paramsForLog);
            $report->loggedDeletionsCount = (int)$loggedCount; // Update report DTO
            $report->addLogMessage("Logged {$report->loggedDeletionsCount} rows for deletion into '{$config->targetDeletedLogTableName}'.");
            $this->logger->info("Successfully logged {$report->loggedDeletionsCount} rows to '{$config->targetDeletedLogTableName}'.");
        } catch (\Throwable $e) {
            $this->logger->error("Failed to log deletions to '{$config->targetDeletedLogTableName}': " . $e->getMessage(), ['exception' => $e]);
            // Decide if this error should halt the entire sync or just be logged.
            // For data integrity of the log, it might be better to halt and rollback.
            throw new TableSyncerException("Failed to log deletions: " . $e->getMessage(), 0, $e);
        }
    } else {
        $this->logger->debug("Deletion logging is not enabled or log table name is missing.");
    }

    // Original DELETE SQL execution follows...
    // Ensure $targetPkColumns, $joinConditionStr, $tempTablePkColForNullCheck are available/re-derived if necessary
    // The original delete logic:
    // $targetPkColumns = $config->getTargetPrimaryKeyColumns();
    // if (empty($targetPkColumns)) { ... } else {
    //    $tempTablePkColForNullCheck = $tempTable . "." . $targetConn->quoteIdentifier($targetPkColumns[0]);
    //    $sqlDelete = "DELETE {$liveTable} FROM {$liveTable} " ...
    //    $affectedRowsDelete = $targetConn->executeStatement($sqlDelete);
    //    $report->deletedCount = (int)$affectedRowsDelete;
    //    ...
    // }
    // It's crucial that the condition for logging deletions (which rows)
    // is IDENTICAL to the condition for actually deleting rows.
    ```
    *   **Important:** The logic to identify rows for deletion (the `LEFT JOIN ... WHERE ... IS NULL` condition) must be identical for both logging the deletions and performing the actual delete operation to ensure consistency. Ensure aliases and column qualifications are correct and consistent.

---

## Phase 4: Orchestration

### Task 4.1: Modify `GenericTableSyncer.php`

**File:** `src/Service/GenericTableSyncer.php`

1.  **Modify `sync()` method:**
    *   After the call to `$this->schemaManager->ensureLiveTable($config);`
    *   Add a conditional call to `ensureDeletedLogTable`.

    ```php
    // ... (inside sync method)
    try {
        // ...
        // 1. Ensure live table exists with correct schema
        $this->schemaManager->ensureLiveTable($config);

        // 1b. Ensure deleted log table exists if logging is enabled
        if ($config->enableDeletionLogging) {
            $this->logger->debug('Deletion logging is enabled, ensuring deleted log table.');
            $this->schemaManager->ensureDeletedLogTable($config);
        }

        // 2. Prepare temp table (drop if exists, create new)
        $this->schemaManager->prepareTempTable($config);
        // ... rest of the method
    } catch (\Throwable $e) {
    // ...
    }
    ```

---

## Phase 5: Documentation & Testing

### Task 5.1: Update `README.md`

1.  **Add a New Section:** "Deletion Logging (Optional)"
2.  **Explain the Feature:** Describe that the syncer can now optionally log deleted record identifiers to a separate table.
3.  **Detail New `TableSyncConfigDTO` Properties:**
    *   `enableDeletionLogging`: How to use it (boolean).
    *   `targetDeletedLogTableName`: Explain it's optional and a default will be used (`<targetLiveTableName>_deleted_log`). How to override it.
4.  **Provide an Example:**
    ```php
    // Create your sync configuration
    $config = new TableSyncConfigDTO(
        // ... other parameters ...
        $targetLiveTableName, // existing param
        // ... other parameters ...
        true, // enableDeletionLogging
        'my_custom_deletions_audit_log' // optional targetDeletedLogTableName
    );
    ```
5.  **Mention Schema of Log Table:** Briefly describe the columns created in the deletion log table (`log_id`, `deleted_syncer_id`, `deleted_at_revision_id`, `deletion_timestamp`).

### Task 5.2: Implement Unit and Integration Tests

1.  **`TableSyncConfigDTOTest.php`:**
    *   Test constructor with new deletion logging parameters.
    *   Test default `targetDeletedLogTableName` generation when enabled and name is null.
    *   Test validation (e.g., exception if enabled but live table name isn't set for default generation).
2.  **`GenericSchemaManagerTest.php`:**
    *   Mock `Connection` and `SchemaManager`.
    *   Test `ensureDeletedLogTable()`:
        *   Does nothing if `enableDeletionLogging` is false.
        *   Calls `createTable()` on DBAL schema manager if table doesn't exist and logging is enabled.
        *   Verifies the `Table` object passed to `createTable()` has the correct columns, primary key, and indexes.
        *   Does not call `createTable()` if table already exists.
3.  **`TempToLiveSynchronizerTest.php`:**
    *   Mock `Connection`.
    *   Test `synchronize()`:
        *   If deletion logging enabled:
            *   Verify an `INSERT` statement is executed against `targetDeletedLogTableName` *before* the `DELETE` on `targetLiveTableName`.
            *   Verify the `INSERT` statement uses correct `currentBatchRevisionId`.
            *   Verify `SyncReportDTO->loggedDeletionsCount` is updated.
        *   If deletion logging disabled:
            *   Verify no `INSERT` statement is executed against a log table.
4.  **`GenericTableSyncerTest.php` (Integration Style):**
    *   Set up a test with `enableDeletionLogging = true`.
    *   Verify `ensureDeletedLogTable` is called on the schema manager mock.
    *   Run a full sync scenario that involves deletions and check if the log table is populated correctly in an in-memory SQLite DB or similar test DB.
