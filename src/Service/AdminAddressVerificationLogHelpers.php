<?php

declare(strict_types=1);

namespace SmartyAddressValidation\Service;

use Shopware\Core\Framework\Uuid\Uuid;

trait AdminAddressVerificationLogHelpers
{
    /**
     * @return array<string, mixed>
     */
    public function getLogs(string $addressId, string $addressType): array
    {
        if (!Uuid::isValid($addressId)) {
            return [
                'success' => true,
                'logs' => [],
            ];
        }

        $queryId = $addressId;
        $column = 'customer_address_id';

        if ($addressType === 'order_address') {
            $orderId = $this->findOrderIdForOrderAddress($addressId);

            if ($orderId === null) {
                return [
                    'success' => true,
                    'logs' => [],
                ];
            }

            $queryId = $orderId;
            $column = 'order_id';
        }

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT *
                 FROM `smarty_validation_log`
                 WHERE `%s` = UNHEX(:id)
                 ORDER BY `created_at` DESC
                 LIMIT 20',
                $column
            ),
            [
                'id' => $queryId,
            ]
        );

        return [
            'success' => true,
            'logs' => array_map([$this, 'decodeLogRow'], $rows),
        ];
    }

    private function findOrderIdForOrderAddress(string $addressId): ?string
    {
        if (!Uuid::isValid($addressId)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT LOWER(HEX(`id`)) AS id
             FROM `order`
             WHERE `billing_address_id` = UNHEX(:id)
             LIMIT 1',
            [
                'id' => $addressId,
            ]
        );

        if (\is_array($row) && \is_string($row['id'] ?? null)) {
            return $row['id'];
        }

        $row = $this->connection->fetchAssociative(
            'SELECT LOWER(HEX(`order_id`)) AS id
             FROM `order_delivery`
             WHERE `shipping_order_address_id` = UNHEX(:id)
             LIMIT 1',
            [
                'id' => $addressId,
            ]
        );

        return \is_array($row) && \is_string($row['id'] ?? null)
            ? $row['id']
            : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decodeLogRow(array $row): array
    {
        foreach (['id', 'customer_address_id', 'customer_id', 'sales_channel_id', 'order_id'] as $binaryColumn) {
            if (isset($row[$binaryColumn]) && \is_string($row[$binaryColumn]) && strlen($row[$binaryColumn]) === 16) {
                $row[$binaryColumn] = Uuid::fromBytesToHex($row[$binaryColumn]);
            }
        }

        foreach (['original_address', 'smarty_request', 'smarty_response', 'validation_result', 'selected_suggestion'] as $jsonColumn) {
            $row[$jsonColumn] = isset($row[$jsonColumn])
                ? json_decode((string) $row[$jsonColumn], true)
                : null;
        }

        return $row;
    }
}
