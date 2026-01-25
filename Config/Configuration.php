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
        $this->timeout = $config['timeout'] ?? self::DEFAULT_TIMEOUT;
        $this->verifySignatures = $config['verifySignatures'] ?? true;
        $this->publicKey = $config['publicKey'] ?? null;
        $this->environment = $config['environment'] ?? null;
        $this->cacheEnabled = $config['cacheEnabled'] ?? true;
        $this->cacheTtl = $config['cacheTtl'] ?? self::DEFAULT_CACHE_TTL;
        $this->retryAttempts = $config['retryAttempts'] ?? self::MAX_RETRY_ATTEMPTS;
        $this->retryDelay = $config['retryDelay'] ?? self::RETRY_DELAY_MS;
        $this->productId = $config['productId'] ?? null;
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
