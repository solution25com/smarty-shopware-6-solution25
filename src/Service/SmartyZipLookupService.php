<?php

declare(strict_types=1);

namespace SmartyIntegration\Service;

use Psr\Log\LoggerInterface;
use SmartyIntegration\Config\SmartyConfig;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SmartyZipLookupService
{
    public function __construct(
        private readonly SmartyConfig $smartyConfig,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array<int, array{label:string, postalCode:string, city:string, state:string}> */
    public function suggestZip(string $zip): array
    {
        $digits = preg_replace('/\D+/', '', $zip) ?? '';

        if (strlen($digits) < 2) {
            return [];
        }

        if (strlen($digits) === 5) {
            return $this->lookupZipExact($digits);
        }

        if (strlen($digits) === 4) {
            $unique = [];

            for ($i = 0; $i <= 9; $i++) {
                $rows = $this->lookupZipExact($digits . (string) $i);

                foreach ($rows as $r) {
                    /* @phpstan-ignore-next-line */
                    $pc = $r['postalCode'] ?? null;
                    if (!$pc) {
                        continue;
                    }

                    $unique[$pc] = [
                        /* @phpstan-ignore-next-line */
                        'label'      => $r['label'] ?? $this->formatZipLabel($r),
                        'postalCode' => (string) $pc,
                        'city'       => (string) ($r['city']),
                        'state'      => (string) ($r['state']),
                    ];
                }
            }

            ksort($unique);
            return array_values($unique);
        }

        return [];
    }

    private function formatZipLabel(array $r): string
    {
        $pc    = (string) ($r['postalCode'] ?? '');
        $city  = trim((string) ($r['city'] ?? ''));
        $state = trim((string) ($r['state'] ?? ''));

        $label = $pc;
        if ($city !== '') {
            $label .= ' – ' . $city;
        }
        if ($state !== '') {
            $label .= ', ' . $state;
        }

        return trim($label);
    }

    /** @return array<int, array{label:string, postalCode:string, city:string, state:string}> */
    private function lookupZipExact(string $zip5): array
    {
        $zip5 = preg_replace('/\D+/', '', $zip5) ?? '';
        if (strlen($zip5) !== 5) {
            return [];
        }

        $authId    = $this->smartyConfig->getAuthId();
        $authToken = $this->smartyConfig->getAuthToken();

        if ($authId === '' || $authToken === '') {
            $this->logger->warning('Smarty ZIP lookup skipped - missing credentials');
            return [];
        }

        $endpoint = 'https://us-zipcode.api.smartystreets.com/lookup';

        try {
            $response = $this->httpClient->request('GET', $endpoint, [
                'query' => [
                    'auth-id'    => $authId,
                    'auth-token' => $authToken,
                    'zipcode'    => $zip5,
                ],
                'timeout' => 5.0,
            ]);

            $status  = $response->getStatusCode();
            $bodyRaw = $response->getContent(false);

            if ($status < 200 || $status >= 300) {
                $this->logger->warning('Smarty ZIP lookup failed', [
                    'zip'    => $zip5,
                    'status' => $status,
                    'body'   => $bodyRaw,
                ]);
                return [];
            }

            $decoded = json_decode($bodyRaw, true);
            if (!is_array($decoded) || !isset($decoded[0]) || !is_array($decoded[0])) {
                $this->logger->warning('Smarty ZIP lookup invalid JSON shape', [
                    'zip'  => $zip5,
                    'body' => $bodyRaw,
                ]);
                return [];
            }

            $row       = $decoded[0];
            $postal    = (string) ($row['zipcode'] ?? $zip5);
            $cityStates = $row['city_states'] ?? [];

            if (!is_array($cityStates) || $cityStates === []) {
                return [];
            }

            $unique = [];

            foreach ($cityStates as $cs) {
                if (!is_array($cs)) {
                    continue;
                }

                $city  = trim((string) ($cs['city'] ?? ''));
                $state = trim((string) ($cs['state'] ?? $cs['state_abbreviation'] ?? ''));

                if ($city === '' || $state === '') {
                    continue;
                }

                $label = sprintf('%s – %s, %s', $postal, $city, $state);
                $key   = strtolower($postal . '|' . $city . '|' . $state);

                $unique[$key] = [
                    'label'      => $label,
                    'postalCode' => $postal,
                    'city'       => $city,
                    'state'      => $state,
                ];
            }

            return array_values($unique);
        } catch (\Throwable $e) {
            $this->logger->error('Smarty ZIP lookup exception', [
                'zip'   => $zip5,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}
