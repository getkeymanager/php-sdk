<?php

declare(strict_types=1);

namespace LicenseManager\SDK\Tests;

use PHPUnit\Framework\TestCase;
use LicenseManager\SDK\SignatureVerifier;

/**
 * Test cases for SignatureVerifier
 */
class SignatureVerifierTest extends TestCase
{
    private string $testPublicKey = '-----BEGIN PUBLIC KEY-----
MIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEAtest
-----END PUBLIC KEY-----';

    public function testConstructorRequiresPublicKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SignatureVerifier('');
    }

    public function testConstructorAcceptsValidPublicKey(): void
    {
        $verifier = new SignatureVerifier($this->testPublicKey);
        $this->assertInstanceOf(SignatureVerifier::class, $verifier);
    }

    public function testVerifyReturnsFalseForInvalidSignature(): void
    {
        $verifier = new SignatureVerifier($this->testPublicKey);
        $data = '{"test":"data"}';
        $signature = 'invalid_signature';

        $result = $verifier->verify($data, $signature);
        $this->assertFalse($result);
    }

    public function testVerifyJsonResponseWithMissingSignature(): void
    {
        $verifier = new SignatureVerifier($this->testPublicKey);
        $jsonResponse = '{"data":{"test":"value"}}';

        $this->expectException(\InvalidArgumentException::class);
        $verifier->verifyJsonResponse($jsonResponse);
    }

    public function testVerifyJsonResponseWithInvalidJson(): void
    {
        $verifier = new SignatureVerifier($this->testPublicKey);
        
        $this->expectException(\InvalidArgumentException::class);
        $verifier->verifyJsonResponse('invalid json');
    }

    public function testCanonicalizeJson(): void
    {
        $verifier = new SignatureVerifier($this->testPublicKey);
        $reflection = new \ReflectionClass($verifier);
        $method = $reflection->getMethod('canonicalizeJson');
        $method->setAccessible(true);

        $data = ['z' => 'value', 'a' => 'another', 'nested' => ['b' => 1, 'a' => 2]];
        $canonical = $method->invoke($verifier, $data);

        // Should be sorted and compact
        $this->assertStringContainsString('"a":', $canonical);
        $this->assertStringContainsString('"z":', $canonical);
        $this->assertStringNotContainsString('  ', $canonical); // No extra spaces
    }
}
