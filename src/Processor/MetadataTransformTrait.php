<?php declare(strict_types=1);

namespace BulkImport\Processor;

use Box\Spout\Common\Entity\Style\Color;
use Box\Spout\Writer\Common\Creator\Style\StyleBuilder;
use Box\Spout\Writer\Common\Creator\WriterEntityFactory;
use Laminas\Http\Client\Exception\ExceptionInterface as HttpExceptionInterface;
use Laminas\Http\ClientStatic;

/**
 * The transformation applies to all values of table "_temporary_value_id".
 *
 * @todo Convert all transformations into atomic and serializable ones.
 *
 * Require trait ConfigTrait.
 */
trait MetadataTransformTrait
{
    /**
     * @var \Doctrine\DBAL\Connection $connection
     */
    protected $connection;

    /**
     * Store all mappings during the a job, so it can be completed.
     *
     * The use case is to fill creators and contributors with the same endpoint.
     * It allows to use a manual user mapping or a previous mapping too.
     *
     * @var array
     */
    protected $valueSuggestMappings = [];

    protected $transformIndex = 0;

    protected function transformLiteralToVarious($term, array $options = []): void
    {
        if (!$this->checkTransformArguments($term, $options)) {
            return;
        }

        if (empty($options['name'])) {
            $options['name'] = str_replace(':', '-', $term);
        }

        $mapping = $this->loadTable($options['mapping']);
        $this->transformLiteralValues($term, $mapping, $options);
    }

    protected function transformLiteralToValueSuggest($term, array $options = []): void
    {
        if (empty($options['name'])) {
            $options['name'] = str_replace(':', '-', $term .'_' . ++$this->transformIndex);
        }

        if (empty($options['mapping']) || !empty($options['partial'])) {
            $this->transformLiteralToValueSuggestWithApi($term, $options);
            return;
        }

        if (!$this->checkTransformArguments($term, $options)) {
            return;
        }

        $mapping = $this->loadTable($options['mapping']) ?: [];
        $datatype = $options['datatype'] ?? null;
        foreach ($mapping as &$map) {
            $mapping['type'] = $datatype;
        }
        unset($map);

        $this->transformLiteralValues($term, $mapping, $options);
    }

    /**
     * The mapping should be already checked.
     */
    protected function transformLiteralValues($term, ?array $mapping, array $options = []): void
    {
        if (empty($mapping)) {
            $this->logger->warn(
                'The mapping "{file}" is empty.', // @translate
                ['file' => $options['mapping']]
            );
            return;
        }

        $propertyId = $this->getPropertyId($term);
        $prefix = $options['prefix'] ?? false;

        // Prepare the mapping. Cells are already trimmed strings.
        $mapper = [];
        foreach ($mapping as $map) {
            $source = $map['source'] ?? null;
            $destination = $map['destination'] ?? null;
            if (!$source || !$destination) {
                continue;
            }

            // Manage the case where a single value is mapped to multiple ones,
            // for example a value to explode into a list of languages.
            $destination = array_filter(array_map('trim', explode('|', $destination)), 'strlen');
            if (!$destination) {
                continue;
            }

            $type = empty($map['type']) ? 'literal' : $map['type'];
            $value = empty($map['label']) ? null : $map['label'];
            if ($type === 'literal' && (string) $value === '') {
                // Unchanged or empty literal value?
                $this->logger->warn(
                    'Cannot convert source "{source}" into "{destination}": a literal value cannot be empty.', // @translate
                    ['source' => $map['source'], 'destination' => $map['destination']]
                );
                continue;
            }

            // TODO Check and normalize property language.
            $lang = empty($map['lang']) ? null : $map['lang'];

            if ($prefix) {
                foreach ($destination as &$dest) {
                    if (strpos($dest, $prefix) !== 0) {
                        $dest = $prefix . $dest;
                    }
                }
            }
            unset($dest);

            foreach ($destination as $dest) {
                $mapper[] = [
                    'source' => $source,
                    'destination' => $destination,
                    'property_id' => $propertyId,
                    'type' => $type,
                    'value' => $value,
                    'uri' => $type === 'literal' ? null : $dest,
                    'lang' => $lang,
                    'value_resource_id' => null,
                    // TODO Try to keep original is_public.
                    'is_public' => 1,
                ];
            }
        }

        $this->processValuesTransform($mapper);

        // TODO Add a stat message.
    }

