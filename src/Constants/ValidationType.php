<?php

declare(strict_types=1);

namespace GetKeyManager\SDK\Constants;

/**
 * License Validation Type Constants
 * 
 * Defines validation strategies for license state resolution.
 * 
 * @package GetKeyManager\SDK\Constants
 */
class ValidationType
{
    /**
     * Offline-first validation (default)
     * 
     * Smart validation strategy:
     * 1. Parse local .lic file offline first
     * 2. Verify RSA-4096 signature
     * 3. Check expiry, features, capabilities
     * 4. Fall back to API only if offline fails
     * 
     * Benefits:
     * - Works without internet connection
     * - Faster response time
     * - Reduced API calls
     * - Fail-safe: API fallback available
     */
    public const OFFLINE_FIRST = false;

    /**
     * Force API validation
     * 
     * Always calls API endpoint:
     * 1. Ignore local .lic file
     * 2. Make direct API call
     * 3. Save returned .lic file for offline use
     * 4. Return fresh license state
     * 
     * Use cases:
     * - Fresh installation (no .lic file exists)
     * - Force refresh for security
     * - License update verification
     * - Periodic revalidation
     */
    public const FORCE_API = true;

    /**
     * Get all validation types as array
     * 
     * @return array Map of type names to values
     */
    public static function all(): array
    {
        return [
            'offline_first' => self::OFFLINE_FIRST,
            'force_api' => self::FORCE_API,
        ];
    }

    /**
     * Get human-readable description for validation type
     * 
     * @param bool $type Validation type constant
     * @return string Description
     */
    public static function description(bool $type): string
    {
        if ($type === self::OFFLINE_FIRST) {
            return 'Offline-first validation (default)';
        }
        if ($type === self::FORCE_API) {
            return 'Force API validation';
        }
        return 'Unknown';
    }
}
