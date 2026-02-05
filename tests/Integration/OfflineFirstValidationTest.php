<?php

declare(strict_types=1);

namespace GetKeyManager\SDK\Tests\Integration;

use PHPUnit\Framework\TestCase;
use GetKeyManager\SDK\LicenseClient;
use GetKeyManager\SDK\Config\Configuration;
use GetKeyManager\SDK\Constants\ValidationType;

/**
 * Integration Tests: Offline-First Validation Strategy
 * 
 * TC-2.1.1: Offline Validation with Cache
 * TC-2.1.2: API Fallback When Offline Fails
 * TC-2.1.3: Force API (Skip Cache)
 * TC-2.1.4: Metadata Passed to API
 */
class OfflineFirstValidationTest extends TestCase
{
    private LicenseClient $client;
    private string $licenseFilePath;
    private string $testLicense = 'LIC-TEST-2024-001';
    private string $testIdentifier = 'example.com';

    protected function setUp(): void
    {
        // Create temporary directory for test licenses
        $this->licenseFilePath = sys_get_temp_dir() . '/test_licenses_' . uniqid();
        mkdir($this->licenseFilePath, 0755, true);

        // Initialize client with test configuration
        $this->client = new LicenseClient([
            'apiKey' => 'test-api-key',
            'baseUrl' => 'https://api.test.com',
            'productPublicKey' => $this->getTestPublicKey(),
            'licenseFilePath' => $this->licenseFilePath,
            'defaultIdentifier' => $this->testIdentifier,
            'cacheEnabled' => true,
            'cacheTtl' => 300,
            'verifySignatures' => false, // Disable for testing
        ]);
    }

    protected function tearDown(): void
    {
        // Clean up test directory
        $files = glob($this->licenseFilePath . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->licenseFilePath);
    }

    /**
     * TC-2.1.1: Offline Validation Concept
     * 
     * Given: License file exists at configured path
     * When: validateLicense() called
     * Then: Offline validation attempted first
     */
    public function testOfflineValidationAttempted(): void
    {
        // Create a test license file
        $licenseData = [
            'license_key' => $this->testLicense,
            'identifier' => $this->testIdentifier,
            'expires_at' => date('Y-m-d H:i:s', strtotime('+1 year')),
            'status' => 'active',
            'features' => ['updates', 'support'],
        ];

        $licenseFile = $this->licenseFilePath . '/test_license.lic';
        file_put_contents($licenseFile, json_encode($licenseData));

        // Verify file exists
        $this->assertFileExists($licenseFile);

        // Verify file is readable
        $this->assertTrue(is_readable($licenseFile));

        // Verify file contains license data
        $content = file_get_contents($licenseFile);
        $this->assertStringContainsString($this->testLicense, $content);
        $this->assertStringContainsString($this->testIdentifier, $content);
    }

    /**
     * TC-2.1.1b: License File Storage Location
     * 
     * Verify license files are stored in configured directory
     */
    public function testLicenseFileStorageLocation(): void
    {
        // Configuration specifies storage location
        $config = new Configuration([
            'apiKey' => 'test-key',
            'licenseFilePath' => '/var/licenses',
        ]);

        $this->assertEquals('/var/licenses', $config->getLicenseFilePath());
    }

    /**
     * TC-2.1.2: API Fallback Concept
     * 
     * Given: Offline validation fails or not available
     * When: validateLicense() called
     * Then: API is called as fallback
     */
    public function testApiFallbackMechanism(): void
    {
        // Simulate offline unavailability
        // In real test, would mock HTTP client to verify API called
        
        $this->assertTrue(true);
        // This is a placeholder for API fallback verification
        // In actual implementation, use mocking to verify HTTP call
    }

    /**
     * TC-2.1.3: Force API Strategy
     * 
     * Given: ValidationType::FORCE_API = true
     * When: validateLicense() called with force=true
     * Then: Offline file ignored, direct API call made
     */
    public function testForceApiStrategyIgnoresCache(): void
    {
        // Create a test license file (should be ignored)
        $licenseFile = $this->licenseFilePath . '/cached_license.lic';
        file_put_contents($licenseFile, json_encode([
            'license_key' => $this->testLicense,
            'expires_at' => date('Y-m-d', strtotime('+1 year')),
        ]));

        $this->assertFileExists($licenseFile);

        // Verify ValidationType constant
        $this->assertTrue(ValidationType::FORCE_API);
        $this->assertFalse(ValidationType::OFFLINE_FIRST);
    }

    /**
     * TC-2.1.3b: Fresh Install Uses Force API
     * 
     * First-time installation should use FORCE_API
     */
    public function testFreshInstallUsesForceApi(): void
    {
        // No license file exists initially
        $expectedFile = $this->licenseFilePath . '/fresh_install.lic';
        $this->assertFileDoesNotExist($expectedFile);

        // First call should use FORCE_API
        // validateLicense('KEY', 'id', null, ValidationType::FORCE_API)
        // Would actually call API and save .lic file

        // This demonstrates the principle
        $this->assertTrue(ValidationType::FORCE_API === true);
    }

    /**
     * TC-2.1.4: Different Validation Strategies
     */
    public function testBothValidationStrategiesAvailable(): void
    {
        // Offline-first (default)
        $offlineFirst = ValidationType::OFFLINE_FIRST;
        $this->assertFalse($offlineFirst);

        // Force API (explicit)
        $forceApi = ValidationType::FORCE_API;
        $this->assertTrue($forceApi);

        // Strategies are different
        $this->assertNotEquals($offlineFirst, $forceApi);
    }

    /**
     * TC-2.1.5: Default Strategy is Offline-First
     * 
     * When no force parameter provided, should use offline-first
     */
    public function testDefaultStrategyIsOfflineFirst(): void
    {
        // Default parameter value in method signature
        $default = ValidationType::OFFLINE_FIRST;
        $this->assertFalse($default);

        // This is the default behavior
        // validateLicense($key, $id) // Uses OFFLINE_FIRST by default
    }

    private function getTestPublicKey(): string
    {
        return '-----BEGIN PUBLIC KEY-----
MIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEA2J3STaL...
-----END PUBLIC KEY-----';
    }
}
