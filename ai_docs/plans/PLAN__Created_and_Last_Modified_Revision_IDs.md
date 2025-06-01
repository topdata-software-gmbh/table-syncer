# Plan: Enhance Table-Syncer with Created and Last Modified Revision IDs

**Goal:** To provide a more granular audit trail by tracking both the revision ID when a record was first created in the target table and the revision ID when it was last modified.

**Summary of Changes:**
1.  Introduce a new metadata column `_syncer_created_revision_id`.
2.  Rename the existing `_syncer_batch_revision` metadata column to `_syncer_last_modified_revision_id` for clarity.
3.  Update DTOs, schema management, and synchronization logic to handle these two distinct revision IDs.
4.  Update documentation and tests.

---

## Detailed Implementation Steps:

### 1. Update DTOs

#### 1.1. `src/DTO/MetadataColumnNamesDTO.php`
    *   **Objective:** Add the new `createdRevisionId` property and rename `batchRevision` to `lastModifiedRevisionId`.
    *   **Actions:**
        1.  Add a new public string property: `public string $createdRevisionId = '_syncer_created_revision_id';`
        2.  Rename the existing public string property `batchRevision` to `public string $lastModifiedRevisionId = '_syncer_last_modified_revision_id';`
        3.  Update the constructor:
            *   Add a new parameter `string $createdRevisionId = '_syncer_created_revision_id'`.
            *   Rename the existing parameter `string $batchRevision` to `string $lastModifiedRevisionId = '_syncer_last_modified_revision_id'`.
            *   Update the constructor body to assign `$this->createdRevisionId = $createdRevisionId;`
            *   Update the constructor body to assign `$this->lastModifiedRevisionId = $lastModifiedRevisionId;` (from the renamed parameter).

    *   **Example Snippet (for constructor changes):**
        ```php
        // Before
        // public string $batchRevision = '_syncer_revision_id';
        // public function __construct(
        //     ...,
        //     string $batchRevision = '_syncer_revision_id'
        // ) {
        //     ...
        //     $this->batchRevision = $batchRevision;
        // }

        // After
        public string $createdRevisionId = '_syncer_created_revision_id';
        public string $lastModifiedRevisionId = '_syncer_last_modified_revision_id';

        public function __construct(
            string $id = '_syncer_id',
            string $contentHash = '_syncer_content_hash',
            string $createdAt = '_syncer_created_at',
            string $updatedAt = '_syncer_updated_at',
            string $createdRevisionId = '_syncer_created_revision_id', // New
            string $lastModifiedRevisionId = '_syncer_last_modified_revision_id' // Renamed from batchRevision
        ) {
            $this->id = $id;
            $this->contentHash = $contentHash;
            $this->createdAt = $createdAt;
            $this->updatedAt = $updatedAt;
            $this->createdRevisionId = $createdRevisionId;         // New
            $this->lastModifiedRevisionId = $lastModifiedRevisionId; // Renamed
        }
        ```

#### 1.2. `src/DTO/TableSyncConfigDTO.php`
    *   **Objective:** Ensure constructor usage of `MetadataColumnNamesDTO` (if any explicit instantiation occurs there) is updated, and generally be aware of the change for any direct property access.
    *   **Actions:**
        1.  Review the constructor. If `new MetadataColumnNamesDTO()` is called without parameters, it will pick up the new defaults, which is fine.
        2.  No direct code changes are expected in this file for this step, as `metadataColumns` is typed `MetadataColumnNamesDTO` and will reflect the changes automatically.

### 2. Update Schema Management

