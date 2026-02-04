<?php

declare(strict_types=1);

namespace GetKeyManager\SDK\Validation;

use GetKeyManager\SDK\Config\Configuration;
use GetKeyManager\SDK\Http\HttpClient;
use GetKeyManager\SDK\Cache\CacheManager;
use GetKeyManager\SDK\StateStore;
use GetKeyManager\SDK\StateResolver;
use GetKeyManager\SDK\SignatureVerifier;
use GetKeyManager\SDK\LicenseState;
use GetKeyManager\SDK\Exceptions\LicenseException;
use GetKeyManager\SDK\Exceptions\ValidationException;
use GetKeyManager\SDK\Exceptions\SignatureException;
use GetKeyManager\SDK\Exceptions\NetworkException;
use GetKeyManager\SDK\Exceptions\StateException;
use InvalidArgumentException;
use Exception;

/**
 * License Validator
 * 
 * Handles license validation, activation, deactivation, and state management.
 * 
 * @package GetKeyManager\SDK\Validation
 */
class LicenseValidator
{
    private Configuration $config;
    private HttpClient $httpClient;
    private CacheManager $cacheManager;
    private StateStore $stateStore;
    private StateResolver $stateResolver;
    private ?SignatureVerifier $signatureVerifier;

    /**
     * Initialize validator
     * 
     * @param Configuration $config SDK configuration
     * @param HttpClient $httpClient HTTP client
     * @param CacheManager $cacheManager Cache manager
     * @param StateStore $stateStore State store
     * @param StateResolver $stateResolver State resolver
     * @param SignatureVerifier|null $signatureVerifier Optional signature verifier
     */
    public function __construct(
        Configuration $config,
        HttpClient $httpClient,
        CacheManager $cacheManager,
        StateStore $stateStore,
        StateResolver $stateResolver,
        ?SignatureVerifier $signatureVerifier = null
    ) {
        $this->config = $config;
        $this->httpClient = $httpClient;
        $this->cacheManager = $cacheManager;
        $this->stateStore = $stateStore;
        $this->stateResolver = $stateResolver;
        $this->signatureVerifier = $signatureVerifier;
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

        $cacheKey = $this->cacheManager->generateKey('license', $licenseKey, 'validation');
        if ($cached = $this->cacheManager->get($cacheKey)) {
            return $cached;
        }

        $payload = array_merge(['license_key' => $licenseKey], $options);
        
        $response = $this->httpClient->request('POST', '/v1/verify', $payload);

        $this->cacheManager->set($cacheKey, $response);

        return $response;
    }

    /**
     * Resolve License State (Hardened Validation)
     * 
     * Returns a LicenseState object that provides unified access to license status,
     * capabilities, and feature gates. This method implements the hardened validation
     * with signature verification and grace period support.
     * 
     * @param string $licenseKey License key to validate
     * @param array $options Optional parameters (hardwareId, domain, productId)
     * @return LicenseState License state object
     * @throws LicenseException
     */
    public function resolveLicenseState(string $licenseKey, array $options = []): LicenseState
    {
        $this->validateLicenseKey($licenseKey);

        // Try to get from StateStore first
        $stateKey = $this->stateStore->getValidationKey($licenseKey);
        
        if ($this->config->isCacheEnabled()) {
            try {
                $cachedState = $this->stateStore->get($stateKey);
                if ($cachedState !== null) {
                    // Check if revalidation is needed
                    if (!$cachedState->needsRevalidation()) {
                        return new LicenseState($cachedState, $licenseKey);
                    }
                }
            } catch (SignatureException $e) {
                // Cached state signature invalid - continue to revalidate
            }
        }

        // Perform validation
        try {
            $response = $this->validateLicense($licenseKey, $options);
            $licenseState = $this->stateResolver->resolveFromValidation($response, $licenseKey);
            
            // Store in StateStore
            if ($this->config->isCacheEnabled()) {
                $this->stateStore->set($stateKey, $licenseState->getEntitlementState());
            }
            
            return $licenseState;
        } catch (NetworkException $e) {
            // Network error - try to use cached state in grace period
            try {
                $cachedState = $this->stateStore->get($stateKey);
                if ($cachedState !== null && $cachedState->allowsOperation()) {
                    // Return grace state
                    return $this->stateResolver->createGraceState(
                        $cachedState->toArray(),
                        $licenseKey
                    );
                }
            } catch (SignatureException $se) {
                // Invalid cache - throw original network error
            }
            
            throw $e;
        }
    }

