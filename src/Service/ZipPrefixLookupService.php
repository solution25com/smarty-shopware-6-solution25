<?php

declare(strict_types=1);

namespace SmartyAddressValidation\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use Throwable;

class ZipPrefixLookupService
{
    private const RESOURCE_PATH = __DIR__ . '/../Resources/data/us_zip_prefixes.csv.gz.b64';

    /**
     * @var list<array{zipcode: string, city: string, state: string, state_name: string, country: string}>
     */
    private const FALLBACK_ROWS = [
        ['zipcode' => '07001', 'city' => 'Avenel', 'state' => 'NJ', 'state_name' => 'New Jersey', 'country' => 'US'],
        ['zipcode' => '07002', 'city' => 'Bayonne', 'state' => 'NJ', 'state_name' => 'New Jersey', 'country' => 'US'],
        ['zipcode' => '07003', 'city' => 'Bloomfield', 'state' => 'NJ', 'state_name' => 'New Jersey', 'country' => 'US'],
        ['zipcode' => '07004', 'city' => 'Fairfield', 'state' => 'NJ', 'state_name' => 'New Jersey', 'country' => 'US'],
        ['zipcode' => '07005', 'city' => 'Boonton', 'state' => 'NJ', 'state_name' => 'New Jersey', 'country' => 'US'],
        ['zipcode' => '07006', 'city' => 'Caldwell', 'state' => 'NJ', 'state_name' => 'New Jersey', 'country' => 'US'],
        ['zipcode' => '07008', 'city' => 'Carteret', 'state' => 'NJ', 'state_name' => 'New Jersey', 'country' => 'US'],
        ['zipcode' => '07008', 'city' => 'West Carteret', 'state' => 'NJ', 'state_name' => 'New Jersey', 'country' => 'US'],
        ['zipcode' => '95010', 'city' => 'Capitola', 'state' => 'CA', 'state_name' => 'California', 'country' => 'US'],
        ['zipcode' => '95011', 'city' => 'Campbell', 'state' => 'CA', 'state_name' => 'California', 'country' => 'US'],
        ['zipcode' => '95013', 'city' => 'Coyote', 'state' => 'CA', 'state_name' => 'California', 'country' => 'US'],
        ['zipcode' => '95014', 'city' => 'Cupertino', 'state' => 'CA', 'state_name' => 'California', 'country' => 'US'],
        ['zipcode' => '95015', 'city' => 'Cupertino', 'state' => 'CA', 'state_name' => 'California', 'country' => 'US'],
        ['zipcode' => '95017', 'city' => 'Davenport', 'state' => 'CA', 'state_name' => 'California', 'country' => 'US'],
        ['zipcode' => '95018', 'city' => 'Felton', 'state' => 'CA', 'state_name' => 'California', 'country' => 'US'],
        ['zipcode' => '95019', 'city' => 'Freedom', 'state' => 'CA', 'state_name' => 'California', 'country' => 'US'],
        ['zipcode' => '95110', 'city' => 'San Jose', 'state' => 'CA', 'state_name' => 'California', 'country' => 'US'],
        ['zipcode' => '95111', 'city' => 'San Jose', 'state' => 'CA', 'state_name' => 'California', 'country' => 'US'],
        ['zipcode' => '95112', 'city' => 'San Jose', 'state' => 'CA', 'state_name' => 'California', 'country' => 'US'],
        ['zipcode' => '95113', 'city' => 'San Jose', 'state' => 'CA', 'state_name' => 'California', 'country' => 'US'],
        ['zipcode' => '95116', 'city' => 'San Jose', 'state' => 'CA', 'state_name' => 'California', 'country' => 'US'],
        ['zipcode' => '95117', 'city' => 'San Jose', 'state' => 'CA', 'state_name' => 'California', 'country' => 'US'],
        ['zipcode' => '95118', 'city' => 'San Jose', 'state' => 'CA', 'state_name' => 'California', 'country' => 'US'],
        ['zipcode' => '95119', 'city' => 'San Jose', 'state' => 'CA', 'state_name' => 'California', 'country' => 'US'],
        ['zipcode' => '95120', 'city' => 'San Jose', 'state' => 'CA', 'state_name' => 'California', 'country' => 'US'],
        ['zipcode' => '95121', 'city' => 'San Jose', 'state' => 'CA', 'state_name' => 'California', 'country' => 'US'],
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByPrefix(string $prefix, int $limit = 10): array
    {
        $prefix = preg_replace('/\D+/', '', $prefix) ?? '';

        if (\strlen($prefix) < 3 || \strlen($prefix) > 4) {
            return [];
        }

        $rows = [];

        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT zipcode, city, state, state_name, country
                 FROM `smarty_zip_prefix_lookup`
                 WHERE zipcode LIKE :prefix
                 ORDER BY zipcode ASC, city ASC
                 LIMIT :limit',
                [
                    'prefix' => $prefix . '%',
                    'limit' => $limit,
                ],
                [
                    'limit' => ParameterType::INTEGER,
                ]
            );
        } catch (Throwable $exception) {
            $this->logger->warning('Smarty ZIP prefix lookup table unavailable, using built-in fallback rows.', [
                'prefix' => $prefix,
                'error' => $exception->getMessage(),
            ]);

            $rows = [];
        }

        $rows = array_merge($rows, $this->resourceRows($prefix, $limit));
        $rows = $this->uniqueRows($rows);

        if ($rows === []) {
            $rows = $this->fallbackRows($prefix);
        }

