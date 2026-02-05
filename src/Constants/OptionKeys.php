<?php

declare(strict_types=1);

namespace GetKeyManager\SDK\Constants;

/**
 * Optional Parameter Keys for SDK Methods
 * 
 * Defines all valid keys for the $options array used in SDK methods.
 * This provides IDE autocomplete, better documentation, and prevents typos.
 * 
 * @package GetKeyManager\SDK\Constants
 */
class OptionKeys
{
    /**
     * ========================================================================
     * VALIDATION & VERIFICATION OPTIONS
     * ========================================================================
     */

    /**
     * Cache time-to-live override (in seconds)
     * 
     * Type: int
     * Default: Uses Configuration::$cacheTtl
     * 
     * Example:
     * ```php
     * [OptionKeys::CACHE_TTL => 600] // 10 minutes
     * ```
     */
    public const CACHE_TTL = 'cache_ttl';

    /**
     * Request timeout override (in seconds)
     * 
     * Type: int
     * Default: Uses Configuration::$timeout (default 30)
     * 
     * Example:
     * ```php
     * [OptionKeys::TIMEOUT => 60] // 60 seconds
     * ```
     */
    public const TIMEOUT = 'timeout';

    /**
     * Custom metadata as key-value pairs
     * 
     * Type: array
     * Default: empty
     * 
     * Example:
     * ```php
     * [OptionKeys::METADATA => [
     *     'hostname' => 'server-01',
     *     'environment' => 'production',
     *     'custom_field' => 'value'
     * ]]
     * ```
     */
    public const METADATA = 'metadata';

    /**
     * ========================================================================
     * ACTIVATION & DEACTIVATION OPTIONS
     * ========================================================================
     */

    /**
     * Idempotency key for idempotent requests
     * 
     * Type: string (UUID v4 recommended)
     * Default: auto-generated UUID
     * 
     * Ensures that repeated requests with the same key return the same response.
     * Required for: Activation, Deactivation
     * 
     * Example:
     * ```php
     * [OptionKeys::IDEMPOTENCY_KEY => '550e8400-e29b-41d4-a716-446655440000']
     * ```
     */
    public const IDEMPOTENCY_KEY = 'idempotencyKey';

    /**
     * Operating system identifier
     * 
     * Type: string
     * Default: PHP_OS
     * 
     * Example values: "Windows 10", "Ubuntu 20.04", "macOS 12.0"
     * 
     * Example:
     * ```php
     * [OptionKeys::OS => 'Windows 10 Pro']
     * ```
     */
    public const OS = 'os';

    /**
     * Product/application version
     * 
     * Type: string
     * Default: null
     * 
     * The version of the application using the license.
     * 
     * Example:
     * ```php
     * [OptionKeys::PRODUCT_VERSION => '1.2.3']
     * ```
     */
    public const PRODUCT_VERSION = 'product_version';

    /**
     * Client/user IP address
     * 
     * Type: string
     * Default: $_SERVER['REMOTE_ADDR'] or auto-detected
     * 
     * Useful for tracking activation locations.
     * 
     * Example:
     * ```php
     * [OptionKeys::IP => '192.168.1.1']
     * ```
     */
    public const IP = 'ip';

    /**
     * ========================================================================
     * HARDWARE & DOMAIN BINDING OPTIONS
     * ========================================================================
     */

    /**
     * Hardware ID override
     * 
     * Type: string
     * Default: auto-generated via generateHardwareId()
     * 
     * Use for explicit hardware binding or testing.
     * 
     * Example:
     * ```php
     * [OptionKeys::HWID => 'abc123def456']
     * ```
     */
    public const HWID = 'hardwareId';

    /**
     * Domain override
     * 
     * Type: string
     * Default: $_SERVER['HTTP_HOST'] or gethostname()
     * 
     * Use for explicit domain binding or testing.
     * 
     * Example:
     * ```php
     * [OptionKeys::DOMAIN => 'example.com']
     * ```
     */
    public const DOMAIN = 'domain';

