<?php

declare(strict_types=1);

namespace GetKeyManager\SDK\Features;

use GetKeyManager\SDK\Config\Configuration;
use GetKeyManager\SDK\Http\HttpClient;
use GetKeyManager\SDK\Cache\CacheManager;
use GetKeyManager\SDK\StateResolver;
use GetKeyManager\SDK\Validation\LicenseValidator;
use GetKeyManager\SDK\LicenseException;
use InvalidArgumentException;

/**
 * Feature Checker
 * 
 * Handles feature checking and validation.
 * 
 * @package GetKeyManager\SDK\Features
 */
class FeatureChecker
{
    private Configuration $config;
    private HttpClient $httpClient;
    private CacheManager $cacheManager;
    private StateResolver $stateResolver;
    private ?LicenseValidator $validator = null;

    /**
     * Initialize feature checker
     * 
     * @param Configuration $config SDK configuration
     * @param HttpClient $httpClient HTTP client
     * @param CacheManager $cacheManager Cache manager
     * @param StateResolver $stateResolver State resolver
     */
    public function __construct(
        Configuration $config,
        HttpClient $httpClient,
        CacheManager $cacheManager,
        StateResolver $stateResolver
    ) {
        $this->config = $config;
        $this->httpClient = $httpClient;
        $this->cacheManager = $cacheManager;
        $this->stateResolver = $stateResolver;
    }

    /**
     * Set the validator for state-based feature checking
     * 
     * @param LicenseValidator $validator License validator
     */
    public function setValidator(LicenseValidator $validator): void
    {
        $this->validator = $validator;
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

        $cacheKey = $this->cacheManager->generateKey('license', $licenseKey, 'feature', $featureName);
        if ($cached = $this->cacheManager->get($cacheKey)) {
            return $cached;
        }

        $response = $this->httpClient->request(
            'GET',
            "/api/v1/licenses/{$licenseKey}/features/{$featureName}"
        );

        $this->cacheManager->set($cacheKey, $response);

        return $response;
    }

    /**
     * Check if a feature is allowed (State-Based)
     * 
     * Delegates to LicenseValidator for state-based feature checking
     * with proper capability resolution.
     * 
     * @param string $licenseKey License key
     * @param string $feature Feature name
     * @return bool True if feature is allowed
     * @throws LicenseException
     */
    public function isFeatureAllowed(string $licenseKey, string $feature): bool
    {
        if ($this->validator === null) {
            throw new \RuntimeException('Validator not set. Call setValidator() first.');
        }

        return $this->validator->isFeatureAllowed($licenseKey, $feature);
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
}
