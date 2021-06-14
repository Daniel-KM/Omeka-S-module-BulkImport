<?php declare(strict_types=1);

namespace BulkImport\Processor;

use Laminas\Http\Client\Adapter\Exception as HttpAdapterException;

/**
 * The transformation applies to all values of table "_temporary_value_id".
 */
trait MetadataTransformTrait
{
    protected function transformLiteralToValueSuggest($term, string $datatype, array $options = []): void
    {
        empty($options['mapping']) || !empty($options['partial'])
            ? $this->transformLiteralToValueSuggestWithApi($term, $datatype, $options)
            : $this->transformLiteralToValueSuggestWithMapping($term, $datatype, $options);
    }

    protected function transformLiteralToValueSuggestWithMapping($term, string $datatype, array $options = []): void
    {
        if (!$this->checkTransformArguments($term, $datatype)) {
            return;
        }

        $propertyId = $this->getPropertyId($term);
        $prefix = $options['prefix'] ?? null;
        $mapping = $this->getTableFromFile($options['mapping']);
        if (empty($mapping)) {
            $this->logger->warn(
                'The mapping "file" is empty.', // @translate
                ['file' => $options['mapping']]
            );
            return;
        }

        // Prepare the mapping. Cells are already trimmed strings.
        $mapper = [];
        foreach ($mapping as $map) {
            // Mysql is case insensitive, but not php array.
            $map = array_change_key_case($map);
            $source = $map['source'] ?? null;
            $destination = $map['destination'] ?? null;
            if (!$source || !$destination) {
                continue;
            }

            $destination = array_filter(array_map('trim', explode('|', $destination)), 'strlen');
            if (!$destination) {
                continue;
            }

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
                    'type' => $datatype,
                    'value' => empty($map['label']) ? null : $map['label'],
                    'uri' => $dest,
                    // TODO Check and normalize property language.
                    'lang' => empty($map['lang']) ? null : $map['lang'],
                    'value_resource_id' => null,
                    'is_public' => 1,
                ];
            }
        }

        $this->transformValuesProcess($mapper);
    }

    protected function transformLiteralToValueSuggestWithApi($term, string $datatype, array $options = []): void
    {
        if (!$this->checkTransformArguments($term, $datatype)) {
            return;
        }

        $propertyId = $this->getPropertyId($term);
        $term = $this->getPropertyTerm($propertyId);

        // Get the list of unique values.
        $sql = <<<'SQL'
SELECT DISTINCT
    `value`.`value` AS `v`,
    GROUP_CONCAT(DISTINCT `value`.`resource_id` ORDER BY `value`.`resource_id` SEPARATOR ',') AS r
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
            'There are {total} literal values for term "{term}".', // @translate
            ['total' => count($list), 'term' => $term]
        );
        if (!count($list)) {
            return;
        }

        // The original file is not updated: the new mapping is saved in files/.
        $config = $this->getServiceLocator()->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $filepath = $basePath . '/bulk_import/' . 'import_' . $this->job->getJobId() . '_' . ($options['filename'] ?? str_replace(':', '-', $term)) . '.tsv';
        $filepathHtml = $basePath . '/bulk_import/' . 'import_' . ($options['filename'] ?? str_replace(':', '-', $term)) . '.html';
        if (!file_exists($filepath)) {
            if (!is_dir(dirname($filepath))) {
                @mkdir(dirname($filepath, 0775, true));
            }
            touch($filepath);
            $this->logger->notice(
                'Mapping for {term} will be saved in "{url}".', // @translate
                ['term' => $term, 'url' => $this->job->getArg('base_path') . '/files/' . mb_substr($filepath, strlen($basePath) + 1)]
            );
            $headers = [
                'label',
                'ids',
                'uri',
                // 'label_1',
                // 'info_1',
                // 'uri_2',
                // 'label_2',
                // 'info_2',
            ];
            $fp = fopen($filepath, 'wb');
            fputcsv($fp, $headers, "\t", '"', '\\');
            fclose($fp);
        }

        // Create a mapping for checking and future reimport.
        $mapping = $this->getTableFromFile($options['mapping']) ?: [];
        if (!empty($mapping)) {
            $this->logger->info(
                'The mapping "{file}" for "{term}" contains "{total}" rows.', // @translate
                ['file' => $options['mapping'], 'term' => $term, count($mapping)]
            );
            // Prepare the keys to search instantly in the mapping.
            $mapping = array_combine(array_column($mapping, 'label'), $mapping);
        }

        // Append the mapping from the file filled in a previous step.
        if (filesize($filepath)) {
            $previousMapping = $this->getTableFromFile($filepath) ?: [];
            // Keep only needed keys and fill them all (for the mapping check).
            // $previousMapping = array_map(function ($v) {
            //     return array_intersect_key($v + ['label' => '', 'id' => '', 'uri' => ''], ['label' => '', 'id' => '', 'uri' => '']);
            // }, $previousMapping);
            $previousMapping = array_combine(array_column($previousMapping, 'label'), $previousMapping);
            $mapping = array_merge($mapping, $previousMapping);
            unset($previousMapping);
        }

        // Prepare the html output.
        if (!file_exists($filepathHtml) || !filesize($filepathHtml)) {
            $this->prepareCheckingFile($filepathHtml, 'start', $term);
        } else {
            $this->prepareCheckingFile($filepathHtml, 'continue', $term);
        }

        // Fill the html mapping (append).
        $fpHtml = fopen($filepathHtml, 'ab');

        // Fill the tsv mapping (append).
        $count = 0;
        $countSingle = 0;
        $total = count($list);
        $fp = fopen($filepath, 'ab');
        foreach ($list as $value => $resourceIds) {
            ++$count;
            $value = trim($value);
            if (!isset($mapping[$value]) || empty($mapping[$value]['uri'])) {
                $mapping[$value]['label'] = $value;
                $mapping[$value]['ids'] = $resourceIds;

                $result = $this->apiQuery($value, $datatype, $options);
                if ($result === null) {
                    $this->logger->err(
                        'Connection issue: skipping next requests for property {term}.', // @translate
                        ['term' => $term]
                    );
                    break;
                }

                $isSingleResult = count($result) === 1;
                foreach ($result as $key => $r) {
                    $k = $key + 1;
                    $mapping[$value]['uri' . ($key ? '_' . $k : '')] = $r['data']['uri'];
                    $mapping[$value]['label_' . $k] = $r['value'];
                    $mapping[$value]['info_' . $k] = is_array($r['data']['info']) ? json_encode($r['data']['info'], 320) : $r['data']['info'];
                }
            } else {
                $mapping[$value]['ids'] = $resourceIds;
            }

            // Save the mapping directly (but keep the original label).
            fputcsv($fp, $mapping[$value], "\t", '"', '\\');
            $this->appendCheckingFile($fpHtml, $value, $resourceIds, $result);

            if ($isSingleResult) {
                ++$countSingle;
                $this->transformValuesSingle(
                    $propertyId,
                    $datatype,
                    $value,
                    $mapping[$value]['uri'],
                    null,
                    null
                );
            }

            if ($count % 100 === 0) {
                $this->logger->info(
                    '{count}/{total} values for term "{term}" processed, {singles} new values updated with a single uri.', // @translate
                    ['count' => $count, 'total' => $total, 'term' => $term, 'singles' => $countSingle]
                );
                if ($this->isErrorOrStop()) {
                    break;
                }
            }
        }
        fclose($fp);
        fclose($fpHtml);
        $this->prepareCheckingFile($filepathHtml, 'end');

        $this->logger->notice(
            '{count}/{total} values for term "{term}" processed, {count_single} new values updated with a single uri.', // @translate
            ['count' => $count, 'total' => $total, 'count_single' => $countSingle]
        );

        $this->logger->notice(
            'The mapping page for {term} is available in "{url}".', // @translate
            ['term' => $term, 'url' => $this->job->getArg('base_path') . '/files/' . mb_substr($filepathHtml, strlen($basePath) + 1)]
        );
    }

    /**
     * @param array $mapper The mapper is already checked.
     */
    protected function transformValuesProcess(array $mapper): void
    {
        // Create a temporary table with the mapper.
        $sql = <<<SQL
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
            ? $this->transformValuesInsert()
            : $this->transformValuesUpdate();

        $sql = <<<SQL
DROP TABLE IF EXISTS `_temporary_mapper`;
SQL;
        $this->connection->exec($sql);
    }

    /**
     * @param array $mapper The mapper is already checked.
     */
    protected function transformValuesUpdate(): void
    {
        $sql = <<<SQL
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
    `value`.`lang` = `_temporary_mapper`.`lang`,
    `value`.`value_resource_id` = `_temporary_mapper`.`value_resource_id`,
    `value`.`is_public` = `_temporary_mapper`.`is_public`;

SQL;
        $this->connection->executeUpdate($sql);
    }

    /**
     * @param array $mapper The mapper is already checked.
     */
    protected function transformValuesInsert(): void
    {
        $sql = <<<SQL
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

        $sql = <<<SQL
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

    protected function transformValuesSingle(
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

    protected function checkTransformArguments($term, $datatype = null): bool
    {
        $propertyId = $this->bulk->getPropertyId($term);
        if (!$propertyId) {
            $this->logger->err(
                'The property "{property}" does not exist.', // @translate
                ['property' => $term]
            );
            return false;
        }

        if (!$datatype) {
            return true;
        }

        if (substr($datatype, 0, 12) === 'valuesuggest' && empty($this->modules['ValueSuggest'])) {
            $this->logger->err(
                'The module "Value Suggest" is required to transform values.' // @translate
            );
            return false;
        }

        return true;
    }

    protected function apiQuery(string $value, string $datatype, array $options = []): ?array
    {
        /** @var \ValueSuggest\Suggester\SuggesterInterface $suggesters */
        static $suggesters = [];
        // static $lang;

        if (!isset($suggesters[$datatype])) {
            if (mb_substr($datatype, 0, 12) !== 'valuesuggest') {
                $this->logger->err(
                    'Only value suggest data types can be queried currently.' // @translate
                );
                return null;
            }
            $suggesters[$datatype] = $this->getServiceLocator()->get('Omeka\DataTypeManager')
                ->get($datatype)
                ->getSuggester();

            // $lang = $this->getParam('language');
        }

        try {
            $suggestions = $suggesters[$datatype]->getSuggestions($value);
        } catch (HttpAdapterException $e) {
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

    protected function prepareCheckingFile(string $filepath, ?string $part = null, ?string $term = null): void
    {
        $html = <<<HTML
<!DOCTYPE html>
<html>
    <head>
        <meta charset="utf-8" />
        <title>Mapping $term</title>
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
        <h1>Mapping $term</h1>
        <table class="blueTable">
            <thead>
                <tr>
                    <th scope="col">Label source</th>
                    <th scope="col">Ressource id</th>
                    <th scope="col">Uri</th>
                    <th scope="col">Valeur</th>
                    <th scope="col">Info</th>
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

    protected function appendCheckingFile($fp, string $value, $resourceIds, array $result): void
    {
        $count = count($result);
        $rowspan = $count <= 1 ? '' : sprintf(' rowspan="%d"', $count);

        $resources = '';
        foreach (explode(',', $resourceIds) as $id) {
            $resources .= sprintf(
                '<a href="%s/admin/item/%d" target="_blank">item #%d</a><br/>',
                $this->job->getArg('base_path'), $id, $id
            ) . "\n";
        }

        $html = "                <tr>\n";
        $html .= sprintf('                    <td scope="row"%s>%s</td>', $rowspan, htmlspecialchars($value, ENT_NOQUOTES | ENT_HTML5)) . "\n";
        $html .= sprintf('                    <td%s>%s</td>', $rowspan, $resources) . "\n";
        if (!$count) {
            $html .= str_repeat('                    <td></td>' . "\n", 3);
            $html .= "                </tr>\n";
        } else {
            $first = true;
            foreach ($result as $r) {
                if ($first) {
                    $first = false;
                } else {
                    $html .= "                <tr>\n";
                }
                $uri = $r['data']['uri'] ?? '';
                $code = (string) basename(rtrim($uri, '/'));
                $info = $r['data']['info'] ?? '';
                $html .= sprintf('                    <td><a href="%s" target="_blank">%s</a></td>', htmlspecialchars($uri, ENT_NOQUOTES | ENT_HTML5), htmlspecialchars($code, ENT_NOQUOTES | ENT_HTML5)) . "\n";
                $html .= sprintf('                    <td>%s</td>', htmlspecialchars($r['value'] ?? '', ENT_NOQUOTES | ENT_HTML5)) . "\n";
                $html .= sprintf('                    <td>%s</td>', htmlspecialchars(is_array($info) ? json_encode($info, 320) : (string) $info, ENT_NOQUOTES | ENT_HTML5)) . "\n";
                $html .= "                </tr>\n";
            }
        }
        fwrite($fp, $html);
    }
}
