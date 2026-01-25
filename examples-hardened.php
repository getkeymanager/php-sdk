<?php

/**
 * PHP SDK - Hardened Features Examples
 * 
 * This file demonstrates the new hardening features:
 * - LicenseState for unified state management
 * - EntitlementState for internal capability tracking
 * - Grace period support
 * - Feature-based gating
 * - Enhanced exception handling
 */

require_once __DIR__ . '/vendor/autoload.php'; // Or manual requires

use GetKeyManager\SDK\LicenseClient;
use GetKeyManager\SDK\LicenseState;
use GetKeyManager\SDK\LicenseException;
use GetKeyManager\SDK\ExpiredException;
use GetKeyManager\SDK\StateException;
use GetKeyManager\SDK\SuspendedException;
use GetKeyManager\SDK\RevokedException;
use GetKeyManager\SDK\NetworkException;
use GetKeyManager\SDK\SignatureException;
use GetKeyManager\SDK\ActivationException;
use GetKeyManager\SDK\ValidationException;
use GetKeyManager\SDK\ApiResponseCode;

// ============================================================================
// Example 1: Initialize client with hardened features
// ============================================================================

$client = new LicenseClient([
    'apiKey' => 'your-api-key-here',
    'baseUrl' => 'https://api.getkeymanager.com',
    'verifySignatures' => true,  // Always verify signatures
    'publicKey' => file_get_contents('/path/to/public-key.pem'),
    'environment' => 'production',
    'productId' => 'your-product-uuid',
    'cacheEnabled' => true,
    'cacheTtl' => 300, // 5 minutes
]);

// ============================================================================
// Example 2: Resolve License State (Hardened Validation)
// ============================================================================

try {
    $licenseKey = 'XXXXX-XXXXX-XXXXX-XXXXX';
    
    // Resolve state with signature verification and grace period support
    $state = $client->resolveLicenseState($licenseKey, [
        'hardwareId' => 'unique-hardware-id',
        'domain' => 'example.com',
    ]);
    
    // Check state
    echo "License State: " . $state->getState() . "\n";
    echo "Is Valid: " . ($state->isValid() ? 'Yes' : 'No') . "\n";
    echo "Status: " . $state->getStatusMessage() . "\n";
    
    // Check specific capabilities
    if ($state->canUpdate()) {
        echo "Updates are allowed\n";
    }
    
    if ($state->canDownload()) {
        echo "Downloads are allowed\n";
    }
    
    if ($state->isInGracePeriod()) {
        echo "Warning: License is in grace period. Please revalidate.\n";
    }
    
} catch (LicenseException $e) {
    // Enhanced exception with error code and details
    echo "Error: " . $e->getMessage() . "\n";
    echo "Error Code: " . $e->getErrorCode() . "\n";
    echo "Details: " . json_encode($e->getErrorDetails()) . "\n";
}

// ============================================================================
// Example 3: Feature-Based Gating (No Global Boolean)
// ============================================================================

try {
    $licenseKey = 'XXXXX-XXXXX-XXXXX-XXXXX';
    $state = $client->resolveLicenseState($licenseKey);
    
    // Check individual features
    if ($state->allows('premium_features')) {
        // Enable premium UI/functionality
        echo "Premium features enabled\n";
    }
    
    if ($state->allows('api_access')) {
        // Allow API calls
        echo "API access granted\n";
    }
    
    // Get feature limits
    $maxUsers = $state->getFeatureValue('max_users');
    if ($maxUsers !== null) {
        echo "Max users allowed: {$maxUsers}\n";
    }
    
    // Check multiple features at once
    $features = $state->getFeatures();
    foreach ($features as $feature => $value) {
        echo "Feature '{$feature}': " . var_export($value, true) . "\n";
    }
    
} catch (LicenseException $e) {
    echo "Feature check failed: " . $e->getMessage() . "\n";
}

// ============================================================================
// Example 4: Protect Update & Download Flows
// ============================================================================

