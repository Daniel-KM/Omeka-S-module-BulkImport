<?php declare(strict_types=1);

namespace BulkImport\Processor;

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
     * @param bool $useMainTable When a resource is a derived resource
     *   ("items"), use the main table ("resource").
     * @return void The mapping of source and destination ids is stored in the
     *   main map when no ids is provided.
     */
    protected function createEmptyEntities(string $sourceType, array $escapedDefaultValues, ?array $ids = null, bool $useMainTable = false): void
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

        if ($useMainTable) {
            $mainSourceType = $this->importables[$sourceType]['main_entity'] ?? null;
            if (!$mainSourceType || empty($this->importables[$mainSourceType]['column_keep_id'])) {
                $this->hasError = true;
                $this->logger->err(
                    'The main source type is not fully defined for {source}.', // @translate
                    ['source' => $sourceType]
                );
                return;
            }
            $table = $this->importables[$mainSourceType]['table'] ?? null;
        } else {
            $table = $this->importables[$sourceType]['table'] ?? null;
        }
        if (!$table) {
            $this->hasError = true;
            $this->logger->err(
                'No table for source {source}.', // @translate
                ['source' => $sourceType]
            );
            return;
        }

        $noConflict = $this->checkConflictSourceIds($sourceType, $ids, $useMainTable);
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
            if (empty($escapedDefaultValues)) {
                $this->hasError = true;
                $this->logger->err(
                    'No empty entity for source {source} can be created: no column.', // @translate
                    ['source' => $sourceType]
                );
                return;
            }
        }

        // Add a random prefix to get the mapping of ids, in all cases.
        $randomPrefix = $this->job->getImportId() . '-' . $this->randomString(6) . ':';
        $columnKeepId = $useMainTable
            ? $this->importables[$mainSourceType]['column_keep_id']
            : $this->importables[$sourceType]['column_keep_id'];
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

        // TODO Find a way to prepare the ids without the map, but original source.
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

        // Don't need to fill the ids when there are ids and no conflict.
        if ($noConflict && $ids) {
            $sqls .= <<<SQL
DROP TABLE IF EXISTS `_temporary_source_entities`;

SQL;
        }

        $this->connection->executeQuery($sqls);

        // Don't need to fill the ids when there are ids and no conflict.
        if ($noConflict && $ids) {
            return;
        }

        $randomPrefixLength = strlen($randomPrefix) + 1;

        // Get the mapping of source and destination ids.
        $sql = <<<SQL
SELECT
    SUBSTR(`$table`.`$columnKeepId`, $randomPrefixLength) AS `s`,
    `$table`.`id` AS `d`
FROM `$table` AS `$table`
JOIN `_temporary_source_entities` AS `tempo` ON CONCAT("$randomPrefix", `tempo`.`id`) = `$table`.`$columnKeepId`;

SQL;

        $result = $this->connection->executeQuery($sql)->fetchAllKeyValue();
        // Numeric keys are automatically converted into integers, not values.
        $this->map[$sourceType] = $result ? array_map('intval', $result) : [];

    $sql = <<<SQL
DROP TABLE IF EXISTS `_temporary_source_entities`;

SQL;
        $this->connection->executeQuery($sql);
    }

    /**
     * Check if ids can be kept during import.
     *
     * @param bool $useMainTable When a resource is a derived resource
     *   ("items"), use the main table ("resource").
     * @return bool True if there is no conflict, else false.
     */
    protected function checkConflictSourceIds(string $sourceType, ?array $ids = null, bool $useMainTable = false): bool
    {
        // When there is a main table, check it.
        if ($useMainTable) {
            $mainSourceType = $this->importables[$sourceType]['main_entity'] ?? null;
            $table = $this->importables[$mainSourceType]['table'] ?? null;
        } else {
            $table = $this->importables[$sourceType]['table'] ?? null;
        }
        if (!$table) {
            $this->hasError = true;
            $this->logger->err(
                'No table for source {source}.', // @translate
                ['source' => $sourceType]
            );
            return false;
        }

        $entityIds = $this->connection
            ->query("SELECT `id` FROM `$table` ORDER BY `id`;")
            ->fetchAll(\PDO::FETCH_COLUMN);

        if (!is_null($ids)) {
            return empty(array_intersect($ids, $entityIds));
        }

        // No need to compute the list of all ids of the different entities:
        // it will be done for each mapped ids.
        return empty(array_intersect_key($this->map[$sourceType], array_flip($entityIds)));
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

    protected function randomString(int $length = 1): string
    {
        $length = max(1, $length);
        return substr(str_replace(['+', '/', '='], ['', '', ''], base64_encode(random_bytes(16 * $length))), 0, $length);
    }
}
