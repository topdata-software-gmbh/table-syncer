## Plan for DBAL 4.x Only Update and `SourceIntrospector` Fix

**Objective:**
1.  Modify `SourceIntrospector` to correctly identify views for logging purposes using DBAL 4.x APIs.
2.  Update `composer.json` to strictly require `doctrine/dbal: ^4.0`.
3.  Update `README.md` to reflect DBAL 4.x as the minimum requirement.
4.  Review and potentially enhance unit tests for `SourceIntrospector`.

---

**Phase 1: Code Modifications (`SourceIntrospector.php`)**

1.  **File:** `src/Service/SourceIntrospection/SourceIntrospector.php`
2.  **Modify `introspectSource` method:**
    *   The primary goal here is to determine `sourceTypeForLogging` correctly.
    *   Since `introspectTable()` works for views in DBAL, the main differentiation is for logging.
    *   The logic should be:
        *   Attempt `introspectTable()`.
        *   If successful:
            *   Check `tablesExist()`. If true, `sourceTypeForLogging = "TABLE"`.
            *   If `tablesExist()` is false, then assume it's a view (or other introspectable object). Attempt to confirm by checking `listViews()`:
                *   Call `$schemaManager->listViews()`.
                *   Iterate through the `View` objects returned.
                *   Compare the name of each `View` (using `$view->getName()` and/or `$view->getQuotedName($platform)`) with the input `$sourceName`. Use `Doctrine\DBAL\Schema\Identifier` for robust name comparison (handles quoting and case sensitivity differences).
                *   If a match is found, `sourceTypeForLogging = "VIEW"`.
                *   If no match is found in `listViews()` (but `introspectTable` succeeded and it's not a table), then `sourceTypeForLogging = "INTROSPECTABLE OBJECT (type undetermined, not in listViews)"`.
        *   If `introspectTable()` throws `TableNotFoundException`, handle as before.

    **Revised Logic Snippet for `introspectSource`:**
    ```php
    // Inside src/Service/SourceIntrospection/SourceIntrospector.php
    // Make sure this 'use' statement is present at the top of the file:
    // use Doctrine\DBAL\Schema\Identifier;

    public function introspectSource(Connection $sourceConnection, string $sourceName): array
    {
        $schemaManager = $sourceConnection->createSchemaManager();
        $sourceTypeForLogging = "UNKNOWN"; // Initialize

        try {
            $this->logger->debug("Attempting to introspect source '{$sourceName}' directly (works for tables and views).");
            $tableDetails = $schemaManager->introspectTable($sourceName); // This works for views too in DBAL

            // If introspectTable succeeded, it's either a table or a view (or similar)
            if ($schemaManager->tablesExist([$sourceName])) {
                $sourceTypeForLogging = "TABLE";
            } else {
                // Not a table, so let's check if it's a known view
                $isConfirmedView = false;
                try {
                    $views = $schemaManager->listViews();
                    $platform = $sourceConnection->getDatabasePlatform();
                    
                    // Normalize the input $sourceName once for comparison
                    $inputIdentifier = new Identifier($sourceName); // Handles if $sourceName is already quoted or contains schema
                    $unquotedInputName = $inputIdentifier->getName(); // Gets the simple unquoted name
                    $quotedInputNamePlatform = $inputIdentifier->getQuotedName($platform); // Gets platform-specific quoted name

                    foreach ($views as $view) {
                        // Compare unquoted names (often sufficient and more robust to case variations if unquoted)
                        // $view->getName() might return 'schema.viewname' or just 'viewname'
                        $viewIdentifier = new Identifier($view->getName());
                        if (strcasecmp($viewIdentifier->getName(), $unquotedInputName) === 0) {
                             // Check if schemas match if both $sourceName and view name include one
                            if ($inputIdentifier->getNamespaceName() === $viewIdentifier->getNamespaceName() ||
                                $inputIdentifier->getNamespaceName() === null || // $sourceName had no schema, view might
                                $viewIdentifier->getNamespaceName() === null  // view had no schema, $sourceName might
                            ) {
                                $isConfirmedView = true;
                                break;
                            }
                        }
                        // Fallback: Compare platform-specific quoted names
                        if ($view->getQuotedName($platform) === $quotedInputNamePlatform) {
                            $isConfirmedView = true;
                            break;
                        }
                    }
                } catch (\Doctrine\DBAL\Exception $e) {
                    $this->logger->warning(
                        "Could not list views to determine type for '{$sourceName}' (after successful introspection, and not a table). Error: " . $e->getMessage(),
                        ['exception_class' => get_class($e)]
                    );
                    // Keep $isConfirmedView = false
                }

                if ($isConfirmedView) {
                    $sourceTypeForLogging = "VIEW";
                } else {
                    // If introspectTable worked, it's not a table, and not found in listViews,
                    // it's an "introspectable object (type undetermined)".
                    $sourceTypeForLogging = "INTROSPECTABLE OBJECT (type undetermined, not in listViews)";
                }
            }

            $this->logger->info("Successfully introspected '{$sourceName}' (identified as {$sourceTypeForLogging}). Extracting column definitions.");
            return $this->extractColumnDefinitions($tableDetails->getColumns());

        } catch (TableNotFoundException $e) {
            $this->logger->error("Source '{$sourceName}' not found or not introspectable (via introspectTable). Error: " . $e->getMessage(), ['exception_class' => get_class($e)]);
            throw new ConfigurationException("Source table or view '{$sourceName}' does not exist or is not accessible/introspectable in the source database `{$sourceConnection->getDatabase()}`.", 0, $e);
        } catch (\Throwable $e) {
            $this->logger->error("Unexpected error while introspecting source '{$sourceName}'. Error: " . $e->getMessage(), ['exception_class' => get_class($e)]);
            throw new ConfigurationException("Error while trying to introspect source '{$sourceName}': " . $e->getMessage(), 0, $e);
        }
    }
    ```

---

**Phase 2: Dependency and Documentation Updates**

1.  **File:** `composer.json`
    *   Change the `doctrine/dbal` requirement:
        ```json
        "require": {
            "php": "^8.0 || ^8.1 || ^8.2 || ^8.3",
            "doctrine/dbal": "^4.0", // Changed from "^3.0 || ^4.0"
            "psr/log": "^1.0 || ^2.0 || ^3.0"
        },
        ```
    *   Run `composer update doctrine/dbal` locally to ensure your `composer.lock` reflects this change and to test.

2.  **File:** `README.md`
    *   Update the "Requirements" section:
        ```markdown
        ## Requirements

        - PHP 8.0 or higher
        - Doctrine DBAL 4.0 or higher // Changed from "3.0 or higher"
        - PSR-3 compatible logger implementation
        ```

---

**Phase 3: Testing**

1.  **Review Existing Tests:**
    *   Examine `tests/Unit/Service/GenericSchemaManagerTest.php` (as `SourceIntrospector` is a dependency of `GenericSchemaManager`).
    *   The current tests for `GenericSchemaManager` are very basic and likely do not cover `SourceIntrospector` logic in detail or specific view introspection scenarios.
    *   Ideally, `SourceIntrospector` should have its own dedicated unit test class.

2.  **Create/Enhance Unit Tests for `SourceIntrospector`:**
    *   Create `tests/Unit/Service/SourceIntrospection/SourceIntrospectorTest.php`.
    *   **Test Scenarios:**
        *   **Scenario 1: Source is a Table**
            *   Mock `Connection` and `SchemaManager`.
            *   Mock `SchemaManager::introspectTable()` to return a mock `Table` object.
            *   Mock `SchemaManager::tablesExist()` to return `true`.
            *   Assert that `SourceIntrospector::introspectSource()` returns the expected column definitions and logs "TABLE".
        *   **Scenario 2: Source is a View**
            *   Mock `Connection` and `SchemaManager`.
            *   Mock `SchemaManager::introspectTable()` to return a mock `Table` object (as it works for views).
            *   Mock `SchemaManager::tablesExist()` to return `false`.
            *   Mock `SchemaManager::listViews()` to return an array containing a mock `View` object whose name (or quoted name) matches the input source name.
            *   Assert that `SourceIntrospector::introspectSource()` returns the expected column definitions and logs "VIEW".
        *   **Scenario 3: Source is Introspectable but Not a Table and Not in `listViews`**
            *   Mock `Connection` and `SchemaManager`.
            *   Mock `SchemaManager::introspectTable()` to return a mock `Table` object.
            *   Mock `SchemaManager::tablesExist()` to return `false`.
            *   Mock `SchemaManager::listViews()` to return an empty array or views with non-matching names.
            *   Assert that `SourceIntrospector::introspectSource()` returns column definitions and logs "INTROSPECTABLE OBJECT (type undetermined, not in listViews)".
        *   **Scenario 4: Source Not Found**
            *   Mock `Connection` and `SchemaManager`.
            *   Mock `SchemaManager::introspectTable()` to throw `TableNotFoundException`.
            *   Assert that `SourceIntrospector::introspectSource()` throws `ConfigurationException`.
        *   **Scenario 5: `listViews()` throws an exception**
            *   Mock `Connection` and `SchemaManager`.
            *   Mock `SchemaManager::introspectTable()` to return a mock `Table` object.
            *   Mock `SchemaManager::tablesExist()` to return `false`.
            *   Mock `SchemaManager::listViews()` to throw a `\Doctrine\DBAL\Exception`.
            *   Assert correct logging ("Could not list views...") and that `sourceTypeForLogging` becomes "INTROSPECTABLE OBJECT (type undetermined, not in listViews)".
    *   Ensure mocks for `View::getName()` and `View::getQuotedName()` are set up correctly in view-related test scenarios.
    *   Use `Doctrine\DBAL\Platforms\AbstractPlatform` (e.g., `MySQLPlatform`) mock for `getQuotedName()`.

3.  **Integration Testing (Manual or Automated):**
    *   If possible, test against a real database setup:
        *   With a source that is a table.
        *   With a source that is a view.
        *   Ensure the synchronization process completes and the logs correctly identify the source type.

---

**Phase 4: Review and Merge**

1.  **Code Review:** Have the changes reviewed, focusing on:
    *   Correctness of the DBAL 4.x API usage in `SourceIntrospector`.
    *   Robustness of name comparison using `Doctrine\DBAL\Schema\Identifier`.
    *   Clarity of logging messages.
2.  **QA:** Test the application flow that utilizes the `TableSyncer` library to ensure no regressions and that view synchronization works as expected.
3.  **Merge** changes to the main branch.
4.  **Tag Release:** Consider tagging a new minor or patch version due to the change in DBAL version support and bug fix.


