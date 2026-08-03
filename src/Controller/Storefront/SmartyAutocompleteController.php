<?php

declare(strict_types=1);

namespace SmartyAddressValidation\Controller\Storefront;

use SmartyAddressValidation\Service\SmartyAutocompleteService;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]
class SmartyAutocompleteController extends StorefrontController
{
    public function __construct(
        private readonly SmartyAutocompleteService $autocompleteService
    ) {
    }

    #[Route(
        path: '/smarty/address/autocomplete/zip',
        name: 'frontend.smarty.address.autocomplete.zip',
        options: ['seo' => false],
        defaults: ['XmlHttpRequest' => true, '_noStore' => true],
        methods: ['GET', 'POST']
    )]
    public function zip(Request $request, SalesChannelContext $context): JsonResponse
    {
        return $this->safe(fn () => $this->autocompleteService->autocompleteZip(
            $this->payload($request),
            $context->getSalesChannelId()
        ));
    }

    #[Route(
        path: '/smarty/address/autocomplete/street',
        name: 'frontend.smarty.address.autocomplete.street',
        options: ['seo' => false],
        defaults: ['XmlHttpRequest' => true, '_noStore' => true],
        methods: ['GET', 'POST']
    )]
    public function street(Request $request, SalesChannelContext $context): JsonResponse
    {
        return $this->safe(fn () => $this->autocompleteService->autocompleteStreet(
            $this->payload($request),
            $context->getSalesChannelId()
        ));
    }

    #[Route(
        path: '/smarty/address/reverse-geo',
        name: 'frontend.smarty.address.reverse_geo',
        options: ['seo' => false],
        defaults: ['XmlHttpRequest' => true, '_noStore' => true],
        methods: ['POST']
    )]
    public function reverseGeo(Request $request, SalesChannelContext $context): JsonResponse
    {
        return $this->safe(fn () => $this->autocompleteService->reverseGeocode(
            $this->payload($request),
            $context->getSalesChannelId()
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $content = trim($request->getContent());
        $payload = [];

        if ($content !== '') {
            $decoded = json_decode($content, true);
            $payload = \is_array($decoded) ? $decoded : [];
        }

        return array_merge(
            $request->query->all(),
            $request->request->all(),
            $payload
        );
    }

    private function safe(callable $callback): JsonResponse
    {
        try {
            return new JsonResponse($callback());
        } catch (Throwable) {
            return new JsonResponse([
                'success' => true,
                'suggestions' => [],
            ]);
        }
    }
}
