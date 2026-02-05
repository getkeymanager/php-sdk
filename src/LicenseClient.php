<?php

declare(strict_types=1);

namespace GetKeyManager\SDK;

use GetKeyManager\SDK\Config\Configuration;
use GetKeyManager\SDK\Constants\IdentifierType;
use GetKeyManager\SDK\Constants\ValidationType;
use GetKeyManager\SDK\Downloads\DownloadManager;
use GetKeyManager\SDK\Dto\ValidationResultDto;
use GetKeyManager\SDK\Dto\ActivationResultDto;
use GetKeyManager\SDK\Dto\SyncResultDto;
use GetKeyManager\SDK\Features\FeatureChecker;
use GetKeyManager\SDK\Http\HttpClient;
use GetKeyManager\SDK\Cache\CacheManager;
use GetKeyManager\SDK\Management\ContractManager;
use GetKeyManager\SDK\Management\LicenseManager;
use GetKeyManager\SDK\Management\ProductManager;
use GetKeyManager\SDK\Telemetry\TelemetryClient;
use GetKeyManager\SDK\Validation\LicenseValidator;

/**
 * License Management Platform - PHP SDK Client
 * 
 * Official PHP client for license validation, activation, and management.
 * This is the main entry point that delegates to specialized components.
 * 
 * @package GetKeyManager\SDK
 * @version 3.0.0
 * @license MIT
 */
class LicenseClient
{
    private const VERSION = '3.0.0';

    private Configuration $config;
    private ?HttpClient $httpClient = null;
    private ?CacheManager $cacheManager = null;
    private ?SignatureVerifier $signatureVerifier = null;
    private ?StateStore $stateStore = null;
    private ?StateResolver $stateResolver = null;

    // Lazy-loaded components
    private ?LicenseValidator $validator = null;
    private ?LicenseManager $licenseManager = null;
    private ?ProductManager $productManager = null;
    private ?ContractManager $contractManager = null;
    private ?FeatureChecker $featureChecker = null;
    private ?TelemetryClient $telemetryClient = null;
    private ?DownloadManager $downloadManager = null;

    /**
     * Initialize License Client
     * 
     * @param array $config Configuration options
     * @throws InvalidArgumentException
     */
    public function __construct(array $config)
    {
        $this->config = new Configuration($config);

        // Initialize signature verifier if needed
        if ($this->config->shouldVerifySignatures() && $this->config->getPublicKey()) {
            $this->signatureVerifier = new SignatureVerifier($this->config->getPublicKey());
        }

        // Initialize StateStore and StateResolver for hardened validation
        $this->stateStore = new StateStore($this->signatureVerifier, $this->config->getCacheTtl());
        $this->stateResolver = new StateResolver(
            $this->signatureVerifier,
            $this->config->getEnvironment(),
            $this->config->getProductId()
        );
    }

    // ========================================================================
    // LAZY COMPONENT INITIALIZATION
    // ========================================================================

    /**
     * Get HTTP client instance
     * 
     * @return HttpClient
     */
    private function getHttpClient(): HttpClient
    {
        if ($this->httpClient === null) {
            $this->httpClient = new HttpClient(
                $this->config,
                $this->signatureVerifier,
                $this->stateResolver
            );
        }
        return $this->httpClient;
    }

    /**
     * Get cache manager instance
     * 
     * @return CacheManager
     */
    private function getCacheManager(): CacheManager
    {
        if ($this->cacheManager === null) {
            $this->cacheManager = new CacheManager(
                $this->config->isCacheEnabled(),
                $this->config->getCacheTtl()
            );
        }
        return $this->cacheManager;
    }

    /**
     * Get license validator instance
     * 
     * @return LicenseValidator
     */
    private function getValidator(): LicenseValidator
    {
        if ($this->validator === null) {
            $this->validator = new LicenseValidator(
                $this->config,
                $this->getHttpClient(),
                $this->getCacheManager(),
                $this->stateStore,
                $this->stateResolver,
                $this->signatureVerifier
            );
        }
        return $this->validator;
    }

    /**
     * Get license manager instance
     * 
     * @return LicenseManager
     */
    private function getLicenseManager(): LicenseManager
    {
        if ($this->licenseManager === null) {
            $this->licenseManager = new LicenseManager(
                $this->config,
                $this->getHttpClient(),
                $this->getCacheManager()
            );
        }
        return $this->licenseManager;
    }

    /**
     * Get product manager instance
     * 
     * @return ProductManager
     */
    private function getProductManager(): ProductManager
    {
        if ($this->productManager === null) {
            $this->productManager = new ProductManager(
                $this->config,
                $this->getHttpClient(),
                $this->getCacheManager()
            );
        }
        return $this->productManager;
    }

    /**
     * Get contract manager instance
     * 
     * @return ContractManager
     */
    private function getContractManager(): ContractManager
    {
        if ($this->contractManager === null) {
            $this->contractManager = new ContractManager(
                $this->config,
                $this->getHttpClient(),
                $this->getCacheManager()
            );
        }
        return $this->contractManager;
    }

