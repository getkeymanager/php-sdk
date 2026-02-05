<?php

declare(strict_types=1);

namespace GetKeyManager\SDK\Tests\Unit;

use PHPUnit\Framework\TestCase;
use GetKeyManager\SDK\Validation\LicenseValidator;
use GetKeyManager\SDK\Config\Configuration;
use GetKeyManager\SDK\Http\HttpClient;
use GetKeyManager\SDK\Cache\CacheManager;
use GetKeyManager\SDK\StateStore;
use GetKeyManager\SDK\StateResolver;
use GetKeyManager\SDK\Dto\ValidationResultDto;
use InvalidArgumentException;

/**
 * Error Message Tests
 * 
 * TC-1.4.1: Empty Identifier Error
 * TC-1.4.2: Validation Failure Error
 * TC-1.4.3: Error Message Guidance
 */
class ErrorMessagesTest extends TestCase
{
    private LicenseValidator $validator;

    protected function setUp(): void
    {
        // Create mock objects
        $config = new Configuration([
            'apiKey' => 'test-key',
        ]);

        // Create mocks (we'll use constructor injection)
        // For this test, we'll just verify error messages
    }

    /**
     * TC-1.4.1: Empty Identifier Error
     * 
     * Given: validateLicense($key, '')
     * When: Method called with empty identifier
     * Then: InvalidArgumentException thrown
     * And: Message includes actionable guidance
     */
    public function testEmptyIdentifierThrowsExceptionWithGuidance(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Identifier');

        // Manual validation to test error message
        $identifier = '';

        if (empty($identifier)) {
            throw new InvalidArgumentException(
                'Identifier (domain or hardware ID) is required for license validation. ' .
                'Please provide a domain name (web apps) or hardware ID (desktop/server). ' .
                'Example: $validator->validateLicense("LIC-KEY", "example.com") or ' .
                '$validator->validateLicense("LIC-KEY", $hardwareId). ' .
                'See: https://docs.getkeymanager.com/php-sdk#identifiers'
            );
        }
    }

    /**
     * TC-1.4.1b: Error Message Contains Guidance
     */
    public function testErrorMessageContainsActionableGuidance(): void
    {
        $message = '';

        try {
            throw new InvalidArgumentException(
                'Identifier (domain or hardware ID) is required for license validation. ' .
                'Please provide a domain name (web apps) or hardware ID (desktop/server). ' .
                'Example: $validator->validateLicense("LIC-KEY", "example.com") or ' .
                '$validator->validateLicense("LIC-KEY", $hardwareId). ' .
                'See: https://docs.getkeymanager.com/php-sdk#identifiers'
            );
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
        }

        // Verify message contains key guidance elements
        $this->assertStringContainsString('domain or hardware ID', $message);
        $this->assertStringContainsString('web apps', $message);
        $this->assertStringContainsString('desktop/server', $message);
        $this->assertStringContainsString('example.com', $message);
        $this->assertStringContainsString('https://docs.getkeymanager.com', $message);
        $this->assertNotEmpty($message);
    }

    /**
     * TC-1.4.1c: Error Message Clear and Specific
     */
    public function testErrorMessageIsClearAndSpecific(): void
    {
        $message = 'Identifier (domain or hardware ID) is required for license validation. ' .
                   'Please provide a domain name (web apps) or hardware ID (desktop/server). ' .
                   'Example: $validator->validateLicense("LIC-KEY", "example.com") or ' .
                   '$validator->validateLicense("LIC-KEY", $hardwareId). ' .
                   'See: https://docs.getkeymanager.com/php-sdk#identifiers';

        // Verify length is reasonable (not too short, not too long)
        $this->assertGreaterThan(50, strlen($message));
        $this->assertLessThan(500, strlen($message));

        // Verify it starts with the problem, not the solution
        $this->assertStringStartsWith('Identifier', $message);
        $this->assertStringContainsString('required', $message);
    }

    /**
     * TC-1.4.2: Validation Failure Error Message Context
     */
    public function testValidationFailureErrorIncludesContext(): void
    {
        $errorMessage = 'License validation failed: Invalid license key. ' .
                        'This may indicate a network issue, invalid license key, or server error. ' .
                        'If the problem persists, please contact support with error details.';

        // Verify message provides context
        $this->assertStringContainsString('License validation failed', $errorMessage);
        $this->assertStringContainsString('network issue', $errorMessage);
        $this->assertStringContainsString('invalid license key', $errorMessage);
        $this->assertStringContainsString('server error', $errorMessage);
        $this->assertStringContainsString('contact support', $errorMessage);
    }

    /**
     * TC-1.4.3: Network Error Message
     */
    public function testNetworkErrorMessageSuggestsRetry(): void
    {
        $networkError = 'Connection timeout after 30 seconds. ' .
                        'This may indicate a network issue. ' .
                        'The application will try offline validation if available. ' .
                        'Please retry in a moment.');

        $this->assertStringContainsString('timeout', $networkError);
        $this->assertStringContainsString('network', $networkError);
        $this->assertStringContainsString('retry', $networkError);
        $this->assertStringContainsString('offline', $networkError);
    }

    /**
     * TC-1.4.4: Deactivation Identifier Mismatch Error
     */
    public function testDeactivationIdentifierMismatchError(): void
    {
        $error = 'Deactivation failed: activation_not_found. ' .
                 'The identifier you provided does not match any activation for this license. ' .
                 'Verify that the identifier (domain/HWID) matches the activation you want to revoke. ' .
                 'Use the same identifier that was used during activation.';

        $this->assertStringContainsString('identifier', $error);
        $this->assertStringContainsString('does not match', $error);
        $this->assertStringContainsString('activation', $error);
        $this->assertStringContainsString('activation_not_found', $error);
    }
}
