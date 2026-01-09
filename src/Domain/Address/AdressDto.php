<?php

declare(strict_types=1);

namespace SmartyIntegration\Domain\Address;

final class AdressDto
{
    public function __construct(
        private string $street,
        private ?string $city,
        private ?string $postalCode,
        private string $countryIso,
    ) {
    }

    public function getStreet(): string
    {
        return $this->street;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function getPostalCode(): ?string
    {
        return $this->postalCode;
    }

    public function getCountryIso(): string
    {
        return $this->countryIso;
    }

    public function toArray(): array
    {
        return [
            'street'     => $this->street,
            'city'       => $this->city,
            'postalCode' => $this->postalCode,
            'countryIso' => $this->countryIso,
        ];
    }
}
