<?php

declare(strict_types=1);

namespace GetKeyManager\SDK\Tests\Unit;

use PHPUnit\Framework\TestCase;
use GetKeyManager\SDK\Config\Configuration;
use InvalidArgumentException;

/**
 * Configuration System Tests
 * 
 * TC-1.1.1: Load Configuration with All Properties
 * TC-1.1.2: Load Public Key from File
 * TC-1.1.3: Validate Configuration Constraints
 * TC-1.1.4: Configuration Inheritance Chain
 */
class ConfigurationTest extends TestCase
{
    private string $testPublicKey = '-----BEGIN PUBLIC KEY-----
MIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEA2J3STaL...
-----END PUBLIC KEY-----';

    /**
     * TC-1.1.1: Load Configuration with All Properties
     * 
     * Given: Configuration array with all options
     * When: Create Configuration instance
     * Then: All properties initialized correctly
     */
    public function testLoadConfigurationWithAllProperties(): void
    {
        $config = new Configuration([
            'apiKey' => 'test-api-key',
            'baseUrl' => 'https://api.test.com',
            'publicKey' => $this->testPublicKey,
            'productPublicKey' => $this->testPublicKey,
            'licenseFilePath' => '/test/licenses',
            'licenseKey' => 'APP-LIC-KEY',
            'defaultIdentifier' => 'example.com',
            'timeout' => 60,
            'cacheTtl' => 600,
            'cacheEnabled' => true,
            'verifySignatures' => true,
            'environment' => 'production',
        ]);

        // Assertions
        $this->assertEquals('test-api-key', $config->getApiKey());
        $this->assertEquals('https://api.test.com', $config->getBaseUrl());
        $this->assertEquals($this->testPublicKey, $config->getPublicKey());
        $this->assertEquals($this->testPublicKey, $config->getProductPublicKey());
        $this->assertEquals('/test/licenses', $config->getLicenseFilePath());
        $this->assertEquals('APP-LIC-KEY', $config->getLicenseKey());
        $this->assertEquals('example.com', $config->getDefaultIdentifier());
        $this->assertEquals(60, $config->getTimeout());
        $this->assertEquals(600, $config->getCacheTtl());
        $this->assertTrue($config->isCacheEnabled());
        $this->assertTrue($config->shouldVerifySignatures());
        $this->assertEquals('production', $config->getEnvironment());
    }

    /**
     * TC-1.1.1b: Use Default Values for Missing Properties
     * 
     * Given: Minimal configuration
     * When: Create Configuration instance
     * Then: Defaults applied for missing values
     */
    public function testDefaultValuesAppliedForMissingProperties(): void
    {
        $config = new Configuration([
            'apiKey' => 'test-api-key',
        ]);

        // Verify defaults
        $this->assertEquals('https://dev.getkeymanager.com/api', $config->getBaseUrl());
        $this->assertEquals(30, $config->getTimeout()); // DEFAULT_TIMEOUT
        $this->assertEquals(300, $config->getCacheTtl()); // DEFAULT_CACHE_TTL
        $this->assertTrue($config->isCacheEnabled());
        $this->assertTrue($config->shouldVerifySignatures());
        $this->assertNull($config->getProductPublicKey());
        $this->assertNull($config->getDefaultIdentifier());
    }

    /**
     * TC-1.1.2: Validate Configuration Constraints
     * 
     * Given: Invalid configuration values
     * When: Create Configuration instance
     * Then: InvalidArgumentException thrown with clear message
     */
    public function testEmptyApiKeyThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('apiKey is required');

        new Configuration([
            'apiKey' => '',
        ]);
    }

    /**
     * TC-1.1.2b: Negative Timeout Clamped to Minimum
     * 
     * Given: Configuration with negative timeout
     * When: Create Configuration instance
     * Then: Timeout clamped to minimum (1s)
     */
    public function testNegativeTimeoutClampedToMinimum(): void
    {
        $config = new Configuration([
            'apiKey' => 'test-api-key',
            'timeout' => -100,
        ]);

        // Should be clamped to minimum of 1
        $this->assertGreaterThanOrEqual(1, $config->getTimeout());
    }

    /**
     * TC-1.1.2c: Zero Cache TTL Allowed
     * 
     * Given: Configuration with 0 cache TTL
     * When: Create Configuration instance
     * Then: Zero accepted (cache disabled)
     */
    public function testZeroCacheTtlAllowed(): void
    {
        $config = new Configuration([
            'apiKey' => 'test-api-key',
            'cacheTtl' => 0,
        ]);

        $this->assertEquals(0, $config->getCacheTtl());
    }

    /**
     * TC-1.1.3: Configuration Property Getters
     * 
     * Verify all configuration getters return correct types
     */
    public function testConfigurationGettersReturnCorrectTypes(): void
    {
        $config = new Configuration([
            'apiKey' => 'test-api-key',
            'publicKey' => $this->testPublicKey,
            'productPublicKey' => $this->testPublicKey,
            'cacheEnabled' => true,
        ]);

        // Type assertions
        $this->assertIsString($config->getApiKey());
        $this->assertIsString($config->getBaseUrl());
        $this->assertIsString($config->getPublicKey());
        $this->assertIsString($config->getProductPublicKey());
        $this->assertIsBool($config->isCacheEnabled());
        $this->assertIsBool($config->shouldVerifySignatures());
        $this->assertIsInt($config->getTimeout());
        $this->assertIsInt($config->getCacheTtl());
    }

    /**
     * TC-1.1.4: Configuration Inheritance Chain
     * 
     * Verify configuration can be created and values inherited
     */
    public function testConfigurationInheritanceChain(): void
    {
        $config = new Configuration([
            'apiKey' => 'test-api-key',
            'productPublicKey' => $this->testPublicKey,
            'licenseFilePath' => '/test/licenses',
            'defaultIdentifier' => 'example.com',
            'licenseKey' => 'APP-KEY',
        ]);

        // All inheritance paths should work
        $this->assertNotNull($config->getProductPublicKey());
        $this->assertNotNull($config->getLicenseFilePath());
        $this->assertNotNull($config->getDefaultIdentifier());
        $this->assertNotNull($config->getLicenseKey());

        // Verify values are exactly what was set
        $this->assertEquals($this->testPublicKey, $config->getProductPublicKey());
        $this->assertEquals('/test/licenses', $config->getLicenseFilePath());
        $this->assertEquals('example.com', $config->getDefaultIdentifier());
        $this->assertEquals('APP-KEY', $config->getLicenseKey());
    }

    /**
     * TC-1.1.4b: Multiple Configuration Instances
     * 
     * Verify multiple instances don't interfere with each other
     */
    public function testMultipleConfigurationInstancesIsolated(): void
    {
        $config1 = new Configuration([
            'apiKey' => 'key-1',
            'defaultIdentifier' => 'domain1.com',
        ]);

        $config2 = new Configuration([
            'apiKey' => 'key-2',
            'defaultIdentifier' => 'domain2.com',
        ]);

        // Verify isolation
        $this->assertEquals('key-1', $config1->getApiKey());
        $this->assertEquals('key-2', $config2->getApiKey());
        $this->assertEquals('domain1.com', $config1->getDefaultIdentifier());
        $this->assertEquals('domain2.com', $config2->getDefaultIdentifier());
    }
}
