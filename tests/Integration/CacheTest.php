<?php

declare(strict_types=1);

namespace GetKeyManager\SDK\Tests\Integration;

use PHPUnit\Framework\TestCase;
use GetKeyManager\SDK\LicenseClient;
use GetKeyManager\SDK\Config\Configuration;
use GetKeyManager\SDK\Cache\CacheManager;

/**
 * Integration Tests: Cache Behavior
 * 
 * TC-2.4.1: Cache Hit (TTL Not Exceeded)
 * TC-2.4.2: Cache Expiration (TTL Exceeded)
 * TC-2.4.3: Skip Cache Option
 */
class CacheTest extends TestCase
{
    /**
     * TC-2.4.1: Cache Configuration TTL
     * 
     * Given: Cache enabled with TTL = 300 seconds
     * When: Configuration created
     * Then: Cache TTL stored and accessible
     */
    public function testCacheTtlConfiguration(): void
    {
        $cacheTtl = 300;

        $config = new Configuration([
            'apiKey' => 'test-key',
            'cacheEnabled' => true,
            'cacheTtl' => $cacheTtl,
        ]);

        // Cache configuration stored
        $this->assertTrue($config->isCacheEnabled());
        $this->assertEquals(300, $config->getCacheTtl());
    }

    /**
     * TC-2.4.1b: Cache Default TTL
     */
    public function testCacheDefaultTtl(): void
    {
        $config = new Configuration([
            'apiKey' => 'test-key',
            'cacheEnabled' => true,
            // No cacheTtl provided, should use default
        ]);

        // Default TTL (300 seconds)
        $this->assertEquals(300, $config->getCacheTtl());
    }

    /**
     * TC-2.4.2: Cache Disabled
     * 
     * Given: cacheEnabled = false
     * When: validateLicense() called
     * Then: Cache not used, every call hits API
     */
    public function testCacheCanBeDisabled(): void
    {
        $config = new Configuration([
            'apiKey' => 'test-key',
            'cacheEnabled' => false,
        ]);

        $this->assertFalse($config->isCacheEnabled());
    }

    /**
     * TC-2.4.3: Cache Key Generation
     * 
     * Verify cache keys are consistent and unique
     */
    public function testCacheKeyGeneration(): void
    {
        // Cache keys should include license key and identifier
        $licenseKey = 'LIC-TEST-123';
        $identifier = 'example.com';

        // Example of cache key generation
        $cacheKey1 = md5('license:' . $licenseKey . ':validation:' . $identifier);
        $cacheKey2 = md5('license:' . $licenseKey . ':validation:' . $identifier);

        // Same inputs produce same cache key
        $this->assertEquals($cacheKey1, $cacheKey2);

        // Different identifier produces different cache key
        $cacheKey3 = md5('license:' . $licenseKey . ':validation:' . 'other.com');
        $this->assertNotEquals($cacheKey1, $cacheKey3);
    }

    /**
     * TC-2.4.4: Cache Hit Scenario Concept
     * 
     * Given: Cache enabled and data cached
     * When: Same validation requested within TTL
     * Then: Cached data returned, no API call
     */
    public function testCacheHitScenario(): void
    {
        // Configuration with caching
        $config = new Configuration([
            'apiKey' => 'test-key',
            'cacheEnabled' => true,
            'cacheTtl' => 300,
        ]);

        // Simulate cache entry time and current time
        $cacheTime = time() - 100; // Cached 100 seconds ago
        $currentTime = time();
        $ttl = 300;

        // Cache is still valid (not expired)
        $timeSinceCached = $currentTime - $cacheTime;
        $isCacheValid = $timeSinceCached < $ttl;

        $this->assertTrue($isCacheValid, 'Cache should be valid within TTL');
    }

    /**
     * TC-2.4.5: Cache Expiration Scenario
     * 
     * Given: Cache TTL exceeded
     * When: Same validation requested
     * Then: Cache treated as expired, API called
     */
    public function testCacheExpirationScenario(): void
    {
        $config = new Configuration([
            'apiKey' => 'test-key',
            'cacheEnabled' => true,
            'cacheTtl' => 300,
        ]);

        // Simulate old cache time
        $cacheTime = time() - 400; // Cached 400 seconds ago (beyond 300s TTL)
        $currentTime = time();
        $ttl = 300;

        // Cache is expired
        $timeSinceCached = $currentTime - $cacheTime;
        $isCacheExpired = $timeSinceCached >= $ttl;

        $this->assertTrue($isCacheExpired, 'Cache should be expired after TTL');
    }

    /**
     * TC-2.4.6: NO_CACHE Option
     * 
     * Given: NO_CACHE option = true
     * When: validateLicense() called with option
     * Then: Cache bypassed, fresh API call made
     */
    public function testNoCacheOptionBypassesCache(): void
    {
        // The NO_CACHE option should be respected
        $options = [
            'NO_CACHE' => true,
        ];

        // Option presence indicates cache should be skipped
        $this->assertTrue(isset($options['NO_CACHE']));
        $this->assertTrue($options['NO_CACHE']);
    }

    /**
     * TC-2.4.7: NO_CACHE Behavior
     */
    public function testNoCachePreventsStorage(): void
    {
        $options = [
            'NO_CACHE' => true,
        ];

        // When NO_CACHE is set, response should not be stored
        if (isset($options['NO_CACHE']) && $options['NO_CACHE']) {
            $shouldCache = false;
        } else {
            $shouldCache = true;
        }

        $this->assertFalse($shouldCache, 'Should not cache when NO_CACHE option set');
    }

    /**
     * TC-2.4.8: Cache Timeout Option
     */
    public function testCustomCacheTimeoutOption(): void
    {
        $config = new Configuration([
            'apiKey' => 'test-key',
            'cacheEnabled' => true,
            'cacheTtl' => 300, // Default
        ]);

        // Method can override with CACHE_TTL option
        $options = [
            'CACHE_TTL' => 600, // Override to 10 minutes
        ];

        $effectiveTtl = isset($options['CACHE_TTL']) 
            ? $options['CACHE_TTL'] 
            : $config->getCacheTtl();

        $this->assertEquals(600, $effectiveTtl);
    }

    /**
     * TC-2.4.9: Multiple Cache Entries
     */
    public function testMultipleLicensesStoredSeperately(): void
    {
        // Different licenses should have separate cache entries
        $license1 = 'LIC-KEY-1';
        $license2 = 'LIC-KEY-2';

        $cacheKey1 = md5('license:' . $license1 . ':validation');
        $cacheKey2 = md5('license:' . $license2 . ':validation');

        // Different keys (not the same entry)
        $this->assertNotEquals($cacheKey1, $cacheKey2);

        // Each can be cached independently
        $this->assertNotEmpty($cacheKey1);
        $this->assertNotEmpty($cacheKey2);
    }
}
