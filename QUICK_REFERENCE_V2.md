# SDK v2.0 Quick Reference Card

## 🆕 What's New in v2.0

### 1. API Response Codes

All exceptions now expose numeric API response codes:

```php
use GetKeyManager\SDK\ApiResponseCode;

try {
    $result = $client->validateLicense($key);
} catch (LicenseException $e) {
    // NEW: Get API response code
    $apiCode = $e->getApiCode();        // 205
    $codeName = $e->getApiCodeName();   // "LICENSE_EXPIRED"
    
    // Handle specific codes
    if ($apiCode === ApiResponseCode::LICENSE_EXPIRED) {
        // Code 205: License expired
    }
}
```

### 2. Common API Response Codes

| Code | Constant | Meaning |
|------|----------|---------|
| 200 | VALID_LICENSE_KEY | License is valid |
| 202 | NO_ACTIVATION_FOUND | Not activated |
| 204 | LICENSE_BLOCKED | License blocked |
| 205 | LICENSE_EXPIRED | License expired |
| 210 | INVALID_LICENSE_KEY | Invalid key |
| 300 | LICENSE_ACTIVATED | Activation success |
| 302 | ACTIVATION_LIMIT_REACHED | No more activations |
| 400 | LICENSE_DEACTIVATED | Deactivation success |

[See full list: API_RESPONSE_CODES.md]

### 3. Modular Architecture

The SDK is now organized into focused modules:

```
sdks/php/
├── LicenseClient.php          # Main facade (use this)
├── ApiResponseCode.php        # Response code constants
├── Config/                    # Configuration
├── Cache/                     # Caching
├── Http/                      # HTTP communication
├── Validation/                # License validation
├── Management/                # CRUD operations
├── Features/                  # Feature checking
├── Telemetry/                 # Telemetry
└── Downloads/                 # Downloads & changelogs
```

**Note**: You only need to use `LicenseClient` - it handles all delegation internally.

## 📝 Basic Usage (Unchanged)

```php
use GetKeyManager\SDK\LicenseClient;

$client = new LicenseClient([
    'apiKey' => 'your-api-key',
    'baseUrl' => 'https://api.getkeymanager.com',
    'verifySignatures' => true,
    'publicKey' => file_get_contents('/path/to/public.pem'),
]);

// All existing methods work exactly the same
$result = $client->validateLicense('LICENSE-KEY');
```

## 🎯 Error Handling Patterns

### Pattern 1: Check Specific API Codes

```php
try {
    $result = $client->validateLicense($key);
} catch (LicenseException $e) {
    switch ($e->getApiCode()) {
        case ApiResponseCode::LICENSE_EXPIRED:
            // Handle expiration
            break;
        case ApiResponseCode::ACTIVATION_LIMIT_REACHED:
            // Handle activation limit
            break;
        default:
            // Generic handling
    }
}
```

### Pattern 2: Check Exception Type

```php
use GetKeyManager\SDK\ExpiredException;
use GetKeyManager\SDK\ActivationException;

try {
    $result = $client->validateLicense($key);
} catch (ExpiredException $e) {
    // License expired
    $expiredAt = $e->getExpiredAt();
    $daysSince = $e->getDaysSinceExpiration();
} catch (ActivationException $e) {
    // Activation issue
} catch (LicenseException $e) {
    // Other errors
}
```

### Pattern 3: Get All Error Info

```php
catch (LicenseException $e) {
    $info = $e->toArray();
    // Returns:
    // [
    //   'exception' => 'GetKeyManager\SDK\ExpiredException',
    //   'message' => 'License key expired',
    //   'code' => 400,
    //   'error_code' => 'LICENSE_EXPIRED',
    //   'api_response_code' => 205,
    //   'api_code_name' => 'LICENSE_EXPIRED',
    //   'details' => [...],
    //   'file' => '...',
    //   'line' => 123
    // ]
}
```

## 🔧 ApiResponseCode Utilities

```php
// Check if code is success/error
ApiResponseCode::isSuccess(200);  // true
ApiResponseCode::isError(205);    // true

// Get code information
ApiResponseCode::getName(205);    // "LICENSE_EXPIRED"
ApiResponseCode::getMessage(205); // "License key expired"
```

## 🚀 Migration Guide

### No Migration Required!

All v1.x code continues to work without changes:

```php
// This still works exactly as before
$client = new LicenseClient(['apiKey' => 'xxx']);
$result = $client->validateLicense('KEY');
```

### Opt-In to New Features

```php
// Start using API codes when you're ready
try {
    $result = $client->validateLicense('KEY');
} catch (LicenseException $e) {
    // NEW in v2.0
    $apiCode = $e->getApiCode();
    $codeName = $e->getApiCodeName();
}
```

## 📦 What Hasn't Changed

✅ All public method signatures  
✅ Return types  
✅ Exception types  
✅ Configuration structure  
✅ Behavior and functionality  

## 🎁 What You Get

✅ Better error messages  
✅ Programmatic error handling via API codes  
✅ IDE autocomplete for error codes  
✅ Easier debugging  
✅ Better documentation  
✅ Improved code organization (for SDK maintainers)  

## 📚 Documentation

- **API Response Codes**: `/API_RESPONSE_CODES.md`
- **Full SDK Guide**: `/sdks/php/HARDENING.md`
- **Examples**: `/sdks/php/examples-hardened.php`
- **Implementation Report**: `/SDK_REFACTORING_REPORT.md`

## 🆘 Support

If you encounter any issues:

1. Check that your code doesn't rely on internal SDK classes (use `LicenseClient` only)
2. Verify you're using supported PHP version (^7.4 || ^8.0)
3. Review examples in `examples-hardened.php`
4. Contact: support@getkeymanager.com

## ✅ Version Compatibility

| Version | Status | Breaking Changes |
|---------|--------|------------------|
| 2.0.0 | ✅ Current | **NONE** |
| 1.x | ✅ Compatible | All v1.x code works in v2.0 |

---

**Version**: 2.0.0  
**Release Date**: January 25, 2025  
**Backward Compatible**: ✅ Yes (100%)  
**Production Ready**: ✅ Yes
