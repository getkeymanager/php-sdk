# License Management Platform - PHP SDK

Official PHP SDK for the License Management Platform API.

**Version: 2.0.0** - Now with complete API coverage!

## Features

### Core Features (v1.x)
- ✅ License validation (online and offline)
- ✅ License activation and deactivation
- ✅ Feature flag checking
- ✅ Hardware ID generation
- ✅ Telemetry submission
- ✅ RSA-4096-SHA256 signature verification
- ✅ Automatic retry with exponential backoff
- ✅ Built-in caching
- ✅ Idempotent operations
- ✅ PSR-12 compliant

### New in v2.0.0
- ✅ **Complete license management** - Create, update, delete, list licenses
- ✅ **License assignment** - Assign licenses to customers (sync & async)
- ✅ **Metadata management** - Custom metadata for licenses and products
- ✅ **Product management** - Create and manage products via API
- ✅ **Generator support** - Generate licenses programmatically
- ✅ **Contract management** - Manage API contracts
- ✅ **Downloadables access** - Provide downloadable files to users
- ✅ **Advanced telemetry** - Retrieve telemetry data with filters
- ✅ **Public changelog API** - Access changelogs without authentication

**30+ new methods added while maintaining full backward compatibility!**

## Requirements

- PHP 7.4 or higher
- ext-json
- ext-openssl
- ext-curl

## Installation

### Via Composer (Recommended)

```bash
composer require getkeymanager/php-sdk
```

### Manual Installation

1. Download the SDK files
2. Include the autoloader:

```php
require_once __DIR__ . '/vendor/autoload.php';
```

## Quick Start

### Initialize the Client

```php
use GetKeyManager\SDK\LicenseClient;

$client = new LicenseClient([
    'apiKey' => 'your-api-key-here',
    'publicKey' => file_get_contents('/path/to/public-key.pem'),
    'baseUrl' => 'https://api.getkeymanager.com', // Optional
    'verifySignatures' => true, // Optional, default: true
    'cacheEnabled' => true, // Optional, default: true
    'cacheTtl' => 300, // Optional, default: 300 seconds
]);
```

### Validate a License (Online)

```php
try {
    $result = $client->validateLicense('XXXXX-XXXXX-XXXXX-XXXXX');
    
    if ($result['valid']) {
        echo "License is valid!\n";
        echo "Status: " . $result['license']['status'] . "\n";
        echo "Expires: " . ($result['license']['expires_at'] ?? 'Never') . "\n";
    } else {
        echo "License is invalid\n";
    }
} catch (LicenseException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
```

### Activate a License

```php
try {
    $hardwareId = $client->generateHardwareId();
    
    $result = $client->activateLicense('XXXXX-XXXXX-XXXXX-XXXXX', [
        'hardwareId' => $hardwareId,
        'metadata' => [
            'hostname' => gethostname(),
            'application_version' => '1.0.0'
        ]
    ]);
    
    if ($result['success']) {
        echo "License activated successfully!\n";
        echo "Activation ID: " . $result['activation']['id'] . "\n";
    }
} catch (LicenseException $e) {
    echo "Activation failed: " . $e->getMessage() . "\n";
}
```

### Deactivate a License

```php
try {
    $result = $client->deactivateLicense('XXXXX-XXXXX-XXXXX-XXXXX', [
        'hardwareId' => $hardwareId
    ]);
    
    if ($result['success']) {
        echo "License deactivated successfully!\n";
    }
} catch (LicenseException $e) {
    echo "Deactivation failed: " . $e->getMessage() . "\n";
}
```

### Check Feature Flags

```php
try {
    $result = $client->checkFeature('XXXXX-XXXXX-XXXXX-XXXXX', 'premium_feature');
    
    if ($result['enabled']) {
        echo "Feature is enabled!\n";
        if (isset($result['value'])) {
            echo "Feature value: " . json_encode($result['value']) . "\n";
        }
    } else {
        echo "Feature is not enabled\n";
    }
} catch (LicenseException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
```

### Validate Offline License

```php
use GetKeyManager\SDK\LicenseClient;

$client = new LicenseClient([
    'apiKey' => 'your-api-key',
    'publicKey' => file_get_contents('/path/to/public-key.pem')
]);

$offlineLicense = file_get_contents('/path/to/offline-license.json');

try {
    $result = $client->validateOfflineLicense($offlineLicense, [
        'hardwareId' => $client->generateHardwareId()
    ]);
    
    if ($result['valid']) {
        echo "Offline license is valid!\n";
        print_r($result['license']);
    } else {
        echo "Offline license validation failed:\n";
        foreach ($result['errors'] as $error) {
            echo "  - $error\n";
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
```

### Send Telemetry

```php
$result = $client->sendTelemetry(
    'XXXXX-XXXXX-XXXXX-XXXXX',
    'application.started',
    [
        'version' => '1.0.0',
        'platform' => PHP_OS
    ],
    [
        'custom_field' => 'custom_value'
    ]
);

if ($result['success']) {
    echo "Telemetry sent successfully\n";
}
```

### Generate Hardware ID

```php
$hardwareId = $client->generateHardwareId();
echo "Hardware ID: $hardwareId\n";
```

## Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `apiKey` | string | *required* | Your API key |
| `publicKey` | string | null | RSA public key for signature verification |
| `baseUrl` | string | `https://api.getkeymanager.com` | API base URL |
| `timeout` | int | 30 | Request timeout in seconds |
| `verifySignatures` | bool | true | Verify response signatures |
| `environment` | string | null | Environment (production/staging/development) |
| `cacheEnabled` | bool | true | Enable response caching |
| `cacheTtl` | int | 300 | Cache TTL in seconds |
| `retryAttempts` | int | 3 | Number of retry attempts |
| `retryDelay` | int | 1000 | Retry delay in milliseconds |

## Error Handling

The SDK uses a hierarchy of exceptions:

```
LicenseException (base)
├── ValidationException
├── NetworkException
├── SignatureException
├── RateLimitException
└── LicenseStatusException
    ├── ExpiredException
    ├── SuspendedException
    └── RevokedException
```

### Example Error Handling

```php
use GetKeyManager\SDK\LicenseClient;
use GetKeyManager\SDK\ExpiredException;
use GetKeyManager\SDK\RateLimitException;
use GetKeyManager\SDK\NetworkException;

try {
    $result = $client->validateLicense($licenseKey);
} catch (ExpiredException $e) {
    echo "License has expired: " . $e->getMessage() . "\n";
} catch (RateLimitException $e) {
    echo "Rate limit exceeded. Please try again later.\n";
} catch (NetworkException $e) {
    echo "Network error: " . $e->getMessage() . "\n";
} catch (LicenseException $e) {
    echo "License error: " . $e->getMessage() . "\n";
}
```

## Caching

The SDK automatically caches:
- License validation responses
- Feature flag checks

### Clear Cache

```php
// Clear all cache
$client->clearCache();

// Clear cache for specific license
$client->clearLicenseCache('XXXXX-XXXXX-XXXXX-XXXXX');
```

## Signature Verification

All API responses are cryptographically signed. The SDK automatically verifies signatures when `verifySignatures` is enabled.

### Using SignatureVerifier Directly

```php
use GetKeyManager\SDK\SignatureVerifier;

$verifier = new SignatureVerifier($publicKeyPem);

$data = '{"license":"XXXXX-XXXXX-XXXXX-XXXXX"}';
$signature = 'base64_encoded_signature';

if ($verifier->verify($data, $signature)) {
    echo "Signature is valid!\n";
} else {
    echo "Signature verification failed!\n";
}
```

### Verify JSON Response

```php
$jsonResponse = '{"data":{},"signature":"..."}';

if ($verifier->verifyJsonResponse($jsonResponse)) {
    echo "Response signature is valid!\n";
}
```

## Idempotency

Activation and deactivation operations support idempotency:

```php
$idempotencyKey = '550e8400-e29b-41d4-a716-446655440000';

$result = $client->activateLicense($licenseKey, [
    'hardwareId' => $hardwareId,
    'idempotencyKey' => $idempotencyKey
]);

// Repeat with same key will return same response
$result2 = $client->activateLicense($licenseKey, [
    'hardwareId' => $hardwareId,
    'idempotencyKey' => $idempotencyKey
]);
```

## Best Practices

### 1. Store API Key Securely

```php
// ❌ Don't hardcode API keys
$client = new LicenseClient(['apiKey' => 'pk_live_...']);

// ✅ Use environment variables
$client = new LicenseClient(['apiKey' => getenv('LICENSE_API_KEY')]);
```

### 2. Cache Hardware ID

```php
// Generate once and store
$hardwareId = $client->generateHardwareId();
file_put_contents('/var/app/hwid.txt', $hardwareId);

// Reuse stored value
$hardwareId = file_get_contents('/var/app/hwid.txt');
```

### 3. Handle Network Failures Gracefully

```php
try {
    $result = $client->validateLicense($licenseKey);
} catch (NetworkException $e) {
    // Fall back to offline validation
    $offlineLicense = file_get_contents('/var/app/offline-license.json');
    $result = $client->validateOfflineLicense($offlineLicense);
}
```

### 4. Validate Licenses Periodically

```php
// Check license every 24 hours
$lastCheck = (int) file_get_contents('/var/app/last-check.txt');
if (time() - $lastCheck > 86400) {
    $result = $client->validateLicense($licenseKey);
    file_put_contents('/var/app/last-check.txt', (string) time());
}
```

## Testing

```bash
# Install dependencies
composer install

# Run tests
composer test

# Run with coverage
composer test -- --coverage-html coverage/
```

## Examples

See the `/examples` directory for complete working examples:

- `validate.php` - Basic license validation
- `activate.php` - License activation
- `features.php` - Feature flag checking
- `offline.php` - Offline license validation
- `telemetry.php` - Sending telemetry data

## Support

- Documentation: https://docs.getkeymanager.com
- API Reference: https://api.getkeymanager.com/docs
- Issues: https://github.com/getkeymanager/php-sdk/issues
- Email: support@getkeymanager.com

## License

MIT License - see LICENSE file for details

## Contributing

Contributions are welcome! Please read CONTRIBUTING.md for guidelines.

## Changelog

See CHANGELOG.md for version history.