try {
    $licenseKey = 'XXXXX-XXXXX-XXXXX-XXXXX';
    
    // Require update capability before allowing updates
    $state = $client->requireCapability($licenseKey, 'updates');
    
    // If we reach here, updates are allowed
    $downloadUrl = generateSecureDownloadUrl($licenseKey);
    echo "Download URL: {$downloadUrl}\n";
    
} catch (StateException $e) {
    // Capability not allowed
    echo "Updates not available: " . $e->getMessage() . "\n";
    echo "Current state: " . $e->getErrorDetails()['state'] . "\n";
} catch (LicenseException $e) {
    echo "Validation failed: " . $e->getMessage() . "\n";
}

// ============================================================================
// Example 5: Graceful Degradation with Grace Period
// ============================================================================

try {
    $licenseKey = 'XXXXX-XXXXX-XXXXX-XXXXX';
    $state = $client->resolveLicenseState($licenseKey);
    
    if ($state->isActive()) {
        // Full functionality
        enableAllFeatures();
    } elseif ($state->isInGracePeriod()) {
        // Limited functionality - show warning
        enableBasicFeatures();
        showRevalidationWarning();
        
        $daysUntilExpiry = $state->getDaysUntilExpiration();
        if ($daysUntilExpiry !== null && $daysUntilExpiry < 7) {
            echo "Warning: License expires in {$daysUntilExpiry} days\n";
        }
    } else {
        // Restricted - show error
        disableAllFeatures();
        showLicenseError($state->getStatusMessage());
    }
    
} catch (LicenseException $e) {
    // Network error or validation failure
    // Try to use cached state if available
    try {
        $state = $client->getLicenseState($licenseKey);
        if ($state->allowsOperation()) {
            echo "Using cached license state (grace period)\n";
            enableBasicFeatures();
        } else {
            disableAllFeatures();
        }
    } catch (Exception $e2) {
        disableAllFeatures();
    }
}

// ============================================================================
// Example 6: Enhanced Exception Handling (Better DX)
// ============================================================================

try {
    $licenseKey = 'XXXXX-XXXXX-XXXXX-XXXXX';
    $state = $client->resolveLicenseState($licenseKey);
    
} catch (ExpiredException $e) {
    // Specific exception for expired licenses
    echo "License expired on: " . date('Y-m-d', $e->getExpiredAt()) . "\n";
    echo "Days since expiration: " . $e->getDaysSinceExpiration() . "\n";
    
    // Check if we're using the specific error code
    if ($e->isErrorCode(ExpiredException::ERROR_LICENSE_EXPIRED)) {
        // Handle expired license specifically
        redirectToRenewalPage();
    }
    
} catch (SuspendedException $e) {
    // License suspended by admin
    echo "License has been suspended\n";
    contactSupport();
    
} catch (RevokedException $e) {
    // License revoked (security issue, chargeback, etc.)
    echo "License has been revoked\n";
    disableApplication();
    
} catch (NetworkException $e) {
    // Network/connectivity issues
    echo "Network error: " . $e->getMessage() . "\n";
    // Try to use cached state
    
} catch (SignatureException $e) {
    // Signature verification failed - possible tampering
    echo "Security warning: Signature verification failed\n";
    logSecurityEvent($e);
    
} catch (LicenseException $e) {
    // Generic license exception
    echo "License error: " . $e->getMessage() . "\n";
    echo "Error code: " . $e->getErrorCode() . "\n";
}

// ============================================================================
// Example 7: CLI Command Protection
// ============================================================================