    /**
     * Check if a feature is allowed (State-Based)
     * 
     * This method uses LicenseState for feature checking with proper
     * capability resolution.
     * 
     * @param string $licenseKey License key
     * @param string $feature Feature name
     * @return bool True if feature is allowed
     * @throws LicenseException
     */
    public function isFeatureAllowed(string $licenseKey, string $feature): bool
    {
        $licenseState = $this->resolveLicenseState($licenseKey);
        return $licenseState->allows($feature);
    }

    /**
     * Get License State (Public API)
     * 
     * Convenience method that returns license state without throwing exceptions
     * on validation failure (returns restricted state instead).
     * 
     * @param string $licenseKey License key
     * @param array $options Optional parameters
     * @return LicenseState License state object
     */
    public function getLicenseState(string $licenseKey, array $options = []): LicenseState
    {
        try {
            return $this->resolveLicenseState($licenseKey, $options);
        } catch (LicenseException $e) {
            // Return restricted state on error
            return $this->stateResolver->createRestrictedState(
                $e->getMessage(),
                $licenseKey
            );
        }
    }

    /**
     * Validate and get state for update/download operations
     * 
     * This method enforces that updates/downloads require a valid state
     * with appropriate capabilities.
     * 
     * @param string $licenseKey License key
     * @param string $capability Required capability ('updates' or 'downloads')
     * @return LicenseState Validated state
     * @throws StateException If capability not allowed
     */
    public function requireCapability(string $licenseKey, string $capability): LicenseState
    {
        $state = $this->resolveLicenseState($licenseKey);
        
        if (!$state->allows($capability)) {
            throw new StateException(
                "License does not have required capability: {$capability}",
                403,
                StateException::ERROR_INVALID_STATE,
                ['capability' => $capability, 'state' => $state->getState()]
            );
        }
        
        return $state;
    }

    /**
     * Clear state cache for a license
     * 
     * @param string $licenseKey License key
     * @return void
     */
    public function clearLicenseState(string $licenseKey): void
    {
        $this->stateStore->clearLicense($licenseKey);
        $this->cacheManager->clearByPattern("license:{$licenseKey}:*");
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
        
        $response = $this->httpClient->request(
            'POST',
            '/v1/activate',
            $payload,
            ['Idempotency-Key' => $idempotencyKey]
        );

        $this->cacheManager->clearByPattern("license:{$licenseKey}:*");

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

        $response = $this->httpClient->request(
            'POST',
            '/v1/deactivate',
            $payload,
            ['Idempotency-Key' => $idempotencyKey]
        );

        $this->cacheManager->clearByPattern("license:{$licenseKey}:*");

        return $response;
    }

