# Changelog

All notable changes to the License Management PHP SDK will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2024-01-15

### Added

#### License Management
- `createLicenseKeys()` - Create multiple license keys
- `updateLicenseKey()` - Update license key properties
- `deleteLicenseKey()` - Delete a license key
- `getLicenseKeys()` - Retrieve license keys with filters
- `getLicenseDetails()` - Get detailed license information
- `getAvailableLicenseKeysCount()` - Count available licenses for a product

#### License Assignment
- `assignLicenseKey()` - Assign license to a customer
- `randomAssignLicenseKeys()` - Randomly assign licenses (synchronous)
- `randomAssignLicenseKeysQueued()` - Randomly assign licenses (queued/async)
- `assignAndActivateLicenseKey()` - Assign and activate in one operation

#### License Metadata
- `createLicenseKeyMeta()` - Create license metadata
- `updateLicenseKeyMeta()` - Update license metadata
- `deleteLicenseKeyMeta()` - Delete license metadata

#### Product Management
- `createProduct()` - Create a new product
- `updateProduct()` - Update product details
- `deleteProduct()` - Delete a product
- `getAllProducts()` - List all products

#### Product Metadata
- `createProductMeta()` - Create product metadata
- `updateProductMeta()` - Update product metadata
- `deleteProductMeta()` - Delete product metadata

#### Generators
- `getAllGenerators()` - List all generators
- `generateLicenseKeys()` - Generate new license keys

#### Contracts
- `getAllContracts()` - List all contracts
- `createContract()` - Create a new contract
- `updateContract()` - Update contract details
- `deleteContract()` - Delete a contract

#### Downloadables
- `accessDownloadables()` - Access downloadable files for a license

#### Telemetry
- `getTelemetryData()` - Retrieve telemetry data with filters

#### Public Endpoints
- `getProductChangelog()` - Get product changelog (no auth required)

### Changed
- Updated SDK version to 2.0.0
- Enhanced error handling for all new endpoints
- Improved idempotency key support across all create/generate operations
- Expanded cache management for new endpoints

### Notes
- All new methods support signature verification
- All new methods follow PSR-12 coding standards
- Backward compatible with 1.x - all existing methods unchanged

## [1.0.0] - 2023-12-01

### Added
- Initial release
- Basic license validation (online and offline)
- License activation and deactivation
- Feature flag checking
- Hardware ID generation
- Telemetry submission
- RSA-4096-SHA256 signature verification
- Automatic retry with exponential backoff
- Built-in caching
- Idempotent operations
