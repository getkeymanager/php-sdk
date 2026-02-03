<?php

declare(strict_types=1);

namespace GetKeyManager\SDK\Management;

use GetKeyManager\SDK\Config\Configuration;
use GetKeyManager\SDK\Http\HttpClient;
use GetKeyManager\SDK\Cache\CacheManager;
use GetKeyManager\SDK\LicenseException;
use InvalidArgumentException;

/**
 * Product Manager
 * 
 * Handles product CRUD operations and metadata.
 * 
 * @package GetKeyManager\SDK\Management
 */
class ProductManager
{
    private Configuration $config;
    private HttpClient $httpClient;
    private CacheManager $cacheManager;

    /**
     * Initialize product manager
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

        if (isset($options['changelog_enabled'])) {
            $payload['changelog_enabled'] = $options['changelog_enabled'];
        }

        if (isset($options['image'])) {
            $payload['image'] = $options['image'];
        }

        if (isset($options['features'])) {
            $payload['features'] = $options['features'];
        }

        $idempotencyKey = $options['idempotencyKey'] ?? $this->generateUuid();

        $response = $this->httpClient->request(
            'POST',
            '/v1/create-product',
            $payload,
            ['Idempotency-Key' => $idempotencyKey]
        );

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

        if (isset($options['slug'])) {
            $payload['slug'] = $options['slug'];
        }

        if (isset($options['description'])) {
            $payload['description'] = $options['description'];
        }

        if (isset($options['status'])) {
            $payload['status'] = $options['status'];
        }

        if (isset($options['changelog_enabled'])) {
            $payload['changelog_enabled'] = $options['changelog_enabled'];
        }

        if (isset($options['features'])) {
            $payload['features'] = $options['features'];
        }

        $response = $this->httpClient->request('POST', '/v1/update-product', $payload);

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

        $response = $this->httpClient->request('POST', '/v1/delete-product', [
            'product_uuid' => $productUuid
        ]);

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
        $cacheKey = $this->cacheManager->generateKey('products', 'all');
        if ($cached = $this->cacheManager->get($cacheKey)) {
            return $cached;
        }

        $response = $this->httpClient->request('GET', '/v1/get-all-products');

        $this->cacheManager->set($cacheKey, $response);

        return $response;
    }

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

        $response = $this->httpClient->request('POST', '/v1/create-product-meta', [
            'product_uuid' => $productUuid,
            'meta_key' => $metaKey,
            'meta_value' => $metaValue
        ]);

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

        $response = $this->httpClient->request('POST', '/v1/update-product-meta', [
            'product_uuid' => $productUuid,
            'meta_key' => $metaKey,
            'meta_value' => $metaValue
        ]);

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

        $response = $this->httpClient->request('POST', '/v1/delete-product-meta', [
            'product_uuid' => $productUuid,
            'meta_key' => $metaKey
        ]);

        return $response;
    }

    /**
     * Get product metadata
     * 
     * Retrieves all product metadata as key-value pairs
     * 
     * @param string $productUuid Product UUID
     * @return array Product metadata
     * @throws LicenseException
     */
    public function getProductMeta(string $productUuid): array
    {
        if (empty($productUuid)) {
            throw new InvalidArgumentException('Product UUID is required');
        }

        $cacheKey = $this->cacheManager->generateKey('product_meta', $productUuid);
        if ($cached = $this->cacheManager->get($cacheKey)) {
            return $cached;
        }

        $response = $this->httpClient->request('GET', '/v1/get-product-meta', [
            'product_uuid' => $productUuid
        ]);

        $this->cacheManager->set($cacheKey, $response);

        return $response;
    }

    /**
     * Get product with features
     * 
     * Retrieve individual product information with all features.
     * Accepts product_uuid or product_slug.
     * 
     * @param array $options Either ['product_uuid' => '...'] or ['product_slug' => '...']
     * @return array Product information with features
     * @throws LicenseException
     */
    public function getProduct(array $options): array
    {
        if (empty($options['product_uuid']) && empty($options['product_slug'])) {
            throw new InvalidArgumentException('Either product_uuid or product_slug is required');
        }

        $params = [];
        if (!empty($options['product_uuid'])) {
            $params['product_uuid'] = $options['product_uuid'];
        } elseif (!empty($options['product_slug'])) {
            $params['product_slug'] = $options['product_slug'];
        }

        $cacheKey = $this->cacheManager->generateKey('product', json_encode($params));
        if ($cached = $this->cacheManager->get($cacheKey)) {
            return $cached;
        }

        $response = $this->httpClient->request('GET', '/v1/get-product', $params);

        $this->cacheManager->set($cacheKey, $response);

        return $response;
    }

    /**
     * Get product with changelog
     * 
     * Retrieve product information with changelog entries.
     * Accepts product_uuid or product_slug.
     * 
     * @param array $options Either ['product_uuid' => '...'] or ['product_slug' => '...']
     * @return array Product information with changelog
     * @throws LicenseException
     */
    public function getProductChangelog(array $options): array
    {
        if (empty($options['product_uuid']) && empty($options['product_slug'])) {
            throw new InvalidArgumentException('Either product_uuid or product_slug is required');
        }

        $params = [];
        if (!empty($options['product_uuid'])) {
            $params['product_uuid'] = $options['product_uuid'];
        } elseif (!empty($options['product_slug'])) {
            $params['product_slug'] = $options['product_slug'];
        }

        $cacheKey = $this->cacheManager->generateKey('product_changelog', json_encode($params));
        if ($cached = $this->cacheManager->get($cacheKey)) {
            return $cached;
        }

        $response = $this->httpClient->request('GET', '/v1/get-product-changelog', $params);

        $this->cacheManager->set($cacheKey, $response);

        return $response;
    }

    /**
     * Get product public key
     * 
     * Retrieve product's cryptographic public key for signature verification.
     * Accepts product_uuid or product_slug.
     * 
     * @param array $options Either ['product_uuid' => '...'] or ['product_slug' => '...']
     * @return array Product public key
     * @throws LicenseException
     */
    public function getProductPublicKey(array $options): array
    {
        if (empty($options['product_uuid']) && empty($options['product_slug'])) {
            throw new InvalidArgumentException('Either product_uuid or product_slug is required');
        }

        $params = [];
        if (!empty($options['product_uuid'])) {
            $params['product_uuid'] = $options['product_uuid'];
        } elseif (!empty($options['product_slug'])) {
            $params['product_slug'] = $options['product_slug'];
        }

        $cacheKey = $this->cacheManager->generateKey('product_public_key', json_encode($params));
        if ($cached = $this->cacheManager->get($cacheKey)) {
            return $cached;
        }

        $response = $this->httpClient->request('GET', '/v1/get-product-public-key', $params);

        $this->cacheManager->set($cacheKey, $response);

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