    /**
     * Get feature checker instance
     * 
     * @return FeatureChecker
     */
    private function getFeatureChecker(): FeatureChecker
    {
        if ($this->featureChecker === null) {
            $this->featureChecker = new FeatureChecker(
                $this->config,
                $this->getHttpClient(),
                $this->getCacheManager(),
                $this->stateResolver
            );
            // Set validator for state-based feature checking
            $this->featureChecker->setValidator($this->getValidator());
        }
        return $this->featureChecker;
    }

    /**
     * Get telemetry client instance
     * 
     * @return TelemetryClient
     */
    private function getTelemetryClient(): TelemetryClient
    {
        if ($this->telemetryClient === null) {
            $this->telemetryClient = new TelemetryClient(
                $this->config,
                $this->getHttpClient()
            );
        }
        return $this->telemetryClient;
    }

    /**
     * Get download manager instance
     * 
     * @return DownloadManager
     */
    private function getDownloadManager(): DownloadManager
    {
        if ($this->downloadManager === null) {
            $this->downloadManager = new DownloadManager(
                $this->config,
                $this->getHttpClient(),
                $this->getCacheManager()
            );
        }
        return $this->downloadManager;
    }

    // ========================================================================
    // VALIDATION & STATE MANAGEMENT (delegate to LicenseValidator)
    // ========================================================================

    /**
     * Validate License with Smart Offline-First or Force API Strategy
     * 
     * MANDATORY PARAMETERS:
     * @param string $licenseKey License key to validate
     * @param string $identifier Domain or hardware ID (auto-generated if not provided; use getIdentifierOrGenerate())
     *
     * OPTIONAL PARAMETERS:
     * @param ?string $publicKey Product public key for offline validation (inherits from config if not provided)
     * @param bool $force ValidationType::FORCE_API or ValidationType::OFFLINE_FIRST (default)
     * @param array $options Optional request parameters - valid keys defined in Constants\OptionKeys:
     *                        - CACHE_TTL: override cache time-to-live in seconds
     *                        - TIMEOUT: HTTP request timeout in seconds
     *                        - METADATA: Additional metadata (associative array)
     *                        - NO_CACHE: Set to true to skip cache and force fresh API call
     * 
     * @return ValidationResultDto Type-safe validation result with:
     *         - isSuccess(): bool - Whether validation passed
     *         - getLicense(): LicenseDataDto - License details if successful
     *         - getMessage(): string - Error message if failed
     *         - getCode(): int - Response code
     * 
     * @throws InvalidArgumentException If licenseKey is empty
     * @throws LicenseException If validation fails after all retries
     * @throws ValidationException If offline .lic file exists but is corrupted or signature invalid
     * 
     * USAGE EXAMPLES:
     * 
     * Example 1: Fresh Installation (force API)
     * ```php
     * $result = $client->validateLicense(
     *     'LIC-2024-ABC123',
     *     'example.com',
     *     null,
     *     ValidationType::FORCE_API  // Required first time
     * );
     * if ($result->isSuccess()) {
     *     echo "License expires: " . $result->getLicense()->expires_at;
     * } else {
     *     echo "Error: " . $result->getMessage();
     * }
     * ```
     * 
     * Example 2: Subsequent Calls (offline-first default)
     * ```php
     * // Loads from cached .lic file first, falls back to API if needed
     * $result = $client->validateLicense('LIC-2024-ABC123', 'example.com');
     * ```
     * 
     * Example 3: Skip Cache and Force Fresh API Call
     * ```php
     * $result = $client->validateLicense(
     *     'LIC-2024-ABC123',
     *     'example.com',
     *     null,
     *     ValidationType::OFFLINE_FIRST,
     *     ['NO_CACHE' => true]  // Force fresh API validation
     * );
     * ```
     * 
     * Example 4: Custom Timeout for Slow Networks
     * ```php
     * $result = $client->validateLicense(
     *     'LIC-2024-ABC123',
     *     'example.com',
     *     null,
     *     ValidationType::OFFLINE_FIRST,
     *     ['TIMEOUT' => 30]  // 30 second timeout
     * );
     * ```
     * 
     * STRATEGY EXPLANATION:
     * - ValidationType::OFFLINE_FIRST (default): Try offline .lic file first, fall back to API
     * - ValidationType::FORCE_API: Always call API directly (slower but guarantees fresh data)
     * 
     * CONFIGURATION INHERITANCE:
     * If not provided, these are inherited from Configuration:
     * - publicKey from config.getProductPublicKey()
     * - identifier from config.getDefaultIdentifier() or auto-generated
     * - cache TTL from config.getCacheTtl()
     * 
     * @see ValidationResultDto
     * @see Constants\ValidationType
     * @see Constants\OptionKeys
     * @see OptionKeysDocumentation::getValidationOptions()
     */
    public function validateLicense(
        string $licenseKey,
        string $identifier = '',
        ?string $publicKey = null,
        bool $force = ValidationType::OFFLINE_FIRST,
        array $options = []
    ): ValidationResultDto {
        // Auto-generate identifier if empty (default behavior)
        if (empty($identifier)) {
            $identifier = $this->getIdentifierOrGenerate();
        }

        // Resolve public key from config if not provided
        $publicKey = $publicKey ?? $this->config->getProductPublicKey();

        return $this->getValidator()->validateLicense(
            $licenseKey,
            $identifier,
            $publicKey,
            $force,
            $options
        );
    }

