<?php

declare(strict_types=1);

namespace GetKeyManager\SDK\Management;

use GetKeyManager\SDK\Config\Configuration;
use GetKeyManager\SDK\Http\HttpClient;
use GetKeyManager\SDK\Cache\CacheManager;
use GetKeyManager\SDK\LicenseException;
use InvalidArgumentException;

/**
 * License Manager
 * 
 * Handles CRUD operations for licenses, assignments, and metadata.
 * 
 * @package GetKeyManager\SDK\Management
 */
class LicenseManager
{
    private Configuration $config;
    private HttpClient $httpClient;
    private CacheManager $cacheManager;

    /**
     * Initialize license manager
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
     * Create license keys
     * 
     * @param string $productUuid Product UUID
     * @param string $generatorUuid Generator UUID
     * @param array $licenses License data array
     * @param string|null $customerEmail Optional customer email
     * @param array $options Additional options (idempotencyKey)
     * @return array Creation result
     * @throws LicenseException
     */
    public function createLicenseKeys(
        string $productUuid,
        string $generatorUuid,
        array $licenses,
        ?string $customerEmail = null,
        array $options = []
    ): array {
        if (empty($productUuid) || empty($generatorUuid)) {
            throw new InvalidArgumentException('Product UUID and Generator UUID are required');
        }

        if (empty($licenses)) {
            throw new InvalidArgumentException('Licenses array cannot be empty');
        }

        $payload = [
            'product_uuid' => $productUuid,
            'generator_uuid' => $generatorUuid,
            'licenses' => $licenses
        ];

        if ($customerEmail) {
            $payload['customer_email'] = $customerEmail;
        }

        $idempotencyKey = $options['idempotencyKey'] ?? $this->generateUuid();

        $response = $this->httpClient->request(
            'POST',
            '/api/v1/create-license-keys',
            $payload,
            ['Idempotency-Key' => $idempotencyKey]
        );

        return $response;
    }

    /**
     * Update license key
     * 
     * @param string $licenseKey License key
     * @param array $options Update options (status, activation_limit, validity_days)
     * @return array Update result
     * @throws LicenseException
     */
    public function updateLicenseKey(string $licenseKey, array $options = []): array
    {
        $this->validateLicenseKey($licenseKey);

        $payload = ['license_key' => $licenseKey];

        if (isset($options['status'])) {
            $payload['status'] = $options['status'];
        }

        if (isset($options['activation_limit'])) {
            $payload['activation_limit'] = $options['activation_limit'];
        }

        if (isset($options['validity_days'])) {
            $payload['validity_days'] = $options['validity_days'];
        }

        $response = $this->httpClient->request('POST', '/api/v1/update-license-key', $payload);

        $this->cacheManager->clearByPattern("license:{$licenseKey}:*");

        return $response;
    }

    /**
     * Delete license key
     * 
     * @param string $licenseKey License key
     * @return array Deletion result
     * @throws LicenseException
     */
    public function deleteLicenseKey(string $licenseKey): array
    {
        $this->validateLicenseKey($licenseKey);

        $response = $this->httpClient->request('POST', '/api/v1/delete-license-key', [
            'license_key' => $licenseKey
        ]);

        $this->cacheManager->clearByPattern("license:{$licenseKey}:*");

        return $response;
    }

    /**
     * Get license keys
     * 
     * @param array $filters Optional filters (product_uuid, status, customer_email)
     * @return array License keys
     * @throws LicenseException
     */
    public function getLicenseKeys(array $filters = []): array
    {
        $queryParams = [];

        if (isset($filters['product_uuid'])) {
            $queryParams['product_uuid'] = $filters['product_uuid'];
        }

        if (isset($filters['status'])) {
            $queryParams['status'] = $filters['status'];
        }

        if (isset($filters['customer_email'])) {
            $queryParams['customer_email'] = $filters['customer_email'];
        }

        $endpoint = '/api/v1/get-license-keys';
        if (!empty($queryParams)) {
            $endpoint .= '?' . http_build_query($queryParams);
        }

        $response = $this->httpClient->request('GET', $endpoint);

        return $response;
    }

