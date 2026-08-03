<?php

declare(strict_types=1);

namespace SmartyAddressValidation\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1767398400ExtendSmartyLogsAndOrderAddressFields extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1767398400;
    }

    public function update(Connection $connection): void
    {
        $this->addOrderAddressRelation($connection);

        $this->addLogColumn($connection, 'order_id', 'BINARY(16) NULL AFTER `customer_id`');
        $this->addLogColumn($connection, 'selected_suggestion', 'JSON NULL AFTER `validation_result`');

        $this->addLogIndex($connection, 'idx_smarty_log_order_id', 'order_id');
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function addOrderAddressRelation(Connection $connection): void
    {
        $setId = $connection->fetchOne(
            'SELECT LOWER(HEX(id)) FROM custom_field_set WHERE name = :name',
            ['name' => 'smarty_address_validation']
        );

        if (!\is_string($setId) || $setId === '') {
            return;
        }

        $exists = (bool) $connection->fetchOne(
            'SELECT 1 FROM custom_field_set_relation
             WHERE set_id = UNHEX(:setId) AND entity_name = :entity',
            [
                'setId' => $setId,
                'entity' => 'order_address',
            ]
        );

        if ($exists) {
            return;
        }

        $connection->executeStatement(
            'INSERT INTO custom_field_set_relation (id, set_id, entity_name, created_at)
             VALUES (UNHEX(:id), UNHEX(:setId), :entity, NOW(3))',
            [
                'id' => Uuid::randomHex(),
                'setId' => $setId,
                'entity' => 'order_address',
            ]
        );
    }

    private function addLogColumn(Connection $connection, string $name, string $definition): void
    {
        $exists = $connection->fetchOne(
            'SHOW COLUMNS FROM `smarty_validation_log` LIKE :name',
            ['name' => $name]
        );

        if ($exists !== false) {
            return;
        }

        $connection->executeStatement(
            sprintf(
                'ALTER TABLE `smarty_validation_log` ADD COLUMN `%s` %s',
                $name,
                $definition
            )
        );
    }

    private function addLogIndex(Connection $connection, string $indexName, string $column): void
    {
        $exists = $connection->fetchOne(
            'SHOW INDEX FROM `smarty_validation_log` WHERE Key_name = :name',
            ['name' => $indexName]
        );

        if ($exists !== false) {
            return;
        }

        $connection->executeStatement(
            sprintf(
                'CREATE INDEX `%s` ON `smarty_validation_log` (`%s`)',
                $indexName,
                $column
            )
        );
    }
}