    /**
     * Resolve License State with Offline-First Validation
     * 
     * MANDATORY PARAMETERS:
     * @param string $licenseKey License key
     * @param string $identifier Domain or hardware ID (auto-generated if empty)
     *
     * OPTIONAL PARAMETERS:
     * @param ?string $publicKey Product public key (inherits from config)
     * @param bool $force ValidationType::FORCE_API or ValidationType::OFFLINE_FIRST (default)
     * @param array $options Optional parameters (CACHE_TTL, TIMEOUT, METADATA, NO_CACHE)
     * 
     * @return LicenseState License state object for capability checking
     * @throws LicenseException
     */
    public function resolveLicenseState(
        string $licenseKey,
        string $identifier = '',
        ?string $publicKey = null,
        bool $force = ValidationType::OFFLINE_FIRST,
        array $options = []
    ): LicenseState {
        // Auto-generate identifier if empty
        if (empty($identifier)) {
            $identifier = $this->getIdentifierOrGenerate();
        }

        // Resolve public key from config if not provided
        $publicKey = $publicKey ?? $this->config->getProductPublicKey();

        return $this->getValidator()->resolveLicenseState(
            $licenseKey,
            $identifier,
            $publicKey,
            $force,
            $options
        );
    }

    /**
     * Check if Feature is Allowed (State-Based Authorization)
     * 
     * Validates license state and checks if the specified feature is allowed.
     * Returns boolean (never throws exception) - perfect for safe feature-gating.
     * 
     * MANDATORY PARAMETERS:
     * @param string $licenseKey License key
     * @param string $feature Feature name to check (e.g., 'api_access', 'downloads', 'updates')
     * @param string $identifier Domain or hardware ID (auto-generated if empty)
     *
     * OPTIONAL PARAMETERS:
     * @param ?string $publicKey Product public key (inherits from config)
     * @param bool $force ValidationType::FORCE_API or ValidationType::OFFLINE_FIRST (default)
     * @param array $options Optional parameters:
     *                        - CACHE_TTL: cache override
     *                        - TIMEOUT: request timeout
     *                        - METADATA: additional metadata
     *                        - NO_CACHE: force fresh API call
     * 
     * @return bool True if feature is allowed; false if denied or any error occurs
     * 
     * BEHAVIOR & SAFETY:
     * - Returns false on ANY error (network, invalid license, signature mismatch, etc.)
     * - Never throws exceptions - safe for nested calls and feature gates
     * - Supports cache for high-performance feature checks
     * - Fail-secure: defaults to deny if validation cannot be completed
     * 
     * USAGE EXAMPLES:
     * 
     * Example 1: Simple Feature Gate
     * ```php
     * if ($client->isFeatureAllowed($licenseKey, 'api_access', $identifier)) {
     *     // Enable API endpoints
     *     enableApiRoutes();
     * } else {
     *     // Feature not available
     *     returnErrorResponse(403, 'API access requires upgrade');
     * }
     * ```
     * 
     * Example 2: Multiple Features
     * ```php
     * $requiredFeatures = ['api_access', 'custom_branding'];
     * foreach ($requiredFeatures as $feature) {
     *     if (!$client->isFeatureAllowed($licenseKey, $feature, $identifier)) {
     *         return false;  // Missing required feature\n     *     }
     * }
     * return true;  // All required features available
     * ```
     * 
     * Example 3: Admin Portal (Force Fresh Check)
     * ```php
     * // Use ValidationType::FORCE_API for critical admin operations
     * if ($client->isFeatureAllowed(
     *     $licenseKey,
     *     'admin_portal',
     *     $identifier,
     *     null,
     *     ValidationType::FORCE_API  // Don't rely on cache for admin access
     * )) {
     *     showAdminPanel();
     * } else {
     *     denyAdminAccess();
     * }
     * ```
     * 
     * COMMON FEATURES:
     * - 'api_access': REST API endpoint access
     * - 'updates': Product update downloads
     * - 'downloads': Documentation and asset downloads
     * - 'analytics': Analytics dashboard
     * - 'priority_support': Priority support queue
     * - 'custom_branding': Custom branding features
     * - 'advanced_settings': Advanced configuration options
     * 
     * PERFORMANCE:
     * - Cached results are used by default (fast)
     * - Use NO_CACHE option to force fresh check if needed
     * - Safe to call in loops (cache prevents API spam)
     * 
     * @see Constants\ValidationType
     * @see Constants\OptionKeys
     * @see OptionKeysDocumentation::getValidationOptions()
     */
    public function isFeatureAllowed(
        string $licenseKey,
        string $feature,
        string $identifier = '',
        ?string $publicKey = null,
        bool $force = ValidationType::OFFLINE_FIRST,
        array $options = []
    ): bool {
        try {
            // Auto-generate identifier if empty
            if (empty($identifier)) {
                $identifier = $this->getIdentifierOrGenerate();
            }

            // Resolve public key from config if not provided
            $publicKey = $publicKey ?? $this->config->getProductPublicKey();

            return $this->getValidator()->isFeatureAllowed(
                $licenseKey,
                $feature,
                $identifier,
                $publicKey,
                $force,
                $options
            );
        } catch (Exception $e) {
            // Feature denied on any validation error (fail-secure)
            return false;
        }
    }

