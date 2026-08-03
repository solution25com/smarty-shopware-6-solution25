<?php

declare(strict_types=1);

namespace SmartyAddressValidation\Migration;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1768089600AllowCustomerWriteAutocompleteTrackingFields extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1768089600;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(
            'UPDATE `custom_field`
             SET `allow_customer_write` = 1,
                 `updated_at` = NOW(3)
             WHERE `name` IN (:fields)',
            [
                'fields' => [
                    'autocomplete_used_flag',
                    'user_changed_autocomplete_suggestion_flag',
                ],
            ],
            [
                'fields' => ArrayParameterType::STRING,
            ]
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
