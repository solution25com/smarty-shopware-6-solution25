<?php

declare(strict_types=1);

namespace SmartyAddressValidation\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1767312000CreateSmartyValidationLogTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1767312000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS `smarty_validation_log` (
                `id` BINARY(16) NOT NULL,
                `customer_address_id` BINARY(16) NULL,
                `customer_id` BINARY(16) NULL,
                `original_address` JSON NULL,
                `smarty_request` JSON NULL,
                `smarty_response` JSON NULL,
                `validation_result` JSON NULL,
                `error` LONGTEXT NULL,
                `created_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`id`),
                INDEX `idx.smarty_log.customer_address_id` (`customer_address_id`),
                INDEX `idx.smarty_log.customer_id` (`customer_id`),
                INDEX `idx.smarty_log.created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
