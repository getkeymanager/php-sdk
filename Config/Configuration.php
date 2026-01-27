<?php

declare(strict_types=1);

namespace GetKeyManager\SDK\Config;

use InvalidArgumentException;

/**
 * Configuration Management
 * 
 * Handles validation and storage of SDK configuration.
 * 
 * @package GetKeyManager\SDK\Config
 */
class Configuration
{
    private const DEFAULT_TIMEOUT = 30;
    private const DEFAULT_CACHE_TTL = 300;
    private const MAX_RETRY_ATTEMPTS = 3;
    private const RETRY_DELAY_MS = 1000;

    private string $apiKey;
    private string $baseUrl;
    private int $timeout;
    private bool $verifySignatures;
    private ?string $publicKey;
    private ?string $publicKeyFile;
    private ?string $environment;
    private bool $cacheEnabled;
    private int $cacheTtl;
    private int $retryAttempts;
    private int $retryDelay;
    private ?string $productId;

    /**
     * Create configuration from array
     * 
     * @param array $config Configuration options
     * @throws InvalidArgumentException
     */
    public function __construct(array $config)
    {
        $this->validateConfig($config);

        $this->apiKey = $config['apiKey'];
        $this->baseUrl = $config['baseUrl'] ?? 'https://api.getkeymanager.com';
        
        // Cast to int and ensure positive values
        $timeout = (int)($config['timeout'] ?? self::DEFAULT_TIMEOUT);
        $this->timeout = max(1, $timeout); // Minimum 1 second
        
        $this->verifySignatures = filter_var($config['verifySignatures'] ?? true, FILTER_VALIDATE_BOOLEAN);
        
        // Load public key from file or use direct value
        $this->publicKeyFile = $config['publicKeyFile'] ?? null;
        $this->publicKey = $this->loadPublicKey();
        
        $this->environment = $config['environment'] ?? null;
        $this->cacheEnabled = filter_var($config['cacheEnabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
        
        $cacheTtl = (int)($config['cacheTtl'] ?? self::DEFAULT_CACHE_TTL);
        $this->cacheTtl = max(0, $cacheTtl); // Can be 0 (disabled)
        
        $retryAttempts = (int)($config['retryAttempts'] ?? self::MAX_RETRY_ATTEMPTS);
        $this->retryAttempts = max(0, $retryAttempts); // Can be 0 (no retries)
        
        $retryDelay = (int)($config['retryDelay'] ?? self::RETRY_DELAY_MS);
        $this->retryDelay = max(0, $retryDelay); // Can be 0 (no delay)
        
        $this->productId = $config['productId'] ?? null;
    }

    /**
     * Load public key from file or configuration
     * 
     * @return string|null Public key content or null
     * @throws InvalidArgumentException If public key file cannot be read
     */
    private function loadPublicKey(): ?string
    {
        // Try to load from file first (preferred method)
        if (!empty($this->publicKeyFile)) {
            $filePath = $this->resolvePath($this->publicKeyFile);
            
            if (!file_exists($filePath)) {
                throw new InvalidArgumentException("Public key file not found: {$this->publicKeyFile}");
            }
            
            if (!is_readable($filePath)) {
                throw new InvalidArgumentException("Public key file is not readable: {$this->publicKeyFile}");
            }
            
            $content = file_get_contents($filePath);
            if ($content === false) {
                throw new InvalidArgumentException("Cannot read public key file: {$this->publicKeyFile}");
            }
            
            return trim($content);
        }
        
        // Fall back to publicKey from config (deprecated)
        return null;
    }

    /**
     * Resolve file path with support for common patterns
     * 
     * @param string $path File path (can be absolute or relative)
     * @return string Resolved absolute path
     */
    private function resolvePath(string $path): string
    {
        // If path starts with /, it's already absolute
        if (strpos($path, '/') === 0) {
            return $path;
        }
        
        // If path starts with ~, expand home directory
        if (strpos($path, '~') === 0) {
            $home = getenv('HOME') ?: (isset($_SERVER['USERPROFILE']) ? $_SERVER['USERPROFILE'] : null);
            if ($home) {
                return $home . substr($path, 1);
            }
        }
        
        // Relative paths - try common PHP application roots
        $baseDir = $this->findApplicationRoot();
        return $baseDir . DIRECTORY_SEPARATOR . $path;
    }

    /**
     * Find the application root directory
     * 
     * @return string Application root directory
     */
    private function findApplicationRoot(): string
    {
        // Try to find common application root indicators
        $cwd = getcwd();
        
        // Check if we're in a Laravel application
        if (file_exists($cwd . '/bootstrap') && file_exists($cwd . '/artisan')) {
            return $cwd;
        }
        
        // Check if we're in a CodeIgniter application
        if (file_exists($cwd . '/app') && file_exists($cwd . '/public')) {
            return $cwd;
        }
        
        // Default to current working directory
        return $cwd;
    }

    /**
     * Validate configuration
     * 
     * @param array $config Configuration
     * @throws InvalidArgumentException
     */
    private function validateConfig(array $config): void
    {
        if (empty($config['apiKey'])) {
            throw new InvalidArgumentException('API key is required');
        }
    }

    // Getters
    public function getApiKey(): string { return $this->apiKey; }
    public function getBaseUrl(): string { return $this->baseUrl; }
    public function getTimeout(): int { return $this->timeout; }
    public function shouldVerifySignatures(): bool { return $this->verifySignatures; }
    public function getPublicKey(): ?string { return $this->publicKey; }
    public function getEnvironment(): ?string { return $this->environment; }
    public function isCacheEnabled(): bool { return $this->cacheEnabled; }
    public function getCacheTtl(): int { return $this->cacheTtl; }
    public function getRetryAttempts(): int { return $this->retryAttempts; }
    public function getRetryDelay(): int { return $this->retryDelay; }
    public function getProductId(): ?string { return $this->productId; }
}
