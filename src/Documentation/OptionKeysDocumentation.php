<?php

declare(strict_types=1);

namespace GetKeyManager\SDK\Documentation;

use GetKeyManager\SDK\Constants\OptionKeys;

/**
 * Option Keys Documentation
 * 
 * Provides comprehensive documentation for all valid option keys
 * that can be passed to SDK methods.
 * 
 * This helps developers understand:
 * - Which options are valid for each method
 * - What type each option expects
 * - Default values when option is not provided
 * - Usage examples
 * 
 * @package GetKeyManager\SDK\Documentation
 */
class OptionKeysDocumentation
{
    /**
     * Get documentation for validation/verification method options
     * 
     * These options apply to methods like:
     * - resolveLicenseState()
     * - validateLicense()
     * - isFeatureAllowed()
     * 
     * @return array Detailed documentation for each option
     */
    public static function getValidationOptions(): array
    {
        return [
            OptionKeys::CACHE_TTL => [
                'type' => 'int',
                'default' => 'Uses Configuration::$cacheTtl (default: 300)',
                'description' => 'Cache time-to-live in seconds',
                'example' => '600 (10 minutes)',
                'required' => false,
            ],
            OptionKeys::TIMEOUT => [
                'type' => 'int',
                'default' => 'Uses Configuration::$timeout (default: 30)',
                'description' => 'Request timeout in seconds',
                'example' => '60',
                'required' => false,
            ],
            OptionKeys::METADATA => [
                'type' => 'array',
                'default' => 'empty array',
                'description' => 'Custom metadata key-value pairs to send with request',
                'example' => "['hostname' => 'server-01', 'environment' => 'production']",
                'required' => false,
            ],
            OptionKeys::NO_CACHE => [
                'type' => 'bool',
                'default' => 'false',
                'description' => 'Force bypass cache validation (ignores cached state)',
                'example' => 'true',
                'required' => false,
            ],
        ];
    }

    /**
     * Get documentation for activation/deactivation method options
     * 
     * These options apply to methods like:
     * - activateLicense()
     * - deactivateLicense()
     * 
     * @return array Detailed documentation for each option
     */
    public static function getActivationOptions(): array
    {
        return [
            OptionKeys::IDEMPOTENCY_KEY => [
                'type' => 'string (UUID v4)',
                'default' => 'auto-generated UUID',
                'description' => 'Ensures idempotent requests - same key returns same response',
                'example' => '550e8400-e29b-41d4-a716-446655440000',
                'required' => false,
                'note' => 'Automatically generated if not provided',
            ],
            OptionKeys::OS => [
                'type' => 'string',
                'default' => 'PHP_OS (auto-detected)',
                'description' => 'Operating system identifier',
                'example' => '"Windows 10 Pro" or "Ubuntu 20.04"',
                'required' => false,
            ],
            OptionKeys::PRODUCT_VERSION => [
                'type' => 'string',
                'default' => 'null',
                'description' => 'Application/product version string',
                'example' => '"1.2.3"',
                'required' => false,
            ],
            OptionKeys::IP => [
                'type' => 'string (IP address)',
                'default' => 'auto-detected from $_SERVER',
                'description' => 'Client/user IP address for tracking activation location',
                'example' => '"192.168.1.1" or "203.0.113.45"',
                'required' => false,
            ],
            OptionKeys::HWID => [
                'type' => 'string',
                'default' => 'auto-generated via generateHardwareId()',
                'description' => 'Hardware ID override (use config value if not provided)',
                'example' => '"abc123def456"',
                'required' => false,
            ],
            OptionKeys::DOMAIN => [
                'type' => 'string',
                'default' => 'auto-detected from $_SERVER[\'HTTP_HOST\']',
                'description' => 'Domain override (use config value if not provided)',
                'example' => '"example.com"',
                'required' => false,
            ],
        ];
    }

    /**
     * Get documentation for telemetry method options
     * 
     * These options apply to methods like:
     * - sendTelemetry()
     * 
     * @return array Detailed documentation for each option
     */
    public static function getTelemetryOptions(): array
    {
        return [
            OptionKeys::USER_ID => [
                'type' => 'string',
                'default' => 'null',
                'description' => 'User/account identifier for telemetry',
                'example' => '"user@example.com" or "usr_12345"',
                'required' => false,
            ],
            OptionKeys::COUNTRY => [
                'type' => 'string (ISO 3166-1 alpha-2)',
                'default' => 'auto-detected or null',
                'description' => 'Country code for geolocation tracking',
                'example' => '"US" or "GB" or "JP"',
                'required' => false,
            ],
            OptionKeys::FLAGS => [
                'type' => 'array of strings',
                'default' => 'empty array',
                'description' => 'Flags for categorizing telemetry events',
                'example' => "['suspicious', 'vpn_detected', 'unusual_activity']",
                'required' => false,
            ],
            OptionKeys::METADATA => [
                'type' => 'array',
                'default' => 'empty array',
                'description' => 'Custom metadata key-value pairs',
                'example' => "['session_duration' => 3600, 'feature_used' => 'export']",
                'required' => false,
            ],
        ];
    }

