<?php

declare(strict_types=1);

namespace GetKeyManager\SDK;

use GetKeyManager\SDK\Config\Configuration;
use GetKeyManager\SDK\Downloads\DownloadManager;
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
 * @version 2.0.0
 * @license MIT
 */
class LicenseClient
{
    private const VERSION = '2.0.0';

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

    public function validateLicense(string $licenseKey, array $options = []): array
    {
        return $this->getValidator()->validateLicense($licenseKey, $options);
    }

    public function resolveLicenseState(string $licenseKey, array $options = []): LicenseState
    {
        return $this->getValidator()->resolveLicenseState($licenseKey, $options);
    }

    public function isFeatureAllowed(string $licenseKey, string $feature): bool
    {
        return $this->getValidator()->isFeatureAllowed($licenseKey, $feature);
    }

    public function getLicenseState(string $licenseKey, array $options = []): LicenseState
    {
        return $this->getValidator()->getLicenseState($licenseKey, $options);
    }

    public function requireCapability(string $licenseKey, string $capability): LicenseState
    {
        return $this->getValidator()->requireCapability($licenseKey, $capability);
    }

    public function clearLicenseState(string $licenseKey): void
    {
        $this->getValidator()->clearLicenseState($licenseKey);
    }

    public function activateLicense(string $licenseKey, array $options = []): array
    {
        return $this->getValidator()->activateLicense($licenseKey, $options);
    }

    public function deactivateLicense(string $licenseKey, array $options = []): array
    {
        return $this->getValidator()->deactivateLicense($licenseKey, $options);
    }

    public function validateOfflineLicense($offlineLicenseData, array $options = []): array
    {
        return $this->getValidator()->validateOfflineLicense($offlineLicenseData, $options);
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

    public function accessDownloadables(string $licenseKey, string $identifier): array
    {
        return $this->getDownloadManager()->accessDownloadables($licenseKey, $identifier);
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
}