    /**
     * Get License State (Safe, No Exceptions)
     * 
     * Convenience method that returns license state without throwing exceptions
     * on validation failure (returns restricted state instead).
     * 
     * MANDATORY PARAMETERS:
     * @param string $licenseKey License key
     * @param string $identifier Domain or hardware ID (auto-generated if empty)
     *
     * OPTIONAL PARAMETERS:
     * @param ?string $publicKey Product public key (inherits from config)
     * @param bool $force ValidationType::FORCE_API or ValidationType::OFFLINE_FIRST (default)
     * @param array $options Optional parameters
     * 
     * @return LicenseState License state object (never throws)
     */
    public function getLicenseState(
        string $licenseKey,
        string $identifier = '',
        ?string $publicKey = null,
        bool $force = ValidationType::OFFLINE_FIRST,
        array $options = []
    ): LicenseState {
        // Auto-generate identifier if empty
        if (empty($identifier)) {
            $identifier = $this->getIdentifierOrGenerate();
        }

        // Resolve public key from config if not provided
        $publicKey = $publicKey ?? $this->config->getProductPublicKey();

        return $this->getValidator()->getLicenseState($licenseKey, $identifier, $publicKey, $force, $options);
    }

    /**
     * Require Capability for Operation
     * 
     * Validates license state and requires a specific capability.
     * 
     * MANDATORY PARAMETERS:
     * @param string $licenseKey License key
     * @param string $capability Required capability (e.g., 'updates', 'downloads')
     *
     * @return LicenseState License state object (throws if capability denied)
     * @throws StateException If capability not allowed
     */
    public function requireCapability(string $licenseKey, string $capability): LicenseState
    {
        return $this->getValidator()->requireCapability($licenseKey, $capability);
    }

    public function clearLicenseState(string $licenseKey): void
    {
        $this->getValidator()->clearLicenseState($licenseKey);
    }

    /**
     * Activate License on Device or Domain
     * 
     * Creates a new activation record for the license on the specified device/domain.
     * Returns cryptographically signed activation confirmation that can be stored for offline verification.
     * 
     * MANDATORY PARAMETERS:
     * @param string $licenseKey License key to activate
     * @param string $identifier Domain (web apps) or hardware ID (desktop/server) - auto-generated if empty
     *
     * OPTIONAL PARAMETERS:
     * @param ?string $publicKey Product public key (inherits from config)
     * @param array $options Activation options:
     *                        - IDEMPOTENCY_KEY: UUID for idempotent requests (auto-generated if omitted)
     *                        - OS: Operating system (auto-detected if omitted)
     *                        - PRODUCT_VERSION: Product version string
     *                        - IP: Client IP address
     *                        - DOMAIN: Activation domain (if different from identifier)
     *                        - METADATA: Additional metadata (array)
     * 
     * @return ActivationResultDto Result with:
     *         - isSuccess(): bool - Whether activation was successful
     *         - getActivationId(): string - Unique activation identifier
     *         - getIdentifier(): string - The identifier this was activated on
     *         - getActivatedAt(): string - ISO 8601 timestamp of activation
     *         - getLicense(): LicenseDataDto - Updated license data
     * 
     * @throws InvalidArgumentException If identifier is empty and cannot be auto-generated
     * @throws LicenseException If activation fails (network, invalid key, etc.)
     * 
     * USAGE EXAMPLES:
     * 
     * Example 1: Basic Activation
     * ```php
     * $result = $client->activateLicense(
     *     'LIC-2024-ABC123',
     *     'office-workstation-01.example.com'
     * );
     * 
     * if ($result->isSuccess()) {
     *     echo "Activated! ID: " . $result->getActivationId();
     *     // Store identifier for later deactivation
     *     saveActivationRecord($licenseKey, $result->getIdentifier());
     * } else {
     *     echo "Activation failed: " . $result->getMessage();
     * }
     * ```
     * 
     * Example 2: Activation with System Info
     * ```php
     * $result = $client->activateLicense(
     *     'LIC-2024-ABC123',
     *     'workstation-01.example.com',
     *     null,
     *     [
     *         'OS' => 'Windows Server 2022',
     *         'PRODUCT_VERSION' => '2.5.1',
     *         'IP' => $_SERVER['REMOTE_ADDR'],
     *         'METADATA' => [
     *             'department' => 'Engineering',
     *             'cost_center' => 'CC-1234'
     *         ]
     *     ]
     * );
     * ```
     * 
     * Example 3: Idempotent Activation (Safe Retry)
     * ```php
     * // Use same IDEMPOTENCY_KEY to ensure same result on retry
     * $idempotencyKey = $client->generateUuid();
     * $result = $client->activateLicense(
     *     'LIC-2024-ABC123',
     *     'workstation-01.example.com',
     *     null,
     *     ['IDEMPOTENCY_KEY' => $idempotencyKey]
     * );
     * 
     * // Later retry with same key returns same activation ID (not duplicate)
     * $retry = $client->activateLicense(
     *     'LIC-2024-ABC123',
     *     'workstation-01.example.com',
     *     null,
     *     ['IDEMPOTENCY_KEY' => $idempotencyKey]
     * );
     * assert($result->getActivationId() === $retry->getActivationId());
     * ```
     * 
     * ERROR HANDLING:
     * 
     * Common Error Cases:
     * - "Activation limit reached": License has max activations already in use
     * - "License not found": Invalid license key
     * - "Network error": API unreachable
     * - "Identifier binding mismatch": Hardware ID doesn't match license binding
     * 
     * Recovery Strategies:
     * - If activation limit reached, customer must deactivate from another device
     * - For network errors, use idempotent retry (same IDEMPOTENCY_KEY)
     * - For binding errors, verify the identifier matches the license type
     * 
     * PERFORMANCE:
     * - Typically completes in <500ms on good network
     * - Supports optional timeout parameter via options
     * - No caching (always calls API)
     * 
     * @see ActivationResultDto
     * @see Constants\OptionKeys
     * @see OptionKeysDocumentation::getActivationOptions()
     */
    public function activateLicense(
        string $licenseKey,
        string $identifier = '',
        ?string $publicKey = null,
        array $options = []
    ): ActivationResultDto {
        // Auto-generate identifier if empty
        if (empty($identifier)) {
            $identifier = $this->getIdentifierOrGenerate();
        }

        // Resolve public key from config if not provided
        $publicKey = $publicKey ?? $this->config->getProductPublicKey();

        return $this->getValidator()->activateLicense(
            $licenseKey,
            $identifier,
            $publicKey,
            $options
        );
    }

