<?php declare(strict_types=1);

namespace BulkImport\Processor;

use Log\Stdlib\PsrMessage;
use Omeka\Entity\Resource;

trait ToolsTrait
{
    /**
     * Create empty entities from source or a list of ids to keep original ids.
     *
     * The main map is filled with the source ids and the new ids.
     *
     * @param string $sourceType
     * @param array $escapedDefaultValues When there is a conflict, there should
     *   be a way to find the created entities, for example including the id as
     *   title, with or without a prefix. It should be overridden in a next step
     *   with the real values. Strings should be quoted, in particular empty
     *   one, null set as "NULL", etc.
     * @param array|null $ids When null, get the ids from the source. Else it is
     *   recommended that the unicity ids to already checked, else new ids will
     *   be created.
     * @return void The mapping of source and destination ids is stored in the
     *   main map when no ids is provided.
     */
    protected function createEmptyEntities(string $sourceType, array $escapedDefaultValues, ?array $ids = null): void
    {
        if (empty($sourceType) || empty($escapedDefaultValues)) {
            $this->logger->warn(
                'No source or no default values: they are needed to create empty entities.' // @translate
            );
            return;
        }

        if (is_array($ids) && !count($ids)) {
            $this->logger->warn(
                'No ids set to create {source}.', // @translate
                ['source' => $sourceType]
            );
            return;
        }

        $table = $this->importables[$sourceType]['table'] ?? null;
        if (!$table) {
            $this->hasError = true;
            $this->logger->err(
                'No table for source {source}.', // @translate
                ['source' => $sourceType]
            );
            return;
        }

        $noConflict = $this->checkConflictSourceIds($sourceType, $ids);
        if (!$noConflict) {
            if ($ids) {
                $this->hasError = true;
                $this->logger->err(
                    'Ids are specified for {source}, but they are not unique.', // @translate
                    ['source' => $sourceType]
                );
                return;
            }
            unset($escapedDefaultValues['id']);
        }

        // Add a random prefix to get the mapping of ids, in all cases.
        $randomPrefix = $this->job->getImportId() . '-'
            . substr(base64_encode(random_bytes(128)), 0, 5) . ':';
        $columnKeepId = $this->importables[$sourceType]['column_keep_id'];
        $escapedDefaultValues[$columnKeepId] = "CONCAT('$randomPrefix', id)";

        $columnsString = '`' . implode('`, `', array_keys($escapedDefaultValues)) . '`';
        $valuesString = implode(', ', $escapedDefaultValues);

        // For compatibility with old databases, a temporary table is used in
        // order to create a generator of enough consecutive rows.
        // Don't use "primary key", but "unique key" in order to allow the key
        // "0" as key, used in some cases (the scheme of the thesaurus).
        // The temporary table is not used any more in order to be able to check
        // quickly if source ids are all available for main items.
        $sqls = <<<SQL
DROP TABLE IF EXISTS `_temporary_source_entities`;
CREATE TABLE `_temporary_source_entities` (
    `id` INT unsigned NOT NULL,
    UNIQUE (`id`)
);

SQL;

        foreach (array_chunk($ids ?: array_keys($this->map[$sourceType]), self::CHUNK_RECORD_IDS) as $chunk) {
            $sqls .= 'INSERT INTO `_temporary_source_entities` (`id`) VALUES(' . implode('),(', $chunk) . ");\n";
        }

        $sqls .= <<<SQL
INSERT INTO `$table`
    ($columnsString)
SELECT
    $valuesString
FROM `_temporary_source_entities`;

SQL;

        // Fill the ids when no ids of conflict.
        if ($noConflict && $ids) {
            $sqls .= <<<SQL
DROP TABLE IF EXISTS `_temporary_source_entities`;

SQL;
        }

        $this->connection->executeQuery($sqls);

        // Fill the ids when no ids of conflict.
        if ($noConflict && $ids) {
            return;
        }

        $randomPrefixLength = strlen($randomPrefix) + 1;

        // Get the mapping of source and destination ids.
        $sql = <<<SQL
SELECT
    SUBSTR(`$table`.`$columnKeepId`, $randomPrefixLength) AS `s`;
    `$table`.`id` AS `d`,
FROM `$table` AS `$table`
JOIN `_temporary_source_entities` AS `tempo` ON CONCAT("$randomPrefix", `tempo`.`id`) = `$table`.`$columnKeepId`;

SQL;

        $result = $this->connection->executeQuery($sql)->fetchAllKeyValue();
        // Numeric keys are automatically converted into integers, not values.
        $this->map[$sourceType] = $result ? array_map('intval', $result) : [];

    $sqls = <<<SQL
DROP TABLE IF EXISTS `_temporary_source_entities`;

SQL;
        $this->connection->executeQuery($sql);
    }

    protected function checkConflictSourceIds(string $sourceType, ?array $ids = null): bool
    {
        $table = $this->importables[$sourceType]['table'] ?? null;
        if (!$table) {
            return true;
        }

        $entityIds = $this->connection
            ->query("SELECT `id` FROM `$table` ORDER BY `id`;")
            ->fetchAll(\PDO::FETCH_COLUMN);

        return is_null($ids)
            ? empty(array_intersect_key($this->map[$sourceType], array_flip($entityIds)))
            : empty(array_intersect($ids, $entityIds));
    }


    /**
     * Update created dates and, if any, modified dates. No check is done.
     *
     * Needed in particular for users and jobs, because there are a pre-persist
     * and pre-update events in entities, so it's not possible to keep original
     * created and modified date.
     *
     * @param string $sourceType
     * @param string $columnId
     * @param array $dates
     */
    protected function updateDates(string $sourceType, string $columnId, array $dates): void
    {
        if (!count($dates)) {
            return;
        }

        $table = $this->importables[$sourceType]['table'];

        $sql = '';

        $first = reset($dates);
        if (count($first) === 2) {
            foreach ($dates as $date) {
                [$id, $created] = $date;
                $sql .= "UPDATE `$table` SET `created` = '$created', WHERE `$columnId` = '$id';\n";
            }
        } elseif (count($first) === 3) {
            foreach ($dates as $date) {
                [$id, $created, $modified] = $date;
                $modified = $modified ? "'" . $modified . "'" : 'NULL';
                $sql .= "UPDATE `$table` SET `created` = '$created', `modified` = $modified WHERE `$columnId` = '$id';\n";
            }
        } else {
            return;
        }

        $this->connection->executeStatement($sql);
    }
}
