<?php

declare(strict_types=1);

namespace SmartyAddressValidation\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1767571200CreateSmartyZipPrefixLookupTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1767571200;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS `smarty_zip_prefix_lookup` (
                `id` BINARY(16) NOT NULL,
                `zipcode` VARCHAR(10) NOT NULL,
                `city` VARCHAR(120) NOT NULL,
                `state` VARCHAR(2) NOT NULL,
                `state_name` VARCHAR(120) NOT NULL,
                `country` VARCHAR(2) NOT NULL DEFAULT "US",
                `created_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`id`),
                INDEX `idx.smarty_zip.zipcode` (`zipcode`),
                INDEX `idx.smarty_zip.state` (`state`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        foreach ($this->seedRows() as $row) {
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
    private function seedRows(): array
    {
        return [
            ['zipcode' => '95010', 'city' => 'Capitola', 'state' => 'CA', 'stateName' => 'California'],
            ['zipcode' => '95011', 'city' => 'Campbell', 'state' => 'CA', 'stateName' => 'California'],
            ['zipcode' => '95013', 'city' => 'Coyote', 'state' => 'CA', 'stateName' => 'California'],
            ['zipcode' => '95014', 'city' => 'Cupertino', 'state' => 'CA', 'stateName' => 'California'],
            ['zipcode' => '95015', 'city' => 'Cupertino', 'state' => 'CA', 'stateName' => 'California'],
            ['zipcode' => '95017', 'city' => 'Davenport', 'state' => 'CA', 'stateName' => 'California'],
            ['zipcode' => '95018', 'city' => 'Felton', 'state' => 'CA', 'stateName' => 'California'],
            ['zipcode' => '95019', 'city' => 'Freedom', 'state' => 'CA', 'stateName' => 'California'],
        ];
    }
}