    /**
     * Get license file content for offline validation
     * 
     * Retrieve .lic file content for offline license validation. Returns base64 encoded 
     * license file with cryptographic signature that can be verified offline using 
     * the product's public key.
     * 
     * @param string $licenseKey License key
     * @param array $options Optional parameters (identifier for hardware-bound licenses)
     * @return array License file result with licFileContent
     * @throws LicenseException
     */
    public function getLicenseFile(string $licenseKey, array $options = []): array
    {
        $this->validateLicenseKey($licenseKey);

        $payload = array_merge(['license_key' => $licenseKey], $options);

        $response = $this->httpClient->request('POST', '/v1/get-license-file', $payload);

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

        $publicKey = $options['publicKey'] ?? $this->config->getPublicKey();
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
     * Parse and decrypt a .lic license file
     * 
     * Decrypts a license file using the product's public key and returns the license data.
     * This is for offline license validation scenarios.
     * 
     * @param string $licFileContent Base64-encoded encrypted license file content
     * @param string $publicKey Product public key in PEM format
     * @return array Decrypted license data
     * @throws ValidationException If decryption fails or data is invalid
     */
    public function parseLicenseFile(string $licFileContent, string $publicKey): array
    {
        try {
            // Validate inputs
            if (empty($licFileContent)) {
                throw new ValidationException(
                    'License file content cannot be empty',
                    'EMPTY_LICENSE_FILE'
                );
            }

            if (empty($publicKey)) {
                throw new ValidationException(
                    'Public key cannot be empty',
                    'EMPTY_PUBLIC_KEY'
                );
            }

            // Decode base64
            $encryptedData = base64_decode($licFileContent, true);
            if ($encryptedData === false) {
                throw new ValidationException(
                    'Invalid base64 encoding in license file',
                    'INVALID_BASE64'
                );
            }

            // Split into chunks (256 bytes for RSA-2048/4096)
            $chunks = str_split($encryptedData, 256);
            $decrypted = '';

            // Verify public key is valid
            $keyResource = openssl_pkey_get_public($publicKey);
            if ($keyResource === false) {
                $opensslError = openssl_error_string() ?: 'Unknown OpenSSL error';
                throw new ValidationException(
                    'Invalid public key: ' . $opensslError,
                    'INVALID_PUBLIC_KEY'
                );
            }

            // Decrypt each chunk
            foreach ($chunks as $index => $chunk) {
                $partial = '';
                
                // Decrypt using public key with PKCS1 padding
                $decryptionOk = openssl_public_decrypt(
                    $chunk,
                    $partial,
                    $publicKey,
                    OPENSSL_PKCS1_PADDING
                );

                if ($decryptionOk === false) {
                    $opensslError = openssl_error_string() ?: 'Unknown OpenSSL error';
                    throw new ValidationException(
                        'Decryption failed at chunk ' . $index . ': ' . $opensslError . '. ' .
                        'This may indicate a corrupted license file or incorrect public key.',
                        'DECRYPTION_FAILED'
                    );
                }
                
                $decrypted .= $partial;
            }

            // Free the key resource
            openssl_pkey_free($keyResource);

            // Parse JSON
            $licenseData = json_decode($decrypted, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new ValidationException(
                    'Invalid JSON in decrypted license data: ' . json_last_error_msg(),
                    'INVALID_JSON'
                );
            }

            if (!is_array($licenseData)) {
                throw new ValidationException(
                    'Decrypted license data is not a valid array',
                    'INVALID_DATA_FORMAT'
                );
            }

            return $licenseData;

        } catch (ValidationException $e) {
            // Re-throw validation exceptions as-is
            throw $e;
        } catch (Exception $e) {
            // Wrap any other exceptions
            throw new ValidationException(
                'Unexpected error parsing license file: ' . $e->getMessage(),
                'PARSE_ERROR',
                0,
                $e
            );
        }
    }

    /**
     * Synchronize license and key files with server
     * 
     * This function:
     * 1. Parses local .lic file using parseLicenseFile()
     * 2. Verifies license with server via POST /v1/verify
     * 3. Updates .lic file if verification successful
     * 4. Fetches and updates .key file from server
     * 5. Sends telemetry on failures for piracy detection
     * 
     * @param string $licPath Path to local .lic file
     * @param string $keyPath Path to local .key/.pem file
     * @return array Result with keys: success, licenseUpdated, keyUpdated, errors[], warnings[]
     */
    public function syncLicenseAndKey(string $licPath, string $keyPath): array
    {
        $result = [
            'success' => false,
            'licenseUpdated' => false,
            'keyUpdated' => false,
            'errors' => [],
            'warnings' => []
        ];

        try {
            // Step 1: Validate file paths
            if (!file_exists($licPath)) {
                throw new Exception("License file not found: {$licPath}");
            }
            if (!is_readable($licPath)) {
                throw new Exception("License file not readable: {$licPath}");
            }
            if (!file_exists($keyPath)) {
                throw new Exception("Key file not found: {$keyPath}");
            }
            if (!is_readable($keyPath)) {
                throw new Exception("Key file not readable: {$keyPath}");
            }

            // Read files
            $licFileContent = file_get_contents($licPath);
            $publicKey = file_get_contents($keyPath);

            if ($licFileContent === false) {
                throw new Exception("Failed to read license file: {$licPath}");
            }
            if ($publicKey === false) {
                throw new Exception("Failed to read key file: {$keyPath}");
            }

            // Step 2: Parse local .lic file
            $licenseData = $this->parseLicenseFile($licFileContent, $publicKey);

            // Extract license key for verification
            $licenseKey = $licenseData['license_key'] ?? $licenseData['key'] ?? null;
            if (!$licenseKey) {
                throw new Exception("License key not found in .lic file");
            }

            // Verify product UUID matches
            $configProductUuid = $this->config->getProductId();
            $fileProductUuid = $licenseData['product_uuid'] ?? $licenseData['product']['uuid'] ?? null;
            
            if ($configProductUuid && $fileProductUuid && $configProductUuid !== $fileProductUuid) {
                throw new Exception("Product UUID mismatch. Config: {$configProductUuid}, File: {$fileProductUuid}");
            }

            // Collect system information for telemetry
            $systemInfo = $this->collectSystemInformation();

            // Step 3: Verify license with server
            try {
                $verifyPayload = [
                    'license_key' => $licenseKey,
                    'server_domain' => $systemInfo['domain'],
                    'server_ip' => $systemInfo['ip']
                ];

                // Add hardware ID if available
                if (isset($licenseData['hardware_id'])) {
                    $verifyPayload['hardware_id'] = $licenseData['hardware_id'];
                }

                $verifyResponse = $this->httpClient->request('POST', '/v1/verify', $verifyPayload);

                // Check if response is signed
                if ($this->signatureVerifier && !$this->isResponseSigned($verifyResponse)) {
                    throw new Exception("Verification response is not properly signed");
                }

                // Step 4: Update .lic file if licFileContent provided
                if (isset($verifyResponse['licFileContent']) && !empty($verifyResponse['licFileContent'])) {
                    $this->atomicFileWrite($licPath, $verifyResponse['licFileContent']);
                    $result['licenseUpdated'] = true;
                } else {
                    $result['warnings'][] = "Server did not provide updated license file content";
                }

            } catch (Exception $e) {
                // Step 3.1: Verification failed - send telemetry
                $this->sendTelemetryOnFailure(
                    $licenseKey,
                    'Check Failed',
                    'License verification failed: ' . $e->getMessage(),
                    $systemInfo,
                    $e
                );
                
                $result['errors'][] = 'License verification failed: ' . $e->getMessage();
                return $result;
            }

            // Step 5: Fetch updated public key
            if (!$fileProductUuid) {
                $result['warnings'][] = "Product UUID not found in license file, skipping key update";
            } else {
                try {
                    $keyResponse = $this->httpClient->request('GET', '/api/v1/get-product-public-key', [
                        'product_uuid' => $fileProductUuid
                    ]);

                    // Check if response is signed
                    if ($this->signatureVerifier && !$this->isResponseSigned($keyResponse)) {
                        throw new Exception("Public key response is not properly signed");
                    }

                    // Verify product UUID matches
                    $responseProductUuid = $keyResponse['product']['uuid'] ?? $keyResponse['data']['product_uuid'] ?? null;
                    if ($responseProductUuid && $responseProductUuid !== $fileProductUuid) {
                        throw new Exception("Product UUID mismatch in key response");
                    }

                    // Step 6: Update .key file atomically
                    $newPublicKey = $keyResponse['public_key'] ?? $keyResponse['data']['public_key'] ?? null;
                    if ($newPublicKey && !empty($newPublicKey)) {
                        $this->atomicFileWrite($keyPath, $newPublicKey);
                        $result['keyUpdated'] = true;
                    } else {
                        $result['warnings'][] = "Server did not provide public key";
                    }

                } catch (Exception $e) {
                    // Step 7: Key fetch failed - send telemetry
                    $this->sendTelemetryOnFailure(
                        $licenseKey,
                        'Key Update Failed',
                        'Public key fetch failed: ' . $e->getMessage(),
                        $systemInfo,
                        $e
                    );
                    
                    $result['errors'][] = 'Public key update failed: ' . $e->getMessage();
                    // Don't return here - license was updated successfully
                }
            }

            $result['success'] = true;
            return $result;

        } catch (Exception $e) {
            // Catch-all for any unexpected errors
            $result['errors'][] = $e->getMessage();
            
            // Send telemetry for unexpected errors
            try {
                $systemInfo = $this->collectSystemInformation();
                $this->sendTelemetryOnFailure(
                    'unknown',
                    'Sync Failed',
                    'syncLicenseAndKey error: ' . $e->getMessage(),
                    $systemInfo,
                    $e
                );
            } catch (Exception $telemetryError) {
                // Telemetry failed, but don't hide original error
                $result['warnings'][] = 'Failed to send telemetry: ' . $telemetryError->getMessage();
            }
            
            return $result;
        }
    }

    /**
     * Send telemetry data
     * 
     * @param string $licenseKey License key
     * @param string $dataGroup Telemetry data group (e.g., 'security', 'usage', 'performance')
     * @param string $dataType Data type ('numeric-single-value', 'numeric-xy-axis', 'text')
     * @param mixed $data Data value(s)
     * @param array $options Optional parameters (hwid, country, flags, metadata, activation_identifier)
     * @return array Response data
     */
    public function sendTelemetry(string $licenseKey, string $dataGroup, string $dataType, $data, array $options = []): array
    {
        $systemInfo = $this->collectSystemInformation();
        
        $payload = [
            'license_key' => $licenseKey,
            'data_group' => $dataGroup,
            'data_type' => $dataType,
            'hwid' => $options['hwid'] ?? $systemInfo['hwid'],
            'country' => $options['country'] ?? null,
            'activation_identifier' => $options['activation_identifier'] ?? $systemInfo['domain'],
            'user_identifier' => $options['user_identifier'] ?? null,
            'product_version' => $options['product_version'] ?? $this->config->getVersion(),
            'flags' => $options['flags'] ?? [],
            'metadata' => $options['metadata'] ?? [],
        ];

        // Map data based on type
        switch ($dataType) {
            case 'numeric-single-value':
                $payload['numeric_data_single_value'] = $data;
                break;
            case 'numeric-xy-axis':
                $payload['numeric_data_x'] = $data['x'] ?? null;
                $payload['numeric_data_y'] = $data['y'] ?? null;
                break;
            case 'text':
                $payload['text_data'] = is_string($data) ? $data : json_encode($data);
                break;
        }

        return $this->httpClient->request('POST', '/v1/telemetry', $payload);
    }

    /**
     * Collect system information for telemetry
     * 
     * @return array System information
     */
    private function collectSystemInformation(): array
    {
        $info = [
            'ip' => '',
            'domain' => '',
            'hostname' => '',
            'os' => PHP_OS,
            'php_version' => PHP_VERSION,
            'timestamp' => date('Y-m-d H:i:s'),
            'hwid' => ''
        ];

        // Get server IP
        $info['ip'] = $_SERVER['SERVER_ADDR'] ?? $_SERVER['LOCAL_ADDR'] ?? gethostbyname(gethostname() ?: 'localhost');

        // Get domain
        $info['domain'] = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? gethostname();

        // Get hostname
        $info['hostname'] = gethostname() ?: 'unknown';

        // Get detailed system info on Linux
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            // Linux/Unix
            if (function_exists('shell_exec') && is_callable('shell_exec')) {
                $hostnamectl = @shell_exec('hostnamectl 2>/dev/null');
                if ($hostnamectl) {
                    $info['system_details'] = trim($hostnamectl);
                }
            }
        } else {
            // Windows
            if (function_exists('shell_exec') && is_callable('shell_exec')) {
                $systeminfo = @shell_exec('systeminfo | findstr /B /C:"OS Name" /C:"OS Version" /C:"System Type" 2>nul');
                if ($systeminfo) {
                    $info['system_details'] = trim($systeminfo);
                }
            }
        }

        // Generate HWID
        $hwidData = $info['hostname'] . $info['os'] . ($info['system_details'] ?? '') . $info['ip'];
        $info['hwid'] = hash('sha256', $hwidData);

        return $info;
    }

