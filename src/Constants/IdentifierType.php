<?php

declare(strict_types=1);

namespace GetKeyManager\SDK\Constants;

/**
 * Identifier Type Constants
 * 
 * Defines types of identifiers used for license binding and activation.
 * 
 * @package GetKeyManager\SDK\Constants
 */
class IdentifierType
{
    /**
     * Domain identifier (for web applications)
     * 
     * Use when licensing a web application or SaaS product.
     * Identifier is the domain: example.com, api.example.com, etc.
     * 
     * Example:
     * ```
     * $identifier = 'example.com';
     * $type = IdentifierType::DOMAIN;
     * ```
     */
    public const DOMAIN = 'domain';

    /**
     * Hardware identifier (for on-premises/desktop applications)
     * 
     * Use when licensing desktop, on-premises, or locally-installed software.
     * Identifier is the hardware ID: MAC address, CPU serial, system UUID, etc.
     * 
     * Generate using:
     * ```
     * $identifier = $client->generateHardwareId();
     * $type = IdentifierType::HARDWARE;
     * ```
     */
    public const HARDWARE = 'hwid';

    /**
     * Auto-detect identifier (recommended)
     * 
     * Automatically detects environment and generates appropriate identifier:
     * - Web request: Uses domain ($_SERVER['HTTP_HOST'])
     * - CLI/Background: Uses hardware ID
     * 
     * Example:
     * ```
     * $identifier = $client->generateIdentifier(IdentifierType::AUTO);
     * ```
     */
    public const AUTO = 'auto';

    /**
     * Get all identifier types as array
     * 
     * @return array Map of type names to values
     */
    public static function all(): array
    {
        return [
            'domain' => self::DOMAIN,
            'hardware' => self::HARDWARE,
            'auto' => self::AUTO,
        ];
    }

    /**
     * Get human-readable description for identifier type
     * 
     * @param string $type Identifier type constant
     * @return string Description
     */
    public static function description(string $type): string
    {
        switch ($type) {
            case self::DOMAIN:
                return 'Domain identifier (web applications)';
            case self::HARDWARE:
                return 'Hardware identifier (on-premises/desktop)';
            case self::AUTO:
                return 'Auto-detect identifier (recommended)';
            default:
                return 'Unknown';
        }
    }

    /**
     * Validate identifier type
     * 
     * @param string $type Type to validate
     * @return bool True if valid
     */
    public static function isValid(string $type): bool
    {
        return in_array($type, self::all(), true);
    }
}
