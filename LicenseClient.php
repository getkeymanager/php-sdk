<?php

declare(strict_types=1);

namespace LicenseManager\SDK;

use Exception;
use RuntimeException;
use InvalidArgumentException;

/**
 * License Management Platform - PHP SDK Client
 * 
 * Official PHP client for license validation, activation, and management.
 * 
 * @package LicenseManager\SDK
 * @version 2.0.0
 * @license MIT
 */
class LicenseClient
{
    private const VERSION = '2.0.0';
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
    private array $cache = [];
    private ?SignatureVerifier $signatureVerifier = null;

    /**
     * Initialize License Client
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

        if ($this->verifySignatures && $this->publicKey) {
            $this->signatureVerifier = new SignatureVerifier($this->publicKey);
        }
    }

    /**
     * Validate a license key online
     * 
     * @param string $licenseKey License key to validate
     * @param array $options Optional parameters (hardwareId, domain, productId)
     * @return array Validation result
     * @throws LicenseException
     */
    public function validateLicense(string $licenseKey, array $options = []): array
    {
        $this->validateLicenseKey($licenseKey);

        $cacheKey = $this->getCacheKey('license', $licenseKey, 'validation');
        if ($cached = $this->getFromCache($cacheKey)) {
            return $cached;
        }

        $payload = array_merge(['license_key' => $licenseKey], $options);
        
        $response = $this->request('POST', '/api/v1/licenses/validate', $payload);

        if ($this->verifySignatures && isset($response['signature'])) {
            $this->verifyResponse($response);
        }

        $this->setCache($cacheKey, $response);

        return $response;
    }

    /**
     * Activate a license on a device or domain
     * 
     * @param string $licenseKey License key
     * @param array $options Activation options (hardwareId OR domain required)
     * @return array Activation result
     * @throws LicenseException
     */
    public function activateLicense(string $licenseKey, array $options = []): array
    {
        $this->validateLicenseKey($licenseKey);

        if (!isset($options['hardwareId']) && !isset($options['domain'])) {
            throw new InvalidArgumentException('Either hardwareId or domain is required');
        }

        $payload = array_merge(['license_key' => $licenseKey], $options);

        $idempotencyKey = $options['idempotencyKey'] ?? $this->generateUuid();
        
        $response = $this->request(
            'POST',
            '/api/v1/licenses/activate',
            $payload,
            ['Idempotency-Key' => $idempotencyKey]
        );

        if ($this->verifySignatures && isset($response['signature'])) {
            $this->verifyResponse($response);
        }

        $this->clearLicenseCache($licenseKey);

        return $response;
    }

    /**
     * Deactivate a license from a device or domain
     * 
     * @param string $licenseKey License key
     * @param array $options Deactivation options
     * @return array Deactivation result
     * @throws LicenseException
     */
    public function deactivateLicense(string $licenseKey, array $options = []): array
    {
        $this->validateLicenseKey($licenseKey);

        $payload = array_merge(['license_key' => $licenseKey], $options);

        $idempotencyKey = $options['idempotencyKey'] ?? $this->generateUuid();

        $response = $this->request(
            'POST',
            '/api/v1/licenses/deactivate',
            $payload,
            ['Idempotency-Key' => $idempotencyKey]
        );

        if ($this->verifySignatures && isset($response['signature'])) {
            $this->verifyResponse($response);
        }

        $this->clearLicenseCache($licenseKey);

        return $response;
    }

    /**
     * Check if a feature is enabled for a license
     * 
     * @param string $licenseKey License key
     * @param string $featureName Feature name
     * @return array Feature check result
     * @throws LicenseException
     */
    public function checkFeature(string $licenseKey, string $featureName): array
    {
        $this->validateLicenseKey($licenseKey);

        if (empty($featureName)) {
            throw new InvalidArgumentException('Feature name cannot be empty');
        }

        $cacheKey = $this->getCacheKey('license', $licenseKey, 'feature', $featureName);
        if ($cached = $this->getFromCache($cacheKey)) {
            return $cached;
        }

        $response = $this->request(
            'GET',
            "/api/v1/licenses/{$licenseKey}/features/{$featureName}"
        );

        if ($this->verifySignatures && isset($response['signature'])) {
            $this->verifyResponse($response);
        }

        $this->setCache($cacheKey, $response);

        return $response;
    }

