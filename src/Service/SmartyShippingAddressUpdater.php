<?php

declare(strict_types=1);

namespace SmartyIntegration\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\Country\CountryEntity;
use SmartyIntegration\Domain\Address\SmartyValidationResult;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

final class SmartyShippingAddressUpdater
{
    public function __construct(
        private readonly EntityRepository $customerAddressRepository,
        private readonly EntityRepository $countryRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function updateFromSmarty(
        SmartyValidationResult $result,
        string $shippingAddressId,
        Context $context,
        string $originalStreet,
        string $originalCity,
        string $originalPostalCode,
        string $originalCountryIso
    ): bool {
        $standardStreet     = $result->getStandardizedStreet() ?? $originalStreet;
        $standardCity       = $result->getStandardizedCity() ?? $originalCity;
        $standardPostalCode = $result->getStandardizedPostalCode() ?? $originalPostalCode;
        $standardCountryIso = $result->getStandardizedCountryIso() ?? $originalCountryIso;

        $countryId = $this->resolveCountryIdByIso($standardCountryIso, $context);

        $updateData = [
            'id'      => $shippingAddressId,
            'street'  => $standardStreet,
            'zipcode' => $standardPostalCode,
            'city'    => $standardCity,
        ];

        if ($countryId) {
            $updateData['countryId'] = $countryId;
        }

        $this->customerAddressRepository->update([$updateData], $context);

        $this->logger->info('Updated shipping address from Smarty', [
            'shippingAddressId' => $shippingAddressId,
            'street'            => $standardStreet,
            'city'              => $standardCity,
            'postalCode'        => $standardPostalCode,
            'countryIso'        => $standardCountryIso,
            'countryId'         => $countryId,
        ]);

        return true;
    }

    private function resolveCountryIdByIso(string $countryIso, Context $context): ?string
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('iso', strtoupper($countryIso)))
            ->setLimit(1);

        /** @var CountryEntity|null $country */
        $country = $this->countryRepository->search($criteria, $context)->first();

        return $country ? $country->getId() : null;
    }
}
