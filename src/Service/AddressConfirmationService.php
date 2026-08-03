<?php

declare(strict_types=1);

namespace SmartyAddressValidation\Service;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Exception;
use SmartyAddressValidation\Struct\AddressValidationResult;
use SmartyAddressValidation\Struct\SmartySuggestion;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\System\Country\Aggregate\CountryState\CountryStateEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

class AddressConfirmationService
{
    use AddressConfirmationResponseHelpers;

    private const CONFIG_PREFIX = 'SmartyAddressValidation.config.';

    public function __construct(
        private readonly EntityRepository $customerAddressRepository,
        private readonly EntityRepository $countryStateRepository,
        private readonly SystemConfigService $systemConfigService,
        private readonly SmartyAddressValidationService $validationService,
        private readonly SmartyLogger $logger
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getStatus(SalesChannelContext $context, Request $request): array
    {
        if (!$this->isStorefrontValidationEnabled($context)) {
            return $this->status(false, false, null, 'disabled', null);
        }

        if ($this->isCheckoutRequest($request)) {
            return $this->status(true, false, null, 'checkout_page', null);
        }

        $customer = $this->getLoggedInCustomer($context);

        if ($customer === null) {
            return $this->status(true, false, null, 'no_customer', null);
        }

        $address = $this->getPreferredCustomerAddress($customer, $context->getContext());

        if ($address === null) {
            return $this->status(true, false, null, 'no_address', null);
        }

        $reason = $this->getValidationReason($address, $context);

        if ($reason === null) {
            return $this->status(true, false, $address->getId(), null, null);
        }

        return $this->status(
            true,
            true,
            $address->getId(),
            $reason,
            $this->addressToArray($address)
        );
    }

    public function validateAddress(
        string $addressId,
        SalesChannelContext $context
    ): AddressValidationResult {
        $customer = $this->getLoggedInCustomer($context);

        if ($customer === null) {
            return AddressValidationResult::error([], null, null, 'No logged-in customer.');
        }

        $address = $this->loadOwnedAddress($addressId, $customer, $context->getContext());

        if ($address === null) {
            return AddressValidationResult::error([], null, null, 'Address not found.');
        }

        return $this->validationService->validate(
            $this->addressToArray($address),
            $context->getContext(),
            $context->getSalesChannelId(),
            $address->getId(),
            $customer->getId()
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function useSuggestion(
        string $addressId,
        int $suggestionIndex,
        SalesChannelContext $context
    ): array {
        $owned = $this->getOwnedAddressOrError($addressId, $context);

        if (!$owned['success']) {
            return $owned;
        }

        /** @var CustomerAddressEntity $address */
        $address = $owned['address'];
        /** @var CustomerEntity $customer */
        $customer = $owned['customer'];

        $result = $this->validateAddress($addressId, $context);
        $suggestion = $result->getSuggestions()[$suggestionIndex] ?? null;

        if (!$suggestion instanceof SmartySuggestion) {
            return $this->failure('Smarty suggestion is no longer available.');
        }

        $updatePayload = array_merge(
            $this->buildSuggestionAddressPayload($suggestion, $address, $context->getContext()),
            [
                'id' => $addressId,
                'customFields' => $this->mergeCustomFields($address, [
                    SmartyAddressValidationService::FIELD_VALID_ADDRESS => $result->isValid(),
                    SmartyAddressValidationService::FIELD_AUTOCOMPLETE_USED => true,
                    SmartyAddressValidationService::FIELD_AUTOCOMPLETE_CHANGED => false,
                    SmartyAddressValidationService::FIELD_SUGGESTED_DECLINED => false,
                    SmartyAddressValidationService::FIELD_LAST_VALIDATION => $this->now(),
                    SmartyAddressValidationService::FIELD_LATITUDE => $result->isValid() ? $result->getLatitude() : null,
                    SmartyAddressValidationService::FIELD_LONGITUDE => $result->isValid() ? $result->getLongitude() : null,
                ], $result),
            ]
        );

        return $this->updateAddress(
            $updatePayload,
            $context,
            $customer->getId(),
            'Smarty suggestion applied.',
            $this->validationWriteContext($context->getContext())
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function useOriginal(string $addressId, SalesChannelContext $context): array
    {
        $owned = $this->getOwnedAddressOrError($addressId, $context);

        if (!$owned['success']) {
            return $owned;
        }

        /** @var CustomerAddressEntity $address */
        $address = $owned['address'];
        /** @var CustomerEntity $customer */
        $customer = $owned['customer'];

        $result = $this->validateAddress($addressId, $context);

        $customFields = [
            SmartyAddressValidationService::FIELD_LAST_VALIDATION => $this->now(),
            SmartyAddressValidationService::FIELD_VALID_ADDRESS => $result->isValid(),
            SmartyAddressValidationService::FIELD_SUGGESTED_DECLINED => true,
        ];

        if ($result->isValid()) {
            $customFields[SmartyAddressValidationService::FIELD_LATITUDE] = $result->getLatitude();
            $customFields[SmartyAddressValidationService::FIELD_LONGITUDE] = $result->getLongitude();
        }

        return $this->updateAddress([
            'id' => $addressId,
            'customFields' => $this->mergeCustomFields($address, $customFields, $result),
        ], $context, $customer->getId(), 'Original address confirmed.', $this->validationWriteContext($context->getContext()));
    }

    /**
     * @return array<string, mixed>
     */
    public function confirmValid(string $addressId, SalesChannelContext $context): array
    {
        $owned = $this->getOwnedAddressOrError($addressId, $context);

        if (!$owned['success']) {
            return $owned;
        }

        /** @var CustomerAddressEntity $address */
        $address = $owned['address'];
        /** @var CustomerEntity $customer */
        $customer = $owned['customer'];

        $result = $this->validateAddress($addressId, $context);

        return $this->updateAddress([
            'id' => $addressId,
            'customFields' => $this->mergeCustomFields($address, [
                SmartyAddressValidationService::FIELD_VALID_ADDRESS => $result->isValid(),
                SmartyAddressValidationService::FIELD_SUGGESTED_DECLINED => false,
                SmartyAddressValidationService::FIELD_LAST_VALIDATION => $this->now(),
                SmartyAddressValidationService::FIELD_LATITUDE => $result->isValid() ? $result->getLatitude() : null,
                SmartyAddressValidationService::FIELD_LONGITUDE => $result->isValid() ? $result->getLongitude() : null,
            ], $result),
        ], $context, $customer->getId(), 'Valid address confirmed.', $this->validationWriteContext($context->getContext()));
    }

    private function getLoggedInCustomer(SalesChannelContext $context): ?CustomerEntity
    {
        $customer = $context->getCustomer();

        if ($customer === null || $customer->getGuest()) {
            return null;
        }

        return $customer;
    }

    private function getPreferredCustomerAddress(
        CustomerEntity $customer,
        Context $context
    ): ?CustomerAddressEntity {
        $ids = array_values(array_unique(array_filter([
            $customer->getActiveShippingAddress()?->getId(),
            $customer->getDefaultShippingAddressId(),
            $customer->getActiveBillingAddress()?->getId(),
            $customer->getDefaultBillingAddressId(),
        ])));

        foreach ($ids as $id) {
            $address = $this->loadOwnedAddress((string) $id, $customer, $context);

            if ($address !== null) {
                return $address;
            }
        }

        return null;
    }

    private function loadOwnedAddress(
        string $addressId,
        CustomerEntity $customer,
        Context $context
    ): ?CustomerAddressEntity {
        $criteria = (new Criteria([$addressId]))
            ->addFilter(new EqualsFilter('customerId', $customer->getId()))
            ->addAssociation('country')
            ->addAssociation('countryState');

        $address = $this->customerAddressRepository->search($criteria, $context)->first();

        return $address instanceof CustomerAddressEntity ? $address : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function getOwnedAddressOrError(string $addressId, SalesChannelContext $context): array
    {
        $customer = $this->getLoggedInCustomer($context);

        if ($customer === null) {
            return $this->failure('No logged-in customer.');
        }

        $address = $this->loadOwnedAddress($addressId, $customer, $context->getContext());

        if ($address === null) {
            return $this->failure('Address not found or does not belong to customer.');
        }

        return [
            'success' => true,
            'customer' => $customer,
            'address' => $address,
        ];
    }

    private function getValidationReason(
        CustomerAddressEntity $address,
        SalesChannelContext $context
    ): ?string {
        $customFields = $address->getCustomFields() ?? [];
        $lastValidation = $customFields[SmartyAddressValidationService::FIELD_LAST_VALIDATION] ?? null;

        if ($lastValidation === null || $lastValidation === '') {
            return 'missing_validation';
        }

        if ($this->isOlderThanThreshold((string) $lastValidation, $context)) {
            return 'older_than_threshold';
        }

        return null;
    }

    private function isOlderThanThreshold(string $date, SalesChannelContext $context): bool
    {
        try {
            $lastValidation = new DateTimeImmutable($date);
        } catch (Throwable) {
            return true;
        }

        $threshold = $this->getValidationAgeThresholdMonths($context);
        $oldestAllowed = (new DateTimeImmutable())->modify(sprintf('-%d months', $threshold));

        return $lastValidation < $oldestAllowed;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSuggestionAddressPayload(
        SmartySuggestion $suggestion,
        CustomerAddressEntity $currentAddress,
        Context $context
    ): array {
        $standardized = $suggestion->toStandardizedAddress();

        $payload = [
            'street' => $standardized['street'] ?? $currentAddress->getStreet(),
            'zipcode' => $standardized['zipcode'] ?? $currentAddress->getZipcode(),
            'city' => $standardized['city'] ?? $currentAddress->getCity(),
            'additionalAddressLine1' => $standardized['additionalAddressLine1'] ?? null,
            'additionalAddressLine2' => $standardized['additionalAddressLine2'] ?? null,
        ];

        $stateId = $this->resolveCountryStateId(
            (string) ($standardized['countryState'] ?? ''),
            $currentAddress->getCountryId(),
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

    private function resolveCountryStateId(string $stateCode, string $countryId, Context $context): ?string
    {
        $stateCode = strtoupper(trim($stateCode));

        if ($stateCode === '') {
            return null;
        }

        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('countryId', $countryId))
            ->addFilter(new EqualsAnyFilter('shortCode', [$stateCode, 'US-' . $stateCode]))
            ->setLimit(1);

        $state = $this->countryStateRepository->search($criteria, $context)->first();

        return $state instanceof CountryStateEntity ? $state->getId() : null;
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function mergeCustomFields(
        CustomerAddressEntity $address,
        array $fields,
        AddressValidationResult $result
    ): array {
        $fields = array_merge([
            SmartyAddressValidationService::FIELD_REQUEST_JSON => $result->getRawRequest(),
            SmartyAddressValidationService::FIELD_RESPONSE_JSON => $result->getRawResponse(),
        ], $this->normalizeInvalidValidationFields($fields));

        return array_merge($this->withoutDeprecatedSmartyJsonFields($address->getCustomFields() ?? []), $fields);
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

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function updateAddress(
        array $payload,
        SalesChannelContext $context,
        string $customerId,
        string $message,
        ?Context $writeContext = null
    ): array {
        $writeContext ??= $context->getContext();
        $cleanupValidationExtension = $writeContext->hasExtension(SmartyAddressValidationService::CONTEXT_EXTENSION_VALIDATION_WRITE);

        try {
            $this->customerAddressRepository->update([$payload], $writeContext);

            return [
                'success' => true,
                'message' => $message,
                'customerId' => $customerId,
                'addressId' => $payload['id'],
            ];
        } catch (Throwable $exception) {
            $this->logger->warning('Smarty address confirmation update failed.', [
                'addressId' => $payload['id'] ?? null,
                'error' => $exception->getMessage(),
            ], $context->getSalesChannelId());

            return $this->failure('Address could not be updated.');
        } finally {
            if ($cleanupValidationExtension) {
                $writeContext->removeExtension(SmartyAddressValidationService::CONTEXT_EXTENSION_VALIDATION_WRITE);
            }
        }
    }

    private function isCheckoutRequest(Request $request): bool
    {
        $route = (string) (
            $request->query->get('currentRoute')
            ?: $request->headers->get('x-current-route')
            ?: ''
        );

        if ($route !== '' && str_contains($route, 'checkout')) {
            return true;
        }

        $path = (string) (
            $request->query->get('currentPath')
            ?: $request->headers->get('x-current-path')
            ?: $request->headers->get('referer')
            ?: ''
        );

        $parsedPath = parse_url($path, \PHP_URL_PATH);

        return \is_string($parsedPath) && str_contains($parsedPath, '/checkout');
    }

    private function isStorefrontValidationEnabled(SalesChannelContext $context): bool
    {
        return (bool) $this->systemConfigService->get(
            self::CONFIG_PREFIX . 'enableStorefrontValidation',
            $context->getSalesChannelId()
        );
    }

    private function getValidationAgeThresholdMonths(SalesChannelContext $context): int
    {
        $value = $this->systemConfigService->get(
            self::CONFIG_PREFIX . 'validationAgeThresholdMonths',
            $context->getSalesChannelId()
        );

        return \is_numeric($value) ? max(1, (int) $value) : 6;
    }
}
