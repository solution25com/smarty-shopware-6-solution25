<?php

declare(strict_types=1);

namespace SmartyAddressValidation\Service;

use Doctrine\DBAL\Connection;
use SmartyAddressValidation\Struct\AddressValidationResult;
use SmartyAddressValidation\Struct\SmartySuggestion;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\System\Country\Aggregate\CountryState\CountryStateEntity;

class AdminAddressVerificationService
{
    use AdminAddressVerificationLogHelpers;

    public function __construct(
        private readonly EntityRepository $customerAddressRepository,
        private readonly EntityRepository $orderAddressRepository,
        private readonly EntityRepository $countryStateRepository,
        private readonly SmartyAddressValidationService $validationService,
        private readonly SmartyLogger $logger,
        private readonly Connection $connection
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function validate(array $payload, Context $context): array
    {
        $addressType = $this->addressType($payload);
        $address = $this->loadAddress($addressType, $this->addressId($payload), $context);

        if ($address === null) {
            return $this->fail('Address not found.');
        }

        $customerAddressId = $address instanceof CustomerAddressEntity
            ? $address->getUniqueIdentifier()
            : null;

        $customerId = $address instanceof CustomerAddressEntity
            ? $address->getCustomerId()
            : null;

        $orderId = $address instanceof OrderAddressEntity
            ? $this->findOrderIdForOrderAddress($address->getUniqueIdentifier())
            : null;

        $result = $this->validationService->validate(
            $this->addressToArray($address),
            $context,
            null,
            $customerAddressId,
            $customerId
        );

        if ($orderId !== null) {
            $this->logger->logValidationAttempt(
                null,
                null,
                $result->getOriginalAddress(),
                $result->getRawRequest(),
                $result->getRawResponse(),
                $result->toArray(),
                $result->getError(),
                null,
                $orderId
            );
        }

        return [
            'success' => true,
            'addressType' => $addressType,
            'addressId' => $address->getUniqueIdentifier(),
            'result' => $result->toArray(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function applySuggestion(array $payload, Context $context): array
    {
        $addressType = $this->addressType($payload);
        $address = $this->loadAddress($addressType, $this->addressId($payload), $context);

        if ($address === null) {
            return $this->fail('Address not found.');
        }

        $result = $this->validateResultForAddress($address, $context);

        $index = max(0, (int) ($payload['suggestionIndex'] ?? 0));
        $suggestion = $result->getSuggestions()[$index] ?? null;

        if (!$suggestion instanceof SmartySuggestion) {
            return $this->fail('Suggestion not available anymore.');
        }

        $repository = $this->repository($addressType);

        $writeContext = $this->validationWriteContext($context);

        try {
            $repository->update([
                $this->suggestionPayload($address, $suggestion, $result, $context),
            ], $writeContext);
        } finally {
            $writeContext->removeExtension(SmartyAddressValidationService::CONTEXT_EXTENSION_VALIDATION_WRITE);
        }

        $this->logSelectedSuggestion($address, $suggestion, $result, $addressType);

        return [
            'success' => true,
            'message' => 'Smarty suggestion applied.',
            'addressId' => $address->getUniqueIdentifier(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function confirmOriginal(array $payload, Context $context): array
    {
        $addressType = $this->addressType($payload);
        $address = $this->loadAddress($addressType, $this->addressId($payload), $context);

        if ($address === null) {
            return $this->fail('Address not found.');
        }

        $result = $this->validateResultForAddress($address, $context);

        $writeContext = $this->validationWriteContext($context);

        try {
            $this->repository($addressType)->update([[
                'id' => $address->getUniqueIdentifier(),
                'customFields' => $this->customFields($address, $result, [
                    SmartyAddressValidationService::FIELD_VALID_ADDRESS => $result->isValid(),
                    SmartyAddressValidationService::FIELD_SUGGESTED_DECLINED => true,
                    SmartyAddressValidationService::FIELD_LAST_VALIDATION => $this->now(),
                ]),
            ]], $writeContext);
        } finally {
            $writeContext->removeExtension(SmartyAddressValidationService::CONTEXT_EXTENSION_VALIDATION_WRITE);
        }

        return [
            'success' => true,
            'message' => 'Original address confirmed.',
            'addressId' => $address->getUniqueIdentifier(),
        ];
    }

    private function validateResultForAddress(Entity $address, Context $context): AddressValidationResult
    {
        $customerAddressId = $address instanceof CustomerAddressEntity
            ? $address->getUniqueIdentifier()
            : null;

        $customerId = $address instanceof CustomerAddressEntity
            ? $address->getCustomerId()
            : null;

        return $this->validationService->validate(
            $this->addressToArray($address),
            $context,
            null,
            $customerAddressId,
            $customerId
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function suggestionPayload(
        Entity $address,
        SmartySuggestion $suggestion,
        AddressValidationResult $result,
        Context $context
    ): array {
        $standardized = $suggestion->toStandardizedAddress();

        $payload = [
            'id' => $address->getUniqueIdentifier(),
            'street' => $standardized['street'] ?? null,
            'zipcode' => $standardized['zipcode'] ?? null,
            'city' => $standardized['city'] ?? null,
            'additionalAddressLine1' => $standardized['additionalAddressLine1'] ?? null,
            'additionalAddressLine2' => $standardized['additionalAddressLine2'] ?? null,
            'customFields' => $this->customFields($address, $result, [
                SmartyAddressValidationService::FIELD_VALID_ADDRESS => $result->isValid(),
                SmartyAddressValidationService::FIELD_SUGGESTED_DECLINED => false,
                SmartyAddressValidationService::FIELD_LAST_VALIDATION => $this->now(),
                SmartyAddressValidationService::FIELD_LATITUDE => $result->isValid() ? $suggestion->getLatitude() : null,
                SmartyAddressValidationService::FIELD_LONGITUDE => $result->isValid() ? $suggestion->getLongitude() : null,
            ]),
        ];

        $stateId = $this->resolveStateId(
            $address,
            (string) ($standardized['countryState'] ?? ''),
            $context
        );

        if ($stateId !== null) {
            $payload['countryStateId'] = $stateId;
        }

        return array_filter(
            $payload,
            static fn (mixed $value): bool => $value !== null
        );
    }

    private function loadAddress(string $type, string $id, Context $context): ?Entity
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $criteria = (new Criteria([$id]))
            ->addAssociation('country')
            ->addAssociation('countryState');

        $address = $this->repository($type)->search($criteria, $context)->first();

        return $address instanceof Entity ? $address : null;
    }

    private function repository(string $type): EntityRepository
    {
        return $type === 'order_address'
            ? $this->orderAddressRepository
            : $this->customerAddressRepository;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function addressId(array $payload): string
    {
        return \is_scalar($payload['addressId'] ?? null)
            ? (string) $payload['addressId']
            : '';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function addressType(array $payload): string
    {
        return ($payload['addressType'] ?? '') === 'order_address'
            ? 'order_address'
            : 'customer_address';
    }

    /**
     * @return array<string, mixed>
     */
    private function addressToArray(Entity $address): array
    {
        return [
            'id' => $address->getUniqueIdentifier(),
            'street' => method_exists($address, 'getStreet') ? $address->getStreet() : '',
            'zipcode' => method_exists($address, 'getZipcode') ? $address->getZipcode() : '',
            'city' => method_exists($address, 'getCity') ? $address->getCity() : '',
            'country' => method_exists($address, 'getCountry') ? $address->getCountry()?->getIso() : '',
            'countryState' => method_exists($address, 'getCountryState')
                ? $address->getCountryState()?->getShortCode()
                : '',
            'additionalAddressLine1' => method_exists($address, 'getAdditionalAddressLine1')
                ? $address->getAdditionalAddressLine1()
                : '',
            'additionalAddressLine2' => method_exists($address, 'getAdditionalAddressLine2')
                ? $address->getAdditionalAddressLine2()
                : '',
            'customFields' => method_exists($address, 'getCustomFields')
                ? ($address->getCustomFields() ?? [])
                : [],
        ];
    }

    private function resolveStateId(Entity $address, string $state, Context $context): ?string
    {
        $countryId = method_exists($address, 'getCountryId')
            ? $address->getCountryId()
            : null;

        if (!$countryId || trim($state) === '') {
            return null;
        }

        $code = strtoupper(str_replace('US-', '', trim($state)));

        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('countryId', $countryId))
            ->addFilter(new EqualsAnyFilter('shortCode', [$code, 'US-' . $code]))
            ->setLimit(1);

        $stateEntity = $this->countryStateRepository->search($criteria, $context)->first();

        return $stateEntity instanceof CountryStateEntity
            ? $stateEntity->getUniqueIdentifier()
            : null;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function customFields(Entity $address, AddressValidationResult $result, array $extra): array
    {
        $existing = method_exists($address, 'getCustomFields')
            ? ($address->getCustomFields() ?? [])
            : [];

        $extra = array_merge([
            SmartyAddressValidationService::FIELD_REQUEST_JSON => $result->getRawRequest(),
            SmartyAddressValidationService::FIELD_RESPONSE_JSON => $result->getRawResponse(),
        ], $this->normalizeInvalidValidationFields($extra));

        return array_merge($this->withoutDeprecatedSmartyJsonFields($existing), $extra);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function normalizeInvalidValidationFields(array $fields): array
    {
        $validAddress = $fields[SmartyAddressValidationService::FIELD_VALID_ADDRESS] ?? null;

        if (!\in_array($validAddress, [false, 0, '0'], true)) {
            return $fields;
        }

        $fields[SmartyAddressValidationService::FIELD_VALID_ADDRESS] = false;
        $fields[SmartyAddressValidationService::FIELD_LATITUDE] = null;
        $fields[SmartyAddressValidationService::FIELD_LONGITUDE] = null;

        return $fields;
    }

    private function logSelectedSuggestion(
        Entity $address,
        SmartySuggestion $suggestion,
        AddressValidationResult $result,
        string $type
    ): void {
        $orderId = $type === 'order_address'
            ? $this->findOrderIdForOrderAddress($address->getUniqueIdentifier())
            : null;

        $this->logger->logValidationAttempt(
            $type === 'customer_address' ? $address->getUniqueIdentifier() : null,
            $address instanceof CustomerAddressEntity ? $address->getCustomerId() : null,
            $result->getOriginalAddress(),
            $result->getRawRequest(),
            $result->getRawResponse(),
            $result->toArray(),
            $result->getError(),
            null,
            $orderId,
            $suggestion->toArray()
        );
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
    }

    private function validationWriteContext(Context $context): Context
    {
        $context->addExtension(
            SmartyAddressValidationService::CONTEXT_EXTENSION_VALIDATION_WRITE,
            new ArrayEntity(['source' => 'smarty'])
        );

        return $context;
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

    /**
     * @return array<string, mixed>
     */
    private function fail(string $message): array
    {
        return [
            'success' => false,
            'error' => $message,
        ];
    }
}
