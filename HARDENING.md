# PHP SDK - Hardening & DX Improvements

## Version 2.0.0 - Major Architecture Refactoring & API Response Code Integration

This update introduces comprehensive security hardening, modular architecture, and standardized API response code handling to make the SDK more maintainable, resilient, and developer-friendly.

## What's New in v2.0

### 🎯 1. **Modular Architecture** (Major Refactoring)

The monolithic `LicenseClient.php` (1,915 lines) has been split into 11 focused, single-responsibility classes:

**Directory Structure:**
```
sdks/php/
├── LicenseClient.php          (522 lines - Main facade with delegation)
├── ApiResponseCode.php         (New - API response code constants)
├── Exceptions.php              (Enhanced with API code support)
├── StateResolver.php           (Enhanced exception mapping)
├── Config/
│   └── Configuration.php       (Configuration management)
├── Cache/
│   └── CacheManager.php        (Cache operations)
├── Http/
│   └── HttpClient.php          (HTTP requests & error handling)
├── Validation/
│   └── LicenseValidator.php    (License validation & activation)
├── Management/
│   ├── LicenseManager.php      (License CRUD & assignment)
│   ├── ProductManager.php      (Product management)
│   └── ContractManager.php     (Contract operations)
├── Features/
│   └── FeatureChecker.php      (Feature checking)
├── Telemetry/
│   └── TelemetryClient.php     (Telemetry operations)
└── Downloads/
    └── DownloadManager.php     (Downloads & changelogs)
```

**Benefits:**
- 📦 **Single Responsibility**: Each class has one clear purpose
- 🧪 **Testable**: Components can be tested independently
- 🔧 **Maintainable**: Easy to locate and modify functionality
- ⚡ **Performance**: Lazy loading reduces memory footprint
- 🔄 **Backward Compatible**: 100% compatible with existing code

### 🎯 2. **API Response Code Integration** (Standardized Error Handling)

All SDK exceptions now expose the numeric API response codes from the server:

```php
use GetKeyManager\SDK\ApiResponseCode;
use GetKeyManager\SDK\LicenseException;

try {
    $result = $client->validateLicense($licenseKey);
} catch (LicenseException $e) {
    // Get the numeric API response code (e.g., 205)
    $apiCode = $e->getApiCode();
    
    // Get the constant name (e.g., 'LICENSE_EXPIRED')
    $codeName = $e->getApiCodeName();
    
    // Get human-readable message
    $message = $e->getMessage();
    
    echo "Error {$apiCode} ({$codeName}): {$message}\n";
    
    // Handle specific codes
    switch ($apiCode) {
        case ApiResponseCode::LICENSE_EXPIRED:  // 205
            redirectToRenewalPage();
            break;
            
        case ApiResponseCode::LICENSE_BLOCKED:  // 204
            contactSupport();
            break;
            
        case ApiResponseCode::ACTIVATION_LIMIT_REACHED:  // 302
            showActivationLimitMessage();
            break;
    }
}
```

**API Response Code Categories:**
- **100-149**: API Key Related (INVALID_API_KEY, INSUFFICIENT_PERMISSIONS)
- **200-219**: License Verification (VALID_LICENSE_KEY, LICENSE_EXPIRED, LICENSE_BLOCKED)
- **300-349**: Activation (LICENSE_ACTIVATED, ACTIVATION_LIMIT_REACHED)
- **400-449**: Deactivation & Metadata
- **500-599**: License Details & Product Management
- **600-749**: Downloads & File Access
- **800-909**: License Assignment & Creation
- **950-979**: License Updates & Telemetry
- **1200-1599**: Contract API
- **1600-1649**: Changelog API

**Utility Methods:**
```php
// Check if code is success or error
if (ApiResponseCode::isSuccess(200)) {
    echo "Success!\n";
}

// Get code name for any numeric code
$name = ApiResponseCode::getName(205); // "LICENSE_EXPIRED"

// Get human-readable message
$message = ApiResponseCode::getMessage(302); // "License key reached activation limit"
```

### 🎯 3. **LicenseState & EntitlementState** (Single Source of Truth)

Instead of relying on simple boolean flags, the SDK now uses a unified state model:

```php
$state = $client->resolveLicenseState($licenseKey);

// Check state
echo $state->getState(); // ACTIVE, GRACE, RESTRICTED, INVALID
echo $state->isValid();  // true/false
echo $state->isActive(); // true/false
echo $state->isInGracePeriod(); // true/false
```

