<?php

declare(strict_types=1);

namespace GetKeyManager\SDK\Dto;

/**
 * Validation Result DTO
 * 
 * Represents the result of a license validation operation.
 * Contains both the HTTP response code and parsed license data.
 * 
 * @package GetKeyManager\SDK\Dto
 */
class ValidationResultDto
{
    public int $code;
    public bool $success;
    public ?string $message = null;
    public ?LicenseDataDto $license = null;
    public ?string $licFileContent = null;
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
        $dto->success = $response['success'] ?? ($response['code'] === 200);
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
        return $this->success && $this->code === 200;
    }

    /**
     * Get HTTP status code
     * 
     * @return int Status code
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
