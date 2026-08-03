<?php declare(strict_types=1);

namespace SmartyAddressValidation\Tests\Service;

use SmartyAddressValidation\Service\AddressNormalizer;
use SmartyAddressValidation\Service\SmartyAddressValidationService;
use SmartyAddressValidation\Service\SmartyClient;
use SmartyAddressValidation\Service\SmartyLogger;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;

class SmartyAddressValidationServiceTest extends TestCase
{
    public function testPostalMatchWithCorrectedZipcodeIsVerifiedAndStoresGeocode(): void
    {
        $smartyClient = $this->createMock(SmartyClient::class);
        $smartyClient->method('isConfigured')->willReturn(true);
        $smartyClient->method('buildSafeRequestData')->willReturn([
            'method' => 'GET',
            'endpoint' => 'https://us-street.api.smarty.com/street-address',
            'environment' => 'test',
            'query' => [
                'street' => '123 Verified Ave',
                'city' => 'Example City',
                'state' => 'EX',
                'zipcode' => '10000',
            ],
        ]);
        $smartyClient->method('validateUsStreetAddress')->willReturn([
            [
                'delivery_line_1' => '123 Verified Ave',
                'last_line' => 'Example City EX 10001-1234',
                'components' => [
                    'primary_number' => '123',
                    'street_name' => 'Verified',
                    'street_suffix' => 'Ave',
                    'city_name' => 'Example City',
                    'state_abbreviation' => 'EX',
                    'zipcode' => '10001',
                    'plus4_code' => '1234',
                ],
                'metadata' => [
                    'latitude' => 12.34567,
                    'longitude' => -76.54321,
                ],
                'analysis' => [
                    'dpv_match_code' => 'Y',
                    'enhanced_match' => 'postal-match,ignored-input',
                ],
            ],
        ]);

        $logger = $this->createMock(SmartyLogger::class);
        $logger->method('diagnostic');
        $logger->method('logValidationAttempt');

        $service = new SmartyAddressValidationService(
            $smartyClient,
            new AddressNormalizer(),
            $logger
        );

        $result = $service->validate([
            'street' => '123 Verified Ave',
            'city' => 'Example City',
            'countryState' => 'EX',
            'zipcode' => '10000',
            'country' => 'US',
        ], Context::createDefaultContext());

        self::assertTrue($result->isValid());
        self::assertSame(12.34567, $result->getLatitude());
        self::assertSame(-76.54321, $result->getLongitude());

        $customFields = $service->buildCustomerAddressCustomFields($result);

        self::assertTrue($customFields[SmartyAddressValidationService::FIELD_VALID_ADDRESS]);
        self::assertSame(12.34567, $customFields[SmartyAddressValidationService::FIELD_LATITUDE]);
        self::assertSame(-76.54321, $customFields[SmartyAddressValidationService::FIELD_LONGITUDE]);
        self::assertSame('10001', $result->getStandardizedAddress()['zipcode'] ?? null);
    }

    public function testMalformedZipcodeKeepsSmartySuggestionButIsNotVerified(): void
    {
        $smartyClient = $this->createMock(SmartyClient::class);
        $smartyClient->expects($this->once())
            ->method('buildSafeRequestData')
            ->willReturn([
                'method' => 'GET',
                'query' => [
                    'zipcode' => '123456',
                ],
            ]);
        $smartyClient->expects($this->once())->method('isConfigured')->willReturn(true);
        $smartyClient->expects($this->once())
            ->method('validateUsStreetAddress')
            ->willReturn([
                [
                    'delivery_line_1' => '123 Example Ave',
                    'last_line' => 'Example City EX 12345',
                    'components' => [
                        'primary_number' => '123',
                        'street_name' => 'Example',
                        'street_suffix' => 'Ave',
                        'city_name' => 'Example City',
                        'state_abbreviation' => 'EX',
                        'zipcode' => '12345',
                    ],
                    'metadata' => [
                        'latitude' => 12.34567,
                        'longitude' => -76.54321,
                    ],
                    'analysis' => [
                        'dpv_match_code' => 'Y',
                        'enhanced_match' => 'postal-match',
                    ],
                ],
            ]);

        $logger = $this->createMock(SmartyLogger::class);
        $logger->method('diagnostic');
        $logger->method('logValidationAttempt');

        $service = new SmartyAddressValidationService(
            $smartyClient,
            new AddressNormalizer(),
            $logger
        );

        $result = $service->validate([
            'street' => '123 Example Ave',
            'city' => 'Example City',
            'countryState' => 'EX',
            'zipcode' => '123456',
            'country' => 'US',
        ], Context::createDefaultContext());

        self::assertFalse($result->isValid());
        self::assertCount(1, $result->getSuggestions());
        self::assertSame('12345', $result->getStandardizedAddress()['zipcode'] ?? null);

        $customFields = $service->buildCustomerAddressCustomFields($result);

        self::assertFalse($customFields[SmartyAddressValidationService::FIELD_VALID_ADDRESS]);
        self::assertNull($customFields[SmartyAddressValidationService::FIELD_LATITUDE]);
        self::assertNull($customFields[SmartyAddressValidationService::FIELD_LONGITUDE]);
    }
}
