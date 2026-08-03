<?php

declare(strict_types=1);

namespace SmartyAddressValidation\Service;

use SmartyAddressValidation\Exception\SmartyApiException;
use SmartyAddressValidation\Exception\SmartyConfigurationException;
use SmartyAddressValidation\Struct\AddressValidationResult;
use SmartyAddressValidation\Struct\SmartySuggestion;
use Shopware\Core\Framework\Context;
use Throwable;

class SmartyAddressValidationService
{
    public const CONTEXT_EXTENSION_VALIDATION_WRITE = 'smarty_validation_write';
    public const FIELD_LAST_VALIDATION = 'last_smarty_validation';
    public const FIELD_LATITUDE = 'smarty_latitude';
    public const FIELD_LONGITUDE = 'smarty_longitude';
    public const FIELD_VALID_ADDRESS = 'verified_flag';
    public const FIELD_AUTOCOMPLETE_USED = 'autocomplete_used_flag';
    public const FIELD_AUTOCOMPLETE_CHANGED = 'user_changed_autocomplete_suggestion_flag';
    public const FIELD_SUGGESTED_DECLINED = 'suggested_address_declined_flag';
    public const FIELD_REQUEST_JSON = 'smarty_request_data_json';
    public const FIELD_RESPONSE_JSON = 'smarty_response_data_json';
    public const FIELD_STANDARDIZED_JSON = 'smarty_standardized_address_json';

    public function __construct(
        private readonly SmartyClient $smartyClient,
        private readonly AddressNormalizer $addressNormalizer,
        private readonly SmartyLogger $logger
    ) {
    }

