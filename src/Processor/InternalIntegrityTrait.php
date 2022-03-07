<?php declare(strict_types=1);

namespace BulkImport\Processor;

trait InternalIntegrityTrait
{
    protected function checkAssets(): void
    {
        // Check if there are empty data, for example from an incomplete import.
        $sql = <<<SQL
SELECT `id`
FROM `asset`
WHERE `asset`.`name` = ""
    AND `asset`.`media_type` = ""
    AND `asset`.`extension` = ""
    AND `asset`.`owner_id` IS NULL;
SQL;
        $result = $this->connection->executeQuery($sql)->fetchFirstColumn();
        if (count($result)) {
            $this->hasError = true;
            $this->logger->err(
                '{total} empty {type} are present in the database. Here are the first ones: {first}. Use module BulkCheck to check and fix them.', // @translate
                ['total' => count($result), 'type' => 'assets', 'first' => implode(', ', array_slice($result, 0, 20))]
            );
        }
    }

    protected function checkResources(): void
    {
        $resourceTables = [
            'item' => \Omeka\Entity\Item::class,
            'item_set' => \Omeka\Entity\ItemSet::class,
            'media' => \Omeka\Entity\Media::class,
        ];

        // Check if there are missing specific id.
        foreach ($resourceTables as $resourceTable => $class) {
            $resourceClass = $this->connection->quote($class);
            $sql = <<<SQL
SELECT `resource`.`id`
FROM `resource` AS `resource`
LEFT JOIN `$resourceTable` AS `spec` ON `spec`.`id` = `resource`.`id`
WHERE `resource`.`resource_type` = $resourceClass
    AND `spec`.`id` IS NULL;
SQL;
            $result = $this->connection->executeQuery($sql)->fetchFirstColumn();
            if (count($result)) {
                $this->hasError = true;
                $this->logger->err(
                    '{total} resources are present in the table "resource", but missing in the table "{type}" of the database. Here are the first ones: {first}. Use module BulkCheck to check and fix them.', // @translate
                    ['total' => count($result), 'type' => $resourceTable, 'first' => implode(', ', array_slice($result, 0, 20))]
                );
            }
        }
    }
}
