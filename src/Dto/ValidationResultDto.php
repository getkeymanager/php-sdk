<?php

declare(strict_types=1);

namespace GetKeyManager\SDK\Dto;

use GetKeyManager\SDK\ApiResponseCode;

/**
 * Validation Result DTO
 * 
 * Represents the result of a license validation operation.
 * Contains the API response code and parsed license data.
 * 
 * @package GetKeyManager\SDK\Dto
 */
class ValidationResultDto
{
    /** @var int API response code (from ApiResponseCode constants, e.g., VALID_LICENSE_KEY = 200) */
    public int $code;
    
    /** @var bool Whether the operation was successful */
    public bool $success;
    
    /** @var string|null Response message */
    public ?string $message = null;
    
    /** @var LicenseDataDto|null Parsed license data */
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
            'license' => $this->license ? $this->license->toArray() : null,
            'licFileContent' => $this->licFileContent,
            'data' => $this->data,
        ];
    }

    /**
     * Check if validation was successful
     * 
     * @return bool True if success
     */
    public function isSuccess(): bool
    {
        return $this->success && ApiResponseCode::isSuccess($this->code);
    }

    /**
     * Get API response code
     * 
     * @return int Response code
     */
    public function getCode(): int
    {
        return $this->code;
    }

    /**
     * Get license data (or null if not available)
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
