<?php

declare(strict_types=1);

namespace SmartyIntegration\Storefront\Controller;

use Psr\Log\LoggerInterface;
use SmartyIntegration\Service\SmartyApiService;
use Shopware\Core\Framework\Context;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class SmartyTestConnectionController extends StorefrontController
{
    public function __construct(
        private readonly SmartyApiService $smartyApiService,
        private readonly LoggerInterface $logger
    ) {
    }

    #[Route(
        path: '/api/_action/smarty/test-connection',
        name: 'smarty.test_connection',
        methods: ['POST'],
        defaults: ['_routeScope' => ['api'], '_csrf_protected' => false]
    )]
    public function testConnection(Request $request, Context $context): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true) ?? [];

        $authId       = $payload['authId']       ?? null;
        $authToken    = $payload['authToken']    ?? null;
        $environment  = $payload['environment']  ?? null;
        $salesChannelId = $payload['salesChannelId'] ?? null;

        $this->logger->info('Smarty admin testConnection called', [
            'salesChannelId' => $salesChannelId,
            'has_authId'     => $authId !== null && $authId !== '',
            'has_authToken'  => $authToken !== null && $authToken !== '',
            'environment'    => $environment,
        ]);

        try {
            $success = $this->smartyApiService->testConnectionWithCredentials(
                $authId,
                $authToken,
                $environment,
                \is_string($salesChannelId) && $salesChannelId !== '' ? $salesChannelId : null
            );

            if ($success) {
                return new JsonResponse([
                    'success' => true,
                    'message' => 'Connection to Smarty US Street API succeeded.',
                ]);
            }

            return new JsonResponse([
                'success' => false,
                'message' => 'Connection to Smarty US Street API failed. Check credentials and logs.',
            ], JsonResponse::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('Smarty admin testConnection exception', [
                'message' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'success' => false,
                'message' => 'Unexpected error while testing connection.',
                'error'   => $e->getMessage(),
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