    protected function transformLiteralToValueSuggestWithApi($term, array $options = []): void
    {
        // The mapping is optional, so not checked.
        if (empty($options['mapping'])) {
            unset($options['mapping']);
        }
        if (!$this->checkTransformArguments($term, $options)) {
            return;
        }

        $propertyId = $this->getPropertyId($term);
        $term = $this->getPropertyTerm($propertyId);
        $datatype = $options['datatype'];

        // Get the list of unique values.
        // TODO Only literal: the already mapped values (label + uri) can be used as mapping, but useless for a new database.
        $sql = <<<'SQL'
SELECT DISTINCT
    `value`.`value` AS `v`,
    GROUP_CONCAT(DISTINCT `value`.`resource_id` ORDER BY `value`.`resource_id` SEPARATOR ' ') AS r
FROM `value`
JOIN `_temporary_value_id`
    ON `_temporary_value_id`.`id` = `value`.`id`
JOIN `item`
    ON `item`.`id` = `value`.`resource_id`
WHERE
    `value`.`type` = "literal"
    AND `value`.`property_id` = :property_id
GROUP BY `v`
ORDER BY `v`;

SQL;
        $bind = ['property_id' => $propertyId];
        $stmt = $this->connection->executeQuery($sql, $bind);
        // Fetch by key pair is not supported by doctrine 2.0.
        $list = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $list = array_column($list, 'r', 'v');

        $this->logger->info(
            'Processing {total} unique literal values for term "{term}" to map to "{datatype}".', // @translate
            ['total' => count($list), 'term' => $term, 'datatype' => $datatype]
        );
        $totalList = count($list);
        if (!$totalList) {
            return;
        }

        $this->prepareValueSuggestMapping($options);
        $currentMapping = &$this->valueSuggestMappings[$options['name']];

        $count = 0;
        $countSingle = 0;
        foreach ($list as $value => $resourceIds) {
            ++$count;
            $value = trim((string) $value);

            // In all cases, update the resource ids for future check.
            if (empty($currentMapping[$value]['items'])) {
                $ids = $resourceIds;
            } else {
                $ids = array_filter(explode(' ', $currentMapping[$value]['items'] . ' ' . $resourceIds));
                sort($ids);
                $ids = implode(' ', array_unique($ids));
            }

            // Check if the value is already mapped with one or multiple uris.
            // It may have been checked in a previous step with an empty array.
            // An empty string means a value missing in the user mapping.
            if (isset($currentMapping[$value]['uri']) && $currentMapping[$value]['uri'] !== '') {
                continue;
            }

            // Complete the new mapping.
            $currentMapping[$value]['source'] = $value;
            $currentMapping[$value]['items'] = $resourceIds;
            $currentMapping[$value]['uri'] = [];
            $currentMapping[$value]['label'] = [];
            $currentMapping[$value]['info'] = [];

            $result = $this->valueSuggestQuery($value, $datatype, $options);

            if ($result === null) {
                $this->logger->err(
                    'Connection issue: skipping next requests for property {term}.', // @translate
                    ['term' => $term]
                );
                break;
            }

            // Check if one of the value is exactly the queried value.
            // Many results may be returned but only the good one is needed.
            if (count($result) > 1) {
                foreach ($result as $r) {
                    if ($r['value'] === $value) {
                        $result = [$r];
                        break;
                    }
                }
            }

            // Store the results for future steps.
            foreach ($result as $r) {
                $currentMapping[$value]['uri'][] = $r['data']['uri'];
                $currentMapping[$value]['label'][] = $r['value'];
                $currentMapping[$value]['info'][] = $r['data']['info'];
                if (isset($r['data']['datatype'])) {
                    $currentMapping[$value]['datatype'][] = $r['data']['datatype'];
                }
            }

            if (count($result) === 1) {
                ++$countSingle;
            }

            if ($count % 100 === 0) {
                $this->logger->info(
                    '{count}/{total} unique values for term "{term}" processed, {singles} new values updated with a single uri.', // @translate
                    ['count' => $count, 'total' => $totalList, 'term' => $term, 'singles' => $countSingle]
                );
                if ($this->isErrorOrStop()) {
                    break;
                }
            }
        }

        if ($this->isErrorOrStop()) {
            return;
        }

        // Save mapped values for the current values. It allows to manage
        // multiple steps, for example to store some authors as creators, then as
        // contributors. Only single values are updated.
        $mapper = array_map(function ($v) use ($propertyId, $datatype) {
            return [
                'source' => $v['source'],
                'property_id' => $propertyId,
                'type' => $v['datatype'] ?? $datatype,
                'value' => reset($v['label']) ?: null,
                'uri' => reset($v['uri']),
                // TODO Check and normalize property language.
                'lang' => null,
                'value_resource_id' => null,
                // TODO Try to keep original is_public.
                'is_public' => 1,
            ];
        }, array_filter($currentMapping, function ($v) {
            return $v['source']
                && is_array($v['uri'])
                && count($v['uri']) === 1;
        }));
        $this->processValuesTransform($mapper);

        $this->logger->notice(
            '{count}/{total} unique values for term "{term}" processed, {singles} new values updated with a single uri.', // @translate
            ['count' => $count, 'total' => $totalList, 'term' => $term, 'singles' => $countSingle]
        );
   }

