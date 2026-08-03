<?php

declare(strict_types=1);

namespace SmartyAddressValidation\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1768003200BackfillAutocompleteTrackingFields extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1768003200;
    }

    public function update(Connection $connection): void
    {
        $this->backfill($connection, 'customer_address');
        $this->backfill($connection, 'order_address');
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function backfill(Connection $connection, string $table): void
    {
        $connection->executeStatement(
            sprintf(
                'UPDATE `%s`
                 SET `custom_fields` = JSON_SET(
                     JSON_SET(
                         COALESCE(`custom_fields`, JSON_OBJECT()),
                         "$.autocomplete_used_flag",
                         IF(
                             JSON_CONTAINS_PATH(`custom_fields`, "one", "$.autocomplete_used_flag"),
                             JSON_EXTRACT(`custom_fields`, "$.autocomplete_used_flag"),
                             0
                         )
                     ),
                     "$.user_changed_autocomplete_suggestion_flag",
                         IF(
                             JSON_CONTAINS_PATH(`custom_fields`, "one", "$.user_changed_autocomplete_suggestion_flag"),
                             JSON_EXTRACT(`custom_fields`, "$.user_changed_autocomplete_suggestion_flag"),
                             0
                         )
                 )
                 WHERE `custom_fields` IS NULL
                    OR NOT JSON_CONTAINS_PATH(`custom_fields`, "one", "$.autocomplete_used_flag")
                    OR NOT JSON_CONTAINS_PATH(`custom_fields`, "one", "$.user_changed_autocomplete_suggestion_flag")',
                $table
            )
        );
    }
}