    /**
     * Send telemetry data on failure (for piracy detection)
     * 
     * @param string $licenseKey License key
     * @param string $dataGroup Telemetry data group
     * @param string $message Error message
     * @param array $systemInfo System information
     * @param \Throwable|null $exception Optional exception for detailed reporting
     */
    private function sendTelemetryOnFailure(string $licenseKey, string $dataGroup, string $message, array $systemInfo, ?\Throwable $exception = null): void
    {
        try {
            $textData = $message;
            if ($exception) {
                $textData .= "\nException: " . get_class($exception) . 
                            "\nMessage: " . $exception->getMessage() . 
                            "\nFile: " . $exception->getFile() . 
                            "\nLine: " . $exception->getLine() .
                            "\nTrace: " . substr($exception->getTraceAsString(), 0, 1000);
            }

            $telemetryData = [
                'license_key' => $licenseKey,
                'server_ip' => $systemInfo['ip'],
                'server_domain' => $systemInfo['domain'],
                'hostname' => $systemInfo['hostname'],
                'os' => $systemInfo['os'],
                'php_version' => $systemInfo['php_version'],
                'timestamp' => $systemInfo['timestamp'],
                'error_message' => $message,
                'hwid' => $systemInfo['hwid']
            ];

            if ($exception) {
                $telemetryData['exception_file'] = $exception->getFile();
                $telemetryData['exception_line'] = $exception->getLine();
                $telemetryData['exception_class'] = get_class($exception);
            }

            if (isset($systemInfo['system_details'])) {
                $telemetryData['system_details'] = $systemInfo['system_details'];
            }

            $flags = [];
            if ($exception) {
                $flags['exception_raised'] = true;
            }
            if (strpos(strtolower($message), 'signature') !== false) {
                $flags['signature_failure'] = true;
            }
            if (strpos(strtolower($message), 'tamper') !== false) {
                $flags['tampering_detected'] = true;
            }

            $this->sendTelemetry($licenseKey, $dataGroup, 'text', $textData, [
                'hwid' => $systemInfo['hwid'],
                'activation_identifier' => $systemInfo['domain'],
                'flags' => $flags,
                'metadata' => $telemetryData
            ]);
        } catch (Exception $e) {
            // Silent fail - telemetry is not critical
        }
    }

