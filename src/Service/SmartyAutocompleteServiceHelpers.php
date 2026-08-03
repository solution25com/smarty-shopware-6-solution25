<?php

declare(strict_types=1);

namespace SmartyAddressValidation\Service;

trait SmartyAutocompleteServiceHelpers
{
    /**
     * @param list<array<string, mixed>> $response
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeZipApiResponse(array $response): array
    {
        $suggestions = [];

        foreach ($response as $result) {
            $zipcodes = \is_array($result['zipcodes'] ?? null) ? $result['zipcodes'] : [];
            $cityStates = \is_array($result['city_states'] ?? null) ? $result['city_states'] : [];

            foreach ($zipcodes as $zip) {
                $zipcode = $this->digits((string) ($zip['zipcode'] ?? ''));

                foreach ($cityStates ?: [[]] as $cityState) {
                    $city = trim((string) ($cityState['city'] ?? $zip['default_city'] ?? ''));
                    $state = $this->normalizeState(
                        (string) ($cityState['state_abbreviation'] ?? $zip['state_abbreviation'] ?? '')
                    );

                    if ($zipcode === '' || $city === '' || $state === '') {
                        continue;
                    }

                    $suggestions[] = $this->zipSuggestion($zipcode, $city, $state);
                }
            }
        }

        return $this->uniqueZipSuggestions($suggestions);
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeStreetResponse(array $response): array
    {
        $suggestions = [];

        foreach (($response['suggestions'] ?? []) as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $street = trim(implode(' ', array_filter([
                $item['street_line'] ?? '',
                $item['secondary'] ?? '',
            ])));

            $city = trim((string) ($item['city'] ?? ''));
            $zipcode = $this->digits((string) ($item['zipcode'] ?? ''));
            $state = $this->normalizeState((string) ($item['state'] ?? $item['state_abbreviation'] ?? ''));

            if ($street === '') {
                continue;
            }

            $suggestions[] = [
                'street' => $street,
                'city' => $city,
                'zipcode' => $zipcode,
                'state' => $state,
                'stateName' => $this->stateName($state),
                'country' => 'US',
                'label' => $this->streetLabel($street, $city, $state, $zipcode),
            ];
        }

        return \array_slice($suggestions, 0, self::MAX_SUGGESTIONS);
    }

    /**
     * @param array<mixed> $response
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeReverseGeoResponse(array $response): array
    {
        $results = \is_array($response['results'] ?? null) ? $response['results'] : [];
        $suggestions = [];
        $seen = [];

        foreach ($results as $result) {
            if (!\is_array($result)) {
                continue;
            }

            $address = \is_array($result['address'] ?? null) ? $result['address'] : [];

            $street = trim((string) ($address['street'] ?? ''));
            $city = trim((string) ($address['city'] ?? ''));
            $zipcode = $this->digits((string) ($address['zipcode'] ?? ''));
            $state = $this->normalizeState((string) ($address['state_abbreviation'] ?? ''));

            if ($street === '' || $zipcode === '') {
                continue;
            }

            $key = implode('|', [$street, $city, $state, $zipcode]);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $suggestions[] = [
                'street' => $street,
                'city' => $city,
                'zipcode' => $zipcode,
                'state' => $state,
                'stateName' => $this->stateName($state),
                'country' => 'US',
                'label' => $this->streetLabel($street, $city, $state, $zipcode),
            ];
        }

        return \array_slice($suggestions, 0, self::MAX_SUGGESTIONS);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, string>
     */
    private function normalizeContext(array $payload): array
    {
        return [
            'city' => $this->cleanText((string) ($payload['city'] ?? '')),
            'state' => $this->normalizeState((string) ($payload['state'] ?? '')),
            'country' => $this->normalizeCountry((string) ($payload['country'] ?? '')),
            'zipcode' => $this->digits((string) ($payload['zipcode'] ?? '')),
        ];
    }

    private function cleanText(string $value): string
    {
        $value = trim($value);

        if ($value === '' || str_contains($value, '*')) {
            return '';
        }

        $lower = strtolower($value);

        if (
            str_contains($lower, 'state/province')
            || str_contains($lower, 'zip code')
            || str_contains($lower, 'select')
            || str_contains($lower, 'placeholder')
        ) {
            return '';
        }

        return $value;
    }

    private function normalizeCountry(string $country): string
    {
        $country = $this->cleanText($country);
        $upper = strtoupper($country);
        $lower = strtolower($country);

        if ($upper === 'US' || $upper === 'USA' || str_contains($lower, 'united states')) {
            return 'US';
        }

        return '';
    }