function protectedCliCommand(LicenseClient $client): void
{
    try {
        $licenseKey = getenv('LICENSE_KEY');
        
        if (!$licenseKey) {
            throw new \Exception('LICENSE_KEY environment variable not set');
        }
        
        // Resolve state before allowing command execution
        $state = $client->resolveLicenseState($licenseKey);
        
        if (!$state->isValid()) {
            echo "Error: Invalid license. Command cannot be executed.\n";
            echo "Status: " . $state->getStatusMessage() . "\n";
            exit(1);
        }
        
        if ($state->isInGracePeriod()) {
            echo "Warning: License in grace period. Please revalidate soon.\n";
        }
        
        // Check if command requires specific capability
        if (!$state->allows('cli_access')) {
            echo "Error: CLI access not included in your license plan.\n";
            exit(1);
        }
        
        // Execute command
        echo "Executing protected command...\n";
        // ... command logic ...
        
    } catch (LicenseException $e) {
        echo "License validation failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// ============================================================================
// Example 8: Telemetry Gating
// ============================================================================

function sendTelemetryIfAllowed(LicenseClient $client, string $licenseKey, array $eventData): void
{
    try {
        $state = $client->resolveLicenseState($licenseKey);
        
        if ($state->canSendTelemetry()) {
            $client->sendTelemetry($licenseKey, 'app.event', $eventData);
            echo "Telemetry sent\n";
        } else {
            echo "Telemetry disabled for this license\n";
        }
        
    } catch (LicenseException $e) {
        // Telemetry should never break app functionality
        // Log error but continue
        error_log("Telemetry error: " . $e->getMessage());
    }
}

// ============================================================================
// Example 9: State Serialization for Display
// ============================================================================

function displayLicenseStatus(LicenseState $state): void
{
    // Convert to array for display
    $stateArray = $state->toArray();
    
    echo "License Status:\n";
    echo "  Key: " . $stateArray['license_key'] . "\n";
    echo "  Valid: " . ($stateArray['is_valid'] ? 'Yes' : 'No') . "\n";
    echo "  State: " . $stateArray['state'] . "\n";
    echo "  Message: " . $stateArray['status_message'] . "\n";
    
    if ($stateArray['expires_at']) {
        echo "  Expires: " . date('Y-m-d H:i:s', $stateArray['expires_at']) . "\n";
        echo "  Days until expiration: " . $stateArray['days_until_expiration'] . "\n";
    } else {
        echo "  Type: Lifetime license\n";
    }
    
    echo "  Features:\n";
    foreach ($stateArray['features'] as $feature => $value) {
        echo "    - {$feature}: " . var_export($value, true) . "\n";
    }
    
    // Or output as JSON
    echo "\nJSON representation:\n";
    echo $state->toJson() . "\n";
}

// ============================================================================
// Helper Functions (placeholders)
// ============================================================================

function enableAllFeatures(): void { echo "All features enabled\n"; }
function enableBasicFeatures(): void { echo "Basic features enabled\n"; }
function disableAllFeatures(): void { echo "All features disabled\n"; }
function showRevalidationWarning(): void { echo "Please revalidate your license\n"; }
function showLicenseError(string $msg): void { echo "License Error: {$msg}\n"; }
function redirectToRenewalPage(): void { echo "Redirecting to renewal page...\n"; }
function contactSupport(): void { echo "Please contact support\n"; }
function disableApplication(): void { echo "Application disabled\n"; }
function logSecurityEvent(Exception $e): void { error_log($e->getMessage()); }
function generateSecureDownloadUrl(string $key): string { return "https://example.com/download?key={$key}"; }

// ============================================================================
// Example 10: API Response Code Handling (New in v2.0)
// ============================================================================

/**
 * Demonstrates the new API response code integration
 */
function handleApiResponseCodes(LicenseClient $client): void
{
    $licenseKey = 'XXXXX-XXXXX-XXXXX-XXXXX';
    
    try {
        $result = $client->validateLicense($licenseKey, [
            'hardwareId' => 'device-123'
        ]);
        
        echo "License validated successfully!\n";
        
    } catch (LicenseException $e) {
        // All exceptions now expose API response codes
        $apiCode = $e->getApiCode();
        $apiCodeName = $e->getApiCodeName();
        
        echo "API Error Occurred:\n";
        echo "  HTTP Status: " . $e->getCode() . "\n";
        echo "  API Response Code: " . $apiCode . "\n";
        echo "  Code Name: " . $apiCodeName . "\n";
        echo "  Message: " . $e->getMessage() . "\n";
        
        // Handle specific API response codes
        switch ($apiCode) {
            case ApiResponseCode::LICENSE_EXPIRED:
                // Code 205: License has expired
                echo "Action: Redirect user to renewal page\n";
                if ($e instanceof ExpiredException) {
                    $expiredAt = $e->getExpiredAt();
                    $daysSince = $e->getDaysSinceExpiration();
                    echo "  Expired At: " . date('Y-m-d', $expiredAt) . "\n";
                    echo "  Days Since: {$daysSince}\n";
                }
                redirectToRenewalPage();
                break;
                
            case ApiResponseCode::LICENSE_BLOCKED:
                // Code 204: License has been blocked
                echo "Action: Contact support\n";
                disableApplication();
                break;
                
            case ApiResponseCode::ACTIVATION_LIMIT_REACHED:
                // Code 302: No more activations available
                echo "Action: Show activation limit reached message\n";
                echo "Suggestion: User should deactivate an old device first\n";
                break;
                
            case ApiResponseCode::NO_ACTIVATION_FOUND:
                // Code 202: License/device combo not activated
                echo "Action: Prompt user to activate license\n";
                // Offer to activate
                break;
                
            case ApiResponseCode::INVALID_LICENSE_KEY:
                // Code 210: License key doesn't exist
                echo "Action: Show invalid license key error\n";
                break;
                
            case ApiResponseCode::PRODUCT_INACTIVE:
                // Code 203: Associated product is inactive
                echo "Action: Product has been discontinued\n";
                contactSupport();
                break;
                
            case ApiResponseCode::INVALID_API_KEY:
                // Code 100: API key is invalid
                echo "Action: Developer error - check API key configuration\n";
                logSecurityEvent($e);
                break;
                
            case ApiResponseCode::INSUFFICIENT_PERMISSIONS:
                // Code 102: API key lacks required permissions
                echo "Action: API key needs additional permissions\n";
                break;
                
            default:
                // Generic handling
                echo "Action: Show generic error message\n";
                echo "Error Details: " . json_encode($e->getErrorDetails()) . "\n";
        }
    }
}

// ============================================================================
// Example 11: Checking Response Code Categories
// ============================================================================

function checkResponseCodeType(int $code): void
{
    if (ApiResponseCode::isSuccess($code)) {
        echo "Code {$code} is a success code\n";
    } elseif (ApiResponseCode::isError($code)) {
        echo "Code {$code} is an error code\n";
        
        // Get human-readable message
        $message = ApiResponseCode::getMessage($code);
        $name = ApiResponseCode::getName($code);
        
        echo "  Name: {$name}\n";
        echo "  Message: {$message}\n";
    }
}

// ============================================================================
// Example 12: Comprehensive Error Handling with API Codes
// ============================================================================

function comprehensiveErrorHandling(LicenseClient $client, string $licenseKey): void
{
    try {
        // Attempt to validate license
        $state = $client->resolveLicenseState($licenseKey);
        
        if ($state->isValid()) {
            echo "✓ License is valid\n";
            enableAllFeatures();
        }
        
    } catch (ExpiredException $e) {
        // API Code 205: LICENSE_EXPIRED
        $apiCode = $e->getApiCode();
        echo "✗ License Expired (API Code: {$apiCode})\n";
        echo "  API Code Name: " . $e->getApiCodeName() . "\n";
        echo "  Message: " . $e->getMessage() . "\n";
        
        if ($expiredAt = $e->getExpiredAt()) {
            $daysSince = $e->getDaysSinceExpiration();
            echo "  Expired: {$daysSince} days ago\n";
            
            if ($daysSince < 30) {
                echo "  Grace period: Contact support for renewal\n";
            } else {
                echo "  Action required: Renew license immediately\n";
            }
        }
        
        disableAllFeatures();
        redirectToRenewalPage();
        
    } catch (ActivationException $e) {
        // API Codes 302, 301, 202: Activation-related errors
        $apiCode = $e->getApiCode();
        $codeName = $e->getApiCodeName();
        
        echo "✗ Activation Error (API Code: {$apiCode} - {$codeName})\n";
        
        switch ($apiCode) {
            case ApiResponseCode::ACTIVATION_LIMIT_REACHED:
                echo "  All activation slots are used\n";
                echo "  Available actions:\n";
                echo "    1. Deactivate an old device\n";
                echo "    2. Upgrade to a plan with more activations\n";
                break;
                
            case ApiResponseCode::LICENSE_ALREADY_ACTIVE:
                echo "  License is already active on this device\n";
                echo "  No action needed\n";
                break;
                
            case ApiResponseCode::NO_ACTIVATION_FOUND:
                echo "  License is not activated on this device\n";
                echo "  Please activate the license first\n";
                break;
        }
        
    } catch (ValidationException $e) {
        // API Codes 100, 101, 102, 103, 150, 210, etc.
        $apiCode = $e->getApiCode();
        $codeName = $e->getApiCodeName();
        
        echo "✗ Validation Error (API Code: {$apiCode} - {$codeName})\n";
        echo "  Message: " . $e->getMessage() . "\n";
        
        // Check if it's API key related (developer error)
        if ($apiCode >= 100 && $apiCode < 150) {
            echo "  This is a configuration error\n";
            echo "  Action: Check your API key settings\n";
            logSecurityEvent($e);
        } else {
            echo "  This is a license validation error\n";
            showLicenseError($e->getMessage());
        }
        
    } catch (NetworkException $e) {
        // Network/connectivity errors
        echo "✗ Network Error\n";
        echo "  Message: " . $e->getMessage() . "\n";
        echo "  Attempting to use cached license state...\n";
        
        // Try to get cached state
        try {
            $state = $client->getLicenseState($licenseKey);
            if ($state->isInGracePeriod()) {
                echo "  ⚠ Using cached state (grace period active)\n";
                enableBasicFeatures();
            }
        } catch (Exception $cacheError) {
            echo "  No valid cached state available\n";
            disableAllFeatures();
        }
        
    } catch (SignatureException $e) {
        // Signature verification failures
        echo "✗ Security Error: Signature Verification Failed\n";
        echo "  This may indicate tampering or configuration issues\n";
        echo "  API Code: " . $e->getApiCode() . "\n";
        
        disableApplication();
        logSecurityEvent($e);
        
    } catch (LicenseException $e) {
        // Generic license exceptions
        $apiCode = $e->getApiCode();
        $codeName = $e->getApiCodeName();
        
        echo "✗ License Error\n";
        echo "  HTTP Code: " . $e->getCode() . "\n";
        echo "  API Code: {$apiCode} ({$codeName})\n";
        echo "  Message: " . $e->getMessage() . "\n";
        
        // Log full exception details for debugging
        $exceptionArray = $e->toArray();
        error_log("License Exception: " . json_encode($exceptionArray, JSON_PRETTY_PRINT));
        
        disableAllFeatures();
    }
}

// ============================================================================
// Example 13: API Response Code Constants Usage
// ============================================================================

/**
 * Example of using ApiResponseCode constants in your application logic
 */
function customResponseHandler(LicenseClient $client): void
{
    try {
        $result = $client->validateLicense('LICENSE-KEY');
        
        // Success
        echo "Validation successful\n";
        
    } catch (LicenseException $e) {
        $apiCode = $e->getApiCode();
        
        // Use constants for clarity and IDE autocomplete support
        if ($apiCode === ApiResponseCode::LICENSE_EXPIRED) {
            handleExpiredLicense($e);
        } elseif ($apiCode === ApiResponseCode::LICENSE_BLOCKED) {
            handleBlockedLicense($e);
        } elseif ($apiCode === ApiResponseCode::ACTIVATION_LIMIT_REACHED) {
            handleActivationLimitReached($e);
        } elseif ($apiCode === ApiResponseCode::INVALID_LICENSE_KEY) {
            handleInvalidLicense($e);
        } elseif ($apiCode === ApiResponseCode::NO_ACTIVATION_FOUND) {
            handleNotActivated($e);
        } else {
            handleGenericError($e);
        }
    }
}

function handleExpiredLicense(LicenseException $e): void {
    echo "License expired. Redirecting to renewal...\n";
}

function handleBlockedLicense(LicenseException $e): void {
    echo "License blocked. Contact support.\n";
}

function handleActivationLimitReached(LicenseException $e): void {
    echo "Activation limit reached. Please deactivate old devices.\n";
}

function handleInvalidLicense(LicenseException $e): void {
    echo "Invalid license key. Please check your input.\n";
}

function handleNotActivated(LicenseException $e): void {
    echo "License not activated. Activating now...\n";
}

function handleGenericError(LicenseException $e): void {
    echo "Error: " . $e->getMessage() . "\n";
    echo "API Code: " . $e->getApiCode() . " (" . $e->getApiCodeName() . ")\n";
}
