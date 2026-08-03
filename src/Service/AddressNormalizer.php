<?php

declare(strict_types=1);

namespace SmartyAddressValidation\Service;

class AddressNormalizer
{
    /**
     * @param array<string, mixed> $address
     *
     * @return array<string, mixed>
     */
    public function normalize(array $address): array
    {
        return [
            'street' => $this->stringValue($address, ['street', 'streetAddress', 'address1']),
            'zipcode' => $this->stringValue($address, ['zipcode', 'zipCode', 'postalCode']),
            'city' => $this->stringValue($address, ['city']),
            'country' => $this->stringValue($address, ['country', 'countryIso', 'countryId']),
            'countryState' => $this->stringValue($address, [
                'countryState',
                'region',
                'state',
                'stateCode',
                'countryStateId',
            ]),
            'additionalAddressLine1' => $this->stringValue($address, [
                'additionalAddressLine1',
                'street2',
                'address2',
            ]),
            'additionalAddressLine2' => $this->stringValue($address, [
                'additionalAddressLine2',
                'street3',
                'address3',
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $normalizedAddress
     *
     * @return array<string, mixed>
     */
    public function toSmartyPayload(array $normalizedAddress): array
    {
        $street = trim((string) ($normalizedAddress['street'] ?? ''));
        $street2 = trim(implode(' ', array_filter([
            $normalizedAddress['additionalAddressLine1'] ?? '',
            $normalizedAddress['additionalAddressLine2'] ?? '',
        ])));

        $payload = [
            'street' => $street,
            'street2' => $street2,
            'city' => trim((string) ($normalizedAddress['city'] ?? '')),
            'state' => $this->normalizeState((string) ($normalizedAddress['countryState'] ?? '')),
            'zipcode' => trim((string) ($normalizedAddress['zipcode'] ?? '')),
            'candidates' => 10,
            'match' => 'enhanced',
        ];

        return array_filter(
            $payload,
            static fn (mixed $value): bool => $value !== ''
        );
    }

    /**
     * @param array<string, mixed> $address
     * @param list<string> $keys
     */
    private function stringValue(array $address, array $keys): string
    {
        foreach ($keys as $key) {
            if (!\array_key_exists($key, $address)) {
                continue;
            }

            $value = $address[$key];

            if (\is_scalar($value)) {
                return trim((string) $value);
            }

            if (\is_array($value)) {
                $nested = $this->extractNestedNameOrCode($value);

                if ($nested !== '') {
                    return $nested;
                }
            }
        }

        return '';
    }

    private function normalizeState(string $state): string
    {
        $state = trim($state);

        if (str_contains($state, '-')) {
            $parts = explode('-', $state);

            return strtoupper((string) end($parts));
        }

        return strtoupper($state);
    }

    /**
     * @param array<string, mixed> $value
     */
    private function extractNestedNameOrCode(array $value): string
    {
        foreach (['shortCode', 'iso', 'name', 'translated.name'] as $key) {
            $nestedValue = $this->readNestedValue($value, $key);

            if (\is_scalar($nestedValue) && trim((string) $nestedValue) !== '') {
                return trim((string) $nestedValue);
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function readNestedValue(array $data, string $path): mixed
    {
        $segments = explode('.', $path);
        $current = $data;

        foreach ($segments as $segment) {
            if (!\is_array($current) || !\array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }
}
