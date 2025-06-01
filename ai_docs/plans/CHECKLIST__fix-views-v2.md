# Checklist: Implement `SourceObjectTypeEnum`

## Phase 1: Define the Enum

*   [ ] **Create Enum File:**
    *   **Action:** Create a new PHP file at `src/Enum/SourceObjectTypeEnum.php`.
    *   **Content:**
        ```php
        <?php

        namespace TopdataSoftwareGmbh\TableSyncer\Enum;

        enum SourceObjectTypeEnum: string
        {
            case TABLE = 'TABLE';
            case VIEW = 'VIEW';
            // Add other cases like INTROSPECTABLE_OBJECT_... if they are still relevant from earlier designs or future needs.
            // For the current simplified SourceIntrospector, TABLE and VIEW are primary.
            // The UNKNOWN case is also good for initialization.
            case UNKNOWN = 'UNKNOWN';
        }
        ```
    *   **Note for AI/Dev:** The initial plan included `INTROSPECTABLE_OBJECT_UNDETERMINED` and `INTROSPECTABLE_OBJECT_NOT_IN_LISTVIEWS`. Review the *latest* `SourceIntrospector` logic to confirm if these states are actually set. If not, they can be omitted from the Enum for now or kept for future-proofing. For the *last provided* simplified `SourceIntrospector`, `TABLE`, `VIEW`, and `UNKNOWN` are the most directly applicable. *Adjust the Enum cases based on the final logic of `SourceIntrospector`.*

## Phase 2: Update `SourceIntrospector.php` to Use the Enum

*   [ ] **Import Enum:**
    *   **Action:** In `src/Service/SourceIntrospection/SourceIntrospector.php`, add the `use` statement:
        ```php
        use TopdataSoftwareGmbh\TableSyncer\Enum\SourceObjectTypeEnum;
        ```

*   [ ] **Update `introspectSource` Method - Initialization:**
    *   **Locate:** The line where `$sourceTypeForLogging` is initialized.
    *   **Change From:** ` $sourceTypeForLogging = "UNKNOWN";`
    *   **Change To:** ` $sourceTypeForLogging = SourceObjectTypeEnum::UNKNOWN;`

*   [ ] **Update `introspectSource` Method - Table Path:**
    *   **Locate:** Inside the `if ($schemaManager->tablesExist([$sourceName]))` block.
    *   **Change Assignment:**
        *   **From:** `$sourceTypeForLogging = "TABLE";`
        *   **To:** `$sourceTypeForLogging = SourceObjectTypeEnum::TABLE;`
    *   **Update Logging (Example):**
        *   **From (Conceptual):** ` $this->logger->info("... identified as TABLE ...");`
        *   **To (Conceptual):** ` $this->logger->info("... identified as {$sourceTypeForLogging->value} ...");` (Or if the log message is static and clearly about tables, it might remain `"TABLE"` literal for that specific message, but the variable assignment must use the Enum).

*   [ ] **Update `introspectSource` Method - View Path:**
    *   **Locate:** Inside the `else` block (after `tablesExist`), within the `if ($this->isNameInListViews(...))` block.
    *   **Change Assignment:**
        *   **From:** `$sourceTypeForLogging = "VIEW";`
        *   **To:** `$sourceTypeForLogging = SourceObjectTypeEnum::VIEW;`
    *   **Update Logging (Example):**
        *   **From (Conceptual):** ` $this->logger->info("... identified as VIEW ...");`
        *   **To (Conceptual):** ` $this->logger->info("... identified as {$sourceTypeForLogging->value} ...");`

*   [ ] **General Logging Review:**
    *   **Action:** Scan all PSR-3 log calls within `SourceIntrospector.php` that use the `$sourceTypeForLogging` variable in their message.
    *   **Ensure:** If the variable `$sourceTypeForLogging` (which is now an Enum instance) is part of the log message string, its string value is accessed using `->value` (e.g., `"... type: {$sourceTypeForLogging->value} ..."`).

## Phase 3: Testing

*   [ ] **Unit Tests:**
    *   **Action:** Review and update unit tests for `SourceIntrospector` (likely in `tests/Unit/Service/SourceIntrospection/SourceIntrospectorTest.php` if it exists, or tests within `GenericSchemaManagerTest` that cover this).
    *   **Verify:** Assertions that check logged strings related to the source type should still expect the correct *string values* (e.g., `'TABLE'`, `'VIEW'`), as `SourceObjectTypeEnum::TABLE->value` will resolve to `'TABLE'`. The tests verify the outcome, while the code change ensures the internal representation is type-safe.

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

*   [ ] **Verify No Unused Enum Cases (if applicable):**
    *   **Action:** If `SourceObjectTypeEnum` includes cases like `INTROSPECTABLE_OBJECT_...`, ensure the final `SourceIntrospector` logic actually sets these states. If not, consider removing unused Enum cases or documenting why they are kept (e.g., for future expansion).

*   [ ] **Merge Changes:** Once all checks pass and testing is successful, merge the changes.

---

