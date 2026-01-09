<?php

declare(strict_types=1);

namespace SmartyIntegration\Controller;

use Psr\Log\LoggerInterface;
use SmartyIntegration\Domain\Address\AdressDto;
use SmartyIntegration\Service\SmartyApiService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Shopware\Core\PlatformRequest;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => ['api']])]
class SmartyAdminAddressController
{
    public function __construct(
        private readonly SmartyApiService $smartyApiService,
        private readonly LoggerInterface $logger
    ) {
    }

    #[Route(
        path: '/api/_action/smarty/validate-address',
        name: 'api.action.smarty.validate_address',
        methods: ['POST']
    )]
    public function validateAddress(Request $request): JsonResponse
    {
        $rawBody = (string) $request->getContent();
        $payload = json_decode($rawBody, true) ?? [];

        $this->logger->info('SmartyAdminAddressController::validateAddress called', [
            'payload' => $payload,
        ]);

        $street     = $payload['street']     ?? null;
        $city       = $payload['city']       ?? null;
        $postalCode = $payload['postalCode'] ?? null;
        $countryIso = $payload['countryIso'] ?? null;

        if (!$street || !$city || !$postalCode || !$countryIso) {
            $this->logger->warning('Smarty admin validateAddress: missing required fields', [
                'street'     => $street,
                'city'       => $city,
                'postalCode' => $postalCode,
                'countryIso' => $countryIso,
            ]);

            return new JsonResponse([
                'success' => false,
                'data'    => ['isValid' => false],
                'message' => 'Missing required fields: street, city, postalCode, countryIso',
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            $dto = new AdressDto(
                $street,
                $city,
                $postalCode,
                $countryIso
            );

            $this->logger->debug('Smarty admin validateAddress: calling SmartyApiService::validateAdress', [
                'street'     => $street,
                'city'       => $city,
                'postalCode' => $postalCode,
                'countryIso' => $countryIso,
            ]);

            $result = $this->smartyApiService->validateAdress($dto, null);

            $this->logger->info('Smarty admin validateAddress: got result from SmartyApiService', [
                'isValid'                => $result->isValid(),
                'standardizedStreet'     => $result->getStandardizedStreet(),
                'standardizedCity'       => $result->getStandardizedCity(),
                'standardizedPostalCode' => $result->getStandardizedPostalCode(),
                'standardizedCountryIso' => $result->getStandardizedCountryIso(),
            ]);

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'isValid'                => $result->isValid(),
                    'standardizedStreet'     => $result->getStandardizedStreet(),
                    'standardizedCity'       => $result->getStandardizedCity(),
                    'standardizedPostalCode' => $result->getStandardizedPostalCode(),
                    'standardizedCountryIso' => $result->getStandardizedCountryIso(),
                ],
                'message' => $result->isValid()
                    ? 'Address is valid.'
                    : 'Invalid address. Please check street, city and ZIP code.',
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Smarty admin validateAddress: unhandled exception', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return new JsonResponse([
                'success' => false,
                'data'    => ['isValid' => false],
                'message' => 'Smarty address validation failed',
                'error'   => $e->getMessage(),
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