#### 2.1. `src/Service/GenericSchemaManager.php`
    *   **Objective:** Add the `_syncer_created_revision_id` column definition and update the `_syncer_last_modified_revision_id` (formerly `_syncer_batch_revision`) definition for the live table.
    *   **Actions:**
        1.  In the `getLiveTableSpecificMetadataColumns(TableSyncConfigDTO $config): array` method:
            *   Add a new column definition array for `$meta->createdRevisionId`:
                ```php
                [
                    'name'    => $meta->createdRevisionId,
                    'type'    => \Doctrine\DBAL\Types\Types::INTEGER,
                    'notnull' => true, // Important for new records. Existing records might need a migration strategy.
                ],
                ```
            *   Update the existing column definition for what was `$meta->batchRevision` to use `$meta->lastModifiedRevisionId`. The properties (`type`, `notnull`) should remain the same (`Types::INTEGER`, `notnull` => `false` as it is currently, or change to `true` if desired for consistency, though `false` allows for initial state before any sync).
                ```php
                // Before
                // [
                //     'name'    => $meta->batchRevision,
                //     'type'    => Types::INTEGER,
                //     'notnull' => false,
                // ],

                // After
                [
                    'name'    => $meta->lastModifiedRevisionId,
                    'type'    => \Doctrine\DBAL\Types\Types::INTEGER,
                    'notnull' => true, // Consider changing to true for consistency with createdRevisionId if all rows will always have it.
                                       // If keeping `false`, it implies it can be nullable.
                                       // For now, let's align and make it `true` if new records always get it.
                                       // If this causes issues for existing tables not yet migrated, it can be `false`.
                                       // For safety and consistency, let's propose `true` and document migration.
                ],
                ```
        2.  **No changes** are needed in `getTempTableSpecificMetadataColumns()`, as these revision IDs are only relevant for the live table.
        3.  In `ensureLiveTable()`: When creating a new table, it will now include `_syncer_created_revision_id` and use the new name for `_syncer_last_modified_revision_id`. The validation logic for existing tables should implicitly check for these columns based on `getLiveTableSpecificMetadataColumns()`. If an existing table is missing `_syncer_created_revision_id` or has the old `_syncer_batch_revision` name, `ensureLiveTable` might throw a `ConfigurationException` during validation. This is acceptable, as schema changes are expected. Users might need to manually alter existing tables or allow the syncer to (if it were designed to) to add missing columns. *The current `ensureLiveTable` logic will throw if columns are missing from an existing table, prompting a manual fix or table drop-recreate.*

### 3. Update Synchronization Logic

#### 3.1. `src/Service/TempToLiveSynchronizer.php`
    *   **Objective:** Modify SQL statements and parameter binding to correctly populate `_syncer_created_revision_id` and `_syncer_last_modified_revision_id`.
    *   **Actions:**
        1.  Throughout the file, replace all instances of `$meta->batchRevision` with `$meta->lastModifiedRevisionId`.

        2.  **Initial Bulk Import (if `$countInt === 0`):**
            *   Update `$colsToInsertLive`: Add `$meta->createdRevisionId` and use `$meta->lastModifiedRevisionId`.
            *   Update the `SELECT` part of `$sqlInitialInsert`: Add an additional placeholder for the `createdRevisionId`.
                ```sql
                -- Before: SELECT ..., ? FROM {$tempTable} (for batch_revision)
                -- After:  SELECT ..., ?, ? FROM {$tempTable} (for created_revision, last_modified_revision)
                ```
            *   Update `$targetConn->executeStatement($sqlInitialInsert, ...)` parameters: Pass `$currentBatchRevisionId` twice.
                ```php
                // Before
                // $colsToInsertLive = array_unique(array_merge(..., [$meta->batchRevision]));
                // $sqlInitialInsert = "INSERT INTO {$liveTable} (" . implode(', ', $quotedColsToInsertLive) . ") "
                //     . "SELECT " . implode(', ', $quotedColsToSelectTemp) . ", ? "
                //     . "FROM {$tempTable}";
                // $affectedRows = $targetConn->executeStatement($sqlInitialInsert, [$currentBatchRevisionId]);

                // After
                $colsToInsertLive = array_unique(array_merge(
                    $config->getTargetPrimaryKeyColumns(),
                    $config->getTargetDataColumns(),
                    [$meta->contentHash, $meta->createdAt, $meta->createdRevisionId, $meta->lastModifiedRevisionId] // Added createdRevisionId, updated lastModifiedRevisionId
                ));
                // ... ensure $quotedColsToInsertLive is regenerated ...
                // ... ensure column count check reflects two revision ID columns from parameters:
                // if (count($quotedColsToSelectTemp) + 2 !== count($quotedColsToInsertLive)) { ... }

                $sqlInitialInsert = "INSERT INTO {$liveTable} (" . implode(', ', $quotedColsToInsertLive) . ") "
                    . "SELECT " . implode(', ', $quotedColsToSelectTemp) . ", ?, ? " // Two placeholders
                    . "FROM {$tempTable}";

                $this->logger->debug('Executing initial insert SQL for live table', ['sql' => $sqlInitialInsert]);
                $affectedRows = $targetConn->executeStatement($sqlInitialInsert, [$currentBatchRevisionId, $currentBatchRevisionId]); // Pass twice
                ```

        3.  **Handle Updates:**
            *   The `$setClausesForUpdate` should only update `$meta->lastModifiedRevisionId`. The `$meta->createdRevisionId` **must not** be changed during an update.
                ```php
                // Before: $setClausesForUpdate[] = "{$liveTable}." . $targetConn->quoteIdentifier($meta->batchRevision) . " = ?";
                // After:  $setClausesForUpdate[] = "{$liveTable}." . $targetConn->quoteIdentifier($meta->lastModifiedRevisionId) . " = ?";
                ```
            *   The parameters for `$targetConn->executeStatement($sqlUpdate, ...)` remain a single `$currentBatchRevisionId`.

        4.  **Handle Deletes:**
            *   No direct changes regarding the new revision ID columns are needed in the `DELETE` statement itself or its logging part (the `deleted_at_revision_id` in the log table correctly refers to the current batch revision).

        5.  **Handle Inserts (New Rows):**
            *   Update `$colsToInsertLiveForNew`: Add `$meta->createdRevisionId` and use `$meta->lastModifiedRevisionId`.
            *   Update the `SELECT` part of `$sqlInsert`: Add an additional placeholder for the `createdRevisionId`.
                ```sql
                -- Before: SELECT ..., ? FROM {$tempTable} ... (for batch_revision)
                -- After:  SELECT ..., ?, ? FROM {$tempTable} ... (for created_revision, last_modified_revision)
                ```
            *   Update `$targetConn->executeStatement($sqlInsert, ...)` parameters: Pass `$currentBatchRevisionId` twice.
                ```php
                // Before
                // $colsToInsertLiveForNew = array_unique(array_merge(..., [$meta->batchRevision]));
                // $sqlInsert = "INSERT INTO {$liveTable} (" . implode(', ', $quotedColsToInsertLiveForNew) . ") "
                //     . "SELECT " . implode(', ', $quotedColsToSelectTempForNew) . ", ? "
                //     . "FROM {$tempTable} ...";
                // $affectedRowsInsert = $targetConn->executeStatement($sqlInsert, [$currentBatchRevisionId]);

                // After
                $colsToInsertLiveForNew = array_unique(array_merge(
                    $config->getTargetPrimaryKeyColumns(),
                    $config->getTargetDataColumns(),
                    [$meta->contentHash, $meta->createdAt, $meta->createdRevisionId, $meta->lastModifiedRevisionId] // Added createdRevisionId, updated lastModifiedRevisionId
                ));
                // ... ensure $quotedColsToInsertLiveForNew is regenerated ...
                // ... ensure column count check reflects two revision ID columns from parameters:
                // if (count($quotedColsToSelectTempForNew) + 2 !== count($quotedColsToInsertLiveForNew)) { ... }

                $sqlInsert = "INSERT INTO {$liveTable} (" . implode(', ', $quotedColsToInsertLiveForNew) . ") "
                    . "SELECT " . implode(', ', $quotedColsToSelectTempForNew) . ", ?, ? " // Two placeholders
                    . "FROM {$tempTable} "
                    . "LEFT JOIN {$liveTable} ON {$joinConditionStr} "
                    . "WHERE {$liveTablePkColForNullCheck} IS NULL";

                $this->logger->debug('Executing insert SQL for new rows in live table', ['sql' => $sqlInsert]);
                $affectedRowsInsert = $targetConn->executeStatement($sqlInsert, [$currentBatchRevisionId, $currentBatchRevisionId]); // Pass twice
                ```

