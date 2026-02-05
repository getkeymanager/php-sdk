<?php

declare(strict_types=1);

namespace GetKeyManager\SDK\Tests\Unit;

use PHPUnit\Framework\TestCase;
use GetKeyManager\SDK\Constants\ValidationType;
use GetKeyManager\SDK\Constants\IdentifierType;

/**
 * Constants & Identifier Tests
 * 
 * TC-1.2.1: ValidationType Constants
 * TC-1.2.2: IdentifierType Constants  
 * TC-1.2.3: Constants Helper Methods
 */
class IdentifierTest extends TestCase
{
    /**
     * TC-1.2.1: ValidationType Constants
     * 
     * Given: ValidationType class
     * When: Access constants
     * Then: Correct boolean values returned
     */
    public function testValidationTypeConstants(): void
    {
        // OFFLINE_FIRST should be false
        $this->assertFalse(ValidationType::OFFLINE_FIRST);
        
        // FORCE_API should be true
        $this->assertTrue(ValidationType::FORCE_API);
        
        // Different values
        $this->assertNotEquals(ValidationType::OFFLINE_FIRST, ValidationType::FORCE_API);
    }

    /**
     * TC-1.2.1b: ValidationType::all() Returns Array
     */
    public function testValidationTypeAllReturnsArray(): void
    {
        $all = ValidationType::all();
        
        $this->assertIsArray($all);
        $this->assertArrayHasKey('offline_first', $all);
        $this->assertArrayHasKey('force_api', $all);
        $this->assertFalse($all['offline_first']);
        $this->assertTrue($all['force_api']);
    }

    /**
     * TC-1.2.1c: ValidationType::description() Returns Readable String
     */
    public function testValidationTypeDescriptionOfflineFirst(): void
    {
        $desc = ValidationType::description(ValidationType::OFFLINE_FIRST);
        
        $this->assertIsString($desc);
        $this->assertStringContainsString('Offline', $desc);
        $this->assertNotEmpty($desc);
    }

    /**
     * TC-1.2.1d: ValidationType::description() For Force API
     */
    public function testValidationTypeDescriptionForceApi(): void
    {
        $desc = ValidationType::description(ValidationType::FORCE_API);
        
        $this->assertIsString($desc);
        $this->assertStringContainsString('Force API', $desc);
        $this->assertStringContainsString('API', $desc);
        $this->assertNotEmpty($desc);
    }

    /**
     * TC-1.2.2: IdentifierType Constants
     * 
     * Given: IdentifierType class
     * When: Access constants
     * Then: Correct string values returned
     */
    public function testIdentifierTypeConstants(): void
    {
        $this->assertEquals('domain', IdentifierType::DOMAIN);
        $this->assertEquals('hwid', IdentifierType::HARDWARE);
        $this->assertEquals('auto', IdentifierType::AUTO);
        
        // All different
        $this->assertNotEquals(IdentifierType::DOMAIN, IdentifierType::HARDWARE);
        $this->assertNotEquals(IdentifierType::HARDWARE, IdentifierType::AUTO);
        $this->assertNotEquals(IdentifierType::DOMAIN, IdentifierType::AUTO);
    }

    /**
     * TC-1.2.2b: IdentifierType::all() Returns Array
     */
    public function testIdentifierTypeAllReturnsArray(): void
    {
        $all = IdentifierType::all();
        
        $this->assertIsArray($all);
        $this->assertArrayHasKey('domain', $all);
        $this->assertArrayHasKey('hardware', $all);
        $this->assertArrayHasKey('auto', $all);
        $this->assertEquals('domain', $all['domain']);
        $this->assertEquals('hwid', $all['hardware']);
        $this->assertEquals('auto', $all['auto']);
    }

    /**
     * TC-1.2.2c: IdentifierType::description() Returns Readable String
     */
    public function testIdentifierTypeDescriptionDomain(): void
    {
        $desc = IdentifierType::description(IdentifierType::DOMAIN);
        
        $this->assertIsString($desc);
        $this->assertStringContainsString('Domain', $desc);
        $this->assertStringContainsString('web', $desc);
        $this->assertNotEmpty($desc);
    }

    /**
     * TC-1.2.2d: IdentifierType::description() For Hardware
     */
    public function testIdentifierTypeDescriptionHardware(): void
    {
        $desc = IdentifierType::description(IdentifierType::HARDWARE);
        
        $this->assertIsString($desc);
        $this->assertStringContainsString('Hardware', $desc);
        $this->assertStringContainsString('on-premises', $desc);
        $this->assertNotEmpty($desc);
    }

    /**
     * TC-1.2.2e: IdentifierType::description() For Auto
     */
    public function testIdentifierTypeDescriptionAuto(): void
    {
        $desc = IdentifierType::description(IdentifierType::AUTO);
        
        $this->assertIsString($desc);
        $this->assertStringContainsString('Auto', $desc);
        $this->assertStringContainsString('auto', $desc);
        $this->assertNotEmpty($desc);
    }

    /**
     * TC-1.2.3: IdentifierType::isValid() Validation
     */
    public function testIdentifierTypeValidation(): void
    {
        // Valid types
        $this->assertTrue(IdentifierType::isValid(IdentifierType::DOMAIN));
        $this->assertTrue(IdentifierType::isValid(IdentifierType::HARDWARE));
        $this->assertTrue(IdentifierType::isValid(IdentifierType::AUTO));
        $this->assertTrue(IdentifierType::isValid('domain'));
        $this->assertTrue(IdentifierType::isValid('hwid'));
        $this->assertTrue(IdentifierType::isValid('auto'));

        // Invalid types
        $this->assertFalse(IdentifierType::isValid('invalid'));
        $this->assertFalse(IdentifierType::isValid(''));
        $this->assertFalse(IdentifierType::isValid('Domain'));
        $this->assertFalse(IdentifierType::isValid('HWID'));
    }

    /**
     * TC-1.2.3b: Constants Are Immutable
     * 
     * Verify constants cannot be changed (PHP language feature)
     */
    public function testConstantsAreImmutable(): void
    {
        // Store original values
        $offlineFirst = ValidationType::OFFLINE_FIRST;
        $forceApi = ValidationType::FORCE_API;
        $domain = IdentifierType::DOMAIN;
        
        // Verify they don't change
        $this->assertEquals($offlineFirst, ValidationType::OFFLINE_FIRST);
        $this->assertEquals($forceApi, ValidationType::FORCE_API);
        $this->assertEquals($domain, IdentifierType::DOMAIN);
    }
}
