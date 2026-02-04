<?php

declare(strict_types=1);

namespace GetKeyManager\SDK\Telemetry;

use GetKeyManager\SDK\Config\Configuration;
use GetKeyManager\SDK\Http\HttpClient;
use GetKeyManager\SDK\Exceptions\LicenseException;
use InvalidArgumentException;
use Exception;

/**
 * Telemetry Client
 * 
 * Handles telemetry data submission and retrieval.
 * 
 * @package GetKeyManager\SDK\Telemetry
 */
class TelemetryClient
{
    private Configuration $config;
    private HttpClient $httpClient;

    /**
     * Initialize telemetry client
     * 
     * @param Configuration $config SDK configuration
     * @param HttpClient $httpClient HTTP client
     */
    public function __construct(
        Configuration $config,
        HttpClient $httpClient
    ) {
        $this->config = $config;
        $this->httpClient = $httpClient;
    }

    /**
     * Send telemetry data
     * 
     * @param string $dataType Data type: numeric-single-value, numeric-xy-axis, or text
     * @param string $dataGroup Data group/category
     * @param array $dataValues Data values based on type
     * @param array $options Optional parameters (license_key, activation_identifier, user_identifier, product_id, product_version)
     * @return array Result
     */
    public function sendTelemetry(
        string $dataType,
        string $dataGroup,
        array $dataValues = [],
        array $options = []
    ): array {
        try {
            // Validate data type
            $validDataTypes = ['numeric-single-value', 'numeric-xy-axis', 'text'];
            if (!in_array($dataType, $validDataTypes)) {
                throw new InvalidArgumentException("Invalid data_type. Must be one of: " . implode(', ', $validDataTypes));
            }

            // Build payload based on data type
            $data = [
                'data_type' => $dataType,
                'data_group' => $dataGroup,
            ];

            // Add conditional data fields based on type
            switch ($dataType) {
                case 'numeric-single-value':
                    if (!isset($dataValues['value'])) {
                        throw new InvalidArgumentException("numeric_data_single_value is required for numeric-single-value type");
                    }
                    $data['numeric_data_single_value'] = $dataValues['value'];
                    break;
                case 'numeric-xy-axis':
                    if (!isset($dataValues['x']) || !isset($dataValues['y'])) {
                        throw new InvalidArgumentException("numeric_data_x and numeric_data_y are required for numeric-xy-axis type");
                    }
                    $data['numeric_data_x'] = $dataValues['x'];
                    $data['numeric_data_y'] = $dataValues['y'];
                    break;
                case 'text':
                    if (!isset($dataValues['text'])) {
                        throw new InvalidArgumentException("text_data is required for text type");
                    }
                    $data['text_data'] = $dataValues['text'];
                    break;
            }

            // Add optional context fields
            if (!empty($options['license_key'])) {
                $data['license_key'] = $options['license_key'];
            }
            if (!empty($options['activation_identifier'])) {
                $data['activation_identifier'] = $options['activation_identifier'];
            }
            if (!empty($options['user_identifier'])) {
                $data['user_identifier'] = $options['user_identifier'];
            }
            if (!empty($options['product_id'])) {
                $data['product_id'] = $options['product_id'];
            }
            if (!empty($options['product_version'])) {
                $data['product_version'] = $options['product_version'];
            }

            $response = $this->httpClient->request('POST', '/v1/send-telemetry', $data);

            return [
                'success' => true,
                'telemetry_id' => $response['telemetry_id'] ?? null,
                'is_flagged' => $response['is_flagged'] ?? false,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get telemetry data
     * 
     * @param string $dataType Data type (numeric-single-value, numeric-xy-axis, text)
     * @param string $dataGroup Data group
     * @param array $filters Optional filters
     * @return array Telemetry data
     * @throws LicenseException
     */
    public function getTelemetryData(string $dataType, string $dataGroup, array $filters = []): array
    {
        if (empty($dataType) || empty($dataGroup)) {
            throw new InvalidArgumentException('Data type and data group are required');
        }

        $queryParams = [
            'data_type' => $dataType,
            'data_group' => $dataGroup
        ];

        if (isset($filters['product_id'])) {
            $queryParams['product_id'] = $filters['product_id'];
        }

        if (isset($filters['user_identifier'])) {
            $queryParams['user_identifier'] = $filters['user_identifier'];
        }

        if (isset($filters['license_key'])) {
            $queryParams['license_key'] = $filters['license_key'];
        }

        if (isset($filters['has_red_flags'])) {
            $queryParams['has_red_flags'] = $filters['has_red_flags'];
        }

        $endpoint = '/v1/get-telemetry-data?' . http_build_query($queryParams);

        $response = $this->httpClient->request('GET', $endpoint);

        return $response;
    }
}
