<?php

/**
 * License Management Platform - PHP SDK Examples (v2.0.0)
 * 
 * This file demonstrates all available SDK methods.
 */

require_once __DIR__ . '/vendor/autoload.php';

use GetKeyManager\SDK\LicenseClient;
use GetKeyManager\SDK\LicenseException;

// Initialize client
$client = new LicenseClient([
    'apiKey' => 'your-api-key-here',
    'publicKey' => file_get_contents('/path/to/public-key.pem'),
    'baseUrl' => 'https://api.getkeymanager.com',
    'verifySignatures' => true,
    'cacheEnabled' => true,
]);

// =============================================================================
// LICENSE VALIDATION & ACTIVATION
// =============================================================================

echo "=== License Validation & Activation ===\n\n";

// Validate license
try {
    $result = $client->validateLicense('XXXXX-XXXXX-XXXXX-XXXXX');
    echo "Valid: " . ($result['valid'] ? 'Yes' : 'No') . "\n";
} catch (LicenseException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Activate license
try {
    $hardwareId = $client->generateHardwareId();
    $result = $client->activateLicense('XXXXX-XXXXX-XXXXX-XXXXX', [
        'hardwareId' => $hardwareId
    ]);
    echo "Activation ID: " . $result['activation']['id'] . "\n";
} catch (LicenseException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Deactivate license
try {
    $result = $client->deactivateLicense('XXXXX-XXXXX-XXXXX-XXXXX', [
        'hardwareId' => $hardwareId
    ]);
    echo "Deactivated: " . ($result['success'] ? 'Yes' : 'No') . "\n";
} catch (LicenseException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Check feature
try {
    $result = $client->checkFeature('XXXXX-XXXXX-XXXXX-XXXXX', 'premium_support');
    echo "Feature enabled: " . ($result['enabled'] ? 'Yes' : 'No') . "\n";
} catch (LicenseException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Validate offline license
try {
    $offlineLicense = file_get_contents('/path/to/offline-license.json');
    $result = $client->validateOfflineLicense($offlineLicense, [
        'hardwareId' => $hardwareId
    ]);
    echo "Offline valid: " . ($result['valid'] ? 'Yes' : 'No') . "\n";
} catch (LicenseException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// =============================================================================
// LICENSE MANAGEMENT (NEW IN V2)
// =============================================================================

echo "\n=== License Management ===\n\n";

// Create license keys
try {
    $result = $client->createLicenseKeys(
        'product-uuid',
        'generator-uuid',
        [
            ['activation_limit' => 5, 'validity_days' => 365],
            ['activation_limit' => 1, 'validity_days' => 30]
        ],
        'customer@example.com'
    );
    echo "Created " . count($result['licenses']) . " licenses\n";
} catch (LicenseException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Update license key
try {
    $result = $client->updateLicenseKey('XXXXX-XXXXX-XXXXX-XXXXX', [
        'activation_limit' => 10,
        'validity_days' => 365
    ]);
    echo "Updated license\n";
} catch (LicenseException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Get license keys with filters
try {
    $result = $client->getLicenseKeys([
        'product_uuid' => 'product-uuid',
        'status' => 'active'
    ]);
    echo "Found " . count($result['licenses']) . " licenses\n";
} catch (LicenseException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Get license details
try {
    $result = $client->getLicenseDetails('XXXXX-XXXXX-XXXXX-XXXXX');
    echo "License status: " . $result['license']['status'] . "\n";
} catch (LicenseException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Get available license count
try {
    $result = $client->getAvailableLicenseKeysCount('product-uuid');
    echo "Available licenses: " . $result['count'] . "\n";
} catch (LicenseException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Delete license key
try {
    $result = $client->deleteLicenseKey('XXXXX-XXXXX-XXXXX-XXXXX');
    echo "Deleted license\n";
} catch (LicenseException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// =============================================================================
// LICENSE ASSIGNMENT (NEW IN V2)
// =============================================================================

echo "\n=== License Assignment ===\n\n";

// Assign license to customer
try {
    $result = $client->assignLicenseKey(
        'XXXXX-XXXXX-XXXXX-XXXXX',
        'customer@example.com',
        'John Doe'
    );
    echo "Assigned license\n";
} catch (LicenseException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Random assign licenses (synchronous)
try {
    $result = $client->randomAssignLicenseKeys(
        'product-uuid',
        'generator-uuid',
        5,
        'customer@example.com',
        'John Doe'
    );
    echo "Assigned " . count($result['licenses']) . " licenses\n";
} catch (LicenseException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Random assign licenses (queued)
try {
    $result = $client->randomAssignLicenseKeysQueued(
        'product-uuid',
        'generator-uuid',
        100,
        'customer@example.com',
        'John Doe'
    );
    echo "Job queued: " . $result['job_id'] . "\n";
} catch (LicenseException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Assign and activate
try {
    $result = $client->assignAndActivateLicenseKey(
        'XXXXX-XXXXX-XXXXX-XXXXX',
        'customer@example.com',
        $hardwareId
    );
    echo "Assigned and activated\n";
} catch (LicenseException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// =============================================================================
// LICENSE METADATA (NEW IN V2)
// =============================================================================

echo "\n=== License Metadata ===\n\n";

// Create metadata
try {
    $result = $client->createLicenseKeyMeta(
        'XXXXX-XXXXX-XXXXX-XXXXX',
        'custom_field',
        'custom_value'
    );
    echo "Created metadata\n";
} catch (LicenseException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Update metadata
try {
    $result = $client->updateLicenseKeyMeta(
        'XXXXX-XXXXX-XXXXX-XXXXX',
        'custom_field',
        'new_value'
    );
    echo "Updated metadata\n";
} catch (LicenseException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Delete metadata
try {
    $result = $client->deleteLicenseKeyMeta(
        'XXXXX-XXXXX-XXXXX-XXXXX',
        'custom_field'
    );
    echo "Deleted metadata\n";
} catch (LicenseException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// =============================================================================
// PRODUCT MANAGEMENT (NEW IN V2)
// =============================================================================

echo "\n=== Product Management ===\n\n";

// Create product
try {
    $result = $client->createProduct('My Product', [
        'slug' => 'my-product',
        'description' => 'Product description',
        'status' => 'active'
    ]);
    echo "Created product: " . $result['product']['uuid'] . "\n";
} catch (LicenseException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Update product
try {
    $result = $client->updateProduct('product-uuid', [
        'name' => 'Updated Product Name',
        'description' => 'Updated description'
    ]);
    echo "Updated product\n";
} catch (LicenseException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Get all products
try {
    $result = $client->getAllProducts();
    echo "Found " . count($result['products']) . " products\n";
} catch (LicenseException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Delete product
try {
    $result = $client->deleteProduct('product-uuid');
    echo "Deleted product\n";
} catch (LicenseException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// =============================================================================
// PRODUCT METADATA (NEW IN V2)
// =============================================================================

echo "\n=== Product Metadata ===\n\n";

// Create product metadata
try {
    $result = $client->createProductMeta('product-uuid', 'version', '1.0.0');
    echo "Created product metadata\n";
} catch (LicenseException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Update product metadata
try {
    $result = $client->updateProductMeta('product-uuid', 'version', '2.0.0');
    echo "Updated product metadata\n";
} catch (LicenseException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Delete product metadata
try {
    $result = $client->deleteProductMeta('product-uuid', 'version');
    echo "Deleted product metadata\n";
} catch (LicenseException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// =============================================================================
// GENERATORS (NEW IN V2)
// =============================================================================

echo "\n=== Generators ===\n\n";

// Get all generators
try {
    $result = $client->getAllGenerators();
    echo "Found " . count($result['generators']) . " generators\n";
} catch (LicenseException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Generate license keys
try {
    $result = $client->generateLicenseKeys('generator-uuid', 10, [
        'activation_limit' => 5,
        'validity_days' => 365
    ]);
    echo "Generated " . count($result['licenses']) . " licenses\n";
} catch (LicenseException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// =============================================================================
// CONTRACTS (NEW IN V2)
// =============================================================================

echo "\n=== Contracts ===\n\n";

// Get all contracts
try {
    $result = $client->getAllContracts();
    echo "Found " . count($result['contracts']) . " contracts\n";
} catch (LicenseException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Create contract
try {
    $result = $client->createContract([
        'contract_key' => 'unique-contract-key',
        'contract_name' => 'API Contract',
        'contract_information' => 'Contract details',
        'product_id' => 1,
        'license_keys_quantity' => 1000,
        'status' => 'active',
        'can_get_info' => true,
        'can_generate' => true,
        'can_destroy' => false,
        'can_destroy_all' => false
    ]);
    echo "Created contract\n";
} catch (LicenseException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// =============================================================================
// DOWNLOADABLES (NEW IN V2)
// =============================================================================

echo "\n=== Downloadables ===\n\n";

// Access downloadables
try {
    $result = $client->accessDownloadables('XXXXX-XXXXX-XXXXX-XXXXX', $hardwareId);
    echo "Found " . count($result['downloadables']) . " files\n";
    foreach ($result['downloadables'] as $file) {
        echo "- " . $file['filename'] . ": " . $file['download_url'] . "\n";
    }
} catch (LicenseException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// =============================================================================
// TELEMETRY (EXPANDED IN V2)
// =============================================================================

echo "\n=== Telemetry ===\n\n";

// Send telemetry
try {
    $result = $client->sendTelemetry(
        'XXXXX-XXXXX-XXXXX-XXXXX',
        'application.started',
        ['version' => '1.0.0'],
        ['hostname' => gethostname()]
    );
    echo "Telemetry sent\n";
} catch (LicenseException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Get telemetry data
try {
    $result = $client->getTelemetryData(
        'numeric-single-value',
        'app_usage',
        ['product_id' => 1]
    );
    echo "Retrieved telemetry data\n";
} catch (LicenseException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// =============================================================================
// CHANGELOG (NEW IN V2)
// =============================================================================

echo "\n=== Changelog ===\n\n";

// Get product changelog
try {
    $result = $client->getProductChangelog('my-product');
    echo "Found " . count($result['entries']) . " changelog entries\n";
} catch (LicenseException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// =============================================================================
// UTILITY METHODS
// =============================================================================

echo "\n=== Utility Methods ===\n\n";

// Generate hardware ID
$hardwareId = $client->generateHardwareId();
echo "Hardware ID: " . $hardwareId . "\n";

// Clear cache
$client->clearCache();
echo "Cache cleared\n";

// Clear license-specific cache
$client->clearLicenseCache('XXXXX-XXXXX-XXXXX-XXXXX');
echo "License cache cleared\n";

echo "\n=== Examples Complete ===\n";
