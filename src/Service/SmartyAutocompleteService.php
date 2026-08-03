<?php

declare(strict_types=1);

namespace SmartyAddressValidation\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

class SmartyAutocompleteService
{
    use SmartyAutocompleteServiceHelpers;

    private const CONFIG_PREFIX = 'SmartyAddressValidation.config.';
    private const PRO_ENDPOINT = 'https://us-autocomplete-pro.api.smarty.com/lookup';
    private const ZIP_ENDPOINT = 'https://us-zipcode.api.smarty.com/lookup';
    private const REVERSE_GEO_ENDPOINT = 'https://us-reverse-geo.api.smarty.com/lookup';
    private const MAX_SUGGESTIONS = 10;

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly HttpClientInterface $httpClient,
        private readonly SmartyLogger $logger,
        private readonly ZipPrefixLookupService $zipPrefixLookupService
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function autocompleteZip(
        array $payload,
        ?string $salesChannelId = null,
        string $featureFlag = 'enableStorefrontValidation'
    ): array {
        if (!$this->isEnabled($salesChannelId, $featureFlag)) {
            return $this->empty();
        }

        $context = $this->normalizeContext($payload);
        $zipcode = $this->digits((string) ($payload['zipcode'] ?? $payload['query'] ?? ''));

        if (\strlen($zipcode) > 5) {
            $zipcode = \substr($zipcode, 0, 5);
        }

        $this->logger->debug('Smarty ZIP autocomplete normalized request.', [
            'zipcode' => $zipcode,
            'context' => $context,
        ], $salesChannelId);

        if (\strlen($zipcode) < 3) {
            return $this->empty();
        }

        if ($context['country'] !== '' && $context['country'] !== 'US') {
            return $this->empty();
        }

        if (\strlen($zipcode) < 5) {
            $suggestions = $this->zipPrefixLookupService->findByPrefix($zipcode);

            $this->logger->debug('Smarty ZIP autocomplete used local prefix fallback.', [
                'zipcodePrefix' => $zipcode,
                'suggestionCount' => \count($suggestions),
            ], $salesChannelId);

            return [
                'success' => true,
                'suggestions' => $suggestions,
            ];
        }

        if (!$this->hasSecretCredentials($salesChannelId)) {
            $this->logger->warning('Smarty ZIP lookup skipped. Auth credentials missing.', [], $salesChannelId);

            return $this->empty();
        }

        try {
            $response = $this->callZipApi($zipcode, $context, $salesChannelId);
            $suggestions = $this->normalizeZipApiResponse($response);

            $this->logger->debug('Smarty ZIP autocomplete used Smarty lookup.', [
                'zipcode' => $zipcode,
                'rawSuggestionCount' => $this->countSuggestions($response, 'zipcodes'),
                'normalizedSuggestionCount' => \count($suggestions),
            ], $salesChannelId);

            return [
                'success' => true,
                'suggestions' => $suggestions,
            ];
        } catch (Throwable $exception) {
            $this->logger->warning('Smarty ZIP lookup failed.', [
                'error' => $exception->getMessage(),
                'zipcode' => $zipcode,
                'context' => $context,
            ], $salesChannelId);

            return $this->empty();
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function autocompleteStreet(
        array $payload,
        ?string $salesChannelId = null,
        string $featureFlag = 'enableStorefrontValidation'
    ): array {
        if (!$this->isEnabled($salesChannelId, $featureFlag)) {
            return $this->empty();
        }

        $context = $this->normalizeContext($payload);
        $search = trim((string) ($payload['street'] ?? $payload['query'] ?? ''));

        $this->logger->debug('Smarty street autocomplete normalized request.', [
            'search' => $search,
            'context' => $context,
        ], $salesChannelId);

        if (\strlen($search) < 3) {
            return $this->empty();
        }

        if ($context['country'] !== '' && $context['country'] !== 'US') {
            return $this->empty();
        }

        try {
            $credentials = $this->streetAuthQuery($salesChannelId);

            if ($credentials === []) {
                $this->logger->warning('Smarty street autocomplete skipped. Credentials missing.', [
                    'search' => $search,
                ], $salesChannelId);

                return $this->empty();
            }

            $attempts = $this->buildStreetQueryAttempts($search, $context);

            foreach ($attempts as $attemptIndex => $query) {
                $response = $this->callAutocompletePro($query, $salesChannelId, $credentials, $attemptIndex + 1);
                $suggestions = $this->normalizeStreetResponse($response);

                $this->logger->debug('Smarty street autocomplete normalized response.', [
                    'attempt' => $attemptIndex + 1,
                    'rawSuggestionCount' => $this->countSuggestions($response, 'suggestions'),
                    'normalizedSuggestionCount' => \count($suggestions),
                ], $salesChannelId);

                if ($suggestions !== []) {
                    return [
                        'success' => true,
                        'suggestions' => $suggestions,
                    ];
                }
            }

            return [
                'success' => true,
                'suggestions' => [],
            ];
        } catch (Throwable $exception) {
            $this->logger->warning('Smarty street autocomplete failed.', [
                'error' => $exception->getMessage(),
                'search' => $search,
                'context' => $context,
            ], $salesChannelId);

            return $this->empty();
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function reverseGeocode(
        array $payload,
        ?string $salesChannelId = null,
        string $featureFlag = 'enableStorefrontValidation'
    ): array {
        if (!$this->isEnabled($salesChannelId, $featureFlag)) {
            return $this->empty();
        }

        $latitude = $this->coordinate($payload['latitude'] ?? null);
        $longitude = $this->coordinate($payload['longitude'] ?? null);

        if ($latitude === null || $longitude === null) {
            return $this->empty();
        }

        if (!$this->hasSecretCredentials($salesChannelId)) {
            $this->logger->warning('Smarty reverse geocode skipped. Auth credentials missing.', [], $salesChannelId);

            return $this->empty();
        }

        try {
            $response = $this->callReverseGeo($latitude, $longitude, $salesChannelId);
            $suggestions = $this->normalizeReverseGeoResponse($response);

            $this->logger->debug('Smarty reverse geocode normalized response.', [
                'normalizedSuggestionCount' => \count($suggestions),
            ], $salesChannelId);

            return [
                'success' => true,
                'suggestions' => $suggestions,
            ];
        } catch (Throwable $exception) {
            $this->logger->warning('Smarty reverse geocode failed.', [
                'error' => $exception->getMessage(),
            ], $salesChannelId);

            return $this->empty();
        }
    }

    /**
     * @return array<mixed>
     */
    private function callReverseGeo(float $latitude, float $longitude, ?string $salesChannelId): array
    {
        $query = array_merge($this->secretAuthQuery($salesChannelId), [
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);

        $this->logSafeRequest('Smarty reverse geocode request.', self::REVERSE_GEO_ENDPOINT, $query, $salesChannelId, [
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);

        return $this->getJson(self::REVERSE_GEO_ENDPOINT, $query);
    }

    private function coordinate(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        $float = (float) $value;

        return ($float >= -180.0 && $float <= 180.0) ? $float : null;
    }

    /**
     * @param array<string, string> $context
     *
     * @return list<array<string, mixed>>
     */
    private function callZipApi(
        string $zipcode,
        array $context,
        ?string $salesChannelId
    ): array {
        $query = array_merge($this->secretAuthQuery($salesChannelId), [
            'zipcode' => $zipcode,
        ]);

        if ($context['city'] !== '') {
            $query['city'] = $context['city'];
        }

        if ($context['state'] !== '') {
            $query['state'] = $context['state'];
        }

        $this->logSafeRequest('Smarty ZIP lookup request.', self::ZIP_ENDPOINT, $query, $salesChannelId, [
            'zipcode' => $zipcode,
            'context' => $context,
        ]);

        /** @var list<array<string, mixed>> $response */
        $response = $this->getJson(self::ZIP_ENDPOINT, $query);

        return $response;
    }

    private function callAutocompletePro(
        array $query,
        ?string $salesChannelId,
        ?array $credentials = null,
        int $attempt = 1
    ): array {
        $credentials ??= $this->streetAuthQuery($salesChannelId);

        if ($credentials === []) {
            return ['suggestions' => []];
        }

        $query = array_merge($credentials, $query);

        $this->logSafeRequest('Smarty street autocomplete request.', self::PRO_ENDPOINT, $query, $salesChannelId, [
            'attempt' => $attempt,
        ]);

        return $this->getJson(self::PRO_ENDPOINT, $query);
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<mixed>
     */
    private function getJson(string $endpoint, array $query): array
    {
        $response = $this->httpClient->request('GET', $endpoint, [
            'query' => $query,
            'timeout' => 6,
        ]);

        if ($response->getStatusCode() >= 400) {
            return [];
        }

        $decoded = json_decode($response->getContent(false), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, string>
     */
    private function secretAuthQuery(?string $salesChannelId): array
    {
        return [
            'auth-id' => $this->stringConfig('smartyAuthId', $salesChannelId),
            'auth-token' => $this->stringConfig('smartyAuthToken', $salesChannelId),
        ];
    }

    private function hasSecretCredentials(?string $salesChannelId): bool
    {
        return $this->stringConfig('smartyAuthId', $salesChannelId) !== ''
            && $this->stringConfig('smartyAuthToken', $salesChannelId) !== '';
    }

    private function isEnabled(?string $salesChannelId, string $featureFlag = 'enableStorefrontValidation'): bool
    {
        $value = $this->systemConfigService->get(
            self::CONFIG_PREFIX . $featureFlag,
            $salesChannelId
        );

        return $value !== false;
    }

    private function stringConfig(string $key, ?string $salesChannelId): string
    {
        $value = $this->systemConfigService->get(self::CONFIG_PREFIX . $key, $salesChannelId);

        return \is_string($value) ? trim($value) : '';
    }

    /**
     * @param array<string, mixed> $query
     */
    private function logSafeRequest(
        string $message,
        string $endpoint,
        array $query,
        ?string $salesChannelId,
        array $context = []
    ): void {
        unset($query['key'], $query['auth-id'], $query['auth-token']);

        $this->logger->debug($message, [
            'endpoint' => $endpoint,
            'query' => $query,
            'context' => $context,
        ], $salesChannelId);
    }

    private function streetAuthQuery(?string $salesChannelId): array
    {
        $authId = $this->stringConfig('smartyAuthId', $salesChannelId);
        $authToken = $this->stringConfig('smartyAuthToken', $salesChannelId);
        $websiteKey = $this->stringConfig('smartyWebsiteKey', $salesChannelId);

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
}