### 4. Update Tests

#### 4.1. `tests/Unit/DTO/MetadataColumnNamesDTOTest.php`
    *   **Objective:** Adapt tests to reflect the new `createdRevisionId` and renamed `lastModifiedRevisionId`.
    *   **Actions:**
        1.  This test file currently doesn't match the actual DTO structure. It needs to be completely rewritten to test the `TopdataSoftwareGmbh\TableSyncer\DTO\MetadataColumnNamesDTO`.
        2.  **New Test Structure:**
            ```php
            <?php

            namespace Tests\Unit\DTO;

            use PHPUnit\Framework\TestCase;
            use TopdataSoftwareGmbh\TableSyncer\DTO\MetadataColumnNamesDTO;

            class MetadataColumnNamesDTOTest extends TestCase
            {
                public function testConstructorWithDefaultValues(): void
                {
                    $dto = new MetadataColumnNamesDTO();
                    $this->assertSame('_syncer_id', $dto->id);
                    $this->assertSame('_syncer_content_hash', $dto->contentHash);
                    $this->assertSame('_syncer_created_at', $dto->createdAt);
                    $this->assertSame('_syncer_updated_at', $dto->updatedAt);
                    $this->assertSame('_syncer_created_revision_id', $dto->createdRevisionId); // New
                    $this->assertSame('_syncer_last_modified_revision_id', $dto->lastModifiedRevisionId); // Renamed
                }

                public function testConstructorWithCustomValues(): void
                {
                    $dto = new MetadataColumnNamesDTO(
                        'custom_id',
                        'custom_hash',
                        'custom_created_at',
                        'custom_updated_at',
                        'custom_created_rev', // New
                        'custom_modified_rev'  // Renamed
                    );
                    $this->assertSame('custom_id', $dto->id);
                    $this->assertSame('custom_hash', $dto->contentHash);
                    $this->assertSame('custom_created_at', $dto->createdAt);
                    $this->assertSame('custom_updated_at', $dto->updatedAt);
                    $this->assertSame('custom_created_rev', $dto->createdRevisionId); // New
                    $this->assertSame('custom_modified_rev', $dto->lastModifiedRevisionId); // Renamed
                }
            }
            ```
        3.  Replace the content of `tests/Unit/DTO/MetadataColumnNamesDTOTest.php` with the above new test structure.