        return array_map([$this, 'normalizeRow'], \array_slice($rows, 0, $limit));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        $zipcode = (string) ($row['zipcode'] ?? '');
        $city = (string) ($row['city'] ?? '');
        $stateName = (string) ($row['state_name'] ?? $row['state'] ?? '');
        $state = strtoupper((string) ($row['state'] ?? $row['state_abbreviation'] ?? ''));
        if ($state === '' && $stateName !== '') {
            $state = $this->stateCodeFromName($stateName);
        }
        $country = (string) ($row['country'] ?? 'US');

        if ($stateName === '' && $state !== '') {
            $stateName = $this->stateName($state);
        }

        return [
            'zipcode' => $zipcode,
            'city' => $city,
            'state' => $state,
            'stateName' => $stateName,
            'country' => $country,
            'label' => sprintf('%s — %s, %s', $zipcode, $city, $state),
        ];
    }

    private function stateCodeFromName(string $stateName): string
    {
        $lookup = [
            'ALABAMA' => 'AL', 'ALASKA' => 'AK', 'ARIZONA' => 'AZ', 'ARKANSAS' => 'AR',
            'CALIFORNIA' => 'CA', 'COLORADO' => 'CO', 'CONNECTICUT' => 'CT', 'DELAWARE' => 'DE',
            'DISTRICT OF COLUMBIA' => 'DC', 'FLORIDA' => 'FL', 'GEORGIA' => 'GA', 'HAWAII' => 'HI',
            'IDAHO' => 'ID', 'ILLINOIS' => 'IL', 'INDIANA' => 'IN', 'IOWA' => 'IA',
            'KANSAS' => 'KS', 'KENTUCKY' => 'KY', 'LOUISIANA' => 'LA', 'MAINE' => 'ME',
            'MARYLAND' => 'MD', 'MASSACHUSETTS' => 'MA', 'MICHIGAN' => 'MI', 'MINNESOTA' => 'MN',
            'MISSISSIPPI' => 'MS', 'MISSOURI' => 'MO', 'MONTANA' => 'MT', 'NEBRASKA' => 'NE',
            'NEVADA' => 'NV', 'NEW HAMPSHIRE' => 'NH', 'NEW JERSEY' => 'NJ', 'NEW MEXICO' => 'NM',
            'NEW YORK' => 'NY', 'NORTH CAROLINA' => 'NC', 'NORTH DAKOTA' => 'ND', 'OHIO' => 'OH',
            'OKLAHOMA' => 'OK', 'OREGON' => 'OR', 'PENNSYLVANIA' => 'PA', 'RHODE ISLAND' => 'RI',
            'SOUTH CAROLINA' => 'SC', 'SOUTH DAKOTA' => 'SD', 'TENNESSEE' => 'TN', 'TEXAS' => 'TX',
            'UTAH' => 'UT', 'VERMONT' => 'VT', 'VIRGINIA' => 'VA', 'WASHINGTON' => 'WA',
            'WEST VIRGINIA' => 'WV', 'WISCONSIN' => 'WI', 'WYOMING' => 'WY',
        ];

        return $lookup[strtoupper(trim($stateName))] ?? '';
    }

    private function stateName(string $state): string
    {
        $lookup = [
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

        return $lookup[strtoupper(trim($state))] ?? $state;
    }

    /**
     * @return list<array{zipcode: string, city: string, state: string, state_name: string, country: string}>
     */
    private function fallbackRows(string $prefix): array
    {
        return array_values(array_filter(
            self::FALLBACK_ROWS,
            static fn (array $row): bool => str_starts_with($row['zipcode'], $prefix)
        ));
    }

    /**
     * @return list<array{zipcode: string, city: string, state: string, state_name: string, country: string}>
     */
    private function resourceRows(string $prefix, int $limit): array
    {
        $contents = $this->resourceContents();

        if ($contents === '') {
            return [];
        }

        $rows = [];

        foreach (explode("\n", $contents) as $line) {
            if ($line === '') {
                continue;
            }

            $columns = str_getcsv($line, ',', '"', '\\');

            if (\count($columns) < 5) {
                continue;
            }

            $zipcode = (string) $columns[0];

            if (!str_starts_with($zipcode, $prefix)) {
                if ($rows !== [] && strcmp($zipcode, $prefix) > 0) {
                    break;
                }

                continue;
            }

            $rows[] = [
                'zipcode' => $zipcode,
                'city' => (string) $columns[1],
                'state' => (string) $columns[2],
                'state_name' => (string) $columns[3],
                'country' => (string) $columns[4],
            ];

            if (\count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    private function resourceContents(): string
    {
        static $contents = null;

        if (\is_string($contents)) {
            return $contents;
        }

        if (!\is_file(self::RESOURCE_PATH) || !\is_readable(self::RESOURCE_PATH)) {
            $contents = '';

            return $contents;
        }

        $encoded = file_get_contents(self::RESOURCE_PATH);

        if (!\is_string($encoded) || $encoded === '') {
            $contents = '';

            return $contents;
        }

        $compressed = base64_decode($encoded, true);

        if (!\is_string($compressed)) {
            $contents = '';

            return $contents;
        }

        $decoded = gzdecode($compressed);
        $contents = \is_string($decoded) ? $decoded : '';

        return $contents;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function uniqueRows(array $rows): array
    {
        $unique = [];

        foreach ($rows as $row) {
            $key = implode('|', [
                $row['zipcode'] ?? '',
                strtolower((string) ($row['city'] ?? '')),
                $row['state'] ?? '',
            ]);

            $unique[$key] = $row;
        }

        uasort($unique, static function (array $left, array $right): int {
            return [
                (string) ($left['zipcode'] ?? ''),
                (string) ($left['city'] ?? ''),
                (string) ($left['state'] ?? ''),
            ] <=> [
                (string) ($right['zipcode'] ?? ''),
                (string) ($right['city'] ?? ''),
                (string) ($right['state'] ?? ''),
            ];
        });

        return array_values($unique);
    }
}
