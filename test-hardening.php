<?php

declare(strict_types=1);

/**
 * Test script for hardened SDK features
 */

// Load all SDK files
require_once __DIR__ . '/SignatureVerifier.php';
require_once __DIR__ . '/Exceptions.php';
require_once __DIR__ . '/EntitlementState.php';
require_once __DIR__ . '/LicenseState.php';
require_once __DIR__ . '/StateStore.php';
require_once __DIR__ . '/StateResolver.php';
require_once __DIR__ . '/LicenseClient.php';

use GetKeyManager\SDK\LicenseClient;
use GetKeyManager\SDK\LicenseState;
use GetKeyManager\SDK\EntitlementState;

echo "SDK Hardening Test Script\n";
echo "==========================\n\n";

// Test 1: Create client with new configuration
echo "Test 1: Initialize LicenseClient with StateStore/StateResolver\n";
try {
    $client = new LicenseClient([
        'apiKey' => 'test-api-key',
        'baseUrl' => 'https://api.getkeymanager.com',
        'verifySignatures' => false, // Disable for testing
        'cacheEnabled' => true,
        'cacheTtl' => 300,
    ]);
    echo "✓ Client initialized successfully\n\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n\n";
    exit(1);
}

// Test 2: Test EntitlementState creation
echo "Test 2: Create EntitlementState\n";
try {
    $payload = [
        'valid' => true,
        'status' => 'active',
        'issued_at' => time(),
        'valid_until' => time() + 86400 * 30, // 30 days
        'features' => [
            'premium' => true,
            'api_access' => true,
            'max_users' => 10,
        ],
    ];
    
    $entitlementState = new EntitlementState($payload);
    echo "✓ EntitlementState created\n";
    echo "  State: " . $entitlementState->getState() . "\n";
    echo "  Is Active: " . ($entitlementState->isActive() ? 'Yes' : 'No') . "\n";
    echo "  Allows Operation: " . ($entitlementState->allowsOperation() ? 'Yes' : 'No') . "\n\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n\n";
}

// Test 3: Test LicenseState wrapper
echo "Test 3: Create LicenseState\n";
try {
    $licenseState = new LicenseState($entitlementState, 'TEST-KEY-12345');
    echo "✓ LicenseState created\n";
    echo "  Is Valid: " . ($licenseState->isValid() ? 'Yes' : 'No') . "\n";
    echo "  Status Message: " . $licenseState->getStatusMessage() . "\n";
    echo "  Can Update: " . ($licenseState->canUpdate() ? 'Yes' : 'No') . "\n";
    echo "  Can Download: " . ($licenseState->canDownload() ? 'Yes' : 'No') . "\n";
    echo "  Allows 'premium': " . ($licenseState->allows('premium') ? 'Yes' : 'No') . "\n\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n\n";
}

// Test 4: Test grace period state
echo "Test 4: Create Grace Period State\n";
try {
    $expiredPayload = [
        'valid' => true,
        'status' => 'active',
        'issued_at' => time() - 86400 * 10,
        'valid_until' => time() - 86400 * 2, // Expired 2 days ago
        'last_verified_at' => time() - 3600, // Verified 1 hour ago
        'features' => ['basic' => true],
    ];
    
    $graceState = new EntitlementState($expiredPayload);
    echo "✓ Grace state created\n";
    echo "  State: " . $graceState->getState() . "\n";
    echo "  Is In Grace: " . ($graceState->isInGrace() ? 'Yes' : 'No') . "\n";
    echo "  Allows Operation: " . ($graceState->allowsOperation() ? 'Yes' : 'No') . "\n\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n\n";
}

// Test 5: Test restricted state
echo "Test 5: Create Restricted State\n";
try {
    $restrictedPayload = [
        'valid' => false,
        'status' => 'suspended',
        'issued_at' => time(),
    ];
    
    $restrictedState = new EntitlementState($restrictedPayload);
    $licenseState = new LicenseState($restrictedState, 'RESTRICTED-KEY');
    echo "✓ Restricted state created\n";
    echo "  State: " . $restrictedState->getState() . "\n";
    echo "  Is Valid: " . ($licenseState->isValid() ? 'Yes' : 'No') . "\n";
    echo "  Status Message: " . $licenseState->getStatusMessage() . "\n\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n\n";
}

// Test 6: Test Exception hierarchy
echo "Test 6: Test Enhanced Exceptions\n";
try {
    throw new GetKeyManager\SDK\ExpiredException(
        'License has expired',
        403,
        'LICENSE_EXPIRED',
        ['expired_at' => time() - 86400]
    );
} catch (GetKeyManager\SDK\ExpiredException $e) {
    echo "✓ ExpiredException thrown and caught\n";
    echo "  Message: " . $e->getMessage() . "\n";
    echo "  Error Code: " . $e->getErrorCode() . "\n";
    echo "  Details: " . json_encode($e->getErrorDetails()) . "\n\n";
} catch (Exception $e) {
    echo "✗ Unexpected exception type\n\n";
}

// Test 7: Test LicenseState toArray()
echo "Test 7: Test LicenseState serialization\n";
try {
    $arrayData = $licenseState->toArray();
    echo "✓ LicenseState serialized\n";
    echo "  Keys: " . implode(', ', array_keys($arrayData)) . "\n\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n\n";
}

echo "All tests completed!\n";