#### 4.2. Other Tests
    *   Review `tests/Unit/Service/GenericTableSyncerTest.php` and other relevant test files.
    *   While direct changes might not be needed if they mock `TableSyncConfigDTO` and `MetadataColumnNamesDTO` correctly, ensure that any assertions related to `batchRevision` are updated if they exist.
    *   Consider adding integration tests for `TempToLiveSynchronizer` to verify the correct population of both revision ID columns under different scenarios (initial import, insert, update), though this is a larger undertaking and might be a follow-up.

### 5. Update Documentation

#### 5.1. `README.md`
    *   **Objective:** Update documentation to reflect the new and renamed revision ID columns.
    *   **Actions:**
        1.  In the "Customizing MetadataColumnNamesDTO" section:
            *   Add `$metadataColumns->createdRevisionId = 'custom_created_rev_col';`
            *   Update `$metadataColumns->batchRevision` to `$metadataColumns->lastModifiedRevisionId = 'custom_modified_rev_col';`
        2.  In any general description of metadata columns, explain the purpose of `_syncer_created_revision_id` and `_syncer_last_modified_revision_id`.
        3.  In the `TableSyncConfigDTO` section, if `metadataColumns` is described, ensure it mentions the new revision ID handling.

#### 5.2. `CHANGELOG.md`
    *   **Objective:** Add an entry for this enhancement.
    *   **Actions:**
        1.  Add a new entry under the next appropriate version (e.g., `[1.2.0]` or similar):
            ```markdown
            ### Added
            - Introduced `_syncer_created_revision_id` metadata column to track the revision ID when a record was first inserted.
            - Enhanced audit trail capabilities for synchronized records.

            ### Changed
            - Renamed metadata column `_syncer_batch_revision` to `_syncer_last_modified_revision_id` for clarity, representing the revision ID of the last modification.
            ```

### 6. Important Considerations & Migration Notes

*   **Existing Live Tables:**
    *   When this updated syncer runs against an existing live table:
        *   The `_syncer_created_revision_id` column will be missing.
        *   The column `_syncer_batch_revision` will exist, but the DTO now expects `_syncer_last_modified_revision_id`.
    *   The current `GenericSchemaManager::ensureLiveTable()` will likely throw a `ConfigurationException` due to schema mismatch.
    *   **Manual Migration Steps for Users:**
        1.  **Rename Column:** Users will need to manually rename `_syncer_batch_revision` to `_syncer_last_modified_revision_id` in their existing live tables.
            ```sql
            -- Example for MySQL/MariaDB
            ALTER TABLE your_live_table_name CHANGE _syncer_batch_revision _syncer_last_modified_revision_id INT DEFAULT NULL;
            -- Example for PostgreSQL
            ALTER TABLE your_live_table_name RENAME COLUMN _syncer_batch_revision TO _syncer_last_modified_revision_id;
            ```
        2.  **Add New Column:** Users will need to manually add the `_syncer_created_revision_id` column.
            ```sql
            -- Example for MySQL/MariaDB
            ALTER TABLE your_live_table_name ADD COLUMN _syncer_created_revision_id INT NOT NULL;
            -- Example for PostgreSQL
            ALTER TABLE your_live_table_name ADD COLUMN _syncer_created_revision_id INT NOT NULL;
            ```
        3.  **Backfill Data (Optional but Recommended):** For existing rows, `_syncer_created_revision_id` will be unpopulated (or have a default if the DB forces one during `ADD COLUMN NOT NULL`). Users should consider a one-time script to populate this, for instance, by setting it to the value of the (now renamed) `_syncer_last_modified_revision_id` for existing records, assuming that's the best available approximation of their creation revision.
            ```sql
            -- Example for MySQL/MariaDB & PostgreSQL
            UPDATE your_live_table_name SET _syncer_created_revision_id = _syncer_last_modified_revision_id WHERE _syncer_created_revision_id IS NULL OR _syncer_created_revision_id = 0; -- Adjust condition as needed
            ```
    *   This manual migration strategy should be briefly mentioned in the `CHANGELOG.md` or `README.md` as a note for upgrading users.

---

This plan provides a comprehensive set of instructions. The AI agent should proceed step-by-step, paying close attention to SQL query modifications and parameter counts in `TempToLiveSynchronizer.php`.
