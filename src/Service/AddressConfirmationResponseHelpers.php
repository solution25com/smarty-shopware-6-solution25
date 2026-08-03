<?php

declare(strict_types=1);

namespace SmartyAddressValidation\Service;

use DateTimeImmutable;
use DateTimeInterface;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\ArrayEntity;

trait AddressConfirmationResponseHelpers
{
    /**
     * @return array<string, mixed>
     */
    private function addressToArray(CustomerAddressEntity $address): array
    {
        return [
            'id' => $address->getId(),
            'firstName' => $address->getFirstName(),
            'lastName' => $address->getLastName(),
            'company' => $address->getCompany(),
            'street' => $address->getStreet(),
            'zipcode' => $address->getZipcode(),
            'city' => $address->getCity(),
            'countryId' => $address->getCountryId(),
            'countryStateId' => $address->getCountryStateId(),
            'country' => [
                'id' => $address->getCountry()?->getId(),
                'iso' => $address->getCountry()?->getIso(),
                'name' => $address->getCountry()?->getName(),
            ],
            'countryState' => [
                'id' => $address->getCountryState()?->getId(),
                'shortCode' => $address->getCountryState()?->getShortCode(),
                'name' => $address->getCountryState()?->getName(),
            ],
            'additionalAddressLine1' => $address->getAdditionalAddressLine1(),
            'additionalAddressLine2' => $address->getAdditionalAddressLine2(),
            'customFields' => $address->getCustomFields() ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function status(
        bool $enabled,
        bool $shouldValidate,
        ?string $addressId,
        ?string $reason,
        ?array $address
    ): array {
        return [
            'enabled' => $enabled,
            'shouldValidate' => $shouldValidate,
            'addressId' => $addressId,
            'reason' => $reason,
            'address' => $address,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(string $message): array
    {
        return [
            'success' => false,
            'error' => $message,
        ];
    }

    private function validationWriteContext(Context $context): Context
    {
        $context->addExtension(
            SmartyAddressValidationService::CONTEXT_EXTENSION_VALIDATION_WRITE,
            new ArrayEntity(['source' => 'smarty'])
        );

        return $context;
    }

    private function now(): string
    {
        return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
    }

    /**
     * @param array<string, mixed> $customFields
     * @return array<string, mixed>
     */
    private function withoutDeprecatedSmartyJsonFields(array $customFields): array
    {
        unset(
            $customFields[SmartyAddressValidationService::FIELD_STANDARDIZED_JSON]
        );

        return $customFields;
    }
}
