<?php

declare(strict_types=1);

namespace SmartyAddressValidation\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1767657600RepairSmartyGeoCustomFieldTypes extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1767657600;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(
            'UPDATE custom_field
             SET type = :type, updated_at = NOW(3)
             WHERE name IN (:latitude, :longitude)',
            [
                'type' => 'float',
                'latitude' => 'smarty_latitude',
                'longitude' => 'smarty_longitude',
            ]
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