    /**
     * @param array<string, mixed> $address
     */
    public function validate(
        array $address,
        Context $context,
        ?string $salesChannelId = null,
        ?string $customerAddressId = null,
        ?string $customerId = null
    ): AddressValidationResult {
        unset($context);

        $originalAddress = $this->addressNormalizer->normalize($address);
        $smartyPayload = $this->addressNormalizer->toSmartyPayload($originalAddress);
        $rawRequest = $this->smartyClient->buildSafeRequestData($smartyPayload, $salesChannelId);
        $hasValidZipcodeFormat = $this->hasValidUsZipcodeFormat($originalAddress);

        $this->logger->diagnostic('Smarty validation service started.', [
            'customerAddressId' => $customerAddressId,
            'customerId' => $customerId,
            'salesChannelId' => $salesChannelId,
            'originalAddress' => $originalAddress,
            'smartyPayload' => $smartyPayload,
        ]);

        if (!$this->smartyClient->isConfigured($salesChannelId)) {
            $this->logger->diagnostic('Smarty validation service skipped because credentials are not configured.', [
                'customerAddressId' => $customerAddressId,
                'salesChannelId' => $salesChannelId,
            ]);

            $result = AddressValidationResult::error(
                originalAddress: $originalAddress,
                rawRequest: $rawRequest,
                error: 'Smarty credentials are not configured.',
                message: 'Address validation is currently unavailable.'
            );

            $this->logResult($result, $customerAddressId, $customerId, $salesChannelId);

            return $result;
        }

        try {
            $rawResponse = $this->smartyClient->validateUsStreetAddress($smartyPayload, $salesChannelId);
            $this->logger->diagnostic('Smarty validation service received API response.', [
                'customerAddressId' => $customerAddressId,
                'responseCount' => count($rawResponse),
                'hasValidZipcodeFormat' => $hasValidZipcodeFormat,
            ]);
            $result = $this->mapResponse(
                $originalAddress,
                $rawRequest,
                $rawResponse,
                $salesChannelId,
                $hasValidZipcodeFormat
            );
        } catch (SmartyConfigurationException | SmartyApiException $exception) {
            $this->logger->diagnostic('Smarty validation service API/configuration exception.', [
                'customerAddressId' => $customerAddressId,
                'error' => $exception->getMessage(),
            ]);
            $result = AddressValidationResult::error(
                originalAddress: $originalAddress,
                rawRequest: $rawRequest,
                error: $exception->getMessage(),
                message: 'Address validation is currently unavailable.'
            );
        } catch (Throwable $exception) {
            $this->logger->diagnostic('Smarty validation service unexpected exception.', [
                'customerAddressId' => $customerAddressId,
                'error' => $exception->getMessage(),
            ]);
            $result = AddressValidationResult::error(
                originalAddress: $originalAddress,
                rawRequest: $rawRequest,
                error: 'Unexpected Smarty validation error: ' . $exception->getMessage(),
                message: 'Address validation is currently unavailable.'
            );
        }

        $this->logResult($result, $customerAddressId, $customerId, $salesChannelId);

        $this->logger->diagnostic('Smarty validation service finished.', [
            'customerAddressId' => $customerAddressId,
            'isValid' => $result->isValid(),
            'error' => $result->getError(),
            'suggestionCount' => count($result->getSuggestions()),
            'hasStandardizedAddress' => $result->getStandardizedAddress() !== null,
        ]);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildCustomerAddressCustomFields(
        AddressValidationResult $result
    ): array {
        $isValid = $result->isValid();

        return [
            self::FIELD_VALID_ADDRESS => $isValid,
            self::FIELD_SUGGESTED_DECLINED => false,
            self::FIELD_LAST_VALIDATION => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            self::FIELD_LATITUDE => $isValid ? $result->getLatitude() : null,
            self::FIELD_LONGITUDE => $isValid ? $result->getLongitude() : null,
            self::FIELD_REQUEST_JSON => $result->getRawRequest(),
            self::FIELD_RESPONSE_JSON => $result->getRawResponse(),
        ];
    }

    /**
     * @param array<string, mixed> $originalAddress
     * @param array<string, mixed> $rawRequest
     * @param list<array<string, mixed>> $rawResponse
     */
    private function mapResponse(
        array $originalAddress,
        array $rawRequest,
        array $rawResponse,
        ?string $salesChannelId,
        bool $hasValidZipcodeFormat
    ): AddressValidationResult {
        unset($salesChannelId);

        if ($rawResponse === []) {
            return AddressValidationResult::invalid(
                originalAddress: $originalAddress,
                rawRequest: $rawRequest,
                rawResponse: $rawResponse,
                message: 'Smarty could not validate this address.'
            );
        }

        $suggestions = array_map(
            static fn (array $candidate): SmartySuggestion => SmartySuggestion::fromSmartyCandidate($candidate),
            $rawResponse
        );

        $primarySuggestion = $suggestions[0];
        $primaryCandidate = $rawResponse[0];
        $candidateIsValid = $this->isCandidateValid($primaryCandidate);
        $isValid = $hasValidZipcodeFormat && $candidateIsValid;

        $standardizedAddress = $primarySuggestion->toStandardizedAddress();
        $latitude = $primarySuggestion->getLatitude();
        $longitude = $primarySuggestion->getLongitude();

        return new AddressValidationResult(
            isValid: $isValid,
            suggestions: $suggestions,
            standardizedAddress: $standardizedAddress,
            latitude: $latitude,
            longitude: $longitude,
            originalAddress: $originalAddress,
            rawRequest: $rawRequest,
            rawResponse: $rawResponse,
            error: null,
            message: match (true) {
                $isValid => 'Smarty validated the address.',
                !$hasValidZipcodeFormat => 'The ZIP code format is invalid. Smarty returned a suggested correction.',
                default => 'Smarty returned suggestions, but the address requires confirmation.',
            }
        );
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function isCandidateValid(array $candidate): bool
    {
        $analysis = \is_array($candidate['analysis'] ?? null) ? $candidate['analysis'] : [];

        $enhancedMatch = strtolower((string) ($analysis['enhanced_match'] ?? ''));
        $dpvMatchCode = strtoupper((string) ($analysis['dpv_match_code'] ?? ''));

        if (str_contains($enhancedMatch, 'none')) {
            return false;
        }

        if (str_contains($enhancedMatch, 'postal-match')) {
            return true;
        }

        if (
            str_contains($enhancedMatch, 'missing-secondary')
            || str_contains($enhancedMatch, 'unknown-secondary')
        ) {
            return false;
        }

        return $dpvMatchCode === 'Y';
    }

    /**
     * @param array<string, mixed> $address
     */
    private function hasValidUsZipcodeFormat(array $address): bool
    {
        $zipcode = trim((string) ($address['zipcode'] ?? ''));

        if ($zipcode === '') {
            return true;
        }

        return preg_match('/^\d{5}(?:-\d{4})?$/', $zipcode) === 1;
    }

    private function logResult(
        AddressValidationResult $result,
        ?string $customerAddressId,
        ?string $customerId,
        ?string $salesChannelId
    ): void {
        $this->logger->logValidationAttempt(
            customerAddressId: $customerAddressId,
            customerId: $customerId,
            originalAddress: $result->getOriginalAddress(),
            smartyRequest: $result->getRawRequest(),
            smartyResponse: $result->getRawResponse(),
            validationResult: $result->toArray(),
            error: $result->getError(),
            salesChannelId: $salesChannelId
        );
    }
}
