<?php

declare(strict_types=1);

namespace GetKeyManager\SDK\Dto;

use GetKeyManager\SDK\ApiResponseCode;

/**
 * Activation Result DTO
 * 
 * Represents the result of a license activation operation.
 * Contains activation metadata and updated license information.
 * 
 * @package GetKeyManager\SDK\Dto
 */
class ActivationResultDto
{
    /** @var int API response code (from ApiResponseCode constants, e.g., LICENSE_ACTIVATED = 300) */
    public int $code;
    
    /** @var bool Whether the operation was successful */
    public bool $success;
    
    /** @var string|null Response message */
    public ?string $message = null;
    
    /** @var string|null Activation ID */
    public ?string $activation_id = null;
    
    /** @var string|null Hardware ID or domain identifier */
    public ?string $identifier = null;
    
    /** @var string|null Activation timestamp */
    public ?string $activated_at = null;
    
    /** @var LicenseDataDto|null Updated license data */
    public ?LicenseDataDto $license = null;
    
    /** @var string|null Base64-encoded .lic file content */
    public ?string $licFileContent = null;
    
    /** @var array|null Additional response data */
    public ?array $data = null;

    /**
     * Create from API response array
     * 
     * @param array $response API response
     * @return self
     */
    public static function fromResponse(array $response): self
    {
        $dto = new self();
        
        $dto->code = $response['code'] ?? $response['status_code'] ?? 0;
        $dto->success = $response['success'] ?? ApiResponseCode::isSuccess($dto->code);
        $dto->message = $response['message'] ?? null;
        
        // Parse activation data
        $activationData = $response['data']['activation'] ?? $response['activation'] ?? [];
        
        $dto->activation_id = $activationData['id'] ?? $activationData['activation_id'] ?? null;
        $dto->identifier = $activationData['identifier'] ?? $activationData['hardware_id'] ?? $activationData['domain'] ?? null;
        $dto->activated_at = $activationData['activated_at'] ?? $activationData['created_at'] ?? null;
        
        // Parse license data if present
        if (isset($response['data']['license']) && is_array($response['data']['license'])) {
            $dto->license = LicenseDataDto::fromArray($response['data']['license']);
        } elseif (isset($response['license']) && is_array($response['license'])) {
            $dto->license = LicenseDataDto::fromArray($response['license']);
        }
        
        // Store .lic file content if present
        $dto->licFileContent = $response['data']['licFileContent'] 
            ?? $response['licFileContent'] 
            ?? null;
        
        $dto->data = $response['data'] ?? null;
        
        return $dto;
    }

    /**
     * Convert to array for backward compatibility
     * 
     * @return array Result as array
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'success' => $this->success,
            'message' => $this->message,
            'activation_id' => $this->activation_id,
            'identifier' => $this->identifier,
            'activated_at' => $this->activated_at,
            'license' => $this->license ? $this->license->toArray() : null,
            'licFileContent' => $this->licFileContent,
            'data' => $this->data,
        ];
    }

    /**
     * Check if activation was successful
     * 
     * @return bool True if success
     */
    public function isSuccess(): bool
    {
        return $this->success && ApiResponseCode::isSuccess($this->code);
    }

    /**
     * Get activation ID
     * 
     * @return string|null Activation ID
     */
    public function getActivationId(): ?string
    {
        return $this->activation_id;
    }

    /**
     * Get activated identifier
     * 
     * @return string|null Identifier (domain/hwid)
     */
    public function getIdentifier(): ?string
    {
        return $this->identifier;
    }

    /**
     * Get activation timestamp
     * 
     * @return string|null Timestamp
     */
    public function getActivatedAt(): ?string
    {
        return $this->activated_at;
    }

    /**
     * Get updated license data
     * 
     * @return LicenseDataDto|null License data
     */
    public function getLicense(): ?LicenseDataDto
    {
        return $this->license;
    }

    /**
     * Check if .lic file content is available
     * 
     * @return bool True if content available
     */
    public function hasLicenseFile(): bool
    {
        return !empty($this->licFileContent);
    }

    /**
     * Get .lic file content (base64 encoded)
     * 
     * @return string|null File content
     */
    public function getLicenseFileContent(): ?string
    {
        return $this->licFileContent;
    }
}
