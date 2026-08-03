<?php

declare(strict_types=1);

namespace SmartyAddressValidation\Upgrade;

use Doctrine\DBAL\Connection;

class LegacyNamingUpgrade
{
    public const LEGACY_PLUGIN_NAME = 'NatchezSmartyAddressValidation';

    private const TABLE_RENAMES = [
        'natchez_smarty_validation_log' => 'smarty_validation_log',
        'natchez_smarty_zip_prefix_lookup' => 'smarty_zip_prefix_lookup',
    ];

    private const LEGACY_CUSTOM_FIELD_SET = 'natchez_smarty_address_validation';
    private const CUSTOM_FIELD_SET = 'smarty_address_validation';

    private const LEGACY_CONFIG_PREFIX = 'NatchezSmartyAddressValidation.config.';
    private const CONFIG_PREFIX = 'SmartyAddressValidation.config.';

    private const LEGACY_NAMESPACE = 'NatchezSmartyAddressValidation\\';
    private const NAMESPACE = 'SmartyAddressValidation\\';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function run(): void
    {
        if (!$this->hasLegacyState()) {
            return;
        }

        $this->adoptTables();
        $this->adoptCustomFieldSet();
        $this->adoptSystemConfig();
        $this->adoptMigrationHistory();
        $this->removeLegacyPluginRow();
    }

    private function hasLegacyState(): bool
    {
        foreach (array_keys(self::TABLE_RENAMES) as $legacyTable) {
            if ($this->tableExists($legacyTable)) {
                return true;
            }
        }

        $legacyRows = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM `system_config` WHERE `configuration_key` LIKE :prefix',
            ['prefix' => self::LEGACY_CONFIG_PREFIX . '%']
        );

        if ((int) $legacyRows > 0) {
            return true;
        }

        $legacyMigrations = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM `migration` WHERE LEFT(`class`, :len) = :prefix',
            ['len' => \strlen(self::LEGACY_NAMESPACE), 'prefix' => self::LEGACY_NAMESPACE]
        );

        return (int) $legacyMigrations > 0;
    }

    private function adoptTables(): void
    {
        foreach (self::TABLE_RENAMES as $legacy => $current) {
            if (!$this->tableExists($legacy)) {
                continue;
            }

            if (!$this->tableExists($current)) {
                $this->connection->executeStatement(
                    \sprintf('RENAME TABLE `%s` TO `%s`', $legacy, $current)
                );

                continue;
            }

            $this->connection->executeStatement(
                \sprintf('INSERT IGNORE INTO `%s` SELECT * FROM `%s`', $current, $legacy)
            );

            $backup = $legacy . '_backup';

            if (!$this->tableExists($backup)) {
                $this->connection->executeStatement(
                    \sprintf('RENAME TABLE `%s` TO `%s`', $legacy, $backup)
                );
            }
        }
    }

    private function adoptCustomFieldSet(): void
    {
        $legacyId = $this->connection->fetchOne(
            'SELECT `id` FROM `custom_field_set` WHERE `name` = :name',
            ['name' => self::LEGACY_CUSTOM_FIELD_SET]
        );

        if ($legacyId === false) {
            return;
        }

        $currentId = $this->connection->fetchOne(
            'SELECT `id` FROM `custom_field_set` WHERE `name` = :name',
            ['name' => self::CUSTOM_FIELD_SET]
        );

        if ($currentId === false) {
            $this->connection->executeStatement(
                'UPDATE `custom_field_set` SET `name` = :name WHERE `id` = :id',
                ['name' => self::CUSTOM_FIELD_SET, 'id' => $legacyId]
            );

            return;
        }

        $this->connection->executeStatement(
            'DELETE FROM `custom_field_set_relation` WHERE `set_id` = :id',
            ['id' => $legacyId]
        );
        $this->connection->executeStatement(
            'DELETE FROM `custom_field` WHERE `set_id` = :id',
            ['id' => $legacyId]
        );
        $this->connection->executeStatement(
            'DELETE FROM `custom_field_set` WHERE `id` = :id',
            ['id' => $legacyId]
        );
    }

    private function adoptSystemConfig(): void
    {
        $legacyRows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(`id`)) AS id, `configuration_key`, `sales_channel_id`
             FROM `system_config` WHERE `configuration_key` LIKE :prefix',
            ['prefix' => self::LEGACY_CONFIG_PREFIX . '%']
        );

        foreach ($legacyRows as $row) {
            $newKey = self::CONFIG_PREFIX
                . \substr((string) $row['configuration_key'], \strlen(self::LEGACY_CONFIG_PREFIX));

            $this->connection->executeStatement(
                'DELETE FROM `system_config`
                 WHERE `configuration_key` = :key
                   AND `sales_channel_id` <=> :salesChannelId
                   AND LOWER(HEX(`id`)) != :id',
                [
                    'key' => $newKey,
                    'salesChannelId' => $row['sales_channel_id'],
                    'id' => $row['id'],
                ]
            );

            $this->connection->executeStatement(
                'UPDATE `system_config` SET `configuration_key` = :key WHERE LOWER(HEX(`id`)) = :id',
                ['key' => $newKey, 'id' => $row['id']]
            );
        }
    }

    private function adoptMigrationHistory(): void
    {
        $legacyClasses = $this->connection->fetchFirstColumn(
            'SELECT `class` FROM `migration` WHERE LEFT(`class`, :len) = :prefix',
            ['len' => \strlen(self::LEGACY_NAMESPACE), 'prefix' => self::LEGACY_NAMESPACE]
        );

        foreach ($legacyClasses as $legacyClass) {
            $newClass = self::NAMESPACE
                . \substr((string) $legacyClass, \strlen(self::LEGACY_NAMESPACE));

            $exists = $this->connection->fetchOne(
                'SELECT COUNT(*) FROM `migration` WHERE `class` = :class',
                ['class' => $newClass]
            );

            if ((int) $exists > 0) {
                $this->connection->executeStatement(
                    'DELETE FROM `migration` WHERE `class` = :class',
                    ['class' => $legacyClass]
                );

                continue;
            }

            $this->connection->executeStatement(
                'UPDATE `migration` SET `class` = :new WHERE `class` = :old',
                ['new' => $newClass, 'old' => $legacyClass]
            );
        }
    }

    private function removeLegacyPluginRow(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM `plugin` WHERE `name` = :name',
            ['name' => self::LEGACY_PLUGIN_NAME]
        );
    }

    private function tableExists(string $table): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :table',
            ['table' => $table]
        ) > 0;
    }
}