    /**
     * ========================================================================
     * TELEMETRY OPTIONS
     * ========================================================================
     */

    /**
     * User identifier for telemetry
     * 
     * Type: string
     * Default: null
     * 
     * Identifies the user/account using the license.
     * 
     * Example:
     * ```php
     * [OptionKeys::USER_ID => 'user@example.com']
     * ```
     */
    public const USER_ID = 'user_id';

    /**
     * Country code for geolocation tracking
     * 
     * Type: string (ISO 3166-1 alpha-2)
     * Default: auto-detected or null
     * 
     * Example:
     * ```php
     * [OptionKeys::COUNTRY => 'US']
     * ```
     */
    public const COUNTRY = 'country';

    /**
     * Flags for telemetry categorization
     * 
     * Type: array of strings
     * Default: empty
     * 
     * Example:
     * ```php
     * [OptionKeys::FLAGS => ['suspicious', 'vpn_detected']]
     * ```
     */
    public const FLAGS = 'flags';

    /**
     * ========================================================================
     * ADVANCED OPTIONS
     * ========================================================================
     */

    /**
     * Force bypass cache validation
     * 
     * Type: bool
     * Default: false
     * 
     * When true, completely ignores cached state and fetches fresh data.
     * 
     * Example:
     * ```php
     * [OptionKeys::NO_CACHE => true]
     * ```
     */
    public const NO_CACHE = 'no_cache';

    /**
     * ========================================================================
     * STATIC HELPER METHODS
     * ========================================================================
     */

    /**
     * Get all validation-related option keys
     * 
     * @return array List of validation option keys
     */
    public static function validationOptions(): array
    {
        return [
            self::CACHE_TTL,
            self::TIMEOUT,
            self::METADATA,
            self::NO_CACHE,
        ];
    }

    /**
     * Get all activation/deactivation option keys
     * 
     * @return array List of activation option keys
     */
    public static function activationOptions(): array
    {
        return [
            self::IDEMPOTENCY_KEY,
            self::OS,
            self::PRODUCT_VERSION,
            self::IP,
            self::HWID,
            self::DOMAIN,
            self::METADATA,
        ];
    }

    /**
     * Get all telemetry-related option keys
     * 
     * @return array List of telemetry option keys
     */
    public static function telemetryOptions(): array
    {
        return [
            self::USER_ID,
            self::COUNTRY,
            self::FLAGS,
            self::METADATA,
        ];
    }

    /**
     * Get all valid option keys
     * 
     * @return array List of all option keys
     */
    public static function all(): array
    {
        return array_merge(
            self::validationOptions(),
            self::activationOptions(),
            self::telemetryOptions()
        );
    }

    /**
     * Check if an option key is valid
     * 
     * @param string $key Option key to validate
     * @return bool True if valid
     */
    public static function isValid(string $key): bool
    {
        return in_array($key, self::all(), true);
    }

    /**
     * Get description for an option key
     * 
     * @param string $key Option key
     * @return string Description
     */
    public static function description(string $key): string
    {
        $descriptions = [
            self::CACHE_TTL => 'Cache time-to-live override (seconds)',
            self::TIMEOUT => 'Request timeout override (seconds)',
            self::METADATA => 'Custom metadata key-value pairs',
            self::IDEMPOTENCY_KEY => 'UUID for idempotent requests',
            self::OS => 'Operating system identifier',
            self::PRODUCT_VERSION => 'Product/application version',
            self::IP => 'Client IP address',
            self::HWID => 'Hardware ID override',
            self::DOMAIN => 'Domain override',
            self::USER_ID => 'User identifier for telemetry',
            self::COUNTRY => 'Country code (ISO 3166-1 alpha-2)',
            self::FLAGS => 'Telemetry flags array',
            self::NO_CACHE => 'Force bypass cache validation',
        ];

        return $descriptions[$key] ?? 'Unknown option key';
    }
}
