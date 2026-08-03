<?php

declare(strict_types=1);

namespace SmartyAddressValidation\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1768176000AddSuggestedAddressDeclinedField extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1768176000;
    }

    public function update(Connection $connection): void
    {
        $setId = $connection->fetchOne(
            'SELECT LOWER(HEX(id)) FROM `custom_field_set` WHERE `name` = :name',
            ['name' => 'smarty_address_validation']
        );

        if (\is_string($setId) && $setId !== '') {
            $this->createField($connection, $setId);
        }

        $this->backfill($connection, 'customer_address');
        $this->backfill($connection, 'order_address');
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function createField(Connection $connection, string $setId): void
    {
        $exists = (bool) $connection->fetchOne(
            'SELECT 1 FROM `custom_field` WHERE `name` = :name',
            ['name' => 'suggested_address_declined_flag']
        );

        if ($exists) {
            return;
        }

        $connection->executeStatement(
            'INSERT INTO `custom_field` (id, name, type, config, active, allow_customer_write, set_id, created_at)
             VALUES (UNHEX(:id), :name, "bool", :config, 1, 1, UNHEX(:setId), NOW(3))',
            [
                'id' => Uuid::randomHex(),
                'name' => 'suggested_address_declined_flag',
                'setId' => $setId,
                'config' => json_encode([
                    'label' => ['en-GB' => 'Suggested address declined'],
                    'customFieldPosition' => 22,
                ], \JSON_THROW_ON_ERROR),
            ]
        );
    }

    private function backfill(Connection $connection, string $table): void
    {
        $connection->executeStatement(
            sprintf(
                'UPDATE `%s`
                 SET `custom_fields` = JSON_SET(
                     COALESCE(`custom_fields`, JSON_OBJECT()),
                     "$.suggested_address_declined_flag",
                     IF(
                         JSON_CONTAINS_PATH(`custom_fields`, "one", "$.suggested_address_declined_flag"),
                         JSON_EXTRACT(`custom_fields`, "$.suggested_address_declined_flag"),
                         0
                     )
                 )
                 WHERE `custom_fields` IS NULL
                    OR NOT JSON_CONTAINS_PATH(`custom_fields`, "one", "$.suggested_address_declined_flag")',
                $table
            )
        );
    }
}
