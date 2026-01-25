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
use GetKeyManager\SDK\LicenseException;
use GetKeyManager\SDK\ValidationException;
use GetKeyManager\SDK\SignatureException;
use GetKeyManager\SDK\NetworkException;
use GetKeyManager\SDK\StateException;
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
        
        $response = $this->httpClient->request('POST', '/api/v1/verify', $payload);

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
            '/api/v1/activate',
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
            '/api/v1/deactivate',
            $payload,
            ['Idempotency-Key' => $idempotencyKey]
        );

        $this->cacheManager->clearByPattern("license:{$licenseKey}:*");

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
