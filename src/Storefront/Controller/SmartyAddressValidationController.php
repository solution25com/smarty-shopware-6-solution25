<?php

declare(strict_types=1);

namespace SmartyIntegration\Storefront\Controller;

use Psr\Log\LoggerInterface;
use SmartyIntegration\Domain\Address\AdressDto;
use SmartyIntegration\Domain\Address\SmartyValidationResult;
use SmartyIntegration\Service\SmartyApiService;
use SmartyIntegration\Service\SmartyShippingAddressUpdater;
use SmartyIntegration\Service\SmartyZipLookupService;
use SmartyIntegration\Service\SmartySuggestAddresses;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]
class SmartyAddressValidationController extends StorefrontController
{
    public function __construct(
        private readonly SmartyApiService $smartyApiService,
        private readonly SmartyZipLookupService $zipLookupService,
        private readonly SmartyShippingAddressUpdater $shippingAddressUpdater,
        private readonly SmartySuggestAddresses $smartySuggestAddresses,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/smarty/address/validate',
        name: 'frontend.smarty.address.validate',
        methods: ['POST'],
        defaults: ['XmlHttpRequest' => true]
    )]
    public function validateAddress(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true) ?? [];

        $this->logger->info('Storefront validateAddress request', [
            'payload'        => $payload,
            'salesChannelId' => $salesChannelContext->getSalesChannelId(),
        ]);

        $street     = $payload['street']     ?? null;
        $city       = $payload['city']       ?? null;
        $postalCode = $payload['postalCode'] ?? null;
        $countryIso = $payload['countryIso'] ?? null;

        $latInput = isset($payload['latitude']) ? (float) $payload['latitude'] : null;
        $lngInput = isset($payload['longitude']) ? (float) $payload['longitude'] : null;

        $addressTooOld = (bool) ($payload['addressTooOld'] ?? false);

        if (!$street || !$city || !$postalCode || !$countryIso) {
            $this->logger->warning('Storefront validateAddress missing fields', $payload);

            return new JsonResponse([
                'success' => false,
                'message' => 'Address validation failed.',
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        $customer          = $salesChannelContext->getCustomer();
        $shippingAddress   = null;
        $shippingAddressId = null;

        if ($customer) {
            $shippingAddress = $customer->getActiveShippingAddress()
                ?? $customer->getDefaultShippingAddress();

            if ($shippingAddress) {
                $shippingAddressId = $shippingAddress->getId();
            }
        }

        $addressDto = new AdressDto(
            (string) $street,
            (string) $city,
            (string) $postalCode,
            (string) $countryIso
        );

        try {
            /** @var SmartyValidationResult $result */
            $result = $this->smartyApiService->validateAdress(
                $addressDto,
                $salesChannelContext->getSalesChannelId()
            );

            $smartyLat = $result->getLatitude();
            $smartyLng = $result->getLongitude();

            $standardCountryIso = $result->getStandardizedCountryIso();
            $standardPostalCode = $result->getStandardizedPostalCode();
            $inputCountryIso    = (string) $countryIso;

            $countryMismatch = false;
            /* @phpstan-ignore-next-line */
            if ($standardCountryIso && $inputCountryIso) {
                $countryMismatch = strtoupper($standardCountryIso) !== strtoupper($inputCountryIso);
            }

            $postalMismatch = false;
            /* @phpstan-ignore-next-line */
            if ($standardPostalCode && $postalCode) {
                $inputZip    = preg_replace('/\D/', '', (string) $postalCode);
                $standardZip = preg_replace('/\D/', '', (string) $standardPostalCode);

                if (strtoupper((string) $countryIso) === 'US') {
                    $inputZip    = substr($inputZip, 0, 5);
                    $standardZip = substr($standardZip, 0, 5);
                }

                if ($inputZip && $standardZip && $inputZip !== $standardZip) {
                    $postalMismatch = true;
                }
            }

            $coordsValid = null;
            if ($latInput !== null && $lngInput !== null && $smartyLat !== null && $smartyLng !== null) {
                $coordsValid = $this->coordinatesCloseEnough($latInput, $lngInput, $smartyLat, $smartyLng);
            }

            $isValid        = $result->isValid() && !$countryMismatch && !$postalMismatch;
            $addressUpdated = false;

            $this->logger->info('Storefront validateAddress result', [
                'isValid'            => $isValid,
                'street'             => $result->getStandardizedStreet(),
                'city'               => $result->getStandardizedCity(),
                'postalCode'         => $standardPostalCode,
                'countryIso'         => $standardCountryIso,
                'coordsValid'        => $coordsValid,
                'addressTooOld'      => $addressTooOld,
                'shippingAddressId'  => $shippingAddressId,
                'hasCustomer'        => $customer !== null,
                'countryMismatch'    => $countryMismatch,
                'postalMismatch'     => $postalMismatch,
                'inputPostalCode'    => $postalCode,
                'standardPostalCode' => $standardPostalCode,
                'inputCountryIso'    => $inputCountryIso,
            ]);

            if ($isValid && $shippingAddressId !== null) {
                $addressUpdated = $this->shippingAddressUpdater->updateFromSmarty(
                    $result,
                    $shippingAddressId,
                    $salesChannelContext->getContext(),
                    (string) $street,
                    (string) $city,
                    (string) $postalCode,
                    (string) $countryIso
                );
            }

            $message = $isValid
                ? ($addressTooOld
                    ? 'We have re-validated and updated your shipping address.'
                    : 'Your shipping address has been validated.')
                : ($countryMismatch
                    ? 'The selected country does not match the address.'
                    : ($postalMismatch
                        ? 'The postal code you entered does not match this street and city.'
                        : 'Address validation failed.'));

            return new JsonResponse([
                'success' => true,
                'message' => $message,
                'data' => [
                    'isValid'                => $isValid,
                    'standardizedStreet'     => $result->getStandardizedStreet(),
                    'standardizedCity'       => $result->getStandardizedCity(),
                    'standardizedPostalCode' => $standardPostalCode,
                    'standardizedCountryIso' => $standardCountryIso,
                    'rawResponse'            => $result->getRawResponse(),
                    'smartyLatitude'         => $smartyLat,
                    'smartyLongitude'        => $smartyLng,
                    'coordsValid'            => $coordsValid,
                    'addressUpdated'         => $addressUpdated,
                    'hasCustomer'            => $customer !== null,
                    'countryMismatch'        => $countryMismatch,
                    'postalMismatch'         => $postalMismatch,
                    'inputCountryIso'        => $inputCountryIso,
                    'detectedCountryIso'     => $standardCountryIso,
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Storefront validateAddress exception', [
                'message' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'success' => false,
                'message' => 'Smarty address validation failed',
                'error'   => $e->getMessage(),
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route(
        path: '/smarty/address/suggest',
        name: 'frontend.smarty.address.suggest',
        methods: ['POST'],
        defaults: ['XmlHttpRequest' => true]
    )]
    public function suggestAddress(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true) ?? [];

        $this->logger->info('Storefront suggestAddress request', [
            'payload'        => $payload,
            'salesChannelId' => $salesChannelContext->getSalesChannelId(),
        ]);

        $street     = (string) ($payload['street'] ?? '');
        $city       = $payload['city']       ?? null;
        $postalCode = $payload['postalCode'] ?? null;
        $countryIso = (string) ($payload['countryIso'] ?? 'US');

        if ($street === '') {
            $this->logger->warning('Storefront suggestAddress missing street', $payload);

            return new JsonResponse([
                'success' => false,
                'message' => 'Missing field: street',
                'data'    => ['suggestions' => []],
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        $dto = new AdressDto(
            $street,
            $city ? (string) $city : null,
            $postalCode ? (string) $postalCode : null,
            $countryIso
        );

        try {
            $suggestions = $this->smartySuggestAddresses->suggestAddresses(
                $dto,
                $salesChannelContext->getSalesChannelId()
            );

            $this->logger->info('Storefront suggestAddress result', [
                'count' => count($suggestions),
            ]);

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'suggestions' => $suggestions,
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Storefront suggestAddress exception', [
                'message' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'success' => false,
                'message' => 'Smarty address suggestion failed',
                'error'   => $e->getMessage(),
                'data'    => ['suggestions' => []],
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route(
        path: '/smarty/address/suggest-zip',
        name: 'frontend.smarty.address.suggest_zip',
        methods: ['POST'],
        defaults: ['XmlHttpRequest' => true]
    )]
    public function suggestZipAction(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true) ?? [];

        $this->logger->info('Storefront suggestZip request', [
            'payload'        => $payload,
            'salesChannelId' => $salesChannelContext->getSalesChannelId(),
        ]);

        $zip = (string) ($payload['postalCode'] ?? '');

        return new JsonResponse([
            'success' => true,
            'data' => [
                'suggestions' => $this->zipLookupService->suggestZip($zip),
            ],
        ]);
    }

    #[Route(
        path: '/smarty/address/from-coordinates',
        name: 'frontend.smarty.address.from_coordinates',
        methods: ['POST'],
        defaults: ['XmlHttpRequest' => true]
    )]
    public function addressFromCoordinates(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true) ?? [];

        $this->logger->info('Storefront addressFromCoordinates request', $payload);

        $lat = isset($payload['latitude']) ? (float) $payload['latitude'] : null;
        $lng = isset($payload['longitude']) ? (float) $payload['longitude'] : null;

        if ($lat === null || $lng === null) {
            $this->logger->warning('Storefront addressFromCoordinates missing lat/lng', $payload);

            return new JsonResponse([
                'success' => false,
                'message' => 'Missing latitude/longitude',
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->smartyApiService->lookupByCoordinates(
                $lat,
                $lng,
                $salesChannelContext->getSalesChannelId()
            );

            $this->logger->info('Storefront addressFromCoordinates result', [
                'isValid'     => $result->isValid(),
                'street'      => $result->getStandardizedStreet(),
                'city'        => $result->getStandardizedCity(),
                'postalCode'  => $result->getStandardizedPostalCode(),
            ]);

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'isValid'     => $result->isValid(),
                    'street'      => $result->getStandardizedStreet(),
                    'city'        => $result->getStandardizedCity(),
                    'postalCode'  => $result->getStandardizedPostalCode(),
                    'countryIso'  => $result->getStandardizedCountryIso(),
                    'state'       => method_exists($result, 'getStateName') ? $result->getStateName() : null,
                    'rawResponse' => $result->getRawResponse(),
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Storefront addressFromCoordinates exception', [
                'message' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'success' => false,
                'message' => 'Address lookup by coordinates failed',
                'error'   => $e->getMessage(),
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function coordinatesCloseEnough(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2,
        float $maxDistanceKm = 0.1
    ): bool {
        $earthRadius = 6371.0;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1.0 - $a));
        $distance = $earthRadius * $c;

        return $distance <= $maxDistanceKm;
    }
}
