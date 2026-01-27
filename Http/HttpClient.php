<?php

declare(strict_types=1);

namespace GetKeyManager\SDK\Http;

use GetKeyManager\SDK\Config\Configuration;
use GetKeyManager\SDK\LicenseException;
use GetKeyManager\SDK\NetworkException;
use GetKeyManager\SDK\RateLimitException;
use GetKeyManager\SDK\StateResolver;
use GetKeyManager\SDK\SignatureVerifier;
use GetKeyManager\SDK\SignatureException;
use GetKeyManager\SDK\ApiResponseCode;
use RuntimeException;

/**
 * HTTP Client
 * 
 * Handles all HTTP requests to the API with retry logic and error handling.
 * 
 * @package GetKeyManager\SDK\Http
 */
class HttpClient
{
    private const VERSION = '2.0.0';

    private Configuration $config;
    private ?SignatureVerifier $signatureVerifier;
    private ?StateResolver $stateResolver;

    /**
     * Initialize HTTP client
     * 
     * @param Configuration $config SDK configuration
     * @param SignatureVerifier|null $signatureVerifier Optional signature verifier
     * @param StateResolver|null $stateResolver Optional state resolver for exception handling
     */
    public function __construct(
        Configuration $config,
        ?SignatureVerifier $signatureVerifier = null,
        ?StateResolver $stateResolver = null
    ) {
        $this->config = $config;
        $this->signatureVerifier = $signatureVerifier;
        $this->stateResolver = $stateResolver;
    }

    /**
     * Make authenticated API request
     * 
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array|null $data Request payload
     * @param array $extraHeaders Additional headers
     * @return array Response data
     * @throws NetworkException On network errors
     * @throws LicenseException On API errors
     */
    public function request(
        string $method,
        string $endpoint,
        ?array $data = null,
        array $extraHeaders = []
    ): array {
        $url = $this->config->getBaseUrl() . $endpoint;
        
        $headers = array_merge([
            'X-API-Key: ' . $this->config->getApiKey(),
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: GetKeyManager-PHP-SDK/' . self::VERSION
        ], array_map(function($k, $v) { return "$k: $v"; }, array_keys($extraHeaders), $extraHeaders));

        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->config->getRetryAttempts()) {
            try {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => $this->config->getTimeout(),
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_CUSTOMREQUEST => $method,
                ]);

                if ($data !== null && in_array($method, ['POST', 'PUT', 'PATCH'])) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                curl_close($ch);

                if ($response === false) {
                    throw new NetworkException(
                        "Network error: {$error}",
                        0,
                        NetworkException::ERROR_NETWORK_ERROR
                    );
                }

                $responseData = json_decode($response, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new RuntimeException('Invalid JSON response from API');
                }

                // Handle rate limiting with retry
                if ($httpCode === 429) {
                    $retryAfter = $responseData['retry_after'] ?? $this->config->getRetryDelay();
                    usleep($retryAfter * 1000);
                    $attempt++;
                    continue;
                }

                // Handle successful response
                if ($httpCode >= 200 && $httpCode < 300) {
                    // Verify signature if enabled and present
                    if ($this->config->shouldVerifySignatures() && isset($responseData['signature'])) {
                        $this->verifyResponse($responseData);
                    }
                    
                    return $responseData['data'] ?? $responseData;
                }

                // Handle error response using StateResolver if available
                $this->handleErrorResponse($httpCode, $responseData);
                
            } catch (NetworkException $e) {
                $lastException = $e;
                $attempt++;
                if ($attempt < $this->config->getRetryAttempts()) {
                    usleep($this->config->getRetryDelay() * 1000 * $attempt);
                }
            }
        }

        throw $lastException ?? new NetworkException(
            'Request failed after ' . $this->config->getRetryAttempts() . ' retries',
            0,
            NetworkException::ERROR_NETWORK_ERROR
        );
    }

    /**
     * Make public (non-authenticated) API request
     * 
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @return array Response data
     * @throws NetworkException On network errors
     * @throws LicenseException On API errors
     */
    public function requestPublic(string $method, string $endpoint): array
    {
        $url = $this->config->getBaseUrl() . $endpoint;
        
        $headers = [
            'Accept: application/json',
            'User-Agent: GetKeyManager-PHP-SDK/' . self::VERSION
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->config->getTimeout(),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new NetworkException(
                "Network error: {$error}",
                0,
                NetworkException::ERROR_NETWORK_ERROR
            );
        }

        $responseData = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Invalid JSON response from API');
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return $responseData;
        }

        throw new LicenseException('Failed to fetch public data', $httpCode);
    }

    /**
     * Handle error response from API
     * 
     * @param int $httpCode HTTP status code
     * @param array $responseData Response data
     * @throws LicenseException
     */
    private function handleErrorResponse(int $httpCode, array $responseData): void
    {
        // Check if response has the standard structure with response.code
        if (isset($responseData['response']['code'])) {
            $apiCode = $responseData['response']['code'];
            
            // Use StateResolver for proper exception mapping if available
            if ($this->stateResolver) {
                $this->stateResolver->throwExceptionForResponse($responseData);
            }
            
            // Fallback: throw generic exception with API code info
            $message = $responseData['response']['message'] ?? ApiResponseCode::getMessage($apiCode);
            $codeName = ApiResponseCode::getName($apiCode);
            
            $exception = new LicenseException(
                $message,
                $httpCode,
                $apiCode,
                $responseData['response']['data'] ?? [],
                null,
                $apiCode
            );
            $exception->setResponseData($responseData);
            throw $exception;
        }
        
        // Legacy error format support
        if (isset($responseData['error'])) {
            $error = $responseData['error'];
            $code = $error['code'] ?? 'UNKNOWN_ERROR';
            $message = $error['message'] ?? 'An error occurred';
            
            throw new LicenseException($message, $httpCode, $code, $error);
        }

        // Generic error
        throw new LicenseException(
            'API request failed',
            $httpCode,
            'API_ERROR',
            $responseData
        );
    }

    /**
     * Verify response signature
     * 
     * @param array $response Response data
     * @throws SignatureException
     */
    private function verifyResponse(array $response): void
    {
        if (!$this->signatureVerifier) {
            return;
        }

        if (!isset($response['signature'])) {
            return;
        }

        $signature = $response['signature'];
        unset($response['signature']);

        $payload = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        if (!$this->signatureVerifier->verify($payload, $signature)) {
            throw new SignatureException(
                'Response signature verification failed',
                0,
                SignatureException::ERROR_SIGNATURE_VERIFICATION_FAILED
            );
        }
    }
}