    /**
     * Deactivate License from Device or Domain
     * 
     * Removes an activation from the specified device/domain. The license remains valid
     * for other activations or for future reactivation on the same/different device.
     * 
     * MANDATORY PARAMETERS:
     * @param string $licenseKey License key to deactivate
     * @param string $identifier Domain or hardware ID to deactivate from - auto-generated if empty
     *
     * OPTIONAL PARAMETERS:
     * @param array $options Deactivation options:
     *                        - IDEMPOTENCY_KEY: UUID for idempotent requests (auto-generated if omitted)
     *                        - METADATA: Additional metadata
     * 
     * @return ActivationResultDto Result with deactivation confirmation
     *         - isSuccess(): bool - Whether deactivation was successful
     *         - getIdentifier(): string - The identifier that was deactivated
     *         - getLicense(): LicenseDataDto - Updated license data
     * 
     * @throws InvalidArgumentException If identifier is empty and cannot be auto-generated
     * @throws LicenseException If deactivation fails
     * 
     * USAGE EXAMPLES:
     * 
     * Example 1: Simple Deactivation
     * ```php
     * $result = $client->deactivateLicense(
     *     'LIC-2024-ABC123',
     *     'workstation-01.example.com'
     * );
     * 
     * if ($result->isSuccess()) {
     *     echo "Deactivated from: " . $result->getIdentifier();
     * } else {
     *     echo "Deactivation failed: " . $result->getMessage();
     * }
     * ```
     * 
     * Example 2: Deactivation with Audit Trail
     * ```php
     * try {
     *     $result = $client->deactivateLicense(
     *         $licenseKey,
     *         $identifier,
     *         ['METADATA' => ['reason' => 'user_upgrade']]
     *     );
     *     
     *     if ($result->isSuccess()) {
     *         // Log deactivation for compliance
     *         logAuditEvent('license_deactivated', [
     *             'license_key' => $licenseKey,
     *             'identifier' => $result->getIdentifier(),
     *             'timestamp' => $result->getActivatedAt()
     *         ]);
     *     }
     * } catch (LicenseException $e) {
     *     handleDeactivationError($e);
     * }
     * ```
     * 
     * Example 3: Idempotent Deactivation
     * ```php
     * // Use same IDEMPOTENCY_KEY to ensure safe retry
     * $options = ['IDEMPOTENCY_KEY' => $storedIdempotencyKey];
     * 
     * $result = $client->deactivateLicense($licenseKey, $identifier, $options);
     * // If network error on first call, retry with same key - no duplicate deactivation
     * ```
     * 
     * USE CASES:
     * 
     * 1. User Uninstall
     *    - Call deactivateLicense during uninstall process
     *    - Frees activation slot for other devices
     * 
     * 2. Hardware Upgrade
     *    - Deactivate from old machine
     *    - Activate on new machine
     *    - Seamless transition
     * 
     * 3. License Downgrade
     *    - Customer wants to reduce activations
     *    - Deactivate from selected devices
     * 
     * 4. Device Replacement
     *    - Old device no longer used
     *    - Deactivate to free slot
     *    - Activate on replacement device
     * 
     * ERROR HANDLING:
     * 
     * Common Errors:
     * - "Activation not found": Identifier was never activated or already deactivated
     * - "Network error": API unreachable
     * - "Permission denied": Not authorized to deactivate
     * 
     * Recovery:
     * - Use IDEMPOTENCY_KEY for safe retry on network errors
     * - If activation not found, verify the correct identifier
     * - Check license status with validateLicense()
     * 
     * @see ActivationResultDto
     * @see Constants\OptionKeys
     * @see OptionKeysDocumentation::getActivationOptions()
     */
    public function deactivateLicense(
        string $licenseKey,
        string $identifier = '',
        array $options = []
    ): ActivationResultDto {
        // Auto-generate identifier if empty
        if (empty($identifier)) {
            $identifier = $this->getIdentifierOrGenerate();
        }

        return $this->getValidator()->deactivateLicense(
            $licenseKey,
            $identifier,
            $options
        );
    }

