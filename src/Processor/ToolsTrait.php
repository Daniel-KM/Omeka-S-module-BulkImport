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
     * @param bool $isAlreadyFilled By default, the map will be filled, but when
     *   ids are already prepared, the only need is to create entities directly.
     * @param bool $useMainTable When a resource is a derived resource
     *   ("items"), use the main table ("resource").
     * @param bool $keysAreStrings In some cases, id keys are coded strings.
     */
    protected function createEmptyEntities(
        string $sourceType,
        array $escapedDefaultValues,
        bool $isAlreadyFilled = false,
        bool $useMainTable = false,
        bool $keysAreStrings = false
    ): void {
        if (empty($sourceType) || empty($escapedDefaultValues)) {
            $this->logger->warn(
                'No source or no default values: they are needed to create empty entities.' // @translate
            );
            return;
        }

        if (!isset($this->map[$sourceType])
            || !is_array($this->map[$sourceType])
            || !count($this->map[$sourceType])
        ) {
            $this->logger->warn(
                'No ids mapped to create "{source}".', // @translate
                ['source' => $sourceType]
            );
            return;
        }

        $firstKey = key($this->map[$sourceType]);
        if (!is_numeric($firstKey) && !$keysAreStrings) {
            $this->hasError = true;
            $this->logger->err(
                'Ids are not numeric for "{source}".', // @translate
                ['source' => $sourceType]
            );
            return;
        }

        if ($useMainTable) {
            $mainSourceType = $this->importables[$sourceType]['main_entity'] ?? null;
            if (!$mainSourceType || empty($this->importables[$mainSourceType]['column_keep_id'])) {
                $this->hasError = true;
                $this->logger->err(
                    'The main source type is not fully defined for "{source}".', // @translate
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
                'No table for source "{source}".', // @translate
                ['source' => $sourceType]
            );
            return;
        }

        $noConflict = $keysAreStrings
            || $this->checkConflictSourceIds($sourceType, $isAlreadyFilled, $useMainTable);
        if (!$noConflict) {
            if ($isAlreadyFilled) {
                $this->hasError = true;
                $this->logger->err(
                    'There is a conflict in the unique id for source "{source}".', // @translate
                    ['source' => $sourceType]
                );
                return;
            }
        }
        if (!$noConflict || $keysAreStrings) {
            unset($escapedDefaultValues['id']);
            if (empty($escapedDefaultValues)) {
                $this->hasError = true;
                $this->logger->err(
                    'No empty entity for source "{source}" can be created: no column.', // @translate
                    ['source' => $sourceType]
                );
                return;
            }
        }

        // Add a random prefix to get the mapping of ids, in all cases.
        $randomPrefix = $this->job->getImportId() . '-' . $this->randomString(6) . ':';
        $randomPrefixLength = strlen($randomPrefix) + 1;
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
        $idType = $keysAreStrings
            ? 'VARCHAR(1024) COLLATE `latin1_bin`'
            : 'INT unsigned';
        $sqls = <<<SQL
DROP TABLE IF EXISTS `_temporary_source_entities`;
CREATE TABLE `_temporary_source_entities` (
    `id` $idType NOT NULL,
    UNIQUE (`id`)
);

SQL;

        // TODO Find a way to prepare the ids without the map, but original source.
        if ($isAlreadyFilled) {
            $ids = is_array(reset($this->map[$sourceType]))
                ? array_map('intval', array_column($this->map[$sourceType], 'id'))
                : $this->map[$sourceType];
        } else {
            $ids = array_keys($this->map[$sourceType]);
        }
        if ($keysAreStrings) {
            foreach (array_chunk($ids, self::CHUNK_SIMPLE_RECORDS) as $chunk) {
                $chunk = array_map([$this->connection, 'quote'], $chunk);
                $sqls .= 'INSERT INTO `_temporary_source_entities` (`id`) VALUES(' . implode('),(', $chunk) . ");\n";
            }
        } else {
            foreach (array_chunk($ids, self::CHUNK_RECORD_IDS) as $chunk) {
                $chunk = array_map('intval', $chunk);
                $sqls .= 'INSERT INTO `_temporary_source_entities` (`id`) VALUES(' . implode('),(', $chunk) . ");\n";
            }
        }

        $sqls .= <<<SQL
INSERT INTO `$table`
    ($columnsString)
SELECT
    $valuesString
FROM `_temporary_source_entities`;

SQL;

        // Don't need to fill the ids when they are filled.
        if ($isAlreadyFilled) {
            $sqls .= <<<SQL
DROP TABLE IF EXISTS `_temporary_source_entities`;

SQL;
            $this->connection->executeStatement($sqls);
            return;
        }
        $this->connection->executeStatement($sqls);

        // Get the mapping of source and destination ids.
        $sql = <<<SQL
SELECT
    SUBSTR(`$table`.`$columnKeepId`, $randomPrefixLength) AS `s`,
    `$table`.`id` AS `d`
FROM `$table` AS `$table`
JOIN `_temporary_source_entities` AS `tempo` ON CONCAT("$randomPrefix", `tempo`.`id`) = `$table`.`$columnKeepId`;

SQL;

        $result = $this->connection->executeQuery($sql)->fetchAllKeyValue();
        if (!count($result)) {
            $this->hasError = true;
            $this->logger->err(
                'No entities were created for source "{source}".', // @translate
                ['source' => $sourceType]
            );
        } elseif (count($result) !== count($this->map[$sourceType])) {
            $this->hasError = true;
            $this->logger->warn(
                'Some entities were not created for source "{source}".', // @translate
                ['source' => $sourceType]
            );
        }

        // Numeric keys are automatically converted into integers, not values.
        $this->map[$sourceType] = $result ? array_map('intval', $result) : [];

        $sql = <<<SQL
DROP TABLE IF EXISTS `_temporary_source_entities`;

SQL;
        $this->connection->executeStatement($sql);
    }

    /**
     * Check if ids can be kept during import.
     *
     * @param bool $useMainTable When a resource is a derived resource
     *   ("items"), use the main table ("resource").
     * @return bool True if there is no conflict, else false.
     */
    protected function checkConflictSourceIds(string $sourceType, bool $isAlreadyFilled = false, bool $useMainTable = false): bool
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
                'No table for source "{source}".', // @translate
                ['source' => $sourceType]
            );
            return false;
        }

        $entityIds = $this->connection
            ->query("SELECT `id` FROM `$table` ORDER BY `id`;")
            ->fetchFirstColumn();
        $entityIds = array_map('intval', $entityIds);

        if ($isAlreadyFilled) {
            $ids = is_array(reset($this->map[$sourceType]))
                ? array_map('intval', array_column($this->map[$sourceType], 'id'))
                : $this->map[$sourceType];
            return empty(array_intersect($ids, $entityIds));
        }

        // No need to compute the list of all ids of the different entities:
        // it will be done for each mapped ids.
        return empty(array_intersect_key($this->map[$sourceType], array_flip($entityIds)));
    }

    protected function sqlCheckReadDirectly(?string $sourceType): bool
    {
        $table = null;
        if ($sourceType) {
            $table = $this->mapping[$sourceType]['source'] ?? null;
        }

        if (!$this->reader->canReadDirectly($table)) {
            $dbConfig = $this->reader->getDbConfig();
            if ($sourceType) {
                $this->logger->warn(
                    'To import "{source}", the Omeka database user should be able to read the source database directly, so run this query or a similar one with a database admin user: "{sql}".',  // @translate
                    ['source' => $sourceType, 'sql' => sprintf("GRANT SELECT ON `%s`.* TO '%s'@'%s';", $dbConfig['database'], $dbConfig['username'], $dbConfig['hostname'])]
                );
            } else {
                $this->logger->warn(
                    'The Omeka database user should be able to read the source database directly, so run this query or a similar one with a database admin user: "{sql}".',  // @translate
                    ['sql' => sprintf("GRANT SELECT ON `%s`.* TO '%s'@'%s';", $dbConfig['database'], $dbConfig['username'], $dbConfig['hostname'])]
                );
            }
            $this->logger->err(
                'In some cases, the grants should be given to the omeka database user too.'  // @translate
            );
            return false;
        }

        return true;
    }

    protected function sqlTemporaryTableForIdsCreate(string $sourceType, string $entityName): string
    {
        $sqls = <<<SQL
# Create a temporary table to store the mapping between original ids and new ids for "$sourceType" ($entityName).
DROP TABLE IF EXISTS `_temporary__$entityName`;
CREATE TABLE `_temporary__$entityName` (
    `from` INT(11) NOT NULL,
    `to` INT(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SQL;
        if (empty($this->map[$sourceType])) {
            return $sqls;
        }

        $filtered = array_filter($this->map[$sourceType]);
        $isArray = is_array(reset($filtered));
        foreach (array_chunk($filtered, self::CHUNK_RECORD_IDS, true) as $chunk) {
            $values = [];
            if ($isArray) {
                foreach ($chunk as $from => $to) {
                    $values[] = "$from,{$to['id']}";
                }
            } else {
                foreach ($chunk as $from => $to) {
                    $values[] = "$from,$to";
                }
            }
            $sqls .= "INSERT INTO `_temporary__$entityName` (`from`, `to`) VALUES(" . implode('),(', $values) . ");\n";
        }
        return $sqls;
    }

    protected function sqlTemporaryTableForIdsJoin(string $sourceType, string $sourceKey, string $entityName, ?string $joinType = null): string
    {
        $dbConfig = $this->reader->getDbConfig();
        $sourceDatabase = $dbConfig['database'];
        $destinationDatabase = $this->connection->getDatabase();
        $sourceTable = $this->mapping[$sourceType]['source'] ?? null;
        $joinType = strtolower((string) $joinType) === 'left' ? 'LEFT ' : '';

        return <<<SQL
{$joinType}JOIN `$destinationDatabase`.`_temporary__$entityName`
    ON `$sourceDatabase`.`$sourceTable`.`$sourceKey` = `$destinationDatabase`.`_temporary__$entityName`.`from`

SQL;
    }

    protected function sqlTemporaryTableForIdsDrop(string $entityName): string
    {
        return "DROP TABLE IF EXISTS `_temporary__$entityName`;\n";
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

    protected function asciiArrayToString(array $array): string
    {
        // The table of identifiers should be pure ascii to allow indexation.
        // When possible, keep original data to simplify debugging.
        $separator = ' | ';
        $string = implode($separator, $array);
        $sha1 = sha1($string);
        $string = mb_strtolower($string);
        if (extension_loaded('intl')) {
            $transliterator = \Transliterator::createFromRules(':: NFD; :: [:Nonspacing Mark:] Remove; :: NFC;');
            $string = $transliterator->transliterate($string);
        } elseif (extension_loaded('iconv')) {
            $string = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $string);
        } else {
            return $sha1;
        }
        return trim($string, $separator) . $separator . $sha1;
    }
}
