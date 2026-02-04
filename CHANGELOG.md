# Changelog

All notable changes to the License Management PHP SDK will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.1.0] - 2026-02-04

### Added

#### Offline License Files
- `getLicenseFile()` - Retrieve .lic file content for offline validation
- `parseLicenseFile()` - Client-side .lic parsing (Base64 → 256-byte chunks → RSA PKCS1 → JSON)
- `syncLicenseAndKey()` - Sync local .lic/.key files with server + telemetry

#### Product & Metadata
- `getProductMeta()` - Retrieve product metadata key-value pairs
- `getProduct()` - Get product with features (by UUID or slug)
- `getProductPublicKey()` - Fetch product public key for signature verification

#### Public Changelog
- `getProductChangelog()` - Get product changelog entries

#### Validation Helpers
- `isCheckIntervalPast()` - Validate license check interval (fail-safe)
- `isForceValidationPast()` - Validate forced validation interval (fail-safe)

#### Response Codes
- LICENSE_FILE_RETRIEVED (502)
- LICENSE_FILE_GENERATION_FAILED (503)
- LICENSE_KEY_NOT_FOUND_DETAILS (501)
- PRODUCT_FOUND (631)
- PRODUCT_PUBLIC_KEY_FOUND (632)
- PRODUCT_PUBLIC_KEY_NOT_FOUND (633)

## [2.0.0] - 2024-01-15

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
