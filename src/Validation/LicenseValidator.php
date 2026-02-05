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
use GetKeyManager\SDK\Dto\LicenseDataDto;
use GetKeyManager\SDK\Dto\ValidationResultDto;
use GetKeyManager\SDK\Dto\ActivationResultDto;
use GetKeyManager\SDK\Dto\SyncResultDto;
use GetKeyManager\SDK\Constants\OptionKeys;
use GetKeyManager\SDK\Constants\ValidationType;
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
     * Validate a License with Smart Offline-First or Force API Strategy
     * 
     * MANDATORY PARAMETERS:
     * @param string $licenseKey License key (e.g., "LIC-2024-ABCD1234")
     * @param string $identifier Domain or hardware ID (domain binding or hardware binding)
     *
     * OPTIONAL PARAMETERS:
     * @param ?string $publicKey Product RSA public key for offline .lic validation
     *                             If not provided, inherits from Configuration::getProductPublicKey()
     *                             Required for offline-first validation; not needed for force API
     * @param bool $force ValidationType::FORCE_API (true) forces API call; 
     *                     ValidationType::OFFLINE_FIRST (false, default) tries offline first
     * @param array $options Optional request parameters (see OptionKeys class for valid keys)
     *                        - CACHE_TTL: Cache time-to-live in seconds
     *                        - TIMEOUT: HTTP request timeout in seconds
     *                        - METADATA: Additional metadata as associative array
     *                        - NO_CACHE: Set to true to skip cache
     * 
     * RETURN:
     * @return ValidationResultDto Type-safe validation result with license data
     *
     * EXAMPLES:
     * 
     * Fresh Install (force API):
     * ```php
     * $config = new Configuration(['productPublicKey' => $publicKey, 'licenseFilePath' => '/lic']);
     * $validator = new LicenseValidator($config, ...);
     * $result = $validator->validateLicense(
     *     'LIC-2024-ABC123',           // MANDATORY
     *     'example.com',                // MANDATORY identifier
     *     null,                         // OPTIONAL publicKey (inherits from config)
     *     ValidationType::FORCE_API     // OPTIONAL force=true for fresh install
     * );
     * if ($result->isSuccess()) {
     *     echo "License valid: " . $result->getLicense()->license_key;
     * }
     * ```
     *
     * Subsequent Calls (offline-first, default):
     * ```php
     * $result = $validator->validateLicense(
     *     'LIC-2024-ABC123',                      // MANDATORY
     *     'example.com',                          // MANDATORY identifier
     *     null,                                   // OPTIONAL (inherits)
     *     ValidationType::OFFLINE_FIRST           // OPTIONAL false = default behavior
     * );
     * ```
     * 
     * @throws InvalidArgumentException If required parameters are invalid (empty licenseKey or identifier)
     * @throws LicenseException If validation fails and no fallback available
     * @throws ValidationException If signature verification fails during offline validation
     */
    public function validateLicense(
        string $licenseKey,
        string $identifier,
        ?string $publicKey = null,
        bool $force = ValidationType::OFFLINE_FIRST,
        array $options = []
    ): ValidationResultDto {
        $this->validateLicenseKey($licenseKey);

        if (empty($identifier)) {
            throw new InvalidArgumentException(
                'Identifier (domain or hardware ID) is required for license validation. ' .
                'Please provide a domain name (web apps) or hardware ID (desktop/server). ' .
                'Example: $validator->validateLicense("LIC-KEY", "example.com") or ' .
                '$validator->validateLicense("LIC-KEY", $hardwareId). ' .
                'See: https://docs.getkeymanager.com/php-sdk#identifiers'
            );
        }

        // Determine validation strategy
        $useOfflineFirst = ($force === ValidationType::OFFLINE_FIRST);

        // If offline-first and public key available, try offline first
        if ($useOfflineFirst && $publicKey) {
            try {
                $offlineResult = $this->attemptOfflineValidation($licenseKey, $publicKey, $identifier, $options);
                if ($offlineResult !== null) {
                    return $offlineResult;
                }
            } catch (Exception $e) {
                // Offline validation failed, fall through to API
            }
        }

        // API validation
        try {
            $cacheKey = $this->cacheManager->generateKey('license', $licenseKey, 'validation');
            
            if (!isset($options[OptionKeys::NO_CACHE]) && ($cached = $this->cacheManager->get($cacheKey))) {
                return ValidationResultDto::fromResponse($cached);
            }

            $payload = array_merge(['license_key' => $licenseKey, 'identifier' => $identifier], $options);
            
            $response = $this->httpClient->request('POST', '/v1/verify', $payload);

            if (!isset($options[OptionKeys::NO_CACHE])) {
                $this->cacheManager->set($cacheKey, $response);
            }

            return ValidationResultDto::fromResponse($response);
        } catch (Exception $e) {
            throw new LicenseException(
                'License validation failed: ' . $e->getMessage() . '. ' .
                'This may indicate a network issue, invalid license key, or server error. ' .
                'If the problem persists, please contact support with error details.',
                0,
                $e
            );
        }
    }

    /**
     * Resolve License State with Smart Offline-First Validation
     * 
     * Returns a LicenseState object that provides unified access to license status,
     * capabilities, and feature gates. This method implements hardened validation with
     * signature verification and grace period support.
     * 
     * MANDATORY PARAMETERS:
     * @param string $licenseKey License key (e.g., "LIC-2024-ABCD1234")
     * @param string $identifier Domain or hardware ID
     *
     * OPTIONAL PARAMETERS:
     * @param ?string $publicKey Product RSA public key (optional if configured)
     * @param bool $force ValidationType::FORCE_API or ValidationType::OFFLINE_FIRST (default)
     * @param array $options Optional parameters (CACHE_TTL, TIMEOUT, METADATA, NO_CACHE)
     * 
     * @return LicenseState License state object for capability checking
     * @throws LicenseException
     * @throws NetworkException With grace period fallback if configured
     * 
     * EXAMPLE:
     * ```php
     * try {
     *     $state = $validator->resolveLicenseState(
     *         'LIC-2024-ABC123',
     *         'example.com'
     *     );
     *     echo "License status: " . $state->getState();
     * } catch (NetworkException $e) {
     *     // Network error - grace period may be available
     * }
     * ```
     */
    public function resolveLicenseState(
        string $licenseKey,
        string $identifier,
        ?string $publicKey = null,
        bool $force = ValidationType::OFFLINE_FIRST,
        array $options = []
    ): LicenseState {
        $this->validateLicenseKey($licenseKey);

        if (empty($identifier)) {
            throw new InvalidArgumentException(
                'Identifier (domain or hardware ID) is required for state resolution. ' .
                'Use a domain name (web apps) or hardware ID (desktop/server). ' .
                'See: https://docs.getkeymanager.com/php-sdk#identifiers'
            );
        }

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
            $validationResult = $this->validateLicense($licenseKey, $identifier, $publicKey, $force, $options);
            
            if (!$validationResult->isSuccess()) {
                throw new LicenseException(
                    'Validation failed: ' . $validationResult->getMessage(),
                    $validationResult->getCode()
                );
            }

            $licenseState = $this->stateResolver->resolveFromValidation($validationResult->toArray(), $licenseKey);
            
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
     * Check if a Feature is Allowed (State-Based Authorization)
     * 
     * Validates license state and checks if the specified feature is allowed.
     * Uses offline-first validation by default for performance.
     * 
     * MANDATORY PARAMETERS:
     * @param string $licenseKey License key
     * @param string $feature Feature name (e.g., 'updates', 'advanced_settings', 'api_access')
     * @param string $identifier Domain or hardware ID
     *
     * OPTIONAL PARAMETERS:
     * @param ?string $publicKey Product RSA public key (optional if configured)
     * @param bool $force ValidationType::FORCE_API or ValidationType::OFFLINE_FIRST (default)
     * @param array $options Optional parameters (CACHE_TTL, TIMEOUT, METADATA, NO_CACHE)
     * 
     * @return bool True if feature is allowed; false if denied or license invalid
     * 
     * EXAMPLE:
     * ```php
     * if ($validator->isFeatureAllowed('LIC-2024-ABC123', 'api_access', 'example.com')) {
     *     // Feature is allowed
     *     enableApiAccess();
     * } else {
     *     // Feature not available
     *     showUpgradePrompt();
     * }
     * ```
     */
    public function isFeatureAllowed(
        string $licenseKey,
        string $feature,
        string $identifier,
        ?string $publicKey = null,
        bool $force = ValidationType::OFFLINE_FIRST,
        array $options = []
    ): bool {
        try {
            $licenseState = $this->resolveLicenseState($licenseKey, $identifier, $publicKey, $force, $options);
            return $licenseState->allows($feature);
        } catch (Exception $e) {
            // Feature denied on any validation error
            return false;
        }
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
     * Activate License on a Device or Domain
     * 
     * Creates a new activation for the license on the specified identifier
     * (domain or hardware). Returns cryptographically signed activation confirmation.
     * 
     * MANDATORY PARAMETERS:
     * @param string $licenseKey License key (e.g., "LIC-2024-ABC123")
     * @param string $identifier Domain (for web) or hardware ID (for desktop/on-premises)
     *
     * OPTIONAL PARAMETERS:
     * @param ?string $publicKey Product RSA public key (optional if configured)
     * @param array $options Optional activation parameters
     *                        - IDEMPOTENCY_KEY: UUID for idempotent requests (auto-generated if omitted)
     *                        - OS: Operating system
     *                        - PRODUCT_VERSION: Product version string
     *                        - IP: Client IP address
     *                        - DOMAIN: Activation domain (if different from identifier)
     *                        - METADATA: Additional metadata
     * 
     * @return ActivationResultDto Type-safe activation result with activation_id and license data
     * @throws InvalidArgumentException If identifier is empty
     * @throws LicenseException If activation fails
     * 
     * EXAMPLE:
     * ```php
     * $result = $validator->activateLicense(
     *     'LIC-2024-ABC123',
     *     'workstation-01.example.com',
     *     null,
     *     [
     *         OptionKeys::OS => 'Windows 11 Pro',
     *         OptionKeys::PRODUCT_VERSION => '2.5.1'
     *     ]
     * );
     * 
     * if ($result->isSuccess()) {
     *     echo "Activated as: " . $result->getActivationId();
     * } else {
     *     echo "Activation failed: " . $result->getMessage();
     * }
     * ```
     */
    public function activateLicense(
        string $licenseKey,
        string $identifier,
        ?string $publicKey = null,
        array $options = []
    ): ActivationResultDto {
        $this->validateLicenseKey($licenseKey);

        if (empty($identifier)) {
            throw new InvalidArgumentException(
                'Identifier (domain or hardware ID) is required for license activation. ' .
                'For web apps, use the domain (e.g., "example.com"). ' .
                'For desktop/server, use hardware ID. ' .
                'Example: $validator->activateLicense("LIC-KEY", "workstation-01.example.com"). ' .
                'See: https://docs.getkeymanager.com/php-sdk#activation'
            );
        }

        $payload = array_merge([
            'license_key' => $licenseKey,
            'identifier' => $identifier
        ], $options);

        $idempotencyKey = $options[OptionKeys::IDEMPOTENCY_KEY] ?? $this->generateUuid();
        
        try {
            $response = $this->httpClient->request(
                'POST',
                '/v1/activate',
                $payload,
                ['Idempotency-Key' => $idempotencyKey]
            );

            $this->cacheManager->clearByPattern("license:{$licenseKey}:*");

            return ActivationResultDto::fromResponse($response);
        } catch (Exception $e) {
            throw new LicenseException(
                'License activation failed: ' . $e->getMessage() . '. ' .
                'Possible causes: license already activated, activation limit reached, or network issue. ' .
                'Ensure the identifier matches the deployment target.',
                0,
                $e
            );
        }
    }

    /**
     * Deactivate License from a Device or Domain
     * 
     * Removes the activation from the specified identifier. The license remains valid
     * for other activations or for reactivation on the same identifier later.
     * 
     * MANDATORY PARAMETERS:
     * @param string $licenseKey License key
     * @param string $identifier Domain or hardware ID to deactivate from
     *
     * OPTIONAL PARAMETERS:
     * @param array $options Optional deactivation parameters
     *                        - IDEMPOTENCY_KEY: UUID for idempotent requests (auto-generated if omitted)
     *                        - METADATA: Additional metadata
     * 
     * @return ActivationResultDto Type-safe deactivation result
     * @throws InvalidArgumentException If identifier is empty
     * @throws LicenseException If deactivation fails
     * 
     * EXAMPLE:
     * ```php
     * $result = $validator->deactivateLicense(
     *     'LIC-2024-ABC123',
     *     'workstation-01.example.com'
     * );
     * 
     * if ($result->isSuccess()) {
     *     echo "Deactivated successfully";
     * }
     * ```
     */
    public function deactivateLicense(
        string $licenseKey,
        string $identifier,
        array $options = []
    ): ActivationResultDto {
        $this->validateLicenseKey($licenseKey);

        if (empty($identifier)) {
            throw new InvalidArgumentException(
                'Identifier is required for deactivation. ' .
                'Must match the identifier used during activation. ' .
                'Example: $validator->deactivateLicense("LIC-KEY", "workstation-01.example.com")'
            );
        }

        $payload = array_merge([
            'license_key' => $licenseKey,
            'identifier' => $identifier
        ], $options);

        $idempotencyKey = $options[OptionKeys::IDEMPOTENCY_KEY] ?? $this->generateUuid();

        try {
            $response = $this->httpClient->request(
                'POST',
                '/v1/deactivate',
                $payload,
                ['Idempotency-Key' => $idempotencyKey]
            );

            $this->cacheManager->clearByPattern("license:{$licenseKey}:*");

            return ActivationResultDto::fromResponse($response);
        } catch (Exception $e) {
            throw new LicenseException(
                'License deactivation failed: ' . $e->getMessage() . '. ' .
                'Verify the identifier matches an active activation.',
                0,
                $e
            );
        }
    }

    /**
     * Get License File for Offline Validation
     * 
     * Retrieve .lic file content for offline license validation. Returns base64 encoded 
     * license file with cryptographic signature that can be verified offline using 
     * the product's public key.
     * 
     * MANDATORY PARAMETERS:
     * @param string $licenseKey License key
     * @param string $identifier Domain or hardware ID (for hardware-bound licenses)
     *
     * OPTIONAL PARAMETERS:
     * @param array $options Optional parameters
     *                        - METADATA: Additional metadata
     * 
     * @return array License file result with licFileContent (base64 encoded)
     * @throws InvalidArgumentException If identifier is empty
     * @throws LicenseException If file retrieval fails
     * 
     * EXAMPLE:
     * ```php
     * $result = $validator->getLicenseFile(
     *     'LIC-2024-ABC123',
     *     'example.com'
     * );
     * 
     * if (isset($result['licFileContent'])) {
     *     file_put_contents('/app/license.lic', $result['licFileContent']);
     * }
     * ```
     */
    public function getLicenseFile(
        string $licenseKey,
        string $identifier,
        array $options = []
    ): array {
        $this->validateLicenseKey($licenseKey);

        if (empty($identifier)) {
            throw new InvalidArgumentException(
                'Identifier is required to retrieve license file. ' .
                'This ensures the correct .lic file for your hardware or domain is downloaded. ' .
                'Example: $validator->getLicenseFile("LIC-KEY", "example.com")'
            );
        }

        $payload = array_merge([
            'license_key' => $licenseKey,
            'identifier' => $identifier
        ], $options);

        try {
            $response = $this->httpClient->request('POST', '/v1/get-license-file', $payload);
            return $response;
        } catch (Exception $e) {
            throw new LicenseException(
                'Failed to retrieve license file: ' . $e->getMessage() . '. ' .
                'Ensure license is valid and the identifier is correct.',
                0,
                $e
            );
        }
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

            // Verify public key is valid and get details to determine block size
            $keyResource = openssl_pkey_get_public($publicKey);
            if ($keyResource === false) {
                $opensslError = openssl_error_string() ?: 'Unknown OpenSSL error';
                throw new ValidationException(
                    'Invalid public key: ' . $opensslError,
                    'INVALID_PUBLIC_KEY'
                );
            }

            $keyDetails = openssl_pkey_get_details($keyResource);
            if ($keyDetails === false) {
                throw new ValidationException('Failed to get public key details', 'INVALID_PUBLIC_KEY');
            }

            // RSA block size is key_bits / 8
            $blockSize = $keyDetails['bits'] / 8;
            if ($blockSize <= 0) {
                $blockSize = 256; // Fallback to 2048-bit size
            }

            // Split into chunks based on key size (e.g., 256 for 2048-bit, 512 for 4096-bit)
            $chunks = str_split($encryptedData, $blockSize);
            $decrypted = '';

            // Decrypt each chunk
            foreach ($chunks as $index => $chunk) {
                $partial = '';
                
                // Decrypt using public key with PKCS1 padding
                $decryptionOk = openssl_public_decrypt(
                    $chunk,
                    $partial,
                    $keyResource, // Use resource instead of string for efficiency
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
     * Synchronize License and Key Files with Server
     * 
     * Atomically updates local .lic and .key files with latest data from server.
     * Performs offline validation of local file, then updates both files if verification succeeds.
     * 
     * Process:
     * 1. Validate local .lic file is readable and properly signed
     * 2. Verify license with server (POST /v1/verify)
     * 3. Update .lic file if server provided new content
     * 4. Fetch and update .key (public key) file from server
     * 5. Send telemetry on any errors for piracy detection
     * 
     * MANDATORY PARAMETERS:
     * @param string $licPath Absolute path to local .lic file
     * @param string $keyPath Absolute path to local .key/.pem file
     *
     * OPTIONAL PARAMETERS:
     * @param bool $force If true, force fresh sync; if false (default), check license first
     * 
     * @return SyncResultDto Result object with detailed success/error tracking
     *                        - isSuccess(): true if both files synced successfully
     *                        - isLicenseUpdated(): true if .lic file was updated
     *                        - isKeyUpdated(): true if .key file was updated
     *                        - getErrors(): array of error messages
     *                        - getWarnings(): array of warning messages
     *                        - getSummary(): Human-readable status string
     * 
     * @throws Exception If file paths are invalid or unreadable
     * 
     * EXAMPLE - Periodic Sync (from cron or background job):
     * ```php
     * $result = $validator->syncLicenseAndKey(
     *     '/var/app/license.lic',
     *     '/var/app/license.key'
     * );
     * 
     * if ($result->isSuccess()) {
     *     log("License sync successful");
     *     if ($result->isLicenseUpdated()) {
     *         log("License file was updated");
     *     }
     * } else {
     *     log("License sync failed: " . $result->getFirstError());
     * }
     * ```
     * 
     * EXAMPLE - Force Immediate Sync (during setup):
     * ```php
     * $result = $validator->syncLicenseAndKey(
     *     '/var/app/license.lic',
     *     '/var/app/license.key',
     *     true  // force=true for immediate critical sync
     * );
     * ```
     */
    public function syncLicenseAndKey(
        string $licPath,
        string $keyPath,
        bool $force = false
    ): SyncResultDto {
        $result = new SyncResultDto();

        try {
            // Step 1: Validate file paths
            if (!file_exists($licPath)) {
                $result->addError("License file not found: {$licPath}");
                return $result;
            }
            if (!is_readable($licPath)) {
                $result->addError("License file not readable: {$licPath}");
                return $result;
            }
            if (!file_exists($keyPath)) {
                $result->addError("Key file not found: {$keyPath}");
                return $result;
            }
            if (!is_readable($keyPath)) {
                $result->addError("Key file not readable: {$keyPath}");
                return $result;
            }

            // Read files
            $licFileContent = file_get_contents($licPath);
            $publicKey = file_get_contents($keyPath);

            if ($licFileContent === false) {
                $result->addError("Failed to read license file: {$licPath}");
                return $result;
            }
            if ($publicKey === false) {
                $result->addError("Failed to read key file: {$keyPath}");
                return $result;
            }

            // Step 2: Parse local .lic file
            $licenseData = $this->parseLicenseFile($licFileContent, $publicKey);

            // Extract license key for verification
            $licenseKey = $licenseData['license_key'] ?? $licenseData['key'] ?? null;
            if (!$licenseKey) {
                $result->addError("License key not found in .lic file");
                return $result;
            }

            // Verify product UUID matches
            $configProductUuid = $this->config->getProductId();
            $fileProductUuid = $licenseData['product_uuid'] ?? $licenseData['product']['uuid'] ?? null;
            
            if ($configProductUuid && $fileProductUuid && $configProductUuid !== $fileProductUuid) {
                $result->addError("Product UUID mismatch. Config: {$configProductUuid}, File: {$fileProductUuid}");
                return $result;
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
                    $result->addError("Verification response is not properly signed");
                    return $result;
                }

                // Step 4: Update .lic file if licFileContent provided
                if (isset($verifyResponse['licFileContent']) && !empty($verifyResponse['licFileContent'])) {
                    $this->atomicFileWrite($licPath, $verifyResponse['licFileContent']);
                    $result->isLicenseUpdated = true;
                } else {
                    $result->addWarning("Server did not provide updated license file content");
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
                
                $result->addError('License verification failed: ' . $e->getMessage());
                return $result;
            }

            // Step 5: Fetch updated public key
            if (!$fileProductUuid) {
                $result->addWarning("Product UUID not found in license file, skipping key update");
            } else {
                try {
                    $keyResponse = $this->httpClient->request('GET', '/api/v1/get-product-public-key', [
                        'product_uuid' => $fileProductUuid
                    ]);

                    // Check if response is signed
                    if ($this->signatureVerifier && !$this->isResponseSigned($keyResponse)) {
                        $result->addError("Public key response is not properly signed");
                        return $result;
                    }

                    // Verify product UUID matches
                    $responseProductUuid = $keyResponse['product']['uuid'] ?? $keyResponse['data']['product_uuid'] ?? null;
                    if ($responseProductUuid && $responseProductUuid !== $fileProductUuid) {
                        $result->addError("Product UUID mismatch in key response");
                        return $result;
                    }

                    // Step 6: Update .key file atomically
                    $newPublicKey = $keyResponse['public_key'] ?? $keyResponse['data']['public_key'] ?? null;
                    if ($newPublicKey && !empty($newPublicKey)) {
                        $this->atomicFileWrite($keyPath, $newPublicKey);
                        $result->isKeyUpdated = true;
                    } else {
                        $result->addWarning("Server did not provide public key");
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
                    
                    $result->addError('Public key update failed: ' . $e->getMessage());
                    // Don't return here - license was updated successfully
                }
            }

            $result->success = true;
            return $result;

        } catch (Exception $e) {
            // Catch-all for any unexpected errors
            $result->addError($e->getMessage());
            
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
                $result->addWarning('Failed to send telemetry: ' . $telemetryError->getMessage());
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

        return $this->httpClient->request('POST', '/v1/send-telemetry', $payload);
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
            throw new InvalidArgumentException(
                'License key cannot be empty. ' .
                'Please provide your license key (format: LIC-XXXX-XXXX-XXXX). ' .
                'If you don\'t have a license key, please generate one from your admin dashboard ' .
                'or contact support. ' .
                'See: https://docs.getkeymanager.com/php-sdk#getting-started'
            );
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
            $this->httpClient->post('/v1/send-telemetry', $telemetryData);
        } catch (\Exception $e) {
            // Silent fail for telemetry
        }
    }

    /**
     * Attempt Offline License Validation
     * 
     * Tries to validate a license offline using a .lic file on disk.
     * Returns null if offline validation cannot be attempted (no file, missing publicKey, etc).
     * Throws exception only if the file exists but validation fails (corruption, signature mismatch).
     * 
     * This implements the "offline-first" strategy: if the licensed application or framework
     * previously downloaded a .lic file, use it immediately without network round-trip.
     * 
     * @param string $licenseKey License key to validate
     * @param string $publicKey Product RSA public key for signature verification
     * @param string $identifier Domain or hardware ID for binding verification
     * @param array $options Optional parameters (licenseFilePath if different from config)
     * 
     * @return ?ValidationResultDto Validation result if offline validation succeeded; null if cannot attempt
     * 
     * @throws ValidationException If .lic file exists but is corrupted or signature invalid
     * @throws SignatureException If signature verification fails
     */
    private function attemptOfflineValidation(
        string $licenseKey,
        string $publicKey,
        string $identifier,
        array $options = []
    ): ?ValidationResultDto {
        // Try to find .lic file path
        $licFilePath = $options['licenseFilePath'] ?? $this->config->getLicenseFilePath();
        
        if (!$licFilePath) {
            return null; // No license file path configured
        }

        // Check if .lic file exists for this license
        $expectedPath = rtrim($licFilePath, '/') . '/' . hash('sha256', $licenseKey) . '.lic';
        
        if (!file_exists($expectedPath)) {
            return null; // No .lic file available for offline validation
        }

        try {
            // Read and parse the .lic file
            $licContent = file_get_contents($expectedPath);
            if ($licContent === false) {
                return null; // Cannot read file, fall back to API
            }

            // Parse the encrypted license file
            $licenseData = $this->parseLicenseFile($licContent, $publicKey);

            // Verify the license key matches
            if (($licenseData['license_key'] ?? $licenseData['key'] ?? null) !== $licenseKey) {
                throw new ValidationException(
                    'License key in offline file does not match request',
                    'LICENSE_KEY_MISMATCH'
                );
            }

            // Check expiry
            if (isset($licenseData['expires_at'])) {
                $expiresAt = strtotime($licenseData['expires_at']);
                if ($expiresAt && time() > $expiresAt) {
                    throw new ValidationException(
                        'License in offline file has expired',
                        'LICENSE_EXPIRED'
                    );
                }
            }

            // Check binding (if hardware_id or domain is specified in the license)
            if (isset($licenseData['hardware_id']) && $licenseData['hardware_id'] !== $identifier) {
                throw new ValidationException(
                    'Hardware ID in offline file does not match identifier',
                    'HARDWARE_BINDING_MISMATCH'
                );
            }

            if (isset($licenseData['domain']) && $licenseData['domain'] !== $identifier) {
                throw new ValidationException(
                    'Domain in offline file does not match identifier',
                    'DOMAIN_BINDING_MISMATCH'
                );
            }

            // Offline validation successful - build response
            return ValidationResultDto::fromResponse([
                'code' => 200,
                'success' => true,
                'message' => 'Offline validation successful',
                'data' => [
                    'license' => $licenseData
                ],
                'licFileContent' => $licContent
            ]);

        } catch (ValidationException $e) {
            // File exists but is invalid - this should fail, don't fall back
            throw $e;
        } catch (Exception $e) {
            // Other errors during parsing - return null to fall back to API
            return null;
        }
    }
}