    protected function prepareValueSuggestMapping(array $options = []): void
    {
        // Create a mapping for checking and future reimport.
        $table = $this->loadTable($options['mapping']) ?: [];

        // Keep only the needed columns.
        $columns = [
            'source' => null,
            'items' => null,
            'uri' => null,
            'label' => null,
            'info' => null,
        ];
        $table = array_map(function ($v) use ($columns) {
            return array_replace($columns, array_intersect_key($v, $columns));
        }, $table);

        if (!empty($options['prefix'])) {
            $prefix = $options['prefix'];
            $table = array_map(function ($v) use ($prefix) {
                if (!empty($v['uri']) && strpos($v['uri'], $prefix) !== 0) {
                    $v['uri'] = $prefix . $v['uri'];
                }
                return $v;
            }, $table);
        }

        // Prepare the keys to search instantly in the mapping.
        $this->valueSuggestMappings[$options['name']] = array_combine(array_column($table, 'source'), $table);
    }

    protected function transformLiteralWithOperations(array $operations = []): void
    {
        // TODO Move all check inside the preprocess.
        // TODO Use a transaction (implicit currently).
        // TODO Bind is not working currently with multiple queries, but only used for property id.

        $sqls = [];
        // $bind = [];
        $excludes = [];
        foreach ($operations as $index => $operation) {
            ++$index;
            $sqlExclude = '';
            $sqlExcludeWhere = '';
            if (!empty($operation['params']['exclude'])) {
                $exclude = $this->loadList($operation['params']['exclude']);
                if ($exclude) {
                    $excludes[] = $index;
                    // To exclude is to keep only other ones.
                    $sqls[] = <<<SQL
# Prepare the list of values not to process.
DROP TABLE IF EXISTS `_temporary_value_exclude_$index`;
CREATE TABLE `_temporary_value_exclude_$index` (
    `exclude` longtext COLLATE utf8mb4_unicode_ci,
    KEY `IDX_exclude` (`exclude`(190))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
                    // The list is already filtered.
                    foreach (array_chunk($exclude, self::CHUNK_ENTITIES, true) as $chunk) {
                        $chunkString = implode('),(', array_map([$this->connection, 'quote'], $chunk));
                        $sqls[] = <<<SQL
INSERT INTO `_temporary_value_exclude_$index` (`exclude`)
VALUES($chunkString);
SQL;
                    }
                    $sqlExclude = <<<SQL
LEFT JOIN `_temporary_value_exclude_$index`
    ON `_temporary_value_exclude_$index`.`exclude` = `value`.`value`
SQL;
                    $sqlExcludeWhere = <<<SQL
    AND `_temporary_value_exclude_$index`.`exclude` IS NULL
SQL;
                }
            }

            switch ($operation['action']) {
                case 'cut':
                    if (empty($operation['params']['destination'])
                        || count($operation['params']['destination']) !== 2
                    ) {
                        $this->logger->err(
                            'The operation "cut" requires two destinations currently.' // @translate
                        );
                        break 2;
                    }
                    if (!isset($operation['params']['separator']) || !strlen($operation['params']['separator'])) {
                        $this->logger->err(
                            'The operation "cut" requires a separator.' // @translate
                        );
                        break 2;
                    }
                    // TODO Manage separators quote and double quote for security (but user should be able to access to database anyway).
                    $separator = $operation['params']['separator'];
                    $quotedSeparator = $this->connection->quote($separator);
                    if ($separator === '%') {
                        $separator = '\\%';
                    } elseif ($separator === '_') {
                        $separator = '\\_';
                    }

                    // value => bio:place : dcterms:publisher
                    $binds = [];
                    $binds['property_id_1'] = $this->getPropertyId($operation['params']['destination'][0]);
                    $binds['property_id_2'] = $this->getPropertyId($operation['params']['destination'][1]);

                    $sqls[] = <<<SQL
# Create a new trimmed value with first part.
INSERT INTO `value`
    (`resource_id`, `property_id`, `type`, `value`, `uri`, `lang`, `value_resource_id`, `is_public`)
SELECT
    `value`.`resource_id`,
    {$binds['property_id_1']},
    # Hack to keep list of all inserted ids for next operations (or create another temporary table?).
    CONCAT("operation-$index ", `value`.`type`),
    TRIM(SUBSTRING_INDEX(`value`.`value`, $quotedSeparator, 1)),
    `value`.`uri`,
    `value`.`lang`,
    `value`.`value_resource_id`,
    `value`.`is_public`
FROM `value`
JOIN `_temporary_value_id`
    ON `_temporary_value_id`.`id` = `value`.`id`
$sqlExclude
WHERE
    `value`.`value` LIKE '%$separator%'
    $sqlExcludeWhere
;
SQL;
                    $sqls[] = <<<SQL
# Update source with the trimmed second part.
UPDATE `value`
JOIN `_temporary_value_id`
    ON `_temporary_value_id`.`id` = `value`.`id`
$sqlExclude
SET
    `value`.`property_id` = {$binds['property_id_2']},
    `value`.`value` = TRIM(SUBSTRING(`value`.`value`, LOCATE($quotedSeparator, `value`.`value`) + 1))
WHERE
    value.value LIKE '%$separator%'
    $sqlExcludeWhere
;
SQL;
                    $sqls[] = <<<SQL
# Store the new value ids to manage next operations.
INSERT INTO `_temporary_value_id` (`id`)
SELECT `value`.`id`
FROM `value`
WHERE `value`.`property_id` = {$binds['property_id_1']}
    AND (`value`.`type` LIKE "operation-$index %")
ON DUPLICATE KEY UPDATE
    `_temporary_value_id`.`id` = `_temporary_value_id`.`id`
;
SQL;
                    $sqls[] = <<<SQL
# Finalize type for first part.
UPDATE `value`
SET
    `value`.`type` = SUBSTRING_INDEX(`value`.`type`, " ", -1)
WHERE `value`.`property_id` = {$binds['property_id_1']}
    AND (`value`.`type` LIKE "operation-$index %")
;
SQL;
                    break;

                default:
                    break;
            }
        }

        // Remove excludes.
        foreach ($excludes as $index) {
            $sqls[] = <<<SQL
DROP TABLE IF EXISTS `_temporary_value_exclude_$index`;
SQL;
        }

        // Transaction is implicit.
        $this->connection->executeUpdate(implode("\n", $sqls));
    }

    /**
     * @param array $mapper The mapper is already checked.
     */
    protected function processValuesTransform(array $mapper): void
    {
        // Create a temporary table with the mapper.
        $sql = <<<'SQL'
DROP TABLE IF EXISTS `_temporary_mapper`;
CREATE TABLE `_temporary_mapper` LIKE `value`;
ALTER TABLE `_temporary_mapper`
    DROP `resource_id`,
    ADD `source` longtext COLLATE utf8mb4_unicode_ci;

SQL;
        foreach (array_chunk($mapper, self::CHUNK_ENTITIES, true) as $chunk) {
            array_walk($chunk, function (&$v, $k): void {
                $v = ((int) $v['property_id'])
                    . ",'" . $v['type'] . "'"
                    . ',' . (strlen((string) $v['value']) ? $this->connection->quote($v['value']) : 'NULL')
                    . ',' . (strlen((string) $v['uri']) ? $this->connection->quote($v['uri']) : 'NULL')
                    // TODO Check and normalize property language.
                    . ',' . (strlen((string) $v['lang']) ? $this->connection->quote($v['lang']) : 'NULL')
                    . ',' . ((int) $v['value_resource_id'] ? (int) $v['value_resource_id'] : 'NULL')
                    // TODO Try to keep original is_public.
                    . ',' . (isset($v['is_public']) ? (int) $v['is_public'] : 1)
                    . ',' . $this->connection->quote($v['source'])
                ;
            });
            $chunkString = implode('),(', $chunk);
            $sql .= <<<SQL
INSERT INTO `_temporary_mapper` (`property_id`,`type`,`value`,`uri`,`lang`,`value_resource_id`,`is_public`,`source`)
VALUES($chunkString);

SQL;
        }
        $this->connection->exec($sql);

        // When there are multiple destinations for one source, the process
        // inserts new rows then removes the source one, else a simple update is
        // possible.
        $hasMultipleDestinations = false;
        foreach ($mapper as $map) {
            if (isset($map['destination'])
                && is_array($map['destination'])
                && count($map['destination']) > 1
            ) {
                $hasMultipleDestinations = true;
                break;
            }
        }
        $hasMultipleDestinations
            ? $this->processValuesTransformInsert()
            : $this->processValuesTransformUpdate();

        $sql = <<<'SQL'
DROP TABLE IF EXISTS `_temporary_mapper`;
SQL;
        $this->connection->exec($sql);
    }

    protected function processValuesTransformUpdate(): void
    {
        $sql = <<<'SQL'
UPDATE `value`
JOIN `_temporary_value_id`
    ON `_temporary_value_id`.`id` = `value`.`id`
JOIN `_temporary_mapper`
    ON `_temporary_mapper`.`property_id` = `value`.`property_id`
    AND `_temporary_mapper`.`source` = `value`.`value`
SET
    `value`.`property_id` = `_temporary_mapper`.`property_id`,
    `value`.`type` = `_temporary_mapper`.`type`,
    `value`.`value` = `_temporary_mapper`.`value`,
    `value`.`uri` = `_temporary_mapper`.`uri`,
    `value`.`value_resource_id` = `_temporary_mapper`.`value_resource_id`,
    # `value`.`is_public` = `_temporary_mapper`.`is_public`,
    `value`.`lang` = `_temporary_mapper`.`lang`;

SQL;
        $this->connection->executeUpdate($sql);
    }

    protected function processValuesTransformInsert(): void
    {
        $sql = <<<'SQL'
SELECT MAX(`id`) FROM `value`;
SQL;
        $maxId = $this->connection->query($sql)->fetchColumn();

        $sql = <<<SQL
INSERT INTO `value`
    (`resource_id`, `property_id`, `type`, `value`, `uri`, `lang`, `value_resource_id`, `is_public`)
SELECT
    `value`.`resource_id`,
    `_temporary_mapper`.`property_id`,
    `_temporary_mapper`.`type`,
    `_temporary_mapper`.`value`,
    `_temporary_mapper`.`uri`,
    `_temporary_mapper`.`lang`,
    `_temporary_mapper`.`value_resource_id`,
    `_temporary_mapper`.`is_public`
FROM `value`
JOIN `_temporary_value_id`
    ON `_temporary_value_id`.`id` = `value`.`id`
JOIN `_temporary_mapper`
    ON `_temporary_mapper`.`property_id` = `value`.`property_id`
    AND `_temporary_mapper`.`source` = `value`.`value`;

SQL;
        $this->connection->executeUpdate($sql);

        $sql = <<<'SQL'
DELETE `value`
FROM `value`
JOIN `_temporary_value_id`
    ON `_temporary_value_id`.`id` = `value`.`id`
JOIN `_temporary_mapper`
    ON `_temporary_mapper`.`property_id` = `value`.`property_id`
    AND `_temporary_mapper`.`source` = `value`.`value`
WHERE
    `value`.`id` <= :value_id;

SQL;
        $this->connection->executeUpdate($sql, ['value_id' => $maxId]);
    }

    protected function processValuesTransformSingle(
        int $propertyId,
        string $datatype,
        ?string $value = null,
        ?string $uri = null,
        ?string $valueResourceId = null,
        ?string $lang = null
    ): void {
        $sql = <<<'SQL'
UPDATE `value`
JOIN `_temporary_value_id`
    ON `_temporary_value_id`.`id` = `value`.`id`
JOIN `resource`
    ON `resource`.`id` = `value`.`resource_id`
SET
    `value`.`type` = :datatype,
    `value`.`uri` = :uri,
    `value`.`value_resource_id` = :value_resource_id,
    `value`.`lang` = :lang
WHERE
    `resource`.`resource_type` = "Omeka\\Entity\\Item"
    AND `property_id` = :property_id
    AND `value`.`type` = "literal"
    AND `value` = :value;

SQL;
        $bind = [
            'property_id' => $propertyId,
            'datatype' => $datatype,
            'value' => $value,
            'uri' => $uri,
            'value_resource_id' => $valueResourceId,
            'lang' => $lang,
        ];
        $this->connection->executeUpdate($sql, $bind);
    }

    protected function checkTransformArguments($term = null, array $options = []): bool
    {
        if ($term) {
            $propertyId = $this->bulk->getPropertyId($term);
            if (!$propertyId) {
                $this->logger->err(
                    'The property "{property}" does not exist.', // @translate
                    ['property' => $term]
                );
                return false;
            }
        }

        if (!empty($options['datatype'])) {
            $dataTypeExceptions = [
            ];
            $dataTypeManager = $this->getServiceLocator()->get('Omeka\DataTypeManager');
            if (!in_array($options['datatype'], $dataTypeExceptions)
                && !$dataTypeManager->has($options['datatype'])
            ) {
                $this->logger->err(
                    'The data type "{datatype}" does not exist.', // @translate
                    ['datatype' => $options['datatype']]
                );
                return false;
            }
        }

        if (!empty($options['mapping'])) {
            $result =  (bool) $this->hasConfigFile($options['mapping']);
            if (!$result) {
                $this->logger->err(
                    'There is no file "{filename}".', // @translate
                    ['filename' => $options['mapping']]
                );
                return false;
            }
        }

        return true;
    }

    protected function valueSuggestQuery(string $value, string $datatype, array $options = [], int $loop = 0): ?array
    {
        // An exception is done for geonames, because the username is hardcoded
        // and has few credits for all module users.
        if ($datatype === 'valuesuggest:geonames:geonames') {
            return $this->valueSuggestQueryGeonames($value, $datatype, $options);
        }

        /** @var \ValueSuggest\Suggester\SuggesterInterface $suggesters */
        static $suggesters = [];
        // static $lang;

        if (!isset($suggesters[$datatype])) {
            $suggesters[$datatype] = $this->getServiceLocator()->get('Omeka\DataTypeManager')
                ->get($datatype)
                ->getSuggester();

            // $lang = $this->getParam('language');
        }

        try {
            $suggestions = $suggesters[$datatype]->getSuggestions($value);
        } catch (HttpExceptionInterface $e) {
            // Since the exception can occur randomly, a second query is done.
            if ($loop < 1) {
                sleep(10);
                return $this->valueSuggestQuery($value, $datatype, $options, ++$loop);
            }
            // Allow to continue next processes.
            $this->logger->err(
                'Connection issue: {exception}', // @translate
                ['exception' => $e]
            );
            return null;
        }

        return is_array($suggestions)
            ? $suggestions
            : [];
    }

    /**
     * Fix for the GeonamesSuggester, that cannot manage a specific username.
     *
     * @see \ValueSuggest\Suggester\Geonames\GeonamesSuggest::getSuggestions()
     */
    protected function valueSuggestQueryGeonames(string $value, string $datatype, array $options = [], int $loop = 0): ?array
    {
        static $language2;
        static $searchType;

        if (is_null($language2)) {
            $language2 = $this->getParam('language_2') ?: '';
            $searchType = $this->getParam('geonames_search') ?: 'strict';
        }

        $originalValue = $value;
        $queryKey = 'name_equals';
        $isNameRequired = 'true';
        $startWith = $value;
        if (mb_strpos($value, '|') !== false) {
            // Manage location like "France | Paris".
            // TODO In some other cases, location are indicated "Paris, France", not "France, Paris".
            $valueList = array_filter(explode('|', $value));
            if (count($valueList) > 1) {
                $queryKey = 'name';
                $valueList = array_reverse($valueList);
                $value = implode(' ', $valueList);
                $startWith = reset($valueList);
            }
        }

        /** @see https://www.geonames.org/export/geonames-search.html */
        $query = [
            $queryKey => $value,
            // Input is already mainly checked.
            'name_startsWith' => $startWith,
            'isNameRequired' => $isNameRequired,
            'fuzzy' => 0,
            // Geographical country code (not political country: Guyane is GF).
            // 'country' => 'FR'
            // 'continentCode' => 'EU'
            'maxRows' => 20,
            'lang' => $language2,
            // TODO Use your own or a privacy aware username for geonames, not "google'.
            'username' => 'google',
        ];

        // Don't use https, or add certificate to omeka config.
        $url = 'http://api.geonames.org/searchJSON';
        $headers = [
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:60.0) Gecko/20100101 Firefox/96.0',
            'Content-Type' => 'application/json',
            'Accept-Encoding' => 'gzip, deflate',
        ];

        $e = null;
        try {
            $response = ClientStatic::get($url, $query, $headers);
        } catch (HttpExceptionInterface $e) {
            // Check below.
        }

        if (empty($response) || !$response->isSuccess()) {
            // Since the exception can occur randomly, a second query is done.
            if ($loop < 1) {
                sleep(10);
                return $this->valueSuggestQueryGeonames($originalValue, $datatype, $options, ++$loop);
            }
            // Allow to continue next processes.
            if (empty($e)) {
                $this->logger->err(
                    'Connection issue.', // @translate
                );
            } else {
                $this->logger->err(
                    'Connection issue: {exception}', // @translate
                    ['exception' => $e]
                );
            }
            return null;
        }

        $results = json_decode($response->getBody(), true);

        // Try a larger query when there is no results.
        $total = $results['totalResultsCount'];
        if (!$total) {
            if ($searchType === 'strict' || $queryKey === 'q') {
                return [];
            }
            unset($query[$queryKey]);
            $query[$queryKey === 'name_equals' ? 'name' : 'q'] = $value;
            try {
                $response = ClientStatic::get($url, $query, $headers);
            } catch (HttpExceptionInterface $e) {
                return [];
            }
            if (empty($response) || !$response->isSuccess()) {
                return [];
            }
            $results = json_decode($response->getBody(), true);
        }

        $suggestions = [];
        foreach ($results['geonames'] as $result) {
            $info = [];
            if (isset($result['fcodeName']) && $result['fcodeName']) {
                $info[] = sprintf('Feature: %s', $result['fcodeName']);
            }
            if (isset($result['countryName']) && $result['countryName']) {
                $info[] = sprintf('Country: %s', $result['countryName']);
            }
            if (isset($result['adminName1']) && $result['adminName1']) {
                $info[] = sprintf('Admin name: %s', $result['adminName1']);
            }
            if (isset($result['population']) && $result['population']) {
                $info[] = sprintf('Population: %s', number_format($result['population']));
            }
            $suggestions[] = [
                'value' => $result['name'],
                'data' => [
                    // TODO The rdf name should be "https://sws.geonames.org/%s/" ?
                    'uri' => sprintf('http://www.geonames.org/%s', $result['geonameId']),
                    'info' => implode("\n", $info),
                ],
            ];
        }

        return $suggestions;
    }