    /**
     * Validate an offline license file
     * 
     * @param string|array $offlineLicenseData JSON string or parsed array
     * @param array $options Validation options (hardwareId, publicKey)
     * @return array Validation result
     * @throws LicenseException
     */
    public function validateOfflineLicense($offlineLicenseData, array $options = []): array
    {
        if (is_string($offlineLicenseData)) {
            $data = json_decode($offlineLicenseData, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new ValidationException('Invalid JSON: ' . json_last_error_msg());
            }
        } else {
            $data = $offlineLicenseData;
        }

        $errors = [];

        if (!isset($data['license'], $data['signature'])) {
            throw new ValidationException('Invalid offline license format');
        }

        $publicKey = $options['publicKey'] ?? $this->publicKey;
        if (!$publicKey) {
            throw new InvalidArgumentException('Public key is required for offline validation');
        }

        $verifier = new SignatureVerifier($publicKey);
        $signature = $data['signature'];
        unset($data['signature']);

        try {
            $payload = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!$verifier->verify($payload, $signature)) {
                $errors[] = 'Signature verification failed';
            }
        } catch (Exception $e) {
            $errors[] = 'Signature verification error: ' . $e->getMessage();
        }

        if (isset($data['license']['expires_at']) && $data['license']['expires_at']) {
            $expiresAt = strtotime($data['license']['expires_at']);
            $now = time();
            $tolerance = 24 * 3600; // 24 hours

            if ($now - $tolerance > $expiresAt) {
                $errors[] = 'License has expired';
            }
        }

        if (isset($options['hardwareId']) && isset($data['license']['hardware_id'])) {
            if ($options['hardwareId'] !== $data['license']['hardware_id']) {
                $errors[] = 'Hardware ID mismatch';
            }
        }

