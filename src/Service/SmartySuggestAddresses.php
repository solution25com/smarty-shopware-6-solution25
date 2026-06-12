<?php

declare(strict_types=1);

namespace SmartyIntegration\Service;

use Psr\Log\LoggerInterface;
use SmartyIntegration\Config\SmartyConfig;
use SmartyIntegration\Domain\Address\AdressDto;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SmartySuggestAddresses
{
    public function __construct(
        private readonly SmartyConfig $smartyConfig,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger
    ) {
    }

    public function suggestAddresses(
        AdressDto $addressDto,
        ?string $salesChannelId = null,
        string $clientIp = ''
    ): array {
        $authId    = $this->smartyConfig->getAuthId();
        $authToken = $this->smartyConfig->getAuthToken();

        if (!$authId || !$authToken) {
            $this->logger->error('Smarty suggestAddresses called without authId/authToken', [
                'salesChannelId' => $salesChannelId,
            ]);
            return [];
        }

        $street = trim((string) $addressDto->getStreet());
        if ($street === '') {
            $this->logger->warning('Smarty suggestAddresses: empty street in DTO', [
                'salesChannelId' => $salesChannelId,
            ]);
            return [];
        }

        $url = 'https://us-autocomplete-pro.api.smarty.com/lookup';

        $query = [
            'auth-id'             => $authId,
            'auth-token'          => $authToken,
            'license'             => 'us-autocomplete-pro-cloud',
            'search'              => $street,
            'source'              => 'all',
            'max_results'         => 10,
            'prefer_geolocation'  => 'city',
        ];

        $postalCode = preg_replace('/\D+/', '', (string) $addressDto->getPostalCode()) ?? '';
        if ($postalCode !== '') {
            $query['include_only_zip_codes'] = substr($postalCode, 0, 5);
            $query['prefer_geolocation'] = 'none';
        }

        $options = [
            'query'   => $query,
            'timeout' => 5,
            'headers' => [],
        ];

        if ($clientIp !== '') {
            $options['headers']['X-Forwarded-For'] = $clientIp;
        }

        try {
            $response = $this->httpClient->request('GET', $url, $options);

            $status  = $response->getStatusCode();
            $rawBody = $response->getContent(false);

            $this->logger->info('Smarty suggestAddresses RAW', [
                'status'         => $status,
                'body'           => $rawBody,
                'query'          => $query,
                'clientIp'       => $clientIp,
                'salesChannelId' => $salesChannelId,
            ]);

            if ($status < 200 || $status >= 300) {
                return [];
            }

            $data = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);

            $suggestions = [];
            foreach (($data['suggestions'] ?? []) as $s) {
                $streetLine = (string) ($s['street_line'] ?? '');
                if ($streetLine === '') {
                    continue;
                }

                $city  = (string) ($s['city'] ?? '');
                $state = (string) ($s['state'] ?? '');
                $zip   = (string) ($s['zipcode'] ?? '');

                $labelParts = array_filter([
                    $streetLine,
                    trim($city . ' ' . $state),
                    $zip,
                ]);

                $suggestions[] = [
                    'label'      => implode(', ', $labelParts),
                    'street'     => $streetLine,
                    'city'       => $city,
                    'postalCode' => $zip,
                    'state'      => $state,
                    'countryIso' => 'US',
                ];
            }

            if (!$suggestions) {
                return [];
            }

            $suggestions = $this->filterSuggestions($addressDto, $suggestions);

            if (!$suggestions) {
                return [];
            }

            usort(
                $suggestions,
                fn(array $a, array $b) =>
                    $this->scoreSuggestion($addressDto, $b) <=> $this->scoreSuggestion($addressDto, $a)
            );

            return array_slice($suggestions, 0, 10);
        } catch (\Throwable $e) {
            $this->logger->error('Smarty suggestAddresses exception', [
                'exception'      => $e,
                'clientIp'       => $clientIp,
                'salesChannelId' => $salesChannelId,
            ]);
            return [];
        }
    }

    private function scoreSuggestion(AdressDto $input, array $s): int
    {
        $score = 0;

        $inStreet = $this->norm((string) $input->getStreet());
        $inCity   = $this->norm((string) $input->getCity());
        $inState  = $this->norm($this->readInputState($input));
        $inZip    = preg_replace('/\D+/', '', (string) $input->getPostalCode());

        $sStreet = $this->norm((string) ($s['street'] ?? ''));
        $sCity   = $this->norm((string) ($s['city'] ?? ''));
        $sState  = $this->norm((string) ($s['state'] ?? ''));
        $sZip    = preg_replace('/\D+/', '', (string) ($s['postalCode'] ?? ''));

        if ($inCity !== '' && $inCity === $sCity) {
            $score += 400;
        }

        if ($inState !== '' && $inState === $sState) {
            $score += 300;
        }

        if ($inZip !== '' && $sZip !== '') {
            if ($inZip === $sZip) {
                $score += 500;
            } elseif (substr($inZip, 0, 3) === substr($sZip, 0, 3)) {
                $score += 150;
            }
        }

        if ($inStreet !== '' && $sStreet !== '') {
            $pct = 0.0;
            similar_text($inStreet, $sStreet, $pct);
            $score += (int) round($pct * 5);

            $inNum = $this->extractHouseNumber($inStreet);
            $sNum  = $this->extractHouseNumber($sStreet);
            if ($inNum !== '' && $inNum === $sNum) {
                $score += 200;
            }
        }

        return $score;
    }

    private function filterSuggestions(AdressDto $input, array $suggestions): array
    {
        $inputCity = $this->norm((string) $input->getCity());
        $inputZip  = preg_replace('/\D+/', '', (string) $input->getPostalCode());

        $filtered = array_values(array_filter($suggestions, function (array $suggestion) use ($inputCity, $inputZip): bool {
            $suggestionCity = $this->norm((string) ($suggestion['city'] ?? ''));
            $suggestionZip  = preg_replace('/\D+/', '', (string) ($suggestion['postalCode'] ?? ''));

            if ($inputCity !== '' && $suggestionCity !== '' && $suggestionCity !== $inputCity) {
                return false;
            }

            if ($inputZip !== '' && $suggestionZip !== '') {
                return substr($suggestionZip, 0, 5) === substr($inputZip, 0, 5);
            }

            return true;
        }));

        if ($filtered !== []) {
            return $filtered;
        }

        if ($inputZip !== '') {
            return [];
        }

        return $suggestions;
    }

    private function readInputState(AdressDto $input): string
    {
        /* @phpstan-ignore-next-line */
        if (method_exists($input, 'getState')) {
            /** @var mixed $v */
            $v = $input->getState();
            return is_scalar($v) ? (string) $v : '';
        }

        /* @phpstan-ignore-next-line */
        if (method_exists($input, 'getStateCode')) {
            /** @var mixed $v */
            $v = $input->getStateCode();
            return is_scalar($v) ? (string) $v : '';
        }

        /* @phpstan-ignore-next-line */
        if (method_exists($input, 'getCountryState')) {
            /** @var mixed $st */
            $st = $input->getCountryState();

            if (is_string($st)) {
                return $st;
            }

            if (is_object($st)) {
                if (method_exists($st, 'getShortCode')) {
                    /** @var mixed $v */
                    $v = $st->getShortCode();
                    return is_scalar($v) ? (string) $v : '';
                }
                if (method_exists($st, 'getIso')) {
                    /** @var mixed $v */
                    $v = $st->getIso();
                    return is_scalar($v) ? (string) $v : '';
                }
                if (method_exists($st, 'getName')) {
                    /** @var mixed $v */
                    $v = $st->getName();
                    return is_scalar($v) ? (string) $v : '';
                }
            }
        }

        /* @phpstan-ignore-next-line */
        if (method_exists($input, 'getCountryStateIso')) {
            /** @var mixed $v */
            $v = $input->getCountryStateIso();
            return is_scalar($v) ? (string) $v : '';
        }

        return '';
    }

    private function norm(string $v): string
    {
        $v = strtoupper(trim($v));
        $v = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $v) ?? '';
        $v = preg_replace('/\s+/', ' ', $v) ?? '';
        return trim($v);
    }

    private function extractHouseNumber(string $street): string
    {
        if (preg_match('/\b(\d+(?:\s*1\/2)?)\b/i', $street, $m)) {
            return strtoupper(trim($m[1]));
        }
        return '';
    }
}