    protected function getOutputFilepath(string $filename, string $extension, bool $relative = false): string
    {
        $relativePath = 'bulk_import/' . 'import_' . $this->job->getImportId() . '_' . str_replace(':', '-', $filename) . '.' . $extension;
        if ($relative) {
            return 'files/' . $relativePath;
        }
        $basePath = $this->getServiceLocator()->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $filepath = $basePath . '/' . $relativePath;
        if (!file_exists($filepath)) {
            if (!is_dir(dirname($filepath))) {
                @mkdir(dirname($filepath, 0775, true));
            }
            touch($filepath);
        }
        return $filepath;
    }

    /**
     * The original files are not updated: the new mapping are saved inside
     * files/bulk_import/ with the job id in filename.
     *
     * OpenDocument Spreedsheet is used instead of csv/tsv because there may be
     * values with end of lines. Furthermore, it allows to merge cells when
     * there are multiple results (but box/spout doesn't manage it).
     */
    protected function saveValueSuggestMappings(): void
    {
        foreach ($this->valueSuggestMappings as $name => $mapper) {
            $this->saveValueSuggestMappingToOds($name, $mapper);
            $this->saveValueSuggestMappingToHtml($name, $mapper);
        }
    }

    protected function saveValueSuggestMappingToOds(string $name, array $mapper): void
    {
        $basePath = trim($this->job->getArg('base_path'), '/');
        $baseUrl = $this->job->getArg('base_url') . '/' . ($basePath ? $basePath . '/' : '');
        $filepath = $this->getOutputFilepath($name, 'ods');
        $relativePath = $this->getOutputFilepath($name, 'ods', true);

        // TODO Remove when patch https://github.com/omeka-s-modules/CSVImport/pull/182 will be included.
        // Manage compatibility with old version of CSV Import.
        // For now, it should be first checked.
        if (class_exists(\Box\Spout\Writer\WriterFactory::class)) {
            $spreadsheetWriter = \Box\Spout\Writer\WriterFactory::create(\Box\Spout\Common\Type::ODS);
        } elseif (class_exists(WriterEntityFactory::class)) {
            /** @var \Box\Spout\Writer\ODS\Writer $spreadsheetWriter */
            $spreadsheetWriter = WriterEntityFactory::createODSWriter();
        } else {
            $this->logger->err(
                'The library to manage OpenDocument spreadsheet is not available.' // @translate
            );
            return;
        }

        try {
            @unlink($filepath);
            $spreadsheetWriter->openToFile($filepath);
        } catch (\Box\Spout\Common\Exception\IOException $e) {
            $this->hasError = true;
            $this->logger->err(
                'File "{filename}" cannot be created.', // @translate
                ['filename' => $filepath]
            );
            return;
        }

        $spreadsheetWriter->getCurrentSheet()
            ->setName($name);

        $headers = [
            'source',
            'items',
            'uri',
            'label',
            'info',
        ];
        /** @var \Box\Spout\Common\Entity\Row $row */
        $row = WriterEntityFactory::createRowFromArray($headers, (new StyleBuilder())->setFontBold()->build());
        $spreadsheetWriter->addRow($row);

        $newStyle = (new StyleBuilder())->setBackgroundColor(Color::rgb(208, 228, 245))->build();

        $even = false;
        foreach ($mapper as $map) {
            // Skip useless data.
            if (empty($map['source'])) {
                continue;
            }

            // In the case where a user mapped value was not updated.
            if (!is_array($map['uri'])) {
                $map['uri'] = array_filter([$map['uri']]);
                $map['label'] = array_filter([$map['label']]);
                $map['info'] = array_filter([$map['info']]);
            }
            if (!count($map['uri'])) {
                $map['uri'] = [''];
                $map['label'] = [''];
                $map['info'] = [''];
            }

            $resources = '';
            foreach (array_unique(array_filter(explode(' ', $map['items']))) as $id) {
                $resources .= sprintf('%sadmin/item/%d', $baseUrl, $id) . "\n";
            }
            $resources = trim($resources);

            $data = [
                $map['source'],
                $resources,
            ];

            $dataBase = $data;
            foreach ($map['uri'] as $key => $uri) {
                $data = $dataBase;
                $data[] = $uri;
                $data[] = $map['label'][$key] ?? '';
                $data[] = $map['info'][$key] ?? '';
                $row = WriterEntityFactory::createRowFromArray($data);
                if ($even) {
                    $row->setStyle($newStyle);
                }
                $spreadsheetWriter->addRow($row);
            }

            $even = !$even;
        }

        $spreadsheetWriter->close();

        $this->logger->notice(
            'The mapping spreadsheet for "{name}" is available in "{url}".', // @translate
            [
                'name' => $name,
                'url' => $baseUrl . $relativePath,
            ]
        );
    }

