<?php

declare(strict_types=1);

namespace SmartyAddressValidation\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1767916800RenameSmartyValidAddressField extends MigrationStep
{
    private const OLD_FIELD = 'smarty_valid_address';
    private const NEW_FIELD = 'verified_flag';

    public function getCreationTimestamp(): int
    {
        return 1767916800;
    }

    public function update(Connection $connection): void
    {
        $this->renameCustomFieldDefinition($connection);
        $this->renameAddressCustomFieldValues($connection, 'customer_address');
        $this->renameAddressCustomFieldValues($connection, 'order_address');
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function renameCustomFieldDefinition(Connection $connection): void
    {
        $newFieldExists = (bool) $connection->fetchOne(
            'SELECT 1 FROM `custom_field` WHERE `name` = :name',
            ['name' => self::NEW_FIELD]
        );

        $oldFieldId = $connection->fetchOne(
            'SELECT LOWER(HEX(`id`)) FROM `custom_field` WHERE `name` = :name',
            ['name' => self::OLD_FIELD]
        );

        if (!\is_string($oldFieldId) || $oldFieldId === '') {
            return;
        }

        if ($newFieldExists) {
            $connection->executeStatement(
                'DELETE FROM `custom_field` WHERE `id` = UNHEX(:id)',
                ['id' => $oldFieldId]
            );

            return;
        }

        $connection->executeStatement(
            'UPDATE `custom_field`
             SET `name` = :newName,
                 `config` = JSON_SET(COALESCE(`config`, JSON_OBJECT()), \'$.label."en-GB"\', :label),
                 `updated_at` = NOW(3)
             WHERE `id` = UNHEX(:id)',
            [
                'id' => $oldFieldId,
                'newName' => self::NEW_FIELD,
                'label' => 'Verified flag',
            ]
        );
    }

    private function renameAddressCustomFieldValues(Connection $connection, string $table): void
    {
        $connection->executeStatement(
            sprintf(
                'UPDATE `%s`
                 SET `custom_fields` = JSON_REMOVE(
                     JSON_SET(
                         COALESCE(`custom_fields`, JSON_OBJECT()),
                         "$.%s",
                         IF(
                             JSON_CONTAINS_PATH(`custom_fields`, "one", "$.%s"),
                             JSON_EXTRACT(`custom_fields`, "$.%s"),
                             JSON_EXTRACT(`custom_fields`, "$.%s")
                         )
                     ),
                     "$.%s"
                 )
                 WHERE JSON_CONTAINS_PATH(`custom_fields`, "one", "$.%s")',
                $table,
                self::NEW_FIELD,
                self::NEW_FIELD,
                self::NEW_FIELD,
                self::OLD_FIELD,
                self::OLD_FIELD,
                self::OLD_FIELD
            )
        );
    }
}
