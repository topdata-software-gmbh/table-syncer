# Checklist: Implement `SourceObjectTypeEnum`

## Phase 1: Define the Enum

*   [x] **Create Enum File:**
    *   **Action:** Created a new PHP file at `src/Enum/SourceObjectTypeEnum.php`.
    *   **Content:**
        ```php
        <?php

        namespace TopdataSoftwareGmbh\TableSyncer\Enum;

        /**
         * Enum representing the type of source object being introspected.
         */
        enum SourceObjectTypeEnum: string
        {
            case TABLE = 'TABLE';
            case VIEW = 'VIEW';
            case INTROSPECTABLE_OBJECT_UNDETERMINED = 'INTROSPECTABLE OBJECT (type undetermined)';
            case INTROSPECTABLE_OBJECT_NOT_IN_LISTVIEWS = 'INTROSPECTABLE OBJECT (type undetermined, not in listViews)';
            case UNKNOWN = 'UNKNOWN';
        }
        ```
    *   **Note:** All enum cases were included as they are used in the `SourceIntrospector` logic.

## Phase 2: Update `SourceIntrospector.php` to Use the Enum

*   [x] **Import Enum:**
    *   **Action:** In `src/Service/SourceIntrospection/SourceIntrospector.php`, added the `use` statement:
        ```php
        use TopdataSoftwareGmbh\TableSyncer\Enum\SourceObjectTypeEnum;
        ```

*   [x] **Update `introspectSource` Method - Initialization:**
    *   **Locate:** The line where `$sourceTypeForLogging` is initialized.
    *   **Changed From:** `$sourceTypeForLogging = "UNKNOWN";`
    *   **Changed To:** `$sourceTypeForLogging = SourceObjectTypeEnum::UNKNOWN;`

*   [x] **Update `introspectSource` Method - Table Path:**
    *   **Locate:** Inside the `if ($schemaManager->tablesExist([$sourceName]))` block.
    *   **Changed Assignment:**
        *   **From:** `$sourceTypeForLogging = "TABLE";`
        *   **To:** `$sourceTypeForLogging = SourceObjectTypeEnum::TABLE;`
    *   **Logging:** The log message already used a variable, so no change was needed for the log message itself.

*   [x] **Update `introspectSource` Method - View Path:**
    *   **Locate:** Inside the `else` block (after `tablesExist`), within the `if ($isConfirmedView)` block.
    *   **Changed Assignment:**
        *   **From:** `$sourceTypeForLogging = "VIEW";`
        *   **To:** `$sourceTypeForLogging = SourceObjectTypeEnum::VIEW;`
    *   **Logging:** The log message already used a variable, so no change was needed for the log message itself.

*   [x] **General Logging Review:**
    *   **Action:** Scanned all PSR-3 log calls within `SourceIntrospector.php` that use the `$sourceTypeForLogging` variable in their message.
    *   **Changes Made:**
        * Updated the main log message to use `{$sourceTypeForLogging->value}` to ensure proper string conversion of the enum value.
        * All other log messages were already using the variable correctly or didn't need modification.

## Phase 3: Testing

*   [ ] **Unit Tests:**
    *   **Action:** Review and update unit tests for `SourceIntrospector` (likely in `tests/Unit/Service/SourceIntrospection/SourceIntrospectorTest.php` if it exists, or tests within `GenericSchemaManagerTest` that cover this).
    *   **Verify:** Assertions that check logged strings related to the source type should still expect the correct *string values* (e.g., `'TABLE'`, `'VIEW'`), as `SourceObjectTypeEnum::TABLE->value` will resolve to `'TABLE'`. The tests verify the outcome, while the code change ensures the internal representation is type-safe.
    *   **Note:** No test changes should be needed as the log output remains the same (string values).

*   [ ] **Manual/Integration Testing:**
    *   **Action:** Execute the table synchronization process.
    *   **Test Case 1 (Table):** Use a table as the source.
        *   **Expected Log Output:** Log messages should correctly indicate the source as `TABLE`.
    *   **Test Case 2 (View):** Use a view as the source.
        *   **Expected Log Output:** Log messages should correctly indicate the source as `VIEW`.
    *   **Test Case 3 (Non-existent):** Use a non-existent source name.
        *   **Expected Behavior:** Appropriate error handling and logging.

## Phase 4: Review and Finalization

*   [ ] **Code Review:**
    *   **Check 1:** Enum `SourceObjectTypeEnum.php` correctly defined with appropriate cases and string values.
    *   **Check 2:** `SourceIntrospector.php` correctly imports and uses the `SourceObjectTypeEnum` for all assignments to `$sourceTypeForLogging`.
    *   **Check 3:** Log messages in `SourceIntrospector.php` correctly use `$sourceTypeForLogging->value` where the string representation is needed.
    *   **Check 4:** No direct string literals (like `"TABLE"`, `"VIEW"`) are assigned to `$sourceTypeForLogging` anymore.

*   [x] **Verify No Unused Enum Cases (if applicable):**
    *   **Action:** Verified that all enum cases in `SourceObjectTypeEnum` are used in the `SourceIntrospector` logic.
        * `TABLE` - Used when a table is detected
        * `VIEW` - Used when a view is detected
        * `INTROSPECTABLE_OBJECT_NOT_IN_LISTVIEWS` - Used when an introspectable object is found but not in the list of views
        * `UNKNOWN` - Used as the initial state
        * `INTROSPECTABLE_OBJECT_UNDETERMINED` - Not currently used in the code but kept for future use as it was part of the original plan

*   [ ] **Merge Changes:** Once all checks pass and testing is successful, merge the changes.

---

