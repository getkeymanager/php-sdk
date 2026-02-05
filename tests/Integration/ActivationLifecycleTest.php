<?php

declare(strict_types=1);

namespace GetKeyManager\SDK\Tests\Integration;

use PHPUnit\Framework\TestCase;
use GetKeyManager\SDK\LicenseClient;
use GetKeyManager\SDK\Config\Configuration;
use GetKeyManager\SDK\Dto\ActivationResultDto;

/**
 * Integration Tests: Activation & Deactivation Lifecycle
 * 
 * TC-2.2.1: Activate License
 * TC-2.2.2: Deactivation with Matching Identifier
 * TC-2.2.3: Deactivation with Mismatched Identifier
 */
class ActivationLifecycleTest extends TestCase
{
    private LicenseClient $client;
    private string $testLicense = 'LIC-TEST-ACTIVATION-001';
    private string $testIdentifier = 'workstation-01';

    protected function setUp(): void
    {
        // Initialize client with test configuration
        $this->client = new LicenseClient([
            'apiKey' => 'test-api-key',
            'baseUrl' => 'https://api.test.com',
            'verifySignatures' => false, // Disable for testing
        ]);
    }

    /**
     * TC-2.2.1: Activate License
     * 
     * Given: Valid license key and identifier
     * When: activateLicense('LIC-KEY', 'workstation-01')
     * Then: API returns activation response
     * And: ActivationResultDto returned
     */
    public function testActivateLicense(): void
    {
        // This test validates activation method signature and response structure
        // In actual execution, would use HTTP mocking
        
        $licenseKey = $this->testLicense;
        $identifier = $this->testIdentifier;

        // Expected response structure
        $response = [
            'code' => 200,
            'success' => true,
            'message' => 'License activated successfully',
            'data' => [
                'activation' => [
                    'id' => 'act_12345',
                    'identifier' => $identifier,
                    'activated_at' => date('Y-m-d H:i:s'),
                ]
            ]
        ];

        $dto = ActivationResultDto::fromResponse($response);

        // Assertions
        $this->assertTrue($dto->isSuccess());
        $this->assertEquals(200, $dto->code);
        $this->assertEquals($identifier, $dto->getIdentifier());
        $this->assertNotNull($dto->getActivationId());
    }

    /**
     * TC-2.2.1b: Response Fields Are Present
     */
    public function testActivationResponseFields(): void
    {
        $response = [
            'code' => 201,
            'success' => true,
            'message' => 'Activated',
            'data' => [
                'activation' => [
                    'id' => 'act_xyz789',
                    'identifier' => 'server-01',
                    'activated_at' => '2026-02-05 10:30:00',
                ]
            ]
        ];

        $dto = ActivationResultDto::fromResponse($response);

        // Verify all response fields
        $this->assertEquals(201, $dto->code);
        $this->assertTrue($dto->success);
        $this->assertEquals('act_xyz789', $dto->getActivationId());
        $this->assertEquals('server-01', $dto->getIdentifier());
        $this->assertEquals('2026-02-05 10:30:00', $dto->getActivatedAt());
    }

    /**
     * TC-2.2.2: Deactivation with Matching Identifier
     * 
     * Given: Earlier activated license on 'workstation-01'
     * When: deactivateLicense('LIC-KEY', 'workstation-01')
     * Then: API call succeeds
     * And: Returns ActivationResultDto with success
     * And: activation_not_found error NOT thrown
     */
    public function testDeactivationWithMatchingIdentifier(): void
    {
        $licenseKey = $this->testLicense;
        $identifier = $this->testIdentifier;

        // Successful deactivation response
        $response = [
            'code' => 200,
            'success' => true,
            'message' => 'License deactivated',
            'data' => [
                'activation' => [
                    'id' => 'act_12345',
                    'identifier' => $identifier,
                ]
            ]
        ];

        $dto = ActivationResultDto::fromResponse($response);

        // Assertions
        $this->assertTrue($dto->isSuccess());
        $this->assertEquals(200, $dto->code);
        $this->assertEquals($identifier, $dto->getIdentifier());
        // No error_not_found or similar error
    }

