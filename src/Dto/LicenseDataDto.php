<?php

declare(strict_types=1);

namespace GetKeyManager\SDK\Dto;

/**
 * License Data DTO
 * 
 * Represents parsed/decrypted license data from .lic files or API responses.
 * 
 * @package GetKeyManager\SDK\Dto
 */
class LicenseDataDto
{
    public string $license_key;
    public ?string $status = null;
    public ?string $expires_at = null;
    public ?array $features = [];
    public ?array $capabilities = [];
    public ?string $product_uuid = null;
    public ?string $product_name = null;
    public ?string $hardware_id = null;
    public ?string $domain = null;
    public ?array $metadata = [];
    public ?int $lastCheckedDate = null;
    public ?int $licenseCheckInterval = null;
    public ?int $forceLicenseValidation = null;
    public ?array $raw_data = [];

    /**
     * Create from array
     * 
     * @param array $data License data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $dto = new self();
        
        $dto->license_key = $data['license_key'] ?? $data['key'] ?? '';
        $dto->status = $data['status'] ?? null;
        $dto->expires_at = $data['expires_at'] ?? null;
        $dto->features = $data['features'] ?? $data['feature_flags'] ?? [];
        $dto->capabilities = $data['capabilities'] ?? [];
        $dto->product_uuid = $data['product_uuid'] ?? $data['product']['uuid'] ?? null;
        $dto->product_name = $data['product_name'] ?? $data['product']['name'] ?? null;
        $dto->hardware_id = $data['hardware_id'] ?? null;
        $dto->domain = $data['domain'] ?? null;
        $dto->metadata = $data['metadata'] ?? [];
        $dto->lastCheckedDate = $data['lastCheckedDate'] ?? null;
        $dto->licenseCheckInterval = $data['licenseCheckInterval'] ?? null;
        $dto->forceLicenseValidation = $data['forceLicenseValidation'] ?? null;
        $dto->raw_data = $data;
        
        return $dto;
    }

    /**
     * Convert to array
     * 
     * @return array DTO as array
     */
    public function toArray(): array
    {
        return [
            'license_key' => $this->license_key,
            'status' => $this->status,
            'expires_at' => $this->expires_at,
            'features' => $this->features,
            'capabilities' => $this->capabilities,
            'product_uuid' => $this->product_uuid,
            'product_name' => $this->product_name,
            'hardware_id' => $this->hardware_id,
            'domain' => $this->domain,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Check if license is expired
     * 
     * @return bool True if expired
     */
    public function isExpired(): bool
    {
        if (!$this->expires_at) {
            return false; // No expiry = never expires
        }

        try {
            $expiresAt = strtotime($this->expires_at);
            return $expiresAt !== false && $expiresAt < time();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if license has a specific feature
     * 
     * @param string $feature Feature name
     * @return bool True if feature enabled
     */
    public function hasFeature(string $feature): bool
    {
        if (is_array($this->features)) {
            return isset($this->features[$feature]) && $this->features[$feature] === true;
        }

        return false;
    }

    /**
     * Check if license has a specific capability
     * 
     * @param string $capability Capability name
     * @return bool True if capability allowed
     */
    public function hasCapability(string $capability): bool
    {
        if (is_array($this->capabilities)) {
            return in_array($capability, $this->capabilities, true);
        }

        return false;
    }
}