    public function validateOfflineLicense($offlineLicenseData, array $options = []): array
    {
        return $this->getValidator()->validateOfflineLicense($offlineLicenseData, $options);
    }

    /**
     * Get License File for Offline Validation
     * 
     * Retrieve .lic file content for offline license validation. Returns base64 encoded
     * license file with cryptographic signature.
     * 
     * MANDATORY PARAMETERS:\n     * @param string $licenseKey License key
     * @param string $identifier Domain or hardware ID (auto-generated if empty)
     *
     * OPTIONAL PARAMETERS:
     * @param array $options Optional parameters (METADATA)
     * 
     * @return array License file result with licFileContent
     * @throws InvalidArgumentException
     * @throws LicenseException
     */
    public function getLicenseFile(
        string $licenseKey,
        string $identifier = '',
        array $options = []
    ): array {
        // Auto-generate identifier if empty
        if (empty($identifier)) {
            $identifier = $this->getIdentifierOrGenerate();
        }

        return $this->getValidator()->getLicenseFile($licenseKey, $identifier, $options);
    }

    /**
     * Synchronize License and Key Files with Server
     * 
     * Atomically updates local .lic and .key files with latest data from server.
     * 
     * MANDATORY PARAMETERS:
     * @param string $licPath Absolute path to local .lic file
     * @param string $keyPath Absolute path to local .key/.pem file
     *
     * OPTIONAL PARAMETERS:
     * @param bool $force If true, force fresh sync; default false (check first)
     * 
     * @return SyncResultDto Result object with success, error, and warning tracking
     * @throws Exception If file paths are invalid
     * 
     * EXAMPLE:
     * ```php
     * $result = $client->syncLicenseAndKey('/var/app/license.lic', '/var/app/license.key');
     * if ($result->isSuccess()) {
     *     echo \"Files synced: \" . $result->getSummary();
     * }
     * ```
     */
    public function syncLicenseAndKey(
        string $licPath,
        string $keyPath,
        bool $force = false
    ): SyncResultDto {
        return $this->getValidator()->syncLicenseAndKey($licPath, $keyPath, $force);
    }

    // ========================================================================
    // FEATURE CHECKING (delegate to FeatureChecker)
    // ========================================================================

    public function checkFeature(string $licenseKey, string $featureName): array
    {
        return $this->getFeatureChecker()->checkFeature($licenseKey, $featureName);
    }

    // ========================================================================
    // LICENSE MANAGEMENT (delegate to LicenseManager)
    // ========================================================================

    public function createLicenseKeys(string $productUuid, string $generatorUuid, array $licenses, ?string $customerEmail = null, array $options = []): array
    {
        return $this->getLicenseManager()->createLicenseKeys($productUuid, $generatorUuid, $licenses, $customerEmail, $options);
    }

    public function updateLicenseKey(string $licenseKey, array $options = []): array
    {
        return $this->getLicenseManager()->updateLicenseKey($licenseKey, $options);
    }

    public function deleteLicenseKey(string $licenseKey): array
    {
        return $this->getLicenseManager()->deleteLicenseKey($licenseKey);
    }

    public function getLicenseKeys(array $filters = []): array
    {
        return $this->getLicenseManager()->getLicenseKeys($filters);
    }

    public function getLicenseDetails(string $licenseKey): array
    {
        return $this->getLicenseManager()->getLicenseDetails($licenseKey);
    }

    public function getAvailableLicenseKeysCount(string $productUuid): array
    {
        return $this->getLicenseManager()->getAvailableLicenseKeysCount($productUuid);
    }

