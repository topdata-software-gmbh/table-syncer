# Changelog

## [1.1.0] - 2025-06-15
### Added
- Optional deletion logging feature to track deleted records
- New configuration options in TableSyncConfigDTO for enabling deletion logging
- Automatic creation of deletion log table with appropriate schema
- Enhanced SyncReportDTO with logged deletions count
- Comprehensive documentation for the deletion logging feature

## [1.0.1] - 2025-05-31
### Added
- Enhanced error handling in synchronization process
- Improved transaction management in GenericTableSyncer
- More detailed logging with contextual information
- Complete documentation of service architecture with dependency injection

### Changed
- Refactored GenericTableSyncer to use proper dependency injection
- Updated SQL operations to be fully transactional
- Improved handling of datetime placeholder values
- Enhanced error messaging for troubleshooting

### Fixed
- Fixed transaction handling to ensure proper rollback on errors
- Improved error propagation throughout the synchronization process

## [1.0.0] - 2025-05-30
### Added
- Initial release of Table Syncer library
- Core database table synchronization functionality
- Support for column name mapping between source and target tables
- Staging table approach for efficient data synchronization
- PSR-3 logging support for monitoring and debugging
- Customizable metadata column handling
- Support for non-nullable datetime columns with placeholder values
- Comprehensive DTO classes for configuration and reporting
- Robust error handling with custom exceptions

### Changed
- (None in initial release)

### Fixed
- (None in initial release)

### Removed
- (None in initial release)