    /**
     * Atomically write content to a file
     * 
     * @param string $filePath Target file path
     * @param string $content Content to write
     * @throws Exception On write failure
     */
    private function atomicFileWrite(string $filePath, string $content): void
    {
        $dir = dirname($filePath);
        $tempFile = $dir . '/' . uniqid('tmp_', true);

        // Write to temporary file
        if (file_put_contents($tempFile, $content, LOCK_EX) === false) {
            throw new Exception("Failed to write temporary file: {$tempFile}");
        }

        // Set same permissions as original file (if exists)
        if (file_exists($filePath)) {
            $perms = fileperms($filePath);
            if ($perms !== false) {
                chmod($tempFile, $perms);
            }
        }

        // Atomic rename
        if (!rename($tempFile, $filePath)) {
            @unlink($tempFile); // Clean up temp file
            throw new Exception("Failed to replace file atomically: {$filePath}");
        }
    }

    /**
     * Check if API response is properly signed
     * 
     * @param array $response API response
     * @return bool True if signed (or signatures disabled)
     */
    private function isResponseSigned(array $response): bool
    {
        if (!$this->config->shouldVerifySignatures()) {
            return true; // Signatures not required
        }

        return isset($response['signature']) && !empty($response['signature']);
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

    /**
     * Check if license check interval has past
     * 
     * Parses the .lic file and checks if the license check interval
     * (licenseCheckInterval days) has elapsed since lastCheckedDate.
     * 
     * Returns true if:
     * - Check interval has expired
     * - Any error occurs during parsing/validation
     * 
     * Automatically sends telemetry on expiration or error.
     * 
     * @param string $licPath Path to .lic file
     * @param string $keyPath Path to public key file
     * @return bool True if check interval has passed or error occurred
     */
    public function isCheckIntervalPast(string $licPath, string $keyPath): bool
    {
        try {
            // Check if files exist
            if (!file_exists($licPath)) {
                $this->sendIntervalTelemetry('License file not found', $licPath, 'check_interval');
                return true;
            }

            if (!file_exists($keyPath)) {
                $this->sendIntervalTelemetry('Public key file not found', $keyPath, 'check_interval');
                return true;
            }

            // Read files
            $licContent = file_get_contents($licPath);
            $publicKey = file_get_contents($keyPath);

            if ($licContent === false || $publicKey === false) {
                $this->sendIntervalTelemetry('Failed to read license or key file', $licPath, 'check_interval');
                return true;
            }

            // Parse license file
            $licenseData = $this->parseLicenseFile($licContent, $publicKey);

            // Extract required fields
            if (!isset($licenseData['lastCheckedDate']) || !isset($licenseData['licenseCheckInterval'])) {
                $this->sendIntervalTelemetry('Missing lastCheckedDate or licenseCheckInterval', $licPath, 'check_interval');
                return true;
            }

            $lastCheckedDate = $licenseData['lastCheckedDate'];
            $intervalDays = (int)$licenseData['licenseCheckInterval'];
            $licenseKey = $licenseData['licenseKey'] ?? 'unknown';

            // Parse date
            $lastChecked = \DateTime::createFromFormat('Y-m-d', $lastCheckedDate);
            if (!$lastChecked) {
                $this->sendIntervalTelemetry('Invalid lastCheckedDate format', $licPath, 'check_interval', $licenseKey);
                return true;
            }

            // Calculate expiry date
            $expiryDate = clone $lastChecked;
            $expiryDate->modify("+{$intervalDays} days");

            // Compare with current date
            $currentDate = new \DateTime();
            $isPast = $currentDate >= $expiryDate;

            // Send telemetry if interval has passed
            if ($isPast) {
                $daysOverdue = $currentDate->diff($expiryDate)->days;
                $this->sendIntervalTelemetry(
                    "License check interval expired (overdue by {$daysOverdue} days)",
                    $licPath,
                    'check_interval',
                    $licenseKey
                );
            }

            return $isPast;

        } catch (\Exception $e) {
            // On any error, send telemetry and return true (fail-safe)
            $this->sendIntervalTelemetry(
                'Error checking interval: ' . $e->getMessage(),
                $licPath ?? 'unknown',
                'check_interval'
            );
            return true;
        }
    }

    /**
     * Check if force validation period has past
     * 
     * Parses the .lic file and checks if the force validation period
     * (forceLicenseValidation days) has elapsed since lastCheckedDate.
     * 
     * Returns true if:
     * - Force validation period has expired
     * - Any error occurs during parsing/validation
     * 
     * When this returns true, the application should show license screen
     * and require admin to revalidate.
     * 
     * Automatically sends telemetry on expiration or error.
     * 
     * @param string $licPath Path to .lic file
     * @param string $keyPath Path to public key file
     * @return bool True if force validation period has passed or error occurred
     */
    public function isForceValidationPast(string $licPath, string $keyPath): bool
    {
        try {
            // Check if files exist
            if (!file_exists($licPath)) {
                $this->sendIntervalTelemetry('License file not found', $licPath, 'force_validation');
                return true;
            }

            if (!file_exists($keyPath)) {
                $this->sendIntervalTelemetry('Public key file not found', $keyPath, 'force_validation');
                return true;
            }

            // Read files
            $licContent = file_get_contents($licPath);
            $publicKey = file_get_contents($keyPath);

            if ($licContent === false || $publicKey === false) {
                $this->sendIntervalTelemetry('Failed to read license or key file', $licPath, 'force_validation');
                return true;
            }

            // Parse license file
            $licenseData = $this->parseLicenseFile($licContent, $publicKey);

            // Extract required fields
            if (!isset($licenseData['lastCheckedDate']) || !isset($licenseData['forceLicenseValidation'])) {
                $this->sendIntervalTelemetry('Missing lastCheckedDate or forceLicenseValidation', $licPath, 'force_validation');
                return true;
            }

            $lastCheckedDate = $licenseData['lastCheckedDate'];
            $validationDays = (int)$licenseData['forceLicenseValidation'];
            $licenseKey = $licenseData['licenseKey'] ?? 'unknown';

            // Parse date
            $lastChecked = \DateTime::createFromFormat('Y-m-d', $lastCheckedDate);
            if (!$lastChecked) {
                $this->sendIntervalTelemetry('Invalid lastCheckedDate format', $licPath, 'force_validation', $licenseKey);
                return true;
            }

            // Calculate expiry date
            $expiryDate = clone $lastChecked;
            $expiryDate->modify("+{$validationDays} days");

            // Compare with current date
            $currentDate = new \DateTime();
            $isPast = $currentDate >= $expiryDate;

            // Send telemetry if force validation period has passed
            if ($isPast) {
                $daysOverdue = $currentDate->diff($expiryDate)->days;
                $this->sendIntervalTelemetry(
                    "Force validation period expired (overdue by {$daysOverdue} days)",
                    $licPath,
                    'force_validation',
                    $licenseKey
                );
            }

            return $isPast;

        } catch (\Exception $e) {
            // On any error, send telemetry and return true (fail-safe)
            $this->sendIntervalTelemetry(
                'Error checking force validation: ' . $e->getMessage(),
                $licPath ?? 'unknown',
                'force_validation'
            );
            return true;
        }
    }

    /**
     * Send telemetry for interval/validation checks
     * 
     * @param string $message Error or status message
     * @param string $licPath License file path
     * @param string $checkType Type of check (check_interval or force_validation)
     * @param string|null $licenseKey Optional license key
     */
    private function sendIntervalTelemetry(
        string $message,
        string $licPath,
        string $checkType,
        ?string $licenseKey = null
    ): void {
        try {
            // Collect system information
            $systemInfo = $this->collectSystemInfo();

            // Prepare telemetry data
            $telemetryData = [
                'text' => $message,
                'text_data' => ucfirst(str_replace('_', ' ', $checkType)) . ' Failed',
                'license_key' => $licenseKey ?? 'unknown',
                'license_file_path' => $licPath,
                'check_type' => $checkType,
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                'server_ip' => $systemInfo['server_ip'],
                'server_domain' => $systemInfo['server_domain'],
                'server_hostname' => $systemInfo['server_hostname'],
                'os_platform' => $systemInfo['os_platform'],
                'os_version' => $systemInfo['os_version'],
                'runtime_version' => $systemInfo['runtime_version'],
            ];

            // Add system details if available
            if (!empty($systemInfo['system_details'])) {
                $telemetryData['system_details'] = $systemInfo['system_details'];
            }

            // Send telemetry (fire-and-forget)
            $this->httpClient->post('/v1/telemetry', $telemetryData);
        } catch (\Exception $e) {
            // Silent fail for telemetry
        }
    }
}