    protected function saveValueSuggestMappingToHtml(string $name, array $mapper): void
    {
        $basePath = trim($this->job->getArg('base_path'), '/');
        $baseUrl = $this->job->getArg('base_url') . '/' . ($basePath ? $basePath . '/' : '');
        $filepath = $this->getOutputFilepath($name, 'html');
        $relativePath = $this->getOutputFilepath($name, 'html', true);

        $this->prepareValueSuggestMappingToHtml($filepath, 'start', $name);

        $fp = fopen($filepath, 'ab');

        foreach ($mapper as $map) {
            // Skip useless data.
            if (empty($map['source'])) {
                continue;
            }

            // In the case where a user mapped value was not updated.
            if (!is_array($map['uri'])) {
                $map['uri'] = array_filter([$map['uri']]);
                $map['label'] = array_filter([$map['label']]);
                $map['info'] = array_filter([$map['info']]);
            }
            if (!count($map['uri'])) {
                $map['uri'] = [''];
                $map['label'] = [''];
                $map['info'] = [''];
            }

            $this->appendValueSuggestMappingToHtml($fp, $map, $baseUrl);
        }

        fclose($fp);
        $this->prepareValueSuggestMappingToHtml($filepath, 'end');

        $this->logger->notice(
            'The mapping checking page for "{name}" is available in "{url}".', // @translate
            [
                'name' => $name,
                'url' => $baseUrl . $relativePath,
            ]
        );
    }

