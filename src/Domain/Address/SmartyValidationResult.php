<?php

declare(strict_types=1);

namespace SmartyIntegration\Domain\Address;

class SmartyValidationResult
{
    public function __construct(
        private bool $isValid,
        private ?string $standardizedStreet,
        private ?string $standardizedCity,
        private ?string $standardizedPostalCode,
        private ?string $standardizedCountryIso,
        private array $rawResponse
    ) {
    }

    public function isValid(): bool
    {
        return $this->isValid;
    }

    public function getStandardizedStreet(): ?string
    {
        return $this->standardizedStreet;
    }

    public function getStandardizedCity(): ?string
    {
        return $this->standardizedCity;
    }

    public function getStandardizedPostalCode(): ?string
    {
        return $this->standardizedPostalCode;
    }

    public function getStandardizedCountryIso(): ?string
    {
        return $this->standardizedCountryIso;
    }

    public function getRawResponse(): array
    {
        return $this->rawResponse;
    }


    public function getLatitude(): ?float
    {
        if (!isset($this->rawResponse[0]['metadata']['latitude'])) {
            return null;
        }

        return (float) $this->rawResponse[0]['metadata']['latitude'];
    }

    public function getLongitude(): ?float
    {
        if (!isset($this->rawResponse[0]['metadata']['longitude'])) {
            return null;
        }

        return (float) $this->rawResponse[0]['metadata']['longitude'];
    }

    public static function fromApiResponse(array $response): self
    {
        if (empty($response) || !isset($response[0])) {
            return new self(
                false,
                null,
                null,
                null,
                null,
                $response
            );
        }

        $candidate  = $response[0];
        $components = $candidate['components'] ?? [];
        $analysis   = $candidate['analysis'] ?? [];


        $zipcode = $components['zipcode'] ?? null;
        $plus4   = $components['plus4_code'] ?? null;
        $postal  = $zipcode && $plus4 ? $zipcode . '-' . $plus4 : $zipcode;


        $dpvMatchCode = $analysis['dpv_match_code'] ?? null;
        $isValid = \in_array($dpvMatchCode, ['Y', 'D'], true);

        return new self(
            $isValid,
            $candidate['delivery_line_1'] ?? null,
            $components['city_name'] ?? null,
            $postal,
            'US',
            $response
        );
    }
}
