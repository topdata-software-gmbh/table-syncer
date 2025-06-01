## Refactoring Checklist: Unified Source Introspection

**Project:** Table Syncer
**Goal:** Refactor `GenericSchemaManager` to use a new `SourceIntrospector` class for handling table and view sources.

---

### Phase 1: Create the `SourceIntrospector` Class

*   [x] **Directory Created:**
    *   Path: `src/Service/SourceIntrospection/` exists.
*   [x] **File `SourceIntrospector.php` Created:**
    *   Path: `src/Service/SourceIntrospection/SourceIntrospector.php` exists.
*   [x] **`SourceIntrospector.php` - Namespace Correct:**
    *   Namespace is `TopdataSoftwareGmbh\TableSyncer\Service\SourceIntrospection`.
*   [x] **`SourceIntrospector.php` - Imports Correct:**
    *   Includes `Doctrine\DBAL\Connection`.
    *   Includes `Doctrine\DBAL\Schema\Exception\TableNotFoundException`.
    *   Includes `Doctrine\DBAL\Types\Type`.
    *   Includes `Psr\Log\LoggerInterface`.
    *   Includes `Psr\Log\NullLogger`.
    *   Includes `TopdataSoftwareGmbh\TableSyncer\Exception\ConfigurationException`.
    *   Includes `Doctrine\DBAL\Schema\Column as DbalColumn`.
*   [x] **`SourceIntrospector.php` - Class Definition Correct:**
    *   Class name is `SourceIntrospector`.
    *   Has a private readonly property `$logger` of type `LoggerInterface`.
    *   Constructor accepts an optional `?LoggerInterface $logger` and initializes `$this->logger`.
*   [x] **`SourceIntrospector.php` - `introspectSource` Method:**
    *   Signature is `public function introspectSource(Connection $sourceConnection, string $sourceName): array`.
    *   Contains logic to check for table existence using `$schemaManager->tablesExist()`.
    *   Contains logic to check for view existence using `method_exists($schemaManager, 'viewsExist')` and `$schemaManager->viewsExist()` OR fallback to `$schemaManager->listViews()`.
    *   Contains logic for a final direct introspection attempt using `$schemaManager->introspectTable()` wrapped in a try-catch for `TableNotFoundException`.
    *   Throws `ConfigurationException` if source cannot be identified or introspected.
    *   Calls `extractColumnDefinitions` upon successful identification and introspection.
    *   Includes appropriate debug/info/warning logging statements.
*   [x] **`SourceIntrospector.php` - `extractColumnDefinitions` Method:**
    *   Signature is `private function extractColumnDefinitions(array $dbalColumns): array`.
    *   Type hint for `$dbalColumns` is `DbalColumn[]`.
    *   Correctly iterates through `$dbalColumns` and populates the definitions array with all required keys (`name`, `type`, `length`, `precision`, `scale`, `unsigned`, `fixed`, `notnull`, `default`, `autoincrement`, `platformOptions`, `comment`).
    *   Uses `Type::lookupName($dbalColumn->getType())` for the 'type' field.

---

### Phase 2: Refactor `GenericSchemaManager`

*   [x] **File `GenericSchemaManager.php` - Import Added:**
    *   `use TopdataSoftwareGmbh\TableSyncer\Service\SourceIntrospection\SourceIntrospector;` is present.
*   [x] **File `GenericSchemaManager.php` - Property Updated:**
    *   Property is `private readonly SourceIntrospector $sourceIntrospector;`.
    *   (Previous `sourceIntrospectors` array property is removed).
*   [x] **File `GenericSchemaManager.php` - Constructor Updated:**
    *   Signature is `public function __construct(?LoggerInterface $logger = null, ?SourceIntrospector $sourceIntrospector = null)`.
    *   Correctly initializes `$this->sourceIntrospector` (either with the provided instance or a new default instance).
*   [x] **File `GenericSchemaManager.php` - `getSourceColumnDefinitions` Method Updated:**
    *   Method body is replaced with the new logic that delegates to `$this->sourceIntrospector->introspectSource()`.
    *   Includes caching logic for `$sourceColumnDefinitionsCache` and `$cachedSourceTableName`.
    *   Handles `ConfigurationException` and other `Throwable` exceptions from the introspector call.
*   [x] **File `GenericSchemaManager.php` - Unused Method Removed (If Applicable):**
    *   The `getDbalTypeNameFromTypeObject` method is removed if it's no longer used within `GenericSchemaManager` itself.

---

### Phase 3: Cleanup (If Applicable)

*   [ ] **Intermediary Files Deleted (If Created Previously):**
    *   Verify that `src/Service/SourceIntrospection/SourceIntrospectorInterface.php` is deleted.
    *   Verify that `src/Service/SourceIntrospection/TableSourceIntrospector.php` is deleted.
    *   Verify that `src/Service/SourceIntrospection/ViewSourceIntrospector.php` is deleted.
    *   Verify that `src/Service/SourceIntrospection/ColumnDefinitionExtractorTrait.php` (if created) is deleted.

---

### Phase 4: Verification (Conceptual)

*   [ ] **Unit Tests:** Conceptually, all existing relevant unit tests for `GenericSchemaManager` pass.
*   [ ] **Functionality:** Conceptually, the Table Syncer now correctly processes sources that are database views.
*   [ ] **Logging:** Conceptually, new log messages from `SourceIntrospector` are observed and are accurate.