### 🎯 4. **Feature-Based Gating** (No More Global Boolean)

Every feature is checked individually:

```php
$state = $client->resolveLicenseState($licenseKey);

if ($state->allows('premium_features')) {
    // Enable premium functionality
}

if ($state->canUpdate()) {
    // Allow updates
}

if ($state->canDownload()) {
    // Allow downloads
}

$maxUsers = $state->getFeatureValue('max_users');
```

### 3. **Grace Period Support**

Applications continue functioning when revalidation fails temporarily:

```php
$state = $client->resolveLicenseState($licenseKey);

if ($state->isActive()) {
    // Full functionality
    enableAllFeatures();
} elseif ($state->isInGracePeriod()) {
    // Limited functionality - show warning
    enableBasicFeatures();
    showRevalidationWarning();
} else {
    // Restricted
    disableAllFeatures();
}
```

### 4. **Signed Payload Verification**

Every cached state is verified on read:

```php
$client = new LicenseClient([
    'apiKey' => 'your-api-key',
    'verifySignatures' => true,
    'publicKey' => $publicKeyPem,
]);

// All cached states are verified with signature
$state = $client->resolveLicenseState($licenseKey);
```

### 5. **Enhanced Exception Handling** (Better DX)

No more need for `dd()` to understand failures:

```php
try {
    $state = $client->resolveLicenseState($licenseKey);
} catch (ExpiredException $e) {
    echo "License expired on: " . date('Y-m-d', $e->getExpiredAt()) . "\n";
    echo "Days since expiration: " . $e->getDaysSinceExpiration() . "\n";
    
    // Check specific error code
    if ($e->isErrorCode(ExpiredException::ERROR_LICENSE_EXPIRED)) {
        redirectToRenewalPage();
    }
} catch (SuspendedException $e) {
    echo "License suspended\n";
} catch (NetworkException $e) {
    echo "Network error: " . $e->getMessage() . "\n";
    // Try cached state
}
```

### 6. **Capability-Based Protection**

Protect sensitive operations:

```php
try {
    // Require specific capability before allowing operation
    $state = $client->requireCapability($licenseKey, 'updates');
    
    // If we reach here, capability is granted
    $downloadUrl = generateSecureDownloadUrl($licenseKey);
    
} catch (StateException $e) {
    echo "Operation not allowed: " . $e->getMessage() . "\n";
}
```

## New Classes

### LicenseState (Public API)

The public-facing API for checking license state:

- `isValid()` - Check if license is valid (active or grace)
- `isActive()` - Check if license is fully active
- `isInGracePeriod()` - Check if in grace period
- `isExpired()` - Check if license has expired
- `allows(string $feature)` - Check if feature is allowed
- `getFeatureValue(string $feature)` - Get feature value/limit
- `canUpdate()` - Check if updates are allowed
- `canDownload()` - Check if downloads are allowed
- `canSendTelemetry()` - Check if telemetry should be sent
- `getState()` - Get current state (ACTIVE/GRACE/RESTRICTED/INVALID)
- `getStatusMessage()` - Get human-readable status
- `getExpiresAt()` - Get expiration timestamp
- `getDaysUntilExpiration()` - Get days until expiry
- `needsRevalidation()` - Check if revalidation needed
- `getFeatures()` - Get all features/capabilities
- `toArray()` - Serialize to array
- `toJson()` - Serialize to JSON

### EntitlementState (Internal)

Internal representation using domain-agnostic terminology to avoid obvious tampering targets.

### StateStore (Internal)

Manages cached state with signature verification on every read.

### StateResolver (Internal)

Resolves EntitlementState from API responses with proper signature verification.

## New Exception Classes

All exceptions now include:
- `getErrorCode()` - Get specific error code (string)
- `getApiCode()` - Get numeric API response code (int)
- `getApiCodeName()` - Get API response code constant name (string)
- `setApiCode(int $code)` - Set API response code
- `getErrorDetails()` - Get additional error details
- `isErrorCode(string $code)` - Check if matches specific error code
- `toArray()` - Serialize for logging (includes API code info)

**Exception Hierarchy:**
```
LicenseException (base)
├── ValidationException
├── NetworkException
├── SignatureException
├── RateLimitException
├── LicenseStatusException
│   ├── ExpiredException
│   ├── SuspendedException
│   └── RevokedException
├── ActivationException
├── FeatureException
└── StateException
```

