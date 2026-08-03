<?php

declare(strict_types=1);

namespace SmartyAddressValidation\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1768262400RenameNatchezIdentifiersToSmarty extends MigrationStep
{
    private const TABLE_RENAMES = [
        'natchez_smarty_validation_log' => 'smarty_validation_log',
        'natchez_smarty_zip_prefix_lookup' => 'smarty_zip_prefix_lookup',
    ];

    private const CONFIG_PREFIX_OLD = 'NatchezSmartyAddressValidation.config.';
    private const CONFIG_PREFIX_NEW = 'SmartyAddressValidation.config.';

    public function getCreationTimestamp(): int
    {
        return 1768262400;
    }

    public function update(Connection $connection): void
    {
        $this->renameTables($connection);
        $this->renameCustomFieldSet($connection);
        $this->renameSystemConfigKeys($connection);
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function renameTables(Connection $connection): void
    {
        foreach (self::TABLE_RENAMES as $old => $new) {
            if (!$this->tableExists($connection, $old) || $this->tableExists($connection, $new)) {
                continue;
            }

            $connection->executeStatement(\sprintf('RENAME TABLE `%s` TO `%s`', $old, $new));
        }
    }

    private function renameCustomFieldSet(Connection $connection): void
    {
        $legacyId = $connection->fetchOne(
            'SELECT id FROM `custom_field_set` WHERE `name` = :name',
            ['name' => 'natchez_smarty_address_validation']
        );

        if ($legacyId === false) {
            return;
        }

        $currentId = $connection->fetchOne(
            'SELECT id FROM `custom_field_set` WHERE `name` = :name',
            ['name' => 'smarty_address_validation']
        );

        if ($currentId !== false) {
            return;
        }

        $connection->executeStatement(
            'UPDATE `custom_field_set` SET `name` = :new WHERE `id` = :id',
            ['new' => 'smarty_address_validation', 'id' => $legacyId]
        );
    }

    private function renameSystemConfigKeys(Connection $connection): void
    {
        $connection->executeStatement(
            'UPDATE `system_config` SET `configuration_key` = CONCAT(:newPrefix, SUBSTRING(`configuration_key`, :len))
             WHERE `configuration_key` LIKE :oldLike
               AND NOT EXISTS (
                   SELECT 1 FROM (SELECT `configuration_key` FROM `system_config`) AS existing
                   WHERE existing.`configuration_key` = CONCAT(:newPrefix2, SUBSTRING(`system_config`.`configuration_key`, :len2))
               )',
            [
                'newPrefix' => self::CONFIG_PREFIX_NEW,
                'newPrefix2' => self::CONFIG_PREFIX_NEW,
                'len' => \strlen(self::CONFIG_PREFIX_OLD) + 1,
                'len2' => \strlen(self::CONFIG_PREFIX_OLD) + 1,
                'oldLike' => self::CONFIG_PREFIX_OLD . '%',
            ]
        );
    }

    private function tableExists(Connection $connection, string $table): bool
    {
        return $connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :table',
            ['table' => $table]
        ) > 0;
    }
}
