<?php

declare(strict_types=1);

namespace SmartyAddressValidation\Service;

use Doctrine\DBAL\Connection;
use JsonException;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Throwable;

class SmartyLogger
{
    private const CONFIG_PREFIX = 'SmartyAddressValidation.config.';

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly LoggerInterface $logger,
        private readonly Connection $connection
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function diagnostic(string $message, array $context = []): void
    {
        $this->logger->info('[SmartyAddressValidation diagnostic] ' . $message, $this->sanitizeContext($context));
    }

    /**
     * @param array<string, mixed> $context
     */
    public function debug(string $message, array $context = [], ?string $salesChannelId = null): void
    {
        if ($this->isEnabled($salesChannelId)) {
            $this->logger->debug($message, $this->sanitizeContext($context));
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = [], ?string $salesChannelId = null): void
    {
        if ($this->isEnabled($salesChannelId)) {
            $this->logger->warning($message, $this->sanitizeContext($context));
        }
    }

    /**
     * @param array<string, mixed> $originalAddress
     * @param array<string, mixed>|null $smartyRequest
     * @param array<string, mixed>|null $smartyResponse
     * @param array<string, mixed> $validationResult
     * @param array<string, mixed>|null $selectedSuggestion
     */
    public function logValidationAttempt(
        ?string $customerAddressId,
        ?string $customerId,
        array $originalAddress,
        ?array $smartyRequest,
        ?array $smartyResponse,
        array $validationResult,
        ?string $error,
        ?string $salesChannelId = null,
        ?string $orderId = null,
        ?array $selectedSuggestion = null
    ): void {
        if (!$this->isEnabled($salesChannelId)) {
            return;
        }

        $this->logger->info('Smarty validation attempt.', [
            'customer_address_id' => $customerAddressId,
            'customer_id' => $customerId,
            'order_id' => $orderId,
            'error' => $error,
        ]);

        try {
            $this->connection->executeStatement(
                'INSERT INTO `smarty_validation_log`
                    (`id`, `customer_address_id`, `customer_id`, `order_id`,
                     `original_address`, `smarty_request`, `smarty_response`,
                     `validation_result`, `selected_suggestion`, `error`, `created_at`)
                 VALUES
                    (UNHEX(:id),
                     IF(:customerAddressId IS NULL, NULL, UNHEX(:customerAddressId)),
                     IF(:customerId IS NULL, NULL, UNHEX(:customerId)),
                     IF(:orderId IS NULL, NULL, UNHEX(:orderId)),
                     :originalAddress, :smartyRequest, :smartyResponse,
                     :validationResult, :selectedSuggestion, :error, NOW(3))',
                [
                    'id' => Uuid::randomHex(),
                    'customerAddressId' => $this->uuidOrNull($customerAddressId),
                    'customerId' => $this->uuidOrNull($customerId),
                    'orderId' => $this->uuidOrNull($orderId),
                    'originalAddress' => $this->json($originalAddress),
                    'smartyRequest' => $this->nullableJson($this->sanitizeNullable($smartyRequest)),
                    'smartyResponse' => $this->nullableJson($this->sanitizeNullable($smartyResponse)),
                    'validationResult' => $this->json($validationResult),
                    'selectedSuggestion' => $this->nullableJson($selectedSuggestion),
                    'error' => $error,
                ]
            );
        } catch (Throwable $exception) {
            $this->logger->warning('Failed to write Smarty validation log.', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function isEnabled(?string $salesChannelId): bool
    {
        return (bool) $this->systemConfigService->get(
            self::CONFIG_PREFIX . 'enableLogging',
            $salesChannelId
        );
    }

    /**
     * @param array<string, mixed>|null $context
     * @return array<string, mixed>|null
     */
    private function sanitizeNullable(?array $context): ?array
    {
        return $context === null ? null : $this->sanitizeContext($context);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function sanitizeContext(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                $sanitized[$key] = '[redacted]';
                continue;
            }

            $sanitized[$key] = \is_array($value) ? $this->sanitizeContext($value) : $value;
        }

        return $sanitized;
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);

        return str_contains($key, 'token')
            || str_contains($key, 'auth')
            || str_contains($key, 'key')
            || str_contains($key, 'secret');
    }

    private function uuidOrNull(?string $id): ?string
    {
        return $id !== null && Uuid::isValid($id) ? $id : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data): string
    {
        try {
            return (string) json_encode($data, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return '{}';
        }
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private function nullableJson(?array $data): ?string
    {
        return $data === null ? null : $this->json($data);
    }
}