**Exception Enhancement Example:**
```php
try {
    $result = $client->validateLicense($licenseKey);
} catch (LicenseException $e) {
    // Enhanced exception with API response code
    echo "HTTP Status: " . $e->getCode() . "\n";          // 400
    echo "Error Code: " . $e->getErrorCode() . "\n";      // "LICENSE_EXPIRED"
    echo "API Code: " . $e->getApiCode() . "\n";          // 205
    echo "API Code Name: " . $e->getApiCodeName() . "\n"; // "LICENSE_EXPIRED"
    echo "Message: " . $e->getMessage() . "\n";           // "License key expired"
    
    // Full exception info
    $info = $e->toArray();
    // Includes: exception, message, code, error_code, api_response_code, api_code_name, details, file, line
}
```

## New Methods

### `resolveLicenseState(string $licenseKey, array $options = []): LicenseState`

Resolves license state with hardened validation, signature verification, and grace period support.

```php
$state = $client->resolveLicenseState('LICENSE-KEY', [
    'hardwareId' => 'hardware-id',
    'domain' => 'example.com',
]);
```

### `isFeatureAllowed(string $licenseKey, string $feature): bool`

Check if a specific feature is allowed:

```php
if ($client->isFeatureAllowed($licenseKey, 'premium_features')) {
    // Enable premium features
}
```

### `getLicenseState(string $licenseKey, array $options = []): LicenseState`

Get license state without throwing exceptions (returns restricted state on error):

```php
$state = $client->getLicenseState($licenseKey);
if ($state->isValid()) {
    // Use license
}
```

### `requireCapability(string $licenseKey, string $capability): LicenseState`

Require a specific capability or throw exception:

```php
try {
    $state = $client->requireCapability($licenseKey, 'updates');
    // Capability granted
} catch (StateException $e) {
    // Capability not allowed
}
```

### `clearLicenseState(string $licenseKey): void`

Clear all cached state for a license:

```php
$client->clearLicenseState($licenseKey);
```

## Backward Compatibility

All existing methods remain unchanged and continue to work:

- `validateLicense()` - Still returns raw API response
- `activateLicense()` - Unchanged
- `deactivateLicense()` - Unchanged
- `checkFeature()` - Unchanged

New state-based methods are additions, not replacements.

## Migration Guide

### Before (v2.0):

```php
$result = $client->validateLicense($licenseKey);
if ($result['valid']) {
    // Enable features
}
```

### After (v2.1) - Recommended:

```php
$state = $client->resolveLicenseState($licenseKey);
if ($state->isValid()) {
    // Enable features
}

if ($state->allows('premium')) {
    // Enable premium features
}
```

## Configuration Changes

New optional configuration options:

```php
$client = new LicenseClient([
    'apiKey' => 'your-api-key',
    'baseUrl' => 'https://api.getkeymanager.com',
    'verifySignatures' => true,  // Recommended: always verify
    'publicKey' => $publicKeyPem, // Required for signature verification
    'environment' => 'production', // Used in state context
    'productId' => 'product-uuid', // Used in state context
    'cacheEnabled' => true,
    'cacheTtl' => 300,
]);
```

## Security Benefits

1. **Signed Payloads** - All cached states verified with RSA signatures
2. **Grace Periods** - Graceful degradation instead of hard failures
3. **Multiple Enforcement Layers** - Feature gates, downloads, updates, telemetry all check state
4. **No Global Boolean** - Can't simply set `$licensed = true`
5. **Internal Abstraction** - Internal logic uses "EntitlementState" not "License"
6. **State-Based Logic** - All decisions based on state, not simple flags

## Anti-Tampering Measures

The following techniques make trivial bypasses ineffective:

1. **Signature Verification** - Cached data verified on every read
2. **Grace Period Logic** - Can't be bypassed by simple `return true`
3. **Multiple Surfaces** - Middleware, feature gates, downloads, CLI all check state independently
4. **Context Binding** - State includes domain/hardware ID verification
5. **Validity Windows** - Time-based restrictions enforced
6. **Capability Model** - Feature-specific checks throughout application

## Examples

See `examples-hardened.php` for comprehensive examples of all new features.

## Testing

Run the test suite:

```bash
composer test
```

Run hardening feature tests:

```bash
php test-hardening.php
```

## Support

- Documentation: https://docs.getkeymanager.com
- Issues: https://github.com/getkeymanager/php-sdk/issues
- Email: support@getkeymanager.com

## License

MIT License - see LICENSE file for details
