<?php

declare(strict_types=1);

namespace SmartyIntegration\SalesChannel;

use Psr\Log\LoggerInterface;
use SmartyIntegration\Domain\Address\AdressDto;
use SmartyIntegration\Service\SmartyApiService;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractRegisterRoute;
use Shopware\Core\Checkout\Customer\SalesChannel\CustomerResponse;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\Validation\DataValidationDefinition;
use Shopware\Core\Framework\Validation\Exception\ConstraintViolationException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

class SmartyCustomerRegisterRoute extends AbstractRegisterRoute
{
    public function __construct(
        private readonly AbstractRegisterRoute $decorated,
        private readonly SmartyApiService $smartyApiService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getDecorated(): AbstractRegisterRoute
    {
        return $this->decorated;
    }

    public function register(
        RequestDataBag $data,
        SalesChannelContext $salesChannelContext,
        bool $validateStorefrontUrl = true,
        ?DataValidationDefinition $additionalValidationDefinitions = null
    ): CustomerResponse {

        $this->logger->info('SmartyCustomerRegisterRoute::register() called');


        /** @var RequestDataBag|null $billing */
        $billing = $data->get('billingAddress');

        if ($billing instanceof RequestDataBag) {
            $this->validateAddress($billing, $data, 'billingAddress');
        }

        $differentShipping = (bool) $data->get('differentShippingAddress');

        if ($differentShipping) {
            /** @var RequestDataBag|null $shipping */
            $shipping = $data->get('shippingAddress');

            if ($shipping instanceof RequestDataBag) {
                $this->validateAddress($shipping, $data, 'shippingAddress');
            }
        }

        return $this->decorated->register(
            $data,
            $salesChannelContext,
            $validateStorefrontUrl,
            $additionalValidationDefinitions
        );
    }

    private function validateAddress(RequestDataBag $address, RequestDataBag $rootData, string $path): void
    {
        $street  = (string) $address->get('street');
        $city    = (string) $address->get('city');
        $zipCode = (string) $address->get('zipcode');

        if ($street === '' || $city === '' || $zipCode === '') {
            return;
        }

        $countryIso = 'US';

        $dto = new AdressDto(
            $street,
            $city,
            $zipCode,
            $countryIso
        );

        $result = $this->smartyApiService->validateAdress(
            $dto,
            null
        );

        if (!$result->isValid()) {
            $this->logger->error('Smarty address validation FAILED during storefront register.', [
                'path'        => $path,
                'street'      => $street,
                'city'        => $city,
                'zipcode'     => $zipCode,
                'countryIso'  => $countryIso,
                'rawResponse' => $result->getRawResponse(),
                'fullInput'   => $rootData->all(),
            ]);

            $violations = new ConstraintViolationList([
                new ConstraintViolation(
                    'Invalid address. Please check street, city and ZIP code.',
                    '',
                    [],
                    null,
                    $path . '[street]',
                    $street
                ),
            ]);

            throw new ConstraintViolationException($violations, $rootData->all());
        }

        $this->logger->info('Smarty address validated successfully.', [
            'path'    => $path,
            'street'  => $street,
            'city'    => $city,
            'zipcode' => $zipCode,
        ]);
    }
}
