<?php

declare(strict_types=1);

namespace GetKeyManager\SDK\Tests\Unit;

use PHPUnit\Framework\TestCase;
use GetKeyManager\SDK\Dto\ValidationResultDto;
use GetKeyManager\SDK\Dto\LicenseDataDto;

/**
 * DTO Tests
 * 
 * TC-1.3.1: ValidationResultDto Creation
 * TC-1.3.2: DTO Backward Compatibility
 * TC-1.3.3: LicenseDataDto Helper Methods
 */
class DtoTest extends TestCase
{
    /**
     * TC-1.3.1: ValidationResultDto Creation from Response
     * 
     * Given: API response array
     * When: ValidationResultDto::fromResponse($response)
     * Then: DTO properties populated correctly
     */
    public function testValidationResultDtoFromResponse(): void
    {
        $response = [
            'code' => 200,
            'success' => true,
            'message' => 'License valid',
            'data' => [
                'license' => [
                    'license_key' => 'LIC-TEST-123',
                    'expires_at' => '2025-12-31 23:59:59',
                    'features' => ['updates', 'support', 'api_access'],
                    'status' => 'active'
                ]
            ]
        ];

        $dto = ValidationResultDto::fromResponse($response);

        // Assertions
        $this->assertEquals(200, $dto->code);
        $this->assertTrue($dto->success);
        $this->assertEquals('License valid', $dto->message);
        $this->assertNotNull($dto->license);
        $this->assertInstanceOf(LicenseDataDto::class, $dto->license);
        $this->assertEquals('LIC-TEST-123', $dto->license->license_key);
    }

    /**
     * TC-1.3.1b: ValidationResultDto with Missing License Data
     */
    public function testValidationResultDtoWithMissingLicense(): void
    {
        $response = [
            'code' => 404,
            'success' => false,
            'message' => 'License not found',
        ];

        $dto = ValidationResultDto::fromResponse($response);

        $this->assertEquals(404, $dto->code);
        $this->assertFalse($dto->success);
        $this->assertNull($dto->license);
    }

    /**
     * TC-1.3.2: DTO Backward Compatibility with toArray()
     * 
     * Given: ValidationResultDto instance
     * When: Call toArray()
     * Then: Returns array with all properties
     */
    public function testValidationResultDtoToArray(): void
    {
        $response = [
            'code' => 200,
            'success' => true,
            'message' => 'Valid',
            'data' => [
                'license' => [
                    'license_key' => 'LIC-KEY',
                    'expires_at' => '2025-12-31',
                    'features' => ['feat1'],
                    'status' => 'active'
                ]
            ]
        ];

        $dto = ValidationResultDto::fromResponse($response);
        $array = $dto->toArray();

        // Old array access should work
        $this->assertTrue($array['success']);
        $this->assertEquals(200, $array['code']);
        $this->assertEquals('LIC-KEY', $array['license']['license_key']);
        $this->assertEquals('Valid', $array['message']);
    }

    /**
     * TC-1.3.2b: Helper Methods on DTO
     */
    public function testValidationResultDtoHelperMethods(): void
    {
        $response = [
            'code' => 200,
            'success' => true,
            'message' => 'Valid',
            'data' => [
                'license' => [
                    'license_key' => 'LIC-KEY',
                    'expires_at' => '2025-12-31',
                    'features' => [],
                    'status' => 'active'
                ]
            ]
        ];

        $dto = ValidationResultDto::fromResponse($response);

        // Test helper methods
        $this->assertTrue($dto->isSuccess());
        $this->assertEquals(200, $dto->getCode());
        $this->assertNotNull($dto->getLicense());
    }

    /**
     * TC-1.3.3: LicenseDataDto Helper Methods - isExpired()
     */
    public function testLicenseDataDtoIsExpired(): void
    {
        // Create expired license
        $expiredData = [
            'license_key' => 'LIC-KEY',
            'expires_at' => '2020-12-31 23:59:59', // Past date
            'features' => [],
            'status' => 'expired'
        ];

        $license = LicenseDataDto::fromArray($expiredData);
        $this->assertTrue($license->isExpired());

        // Create active license
        $activeData = [
            'license_key' => 'LIC-KEY',
            'expires_at' => '2099-12-31 23:59:59', // Future date
            'features' => [],
            'status' => 'active'
        ];

        $license = LicenseDataDto::fromArray($activeData);
        $this->assertFalse($license->isExpired());
    }

    /**
     * TC-1.3.3b: LicenseDataDto Helper Methods - hasFeature()
     */
    public function testLicenseDataDtoHasFeature(): void
    {
        $licenseData = [
            'license_key' => 'LIC-KEY',
            'expires_at' => '2025-12-31',
            'features' => ['updates', 'support', 'api_access'],
            'status' => 'active'
        ];

        $license = LicenseDataDto::fromArray($licenseData);

        // Test feature checks
        $this->assertTrue($license->hasFeature('updates'));
        $this->assertTrue($license->hasFeature('support'));
        $this->assertTrue($license->hasFeature('api_access'));
        $this->assertFalse($license->hasFeature('analytics'));
        $this->assertFalse($license->hasFeature('missing_feature'));
    }

    /**
     * TC-1.3.3c: LicenseDataDto Property Access
     */
    public function testLicenseDataDtoPropertyAccess(): void
    {
        $licenseData = [
            'license_key' => 'LIC-TEST-123',
            'expires_at' => '2025-12-31 23:59:59',
            'features' => ['feat1', 'feat2'],
            'status' => 'active',
            'activations' => 5,
            'used_activations' => 2,
        ];

        $license = LicenseDataDto::fromArray($licenseData);

        // Test property access
        $this->assertEquals('LIC-TEST-123', $license->license_key);
        $this->assertEquals('2025-12-31 23:59:59', $license->expires_at);
        $this->assertIsArray($license->features);
        $this->assertCount(2, $license->features);
        $this->assertEquals('active', $license->status);
        $this->assertEquals(5, $license->activations);
        $this->assertEquals(2, $license->used_activations);
    }

    /**
     * TC-1.3.3d: LicenseDataDto toArray() Conversion
     */
    public function testLicenseDataDtoToArray(): void
    {
        $originalData = [
            'license_key' => 'LIC-KEY',
            'expires_at' => '2025-12-31',
            'features' => ['feat1'],
            'status' => 'active'
        ];

        $license = LicenseDataDto::fromArray($originalData);
        $array = $license->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('LIC-KEY', $array['license_key']);
        $this->assertEquals('2025-12-31', $array['expires_at']);
        $this->assertArrayHasKey('features', $array);
        $this->assertEquals('active', $array['status']);
    }
}
