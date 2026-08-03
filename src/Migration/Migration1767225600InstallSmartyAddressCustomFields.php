<?php

declare(strict_types=1);

namespace SmartyAddressValidation\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1767225600InstallSmartyAddressCustomFields extends MigrationStep
{
    private const CUSTOM_FIELD_SET_NAME = 'smarty_address_validation';
    private const ENTITY_NAME = 'customer_address';

    public function getCreationTimestamp(): int
    {
        return 1767225600;
    }

    public function update(Connection $connection): void
    {
        $setId = $this->getOrCreateCustomFieldSet($connection);

        $this->createRelation($connection, $setId);

        $this->createCustomField(
            $connection,
            $setId,
            'last_smarty_validation',
            'datetime',
            'Last Smarty validation',
            1
        );

        $this->createCustomField(
            $connection,
            $setId,
            'smarty_latitude',
            'float',
            'Smarty latitude',
            2
        );

        $this->createCustomField(
            $connection,
            $setId,
            'smarty_longitude',
            'float',
            'Smarty longitude',
            3
        );

        $this->createCustomField(
            $connection,
            $setId,
            'verified_flag',
            'bool',
            'Verified flag',
            4
        );

        $this->createCustomField(
            $connection,
            $setId,
            'smarty_request_data_json',
            'json',
            'Smarty request data JSON',
            5
        );

        $this->createCustomField(
            $connection,
            $setId,
            'smarty_response_data_json',
            'json',
            'Smarty response data JSON',
            6
        );

        $this->createCustomField(
            $connection,
            $setId,
            'smarty_standardized_address_json',
            'json',
            'Smarty standardized address JSON',
            7
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function getOrCreateCustomFieldSet(Connection $connection): string
    {
        $existingId = $connection->fetchOne(
            'SELECT LOWER(HEX(id)) FROM custom_field_set WHERE name = :name',
            ['name' => self::CUSTOM_FIELD_SET_NAME]
        );

        if (\is_string($existingId) && $existingId !== '') {
            return $existingId;
        }

        $setId = Uuid::randomHex();

        $connection->executeStatement(
            'INSERT INTO custom_field_set (id, name, config, active, `global`, created_at)
             VALUES (UNHEX(:id), :name, :config, 1, 0, NOW(3))',
            [
                'id' => $setId,
                'name' => self::CUSTOM_FIELD_SET_NAME,
                'config' => $this->json([
                    'label' => [
                        'en-GB' => 'Smarty Address Validation',
                    ],
                ]),
            ]
        );

        return $setId;
    }

    private function createRelation(Connection $connection, string $setId): void
    {
        $exists = (bool) $connection->fetchOne(
            'SELECT 1 FROM custom_field_set_relation
             WHERE set_id = UNHEX(:setId) AND entity_name = :entityName',
            [
                'setId' => $setId,
                'entityName' => self::ENTITY_NAME,
            ]
        );

        if ($exists) {
            return;
        }

        $connection->executeStatement(
            'INSERT INTO custom_field_set_relation (id, set_id, entity_name, created_at)
             VALUES (UNHEX(:id), UNHEX(:setId), :entityName, NOW(3))',
            [
                'id' => Uuid::randomHex(),
                'setId' => $setId,
                'entityName' => self::ENTITY_NAME,
            ]
        );
    }

    private function createCustomField(
        Connection $connection,
        string $setId,
        string $name,
        string $type,
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
            'INSERT INTO custom_field (id, name, type, config, active, set_id, created_at)
             VALUES (UNHEX(:id), :name, :type, :config, 1, UNHEX(:setId), NOW(3))',
            [
                'id' => Uuid::randomHex(),
                'name' => $name,
                'type' => $type,
                'config' => $this->json([
                    'label' => [
                        'en-GB' => $label,
                    ],
                    'customFieldPosition' => $position,
                ]),
                'setId' => $setId,
            ]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data): string
    {
        return (string) json_encode($data, \JSON_THROW_ON_ERROR);
    }
}
