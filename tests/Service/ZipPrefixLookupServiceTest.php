<?php declare(strict_types=1);

namespace SmartyAddressValidation\Tests\Service;

use Doctrine\DBAL\Connection;
use SmartyAddressValidation\Service\ZipPrefixLookupService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ZipPrefixLookupServiceTest extends TestCase
{
    public function testFindByPrefixUsesBundledNationwideResourceForThreeDigits(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([]);

        $service = new ZipPrefixLookupService(
            $connection,
            $this->createMock(LoggerInterface::class)
        );

        $suggestions = $service->findByPrefix('070');

        self::assertNotEmpty($suggestions);
        self::assertSame('07001', $suggestions[0]['zipcode']);
        self::assertSame('Avenel', $suggestions[0]['city']);
        self::assertSame('NJ', $suggestions[0]['state']);
        self::assertSame('New Jersey', $suggestions[0]['stateName']);
        self::assertSame('US', $suggestions[0]['country']);
        self::assertSame('07001 — Avenel, NJ', $suggestions[0]['label']);
    }

    public function testFindByPrefixUsesBundledNationwideResourceForCaliforniaPrefix(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([]);

        $service = new ZipPrefixLookupService(
            $connection,
            $this->createMock(LoggerInterface::class)
        );

        $suggestions = $service->findByPrefix('950');
        $zipcodes = array_column($suggestions, 'zipcode');

        self::assertContains('95010', $zipcodes);
        self::assertContains('95011', $zipcodes);
        self::assertLessThanOrEqual(10, \count($suggestions));
    }
}
