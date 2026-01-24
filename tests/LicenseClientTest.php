<?php

declare(strict_types=1);

namespace GetKeyManager\SDK\Tests;

use PHPUnit\Framework\TestCase;
use GetKeyManager\SDK\LicenseClient;

/**
 * Test cases for LicenseClient
 */
class LicenseClientTest extends TestCase
{
    private LicenseClient $client;
    private string $testApiKey = 'test_api_key_12345';
    private string $testPublicKey = '-----BEGIN PUBLIC KEY-----
MIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEAtest
-----END PUBLIC KEY-----';

    protected function setUp(): void
    {
        $this->client = new LicenseClient([
            'apiKey' => $this->testApiKey,
            'baseUrl' => 'https://api.getkeymanager.com',
            'verifySignatures' => false, // Disable for testing
            'cacheEnabled' => false,
        ]);
    }

    public function testConstructorRequiresApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LicenseClient([]);
    }

    public function testConstructorAcceptsValidConfig(): void
    {
        $client = new LicenseClient([
            'apiKey' => 'test_key',
            'baseUrl' => 'https://test.example.com',
            'timeout' => 60,
        ]);

        $this->assertInstanceOf(LicenseClient::class, $client);
    }

    public function testGenerateHardwareId(): void
    {
        $hardwareId = $this->client->generateHardwareId();
        
        $this->assertIsString($hardwareId);
        $this->assertNotEmpty($hardwareId);
        $this->assertEquals(32, strlen($hardwareId));
        
        // Hardware ID should be consistent
        $hardwareId2 = $this->client->generateHardwareId();
        $this->assertEquals($hardwareId, $hardwareId2);
    }

    public function testValidateLicenseKeyFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client->validateLicense('');
    }

    public function testCacheOperations(): void
    {
        $clientWithCache = new LicenseClient([
            'apiKey' => $this->testApiKey,
            'cacheEnabled' => true,
            'cacheTtl' => 300,
        ]);

        // Test cache clear
        $clientWithCache->clearCache();
        $this->assertTrue(true); // No exception thrown
    }

    public function testValidateOfflineLicenseRequiresPublicKey(): void
    {
        $offlineLicense = json_encode([
            'version' => '1.0',
            'license' => [
                'key' => 'TEST-KEY-1234',
                'status' => 'active',
            ],
            'signature' => 'test_signature',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->client->validateOfflineLicense($offlineLicense);
    }

    public function testValidateOfflineLicenseWithInvalidJson(): void
    {
        $client = new LicenseClient([
            'apiKey' => $this->testApiKey,
            'publicKey' => $this->testPublicKey,
            'verifySignatures' => false,
        ]);

        $result = $client->validateOfflineLicense('invalid json');
        
        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('errors', $result);
    }

    public function testIdempotencyKeyGeneration(): void
    {
        $reflection = new \ReflectionClass($this->client);
        $method = $reflection->getMethod('generateUuid');
        $method->setAccessible(true);

        $uuid1 = $method->invoke($this->client);
        $uuid2 = $method->invoke($this->client);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid1
        );
        $this->assertNotEquals($uuid1, $uuid2);
    }

    public function testConfigurationDefaults(): void
    {
        $client = new LicenseClient([
            'apiKey' => 'test_key',
        ]);

        $reflection = new \ReflectionClass($client);
        
        $baseUrlProp = $reflection->getProperty('baseUrl');
        $baseUrlProp->setAccessible(true);
        $this->assertEquals('https://api.getkeymanager.com', $baseUrlProp->getValue($client));

        $timeoutProp = $reflection->getProperty('timeout');
        $timeoutProp->setAccessible(true);
        $this->assertEquals(30, $timeoutProp->getValue($client));

        $verifyProp = $reflection->getProperty('verifySignatures');
        $verifyProp->setAccessible(true);
        $this->assertTrue($verifyProp->getValue($client));
    }
}
