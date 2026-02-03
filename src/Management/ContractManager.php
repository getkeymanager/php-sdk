<?php

declare(strict_types=1);

namespace GetKeyManager\SDK\Management;

use GetKeyManager\SDK\Config\Configuration;
use GetKeyManager\SDK\Http\HttpClient;
use GetKeyManager\SDK\Cache\CacheManager;
use GetKeyManager\SDK\LicenseException;
use InvalidArgumentException;

/**
 * Contract Manager
 * 
 * Handles contract CRUD operations.
 * 
 * @package GetKeyManager\SDK\Management
 */
class ContractManager
{
    private Configuration $config;
    private HttpClient $httpClient;
    private CacheManager $cacheManager;

    /**
     * Initialize contract manager
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
     * Get all contracts
     * 
     * @return array Contracts list
     * @throws LicenseException
     */
    public function getAllContracts(): array
    {
        $cacheKey = $this->cacheManager->generateKey('contracts', 'all');
        if ($cached = $this->cacheManager->get($cacheKey)) {
            return $cached;
        }

        $response = $this->httpClient->request('GET', '/v1/get-all-contracts');

        $this->cacheManager->set($cacheKey, $response);

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

        $response = $this->httpClient->request(
            'POST',
            '/v1/create-contract',
            $contractData,
            ['Idempotency-Key' => $idempotencyKey]
        );

        $this->cacheManager->clear();

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

        $response = $this->httpClient->request('POST', '/v1/update-contract', $contractData);

        $this->cacheManager->clear();

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

        $response = $this->httpClient->request('POST', '/v1/delete-contract', [
            'contract_id' => $contractId
        ]);

        $this->cacheManager->clear();

        return $response;
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
