<?php

declare(strict_types=1);

namespace SmartyAddressValidation\Service;

use JsonException;
use SmartyAddressValidation\Exception\SmartyApiException;
use SmartyAddressValidation\Exception\SmartyConfigurationException;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SmartyClient
{
    private const CONFIG_PREFIX = 'SmartyAddressValidation.config.';
    private const US_STREET_ENDPOINT = 'https://us-street.api.smarty.com/street-address';

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly HttpClientInterface $httpClient,
        private readonly SmartyLogger $logger
    ) {
    }

    public function isConfigured(?string $salesChannelId = null): bool
    {
        $authId = $this->getStringConfig('smartyAuthId', $salesChannelId);
        $websiteKey = $this->getStringConfig('smartyWebsiteKey', $salesChannelId);
        $authToken = $this->getStringConfig('smartyAuthToken', $salesChannelId);

        return ($authId !== '' && $authToken !== '') || $websiteKey !== '';
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    public function validateUsStreetAddress(array $payload, ?string $salesChannelId = null): array
    {
        if (!$this->isConfigured($salesChannelId)) {
            throw new SmartyConfigurationException('Smarty credentials are not configured.');
        }

        $query = array_merge(
            $this->buildAuthQuery($salesChannelId),
            $this->preparePayload($payload)
        );

        $this->logger->debug('Sending Smarty address validation request.', [
            'environment' => $this->getEnvironment($salesChannelId),
            'endpoint' => self::US_STREET_ENDPOINT,
            'request' => $this->buildSafeRequestData($payload, $salesChannelId),
        ], $salesChannelId);

        try {
            $response = $this->httpClient->request('GET', self::US_STREET_ENDPOINT, [
                'query' => $query,
                'timeout' => 8,
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->getContent(false);

            if ($statusCode >= 400) {
                throw new SmartyApiException(
                    sprintf('Smarty API returned HTTP %d.', $statusCode),
                    $statusCode,
                    $body
                );
            }

            $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (TransportExceptionInterface $exception) {
            throw new SmartyApiException(
                'Smarty API request failed: ' . $exception->getMessage(),
                null,
                null,
                $exception
            );
        } catch (JsonException $exception) {
            throw new SmartyApiException(
                'Smarty API returned invalid JSON.',
                null,
                null,
                $exception
            );
        }

        if (!\is_array($decoded)) {
            throw new SmartyApiException('Smarty API returned an unexpected response format.');
        }

        /** @var list<array<string, mixed>> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function buildSafeRequestData(array $payload, ?string $salesChannelId = null): array
    {
        return [
            'method' => 'GET',
            'endpoint' => self::US_STREET_ENDPOINT,
            'environment' => $this->getEnvironment($salesChannelId),
            'query' => $this->preparePayload($payload),
        ];
    }

    public function getValidationAgeThresholdMonths(?string $salesChannelId = null): int
    {
        $value = $this->systemConfigService->get(
            self::CONFIG_PREFIX . 'validationAgeThresholdMonths',
            $salesChannelId
        );

        if (!\is_numeric($value)) {
            return 6;
        }

        return max(1, (int) $value);
    }

    public function isGeocodingEnabled(?string $salesChannelId = null): bool
    {
        return $this->getBoolConfig('enableGeocoding', $salesChannelId, true);
    }

    public function isAutomaticStandardizationEnabled(?string $salesChannelId = null): bool
    {
        return $this->getBoolConfig('enableAutomaticStandardization', $salesChannelId, false);
    }

    public function getEnvironment(?string $salesChannelId = null): string
    {
        $environment = $this->getStringConfig('smartyEnvironment', $salesChannelId);

        return \in_array($environment, ['test', 'live'], true) ? $environment : 'test';
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function preparePayload(array $payload): array
    {
        $prepared = array_merge($payload, [
            'candidates' => (int) ($payload['candidates'] ?? 10),
            'match' => (string) ($payload['match'] ?? 'enhanced'),
        ]);

        return array_filter(
            $prepared,
            static fn (mixed $value): bool => $value !== null && $value !== ''
        );
    }

    /**
     * @return array<string, string>
     */
    private function buildAuthQuery(?string $salesChannelId): array
    {
        $authId = $this->getStringConfig('smartyAuthId', $salesChannelId);
        $websiteKey = $this->getStringConfig('smartyWebsiteKey', $salesChannelId);
        $authToken = $this->getStringConfig('smartyAuthToken', $salesChannelId);

        if ($authId !== '' && $authToken !== '') {
            return [
                'auth-id' => $authId,
                'auth-token' => $authToken,
            ];
        }

        if ($websiteKey !== '') {
            return [
                'auth-id' => $websiteKey,
            ];
        }

        return [];
    }

    private function getStringConfig(string $key, ?string $salesChannelId): string
    {
        $value = $this->systemConfigService->get(self::CONFIG_PREFIX . $key, $salesChannelId);

        return \is_string($value) ? trim($value) : '';
    }

    private function getBoolConfig(string $key, ?string $salesChannelId, bool $default): bool
    {
        $value = $this->systemConfigService->get(self::CONFIG_PREFIX . $key, $salesChannelId);

        return \is_bool($value) ? $value : $default;
    }
}
