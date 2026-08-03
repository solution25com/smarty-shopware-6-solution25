<?php

declare(strict_types=1);

namespace SmartyAddressValidation\Subscriber;

use SmartyAddressValidation\Service\SmartyAddressValidationService;
use SmartyAddressValidation\Service\SmartyLogger;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Customer\Event\CustomerRegisterEvent;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

class AddressValidationSubscriber implements EventSubscriberInterface
{
    use AddressValidationSubscriberDebugHelpers;

    private const CONTEXT_EXTENSION_PROCESSED_ADDRESSES = 'smarty_processed_customer_addresses';

    public function __construct(
        private readonly EntityRepository $customerAddressRepository,
        private readonly EntityRepository $orderAddressRepository,
        private readonly SmartyAddressValidationService $validationService,
        private readonly SmartyLogger $logger
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CustomerRegisterEvent::class => 'onCustomerRegister',
            'customer_address.written' => 'onCustomerAddressWritten',
            'order_address.written' => 'onOrderAddressWritten',
            CheckoutOrderPlacedEvent::class => 'onOrderPlaced',
        ];
    }

    public function onCustomerRegister(CustomerRegisterEvent $event): void
    {
        $customer = $event->getCustomer();
        $addressIds = array_values(array_filter([
            $customer->getDefaultBillingAddressId(),
            $customer->getDefaultShippingAddressId(),
        ]));

        $this->logger->diagnostic('customer.register received.', [
            'customerId' => $customer->getId(),
            'defaultBillingAddressId' => $customer->getDefaultBillingAddressId(),
            'defaultShippingAddressId' => $customer->getDefaultShippingAddressId(),
            'addressIds' => $addressIds,
        ]);

        $this->logger->debug('customer.register detected address ids.', [
            'addressIds' => $addressIds,
        ]);

        foreach ($addressIds as $addressId) {
            $this->validateCustomerAddress((string) $addressId, $event->getContext());
        }
    }

    public function onCustomerAddressWritten(EntityWrittenEvent $event): void
    {
        if ($event->getContext()->hasExtension(SmartyAddressValidationService::CONTEXT_EXTENSION_VALIDATION_WRITE)) {
            $this->logger->diagnostic('customer_address.written skipped because recursion guard is active.', [
                'writeResults' => $this->writeResultDebugPayload($event, true),
            ]);
            $this->logger->debug('Skipped customer_address.written Smarty validation because recursion guard is active.');

            return;
        }

        $addressIds = $this->changedAddressIds($event, true);
        $submittedCustomFields = $this->customFieldsByAddressId($event);
        $writeResults = $this->writeResultDebugPayload($event, true);

        $this->logger->diagnostic('customer_address.written received.', [
            'addressIds' => $addressIds,
            'writeResults' => $writeResults,
        ]);

        $this->logger->debug('customer_address.written detected address ids.', [
            'addressIds' => $addressIds,
            'writeResults' => $writeResults,
        ]);

        foreach ($addressIds as $addressId) {
            $this->validateCustomerAddress($addressId, $event->getContext(), $submittedCustomFields[$addressId] ?? [], true);
        }
    }

    public function onOrderAddressWritten(EntityWrittenEvent $event): void
    {
        if ($event->getContext()->hasExtension(SmartyAddressValidationService::CONTEXT_EXTENSION_VALIDATION_WRITE)) {
            $this->logger->debug('Skipped order_address.written Smarty validation because recursion guard is active.');

            return;
        }

        foreach ($this->changedAddressIds($event) as $addressId) {
            $this->markOrderAddressNeedsValidation($addressId, $event->getContext());
        }
    }

    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $order = $event->getOrder();

        foreach ($order->getDeliveries() ?? [] as $delivery) {
            $addressId = $delivery->getShippingOrderAddressId();

            if ($addressId) {
                $this->markOrderAddressNeedsValidation($addressId, $event->getContext());
            }
        }

        if ($order->getBillingAddressId()) {
            $this->markOrderAddressNeedsValidation($order->getBillingAddressId(), $event->getContext());
        }
    }

    private function validateCustomerAddress(
        string $addressId,
        Context $context,
        array $submittedCustomFields = [],
        bool $addressChanged = false
    ): void {
        $this->logger->diagnostic('Customer address validation requested.', [
            'addressId' => $addressId,
        ]);

        if (!Uuid::isValid($addressId)) {
            $this->logger->diagnostic('Customer address validation skipped because address id is invalid.', [
                'addressId' => $addressId,
            ]);

            return;
        }

        $address = $this->loadCustomerAddress($addressId, $context);

        if (!$address instanceof CustomerAddressEntity) {
            $this->logger->diagnostic('Customer address validation skipped because address could not be loaded.', [
                'addressId' => $addressId,
            ]);
            $this->logger->debug('Smarty validation skipped because customer address could not be loaded.', [
                'addressId' => $addressId,
            ]);

            return;
        }

        if ($this->hasProcessedAddress($context, $addressId)) {
            $this->logger->diagnostic('Customer address validation skipped because address was already processed in this request.', [
                'addressId' => $addressId,
            ]);
            $this->logger->debug('Skipped Smarty validation because address was already processed in this request.', [
                'addressId' => $addressId,
            ]);

            return;
        }

        $this->markProcessedAddress($context, $addressId);

        $this->logger->debug('Smarty validation started for customer address.', [
            'addressId' => $addressId,
            'customerId' => $address->getCustomerId(),
        ]);

        $normalized = $this->addressToArray($address);
        $countryIso = strtoupper((string) ($normalized['country'] ?? ''));

        $this->logger->diagnostic('Customer address loaded for Smarty validation.', [
            'addressId' => $addressId,
            'customerId' => $address->getCustomerId(),
            'street' => $normalized['street'] ?? null,
            'zipcode' => $normalized['zipcode'] ?? null,
            'city' => $normalized['city'] ?? null,
            'country' => $normalized['country'] ?? null,
            'countryState' => $normalized['countryState'] ?? null,
            'existingCustomFieldKeys' => array_keys($address->getCustomFields() ?? []),
        ]);

        $this->logger->debug('Loaded customer address for Smarty validation.', [
            'addressId' => $addressId,
            'normalizedAddress' => $normalized,
        ]);

        if ($countryIso !== 'US') {
            $this->logger->diagnostic('Customer address validation skipped because country is not US.', [
                'addressId' => $addressId,
                'country' => $countryIso,
            ]);
            $this->logger->debug('Smarty validation skipped because country is not US.', [
                'addressId' => $addressId,
                'country' => $countryIso,
            ]);

            return;
        }

        try {
            $result = $this->validationService->validate(
                $normalized,
                $context,
                null,
                $addressId,
                $address->getCustomerId()
            );
        } catch (Throwable $exception) {
            $this->logger->diagnostic('Customer address validation threw before completion.', [
                'addressId' => $addressId,
                'error' => $exception->getMessage(),
            ]);
            $this->logger->warning('Smarty validation failed before completion.', [
                'addressId' => $addressId,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        if ($result->getError() !== null) {
            $errorMessage = $result->getError();
            $logMessage = str_contains(strtolower($errorMessage), 'credentials are not configured')
                ? 'Smarty validation skipped because Smarty is not configured.'
                : 'Smarty validation completed with error; skipping persistence.';

            $this->logger->diagnostic('Customer address validation returned an error result; custom fields will not be updated.', [
                'addressId' => $addressId,
                'error' => $errorMessage,
                'isConfiguredError' => str_contains(strtolower($errorMessage), 'credentials are not configured'),
            ]);

            $this->logger->warning($logMessage, [
                'addressId' => $addressId,
                'error' => $errorMessage,
            ]);

            return;
        }

        $customFields = array_merge(
            $this->addressCustomFieldsWithTrackingDefaults(
                $address->getCustomFields() ?? [],
                $submittedCustomFields,
                $addressChanged
            ),
            $this->validationService->buildCustomerAddressCustomFields($result)
        );
        $customFields = array_merge(
            $customFields,
            $this->addressChangeTrackingFields($address->getCustomFields() ?? [], $submittedCustomFields, $addressChanged)
        );

        $lastValidation = $customFields[SmartyAddressValidationService::FIELD_LAST_VALIDATION] ?? null;
        $validAddress = $customFields[SmartyAddressValidationService::FIELD_VALID_ADDRESS] ?? null;

        $this->logger->diagnostic('Customer address Smarty result ready to persist.', [
            'addressId' => $addressId,
            'isValid' => $result->isValid(),
            'suggestionCount' => count($result->getSuggestions()),
            'hasStandardizedAddress' => $result->getStandardizedAddress() !== null,
            'last_smarty_validation' => $lastValidation,
            'verified_flag' => $validAddress,
            'customFieldKeys' => array_keys($customFields),
        ]);

        $this->logger->debug('Smarty validation result ready for persistence.', [
            'addressId' => $addressId,
            'isValid' => $result->isValid(),
            'last_smarty_validation' => $lastValidation,
            'verified_flag' => $validAddress,
        ]);

        try {
            $this->validationWriteContext($context);

            $this->logger->diagnostic('Updating customer address Smarty custom fields.', [
                'addressId' => $addressId,
                'customFieldKeys' => array_keys($customFields),
            ]);

            $this->customerAddressRepository->update([[
                'id' => $addressId,
                'customFields' => $customFields,
            ]], $context);

            $this->logger->diagnostic('Customer address Smarty custom fields persisted.', [
                'addressId' => $addressId,
                'last_smarty_validation' => $lastValidation,
                'verified_flag' => $validAddress,
            ]);

            $this->logger->debug('Smarty validation custom fields persisted.', [
                'addressId' => $addressId,
            ]);
        } catch (Throwable $exception) {
            $this->logger->diagnostic('Failed to persist customer address Smarty custom fields.', [
                'addressId' => $addressId,
                'error' => $exception->getMessage(),
            ]);
            $this->logger->warning('Failed to persist Smarty validation custom fields.', [
                'addressId' => $addressId,
                'error' => $exception->getMessage(),
            ]);
        } finally {
            $context->removeExtension(SmartyAddressValidationService::CONTEXT_EXTENSION_VALIDATION_WRITE);
        }
    }

    /**
     * @return list<string>
     */
    private function changedAddressIds(EntityWrittenEvent $event, bool $includeInsertOperations = false): array
    {
        $ids = [];

        foreach ($event->getWriteResults() as $result) {
            if (!$this->shouldProcessWriteResult($result, $includeInsertOperations)) {
                continue;
            }

            /** @var string|array<string, string> $primaryKey */
            $primaryKey = $result->getPrimaryKey();

            if (\is_array($primaryKey)) {
                $primaryKey = $primaryKey['id'] ?? null;
            }

            if (\is_string($primaryKey) && Uuid::isValid($primaryKey)) {
                $ids[] = $primaryKey;
            }
        }

        return array_values(array_unique($ids));
    }

    private function shouldProcessWriteResult(EntityWriteResult $result, bool $includeInsertOperations): bool
    {
        $payload = $result->getPayload();

        if ($this->containsRealAddressChange($payload)) {
            return true;
        }

        if ($result->getOperation() !== EntityWriteResult::OPERATION_INSERT) {
            return false;
        }

        if (!$includeInsertOperations) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function containsRealAddressChange(array $payload): bool
    {
        $fields = [
            'street',
            'zipcode',
            'city',
            'countryId',
            'countryStateId',
            'additionalAddressLine1',
            'additionalAddressLine2',
        ];

        foreach ($fields as $field) {
            if (\array_key_exists($field, $payload)) {
                return true;
            }
        }

        return false;
    }

    private function markOrderAddressNeedsValidation(string $addressId, Context $context): void
    {
        $this->safeUpdate($this->orderAddressRepository, $addressId, $context);
    }

    private function safeUpdate(EntityRepository $repository, string $addressId, Context $context): void
    {
        if (!Uuid::isValid($addressId)) {
            return;
        }

        try {
            $repository->update([[
                'id' => $addressId,
                'customFields' => [
                    SmartyAddressValidationService::FIELD_VALID_ADDRESS => false,
                    SmartyAddressValidationService::FIELD_LAST_VALIDATION => null,
                ],
            ]], $context);
        } catch (Throwable $exception) {
            $this->logger->warning('Could not mark address as needing Smarty validation.', [
                'addressId' => $addressId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function loadCustomerAddress(string $addressId, Context $context): ?CustomerAddressEntity
    {
        $criteria = (new Criteria([$addressId]))
            ->addAssociation('country')
            ->addAssociation('countryState')
            ->addAssociation('customer');

        $address = $this->customerAddressRepository->search($criteria, $context)->first();

        return $address instanceof CustomerAddressEntity ? $address : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function addressToArray(CustomerAddressEntity $address): array
    {
        return [
            'id' => $address->getId(),
            'street' => $address->getStreet(),
            'zipcode' => $address->getZipcode(),
            'city' => $address->getCity(),
            'country' => $address->getCountry()?->getIso(),
            'countryState' => $address->getCountryState()?->getShortCode(),
            'additionalAddressLine1' => $address->getAdditionalAddressLine1(),
            'additionalAddressLine2' => $address->getAdditionalAddressLine2(),
            'customFields' => $address->getCustomFields() ?? [],
        ];
    }

    private function hasProcessedAddress(Context $context, string $addressId): bool
    {
        $extension = $context->getExtension(self::CONTEXT_EXTENSION_PROCESSED_ADDRESSES);

        if (!$extension instanceof ArrayEntity) {
            return false;
        }

        $processed = $extension->get('ids');

        return \is_array($processed) && \in_array($addressId, $processed, true);
    }

    private function markProcessedAddress(Context $context, string $addressId): void
    {
        $extension = $context->getExtension(self::CONTEXT_EXTENSION_PROCESSED_ADDRESSES);

        if (!$extension instanceof ArrayEntity) {
            $extension = new ArrayEntity(['ids' => []]);
            $context->addExtension(self::CONTEXT_EXTENSION_PROCESSED_ADDRESSES, $extension);
        }

        $processed = $extension->get('ids');

        if (!\is_array($processed)) {
            $processed = [];
        }

        if (!\in_array($addressId, $processed, true)) {
            $processed[] = $addressId;
        }

        $extension->set('ids', array_values($processed));
    }

    private function validationWriteContext(Context $context): Context
    {
        $context->addExtension(
            SmartyAddressValidationService::CONTEXT_EXTENSION_VALIDATION_WRITE,
            new ArrayEntity(['source' => 'smarty'])
        );

        return $context;
    }
}
