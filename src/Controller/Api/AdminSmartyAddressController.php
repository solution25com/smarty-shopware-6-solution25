<?php

declare(strict_types=1);

namespace SmartyAddressValidation\Controller\Api;

use SmartyAddressValidation\Service\AdminAddressVerificationService;
use SmartyAddressValidation\Service\SmartyAutocompleteService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
class AdminSmartyAddressController extends AbstractController
{
    public function __construct(
        private readonly AdminAddressVerificationService $service,
        private readonly SmartyAutocompleteService $autocompleteService
    ) {
    }

    #[Route(
        path: '/api/_action/smarty/address/autocomplete/zip',
        name: 'api.action.smarty.address.autocomplete.zip',
        defaults: ['_acl' => ['customer.viewer']],
        methods: ['POST']
    )]
    public function autocompleteZip(Request $request): JsonResponse
    {
        return $this->safe(fn () => $this->autocompleteService->autocompleteZip(
            $this->payload($request),
            null,
            'enableAdminValidation'
        ));
    }

    #[Route(
        path: '/api/_action/smarty/address/autocomplete/street',
        name: 'api.action.smarty.address.autocomplete.street',
        defaults: ['_acl' => ['customer.viewer']],
        methods: ['POST']
    )]
    public function autocompleteStreet(Request $request): JsonResponse
    {
        return $this->safe(fn () => $this->autocompleteService->autocompleteStreet(
            $this->payload($request),
            null,
            'enableAdminValidation'
        ));
    }

    #[Route(
        path: '/api/_action/smarty/address/validate',
        name: 'api.action.smarty.address.validate',
        defaults: ['_acl' => ['customer.viewer', 'order.viewer']],
        methods: ['POST']
    )]
    public function validate(Request $request, Context $context): JsonResponse
    {
        return $this->safe(fn () => $this->service->validate($this->payload($request), $context));
    }

    #[Route(
        path: '/api/_action/smarty/address/apply-suggestion',
        name: 'api.action.smarty.address.apply_suggestion',
        defaults: ['_acl' => ['customer.editor', 'order.editor']],
        methods: ['POST']
    )]
    public function applySuggestion(Request $request, Context $context): JsonResponse
    {
        return $this->safe(fn () => $this->service->applySuggestion($this->payload($request), $context));
    }

    #[Route(
        path: '/api/_action/smarty/address/confirm-original',
        name: 'api.action.smarty.address.confirm_original',
        defaults: ['_acl' => ['customer.editor', 'order.editor']],
        methods: ['POST']
    )]
    public function confirmOriginal(Request $request, Context $context): JsonResponse
    {
        return $this->safe(fn () => $this->service->confirmOriginal($this->payload($request), $context));
    }

    #[Route(
        path: '/api/_action/smarty/address/logs',
        name: 'api.action.smarty.address.logs',
        defaults: ['_acl' => ['customer.viewer', 'order.viewer']],
        methods: ['GET']
    )]
    public function logs(Request $request): JsonResponse
    {
        return $this->safe(fn () => $this->service->getLogs(
            (string) $request->query->get('addressId', ''),
            (string) $request->query->get('addressType', 'customer_address')
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);

        return \is_array($decoded) ? $decoded : $request->request->all();
    }

    private function safe(callable $callback): JsonResponse
    {
        try {
            return new JsonResponse($callback());
        } catch (Throwable $exception) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Smarty admin request failed.',
                'details' => $exception->getMessage(),
            ], 200);
        }
    }
}
