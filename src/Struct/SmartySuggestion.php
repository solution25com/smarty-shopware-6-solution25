<?php

declare(strict_types=1);

namespace SmartyAddressValidation\Struct;

class SmartySuggestion
{
    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $rawCandidate
     */
    public function __construct(
        private readonly ?string $street = null,
        private readonly ?string $additionalAddressLine1 = null,
        private readonly ?string $additionalAddressLine2 = null,
        private readonly ?string $city = null,
        private readonly ?string $state = null,
        private readonly ?string $zipcode = null,
        private readonly ?string $country = 'US',
        private readonly ?float $latitude = null,
        private readonly ?float $longitude = null,
        private readonly array $metadata = [],
        private readonly array $rawCandidate = []
    ) {
    }

    /**
     * @param array<string, mixed> $candidate
     */
    public static function fromSmartyCandidate(array $candidate): self
    {
        $components = \is_array($candidate['components'] ?? null) ? $candidate['components'] : [];
        $metadata = \is_array($candidate['metadata'] ?? null) ? $candidate['metadata'] : [];

        $zipcode = self::buildZipcode($components);

        return new self(
            street: self::nullableString($candidate['delivery_line_1'] ?? null),
            additionalAddressLine1: self::nullableString($candidate['delivery_line_2'] ?? null),
            additionalAddressLine2: null,
            city: self::nullableString($components['city_name'] ?? null),
            state: self::nullableString($components['state_abbreviation'] ?? null),
            zipcode: $zipcode,
            country: 'US',
            latitude: self::nullableFloat($metadata['latitude'] ?? null),
            longitude: self::nullableFloat($metadata['longitude'] ?? null),
            metadata: $metadata,
            rawCandidate: $candidate
        );
    }

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    /**
     * @return array<string, mixed>
     */
    public function toStandardizedAddress(): array
    {
        return array_filter(
            [
                'street' => $this->street,
                'additionalAddressLine1' => $this->additionalAddressLine1,
                'additionalAddressLine2' => $this->additionalAddressLine2,
                'zipcode' => $this->zipcode,
                'city' => $this->city,
                'country' => $this->country,
                'countryState' => $this->state,
                'formattedAddress' => $this->formatAddress(),
            ],
            static fn (mixed $value): bool => $value !== null && $value !== ''
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter(
            [
                'street' => $this->street,
                'additionalAddressLine1' => $this->additionalAddressLine1,
                'additionalAddressLine2' => $this->additionalAddressLine2,
                'zipcode' => $this->zipcode,
                'city' => $this->city,
                'country' => $this->country,
                'countryState' => $this->state,
                'latitude' => $this->latitude,
                'longitude' => $this->longitude,
                'formattedAddress' => $this->formatAddress(),
                'metadata' => $this->metadata,
                'rawCandidate' => $this->rawCandidate,
            ],
            static fn (mixed $value): bool => $value !== null && $value !== []
        );
    }

    private function formatAddress(): ?string
    {
        $lineOne = trim((string) $this->street);
        $lineTwo = trim(implode(' ', array_filter([
            $this->city,
            $this->state,
            $this->zipcode,
        ])));

        $formatted = trim($lineOne . ', ' . $lineTwo, ' ,');

        return $formatted === '' ? null : $formatted;
    }

    /**
     * @param array<string, mixed> $components
     */
    private static function buildZipcode(array $components): ?string
    {
        $zip = self::nullableString($components['zipcode'] ?? null);
        $plus4 = self::nullableString($components['plus4_code'] ?? null);

        if ($zip === null) {
            return null;
        }

        return $plus4 === null ? $zip : $zip . '-' . $plus4;
    }

    private static function nullableString(mixed $value): ?string
    {
        if (!\is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function nullableFloat(mixed $value): ?float
    {
        if (!\is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}