    public function assignLicenseKey(string $licenseKey, string $customerEmail, ?string $customerName = null): array
    {
        return $this->getLicenseManager()->assignLicenseKey($licenseKey, $customerEmail, $customerName);
    }

    public function randomAssignLicenseKeys(string $productUuid, string $generatorUuid, int $quantity, string $customerEmail, ?string $customerName = null, array $options = []): array
    {
        return $this->getLicenseManager()->randomAssignLicenseKeys($productUuid, $generatorUuid, $quantity, $customerEmail, $customerName, $options);
    }

    public function randomAssignLicenseKeysQueued(string $productUuid, string $generatorUuid, int $quantity, string $customerEmail, ?string $customerName = null, array $options = []): array
    {
        return $this->getLicenseManager()->randomAssignLicenseKeysQueued($productUuid, $generatorUuid, $quantity, $customerEmail, $customerName, $options);
    }

    public function assignAndActivateLicenseKey(string $licenseKey, string $customerEmail, string $identifier, array $options = []): array
    {
        return $this->getLicenseManager()->assignAndActivateLicenseKey($licenseKey, $customerEmail, $identifier, $options);
    }

    public function createLicenseKeyMeta(string $licenseKey, string $metaKey, $metaValue): array
    {
        return $this->getLicenseManager()->createLicenseKeyMeta($licenseKey, $metaKey, $metaValue);
    }

    public function updateLicenseKeyMeta(string $licenseKey, string $metaKey, $metaValue): array
    {
        return $this->getLicenseManager()->updateLicenseKeyMeta($licenseKey, $metaKey, $metaValue);
    }

    public function deleteLicenseKeyMeta(string $licenseKey, string $metaKey): array
    {
        return $this->getLicenseManager()->deleteLicenseKeyMeta($licenseKey, $metaKey);
    }

    // ========================================================================
    // PRODUCT MANAGEMENT (delegate to ProductManager)
    // ========================================================================

    public function createProduct(string $name, array $options = []): array
    {
        return $this->getProductManager()->createProduct($name, $options);
    }

    public function updateProduct(string $productUuid, array $options = []): array
    {
        return $this->getProductManager()->updateProduct($productUuid, $options);
    }

    public function deleteProduct(string $productUuid): array
    {
        return $this->getProductManager()->deleteProduct($productUuid);
    }

    public function getAllProducts(): array
    {
        return $this->getProductManager()->getAllProducts();
    }

    public function createProductMeta(string $productUuid, string $metaKey, $metaValue): array
    {
        return $this->getProductManager()->createProductMeta($productUuid, $metaKey, $metaValue);
    }

    public function updateProductMeta(string $productUuid, string $metaKey, $metaValue): array
    {
        return $this->getProductManager()->updateProductMeta($productUuid, $metaKey, $metaValue);
    }

    public function deleteProductMeta(string $productUuid, string $metaKey): array
    {
        return $this->getProductManager()->deleteProductMeta($productUuid, $metaKey);
    }

    // ========================================================================
    // CONTRACT MANAGEMENT (delegate to ContractManager)
    // ========================================================================

    public function getAllContracts(): array
    {
        return $this->getContractManager()->getAllContracts();
    }

    public function createContract(array $contractData, array $options = []): array
    {
        return $this->getContractManager()->createContract($contractData, $options);
    }

    public function updateContract(int $contractId, array $contractData): array
    {
        return $this->getContractManager()->updateContract($contractId, $contractData);
    }

    public function deleteContract(int $contractId): array
    {
        return $this->getContractManager()->deleteContract($contractId);
    }

    // ========================================================================
    // DOWNLOADS (delegate to DownloadManager)
    // ========================================================================

    public function accessDownloadables(string $licenseKey, array $options = []): array
    {
        return $this->getDownloadManager()->accessDownloadables($licenseKey, $options);
    }

    public function getProductChangelog(string $slug): array
    {
        return $this->getDownloadManager()->getProductChangelog($slug);
    }

    public function getAllGenerators(?string $productUuid = null): array
    {
        return $this->getDownloadManager()->getAllGenerators($productUuid);
    }

    public function generateLicenseKeys(string $generatorUuid, int $quantity, array $options = []): array
    {
        return $this->getDownloadManager()->generateLicenseKeys($generatorUuid, $quantity, $options);
    }

    // ========================================================================
    // TELEMETRY (delegate to TelemetryClient)
    // ========================================================================

    public function sendTelemetry(string $dataType, string $dataGroup, array $dataValues = [], array $options = []): array
    {
        return $this->getTelemetryClient()->sendTelemetry($dataType, $dataGroup, $dataValues, $options);
    }

    public function getTelemetryData(string $dataType, string $dataGroup, array $filters = []): array
    {
        return $this->getTelemetryClient()->getTelemetryData($dataType, $dataGroup, $filters);
    }

    // ========================================================================
    // UTILITY METHODS (kept in LicenseClient)
    // ========================================================================