    protected function prepareValueSuggestMappingToHtml(string $filepath, ?string $part = null, ?string $name = null): void
    {
        if ($name) {
            $translate = $this->getServiceLocator()->get('ViewHelperManager')->get('translate');
            $title = htmlspecialchars(sprintf($translate('Mapping "%s"'), ucfirst($name)), ENT_NOQUOTES | ENT_HTML5);
        } else {
            $title = '';
        }

        $html = <<<HTML
<!DOCTYPE html>
<html>
    <head>
        <meta charset="utf-8" />
        <title>$title</title>
        <!-- From https://divtable.com/table-styler -->
        <style>
        table.blueTable {
            border: 1px solid #1c6ea4;
            background-color: #eeeeee;
            width: 100%;
            text-align: left;
            border-collapse: collapse;
        }
        table.blueTable td, table.blueTable th {
            border: 1px solid #aaaaaa;
            padding: 3px 2px;
        }
        table.blueTable tbody td {
            font-size: 13px;
        }
        table.blueTable tr:nth-child(even) {
            background: #d0e4f5;
        }
        table.blueTable thead {
            background: #1c6ea4;
            background: -moz-linear-gradient(top, #5592bb 0%, #327cad 66%, #1c6ea4 100%);
            background: -webkit-linear-gradient(top, #5592bb 0%, #327cad 66%, #1c6ea4 100%);
            background: linear-gradient(to bottom, #5592bb 0%, #327cad 66%, #1c6ea4 100%);
            border-bottom: 2px solid #444444;
        }
        table.blueTable thead th {
            font-size: 15px;
            font-weight: bold;
            color: #ffffff;
            border-left: 2px solid #d0e4f5;
        }
        table.blueTable thead th:first-child {
            border-left: none;
        }
        </style>
    </head>
    <body>
        <h1>$title</h1>
        <table class="blueTable">
            <thead>
                <tr>
                    <th scope="col">source</th>
                    <th scope="col">items</th>
                    <th scope="col">uri</th>
                    <th scope="col">label</th>
                    <th scope="col">info</th>
                </tr>
            </thead>
            <tbody>
            </tbody>
        </table>
    </body>
</html>

HTML;
        if ($part === 'continue') {
            $fp = fopen($filepath, 'ab');
            ftruncate($fp, filesize($filepath) - 58);
            fclose($fp);
            return;
        }

        if ($part === 'end') {
            $html = mb_substr($html, -58);
            $fp = fopen($filepath, 'ab');
            fwrite($fp, $html);
            fclose($fp);
            return;
        }

        if ($part === 'start') {
            $html = mb_substr($html, 0, mb_strlen($html) - 58);
        }

        file_put_contents($filepath, $html);
    }

    protected function appendValueSuggestMappingToHtml($fp, array $map, string $baseUrl): void
    {
        $count = count($map['uri']);
        $rowspan = $count <= 1 ? '' : sprintf(' rowspan="%d"', $count);

        $resources = '';
        foreach (array_unique(array_filter(explode(' ', $map['items']))) as $id) {
            $resources .= sprintf(
                '<a href="%sadmin/item/%d" target="_blank">#%d</a><br/>',
                $baseUrl, $id, $id
            ) . "\n";
        }

        $html = "                <tr>\n";
        $html .= sprintf('                    <td scope="row"%s>%s</td>', $rowspan, htmlspecialchars($map['source'], ENT_NOQUOTES | ENT_HTML5)) . "\n";
        $html .= sprintf('                    <td%s>%s</td>', $rowspan, $resources) . "\n";
        if (!reset($map['uri'])) {
            $html .= str_repeat('                    <td></td>' . "\n", 3);
            $html .= "                </tr>\n";
        } else {
            $first = true;
            foreach ($map['uri'] as $key => $uri) {
                if ($first) {
                    $first = false;
                } else {
                    $html .= "                <tr>\n";
                }
                $code = (string) basename(rtrim($uri, '/'));
                $label = $map['label'][$key] ?? '';
                $info = $map['info'][$key] ?? '';
                $html .= sprintf('                    <td><a href="%s" target="_blank">%s</a></td>', htmlspecialchars($uri, ENT_NOQUOTES | ENT_HTML5), htmlspecialchars($code, ENT_NOQUOTES | ENT_HTML5)) . "\n";
                $html .= sprintf('                    <td>%s</td>', htmlspecialchars($label, ENT_NOQUOTES | ENT_HTML5)) . "\n";
                $html .= sprintf('                    <td>%s</td>', htmlspecialchars($info, ENT_NOQUOTES | ENT_HTML5)) . "\n";
                $html .= "                </tr>\n";
            }
        }

        fwrite($fp, $html);
    }
}
