<?php

declare(strict_types=1);

namespace SmartyAddressValidation\Struct;

class AddressValidationResult
{
    /**
     * @param list<SmartySuggestion> $suggestions
     * @param array<string, mixed> $standardizedAddress
     * @param array<string, mixed> $originalAddress
     * @param array<string, mixed>|null $rawRequest
     * @param array<mixed>|null $rawResponse
     */
    public function __construct(
        private readonly bool $isValid,
        private readonly array $suggestions,
        private readonly ?array $standardizedAddress,
        private readonly ?float $latitude,
        private readonly ?float $longitude,
        private readonly array $originalAddress,
        private readonly ?array $rawRequest = null,
        private readonly ?array $rawResponse = null,
        private readonly ?string $error = null,
        private readonly ?string $message = null
    ) {
    }

    /**
     * @param array<string, mixed> $originalAddress
     * @param array<string, mixed>|null $rawRequest
     * @param array<mixed>|null $rawResponse
     */
    public static function invalid(
        array $originalAddress,
        ?array $rawRequest = null,
        ?array $rawResponse = null,
        ?string $message = null
    ): self {
        return new self(
            isValid: false,
            suggestions: [],
            standardizedAddress: null,
            latitude: null,
            longitude: null,
            originalAddress: $originalAddress,
            rawRequest: $rawRequest,
            rawResponse: $rawResponse,
            error: null,
            message: $message
        );
    }

    /**
     * @param array<string, mixed> $originalAddress
     * @param array<string, mixed>|null $rawRequest
     * @param array<mixed>|null $rawResponse
     */
    public static function error(
        array $originalAddress,
        ?array $rawRequest = null,
        ?array $rawResponse = null,
        ?string $error = null,
        ?string $message = null
    ): self {
        return new self(
            isValid: false,
            suggestions: [],
            standardizedAddress: null,
            latitude: null,
            longitude: null,
            originalAddress: $originalAddress,
            rawRequest: $rawRequest,
            rawResponse: $rawResponse,
            error: $error,
            message: $message
        );
    }

    public function isValid(): bool
    {
        return $this->isValid;
    }

    /**
     * @return list<SmartySuggestion>
     */
    public function getSuggestions(): array
    {
        return $this->suggestions;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getStandardizedAddress(): ?array
    {
        return $this->standardizedAddress;
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
    public function getOriginalAddress(): array
    {
        return $this->originalAddress;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRawRequest(): ?array
    {
        return $this->rawRequest;
    }

    /**
     * @return array<mixed>|null
     */
    public function getRawResponse(): ?array
    {
        return $this->rawResponse;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'isValid' => $this->isValid,
            'suggestions' => array_map(
                static fn (SmartySuggestion $suggestion): array => $suggestion->toArray(),
                $this->suggestions
            ),
            'standardizedAddress' => $this->standardizedAddress,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'originalAddress' => $this->originalAddress,
            'rawRequest' => $this->rawRequest,
            'rawResponse' => $this->rawResponse,
            'error' => $this->error,
            'message' => $this->message,
        ];
    }
}
