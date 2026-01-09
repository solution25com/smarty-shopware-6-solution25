<?php

declare(strict_types=1);

namespace SmartyIntegration\Service;

use Psr\Log\LoggerInterface;
use SmartyIntegration\Config\SmartyConfig;
use SmartyIntegration\Domain\Address\AdressDto;
use SmartyIntegration\Domain\Address\SmartyValidationResult;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SmartyApiService
{
    public function __construct(
        private readonly SmartyConfig $smartyConfig,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger
    ) {
    }

    public function validateAdress(AdressDto $addressDto, ?string $salesChannelId = null): SmartyValidationResult
    {
        $authId = $this->smartyConfig->getAuthId();
        $authToken = $this->smartyConfig->getAuthToken();
        $environment = $this->smartyConfig->getEnvironment();

        $this->logger->info('Smarty validateAdress called', [
            'salesChannelId' => $salesChannelId,
            'environment' => $environment,
            'street' => $addressDto->getStreet(),
            'city' => $addressDto->getCity(),
            'postalCode' => $addressDto->getPostalCode(),
        ]);

        if (!$authId || !$authToken) {
            $this->logger->error('Smarty validateAdress: missing credentials', [
                'authId_present' => $authId !== null,
                'authToken_present' => $authToken !== null,
                'environment' => $environment,
            ]);

            return new SmartyValidationResult(
                isValid: false,
                standardizedStreet: null,
                standardizedCity: null,
                standardizedPostalCode: null,
                standardizedCountryIso: null,
                rawResponse: [
                    'error' => 'Missing Smarty credentials',
                    'environment' => $environment,
                ]
            );
        }

        $baseUrl = 'https://us-street.api.smarty.com/street-address';
        $query = http_build_query([
            'auth-id' => $authId,
            'auth-token' => $authToken,
            'license' => 'us-rooftop-geocoding-cloud',
        ]);

        $url = $baseUrl . '?' . $query;

        $payload = [[
            'street' => $addressDto->getStreet(),
            'city' => $addressDto->getCity(),
            'zipcode' => $addressDto->getPostalCode(),
            'candidates' => 1,
        ]];

        $headers = [
            'Content-Type' => 'application/json; charset=utf-8',
            'Accept' => 'application/json',
        ];

        $this->logger->debug('Smarty validateAdress: sending request', [
            'url' => $url,
            'payload' => $payload,
            'headers' => $headers,
            'environment' => $environment,
        ]);

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => $headers,
                'json' => $payload,
                'timeout' => 10,
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);

            $this->logger->debug('Smarty validateAdress: raw response', [
                'status_code' => $statusCode,
                'body' => $content,
            ]);

            $data = json_decode($content, true);

            if (!is_array($data)) {
                $this->logger->error('Smarty validateAdress: invalid JSON / not array', [
                    'status_code' => $statusCode,
                    'body' => $content,
                ]);

                return new SmartyValidationResult(
                    isValid: false,
                    standardizedStreet: null,
                    standardizedCity: null,
                    standardizedPostalCode: null,
                    standardizedCountryIso: null,
                    rawResponse: [
                        'error' => 'Invalid JSON from Smarty',
                        'status_code' => $statusCode,
                        'raw_response' => $content,
                    ]
                );
            }

            if ($statusCode < 200 || $statusCode >= 300) {
                $this->logger->error('Smarty validateAdress: non-success response', [
                    'status_code' => $statusCode,
                    'body' => $data,
                ]);

                return new SmartyValidationResult(
                    isValid: false,
                    standardizedStreet: null,
                    standardizedCity: null,
                    standardizedPostalCode: null,
                    standardizedCountryIso: null,
                    rawResponse: $data
                );
            }

            $result = SmartyValidationResult::fromApiResponse($data);

            $this->logger->info('Smarty validateAdress: success', [
                'isValid' => $result->isValid(),
                'street' => $result->getStandardizedStreet(),
                'city' => $result->getStandardizedCity(),
                'postalCode' => $result->getStandardizedPostalCode(),
                'countryIso' => $result->getStandardizedCountryIso(),
            ]);

            return $result;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Smarty validateAdress: transport error', [
                'message' => $e->getMessage(),
                'environment' => $environment,
                'endpoint_url' => $url,
            ]);

            return new SmartyValidationResult(
                isValid: false,
                standardizedStreet: null,
                standardizedCity: null,
                standardizedPostalCode: null,
                standardizedCountryIso: null,
                rawResponse: [
                    'error' => 'TransportException',
                    'message' => $e->getMessage(),
                    'environment' => $environment,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error('Smarty validateAdress: unexpected error', [
                'message' => $e->getMessage(),
                'environment' => $environment,
                'endpoint_url' => $url,
            ]);

            return new SmartyValidationResult(
                isValid: false,
                standardizedStreet: null,
                standardizedCity: null,
                standardizedPostalCode: null,
                standardizedCountryIso: null,
                rawResponse: [
                    'error' => 'Throwable',
                    'message' => $e->getMessage(),
                    'environment' => $environment,
                ]
            );
        }
    }

    public function testConnectionWithCredentials(
        ?string $authId,
        ?string $authToken,
        ?string $environment = null,
        ?string $salesChannelId = null
    ): bool {
        $authId = $authId !== null ? trim($authId) : '';
        $authToken = $authToken !== null ? trim($authToken) : '';
        $environment = $environment ?? $this->smartyConfig->getEnvironment();

        $license = 'us-rooftop-geocoding-cloud';

        if ($authId === '' || $authToken === '') {
            $this->logger->error('Smarty testConnectionWithCredentials: missing credentials', [
                'salesChannelId' => $salesChannelId,
                'authId_present' => $authId !== '',
                'authToken_present' => $authToken !== '',
                'environment' => $environment,
                'license' => $license,
            ]);

            return false;
        }

        $baseUrl = 'https://us-street.api.smarty.com/street-address';

        $query = http_build_query([
            'auth-id' => $authId,
            'auth-token' => $authToken,
            'license' => $license,
        ]);

        $url = $baseUrl . '?' . $query;

        $payload = [[
            'street' => '1600 Amphitheatre Pkwy',
            'city' => 'Mountain View',
            'zipcode' => '94043',
        ]];

        $headers = [
            'Content-Type' => 'application/json; charset=utf-8',
            'Accept' => 'application/json',
        ];

        $this->logger->info('Smarty testConnectionWithCredentials: sending request', [
            'salesChannelId' => $salesChannelId,
            'environment' => $environment,
            'endpoint_url' => $baseUrl,
            'license' => $license,
        ]);

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => $headers,
                'json' => $payload,
                'timeout' => 10,
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logger->info('Smarty testConnectionWithCredentials: success', [
                    'status_code' => $statusCode,
                ]);

                return true;
            }

            $this->logger->error('Smarty testConnectionWithCredentials failed', [
                'status_code' => $statusCode,
                'body' => $content,
                'environment' => $environment,
                'endpoint_url' => $baseUrl,
                'license' => $license,
            ]);

            return false;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Smarty testConnectionWithCredentials transport error', [
                'message' => $e->getMessage(),
                'environment' => $environment,
                'endpoint_url' => $baseUrl,
            ]);

            return false;
        } catch (\Throwable $e) {
            $this->logger->error('Smarty testConnectionWithCredentials unexpected error', [
                'message' => $e->getMessage(),
                'environment' => $environment,
                'endpoint_url' => $baseUrl,
            ]);

            return false;
        }
    }

    public function testConnection(?string $salesChannelId = null): bool
    {
        return $this->testConnectionWithCredentials(
            $this->smartyConfig->getAuthId(),
            $this->smartyConfig->getAuthToken(),
            $this->smartyConfig->getEnvironment(),
            $salesChannelId
        );
    }

    public function lookupByCoordinates(float $lat, float $lng, ?string $salesChannelId = null): SmartyValidationResult
    {
        $authId = $this->smartyConfig->getAuthId();
        $authToken = $this->smartyConfig->getAuthToken();

        if (!$authId || !$authToken) {
            $this->logger->error('Smarty reverse-geo requires authId and authToken', [
                'salesChannelId' => $salesChannelId,
            ]);

            return new SmartyValidationResult(false, null, null, null, null, []);
        }

        $url = 'https://us-reverse-geo.api.smarty.com/lookup';

        try {
            $response = $this->httpClient->request('GET', $url, [
                'query' => [
                    'auth-id' => $authId,
                    'auth-token' => $authToken,
                    'license' => 'us-reverse-geocoding-cloud',
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'source' => 'postal',
                ],
                'timeout' => 5,
            ]);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Smarty reverse-geo transport error', [
                'exception' => $e,
            ]);

            return new SmartyValidationResult(false, null, null, null, null, []);
        }

        $statusCode = $response->getStatusCode();
        $rawBody = (string)$response->getContent(false);

        try {
            $json = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->logger->error('Smarty reverse-geo invalid JSON', [
                'body' => $rawBody,
                'exception' => $e,
            ]);

            return new SmartyValidationResult(false, null, null, null, null, []);
        }

        if ($statusCode !== 200) {
            $this->logger->error('Smarty reverse-geo non-200 status', [
                'status' => $statusCode,
                'response' => $json,
            ]);

            return new SmartyValidationResult(false, null, null, null, null, $json ?? []);
        }

        $results = $json['results'] ?? [];
        $first = $results[0] ?? null;

        if (!$first || empty($first['address'])) {
            return new SmartyValidationResult(false, null, null, null, null, $json);
        }

        $addr = $first['address'];

        $street = $addr['street'] ?? null;
        $city = $addr['city'] ?? null;
        $zip = $addr['zipcode'] ?? null;
        $country = 'US';

        return new SmartyValidationResult(
            true,
            $street,
            $city,
            $zip,
            $country,
            $json
        );
    }
}