        return [
            'valid' => empty($errors),
            'license' => $data['license'] ?? [],
            'errors' => $errors
        ];
    }

    /**
     * Send telemetry data
     * 
     * @param string $dataType Data type: numeric-single-value, numeric-xy-axis, or text
     * @param string $dataGroup Data group/category
     * @param array $dataValues Data values based on type
     * @param array $options Optional parameters (license_key, activation_identifier, user_identifier, product_id, product_version)
     * @return array Result
     */
    public function sendTelemetry(
        string $dataType,
        string $dataGroup,
        array $dataValues = [],
        array $options = []
    ): array {
        try {
            // Validate data type
            $validDataTypes = ['numeric-single-value', 'numeric-xy-axis', 'text'];
            if (!in_array($dataType, $validDataTypes)) {
                throw new InvalidArgumentException("Invalid data_type. Must be one of: " . implode(', ', $validDataTypes));
            }

            // Build payload based on data type
            $data = [
                'data_type' => $dataType,
                'data_group' => $dataGroup,
            ];

            // Add conditional data fields based on type
            switch ($dataType) {
                case 'numeric-single-value':
                    if (!isset($dataValues['value'])) {
                        throw new InvalidArgumentException("numeric_data_single_value is required for numeric-single-value type");
                    }
                    $data['numeric_data_single_value'] = $dataValues['value'];
                    break;
                case 'numeric-xy-axis':
                    if (!isset($dataValues['x']) || !isset($dataValues['y'])) {
                        throw new InvalidArgumentException("numeric_data_x and numeric_data_y are required for numeric-xy-axis type");
                    }
                    $data['numeric_data_x'] = $dataValues['x'];
                    $data['numeric_data_y'] = $dataValues['y'];
                    break;
                case 'text':
                    if (!isset($dataValues['text'])) {
                        throw new InvalidArgumentException("text_data is required for text type");
                    }
                    $data['text_data'] = $dataValues['text'];
                    break;
            }

            // Add optional context fields
            if (!empty($options['license_key'])) {
                $data['license_key'] = $options['license_key'];
            }
            if (!empty($options['activation_identifier'])) {
                $data['activation_identifier'] = $options['activation_identifier'];
            }
            if (!empty($options['user_identifier'])) {
                $data['user_identifier'] = $options['user_identifier'];
            }
            if (!empty($options['product_id'])) {
                $data['product_id'] = $options['product_id'];
            }
            if (!empty($options['product_version'])) {
                $data['product_version'] = $options['product_version'];
            }

            $response = $this->request('POST', '/api/v1/send-telemetry-data', $data);

            return [
                'success' => true,
                'telemetry_id' => $response['telemetry_id'] ?? null,
                'is_flagged' => $response['is_flagged'] ?? false,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Generate hardware ID for current system
     * 
     * @return string Hardware ID
     */
    public function generateHardwareId(): string
    {
        $identifiers = [];

        if (function_exists('php_uname')) {
            $identifiers[] = php_uname('n'); // Hostname
            $identifiers[] = php_uname('m'); // Machine type
        }

        if (PHP_OS_FAMILY === 'Windows') {
            exec('wmic csproduct get uuid 2>&1', $output);
            if (isset($output[1])) {
                $identifiers[] = trim($output[1]);
            }
        } elseif (PHP_OS_FAMILY === 'Linux') {
            if (file_exists('/etc/machine-id')) {
                $identifiers[] = trim(file_get_contents('/etc/machine-id'));
            }
            if (file_exists('/var/lib/dbus/machine-id')) {
                $identifiers[] = trim(file_get_contents('/var/lib/dbus/machine-id'));
            }
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            exec('ioreg -rd1 -c IOPlatformExpertDevice 2>&1', $output);
            foreach ($output as $line) {
                if (strpos($line, 'IOPlatformUUID') !== false) {
                    preg_match('/"([^"]+)"$/', $line, $matches);
                    if (isset($matches[1])) {
                        $identifiers[] = $matches[1];
                    }
                }
            }
        }

        if (empty($identifiers)) {
            $identifiers[] = gethostname();
            $identifiers[] = PHP_OS;
        }

        sort($identifiers);
        $combined = implode('|', $identifiers);
        
        return substr(hash('sha256', $combined), 0, 32);
    }

    // ========================================================================
    // LICENSE MANAGEMENT METHODS
    // ========================================================================

    /**
     * Create license keys
     * 
     * @param string $productUuid Product UUID
     * @param string $generatorUuid Generator UUID
     * @param array $licenses Array of license data
     * @param string|null $customerEmail Optional customer email
     * @param array $options Additional options (idempotencyKey)
     * @return array Created licenses
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

        $response = $this->request(
            'POST',
            '/api/v1/create-license-keys',
            $payload,
            ['Idempotency-Key' => $idempotencyKey]
        );

        if ($this->verifySignatures && isset($response['signature'])) {
            $this->verifyResponse($response);
        }

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

        $response = $this->request('POST', '/api/v1/update-license-key', $payload);

        if ($this->verifySignatures && isset($response['signature'])) {
            $this->verifyResponse($response);
        }

        $this->clearLicenseCache($licenseKey);

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

        $response = $this->request('POST', '/api/v1/delete-license-key', [
            'license_key' => $licenseKey
        ]);

        if ($this->verifySignatures && isset($response['signature'])) {
            $this->verifyResponse($response);
        }

        $this->clearLicenseCache($licenseKey);

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

        $response = $this->request('GET', $endpoint);

        if ($this->verifySignatures && isset($response['signature'])) {
            $this->verifyResponse($response);
        }

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

        $cacheKey = $this->getCacheKey('license', $licenseKey, 'details');
        if ($cached = $this->getFromCache($cacheKey)) {
            return $cached;
        }

        $response = $this->request('POST', '/api/v1/get-license-key-details', [
            'license_key' => $licenseKey
        ]);

        if ($this->verifySignatures && isset($response['signature'])) {
            $this->verifyResponse($response);
        }

        $this->setCache($cacheKey, $response);

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

        $response = $this->request('GET', '/api/v1/get-available-license-keys-count?' . http_build_query([
            'product_uuid' => $productUuid
        ]));

        if ($this->verifySignatures && isset($response['signature'])) {
            $this->verifyResponse($response);
        }

        return $response;
    }

    // ========================================================================
    // LICENSE ASSIGNMENT METHODS
    // ========================================================================

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

        $response = $this->request('POST', '/api/v1/assign-license-key', $payload);

        if ($this->verifySignatures && isset($response['signature'])) {
            $this->verifyResponse($response);
        }

        $this->clearLicenseCache($licenseKey);

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

        $response = $this->request(
            'POST',
            '/api/v1/random-assign-license-keys',
            $payload,
            ['Idempotency-Key' => $idempotencyKey]
        );

        if ($this->verifySignatures && isset($response['signature'])) {
            $this->verifyResponse($response);
        }

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

        $response = $this->request(
            'POST',
            '/api/v1/random-assign-license-keys-queued',
            $payload,
            ['Idempotency-Key' => $idempotencyKey]
        );

        if ($this->verifySignatures && isset($response['signature'])) {
            $this->verifyResponse($response);
        }

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

        $response = $this->request(
            'POST',
            '/api/v1/assign-and-activate-license-key',
            $payload,
            ['Idempotency-Key' => $idempotencyKey]
        );

        if ($this->verifySignatures && isset($response['signature'])) {
            $this->verifyResponse($response);
        }

        $this->clearLicenseCache($licenseKey);

        return $response;
    }

    // ========================================================================
    // LICENSE METADATA METHODS
    // ========================================================================

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

        $response = $this->request('POST', '/api/v1/create-license-key-meta', [
            'license_key' => $licenseKey,
            'meta_key' => $metaKey,
            'meta_value' => $metaValue
        ]);

        if ($this->verifySignatures && isset($response['signature'])) {
            $this->verifyResponse($response);
        }

        $this->clearLicenseCache($licenseKey);

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

        $response = $this->request('POST', '/api/v1/update-license-key-meta', [
            'license_key' => $licenseKey,
            'meta_key' => $metaKey,
            'meta_value' => $metaValue
        ]);

        if ($this->verifySignatures && isset($response['signature'])) {
            $this->verifyResponse($response);
        }

        $this->clearLicenseCache($licenseKey);

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

        $response = $this->request('POST', '/api/v1/delete-license-key-meta', [
            'license_key' => $licenseKey,
            'meta_key' => $metaKey
        ]);

        if ($this->verifySignatures && isset($response['signature'])) {
            $this->verifyResponse($response);
        }

        $this->clearLicenseCache($licenseKey);

        return $response;
    }

    // ========================================================================
    // PRODUCT MANAGEMENT METHODS
    // ========================================================================

    /**
     * Create product
     * 
     * @param string $name Product name
     * @param array $options Optional parameters (slug, description, status, idempotencyKey)
     * @return array Created product
     * @throws LicenseException
     */
    public function createProduct(string $name, array $options = []): array
    {
        if (empty($name)) {
            throw new InvalidArgumentException('Product name is required');
        }

        $payload = ['name' => $name];

        if (isset($options['slug'])) {
            $payload['slug'] = $options['slug'];
        }

        if (isset($options['description'])) {
            $payload['description'] = $options['description'];
        }

        if (isset($options['status'])) {
            $payload['status'] = $options['status'];
        }

        $idempotencyKey = $options['idempotencyKey'] ?? $this->generateUuid();

        $response = $this->request(
            'POST',
            '/api/v1/create-product',
            $payload,
            ['Idempotency-Key' => $idempotencyKey]
        );

        if ($this->verifySignatures && isset($response['signature'])) {
            $this->verifyResponse($response);
        }

        return $response;
    }

    /**
     * Update product
     * 
     * @param string $productUuid Product UUID
     * @param array $options Update parameters (name, description, status)
     * @return array Update result
     * @throws LicenseException
     */
    public function updateProduct(string $productUuid, array $options = []): array
    {
        if (empty($productUuid)) {
            throw new InvalidArgumentException('Product UUID is required');
        }

        $payload = ['product_uuid' => $productUuid];

        if (isset($options['name'])) {
            $payload['name'] = $options['name'];
        }

        if (isset($options['description'])) {
            $payload['description'] = $options['description'];
        }

        if (isset($options['status'])) {
            $payload['status'] = $options['status'];
        }

        $response = $this->request('POST', '/api/v1/update-product', $payload);

        if ($this->verifySignatures && isset($response['signature'])) {
            $this->verifyResponse($response);
        }

        return $response;
    }

    /**
     * Delete product
     * 
     * @param string $productUuid Product UUID
     * @return array Deletion result
     * @throws LicenseException
     */
    public function deleteProduct(string $productUuid): array
    {
        if (empty($productUuid)) {
            throw new InvalidArgumentException('Product UUID is required');
        }

        $response = $this->request('POST', '/api/v1/delete-product', [
            'product_uuid' => $productUuid
        ]);

        if ($this->verifySignatures && isset($response['signature'])) {
            $this->verifyResponse($response);
        }

        return $response;
    }

    /**
     * Get all products
     * 
     * @return array Products list
     * @throws LicenseException
     */
    public function getAllProducts(): array
    {
        $cacheKey = $this->getCacheKey('products', 'all');
        if ($cached = $this->getFromCache($cacheKey)) {
            return $cached;
        }

        $response = $this->request('GET', '/api/v1/get-all-products');

        if ($this->verifySignatures && isset($response['signature'])) {
            $this->verifyResponse($response);
        }

        $this->setCache($cacheKey, $response);

        return $response;
    }

    // ========================================================================
    // PRODUCT METADATA METHODS
    // ========================================================================

    /**
     * Create product metadata
     * 
     * @param string $productUuid Product UUID
     * @param string $metaKey Metadata key
     * @param mixed $metaValue Metadata value
     * @return array Creation result
     * @throws LicenseException
     */
    public function createProductMeta(string $productUuid, string $metaKey, $metaValue): array
    {
        if (empty($productUuid)) {
            throw new InvalidArgumentException('Product UUID is required');
        }

        if (empty($metaKey)) {
            throw new InvalidArgumentException('Metadata key cannot be empty');
        }

        $response = $this->request('POST', '/api/v1/create-product-meta', [
            'product_uuid' => $productUuid,
            'meta_key' => $metaKey,
            'meta_value' => $metaValue
        ]);

        if ($this->verifySignatures && isset($response['signature'])) {
            $this->verifyResponse($response);
        }

        return $response;
    }

    /**
     * Update product metadata
     * 
     * @param string $productUuid Product UUID
     * @param string $metaKey Metadata key
     * @param mixed $metaValue Metadata value
     * @return array Update result
     * @throws LicenseException
     */
    public function updateProductMeta(string $productUuid, string $metaKey, $metaValue): array
    {
        if (empty($productUuid)) {
            throw new InvalidArgumentException('Product UUID is required');
        }

        if (empty($metaKey)) {
            throw new InvalidArgumentException('Metadata key cannot be empty');
        }

        $response = $this->request('POST', '/api/v1/update-product-meta', [
            'product_uuid' => $productUuid,
            'meta_key' => $metaKey,
            'meta_value' => $metaValue
        ]);

        if ($this->verifySignatures && isset($response['signature'])) {
            $this->verifyResponse($response);
        }

        return $response;
    }

    /**
     * Delete product metadata
     * 
     * @param string $productUuid Product UUID
     * @param string $metaKey Metadata key
     * @return array Deletion result
     * @throws LicenseException
     */
    public function deleteProductMeta(string $productUuid, string $metaKey): array
    {
        if (empty($productUuid)) {
            throw new InvalidArgumentException('Product UUID is required');
        }

        if (empty($metaKey)) {
            throw new InvalidArgumentException('Metadata key cannot be empty');
        }

        $response = $this->request('POST', '/api/v1/delete-product-meta', [
            'product_uuid' => $productUuid,
            'meta_key' => $metaKey
        ]);

        if ($this->verifySignatures && isset($response['signature'])) {
            $this->verifyResponse($response);
        }

        return $response;
    }

    // ========================================================================
    // GENERATOR METHODS
    // ========================================================================

    /**
     * Get all generators
     * 
     * @param string|null $productUuid Optional product UUID filter
     * @return array Generators list
     * @throws LicenseException
     */
    public function getAllGenerators(?string $productUuid = null): array
    {
        $cacheKey = $this->getCacheKey('generators', $productUuid ?? 'all');
        if ($cached = $this->getFromCache($cacheKey)) {
            return $cached;
        }

        $endpoint = '/api/v1/get-all-generators';
        if ($productUuid) {
            $endpoint .= '?' . http_build_query(['product_uuid' => $productUuid]);
        }

        $response = $this->request('GET', $endpoint);

        if ($this->verifySignatures && isset($response['signature'])) {
            $this->verifyResponse($response);
        }

        $this->setCache($cacheKey, $response);

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

        $response = $this->request(
            'POST',
            '/api/v1/generate',
            $payload,
            ['Idempotency-Key' => $idempotencyKey]
        );

        if ($this->verifySignatures && isset($response['signature'])) {
            $this->verifyResponse($response);
        }

        return $response;
    }

    // ========================================================================
    // CONTRACT METHODS
    // ========================================================================

    /**
     * Get all contracts
     * 
     * @return array Contracts list
     * @throws LicenseException
     */
    public function getAllContracts(): array
    {
        $cacheKey = $this->getCacheKey('contracts', 'all');
        if ($cached = $this->getFromCache($cacheKey)) {
            return $cached;
        }

        $response = $this->request('GET', '/api/v1/get-all-contracts');

        if ($this->verifySignatures && isset($response['signature'])) {
            $this->verifyResponse($response);
        }

        $this->setCache($cacheKey, $response);

        return $response;
    }

    /**
     * Create contract
     * 
     * @param array $contractData Contract data
     * @param array $options Additional options (idempotencyKey)
     * @return array Created contract
     * @throws LicenseException
     */
    public function createContract(array $contractData, array $options = []): array
    {
        $required = [
            'contract_key', 'contract_name', 'contract_information',
            'product_id', 'license_keys_quantity', 'status',
            'can_get_info', 'can_generate', 'can_destroy', 'can_destroy_all'
        ];

        foreach ($required as $field) {
            if (!isset($contractData[$field])) {
                throw new InvalidArgumentException("Field '{$field}' is required");
            }
        }

        $idempotencyKey = $options['idempotencyKey'] ?? $this->generateUuid();

        $response = $this->request(
            'POST',
            '/api/v1/create-contract',
            $contractData,
            ['Idempotency-Key' => $idempotencyKey]
        );

        if ($this->verifySignatures && isset($response['signature'])) {
            $this->verifyResponse($response);
        }

        $this->clearCache();

        return $response;
    }

    /**
     * Update contract
     * 
     * @param int $contractId Contract ID
     * @param array $contractData Contract data
     * @return array Update result
     * @throws LicenseException
     */
    public function updateContract(int $contractId, array $contractData): array
    {
        if ($contractId < 1) {
            throw new InvalidArgumentException('Contract ID must be positive');
        }

        $contractData['contract_id'] = $contractId;

        $response = $this->request('POST', '/api/v1/update-contract', $contractData);

        if ($this->verifySignatures && isset($response['signature'])) {
            $this->verifyResponse($response);
        }

        $this->clearCache();

        return $response;
    }

    /**
     * Delete contract
     * 
     * @param int $contractId Contract ID
     * @return array Deletion result
     * @throws LicenseException
     */
    public function deleteContract(int $contractId): array
    {
        if ($contractId < 1) {
            throw new InvalidArgumentException('Contract ID must be positive');
        }

        $response = $this->request('POST', '/api/v1/delete-contract', [
            'contract_id' => $contractId
        ]);

        if ($this->verifySignatures && isset($response['signature'])) {
            $this->verifyResponse($response);
        }

        $this->clearCache();

        return $response;
    }

    // ========================================================================
    // DOWNLOADABLES METHODS
    // ========================================================================

    /**
     * Access downloadables for a license
     * 
     * @param string $licenseKey License key
     * @param string $identifier Hardware ID or domain
     * @return array Downloadable files with signed URLs
     * @throws LicenseException
     */
    public function accessDownloadables(string $licenseKey, string $identifier): array
    {
        $this->validateLicenseKey($licenseKey);

        if (empty($identifier)) {
            throw new InvalidArgumentException('Identifier is required');
        }

        $response = $this->request('POST', '/api/v1/access-downloadables', [
            'license_key' => $licenseKey,
            'identifier' => $identifier
        ]);

        if ($this->verifySignatures && isset($response['signature'])) {
            $this->verifyResponse($response);
        }

        return $response;
    }

    // ========================================================================
    // TELEMETRY METHODS (EXPANDED)
    // ========================================================================

    /**
     * Get telemetry data
     * 
     * @param string $dataType Data type (numeric-single-value, numeric-xy-axis, text)
     * @param string $dataGroup Data group
     * @param array $filters Optional filters
     * @return array Telemetry data
     * @throws LicenseException
     */
    public function getTelemetryData(string $dataType, string $dataGroup, array $filters = []): array
    {
        if (empty($dataType) || empty($dataGroup)) {
            throw new InvalidArgumentException('Data type and data group are required');
        }

        $queryParams = [
            'data_type' => $dataType,
            'data_group' => $dataGroup
        ];

        if (isset($filters['product_id'])) {
            $queryParams['product_id'] = $filters['product_id'];
        }

        if (isset($filters['user_identifier'])) {
            $queryParams['user_identifier'] = $filters['user_identifier'];
        }

        if (isset($filters['license_key'])) {
            $queryParams['license_key'] = $filters['license_key'];
        }

        if (isset($filters['has_red_flags'])) {
            $queryParams['has_red_flags'] = $filters['has_red_flags'];
        }

        $endpoint = '/api/v1/get-telemetry-data?' . http_build_query($queryParams);

        $response = $this->request('GET', $endpoint);

        if ($this->verifySignatures && isset($response['signature'])) {
            $this->verifyResponse($response);
        }

        return $response;
    }

    // ========================================================================
    // CHANGELOG METHODS (PUBLIC)
    // ========================================================================

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

        $cacheKey = $this->getCacheKey('changelog', $slug);
        if ($cached = $this->getFromCache($cacheKey)) {
            return $cached;
        }

        // This endpoint doesn't require authentication, so we make a simpler request
        $response = $this->requestPublic('GET', "/api/v1/products/{$slug}/changelog");

        $this->setCache($cacheKey, $response);

        return $response;
    }

    /**
     * Make HTTP request to public API (no authentication)
     * 
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @return array Response data
     * @throws LicenseException
     */
    private function requestPublic(string $method, string $endpoint): array
    {
        $url = $this->baseUrl . $endpoint;
        
        $headers = [
            'Accept: application/json',
            'User-Agent: LicenseManager-PHP-SDK/' . self::VERSION
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new NetworkException("Network error: {$error}");
        }

        $responseData = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Invalid JSON response');
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return $responseData;
        }

        throw new LicenseException('Failed to fetch public data', $httpCode);
    }

    /**
     * Clear all cache
     * 
     * @return void
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }

    /**
     * Clear cache for specific license
     * 
     * @param string $licenseKey License key
     * @return void
     */
    public function clearLicenseCache(string $licenseKey): void
    {
        $prefix = "license:{$licenseKey}:";
        foreach (array_keys($this->cache) as $key) {
            if (strpos($key, $prefix) === 0) {
                unset($this->cache[$key]);
            }
        }
    }

    /**
     * Make HTTP request to API
     * 
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array|null $data Request data
     * @param array $extraHeaders Additional headers
     * @return array Response data
     * @throws LicenseException
     */
    private function request(
        string $method,
        string $endpoint,
        ?array $data = null,
        array $extraHeaders = []
    ): array {
        $url = $this->baseUrl . $endpoint;
        
        $headers = array_merge([
            'X-API-Key: ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: LicenseManager-PHP-SDK/' . self::VERSION
        ], array_map(function($k, $v) { return "$k: $v"; }, array_keys($extraHeaders), $extraHeaders));

        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->retryAttempts) {
            try {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => $this->timeout,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_CUSTOMREQUEST => $method,
                ]);

                if ($data !== null && in_array($method, ['POST', 'PUT', 'PATCH'])) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                curl_close($ch);

                if ($response === false) {
                    throw new NetworkException("Network error: {$error}");
                }

                $responseData = json_decode($response, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new RuntimeException('Invalid JSON response');
                }

                if ($httpCode >= 200 && $httpCode < 300) {
                    return $responseData['data'] ?? $responseData;
                }

                if ($httpCode === 429) {
                    $retryAfter = $responseData['retry_after'] ?? $this->retryDelay;
                    usleep($retryAfter * 1000);
                    $attempt++;
                    continue;
                }

                $this->handleErrorResponse($httpCode, $responseData);
            } catch (NetworkException $e) {
                $lastException = $e;
                $attempt++;
                if ($attempt < $this->retryAttempts) {
                    usleep($this->retryDelay * 1000 * $attempt);
                }
            }
        }

        throw $lastException ?? new NetworkException('Request failed after retries');
    }

    /**
     * Handle error response from API
     * 
     * @param int $httpCode HTTP status code
     * @param array $data Response data
     * @throws LicenseException
     */
    private function handleErrorResponse(int $httpCode, array $data): void
    {
        $error = $data['error'] ?? [];
        $code = $error['code'] ?? 'UNKNOWN_ERROR';
        $message = $error['message'] ?? 'An error occurred';

        switch ($code) {
            case 'INVALID_API_KEY':
                throw new ValidationException($message, $httpCode);
            case 'RATE_LIMIT_EXCEEDED':
                throw new RateLimitException($message, $httpCode);
            case 'LICENSE_EXPIRED':
                throw new ExpiredException($message, $httpCode);
            case 'LICENSE_SUSPENDED':
                throw new SuspendedException($message, $httpCode);
            case 'LICENSE_REVOKED':
                throw new RevokedException($message, $httpCode);
            case 'SIGNATURE_VERIFICATION_FAILED':
                throw new SignatureException($message, $httpCode);
            default:
                throw new LicenseException($message, $httpCode);
        }
    }

    /**
     * Verify response signature
     * 
     * @param array $response Response data
     * @throws SignatureException
     */
    private function verifyResponse(array $response): void
    {
        if (!$this->signatureVerifier) {
            throw new SignatureException('Signature verifier not initialized');
        }

        $signature = $response['signature'];
        unset($response['signature']);

        $payload = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        if (!$this->signatureVerifier->verify($payload, $signature)) {
            throw new SignatureException('Signature verification failed');
        }
    }

    /**
     * Get cache key
     * 
     * @param string ...$parts Key parts
     * @return string Cache key
     */
    private function getCacheKey(string ...$parts): string
    {
        return implode(':', $parts);
    }

    /**
     * Get value from cache
     * 
     * @param string $key Cache key
     * @return array|null Cached value or null
     */
    private function getFromCache(string $key): ?array
    {
        if (!$this->cacheEnabled || !isset($this->cache[$key])) {
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
     * @return void
     */
    private function setCache(string $key, array $data): void
    {
        if (!$this->cacheEnabled) {
            return;
        }

        $this->cache[$key] = [
            'data' => $data,
            'expires_at' => time() + $this->cacheTtl
        ];
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

class LicenseException extends Exception
{
}

class ValidationException extends LicenseException
{
}

class NetworkException extends LicenseException
{
}

class SignatureException extends LicenseException
{
}

class RateLimitException extends LicenseException
{
}

class ExpiredException extends LicenseException
{
}

class SuspendedException extends LicenseException
{
}

class RevokedException extends LicenseException
{
}
