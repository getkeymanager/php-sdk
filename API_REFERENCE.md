# PHP SDK - Complete API Reference (v2.0.0)

## Table of Contents

1. [License Validation & Activation](#license-validation--activation)
2. [License Management](#license-management)
3. [License Assignment](#license-assignment)
4. [License Metadata](#license-metadata)
5. [Product Management](#product-management)
6. [Product Metadata](#product-metadata)
7. [Generators](#generators)
8. [Contracts](#contracts)
9. [Downloadables](#downloadables)
10. [Telemetry](#telemetry)
11. [Changelog](#changelog)
12. [Utility Methods](#utility-methods)

---

## License Validation & Activation

### validateLicense()
Validate a license key online.

```php
$result = $client->validateLicense(
    string $licenseKey,
    array $options = []
): array
```

**Parameters:**
- `licenseKey` (string) - License key to validate
- `options` (array) - Optional: hardwareId, domain, productId

**Returns:** Array with validation result

**Example:**
```php
$result = $client->validateLicense('XXXXX-XXXXX-XXXXX-XXXXX', [
    'hardwareId' => $client->generateHardwareId()
]);
```

---

### activateLicense()
Activate a license on a device or domain.

```php
$result = $client->activateLicense(
    string $licenseKey,
    array $options = []
): array
```

**Parameters:**
- `licenseKey` (string) - License key
- `options` (array) - hardwareId OR domain required

**Example:**
```php
$result = $client->activateLicense('XXXXX-XXXXX-XXXXX-XXXXX', [
    'hardwareId' => $client->generateHardwareId(),
    'metadata' => ['version' => '1.0.0']
]);
```

---

### deactivateLicense()
Deactivate a license from a device or domain.

```php
$result = $client->deactivateLicense(
    string $licenseKey,
    array $options = []
): array
```

---

### checkFeature()
Check if a feature is enabled for a license.

```php
$result = $client->checkFeature(
    string $licenseKey,
    string $featureName
): array
```

---

### validateOfflineLicense()
Validate an offline license file.

```php
$result = $client->validateOfflineLicense(
    string|array $offlineLicenseData,
    array $options = []
): array
```

**Parameters:**
- `offlineLicenseData` - JSON string or parsed array
- `options` (array) - hardwareId, publicKey

---

## License Management

### createLicenseKeys()
Create multiple license keys.

```php
$result = $client->createLicenseKeys(
    string $productUuid,
    string $generatorUuid,
    array $licenses,
    ?string $customerEmail = null,
    array $options = []
): array
```

**Example:**
```php
$result = $client->createLicenseKeys(
    'product-uuid-here',
    'generator-uuid-here',
    [
        ['activation_limit' => 5, 'validity_days' => 365],
        ['activation_limit' => 1, 'validity_days' => 30]
    ],
    'customer@example.com'
);
```

---

### updateLicenseKey()
Update license key properties.

```php
$result = $client->updateLicenseKey(
    string $licenseKey,
    array $options = []
): array
```

**Options:**
- `status` - New status
- `activation_limit` - New activation limit
- `validity_days` - New validity period

**Example:**
```php
$result = $client->updateLicenseKey('XXXXX-XXXXX-XXXXX-XXXXX', [
    'activation_limit' => 10,
    'validity_days' => 365
]);
```

---

### deleteLicenseKey()
Delete a license key.

```php
$result = $client->deleteLicenseKey(string $licenseKey): array
```

---

### getLicenseKeys()
Retrieve license keys with filters.

```php
$result = $client->getLicenseKeys(array $filters = []): array
```

**Filters:**
- `product_uuid` - Filter by product
- `status` - Filter by status
- `customer_email` - Filter by customer

**Example:**
```php
$result = $client->getLicenseKeys([
    'product_uuid' => 'product-uuid',
    'status' => 'active'
]);
```

---

### getLicenseDetails()
Get detailed license information.

```php
$result = $client->getLicenseDetails(string $licenseKey): array
```

---

### getAvailableLicenseKeysCount()
Count available licenses for a product.

```php
$result = $client->getAvailableLicenseKeysCount(string $productUuid): array
```

---

## License Assignment

### assignLicenseKey()
Assign license to a customer.

```php
$result = $client->assignLicenseKey(
    string $licenseKey,
    string $customerEmail,
    ?string $customerName = null
): array
```

---

### randomAssignLicenseKeys()
Randomly assign licenses (synchronous).

```php
$result = $client->randomAssignLicenseKeys(
    string $productUuid,
    string $generatorUuid,
    int $quantity,
    string $customerEmail,
    ?string $customerName = null,
    array $options = []
): array
```

**Example:**
```php
$result = $client->randomAssignLicenseKeys(
    'product-uuid',
    'generator-uuid',
    5,
    'customer@example.com',
    'John Doe'
);
```

---

### randomAssignLicenseKeysQueued()
Randomly assign licenses (queued/async).

```php
$result = $client->randomAssignLicenseKeysQueued(
    string $productUuid,
    string $generatorUuid,
    int $quantity,
    string $customerEmail,
    ?string $customerName = null,
    array $options = []
): array
```

---

### assignAndActivateLicenseKey()
Assign and activate in one operation.

```php
$result = $client->assignAndActivateLicenseKey(
    string $licenseKey,
    string $customerEmail,
    string $identifier,
    array $options = []
): array
```

---

## License Metadata

### createLicenseKeyMeta()
Create license metadata.

```php
$result = $client->createLicenseKeyMeta(
    string $licenseKey,
    string $metaKey,
    mixed $metaValue
): array
```

---

### updateLicenseKeyMeta()
Update license metadata.

```php
$result = $client->updateLicenseKeyMeta(
    string $licenseKey,
    string $metaKey,
    mixed $metaValue
): array
```

---

### deleteLicenseKeyMeta()
Delete license metadata.

```php
$result = $client->deleteLicenseKeyMeta(
    string $licenseKey,
    string $metaKey
): array
```

---

## Product Management

### createProduct()
Create a new product.

```php
$result = $client->createProduct(
    string $name,
    array $options = []
): array
```

**Options:**
- `slug` - Product slug
- `description` - Product description
- `status` - Product status

---

### updateProduct()
Update product details.

```php
$result = $client->updateProduct(
    string $productUuid,
    array $options = []
): array
```

---

### deleteProduct()
Delete a product.

```php
$result = $client->deleteProduct(string $productUuid): array
```

---

### getAllProducts()
List all products.

```php
$result = $client->getAllProducts(): array
```

---

## Product Metadata

### createProductMeta()
Create product metadata.

```php
$result = $client->createProductMeta(
    string $productUuid,
    string $metaKey,
    mixed $metaValue
): array
```

---

### updateProductMeta()
Update product metadata.

```php
$result = $client->updateProductMeta(
    string $productUuid,
    string $metaKey,
    mixed $metaValue
): array
```

---

### deleteProductMeta()
Delete product metadata.

```php
$result = $client->deleteProductMeta(
    string $productUuid,
    string $metaKey
): array
```

---

## Generators

### getAllGenerators()
List all generators.

```php
$result = $client->getAllGenerators(?string $productUuid = null): array
```

---

### generateLicenseKeys()
Generate new license keys.

```php
$result = $client->generateLicenseKeys(
    string $generatorUuid,
    int $quantity,
    array $options = []
): array
```

**Options:**
- `activation_limit` - Activation limit
- `validity_days` - Validity period
- `idempotencyKey` - Custom idempotency key

---

## Contracts

### getAllContracts()
List all contracts.

```php
$result = $client->getAllContracts(): array
```

---

### createContract()
Create a new contract.

```php
$result = $client->createContract(
    array $contractData,
    array $options = []
): array
```

**Required fields in contractData:**
- `contract_key`
- `contract_name`
- `contract_information`
- `product_id`
- `license_keys_quantity`
- `status`
- `can_get_info`
- `can_generate`
- `can_destroy`
- `can_destroy_all`

---

### updateContract()
Update contract details.

```php
$result = $client->updateContract(
    int $contractId,
    array $contractData
): array
```

---

### deleteContract()
Delete a contract.

```php
$result = $client->deleteContract(int $contractId): array
```

---

## Downloadables

### accessDownloadables()
Access downloadable files for a license.

```php
$result = $client->accessDownloadables(
    string $licenseKey,
    string $identifier
): array
```

**Returns:** Array with downloadable files and signed URLs

---

## Telemetry

### sendTelemetry()
Send telemetry data.

```php
$result = $client->sendTelemetry(
    string $licenseKey,
    string $eventType,
    array $payload = [],
    array $metadata = []
): array
```

---

### getTelemetryData()
Retrieve telemetry data with filters.

```php
$result = $client->getTelemetryData(
    string $dataType,
    string $dataGroup,
    array $filters = []
): array
```

**Data Types:**
- `numeric-single-value`
- `numeric-xy-axis`
- `text`

**Filters:**
- `product_id`
- `user_identifier`
- `license_key`
- `has_red_flags`

---

## Changelog

### getProductChangelog()
Get product changelog (public, no auth required).

```php
$result = $client->getProductChangelog(string $slug): array
```

---

## Utility Methods

### generateHardwareId()
Generate hardware ID for current system.

```php
$hardwareId = $client->generateHardwareId(): string
```

---

### clearCache()
Clear all cache.

```php
$client->clearCache(): void
```

---

### clearLicenseCache()
Clear cache for specific license.

```php
$client->clearLicenseCache(string $licenseKey): void
```

---

## Exception Handling

All methods can throw the following exceptions:

- `LicenseException` - Base exception
- `ValidationException` - Validation errors
- `NetworkException` - Network errors
- `SignatureException` - Signature verification errors
- `RateLimitException` - Rate limit exceeded
- `ExpiredException` - License expired
- `SuspendedException` - License suspended
- `RevokedException` - License revoked

**Example:**
```php
use GetKeyManager\SDK\LicenseException;
use GetKeyManager\SDK\ValidationException;

try {
    $result = $client->validateLicense('XXXXX-XXXXX-XXXXX-XXXXX');
} catch (ValidationException $e) {
    // Handle validation error
} catch (LicenseException $e) {
    // Handle general license error
}
```