    /**
     * Get license details
     * 
     * @param string $licenseKey License key
     * @return array License details
     * @throws LicenseException
     */
    public function getLicenseDetails(string $licenseKey): array
    {
        $this->validateLicenseKey($licenseKey);

        $cacheKey = $this->cacheManager->generateKey('license', $licenseKey, 'details');
        if ($cached = $this->cacheManager->get($cacheKey)) {
            return $cached;
        }

        $response = $this->httpClient->request('POST', '/api/v1/get-license-key-details', [
            'license_key' => $licenseKey
        ]);

        $this->cacheManager->set($cacheKey, $response);

        return $response;
    }

    /**
     * Get available license keys count
     * 
     * @param string $productUuid Product UUID
     * @return array Count result
     * @throws LicenseException
     */
    public function getAvailableLicenseKeysCount(string $productUuid): array
    {
        if (empty($productUuid)) {
            throw new InvalidArgumentException('Product UUID is required');
        }

        $response = $this->httpClient->request('GET', '/api/v1/get-available-license-keys-count?' . http_build_query([
            'product_uuid' => $productUuid
        ]));

        return $response;
    }

    /**
     * Assign license key to customer
     * 
     * @param string $licenseKey License key
     * @param string $customerEmail Customer email
     * @param string|null $customerName Optional customer name
     * @return array Assignment result
     * @throws LicenseException
     */
    public function assignLicenseKey(
        string $licenseKey,
        string $customerEmail,
        ?string $customerName = null
    ): array {
        $this->validateLicenseKey($licenseKey);

        if (empty($customerEmail)) {
            throw new InvalidArgumentException('Customer email is required');
        }

        $payload = [
            'license_key' => $licenseKey,
            'customer_email' => $customerEmail
        ];

        if ($customerName) {
            $payload['customer_name'] = $customerName;
        }

        $response = $this->httpClient->request('POST', '/api/v1/assign-license-key', $payload);

        $this->cacheManager->clearByPattern("license:{$licenseKey}:*");

        return $response;
    }

    /**
     * Random assign license keys (synchronous)
     * 
     * @param string $productUuid Product UUID
     * @param string $generatorUuid Generator UUID
     * @param int $quantity Number of licenses to assign
     * @param string $customerEmail Customer email
     * @param string|null $customerName Optional customer name
     * @param array $options Additional options (idempotencyKey)
     * @return array Assignment result
     * @throws LicenseException
     */
    public function randomAssignLicenseKeys(
        string $productUuid,
        string $generatorUuid,
        int $quantity,
        string $customerEmail,
        ?string $customerName = null,
        array $options = []
    ): array {
        if (empty($productUuid) || empty($generatorUuid)) {
            throw new InvalidArgumentException('Product UUID and Generator UUID are required');
        }

        if ($quantity < 1) {
            throw new InvalidArgumentException('Quantity must be at least 1');
        }

        if (empty($customerEmail)) {
            throw new InvalidArgumentException('Customer email is required');
        }

        $payload = [
            'product_uuid' => $productUuid,
            'generator_uuid' => $generatorUuid,
            'quantity' => $quantity,
            'customer_email' => $customerEmail
        ];

        if ($customerName) {
            $payload['customer_name'] = $customerName;
        }

        $idempotencyKey = $options['idempotencyKey'] ?? $this->generateUuid();

        $response = $this->httpClient->request(
            'POST',
            '/api/v1/random-assign-license-keys',
            $payload,
            ['Idempotency-Key' => $idempotencyKey]
        );

        return $response;
    }

    /**
     * Random assign license keys (queued/asynchronous)
     * 
     * @param string $productUuid Product UUID
     * @param string $generatorUuid Generator UUID
     * @param int $quantity Number of licenses to assign
     * @param string $customerEmail Customer email
     * @param string|null $customerName Optional customer name
     * @param array $options Additional options (idempotencyKey)
     * @return array Job queued result
     * @throws LicenseException
     */
    public function randomAssignLicenseKeysQueued(
        string $productUuid,
        string $generatorUuid,
        int $quantity,
        string $customerEmail,
        ?string $customerName = null,
        array $options = []
    ): array {
        if (empty($productUuid) || empty($generatorUuid)) {
            throw new InvalidArgumentException('Product UUID and Generator UUID are required');
        }

        if ($quantity < 1) {
            throw new InvalidArgumentException('Quantity must be at least 1');
        }

        if (empty($customerEmail)) {
            throw new InvalidArgumentException('Customer email is required');
        }

        $payload = [
            'product_uuid' => $productUuid,
            'generator_uuid' => $generatorUuid,
            'quantity' => $quantity,
            'customer_email' => $customerEmail
        ];

        if ($customerName) {
            $payload['customer_name'] = $customerName;
        }

        $idempotencyKey = $options['idempotencyKey'] ?? $this->generateUuid();

        $response = $this->httpClient->request(
            'POST',
            '/api/v1/random-assign-license-keys-queued',
            $payload,
            ['Idempotency-Key' => $idempotencyKey]
        );

        return $response;
    }

