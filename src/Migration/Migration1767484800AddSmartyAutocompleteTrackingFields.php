<?php

declare(strict_types=1);

namespace SmartyAddressValidation\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1767484800AddSmartyAutocompleteTrackingFields extends MigrationStep
{
    private const SET_NAME = 'smarty_address_validation';

    public function getCreationTimestamp(): int
    {
        return 1767484800;
    }

    public function update(Connection $connection): void
    {
        $setId = $connection->fetchOne(
            'SELECT LOWER(HEX(id)) FROM custom_field_set WHERE name = :name',
            ['name' => self::SET_NAME]
        );

        if (!\is_string($setId) || $setId === '') {
            return;
        }

        $this->createField(
            $connection,
            $setId,
            'autocomplete_used_flag',
            'Autocomplete used',
            20
        );

        $this->createField(
            $connection,
            $setId,
            'user_changed_autocomplete_suggestion_flag',
            'User changed autocomplete suggestion',
            21
        );

        $this->createField(
            $connection,
            $setId,
            'suggested_address_declined_flag',
            'Suggested address declined',
            22
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function createField(
        Connection $connection,
        string $setId,
        string $name,
        string $label,
        int $position
    ): void {
        $exists = (bool) $connection->fetchOne(
            'SELECT 1 FROM custom_field WHERE name = :name',
            ['name' => $name]
        );

        if ($exists) {
            return;
        }

        $connection->executeStatement(
            'INSERT INTO custom_field (id, name, type, config, active, allow_customer_write, set_id, created_at)
             VALUES (UNHEX(:id), :name, "bool", :config, 1, 1, UNHEX(:setId), NOW(3))',
            [
                'id' => Uuid::randomHex(),
                'name' => $name,
                'setId' => $setId,
                'config' => json_encode([
                    'label' => ['en-GB' => $label],
                    'customFieldPosition' => $position,
                ], \JSON_THROW_ON_ERROR),
            ]
        );
    }
}
