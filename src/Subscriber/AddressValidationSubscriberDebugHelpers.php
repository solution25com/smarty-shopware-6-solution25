<?php

declare(strict_types=1);

namespace SmartyAddressValidation\Subscriber;

use SmartyAddressValidation\Service\SmartyAddressValidationService;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\Uuid\Uuid;

trait AddressValidationSubscriberDebugHelpers
{
    /**
     * @return list<array<string, mixed>>
     */
    private function writeResultDebugPayload(EntityWrittenEvent $event, bool $includeInsertOperations = false): array
    {
        $debug = [];

        foreach ($event->getWriteResults() as $result) {
            /** @var string|array<string, string> $primaryKey */
            $primaryKey = $result->getPrimaryKey();

            if (\is_array($primaryKey)) {
                $primaryKey = $primaryKey['id'] ?? $primaryKey;
            }

            $debug[] = [
                'primaryKey' => $primaryKey,
                'operation' => $result->getOperation(),
                'payloadKeys' => array_keys($result->getPayload()),
                'hasRealAddressChange' => $this->containsRealAddressChange($result->getPayload()),
                'willProcess' => $this->shouldProcessWriteResult($result, $includeInsertOperations),
            ];
        }

        return $debug;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function customFieldsByAddressId(EntityWrittenEvent $event): array
    {
        $customFields = [];

        foreach ($event->getWriteResults() as $result) {
            $payload = $result->getPayload();

            if (!\is_array($payload['customFields'] ?? null)) {
                continue;
            }

            /** @var string|array<string, string> $primaryKey */
            $primaryKey = $result->getPrimaryKey();

            if (\is_array($primaryKey)) {
                $primaryKey = $primaryKey['id'] ?? null;
            }

            if (\is_string($primaryKey) && Uuid::isValid($primaryKey)) {
                $customFields[$primaryKey] = $payload['customFields'];
            }
        }

        return $customFields;
    }

    /**
     * @param array<string, mixed> $persisted
     * @param array<string, mixed> $submitted
     * @return array<string, mixed>
     */
    private function addressCustomFieldsWithTrackingDefaults(
        array $persisted,
        array $submitted,
        bool $addressChanged = false
    ): array {
        $fields = array_merge($persisted, $this->smartyAutocompleteTrackingFields($submitted));

        foreach ($this->smartyAutocompleteTrackingFieldNames() as $fieldName) {
            if (!\array_key_exists($fieldName, $fields)) {
                $fields[$fieldName] = false;
            }
        }

        unset($addressChanged);

        return $fields;
    }

    /**
     * @param array<string, mixed> $persisted
     * @param array<string, mixed> $submitted
     * @return array<string, bool>
     */
    private function addressChangeTrackingFields(array $persisted, array $submitted, bool $addressChanged): array
    {
        $submittedTracking = $this->smartyAutocompleteTrackingFields($submitted);

        if (($submittedTracking[SmartyAddressValidationService::FIELD_AUTOCOMPLETE_CHANGED] ?? false) === true) {
            return [
                SmartyAddressValidationService::FIELD_AUTOCOMPLETE_USED => false,
                SmartyAddressValidationService::FIELD_AUTOCOMPLETE_CHANGED => true,
                SmartyAddressValidationService::FIELD_SUGGESTED_DECLINED => true,
            ];
        }

        if (
            $submittedTracking === []
            && $addressChanged
            && $this->boolish($persisted[SmartyAddressValidationService::FIELD_AUTOCOMPLETE_USED] ?? false)
        ) {
            return [
                SmartyAddressValidationService::FIELD_AUTOCOMPLETE_USED => false,
                SmartyAddressValidationService::FIELD_AUTOCOMPLETE_CHANGED => true,
                SmartyAddressValidationService::FIELD_SUGGESTED_DECLINED => true,
            ];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $customFields
     * @return array<string, bool>
     */
    private function smartyAutocompleteTrackingFields(array $customFields): array
    {
        $trackingFields = [];

        foreach ($this->smartyAutocompleteTrackingFieldNames() as $fieldName) {
            if (\array_key_exists($fieldName, $customFields)) {
                $trackingFields[$fieldName] = $this->boolish($customFields[$fieldName]);
            }
        }

        return $trackingFields;
    }

    /**
     * @return list<string>
     */
    private function smartyAutocompleteTrackingFieldNames(): array
    {
        return [
            SmartyAddressValidationService::FIELD_AUTOCOMPLETE_USED,
            SmartyAddressValidationService::FIELD_AUTOCOMPLETE_CHANGED,
            SmartyAddressValidationService::FIELD_SUGGESTED_DECLINED,
        ];
    }

    private function boolish(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        if (\is_int($value)) {
            return $value === 1;
        }

        $normalized = strtolower(trim((string) $value));

        return \in_array($normalized, ['1', 'true', 'on', 'yes'], true);
    }
}
