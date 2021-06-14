<?php declare(strict_types=1);

namespace BulkImport\Processor;

/**
 * The transformation applies to all values of table "_temporary_value_id".
 */
trait MetadataTransformTrait
{
    protected function transformLiteralToValueSuggest($term, string $datatype, array $options = []): void
    {
        empty($options['mapping'])
            ? $this->transformLiteralToValueSuggestWithApi($term, $datatype, $options)
            : $this->transformLiteralToValueSuggestWithMapping($term, $datatype, $options);
    }

    protected function transformLiteralToValueSuggestWithMapping($term, string $datatype, array $options = []): void
    {
        if (!$this->checkTransformArguments($term, $datatype)) {
            return;
        }

        $propertyId = $this->bulk->getPropertyId($term);
        $mapping = $options['mapping'];
        $prefix = $options['prefix'] ?? null;

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

    protected function transformLiteralToValueSuggestWithApi($term, string $datatype): void
    {
        // Create a mapping for future control.

        // Save the mapping.
        // Save the map as a php array for future purpose (cf. lien rubrique spip).
        $config = $this->getServiceLocator()->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $filepath = $basePath . '/bulk_import/' . 'import_' . $this->job->getJobId() . '.tsv';
        if (!is_dir(dirname($filepath))) {
            @mkdir(dirname($filepath, 0775, true));
        }
        file_put_contents($filepath, json_encode($this->map, 448));
        $this->logger->notice(
            'Mapping saved in "{url}".', // @translate
            // TODO Add domain to url.
            ['url' => '/files/' . mb_substr($filepath, strlen($basePath) + 1)]
        );


        // TODO transformLiteralToValueSuggestWithApi()
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
            array_walk($chunk, function (&$v, $k) {
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
            ? $this->transformValuesInsert($mapper)
            : $this->transformValuesUpdate($mapper);

        $sql = <<<SQL
DROP TABLE IF EXISTS `_temporary_mapper`;
SQL;
        $this->connection->exec($sql);
    }

    /**
     * @param array $mapper The mapper is already checked.
     */
    protected function transformValuesUpdate(array $mapper): void
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
    protected function transformValuesInsert(array $mapper): void
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
}
