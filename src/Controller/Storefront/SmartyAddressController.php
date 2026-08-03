<?php

declare(strict_types=1);

namespace SmartyAddressValidation\Controller\Storefront;

use SmartyAddressValidation\Service\AddressConfirmationService;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]
class SmartyAddressController extends StorefrontController
{
    public function __construct(
        private readonly AddressConfirmationService $addressConfirmationService
    ) {
    }

    #[Route(
        path: '/smarty/address/status',
        name: 'frontend.smarty.address.status',
        options: ['seo' => false],
        defaults: ['XmlHttpRequest' => true, '_noStore' => true],
        methods: ['GET']
    )]
    public function status(Request $request, SalesChannelContext $context): JsonResponse
    {
        try {
            return new JsonResponse(
                $this->addressConfirmationService->getStatus($context, $request)
            );
        } catch (Throwable $exception) {
            return $this->safeError('Unable to check Smarty address status.', $exception);
        }
    }

    #[Route(
        path: '/smarty/address/validate',
        name: 'frontend.smarty.address.validate',
        options: ['seo' => false],
        defaults: ['XmlHttpRequest' => true, '_noStore' => true],
        methods: ['POST']
    )]
    public function validateAddress(Request $request, SalesChannelContext $context): JsonResponse
    {
        try {
            $payload = $this->getPayload($request);
            $addressId = $this->getString($payload, 'addressId');

            if ($addressId === '') {
                return $this->jsonError('Missing addressId.', 400);
            }

            $result = $this->addressConfirmationService->validateAddress($addressId, $context);

            return new JsonResponse($result->toArray());
        } catch (Throwable $exception) {
            return $this->safeError('Unable to validate address.', $exception);
        }
    }

    #[Route(
        path: '/smarty/address/use-suggestion',
        name: 'frontend.smarty.address.use_suggestion',
        options: ['seo' => false],
        defaults: ['XmlHttpRequest' => true, '_noStore' => true],
        methods: ['POST']
    )]
    public function useSuggestion(Request $request, SalesChannelContext $context): JsonResponse
    {
        try {
            $payload = $this->getPayload($request);
            $addressId = $this->getString($payload, 'addressId');
            $suggestionIndex = max(0, (int) ($payload['suggestionIndex'] ?? 0));

            if ($addressId === '') {
                return $this->jsonError('Missing addressId.', 400);
            }

            return new JsonResponse(
                $this->addressConfirmationService->useSuggestion($addressId, $suggestionIndex, $context)
            );
        } catch (Throwable $exception) {
            return $this->safeError('Unable to apply Smarty suggestion.', $exception);
        }
    }

    #[Route(
        path: '/smarty/address/use-original',
        name: 'frontend.smarty.address.use_original',
        options: ['seo' => false],
        defaults: ['XmlHttpRequest' => true, '_noStore' => true],
        methods: ['POST']
    )]
    public function useOriginal(Request $request, SalesChannelContext $context): JsonResponse
    {
        try {
            $payload = $this->getPayload($request);
            $addressId = $this->getString($payload, 'addressId');

            if ($addressId === '') {
                return $this->jsonError('Missing addressId.', 400);
            }

            return new JsonResponse(
                $this->addressConfirmationService->useOriginal($addressId, $context)
            );
        } catch (Throwable $exception) {
            return $this->safeError('Unable to keep original address.', $exception);
        }
    }

    #[Route(
        path: '/smarty/address/confirm-valid',
        name: 'frontend.smarty.address.confirm_valid',
        options: ['seo' => false],
        defaults: ['XmlHttpRequest' => true, '_noStore' => true],
        methods: ['POST']
    )]
    public function confirmValid(Request $request, SalesChannelContext $context): JsonResponse
    {
        try {
            $payload = $this->getPayload($request);
            $addressId = $this->getString($payload, 'addressId');

            if ($addressId === '') {
                return $this->jsonError('Missing addressId.', 400);
            }

            return new JsonResponse(
                $this->addressConfirmationService->confirmValid($addressId, $context)
            );
        } catch (Throwable $exception) {
            return $this->safeError('Unable to confirm valid address.', $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getPayload(Request $request): array
    {
        $content = trim($request->getContent());

        if ($content !== '') {
            $decoded = json_decode($content, true);

            if (\is_array($decoded)) {
                return $decoded;
            }
        }

        return $request->request->all();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function getString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? '';

        return \is_scalar($value) ? trim((string) $value) : '';
    }

    private function jsonError(string $message, int $statusCode): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'error' => $message,
        ], $statusCode);
    }

    private function safeError(string $message, Throwable $exception): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'error' => $message,
            'details' => $exception->getMessage(),
        ], 200);
    }
}
