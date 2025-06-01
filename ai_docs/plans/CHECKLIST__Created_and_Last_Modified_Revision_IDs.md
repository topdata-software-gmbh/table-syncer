# Checklist: Enhance Table-Syncer with Created and Last Modified Revision IDs

## Phase 1: DTO Updates
-   **`src/DTO/MetadataColumnNamesDTO.php`**
    -   [ ] Add `public string $createdRevisionId` property with default `'_syncer_created_revision_id'`.
    -   [ ] Rename `public string $batchRevision` to `public string $lastModifiedRevisionId`.
    -   [ ] Update default value of `lastModifiedRevisionId` to `'_syncer_last_modified_revision_id'`.
    -   [ ] Add `$createdRevisionId` parameter to the constructor.
    -   [ ] Rename `$batchRevision` constructor parameter to `$lastModifiedRevisionId`.
    -   [ ] Assign `$this->createdRevisionId` in the constructor.
    -   [ ] Assign `$this->lastModifiedRevisionId` (from renamed parameter) in the constructor.
-   **`src/DTO/TableSyncConfigDTO.php`**
    -   [ ] Review constructor: Confirm no explicit `MetadataColumnNamesDTO` instantiation with parameters needs changing (defaults should be fine).

## Phase 2: Schema Management Updates
-   **`src/Service/GenericSchemaManager.php`**
    -   In `getLiveTableSpecificMetadataColumns()`:
        -   [ ] Add new column definition array for `$meta->createdRevisionId` (Type: `INTEGER`, `notnull`: `true`).
        -   [ ] Update column definition for `$meta->lastModifiedRevisionId` (formerly `$meta->batchRevision`) (Type: `INTEGER`, `notnull`: `true` - or confirm desired nullability).
    -   [ ] Confirm `ensureLiveTable()` correctly reflects these changes for new table creation and validation of existing tables (no direct code change, but relies on `getLiveTableSpecificMetadataColumns`).

## Phase 3: Synchronization Logic Updates
-   **`src/Service/TempToLiveSynchronizer.php`**
    -   [ ] Globally replace `$meta->batchRevision` with `$meta->lastModifiedRevisionId`.
    -   **Initial Bulk Import (`if ($countInt === 0)`):**
        -   [ ] Update `$colsToInsertLive` to include `$meta->createdRevisionId` and use `$meta->lastModifiedRevisionId`.
        -   [ ] Regenerate `$quotedColsToInsertLive` after updating `$colsToInsertLive`.
        -   [ ] Update column count check (e.g., `count($quotedColsToSelectTemp) + 2 !== count($quotedColsToInsertLive)`) to reflect two revision ID parameters.
        -   [ ] Modify `$sqlInitialInsert`'s `SELECT` clause to have two `?` placeholders for revision IDs.
        -   [ ] Update `$targetConn->executeStatement($sqlInitialInsert, ...)` to pass `$currentBatchRevisionId` twice.
    -   **Handle Updates:**
        -   [ ] Ensure `$setClausesForUpdate` only includes `$meta->lastModifiedRevisionId` (parameter is `$currentBatchRevisionId`).
        -   [ ] Confirm `$meta->createdRevisionId` is NOT part of the `SET` clause for updates.
    -   **Handle Deletes:**
        -   [ ] Confirm no changes needed related to new revision ID columns.
    -   **Handle Inserts (New Rows):**
        -   [ ] Update `$colsToInsertLiveForNew` to include `$meta->createdRevisionId` and use `$meta->lastModifiedRevisionId`.
        -   [ ] Regenerate `$quotedColsToInsertLiveForNew` after updating `$colsToInsertLiveForNew`.
        -   [ ] Update column count check (e.g., `count($quotedColsToSelectTempForNew) + 2 !== count($quotedColsToInsertLiveForNew)`) to reflect two revision ID parameters.
        -   [ ] Modify `$sqlInsert`'s `SELECT` clause to have two `?` placeholders for revision IDs.
        -   [ ] Update `$targetConn->executeStatement($sqlInsert, ...)` to pass `$currentBatchRevisionId` twice.

## Phase 4: Test Updates
-   **`tests/Unit/DTO/MetadataColumnNamesDTOTest.php`**
    -   [ ] Replace entire content with the new test structure provided in the plan.
    -   [ ] Verify `testConstructorWithDefaultValues()` asserts correct defaults for `createdRevisionId` and `lastModifiedRevisionId`.
    -   [ ] Verify `testConstructorWithCustomValues()` asserts correct custom values for `createdRevisionId` and `lastModifiedRevisionId`.
-   **Other Tests (e.g., `GenericTableSyncerTest.php`)**
    -   [ ] Review for any direct assertions or mocks related to the old `batchRevision` and update if necessary.

## Phase 5: Documentation Updates
-   **`README.md`**
    -   In "Customizing MetadataColumnNamesDTO" section:
        -   [ ] Add example for `$metadataColumns->createdRevisionId`.
        -   [ ] Update example from `$metadataColumns->batchRevision` to `$metadataColumns->lastModifiedRevisionId`.
    -   [ ] Update general descriptions of metadata columns to include `_syncer_created_revision_id` and `_syncer_last_modified_revision_id`.
    -   [ ] Add a note about manual migration steps for existing live tables.
-   **`CHANGELOG.md`**
    -   [ ] Add a new version entry.
    -   [ ] Under `### Added`, describe the introduction of `_syncer_created_revision_id`.
    -   [ ] Under `### Changed`, describe the renaming of `_syncer_batch_revision` to `_syncer_last_modified_revision_id`.

## Phase 6: Final Review & Considerations
-   [ ] Review all changes for consistency and correctness.
-   [ ] Consider the implications for users upgrading from previous versions (migration strategy documented).
-   [ ] Manually test the synchronization process with a sample setup if possible, focusing on:
    -   [ ] Initial sync to an empty table.
    -   [ ] Sync with new rows being inserted.
    -   [ ] Sync with existing rows being updated.
    -   [ ] Sync with rows being deleted.
    -   [ ] Verify `_syncer_created_revision_id` and `_syncer_last_modified_revision_id` are populated correctly in each scenario.
-   [ ] Run all automated tests (`composer test`).
-   [ ] Run static analysis (`composer stan`).
-   [ ] Run code style checks/fixes (`composer cs-check`, `composer cs-fix`).

---
This checklist should help ensure all aspects of the enhancement are addressed.