    private function normalizeState(string $state): string
    {
        $state = $this->cleanText($state);

        if ($state === '') {
            return '';
        }

        $upper = strtoupper($state);

        if (str_contains($upper, '-')) {
            $parts = explode('-', $upper);
            $upper = (string) end($parts);
        }

        if (\strlen($upper) === 2 && ctype_alpha($upper)) {
            return $upper;
        }

        return $this->stateCodeFromName($state);
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    /**
     * @param list<array<string, mixed>> $suggestions
     *
     * @return list<array<string, mixed>>
     */
    private function uniqueZipSuggestions(array $suggestions): array
    {
        $seen = [];
        $unique = [];

        foreach ($suggestions as $suggestion) {
            $key = implode('|', [
                $suggestion['zipcode'] ?? '',
                $suggestion['city'] ?? '',
                $suggestion['state'] ?? '',
            ]);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $suggestion;
        }

        return \array_slice($unique, 0, 10);
    }

    /**
     * @return array<string, mixed>
     */
    private function zipSuggestion(string $zipcode, string $city, string $state): array
    {
        return [
            'zipcode' => $zipcode,
            'city' => $city,
            'state' => $state,
            'stateName' => $this->stateName($state),
            'country' => 'US',
            'label' => sprintf('%s — %s, %s', $zipcode, $city, $state),
        ];
    }

    /**
     * @param array<string, string> $context
     *
     * @return list<array<string, mixed>>
     */
    private function buildStreetQueryAttempts(string $search, array $context): array
    {
        $base = [
            'search' => $search,
            'max_results' => self::MAX_SUGGESTIONS,
            'source' => 'all',
            'prefer_geolocation' => 'city',
        ];

        $attempts = [];

        if (\strlen($context['zipcode']) === 5) {
            $attempts[] = array_merge($base, [
                'include_only_zip_codes' => $context['zipcode'],
                'prefer_geolocation' => 'none',
            ]);
        }

        if ($context['city'] !== '' && $context['state'] !== '') {
            $attempts[] = array_merge($base, [
                'include_only_cities' => $context['city'],
                'include_only_states' => $context['state'],
            ]);
        }

        if ($context['city'] !== '') {
            $attempts[] = array_merge($base, [
                'include_only_cities' => $context['city'],
            ]);
        }

        if ($context['state'] !== '') {
            $attempts[] = array_merge($base, [
                'include_only_states' => $context['state'],
            ]);
        }

        $attempts[] = $base;

        $unique = [];
        $normalized = [];

        foreach ($attempts as $attempt) {
            $hash = md5(json_encode($attempt, \JSON_THROW_ON_ERROR));

            if (isset($unique[$hash])) {
                continue;
            }

            $unique[$hash] = true;
            $normalized[] = $attempt;
        }

        return $normalized;
    }

    private function streetLabel(string $street, string $city, string $state, string $zipcode): string
    {
        $locationParts = [];

        if ($city !== '') {
            $locationParts[] = $city;
        }

        $stateZip = trim(implode(' ', array_filter([
            $state !== '' ? $state : null,
            $zipcode !== '' ? $zipcode : null,
        ])));

        if ($stateZip !== '') {
            $locationParts[] = $stateZip;
        }

        return trim(implode(', ', array_filter(array_merge([$street], $locationParts))));
    }

    private function stateCodeFromName(string $state): string
    {
        $state = strtolower(trim($state));

        foreach ($this->stateMap() as $code => $name) {
            if ($state === strtolower($name)) {
                return $code;
            }
        }

        return '';
    }

    private function stateName(string $state): string
    {
        return $this->stateMap()[strtoupper($state)] ?? $state;
    }

    /**
     * @param array<mixed> $response
     */
    private function countSuggestions(array $response, string $key): int
    {
        $items = $response[$key] ?? [];

        if (\is_array($items)) {
            return \count($items);
        }

        return \array_is_list($response) ? \count($response) : 0;
    }

    /**
     * @return array<string, string>
     */
    private function stateMap(): array
    {
        return [
            'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
            'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
            'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii',
            'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa',
            'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine',
            'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota',
            'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska',
            'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico',
            'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio',
            'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island',
            'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas',
            'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington',
            'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function empty(): array
    {
        return [
            'success' => true,
            'suggestions' => [],
        ];
    }
}
