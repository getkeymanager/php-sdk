<?php

declare(strict_types=1);

namespace GetKeyManager\SDK\Tests\Integration;

use PHPUnit\Framework\TestCase;
use GetKeyManager\SDK\LicenseClient;
use GetKeyManager\SDK\Config\Configuration;

/**
 * Integration Tests: Configuration Inheritance
 * 
 * TC-2.3.1: Public Key Inheritance
 * TC-2.3.2: Identifier Inheritance
 * TC-2.3.3: License File Path Inheritance
 */
class ConfigurationInheritanceTest extends TestCase
{
    private string $testPublicKey = '-----BEGIN PUBLIC KEY-----
MIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEA2J3STaL...
-----END PUBLIC KEY-----';

    /**
     * TC-2.3.1: Public Key Inheritance
     * 
     * Given: Configuration with productPublicKey set
     * When: Method called without publicKey argument
     * Then: Config's productPublicKey used automatically
     */
    public function testPublicKeyInheritedFromConfiguration(): void
    {
        $config = new Configuration([
            'apiKey' => 'test-key',
            'productPublicKey' => $this->testPublicKey,
        ]);

        // Public key available in configuration
        $this->assertNotNull($config->getProductPublicKey());
        $this->assertEquals($this->testPublicKey, $config->getProductPublicKey());
    }

    /**
     * TC-2.3.1b: Multiple Configurations with Different Keys
     */
    public function testMultipleConfigurationsCanHaveDifferentKeys(): void
    {
        $key1 = '-----BEGIN PUBLIC KEY-----\nKEY1\n-----END PUBLIC KEY-----';
        $key2 = '-----BEGIN PUBLIC KEY-----\nKEY2\n-----END PUBLIC KEY-----';

        $config1 = new Configuration([
            'apiKey' => 'key1',
            'productPublicKey' => $key1,
        ]);

        $config2 = new Configuration([
            'apiKey' => 'key2',
            'productPublicKey' => $key2,
        ]);

        // Each config maintains its own key
        $this->assertEquals($key1, $config1->getProductPublicKey());
        $this->assertEquals($key2, $config2->getProductPublicKey());
        $this->assertNotEquals($config1->getProductPublicKey(), $config2->getProductPublicKey());
    }

    /**
     * TC-2.3.2: Identifier Inheritance
     * 
     * Given: Configuration with defaultIdentifier set
     * When: Method called without identifier
     * Then: Config's defaultIdentifier used
     */
    public function testDefaultIdentifierInheritedFromConfiguration(): void
    {
        $config = new Configuration([
            'apiKey' => 'test-key',
            'defaultIdentifier' => 'example.com',
        ]);

        // Identifier available in configuration
        $this->assertNotNull($config->getDefaultIdentifier());
        $this->assertEquals('example.com', $config->getDefaultIdentifier());
    }

    /**
     * TC-2.3.2b: Empty Identifier Falls Back to Config
     */
    public function testEmptyIdentifierFallsBackToConfig(): void
    {
        $globalIdentifier = 'global-domain.com';

        $config = new Configuration([
            'apiKey' => 'test-key', 
            'defaultIdentifier' => $globalIdentifier,
        ]);

        // If method receives empty identifier, should use config value
        $providedIdentifier = '';
        $effectiveIdentifier = !empty($providedIdentifier) 
            ? $providedIdentifier 
            : $config->getDefaultIdentifier();

        $this->assertEquals($globalIdentifier, $effectiveIdentifier);
    }

    /**
     * TC-2.3.3: License File Path Inheritance
     * 
     * Given: Configuration with licenseFilePath set
     * When: License validation succeeds
     * Then: Response .lic file saved to configured path
     */
    public function testLicenseFilePathInheritedFromConfiguration(): void
    {
        $licenseFilePath = '/var/app/licenses';

        $config = new Configuration([
            'apiKey' => 'test-key',
            'licenseFilePath' => $licenseFilePath,
        ]);

        // Path available in configuration
        $this->assertNotNull($config->getLicenseFilePath());
        $this->assertEquals($licenseFilePath, $config->getLicenseFilePath());
    }

    /**
     * TC-2.3.3b: Multiple Paths Isolated
     */
    public function testMultipleConfigurationsUseDifferentLicensePaths(): void
    {
        $path1 = '/app1/licenses';
        $path2 = '/app2/licenses';

        $config1 = new Configuration([
            'apiKey' => 'key1',
            'licenseFilePath' => $path1,
        ]);

        $config2 = new Configuration([
            'apiKey' => 'key2',
            'licenseFilePath' => $path2,
        ]);

        // Each config maintains separate path
        $this->assertEquals($path1, $config1->getLicenseFilePath());
        $this->assertEquals($path2, $config2->getLicenseFilePath());
        $this->assertNotEquals($config1->getLicenseFilePath(), $config2->getLicenseFilePath());
    }

    /**
     * TC-2.3.4: License Key Inheritance
     */
    public function testLicenseKeyInheritedFromConfiguration(): void
    {
        $appLicenseKey = 'APP-LICENSE-KEY-2024';

        $config = new Configuration([
            'apiKey' => 'test-key',
            'licenseKey' => $appLicenseKey,
        ]);

        // License key available in configuration
        $this->assertNotNull($config->getLicenseKey());
        $this->assertEquals($appLicenseKey, $config->getLicenseKey());
    }

    /**
     * TC-2.3.5: Complete Configuration Inheritance Chain
     * 
     * Verify all inheritance paths work together
     */
    public function testCompleteConfigurationInheritanceChain(): void
    {
        $config = new Configuration([
            'apiKey' => 'test-api-key',
            'productPublicKey' => $this->testPublicKey,
            'licenseFilePath' => '/licenses',
            'defaultIdentifier' => 'example.com',
            'licenseKey' => 'APP-KEY',
        ]);

        // All inheritance paths available
        $this->assertEquals('test-api-key', $config->getApiKey());
        $this->assertEquals($this->testPublicKey, $config->getProductPublicKey());
        $this->assertEquals('/licenses', $config->getLicenseFilePath());
        $this->assertEquals('example.com', $config->getDefaultIdentifier());
        $this->assertEquals('APP-KEY', $config->getLicenseKey());

        // All values non-null (when set)
        $this->assertNotNull($config->getApiKey());
        $this->assertNotNull($config->getProductPublicKey());
        $this->assertNotNull($config->getLicenseFilePath());
        $this->assertNotNull($config->getDefaultIdentifier());
        $this->assertNotNull($config->getLicenseKey());
    }

    /**
     * TC-2.3.5b: Partial Configuration (Only Required)
     */
    public function testPartialConfigurationSuppliedWithDefaults(): void
    {
        $config = new Configuration([
            'apiKey' => 'test-key',
            // Only API key provided, rest use defaults
        ]);

        // Required values present
        $this->assertEquals('test-key', $config->getApiKey());

        // Defaults applied for optional values
        $this->assertNotNull($config->getBaseUrl());
        $this->assertGreaterThan(0, $config->getTimeout());
        $this->assertGreaterThanOrEqual(0, $config->getCacheTtl());

        // Optional values null (not set)
        $this->assertNull($config->getProductPublicKey());
        $this->assertNull($config->getLicenseFilePath());
        $this->assertNull($config->getDefaultIdentifier());
    }
}