    /**
     * Get combined documentation for all options
     * 
     * @return array All options documentation
     */
    public static function getAll(): array
    {
        return array_merge(
            self::getValidationOptions(),
            self::getActivationOptions(),
            self::getTelemetryOptions()
        );
    }

    /**
     * Get documentation for a specific option key
     * 
     * @param string $optionKey Option key constant
     * @return array|null Documentation or null if not found
     */
    public static function get(string $optionKey): ?array
    {
        $all = self::getAll();
        return $all[$optionKey] ?? null;
    }

    /**
     * Get formatted usage example for method
     * 
     * @param string $methodName Method name (e.g., 'resolveLicenseState')
     * @return string Usage example
     */
    public static function getUsageExample(string $methodName): string
    {
        $examples = [
            'resolveLicenseState' => <<<'PHP'
// Fresh installation (force API)
$state = $client->resolveLicenseState(
    'LIC-2024-ABCD1234',
    'example.com',
    $publicKey,
    ValidationType::FORCE_API,
    [
        OptionKeys::METADATA => ['version' => '1.0'],
        OptionKeys::TIMEOUT => 60,
    ]
);

// Subsequent calls (offline-first, default)
$state = $client->resolveLicenseState(
    'LIC-2024-ABCD1234',
    'example.com',
    $publicKey
);
PHP,
            'activateLicense' => <<<'PHP'
// Activate with custom options
$result = $client->activateLicense(
    'LIC-2024-ABCD1234',
    'example.com',
    [
        OptionKeys::OS => 'Windows 10 Pro',
        OptionKeys::PRODUCT_VERSION => '1.2.3',
        OptionKeys::IP => $_SERVER['REMOTE_ADDR'],
        OptionKeys::IDEMPOTENCY_KEY => $client->generateUuid(),
    ]
);
PHP,
            'sendTelemetry' => <<<'PHP'
// Send telemetry with options
$result = $client->sendTelemetry(
    'LIC-2024-ABCD1234',
    'usage',
    'numeric-single-value',
    100,
    [
        OptionKeys::USER_ID => 'user@example.com',
        OptionKeys::COUNTRY => 'US',
        OptionKeys::FLAGS => ['unusual_activity'],
        OptionKeys::METADATA => ['feature' => 'export_pdf'],
    ]
);
PHP,
        ];

        return $examples[$methodName] ?? 'No example available';
    }

    /**
     * Get formatted documentation as markdown
     * 
     * Useful for generating documentation or help text
     * 
     * @param string $section Section to document ('validation', 'activation', 'telemetry', or 'all')
     * @return string Markdown documentation
     */
    public static function getMarkdown(string $section = 'all'): string
    {
        $markdown = "# Option Keys Documentation\n\n";

        switch ($section) {
            case 'validation':
                $markdown .= "## Validation/Verification Options\n\n";
                foreach (self::getValidationOptions() as $key => $doc) {
                    $markdown .= self::formatOptionMarkdown($key, $doc);
                }
                break;

            case 'activation':
                $markdown .= "## Activation/Deactivation Options\n\n";
                foreach (self::getActivationOptions() as $key => $doc) {
                    $markdown .= self::formatOptionMarkdown($key, $doc);
                }
                break;

            case 'telemetry':
                $markdown .= "## Telemetry Options\n\n";
                foreach (self::getTelemetryOptions() as $key => $doc) {
                    $markdown .= self::formatOptionMarkdown($key, $doc);
                }
                break;

            case 'all':
            default:
                $markdown .= "## Validation/Verification Options\n\n";
                foreach (self::getValidationOptions() as $key => $doc) {
                    $markdown .= self::formatOptionMarkdown($key, $doc);
                }
                $markdown .= "\n## Activation/Deactivation Options\n\n";
                foreach (self::getActivationOptions() as $key => $doc) {
                    $markdown .= self::formatOptionMarkdown($key, $doc);
                }
                $markdown .= "\n## Telemetry Options\n\n";
                foreach (self::getTelemetryOptions() as $key => $doc) {
                    $markdown .= self::formatOptionMarkdown($key, $doc);
                }
        }

        return $markdown;
    }

    /**
     * Format a single option as markdown
     * 
     * @param string $key Option key
     * @param array $doc Documentation
     * @return string Formatted markdown
     */
    private static function formatOptionMarkdown(string $key, array $doc): string
    {
        $markdown = "### `{$key}`\n\n";
        $markdown .= "- **Type:** `{$doc['type']}`\n";
        $markdown .= "- **Default:** {$doc['default']}\n";
        $markdown .= "- **Required:** " . ($doc['required'] ? 'Yes' : 'No') . "\n";
        $markdown .= "- **Description:** {$doc['description']}\n";
        $markdown .= "- **Example:** `{$doc['example']}`\n";

        if (isset($doc['note'])) {
            $markdown .= "- **Note:** {$doc['note']}\n";
        }

        $markdown .= "\n";

        return $markdown;
    }
}
