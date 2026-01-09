<?php

declare(strict_types=1);

namespace SmartyIntegration\SalesChannel;

use SmartyIntegration\Domain\Address\AdressDto;
use SmartyIntegration\Service\SmartyApiService;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractUpsertAddressRoute;
use Shopware\Core\Checkout\Customer\SalesChannel\UpsertAddressRouteResponse;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\Validation\Exception\ConstraintViolationException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

class SmartyUpsertAddressRoute extends AbstractUpsertAddressRoute
{
    public function __construct(
        private readonly AbstractUpsertAddressRoute $decorated,
        private readonly SmartyApiService $smartyApiService
    ) {
    }

    public function getDecorated(): AbstractUpsertAddressRoute
    {
        return $this->decorated;
    }

    public function upsert(
        ?string $addressId,
        RequestDataBag $data,
        SalesChannelContext $context,
        CustomerEntity $customer
    ): UpsertAddressRouteResponse {
        $street    = (string) $data->get('street');
        $city      = (string) $data->get('city');
        $zipCode   = (string) $data->get('zipcode');
        $countryId = (string) $data->get('countryId');

        if ($street === '' || $city === '' || $zipCode === '' || $countryId === '') {
            return $this->decorated->upsert($addressId, $data, $context, $customer);
        }

        $countryIso = 'US';

        $addressDto = new AdressDto(
            $street,
            $city,
            $zipCode,
            $countryIso
        );

        $result = $this->smartyApiService->validateAdress(
            $addressDto,
            $context->getSalesChannelId()
        );

        if (!$result->isValid()) {
            $violations = new ConstraintViolationList([
                new ConstraintViolation(
                    'Invalid address. Please check street, city and ZIP code.',
                    '',
                    [],
                    null,
                    'street',
                    $street
                ),
            ]);

            throw new ConstraintViolationException($violations, $data->all());
        }

        return $this->decorated->upsert($addressId, $data, $context, $customer);
    }
}
