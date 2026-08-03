<?php

declare(strict_types=1);

namespace SmartyAddressValidation\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1767830400SeedNewJerseyZipPrefixRows extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1767830400;
    }

    public function update(Connection $connection): void
    {
        foreach ($this->rows() as $row) {
            $exists = (bool) $connection->fetchOne(
                'SELECT 1 FROM `smarty_zip_prefix_lookup`
                 WHERE zipcode = :zipcode AND city = :city AND state = :state',
                [
                    'zipcode' => $row['zipcode'],
                    'city' => $row['city'],
                    'state' => $row['state'],
                ]
            );

            if ($exists) {
                continue;
            }

            $connection->executeStatement(
                'INSERT INTO `smarty_zip_prefix_lookup`
                    (`id`, `zipcode`, `city`, `state`, `state_name`, `country`, `created_at`)
                 VALUES
                    (UNHEX(:id), :zipcode, :city, :state, :stateName, "US", NOW(3))',
                [
                    'id' => Uuid::randomHex(),
                    'zipcode' => $row['zipcode'],
                    'city' => $row['city'],
                    'state' => $row['state'],
                    'stateName' => $row['stateName'],
                ]
            );
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    /**
     * @return list<array{zipcode: string, city: string, state: string, stateName: string}>
     */
    private function rows(): array
    {
        return [
            ['zipcode' => '07001', 'city' => 'Avenel', 'state' => 'NJ', 'stateName' => 'New Jersey'],
            ['zipcode' => '07002', 'city' => 'Bayonne', 'state' => 'NJ', 'stateName' => 'New Jersey'],
            ['zipcode' => '07003', 'city' => 'Bloomfield', 'state' => 'NJ', 'stateName' => 'New Jersey'],
            ['zipcode' => '07004', 'city' => 'Fairfield', 'state' => 'NJ', 'stateName' => 'New Jersey'],
            ['zipcode' => '07005', 'city' => 'Boonton', 'state' => 'NJ', 'stateName' => 'New Jersey'],
            ['zipcode' => '07006', 'city' => 'Caldwell', 'state' => 'NJ', 'stateName' => 'New Jersey'],
            ['zipcode' => '07008', 'city' => 'Carteret', 'state' => 'NJ', 'stateName' => 'New Jersey'],
            ['zipcode' => '07008', 'city' => 'West Carteret', 'state' => 'NJ', 'stateName' => 'New Jersey'],
        ];
    }
}
