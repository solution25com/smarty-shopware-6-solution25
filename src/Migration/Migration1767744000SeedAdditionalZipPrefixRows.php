<?php

declare(strict_types=1);

namespace SmartyAddressValidation\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1767744000SeedAdditionalZipPrefixRows extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1767744000;
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
            ['zipcode' => '95110', 'city' => 'San Jose', 'state' => 'CA', 'stateName' => 'California'],
            ['zipcode' => '95111', 'city' => 'San Jose', 'state' => 'CA', 'stateName' => 'California'],
            ['zipcode' => '95112', 'city' => 'San Jose', 'state' => 'CA', 'stateName' => 'California'],
            ['zipcode' => '95113', 'city' => 'San Jose', 'state' => 'CA', 'stateName' => 'California'],
            ['zipcode' => '95116', 'city' => 'San Jose', 'state' => 'CA', 'stateName' => 'California'],
            ['zipcode' => '95117', 'city' => 'San Jose', 'state' => 'CA', 'stateName' => 'California'],
            ['zipcode' => '95118', 'city' => 'San Jose', 'state' => 'CA', 'stateName' => 'California'],
            ['zipcode' => '95119', 'city' => 'San Jose', 'state' => 'CA', 'stateName' => 'California'],
            ['zipcode' => '95120', 'city' => 'San Jose', 'state' => 'CA', 'stateName' => 'California'],
            ['zipcode' => '95121', 'city' => 'San Jose', 'state' => 'CA', 'stateName' => 'California'],
        ];
    }
}