    /**
     * Assign and activate license key
     * 
     * @param string $licenseKey License key
     * @param string $customerEmail Customer email
     * @param string $identifier Hardware ID or domain
     * @param array $options Additional options (idempotencyKey)
     * @return array Assignment and activation result
     * @throws LicenseException
     */
    public function assignAndActivateLicenseKey(
        string $licenseKey,
        string $customerEmail,
        string $identifier,
        array $options = []
    ): array {
        $this->validateLicenseKey($licenseKey);

        if (empty($customerEmail)) {
            throw new InvalidArgumentException('Customer email is required');
        }

        if (empty($identifier)) {
            throw new InvalidArgumentException('Identifier is required');
        }

        $payload = [
            'license_key' => $licenseKey,
            'customer_email' => $customerEmail,
            'identifier' => $identifier
        ];

        $idempotencyKey = $options['idempotencyKey'] ?? $this->generateUuid();

        $response = $this->httpClient->request(
            'POST',
            '/api/v1/assign-and-activate-license-key',
            $payload,
            ['Idempotency-Key' => $idempotencyKey]
        );

        $this->cacheManager->clearByPattern("license:{$licenseKey}:*");

        return $response;
    }

    /**
     * Create license key metadata
     * 
     * @param string $licenseKey License key
     * @param string $metaKey Metadata key
     * @param mixed $metaValue Metadata value
     * @return array Creation result
     * @throws LicenseException
     */
    public function createLicenseKeyMeta(string $licenseKey, string $metaKey, $metaValue): array
    {
        $this->validateLicenseKey($licenseKey);

        if (empty($metaKey)) {
            throw new InvalidArgumentException('Metadata key cannot be empty');
        }

        $response = $this->httpClient->request('POST', '/api/v1/create-license-key-meta', [
            'license_key' => $licenseKey,
            'meta_key' => $metaKey,
            'meta_value' => $metaValue
        ]);

        $this->cacheManager->clearByPattern("license:{$licenseKey}:*");

        return $response;
    }

    /**
     * Update license key metadata
     * 
     * @param string $licenseKey License key
     * @param string $metaKey Metadata key
     * @param mixed $metaValue Metadata value
     * @return array Update result
     * @throws LicenseException
     */
    public function updateLicenseKeyMeta(string $licenseKey, string $metaKey, $metaValue): array
    {
        $this->validateLicenseKey($licenseKey);

        if (empty($metaKey)) {
            throw new InvalidArgumentException('Metadata key cannot be empty');
        }

        $response = $this->httpClient->request('POST', '/api/v1/update-license-key-meta', [
            'license_key' => $licenseKey,
            'meta_key' => $metaKey,
            'meta_value' => $metaValue
        ]);

        $this->cacheManager->clearByPattern("license:{$licenseKey}:*");

        return $response;
    }

    /**
     * Delete license key metadata
     * 
     * @param string $licenseKey License key
     * @param string $metaKey Metadata key
     * @return array Deletion result
     * @throws LicenseException
     */
    public function deleteLicenseKeyMeta(string $licenseKey, string $metaKey): array
    {
        $this->validateLicenseKey($licenseKey);

        if (empty($metaKey)) {
            throw new InvalidArgumentException('Metadata key cannot be empty');
        }

        $response = $this->httpClient->request('POST', '/api/v1/delete-license-key-meta', [
            'license_key' => $licenseKey,
            'meta_key' => $metaKey
        ]);

        $this->cacheManager->clearByPattern("license:{$licenseKey}:*");

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