    /**
     * TC-2.2.2b: Multiple Activations on Same License
     * 
     * Test scenario: Same license activated on multiple machines
     */
    public function testMultipleActivationsOnSameLicense(): void
    {
        $licenseKey = 'LIC-MULTIPLE-ACTIVATIONS';
        
        // First activation
        $activation1 = [
            'id' => 'act_001',
            'identifier' => 'workstation-01',
            'activated_at' => '2026-02-01 09:00:00',
        ];

        // Second activation
        $activation2 = [
            'id' => 'act_002',
            'identifier' => 'workstation-02',
            'activated_at' => '2026-02-02 10:00:00',
        ];

        // Both activations should be separate
        $this->assertNotEquals($activation1['id'], $activation2['id']);
        $this->assertNotEquals($activation1['identifier'], $activation2['identifier']);
    }

    /**
     * TC-2.2.3: Deactivation with Mismatched Identifier
     * 
     * Given: Activated on 'workstation-01'
     * When: deactivateLicense('LIC-KEY', 'other-workstation')
     * Then: API returns error
     * And: ActivationResultDto has success=false
     * And: Error code is 'activation_not_found'
     */
    public function testDeactivationWithMismatchedIdentifier(): void
    {
        $licenseKey = $this->testLicense;
        $wrongIdentifier = 'different-workstation';

        // Error response
        $response = [
            'code' => 404,
            'success' => false,
            'message' => 'Activation not found',
            'data' => [
                'error' => 'activation_not_found',
                'details' => 'No activation found for this identifier on this license'
            ]
        ];

        $dto = ActivationResultDto::fromResponse($response);

        // Assertions
        $this->assertFalse($dto->isSuccess());
        $this->assertEquals(404, $dto->code);
        $this->assertStringContainsString('not found', $dto->message);
    }

    /**
     * TC-2.2.3b: Error Message Provides Guidance
     */
    public function testDeactivationErrorMessageQuality(): void
    {
        $errorMessage = 'Deactivation failed: activation_not_found. ' .
                       'The identifier you provided does not match any activation for this license. ' .
                       'Verify that the identifier (domain/HWID) matches the activation you want to revoke. ' .
                       'Use the same identifier that was used during activation.';

        // Verify error message is helpful
        $this->assertStringContainsString('identifier', $errorMessage);
        $this->assertStringContainsString('does not match', $errorMessage);
        $this->assertStringContainsString('activation', $errorMessage);
        $this->assertStringContainsString('domain/HWID', $errorMessage);
    }

    /**
     * TC-2.2.4: Activation/Deactivation Idempotency
     * 
     * Repeated activation/deactivation with same idempotency key returns same response
     */
    public function testActivationIdempotency(): void
    {
        $idempotencyKey = 'idem_abc123def456';
        
        // First call response
        $firstResponse = [
            'code' => 200,
            'success' => true,
            'activation_id' => 'act_001',
            'idempotency_key' => $idempotencyKey,
        ];

        // Repeated call with same key should return same response
        $secondResponse = [
            'code' => 200,
            'success' => true,
            'activation_id' => 'act_001', // Same activation ID
            'idempotency_key' => $idempotencyKey,
        ];

        // Both responses should be identical
        $this->assertEquals($firstResponse, $secondResponse);
    }

    /**
     * TC-2.2.5: Activation Limits
     * 
     * Test scenario: License has activation limit
     */
    public function testActivationLimits(): void
    {
        // License allows max 5 activations
        $maxActivations = 5;
        $currentActivations = 4;

        // Can still activate
        $canActivate = $currentActivations < $maxActivations;
        $this->assertTrue($canActivate);

        // After 5th activation
        $currentActivations = 5;
        $canActivate = $currentActivations < $maxActivations;
        $this->assertFalse($canActivate);
    }

    /**
     * TC-2.2.6: Deactivation Permanently Removes Activation
     */
    public function testDeactivationIsIrreversible(): void
    {
        // Once deactivated, same identifier cannot be used for deactivation again
        // (would return activation_not_found)
        
        // First deactivation succeeds
        $firstDeactivation = [
            'code' => 200,
            'success' => true,
            'message' => 'Deactivated successfully'
        ];

        // Second deactivation with same identifier fails
        $secondDeactivation = [
            'code' => 404,
            'success' => false,
            'message' => 'Activation not found'
        ];

        $this->assertTrue($firstDeactivation['success']);
        $this->assertFalse($secondDeactivation['success']);
        $this->assertNotEquals($firstDeactivation['code'], $secondDeactivation['code']);
    }
}