    public function generateHardwareId(): string
    {
        $identifiers = [];
        if (function_exists('php_uname')) {
            $identifiers[] = php_uname('n');
            $identifiers[] = php_uname('m');
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

    public function clearCache(): void
    {
        $this->getCacheManager()->clear();
    }

    public function clearLicenseCache(string $licenseKey): void
    {
        $this->getCacheManager()->clearByPattern("license:{$licenseKey}:*");
    }

    public function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    // ========================================================================
    // HELPER METHODS FOR IDENTIFIERS & CONFIGURATION INHERITANCE
    // ========================================================================

    /**
     * Generate appropriate identifier for current environment
     * 
     * Automatically detects context and generates suitable identifier:
     * - Web request: domain from $_SERVER['HTTP_HOST']
     * - CLI/Background: hardware ID
     * 
     * MANDATORY PARAMETERS:
     * @param string $type Identifier type (use Constants\IdentifierType)
     *
     * OPTIONAL PARAMETERS:
     * @param string|null $override Custom identifier to use instead of generating
     *
     * @return string Generated or provided identifier
     *
     * @throws InvalidArgumentException If type is invalid
     *
     * EXAMPLES:
     * - Auto-detect: generateIdentifier(IdentifierType::AUTO)
     * - Domain: generateIdentifier(IdentifierType::DOMAIN)
     * - Hardware: generateIdentifier(IdentifierType::HARDWARE)
     * 
     * @see Constants\IdentifierType
     */
    public function generateIdentifier(string $type = 'auto', ?string $override = null): string
    {
        // Return override if provided
        if (!empty($override)) {
            return $override;
        }

        // Validate type
        if (!IdentifierType::isValid($type)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid identifier type: %s. Valid types: %s',
                    $type,
                    implode(', ', IdentifierType::all())
                )
            );
        }

        // Domain identifier (for web applications)
        if ($type === IdentifierType::DOMAIN || $type === IdentifierType::AUTO && isset($_SERVER['HTTP_HOST'])) {
            return $_SERVER['HTTP_HOST'] ?? gethostname();
        }

        // Hardware identifier (for on-premises/desktop)
        if ($type === IdentifierType::HARDWARE || $type === IdentifierType::AUTO) {
            return $this->generateHardwareId();
        }

        // Fallback
        return gethostname();
    }

    /**
     * Get identifier from configuration or generate new one
     * 
     * MANDATORY PARAMETERS:
     * @param string $type Identifier type to generate
     *
     * OPTIONAL PARAMETERS:
     * @param string|null $override Override configuration value
     *
     * @return string Identifier (from config or generated)
     *
     * LOGIC:
     * 1. Use $override if provided and not empty
     * 2. Use configured defaultIdentifier if available
     * 3. Generate new identifier based on $type
     * 
     * @see Constants\IdentifierType
     */
    public function getIdentifierOrGenerate(string $type = 'auto', ?string $override = null): string
    {
        // Use override if provided
        if (!empty($override)) {
            return $override;
        }

        // Try configuration
        $configured = $this->config->getDefaultIdentifier();
        if (!empty($configured)) {
            return $configured;
        }

        // Generate new
        return $this->generateIdentifier($type);
    }

    /**
     * Resolve public key for offline validation
     * 
     * MANDATORY PARAMETERS:
     * @param string|null $override Override public key
     *
     * @return string Public key content
     *
     * @throws \InvalidArgumentException If no public key available
     *
     * LOGIC:
     * 1. Use $override if provided and not empty
     * 2. Use configured productPublicKey if available
     * 3. Throw error with helpful message
     * 
     * ERROR MESSAGE:
     * Actionable error shows:
     * - What is required
     * - How to provide it  
     * - Documentation URL
     */
    public function resolvePublicKey(?string $override = null): string
    {
        // Use override if provided
        if (!empty($override)) {
            return $override;
        }

        // Try configuration
        $configured = $this->config->getProductPublicKey();
        if (!empty($configured)) {
            return $configured;
        }

        // Error - public key required
        throw new \InvalidArgumentException(
            'Product public key is required but not provided. ' .
            'Pass it as parameter or configure via: ' .
            'new LicenseClient([\'productPublicKey\' => file_get_contents(\'storage_path:app/keys/product_public.pem\')]). ' .
            'The public key is needed for offline .lic file validation and signature verification. ' .
            'See: https://docs.getkeymanager.com/php-sdk#configuration'
        );
    }

    /**
     * Check if offline validation is possible
     * 
     * Returns true if:
     * - Public key is configured or provided
     * - License file path is configured
     * - .lic file exists
     * 
     * @return bool True if offline validation possible
     */
    public function canValidateOffline(): bool
    {
        try {
            $publicKey = $this->config->getProductPublicKey();
            if (empty($publicKey)) {
                return false;
            }

            $licPath = $this->config->getLicenseFilePath();
            if (empty($licPath)) {
                return false;
            }

            return file_exists($licPath) && is_readable($licPath);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get Configuration instance for direct access
     * 
     * @return Configuration Configuration object
     */
    public function getConfiguration(): Configuration
    {
        return $this->config;
    }
}
