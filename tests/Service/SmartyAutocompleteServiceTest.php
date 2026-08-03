<?php declare(strict_types=1);

namespace SmartyAddressValidation\Tests\Service;

use SmartyAddressValidation\Service\SmartyAutocompleteService;
use SmartyAddressValidation\Service\SmartyLogger;
use SmartyAddressValidation\Service\ZipPrefixLookupService;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class SmartyAutocompleteServiceTest extends TestCase
{
    public function testAutocompleteZipUsesLocalPrefixLookupForThreeDigits(): void
    {
        $systemConfig = $this->systemConfig([
            'SmartyAddressValidation.config.enableStorefrontValidation' => true,
            'SmartyAddressValidation.config.smartyAuthId' => 'auth-id',
            'SmartyAddressValidation.config.smartyAuthToken' => 'auth-token',
            'SmartyAddressValidation.config.smartyWebsiteKey' => '',
        ]);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->never())->method('request');

        $zipLookup = $this->createMock(ZipPrefixLookupService::class);
        $zipLookup->expects($this->once())
            ->method('findByPrefix')
            ->with('070')
            ->willReturn([
                [
                    'zipcode' => '07001',
                    'city' => 'Avenel',
                    'state' => 'NJ',
                    'stateName' => 'New Jersey',
                    'country' => 'US',
                    'label' => '07001 — Avenel, NJ',
                ],
            ]);

        $service = $this->service($systemConfig, $httpClient, $zipLookup);

        $result = $service->autocompleteZip(['zipcode' => '070'], null);

        self::assertTrue($result['success']);
        self::assertSame('07001', $result['suggestions'][0]['zipcode']);
        self::assertSame('Avenel', $result['suggestions'][0]['city']);
    }

    public function testAutocompleteZipUsesLocalFallbackForFourDigits(): void
    {
        $systemConfig = $this->systemConfig([
            'SmartyAddressValidation.config.enableStorefrontValidation' => true,
            'SmartyAddressValidation.config.smartyAuthId' => 'auth-id',
            'SmartyAddressValidation.config.smartyAuthToken' => 'auth-token',
            'SmartyAddressValidation.config.smartyWebsiteKey' => '',
        ]);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->never())->method('request');

        $zipLookup = $this->createMock(ZipPrefixLookupService::class);
        $zipLookup->expects($this->once())
            ->method('findByPrefix')
            ->with('9501')
            ->willReturn([
                [
                    'zipcode' => '95014',
                    'city' => 'Cupertino',
                    'state' => 'CA',
                    'stateName' => 'California',
                    'country' => 'US',
                    'label' => '95014 — Cupertino, CA',
                ],
            ]);

        $service = $this->service($systemConfig, $httpClient, $zipLookup);

        $result = $service->autocompleteZip(['zipcode' => '9501'], null);

        self::assertTrue($result['success']);
        self::assertSame('95014', $result['suggestions'][0]['zipcode']);
        self::assertSame('Cupertino', $result['suggestions'][0]['city']);
    }

    public function testAutocompleteZipUsesLocalPrefixLookupForFourDigits(): void
    {
        $systemConfig = $this->systemConfig([
            'SmartyAddressValidation.config.enableStorefrontValidation' => true,
            'SmartyAddressValidation.config.smartyAuthId' => 'auth-id',
            'SmartyAddressValidation.config.smartyAuthToken' => 'auth-token',
            'SmartyAddressValidation.config.smartyWebsiteKey' => '',
        ]);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->never())->method('request');

        $zipLookup = $this->createMock(ZipPrefixLookupService::class);
        $zipLookup->expects($this->once())
            ->method('findByPrefix')
            ->with('0700')
            ->willReturn([
                [
                    'zipcode' => '07001',
                    'city' => 'Avenel',
                    'state' => 'NJ',
                    'stateName' => 'New Jersey',
                    'country' => 'US',
                    'label' => '07001 — Avenel, NJ',
                ],
                [
                    'zipcode' => '07008',
                    'city' => 'Carteret',
                    'state' => 'NJ',
                    'stateName' => 'New Jersey',
                    'country' => 'US',
                    'label' => '07008 — Carteret, NJ',
                ],
            ]);

        $service = $this->service($systemConfig, $httpClient, $zipLookup);

        $result = $service->autocompleteZip(['zipcode' => '0700'], null);

        self::assertTrue($result['success']);
        self::assertCount(2, $result['suggestions']);
        self::assertSame('07001', $result['suggestions'][0]['zipcode']);
        self::assertSame('Avenel', $result['suggestions'][0]['city']);
        self::assertSame('NJ', $result['suggestions'][0]['state']);
    }

    public function testAutocompleteZipReturnsEmptyBeforeThreeDigits(): void
    {
        $systemConfig = $this->systemConfig([
            'SmartyAddressValidation.config.enableStorefrontValidation' => true,
            'SmartyAddressValidation.config.smartyAuthId' => 'auth-id',
            'SmartyAddressValidation.config.smartyAuthToken' => 'auth-token',
            'SmartyAddressValidation.config.smartyWebsiteKey' => '',
        ]);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->never())->method('request');

        $zipLookup = $this->createMock(ZipPrefixLookupService::class);
        $zipLookup->expects($this->never())->method('findByPrefix');

        $service = $this->service($systemConfig, $httpClient, $zipLookup);

        $result = $service->autocompleteZip(['zipcode' => '07'], null);

        self::assertTrue($result['success']);
        self::assertSame([], $result['suggestions']);
    }

    public function testAutocompleteZipUsesSmartyForFiveDigits(): void
    {
        $systemConfig = $this->systemConfig([
            'SmartyAddressValidation.config.enableStorefrontValidation' => true,
            'SmartyAddressValidation.config.smartyAuthId' => 'auth-id',
            'SmartyAddressValidation.config.smartyAuthToken' => 'auth-token',
            'SmartyAddressValidation.config.smartyWebsiteKey' => '',
        ]);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->with(false)->willReturn(json_encode([
            [
                'zipcodes' => [
                    ['zipcode' => '95014'],
                ],
                'city_states' => [
                    ['city' => 'Cupertino', 'state_abbreviation' => 'CA'],
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'https://us-zipcode.api.smarty.com/lookup',
                $this->callback(static function (array $options): bool {
                    $query = $options['query'] ?? [];

                    return ($query['zipcode'] ?? null) === '95014'
                        && ($query['auth-id'] ?? null) === 'auth-id'
                        && ($query['auth-token'] ?? null) === 'auth-token';
                })
            )
            ->willReturn($response);

        $zipLookup = $this->createMock(ZipPrefixLookupService::class);
        $zipLookup->expects($this->never())->method('findByPrefix');

        $service = $this->service($systemConfig, $httpClient, $zipLookup);

        $result = $service->autocompleteZip(['zipcode' => '95014'], null);

        self::assertTrue($result['success']);
        self::assertCount(1, $result['suggestions']);
        self::assertSame('95014', $result['suggestions'][0]['zipcode']);
        self::assertSame('Cupertino', $result['suggestions'][0]['city']);
        self::assertSame('CA', $result['suggestions'][0]['state']);
    }

    public function testAutocompleteStreetRetriesAndUsesCredentialsWithoutWebsiteKey(): void
    {
        $systemConfig = $this->systemConfig([
            'SmartyAddressValidation.config.enableStorefrontValidation' => true,
            'SmartyAddressValidation.config.smartyAuthId' => 'auth-id',
            'SmartyAddressValidation.config.smartyAuthToken' => 'auth-token',
            'SmartyAddressValidation.config.smartyWebsiteKey' => '',
        ]);

        $emptyResponse = $this->createMock(ResponseInterface::class);
        $emptyResponse->method('getStatusCode')->willReturn(200);
        $emptyResponse->method('getContent')->with(false)->willReturn(json_encode([
            'suggestions' => [],
        ], \JSON_THROW_ON_ERROR));

        $successResponse = $this->createMock(ResponseInterface::class);
        $successResponse->method('getStatusCode')->willReturn(200);
        $successResponse->method('getContent')->with(false)->willReturn(json_encode([
            'suggestions' => [
                [
                    'street_line' => '1 Apple Park Way',
                    'city' => 'Cupertino',
                    'zipcode' => '95014',
                    'state_abbreviation' => 'CA',
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $queries = [];
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(
                static function (string $method, string $url, array $options) use (&$queries, $emptyResponse, $successResponse): ResponseInterface {
                    $queries[] = $options['query'] ?? [];

                    return \count($queries) === 1 ? $emptyResponse : $successResponse;
                }
            );

        $zipLookup = $this->createMock(ZipPrefixLookupService::class);

        $service = $this->service($systemConfig, $httpClient, $zipLookup);

        $result = $service->autocompleteStreet([
            'street' => '1 Apple',
            'zipcode' => '95014',
            'city' => 'Cupertino',
            'state' => 'CA',
            'country' => 'US',
        ], null);

        self::assertTrue($result['success']);
        self::assertCount(1, $result['suggestions']);
        self::assertSame('1 Apple Park Way', $result['suggestions'][0]['street']);
        self::assertSame('Cupertino', $result['suggestions'][0]['city']);
        self::assertSame('95014', $result['suggestions'][0]['zipcode']);
        self::assertSame('CA', $result['suggestions'][0]['state']);
        self::assertGreaterThanOrEqual(2, \count($queries));
        self::assertSame('1 Apple', $queries[0]['search']);
        self::assertSame('95014', $queries[0]['include_only_zip_codes'] ?? null);
        self::assertSame('none', $queries[0]['prefer_geolocation'] ?? null);
        self::assertArrayNotHasKey('include_only_states', $queries[0]);
        self::assertArrayNotHasKey('include_only_cities', $queries[0]);
        self::assertSame('Cupertino', $queries[1]['include_only_cities'] ?? null);
        self::assertSame('CA', $queries[1]['include_only_states'] ?? null);
        self::assertSame('auth-id', $queries[0]['auth-id']);
        self::assertSame('auth-token', $queries[0]['auth-token']);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function systemConfig(array $config): SystemConfigService
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(
            static fn (string $key, ?string $salesChannelId = null) => $config[$key] ?? null
        );

        return $systemConfig;
    }

    private function service(
        SystemConfigService $systemConfig,
        HttpClientInterface $httpClient,
        ZipPrefixLookupService $zipLookup
    ): SmartyAutocompleteService {
        $logger = $this->createMock(SmartyLogger::class);
        $logger->method('debug');
        $logger->method('warning');

        return new SmartyAutocompleteService(
            $systemConfig,
            $httpClient,
            $logger,
            $zipLookup
        );
    }
}
