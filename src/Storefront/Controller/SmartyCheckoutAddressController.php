<?php

declare(strict_types=1);

namespace SmartyIntegration\Storefront\Controller;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\Country\Aggregate\CountryState\CountryStateEntity;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]
final class SmartyCheckoutAddressController extends StorefrontController
{
    public function __construct(
        private readonly EntityRepository $customerAddressRepository,
        private readonly EntityRepository $countryRepository,
        private readonly EntityRepository $countryStateRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/smarty/checkout/apply-shipping-suggestion',
        name: 'frontend.smarty.checkout.apply_shipping_suggestion',
        methods: ['POST'],
        defaults: ['XmlHttpRequest' => true]
    )]
    public function applyShippingSuggestion(Request $request, SalesChannelContext $scContext): JsonResponse
    {
        $customer = $scContext->getCustomer();
        if (!$customer || !$customer->getActiveShippingAddress()) {
            return new JsonResponse([
                'success' => false,
                'message' => 'No customer shipping address found (guest checkout cannot be updated here).',
            ], 400);
        }

        $data = json_decode((string) $request->getContent(), true) ?: [];

        $street     = trim((string) ($data['street'] ?? ''));
        $city       = trim((string) ($data['city'] ?? ''));
        $postalCode = trim((string) ($data['postalCode'] ?? $data['zipcode'] ?? ''));
        $countryIso = strtoupper(trim((string) ($data['countryIso'] ?? 'US')));
        $stateCode  = strtoupper(trim((string) ($data['state'] ?? $data['state_abbreviation'] ?? '')));

        if ($street === '' || $city === '' || $postalCode === '') {
            return new JsonResponse([
                'success' => false,
                'message' => 'Missing street/city/postalCode.',
            ], 400);
        }

        $context = $scContext->getContext();

        $countryId = $this->resolveCountryId($countryIso, $context);
        if (!$countryId) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Could not resolve country.',
            ], 400);
        }

        $countryStateId = null;
        if ($stateCode !== '') {
            $countryStateId = $this->resolveCountryStateId($countryId, $stateCode, $context);
        }

        $addressId = $customer->getActiveShippingAddress()->getId();

        try {
            $payload = [[
                'id' => $addressId,
                'street' => $street,
                'city' => $city,
                'zipcode' => $postalCode,
                'countryId' => $countryId,
            ]];

            if ($countryStateId) {
                $payload[0]['countryStateId'] = $countryStateId;
            }

            $this->customerAddressRepository->update($payload, $context);

            return new JsonResponse(['success' => true, 'data' => ['addressId' => $addressId]]);
        } catch (\Throwable $e) {
            $this->logger->error('[Smarty] applyShippingSuggestion failed', ['e' => $e]);
            return new JsonResponse(['success' => false, 'message' => 'Failed to apply address.'], 500);
        }
    }

    private function resolveCountryId(string $iso2, Context $context): ?string
    {
        $criteria = (new Criteria())->addFilter(new EqualsFilter('iso', $iso2))->setLimit(1);
        /** @var CountryEntity|null $country */
        $country = $this->countryRepository->search($criteria, $context)->first();
        return $country?->getId();
    }

    private function resolveCountryStateId(string $countryId, string $stateShortCode, Context $context): ?string
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('countryId', $countryId))
            ->addFilter(new EqualsFilter('shortCode', 'US-' . $stateShortCode))
            ->setLimit(1);

        /** @var CountryStateEntity|null $state */
        $state = $this->countryStateRepository->search($criteria, $context)->first();
        return $state?->getId();
    }
}
