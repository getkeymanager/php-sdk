<?php

declare(strict_types=1);

namespace GetKeyManager\SDK\Dto;

/**
 * Sync Result DTO
 * 
 * Represents the result of a license and key synchronization operation.
 * Contains status of both .lic file and public key file updates.
 * 
 * @package GetKeyManager\SDK\Dto
 */
class SyncResultDto
{
    public bool $success;
    public bool $licenseUpdated;
    public bool $keyUpdated;
    public array $errors = [];
    public array $warnings = [];
    public ?string $license_key = null;
    public ?string $last_verified_at = null;

    /**
     * Create from sync operation result
     * 
     * @param array $result Sync result array
     * @return self
     */
    public static function fromResult(array $result): self
    {
        $dto = new self();
        
        $dto->success = $result['success'] ?? false;
        $dto->licenseUpdated = $result['licenseUpdated'] ?? false;
        $dto->keyUpdated = $result['keyUpdated'] ?? false;
        $dto->errors = $result['errors'] ?? [];
        $dto->warnings = $result['warnings'] ?? [];
        $dto->license_key = $result['license_key'] ?? null;
        $dto->last_verified_at = $result['last_verified_at'] ?? null;
        
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
            'success' => $this->success,
            'licenseUpdated' => $this->licenseUpdated,
            'keyUpdated' => $this->keyUpdated,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'license_key' => $this->license_key,
            'last_verified_at' => $this->last_verified_at,
        ];
    }

    /**
     * Check if sync was successful
     * 
     * @return bool True if success
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Check if license file was updated
     * 
     * @return bool True if updated
     */
    public function isLicenseUpdated(): bool
    {
        return $this->licenseUpdated;
    }

    /**
     * Check if key file was updated
     * 
     * @return bool True if updated
     */
    public function isKeyUpdated(): bool
    {
        return $this->keyUpdated;
    }

    /**
     * Get all errors
     * 
     * @return array List of errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Check if there are any errors
     * 
     * @return bool True if errors present
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /**
     * Get first error message
     * 
     * @return string|null First error or null
     */
    public function getFirstError(): ?string
    {
        return $this->errors[0] ?? null;
    }

    /**
     * Get all warnings
     * 
     * @return array List of warnings
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Check if there are any warnings
     * 
     * @return bool True if warnings present
     */
    public function hasWarnings(): bool
    {
        return !empty($this->warnings);
    }

    /**
     * Add an error
     * 
     * @param string $error Error message
     * @return self Fluent interface
     */
    public function addError(string $error): self
    {
        $this->errors[] = $error;
        return $this;
    }

    /**
     * Add a warning
     * 
     * @param string $warning Warning message
     * @return self Fluent interface
     */
    public function addWarning(string $warning): self
    {
        $this->warnings[] = $warning;
        return $this;
    }

    /**
     * Get sync summary as human-readable string
     * 
     * @return string Summary
     */
    public function getSummary(): string
    {
        $status = $this->success ? 'SUCCESS' : 'FAILED';
        $summary = "Sync {$status}. ";

        if ($this->licenseUpdated) {
            $summary .= "License file updated. ";
        } else {
            $summary .= "License file not updated. ";
        }

        if ($this->keyUpdated) {
            $summary .= "Key file updated. ";
        } else {
            $summary .= "Key file not updated. ";
        }

        if (!empty($this->errors)) {
            $summary .= "Errors: " . count($this->errors) . ". ";
        }

        if (!empty($this->warnings)) {
            $summary .= "Warnings: " . count($this->warnings) . ".";
        }

        return trim($summary);
    }
}
