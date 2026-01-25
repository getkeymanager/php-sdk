<?php

declare(strict_types=1);

namespace GetKeyManager\SDK\Cache;

/**
 * Cache Manager
 * 
 * Handles caching of API responses to reduce redundant requests.
 * 
 * @package GetKeyManager\SDK\Cache
 */
class CacheManager
{
    private array $cache = [];
    private bool $enabled;
    private int $ttl;

    /**
     * Initialize cache manager
     * 
     * @param bool $enabled Whether caching is enabled
     * @param int $ttl Cache TTL in seconds
     */
    public function __construct(bool $enabled = true, int $ttl = 300)
    {
        $this->enabled = $enabled;
        $this->ttl = $ttl;
    }

    /**
     * Get cached value
     * 
     * @param string $key Cache key
     * @return array|null Cached data or null if not found/expired
     */
    public function get(string $key): ?array
    {
        if (!$this->enabled || !isset($this->cache[$key])) {
            return null;
        }

        $cached = $this->cache[$key];
        if ($cached['expires_at'] < time()) {
            unset($this->cache[$key]);
            return null;
        }

        return $cached['data'];
    }

    /**
     * Set cache value
     * 
     * @param string $key Cache key
     * @param array $data Data to cache
     * @param int|null $ttl Optional custom TTL
     */
    public function set(string $key, array $data, ?int $ttl = null): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->cache[$key] = [
            'data' => $data,
            'expires_at' => time() + ($ttl ?? $this->ttl)
        ];
    }

    /**
     * Clear specific cache key
     * 
     * @param string $key Cache key
     */
    public function delete(string $key): void
    {
        unset($this->cache[$key]);
    }

    /**
     * Clear all cache
     */
    public function clear(): void
    {
        $this->cache = [];
    }

    /**
     * Clear cache keys matching pattern
     * 
     * @param string $pattern Key pattern (e.g., 'license:*')
     */
    public function clearByPattern(string $pattern): void
    {
        $pattern = str_replace('*', '.*', preg_quote($pattern, '/'));
        
        foreach (array_keys($this->cache) as $key) {
            if (preg_match('/^' . $pattern . '$/', $key)) {
                unset($this->cache[$key]);
            }
        }
    }

    /**
     * Generate cache key from parts
     * 
     * @param string ...$parts Key parts
     * @return string Cache key
     */
    public function generateKey(string ...$parts): string
    {
        return implode(':', $parts);
    }

    /**
     * Check if caching is enabled
     * 
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
