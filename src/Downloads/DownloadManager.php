<?php

declare(strict_types=1);

namespace GetKeyManager\SDK\Downloads;

use GetKeyManager\SDK\Config\Configuration;
use GetKeyManager\SDK\Http\HttpClient;
use GetKeyManager\SDK\Cache\CacheManager;
use GetKeyManager\SDK\Exceptions\LicenseException;
use InvalidArgumentException;

/**
 * Download Manager
 * 
 * Handles downloadables, changelogs, and generator operations.
 * 
 * @package GetKeyManager\SDK\Downloads
 */
class DownloadManager
{
    private Configuration $config;
    private HttpClient $httpClient;
    private CacheManager $cacheManager;

    /**
     * Initialize download manager
     * 
     * @param Configuration $config SDK configuration
     * @param HttpClient $httpClient HTTP client
     * @param CacheManager $cacheManager Cache manager
     */
    public function __construct(
        Configuration $config,
        HttpClient $httpClient,
        CacheManager $cacheManager
    ) {
        $this->config = $config;
        $this->httpClient = $httpClient;
        $this->cacheManager = $cacheManager;
    }

    /**
     * Access downloadables for a license
     * 
     * @param string $licenseKey License key
     * @param array $options Additional options (product_uuid, version, identifier)
     * @return array Downloadable files with signed URLs
     * @throws LicenseException
     */
    public function accessDownloadables(string $licenseKey, array $options = []): array
    {
        $this->validateLicenseKey($licenseKey);

        $payload = array_merge(['license_key' => $licenseKey], $options);

        $response = $this->httpClient->request('POST', '/v1/access-downloadables', $payload);

        return $response;
    }

    /**
     * Get product changelog (public endpoint, no auth required)
     * 
     * @param string $slug Product slug
     * @return array Changelog entries
     * @throws LicenseException
     */
    public function getProductChangelog(string $slug): array
    {
        if (empty($slug)) {
            throw new InvalidArgumentException('Product slug is required');
        }

        $cacheKey = $this->cacheManager->generateKey('changelog', $slug);
        if ($cached = $this->cacheManager->get($cacheKey)) {
            return $cached;
        }

        // This endpoint doesn't require authentication, so we make a simpler request
        $response = $this->httpClient->requestPublic('GET', "/v1/products/{$slug}/changelog");

        $this->cacheManager->set($cacheKey, $response);

        return $response;
    }

    /**
     * Get all generators
     * 
     * @param string|null $productUuid Optional product UUID filter
     * @return array Generators list
     * @throws LicenseException
     */
    public function getAllGenerators(?string $productUuid = null): array
    {
        $cacheKey = $this->cacheManager->generateKey('generators', $productUuid ?? 'all');
        if ($cached = $this->cacheManager->get($cacheKey)) {
            return $cached;
        }

        $endpoint = '/v1/get-all-generators';
        if ($productUuid) {
            $endpoint .= '?' . http_build_query(['product_uuid' => $productUuid]);
        }

        $response = $this->httpClient->request('GET', $endpoint);

        $this->cacheManager->set($cacheKey, $response);

        return $response;
    }

    /**
     * Generate license keys
     * 
     * @param string $generatorUuid Generator UUID
     * @param int $quantity Number of licenses to generate
     * @param array $options Optional parameters (activation_limit, validity_days, idempotencyKey)
     * @return array Generated licenses
     * @throws LicenseException
     */
    public function generateLicenseKeys(string $generatorUuid, int $quantity, array $options = []): array
    {
        if (empty($generatorUuid)) {
            throw new InvalidArgumentException('Generator UUID is required');
        }

        if ($quantity < 1) {
            throw new InvalidArgumentException('Quantity must be at least 1');
        }

        $payload = [
            'generator_uuid' => $generatorUuid,
            'quantity' => $quantity
        ];

        if (isset($options['activation_limit'])) {
            $payload['activation_limit'] = $options['activation_limit'];
        }

        if (isset($options['validity_days'])) {
            $payload['validity_days'] = $options['validity_days'];
        }

        $idempotencyKey = $options['idempotencyKey'] ?? $this->generateUuid();

        $response = $this->httpClient->request(
            'POST',
            '/v1/generate',
            $payload,
            ['Idempotency-Key' => $idempotencyKey]
        );

        return $response;
    }

    /**
     * Validate license key format
     * 
     * @param string $licenseKey License key
     * @throws InvalidArgumentException
     */
    private function validateLicenseKey(string $licenseKey): void
    {
        if (empty($licenseKey)) {
            throw new InvalidArgumentException('License key cannot be empty');
        }
    }

    /**
     * Generate UUID v4
     * 
     * @return string UUID
     */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        
        // Set version to 0100 (UUID v4)
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        
        // Set variant to 10xx (RFC 4122)
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
