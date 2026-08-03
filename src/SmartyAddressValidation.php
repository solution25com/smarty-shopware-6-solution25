<?php

declare(strict_types=1);

namespace SmartyAddressValidation;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use SmartyAddressValidation\Upgrade\LegacyNamingUpgrade;

class SmartyAddressValidation extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);

        $upgrade = new LegacyNamingUpgrade($this->container->get(Connection::class));
        $upgrade->run();
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $connection = $this->container->get(Connection::class);
        $this->removeCustomFields($connection);
        $connection->executeStatement('DROP TABLE IF EXISTS `smarty_validation_log`');
    }

    private function removeCustomFields(Connection $connection): void
    {
        $setId = $connection->fetchOne(
            'SELECT id FROM custom_field_set WHERE name = :name',
            ['name' => 'smarty_address_validation']
        );

        if ($setId === false) {
            return;
        }

        $connection->executeStatement(
            'DELETE FROM custom_field_set_relation WHERE set_id = :setId',
            ['setId' => $setId]
        );

        $connection->executeStatement(
            'DELETE FROM custom_field WHERE set_id = :setId',
            ['setId' => $setId]
        );

        $connection->executeStatement(
            'DELETE FROM custom_field_set WHERE id = :setId',
            ['setId' => $setId]
        );
    }
}
