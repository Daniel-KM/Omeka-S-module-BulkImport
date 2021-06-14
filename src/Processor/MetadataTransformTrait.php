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
    use ConfigTrait;

    /**
     * @var \Doctrine\DBAL\Connection $connection
     */
    protected $connection;

    /**
     * Store all mappings during the a job, so it can be completed.
     *
     * The use case is to fill creators and contributors with the same endpoint.
     * It allows to use a manual user mapping or a previous mapping too.
     * It is used to fill output too (ods and html).
     *
     * @var array
     */
    protected $valueSuggestMappings = [];

    protected $transformIndex = 0;

    protected $operationName = [];

    protected $operationIndex = 0;

    protected $operationSqls = [];

    protected $operationExcludes = [];

    protected $operationRandoms = [];

    /**
     * Maximum number of resources to display in ods/html output.
     *
     * @var integer
     */
    protected $outputByColumn = 10;

    protected function transformOperations(array $operations = []): void
    {
        $this->transformResetProcess();

        // TODO Move all check inside the preprocess.
        // TODO Use a transaction (implicit currently).
        // TODO Bind is not working with multiple queries.

        foreach ($operations as $index => $operation) {
            $this->operationName = $operation['action'];
            $this->operationIndex = ++$index;
            $this->operationRandoms[$this->operationIndex] = substr(str_replace(['+', '/', '='], ['', '', ''], base64_encode(random_bytes(64))), 0, 8);

            switch ($operation['action']) {
                // So, "create_resource" is a quick way to import a spreadsheet…
                case 'create_resource':
                    $result = $this->operationCreateResource($operation['params']);
                    if (!$result) {
                        return;
                    }
                    break;

                case 'attach_item_set':
                    $result = $this->operationAttachItemSet($operation['params']);
                    if (!$result) {
                        return;
                    }
                    break;

                case 'replace_table':
                    $result = $this->operationReplaceTable($operation['params']);
                    if (!$result) {
                        return;
                    }
                    break;

                case 'link_resource':
                    $result = $this->operationLinkResource($operation['params']);
                    if (!$result) {
                        return;
                    }
                    break;

                case 'copy_value_linked':
                    $result = $this->operationCopyValueLinked($operation['params']);
                    if (!$result) {
                        return;
                    }
                    break;

                case 'remove_value':
                    $result = $this->operationRemoveValue($operation['params']);
                    if (!$result) {
                        return;
                    }
                    break;

                case 'modify_value':
                    $result = $this->operationModifyValue($operation['params']);
                    if (!$result) {
                        return;
                    }
                    break;

                case 'cut_value':
                    $result = $this->operationCutValue($operation['params']);
                    if (!$result) {
                        return;
                    }
                    break;

                case 'append_value':
                    $result = $this->operationAppendValue($operation['params']);
                    if (!$result) {
                        return;
                    }
                    break;

                case 'convert_datatype':
                    $result = $this->operationConvertDatatype($operation['params']);
                    if (!$result) {
                        return;
                    }
                    break;

                case 'apply':
                    $this->operationSqls = array_filter($this->operationSqls);
                    if (count($this->operationSqls)) {
                        $this->transformHelperExcludeEnd();
                        $this->connection->executeUpdate(implode("\n", $this->operationSqls));
                    }
                    $this->transformResetProcess();
                    break;

                default:
                    $this->logger->err(
                        'The operation "{action}" is not managed currently.', // @translate
                        ['action' => $this->operationName]
                    );
                    return;
            }
        }

        // Skip process when an error occurred.
        $this->operationSqls = array_filter($this->operationSqls);
        if (!count($this->operationSqls)) {
            return;
        }

        $this->transformHelperExcludeEnd();

        // Transaction is implicit.
        $this->connection->executeUpdate(implode("\n", $this->operationSqls));
    }

    protected function transformResetProcess(): void
    {
        $this->operationSqls = [];
        $this->operationExcludes = [];
    }

    protected function operationCreateResource(array $params): bool
    {
        $params['no_source'] = true;
        $hasMappingProperties = !empty($params['mapping_properties']);
        if ($hasMappingProperties) {
            $mapper = $this->prepareMappingTableFromValues($params);
            if (!$mapper) {
                $this->logger->warn(
                    'The operation "{action}" has no values to create resource.', // @translate
                    ['action' => $this->operationName]
                );
                return true;
            }
        } else {
            $result = $this->prepareMappingTable($params);
            if (!$result) {
                return false;
            }

            $mapper = reset($result);

            $this->storeMappingTable($mapper);
        }

        $this->processCreateResources($params);

        if (!empty($params['link_resource'])) {
            if ($hasMappingProperties) {
                $this->processCreateLinkForCreatedResourcesFromValues($params);
            } else {
                // TODO Create links for created resources from a mapping. Currently, link resources with source_term and remove values.
            }
        }

        $this->removeMappingTables();

        return true;
    }

    protected function operationAttachItemSet(array $params): bool
    {
        if (empty($params['source'])) {
            $this->logger->err(
                'The operation "{action}" requires a source.', // @translate
                ['action' => $this->operationName]
            );
            return false;
        }

        $sourceId = $this->getPropertyId($params['source']);
        if (empty($sourceId)) {
            $this->logger->err(
                'The operation "{action}" requires a valid source: "{term}" does not exist.', // @translate
                ['action' => $this->operationName, 'term' => $params['source']]
            );
            return false;
        }

        $sourceIdentifier = $params['identifier'] ?? 'dcterms:identifier';
        $sourceIdentifierId = $this->getPropertyId($sourceIdentifier);
        if (empty($sourceIdentifierId)) {
            $this->logger->err(
                'The operation "{action}" requires a valid source identifier term: "{term}" does not exist.', // @translate
                ['action' => $this->operationName, 'term' => $params['identifier']]
            );
            return false;
        }

        // Impossible to list item sets identifiers, that may be created in a
        // previous operation.

        [$sqlExclude, $sqlExcludeWhere] = $this->transformHelperExcludeStart($params);

        $this->operationSqls[] = <<<SQL
# Attach items to item sets according to a value.
INSERT INTO `item_item_set`
    (`item_id`, `item_set_id`)
SELECT DISTINCT
    `value`.`resource_id`,
    `value_item_set`.`resource_id`
FROM `value`
JOIN `value` AS `value_item_set`
JOIN `item_set`
    ON `item_set`.`id` = `value_item_set`.`resource_id`
JOIN `_temporary_value`
    ON `_temporary_value`.`id` = `value`.`id`
$sqlExclude
WHERE
    `value`.`property_id` = $sourceId
    AND (`value`.`type` = "literal" OR `value`.`type` = "" OR `value`.`type` IS NULL)
    AND `value`.`value` <> ""
    AND `value`.`value` IS NOT NULL
    AND `value_item_set`.`property_id` = $sourceIdentifierId
    AND (`value_item_set`.`type` = "literal" OR `value_item_set`.`type` = "" OR `value_item_set`.`type` IS NULL)
    AND `value`.`value` = `value_item_set`.`value`
$sqlExcludeWhere
ORDER BY `value`.`resource_id`
ON DUPLICATE KEY UPDATE
    `item_id` = `item_item_set`.`item_id`,
    `item_set_id` = `item_item_set`.`item_set_id`
;
SQL;

        return true;
    }

    protected function operationReplaceTable(array $params): bool
    {
        $result = $this->prepareMappingTable($params);
        if (!$result) {
            return false;
        }

        [$mapper, $hasMultipleDestinations] = $result;

        $this->storeMappingTable($mapper);

        $hasMultipleDestinations
            ? $this->processValuesTransformReplace()
            : $this->processValuesTransformUpdate();

        $this->removeMappingTables();

        return true;
    }

    protected function operationLinkResource(array $params): bool
    {
        if (empty($params['source'])) {
            $this->logger->err(
                'The operation "{action}" requires a source.', // @translate
                ['action' => $this->operationName]
            );
            return false;
        }

        $sourceId = $this->getPropertyId($params['source']);
        if (empty($sourceId)) {
            $this->logger->err(
                'The operation "{action}" requires a valid source: "{term}" does not exist.', // @translate
                ['action' => $this->operationName, 'term' => $params['source']]
            );
            return false;
        }

        if (empty($params['destination'])) {
            $destinationId = $sourceId;
        } else {
            $destinationId = $this->getPropertyId($params['destination']);
            if (empty($destinationId)) {
                $this->logger->err(
                    'The operation "{action}" requires a valid destination: "{term}" does not exist.', // @translate
                    ['action' => $this->operationName, 'term' => $params['destination']]
                );
                return false;
            }
        }

        $sourceIdentifier = $params['identifier'] ?? 'dcterms:identifier';
        $sourceIdentifierId = $this->getPropertyId($sourceIdentifier);
        if (empty($sourceIdentifierId)) {
            $this->logger->err(
                'The operation "{action}" requires a valid source identifier term: "{term}" does not exist.', // @translate
                ['action' => $this->operationName, 'term' => $params['identifier']]
            );
            return false;
        }

        if (empty($params['reciprocal'])) {
            $reciprocalId = null;
        } else {
            $reciprocalId = $this->getPropertyId($params['reciprocal']);
            if (empty($reciprocalId)) {
                $this->logger->err(
                    'The operation "{action}" specifies an invalid reciprocal property: "{term}".', // @translate
                    ['action' => $this->operationName, 'term' => $params['reciprocal']]
                );
                return false;
            }
        }

        $type = $params['type'] ?? 'resource:item';
        $quotedType = $this->connection->quote($type);
        // TODO Use the template for is_public.
        $isPublic = (int) (bool) ($params['is_public'] ?? 1);

        [$sqlExclude, $sqlExcludeWhere] = $this->transformHelperExcludeStart($params);

        $this->operationSqls[] = <<<SQL
# Create linked values for all values that have an identifiable value.
INSERT INTO `value`
    (`resource_id`, `property_id`, `value_resource_id`, `type`, `is_public`)
SELECT DISTINCT
    `value`.`resource_id`,
    $destinationId,
    `value_linked`.`resource_id`,
    $quotedType,
    $isPublic
FROM `value`
JOIN `value` AS `value_linked`
    ON `value_linked`.`value` = `value`.`value`
$sqlExclude
WHERE
    `value`.`property_id` = $sourceId
    AND (`value`.`type` = "literal" OR `value`.`type` = "" OR `value`.`type` IS NULL)
    AND `value`.`value` <> ""
    AND `value`.`value` IS NOT NULL
    AND `value_linked`.`property_id` = $sourceIdentifierId
    AND (`value_linked`.`type` = "literal" OR `value_linked`.`type` = "" OR `value_linked`.`type` IS NULL)
    AND `value_linked`.`value` <> ""
    AND `value_linked`.`value` IS NOT NULL
    $sqlExcludeWhere
ORDER BY `value`.`resource_id`
;
SQL;

        if ($reciprocalId) {
            $this->operationSqls[] = <<<SQL
# Create reciprocal linked values for all values that have an identifiable value.
INSERT INTO `value`
    (`resource_id`, `property_id`, `value_resource_id`, `type`, `is_public`)
SELECT DISTINCT
    `value_linked`.`resource_id`,
    $reciprocalId,
    `value`.`resource_id`,
    $quotedType,
    $isPublic
FROM `value`
JOIN `value` AS `value_linked`
    ON `value_linked`.`value` = `value`.`value`
$sqlExclude
WHERE
    `value`.`property_id` = $sourceId
    AND (`value`.`type` = "literal" OR `value`.`type` = "" OR `value`.`type` IS NULL)
    AND `value`.`value` <> ""
    AND `value`.`value` IS NOT NULL
    AND `value_linked`.`property_id` = $sourceIdentifierId
    AND (`value_linked`.`type` = "literal" OR `value_linked`.`type` = "" OR `value_linked`.`type` IS NULL)
    AND `value_linked`.`value` <> ""
    AND `value_linked`.`value` IS NOT NULL
    $sqlExcludeWhere
ORDER BY `value`.`resource_id`
;
SQL;
        }

        if (empty($params['keep_source'])) {
            $this->operationSqls[] = <<<SQL
# Remove the values that were linked.
DELETE `value`
FROM `value`
JOIN `value` AS `value_linked`
    ON `value_linked`.`value` = `value`.`value`
$sqlExclude
WHERE
    `value`.`property_id` = $sourceId
    AND (`value`.`type` = "literal" OR `value`.`type` = "" OR `value`.`type` IS NULL)
    AND `value`.`value` <> ""
    AND `value`.`value` IS NOT NULL
    AND `value_linked`.`property_id` = $sourceIdentifierId
    AND (`value_linked`.`type` = "literal" OR `value_linked`.`type` = "" OR `value_linked`.`type` IS NULL)
    AND `value_linked`.`value` <> ""
    AND `value_linked`.`value` IS NOT NULL
    $sqlExcludeWhere
;
SQL;
        }

        return true;
    }

    /**
     * Copy all specified values of resources in their linked resources.
     *
     * For example, copy all values with properties dcterms:language and dcterms:audience
     * in the resources linked via the property dcterms:isPartOf.
     */
    protected function operationCopyValueLinked(array $params): bool
    {
        if (empty($params['source'])) {
            $this->logger->err(
                'The operation "{action}" requires a source.', // @translate
                ['action' => $this->operationName]
            );
            return false;
        }

        $sourceId = $this->getPropertyId($params['source']);
        if (empty($sourceId)) {
            $this->logger->err(
                'The operation "{action}" requires a valid source: "{term}" does not exist.', // @translate
                ['action' => $this->operationName, 'term' => $params['source']]
            );
            return false;
        }

        if (empty($params['properties'])) {
            $this->logger->err(
                'The operation "{action}" requires a list of properties.', // @translate
                ['action' => $this->operationName]
            );
            return true;
        }

        if (!is_array($params['properties'])) {
            $params['properties'] = [$params['properties']];
        }

        $properties = [];
        $errors = [];
        foreach ($params['properties'] as $property) {
            $propertyId = $this->bulk->getPropertyId($property);
            $propertyId
                ? $properties[$property] = $propertyId
                : $errors[] = $property;
        }

        if (count($errors)) {
            $this->logger->err(
                'The operation "{action}" has invalid properties: "{terms}".', // @translate
                ['action' => $this->operationName, 'terms' => implode('", "', $errors)]
            );
            return false;
        }

        $propertyIds = implode(', ', $properties);

        [$sqlExclude, $sqlExcludeWhere] = $this->transformHelperExcludeStart($params);

        $this->operationSqls[] = <<<SQL
# Create distinct values from a list of values of linked resources.
INSERT INTO `value`
    (`resource_id`, `property_id`, `value_resource_id`, `type`, `lang`, `value`, `uri`, `is_public`)
SELECT DISTINCT
    `value_linked`.`value_resource_id`,
    `value`.`property_id`,
    `value`.`value_resource_id`,
    `value`.`type`,
    `value`.`lang`,
    `value`.`value`,
    `value`.`uri`,
    `value`.`is_public`
FROM `value`
JOIN `value` AS `value_linked`
    ON `value_linked`.`resource_id` = `value`.`resource_id`
JOIN `_temporary_value`
    ON `_temporary_value`.`id` = `value`.`id`
$sqlExclude
WHERE
    `value_linked`.`value_resource_id` IS NOT NULL
    AND `value`.`property_id` IN ($propertyIds)
    AND `value_linked`.`property_id` = $sourceId
    $sqlExcludeWhere
ORDER BY `value_linked`.`value_resource_id`
;
SQL;

        return true;
    }

    protected function operationRemoveValue(array $params): bool
    {
        if (empty($params['properties'])) {
            $this->logger->err(
                'The operation "{action}" requires a list of properties.', // @translate
                ['action' => $this->operationName]
            );
            return true;
        }

        if (!is_array($params['properties'])) {
            $params['properties'] = [$params['properties']];
        }

        $properties = [];
        $errors = [];
        foreach ($params['properties'] as $property) {
            $propertyId = $this->bulk->getPropertyId($property);
            $propertyId
                ? $properties[$property] = $propertyId
                : $errors[] = $property;
        }

        if (count($errors)) {
            $this->logger->err(
                'The operation "{action}" has invalid properties: "{terms}".', // @translate
                ['action' => $this->operationName, 'terms' => implode('", "', $errors)]
            );
            return false;
        }

        $propertyIds = implode(', ', $properties);

        if (!empty($params['on']['resource_random'])) {
            if (!isset($this->operationRandoms[$this->operationIndex + $params['on']['resource_random']])) {
                $this->logger->err(
                    'The operation "{action}" has an invalid parameter', // @translate
                    ['action' => $this->operationName]
                );
                return false;
            }

            $this->operationSqls[] = <<<SQL
# Delete values for specific properties.
DELETE `value`
FROM `value`
JOIN `resource`
    ON `resource`.`id` = `value`.`resource_id`
JOIN `_temporary_new_resource`
    ON `_temporary_new_resource`.`id` = `resource`.`id`
WHERE
    `value`.`property_id` IN ($propertyIds)
;
SQL;
            return true;
        }

        [$sqlExclude, $sqlExcludeWhere] = $this->transformHelperExcludeStart($params);

        $this->operationSqls[] = <<<SQL
# Delete values for specific properties.
DELETE `value`
FROM `value`
JOIN `_temporary_value`
    ON `_temporary_value`.`id` = `value`.`id`
$sqlExclude
WHERE
    `value`.`property_id` IN ($propertyIds)
    $sqlExcludeWhere
;
SQL;

        return true;
    }

    protected function operationModifyValue(array $params): bool
    {
        if (empty($params['source'])) {
            $this->logger->err(
                'The operation "{action}" requires a source.', // @translate
                ['action' => $this->operationName]
            );
            return false;
        }

        $sourceId = $this->getPropertyId($params['source']);
        if (empty($sourceId)) {
            $this->logger->err(
                'The operation "{action}" requires a valid source: "{term}" does not exist.', // @translate
                ['action' => $this->operationName, 'term' => $params['source']]
            );
            return false;
        }

        if (empty($params['destination'])) {
            $destinationId = $sourceId;
        } else {
            $destinationId = $this->getPropertyId($params['destination']);
            if (empty($destinationId)) {
                $this->logger->err(
                    'The operation "{action}" requires a valid destination: "{term}" does not exist.', // @translate
                    ['action' => $this->operationName, 'term' => $params['destination']]
                );
                return false;
            }
        }

        $updates = [];
        if ($sourceId !== $destinationId) {
            $updates[] = '`value`.`property_id` = ' . $destinationId;
        }
        /* // Use of sql requests from config is not secure.
        if (isset($params['sql_value'])) {
            $updates['sql_value'] = '`value`.`value` = ' . $params['sql_value'];
        }
        if (isset($params['sql_uri'])) {
            $updates['sql_uri'] = '`value`.`uri` = ' . $params['sql_uri'];
        }
        */
        // "prefix"/"suffix" and "value_prefix"/"value_suffix" are the same.
        $update = array_filter([
            isset($params['prefix']) ? $this->connection->quote($params['prefix']) : null,
            '`value`.`value`',
            isset($params['suffix']) ? $this->connection->quote($params['suffix']) : null,
        ]);
        if (count($update) > 1) {
            $updates['value'] = '`value`.`value` = CONCAT(' . implode(', ', $update) . ')';
        }
        $update = array_filter([
            isset($params['value_prefix']) ? $this->connection->quote($params['value_prefix']) : null,
            '`value`.`value`',
            isset($params['value_suffix']) ? $this->connection->quote($params['value_suffix']) : null,
        ]);
        if (count($update) > 1) {
            $updates['value'] = '`value`.`value` = CONCAT(' . implode(', ', $update) . ')';
        }
        $update = array_filter([
            isset($params['uri_prefix']) ? $this->connection->quote($params['uri_prefix']) : null,
            '`value`.`value`',
            isset($params['uri_suffix']) ? $this->connection->quote($params['uri_suffix']) : null,
        ]);
        if (count($update) > 1) {
            $updates['uri'] = '`value`.`uri` = CONCAT(' . implode(', ', $update) . ')';
        }
        if (isset($params['language'])) {
            $updates['lang'] = '`value`.`lang` = ' . (empty($params['language']) ? 'NULL' : $this->connection->quote($params['language']));
        }
        if (isset($params['is_public'])) {
            $updates['is_public'] = '`value`.`is_public` = ' . (int) (bool) $params['is_public'];
        }
        $updates = array_filter($updates);

        if (!count($updates)) {
            $this->logger->err(
                'The operation "{action}" has not defined action to update values.', // @translate
                ['action' => $this->operationName]
            );
            return true;
        }

        [$sqlExclude, $sqlExcludeWhere] = $this->transformHelperExcludeStart($params);

        $updates = implode(",\n    ", $updates);

        $this->operationSqls[] = <<<SQL
# Update values according to rules for each column.
UPDATE `value`
JOIN `_temporary_value`
    ON `_temporary_value`.`id` = `value`.`id`
$sqlExclude
SET
    $updates
WHERE
    `value`.`property_id` = $sourceId
    $sqlExcludeWhere
;
SQL;

        return true;
    }

    protected function operationCutValue(array $params): bool
    {
        if (empty($params['destination'])
            || count($params['destination']) !== 2
        ) {
            $this->logger->err(
                'The operation "{action}" requires two destinations currently.', // @translate
                ['action' => $this->operationName]
            );
            return false;
        }
        if (!isset($params['separator']) || !strlen($params['separator'])) {
            $this->logger->err(
                'The operation "{action}" requires a separator.', // @translate
                ['action' => $this->operationName]
            );
            return false;
        }

        [$sqlExclude, $sqlExcludeWhere] = $this->transformHelperExcludeStart($params);

        // TODO Manage separators quote and double quote for security (but user should be able to access to database anyway).
        $separator = $params['separator'];
        $quotedSeparator = $this->connection->quote($separator);
        if ($separator === '%') {
            $separator = '\\%';
        } elseif ($separator === '_') {
            $separator = '\\_';
        }

        // TODO Bind is not working currently with multiple queries, but only used for property id.
        // value => bio:place : dcterms:publisher
        $binds = [];
        $binds['property_id_1'] = $this->getPropertyId($params['destination'][0]);
        $binds['property_id_2'] = $this->getPropertyId($params['destination'][1]);

        $random = $this->operationRandoms[$this->operationIndex];

        $this->operationSqls[] = <<<SQL
# Create a new trimmed value with first part.
INSERT INTO `value`
    (`resource_id`, `property_id`, `value_resource_id`, `type`, `value`, `uri`, `lang`, `is_public`)
SELECT DISTINCT
    `value`.`resource_id`,
    {$binds['property_id_1']},
    `value`.`value_resource_id`,
    # Hack to keep list of all inserted ids for next operations (or create another temporary table?).
    CONCAT("$random ", `value`.`type`),
    TRIM(SUBSTRING_INDEX(`value`.`value`, $quotedSeparator, 1)),
    `value`.`uri`,
    `value`.`lang`,
    `value`.`is_public`
FROM `value`
JOIN `_temporary_value`
    ON `_temporary_value`.`id` = `value`.`id`
$sqlExclude
WHERE
    `value`.`value` LIKE '%$separator%'
    $sqlExcludeWhere
;
SQL;
        $this->operationSqls[] = <<<SQL
# Update source with the trimmed second part.
UPDATE `value`
JOIN `_temporary_value`
    ON `_temporary_value`.`id` = `value`.`id`
$sqlExclude
SET
    `value`.`property_id` = {$binds['property_id_2']},
    `value`.`value` = TRIM(SUBSTRING(`value`.`value`, LOCATE($quotedSeparator, `value`.`value`) + 1))
WHERE
    value.value LIKE '%$separator%'
    $sqlExcludeWhere
;
SQL;
        $this->operationSqls[] = <<<SQL
# Store the new value ids to manage next operations.
INSERT INTO `_temporary_value` (`id`)
SELECT `value`.`id`
FROM `value`
WHERE `value`.`property_id` = {$binds['property_id_1']}
    AND (`value`.`type` LIKE "$random %")
ON DUPLICATE KEY UPDATE
    `_temporary_value`.`id` = `_temporary_value`.`id`
;
SQL;
        $this->operationSqls[] = <<<SQL
# Finalize type for first part.
UPDATE `value`
SET
    `value`.`type` = SUBSTRING_INDEX(`value`.`type`, " ", -1)
WHERE `value`.`property_id` = {$binds['property_id_1']}
    AND (`value`.`type` LIKE "$random %")
;
SQL;
        return true;
    }

    /**
     * @todo Merge with operationConvertDatatype().
     * @todo Merge with transformToValueSuggestWithApi().
     */
    protected function operationAppendValue(array $params): bool
    {
        if (empty($params['source'])) {
            $this->logger->err(
                'The operation "{action}" requires a source.', // @translate
                ['action' => $this->operationName]
            );
            return false;
        }

        $sourceId = $this->getPropertyId($params['source']);
        if (empty($sourceId)) {
            $this->logger->err(
                'The operation "{action}" requires a valid source: "{term}" does not exist.', // @translate
                ['action' => $this->operationName, 'term' => $params['source']]
            );
            return false;
        }

        $sourceTerm = $params['source'] = $this->getPropertyTerm($sourceId);

        if (empty($params['datatype'])) {
            $this->logger->err(
                'The operation "{action}" requires a source for data (value suggest data type).', // @translate
                ['action' => $this->operationName]
            );
            return false;
        }

        $datatype = $params['datatype'];
        $isValueSuggest = substr($datatype, 0, 12) === 'valuesuggest';
        if (!$isValueSuggest) {
            $this->logger->err(
                'The operation "{action}" requires a value suggest data type as source for data.', // @translate
                ['action' => $this->operationName]
            );
            return false;
        }

        if (empty($params['properties'])) {
            $this->logger->err(
                'The operation "{action}" requires a list of properties to fill.', // @translate
                ['action' => $this->operationName]
            );
            return false;
        }

        if (!is_array($params['properties'])) {
            $params['properties'] = ['identifier' => $params['properties']];
        }
        $fromTo = [];
        $errors = [];
        foreach ($params['properties'] as $from => $to) {
            $toId = $this->bulk->getPropertyId($to);
            if ($toId) {
                $fromTo[$from] = $toId;
            } else {
                $errors[] = $to;
            }
        }
        if (count($errors)) {
            $this->logger->err(
                'The operation "{action}" has invalid properties: "{terms}".', // @translate
                ['action' => $this->operationName, 'terms' => implode('", "', $errors)]
            );
            return false;
        }

        if (empty($params['name'])) {
            $params['name'] = str_replace(':', '-', $datatype .'_' . $this->transformIndex);
        }

        $list = $this->prepareListForValueSuggest([$sourceTerm => $sourceId]);
        if (is_null($list)) {
            return false;
        }
        $totalList = count($list);
        if (!$totalList) {
            return true;
        }

        // Prepare the current mapping if any from params or previous steps.
        $this->prepareValueSuggestMapping($params);
        $this->updateValueSuggestMapping($params, $list);
        $currentMapping = &$this->valueSuggestMappings[$params['name']];
        if (empty($currentMapping)) {
            return false;
        }

        if ($this->isErrorOrStop()) {
            return false;
        }

        // Save mapped values for the current values. It allows to manage
        // multiple steps, for example to store some authors as creators, then as
        // contributors.

        // Only values with a single result are used to update resources.
        $single = function ($v) {
            return isset($v['source'])
                && mb_strlen($v['source'])
                && (
                    (!is_array($v['uri']) && mb_strlen((string) $v['uri']))
                    || (is_array($v['uri']) && count($v['uri']) === 1)
                );
        };

        $mapper = [];
        foreach (array_filter($currentMapping, $single) as $v) {
            foreach ($fromTo as $from => $propertyId) {
                if ($from === 'identifier') {
                    if (!isset($v['uri'])) {
                        $v['uri'] = [];
                    } elseif (!is_array($v['uri'])) {
                        $v['uri'] = [$v['uri']];
                    }
                    $uri = reset($v['uri']) ?: null;
                    if (!isset($v['label'])) {
                        $v['label'] = [];
                    } elseif (!is_array($v['label'])) {
                        $v['label'] = [$v['label']];
                    }
                    $value = reset($v['label']) ?: null;
                    if (!isset($v['type'])) {
                        $v['type'] = [];
                    } elseif (!is_array($v['type'])) {
                        $v['type'] = [$v['type']];
                    }
                    $type = reset($v['type']) ?: $datatype;
                } elseif (empty($v[$from])) {
                    continue;
                } else {
                    $value = is_array($v[$from]) ? reset($v[$from]) : $v[$from];
                    if (is_null($value) || $value === '') {
                        continue;
                    }
                    $type = 'literal';
                    $uri = null;
                }
                $mapper[] = [
                    'source' => $v['source'],
                    'property_id' => $propertyId,
                    'type' => $type,
                    'value' => $value,
                    'uri' => $uri,
                    'lang' => null,
                    'value_resource_id' => null,
                    'is_public' => 1,
                ];
            }
        }

        $this->storeMappingTable($mapper);

        $this->processValuesTransformInsert([$sourceId]);

        if (!empty($params['identifier_to_templates_and_classes'])) {
            $this->processUpdateTemplatesFromDataTypes($params);
        }

        $this->removeMappingTables();

        return true;
    }

    protected function operationConvertDatatype(array $params): bool
    {
        if (empty($params['datatype'])) {
            $this->logger->warn(
                'The operation "{action}" requires a data type.', // @translate
                ['action' => $this->operationName]
            );
            return false;
        }

        $dataTypeExceptions = [
            'valuesuggest:idref:author',
        ];
        $dataTypeManager = $this->getServiceLocator()->get('Omeka\DataTypeManager');
        if (!in_array($params['datatype'], $dataTypeExceptions) && !$dataTypeManager->has($params['datatype'])) {
            $this->logger->warn(
                'The operation "{action}" requires a valid data type ("{type}").', // @translate
                ['action' => $this->operationName, 'type' => $params['datatype']]
            );
            return false;
        }

        $datatype = $params['datatype'];

        if (substr($datatype, 0, 8) === 'numeric:') {
            $this->logger->err(
                'Operation "{action}": cannot convert into data type "{type}" for now.', // @translate
                ['action' => $this->operationName, 'type' => $datatype]
            );
            return false;
        }

        if (empty($params['name'])) {
            $params['name'] = str_replace(':', '-', $datatype .'_' . $this->transformIndex);
        }

        $isValueSuggest = substr($datatype, 0, 12) === 'valuesuggest';

        if ($isValueSuggest
            && (empty($params['mapping']) || !empty($params['partial_mapping']))
        ) {
            $result = $this->transformToValueSuggestWithApi($params);
            if (!$result) {
                return false;
            }

            $this->processValuesTransformUpdate();

            $this->removeMappingTables();
            return true;
        }

        if (!empty($params['mapping'])) {
            $this->logger->err(
                'Operation "{action}": Convert datatype without mapping keys is not supported currently. You should use "replace_table".', // @translate
                ['action' => $this->operationName]
            );
            // TODO Check mapping key.
            // return $this->operationReplaceTable($params);
            return false;
        }

        // TODO Convert without mapping ("literal" to "uri", "customvocab", etc.).
        $this->logger->err(
            'Operation "{action}": cannot convert into data type "{type}" without mapping for now.', // @translate
            ['action' => $this->operationName, 'type' => $datatype]
        );
        return false;
    }

    protected function transformHelperExcludeStart(array $params): array
    {
        if (empty($params['exclude'])) {
            return ['', ''];
        }

        $exclude = $this->loadList($params['exclude']);
        if (empty($exclude)) {
            $this->logger->warn(
                'Exclusion list "{name}" is empty.', // @translate
                ['term' => $params['exclude']]
            );
            return ['', ''];
        }

        $index = &$this->operationIndex;
        $this->operationExcludes[] = $index;
        // To exclude is to keep only other ones.
        $this->operationSqls[] = <<<SQL
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
            $this->operationSqls[] = <<<SQL
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

        return [
            $sqlExclude,
            $sqlExcludeWhere,
        ];
    }

    protected function transformHelperExcludeEnd(): void
    {
        // Remove operationExcludes.
        foreach ($this->operationExcludes as $index) {
            $this->operationSqls[] = <<<SQL
DROP TABLE IF EXISTS `_temporary_value_exclude_$index`;
SQL;
        }
    }

    protected function prepareMappingTable(array $params): ?array
    {
        if (empty($params['mapping'])) {
            $this->logger->warn(
                'Operation "{action}": no mapping defined.', // @translate
                ['action' => $this->operationName]
            );
            return null;
        }

        $table = $this->loadTable($params['mapping']);
        if (empty($table)) {
            $this->logger->warn(
                'Operation "{action}": no mapping or empty mapping in file "{file}".', // @translate
                ['action' => $this->operationName, 'file' => $params['mapping']]
            );
            return null;
        }

        $first = reset($table);
        if (count($first) <= 1) {
            $this->logger->warn(
                'Operation "{action}": mapping requires two columns at least.', // @translate
                ['action' => $this->operationName]
            );
            return null;
        }

        // When mapping table is used differently, there may be no source.
        $hasSource = empty($params['no_source']);

        $firstKeys = array_keys($first);
        $sourceKey = array_search('source', $firstKeys);
        if ($hasSource && $sourceKey === false) {
            $this->logger->err(
                'Operation "{action}": mapping requires a column "source".', // @translate
                ['action' => $this->operationName]
            );
            return null;
        }

        // TODO The param "source" is not used here, but in other steps, so move check.
        if ($hasSource && empty($params['source'])) {
            $this->logger->err(
                'Operation "{action}": a source is required.', // @translate
                ['action' => $this->operationName]
            );
            return null;
        }

        $sourceId = $this->getPropertyId($params['source']);
        if ($hasSource && empty($sourceId)) {
            $this->logger->err(
                'Operation "{action}": a valid source is required: "{term}" does not exist.', // @translate
                ['action' => $this->operationName, 'term' => $params['source']]
            );
            return null;
        }

        if (!empty($params['source_term'])) {
            $saveSourceId = $this->getPropertyId($params['source_term']);
            if (empty($saveSourceId)) {
                $this->logger->err(
                    'Operation "{action}": an invalid property is set to save source: "{term}".', // @translate
                    ['action' => $this->operationName, 'term' => $params['source_term']]
                );
                return null;
            }
        } else {
            $saveSourceId = null;
        }

        // Just fix messages.
        $params['source'] = $hasSource
            ? $this->bulk->getPropertyTerm($sourceId)
            : 'spreadsheet';

        $settings = $params['settings'] ?? [];
        foreach ($settings as $term => &$setting) {
            $setting['property_id'] = $this->bulk->getPropertyId($term);
            if (empty($setting['property_id'])){
                 unset($settings[$term]);
            }
        }
        unset($setting);

        unset($firstKeys[$sourceKey]);

        /** @var \BulkImport\View\Helper\AutomapFields $automapFields */
        $automapFields = $this->getServiceLocator()->get('ViewHelperManager')->get('automapFields');

        $destinations = [];
        $properties = [];
        $propertyIds = $this->getPropertyIds();
        $fields = $automapFields($firstKeys, ['output_full_matches' => true]);
        foreach (array_filter($fields) as $index => $field) {
            // Only one field is managed currently.
            $field = reset($field);
            if (isset($propertyIds[$field['field']])) {
                $field['header'] = $firstKeys[$index];
                $field['term'] = $field['field'];
                $field['property_id'] = $propertyIds[$field['field']];
                // TODO Check the type from the header in the automapping.
                $type = $field['type'] ?: 'literal';
                if (mb_substr($type, 0, 12) === 'customvocab:') {
                    if (empty($this->map['custom_vocabs'][$type]['datatype'])) {
                        $typeResult = $this->getCustomVocabDataType($type);
                        if (!$typeResult) {
                            $this->logger->err(
                                'The data type "{type}" is not valid.', // @translate
                                ['term' => $params['source'], 'value' => $type]
                            );
                            return null;
                        }
                        $type = $typeResult;
                    }
                }
                $field['type'] = $type;
                $destinations[] = $field;
                $properties[$field['field']] = $field['property_id'];
            }
        }

        if (!count($destinations)) {
            $this->logger->warn(
                'There are no mapped properties for destination: "{terms}".', // @translate
                ['terms' => implode('", "', $firstKeys)]
            );
            return null;
        }

        $this->logger->notice(
            'The source {term} is mapped with {count} properties: "{terms}".', // @translate
            ['term' => $params['source'], 'count' => count($properties), 'terms' => implode('", "', array_keys($properties))]
        );

        // Prepare the mapping. Cells are already trimmed strings.
        $mapper = [];
        foreach ($table as $row) {
            $source = $row['source'] ?? '';
            if (!strlen($source)) {
                continue;
            }

            // Prepare a map for the row with one value at least.
            $maps = [];
            foreach ($destinations as $destination) {
                $value = $row[$destination['header']];
                if (!strlen((string) $value)) {
                    continue;
                }

                // Unlike operation "modify_value", this is the mapping that is
                // modified, so the cell value. The Omeka value is set below.
                if (isset($settings[$destination['term']])) {
                    $setting = &$settings[$destination['term']];
                    if (isset($setting['prefix'])) {
                        $value = $setting['prefix'] . $value;
                    }
                    if (isset($setting['suffix'])) {
                        $value .= $setting['suffix'];
                    }
                    if (isset($setting['replace']) && mb_strlen($setting['replace'])) {
                        $sourceForValue = empty($setting['remove_space_source'])
                            ? $source
                            : str_replace(' ', '', $source);
                        $value = str_replace(['{source}', '{destination}'], [$sourceForValue, $value], $value);
                    }
                    unset($setting);
                }

                $type = $destination['type'];
                $uri = null;
                $valueResourceId = null;
                $language = null;
                $isPublic = null;

                switch ($type) {
                    default:
                    // TODO Log unmanaged type.
                    // case 'literal':
                    // case 'html':
                    // case 'rdf:HTML':
                    // case 'xml':
                    // case 'rdf:XMLLiteral':
                    // case mb_substr($type, 0, 12) === 'customvocab:':
                        // Nothing to do.
                        break;

                    case 'uri':
                    case 'dcterms:URI':
                    case substr($type, 0, 12) === 'valuesuggest':
                        $posSpace = mb_strpos($value, ' ');
                        if ($posSpace === false) {
                            $uri = $value;
                            $value = null;
                        } else {
                            $uri = mb_substr($value, 0, $posSpace);
                            $value = trim(mb_substr($value, $posSpace + 1));
                        }
                        break;

                    case substr($type, 0, 8) === 'resource':
                        $vvalue = (int) $value;
                        if ($vvalue) {
                            $valueResourceId = $vvalue;
                            $value = null;
                        } else {
                            // TODO Manage resource id with identifier.
                            $this->logger->err(
                                'For "{term}", the value "{value}" is not a valid resource id.', // @translate
                                ['term' => $params['source'], 'value' => $value]
                            );
                        }
                        break;

                    case 'numeric:integer':
                    case 'numeric:timestamp':
                    case 'numeric:interval':
                    case 'numeric:duration':
                    case 'xsd:integer':
                        // TODO Not managed: store data in the second table (so convert and insert all non-mapped values).
                        $this->logger->err(
                            'Cannot convert source "{term}" into a numeric data type for now.', // @translate
                            ['term' => $params['source']]
                        );
                        break;
                }
                $maps[] = [
                    'source' => $source,
                    'property_id' => $destination['property_id'],
                    'type' => $type,
                    'value' => $value,
                    'uri' => $uri,
                    'value_resource_id' => $valueResourceId,
                    'lang' => $language,
                    'is_public' => $isPublic,
                ];
            }

            if ($saveSourceId) {
                $maps[] = [
                    'source' => $source,
                    'property_id' => $saveSourceId,
                    'type' => 'literal',
                    'value' => $source,
                    'uri' => null,
                    'value_resource_id' => null,
                    'lang' => null,
                    'is_public' => 0,
                ];
            }

            if (count($maps)) {
                $mapper = array_merge($mapper, $maps);
            }
        }

        $hasMultipleDestinations = count($destinations) > 1;

        return [
            $mapper,
            $hasMultipleDestinations,
        ];
    }

    /**
     * Save a mapping table in a temporary table in database.
     *
     * The mapping table is like the table value with a column "source" and
     * without column "resource_id".
     */
    protected function storeMappingTable(array $mapper): void
    {
        // Create a temporary table with the mapper.
        $this->operationSqls[] = <<<'SQL'
# Create a temporary table to map values.
DROP TABLE IF EXISTS `_temporary_mapper`;
CREATE TABLE `_temporary_mapper` LIKE `value`;
ALTER TABLE `_temporary_mapper`
    DROP `resource_id`,
    ADD `source` longtext COLLATE utf8mb4_unicode_ci
;
SQL;

        foreach (array_chunk($mapper, self::CHUNK_ENTITIES, true) as $chunk) {
            array_walk($chunk, function (&$v): void {
                $v = ((int) $v['property_id'])
                    . ",'" . $v['type'] . "'"
                    . ',' . (strlen((string) $v['value']) ? $this->connection->quote($v['value']) : 'NULL')
                    . ',' . (strlen((string) $v['uri']) ? $this->connection->quote($v['uri']) : 'NULL')
                    // TODO Check and normalize property language.
                    . ',' . (strlen((string) $v['lang']) ? $this->connection->quote($v['lang']) : 'NULL')
                    . ',' . ((int) $v['value_resource_id'] ? (int) $v['value_resource_id'] : 'NULL')
                    // TODO Try to keep original is_public or use the template one.
                    . ',' . (isset($v['is_public']) ? (int) $v['is_public'] : 1)
                    . ',' . $this->connection->quote($v['source'])
                ;
            });
            $chunkString = implode('),(', $chunk);
            $this->operationSqls[] = <<<SQL
INSERT INTO `_temporary_mapper` (`property_id`,`type`,`value`,`uri`,`lang`,`value_resource_id`,`is_public`,`source`)
VALUES($chunkString);

SQL;
        }
    }

    protected function prepareMappingTableFromValues(array $params): bool
    {
        // TODO Factorize checks with processCreateLinkForCreatedResources().
        if (empty($params['mapping_properties'])) {
            $this->logger->err(
                'The operation "{action}" requires a mapping of properties.', // @translate
                ['action' => $this->operationName]
            );
            return false;
        }

        $properties = [];
        $errors = [];
        foreach ($params['mapping_properties'] as $source => $destination) {
            $sourceId = $this->bulk->getPropertyId($source);
            if (!$sourceId) {
                $errors[] = $source;
            }
            $destinationId = $this->bulk->getPropertyId($destination);
            if (!$destinationId) {
                $errors[] = $destination;
            }
            if ($sourceId && $destinationId) {
                $properties[$sourceId] = $destinationId;
            }
        }

        if (count($errors)) {
            $this->logger->err(
                'The operation "{action}" has invalid properties: "{terms}".', // @translate
                ['action' => $this->operationName, 'terms' => implode('", "', $errors)]
            );
            return false;
        }

        if (count(array_unique($properties)) === 1) {
            $sourceToDestination = $destinationId;
        } else {
            $sourceIsDestination = true;
            foreach ($properties as $sourceId => $destinationId) {
                if ($sourceId !== $destinationId) {
                    $sourceIsDestination = false;
                    break;
                }
            }
            if ($sourceIsDestination) {
                $sourceToDestination = '`value`.`property_id`';
            } else {
                $sourceToDestination = "    CASE\n";
                foreach ($properties as $sourceId => $destinationId) {
                    $sourceToDestination .= "        WHEN `value`.`property_id` = $sourceId THEN $destinationId\n";
                }
                $sourceToDestination .= "    ELSE `value`.`property_id`\n";
                $sourceToDestination .= '    END';
            }
        }

        $sourceIds = implode(', ', array_keys($properties));

        [$sqlExclude, $sqlExcludeWhere] = $this->transformHelperExcludeStart($params);

        // Copy all distinct values in a temporary table.
        // Create a temporary table with the mapper.
        $this->operationSqls[] = <<<'SQL'
# Create a temporary table to store values.
DROP TABLE IF EXISTS `_temporary_mapper`;
CREATE TABLE `_temporary_mapper` LIKE `value`;
ALTER TABLE `_temporary_mapper`
    DROP `resource_id`,
    ADD `source` longtext COLLATE utf8mb4_unicode_ci
;
SQL;

        $this->operationSqls[] = <<<SQL
# Fill temporary table with unique values from existing values.
INSERT INTO `_temporary_mapper`
    (`property_id`, `type`, `value`, `uri`, `lang`, `value_resource_id`, `is_public`, `source`)
SELECT DISTINCT
    $sourceToDestination,
    `value`.`type`,
    `value`.`value`,
    `value`.`uri`,
    `value`.`lang`,
    `value`.`value_resource_id`,
    `value`.`is_public`,
    `value`.`value`
FROM `value`
JOIN `_temporary_value`
    ON `_temporary_value`.`id` = `value`.`id`
$sqlExclude
WHERE
    `value`.`property_id` IN ($sourceIds)
    $sqlExcludeWhere
;
SQL;
        return true;
    }

    protected function removeMappingTables(): void
    {
        // Remove operationExcludes.
        $this->operationSqls[] = <<<'SQL'
DROP TABLE IF EXISTS `_temporary_mapper`;
DROP TABLE IF EXISTS `_temporary_new_resource`;
SQL;
    }

    /**
     * @todo Merge with operationAppendValue().
     */
    protected function transformToValueSuggestWithApi(array $params): bool
    {
        if (empty($params['source'])) {
            $this->logger->err(
                'The operation "{action}" requires a source.', // @translate
                ['action' => $this->operationName]
            );
            return false;
        }

        $sourceId = $this->getPropertyId($params['source']);
        if (empty($sourceId)) {
            $this->logger->err(
                'The operation "{action}" requires a valid source: "{term}" does not exist.', // @translate
                ['action' => $this->operationName, 'term' => $params['source']]
            );
            return false;
        }

        $sourceTerm = $params['source'] = $this->getPropertyTerm($sourceId);

        $list = $this->prepareListForValueSuggest([$sourceTerm => $sourceId]);
        if (is_null($list)) {
            return false;
        }
        $totalList = count($list);
        if (!$totalList) {
            return true;
        }

        // Prepare the current mapping if any from params or previous steps.
        $this->prepareValueSuggestMapping($params);
        $this->updateValueSuggestMapping($params, $list);
        $currentMapping = &$this->valueSuggestMappings[$params['name']];
        if (empty($currentMapping)) {
            return false;
        }

        if ($this->isErrorOrStop()) {
            return false;
        }

        // Data type is already checked.
        $datatype = $params['datatype'];

        // Save mapped values for the current values. It allows to manage
        // multiple steps, for example to store some authors as creators, then as
        // contributors.

        // Only single values are updated.
        $single = function ($v) {
            return isset($v['source'])
                && mb_strlen($v['source'])
                && (
                    (!is_array($v['uri']) && mb_strlen((string) $v['uri']))
                    || (is_array($v['uri']) && count($v['uri']) === 1)
                );
        };

        $mapper = array_map(function ($v) use ($sourceId, $datatype) {
            if (!isset($v['uri'])) {
                $uri = null;
            } else {
                $uri = is_array($v['uri']) ? reset($v['uri']) : $v['uri'];
            }
            if (!isset($v['label'])) {
                $label = null;
            } else {
                $label = is_array($v['label']) ? reset($v['label']) : $v['label'];
            }
            if (isset($v['type'])) {
                $type = is_array($v['type']) && reset($v['type']) ? reset($v['type']) : $datatype;
            } else {
                $type = $datatype;
            }
            return [
                'source' => $v['source'],
                'property_id' => $sourceId,
                'type' => $type,
                'value' => $label,
                'uri' => $uri,
                // TODO Check and normalize property language.
                'lang' => null,
                'value_resource_id' => null,
                // TODO Try to keep original is_public.
                'is_public' => 1,
            ];
        }, array_filter($currentMapping, $single));

        $this->storeMappingTable($mapper);

        return true;
    }

    protected function prepareListForValueSuggest(array $properties): ?array
    {
        if (empty($properties)) {
            $this->logger->info(
                'Operation "{action}": the list of properties is empty.', // @translate
                ['action' => $this->operationName]
            );
            return null;
        }

        // Get the list of unique values.
        // TODO Only literal: the already mapped values (label + uri) can be used as mapping, but useless for a new database.
        // For example, when authors are created in a previous step, foaf:name
        // is always literal.
        $sql = <<<'SQL'
SELECT DISTINCT
    `value`.`value` AS `v`,
    GROUP_CONCAT(DISTINCT `value`.`resource_id` ORDER BY `value`.`resource_id` SEPARATOR ' ') AS r
FROM `value`
JOIN `_temporary_value`
    ON `_temporary_value`.`id` = `value`.`id`
JOIN `item`
    ON `item`.`id` = `value`.`resource_id`
WHERE
    `value`.`property_id` IN (:property_ids)
    AND (`value`.`type` = "literal" OR `value`.`type` = "" OR `value`.`type` IS NULL)
    AND `value`.`value` <> ""
    AND `value`.`value` IS NOT NULL
GROUP BY `v`
ORDER BY `v`;

SQL;
        $bind = [
            'property_ids' => $properties,
        ];
        $types = [
            'property_ids' => $this->connection::PARAM_INT_ARRAY,
        ];
        $stmt = $this->connection->executeQuery($sql, $bind, $types);
        // Fetch by key pair is not supported by doctrine 2.0.
        $list = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $list = array_column($list, 'r', 'v');
        $totalList = count($list);

        if (!$totalList) {
            $this->logger->info(
                'Operation "{action}": no value to map for terms "{terms}".', // @translate
                ['action' => $this->operationName, 'terms' => implode('", "', array_keys($properties))]
            );
            return [];
        }

        $this->logger->info(
            'Operation "{action}": mapping {total} unique literal values for terms "{terms}".', // @translate
            ['action' => $this->operationName, 'total' => count($list), 'terms' => implode('", "', array_keys($properties))]
        );

        return $list;
    }

    protected function prepareValueSuggestMapping(array $params): void
    {
        // If exists, the original table is already loaded (no mix mappings).
        if (isset($this->valueSuggestMappings[$params['name']])) {
            return;
        }

        // Create a mapping for checking and future reimport.
        $table = $this->loadTable($params['mapping']) ?: [];

        $datatype = $params['datatype'] ?? null;

        // Keep only the needed columns.
        $columns = [
            'source' => null,
            'items' => null,
            'uri' => null,
            'label' => null,
            'type' => null,
            'info' => null,
        ];
        if ($datatype && !empty($this->configs[$datatype]['headers'])) {
            $columns += array_fill_keys($this->configs[$datatype]['headers'], null);
        }
        if (!empty($params['properties'])) {
            $columns += array_fill_keys(array_keys($params['properties']), null);
        }
        unset($columns['identifier']);
        $table = array_map(function ($v) use ($columns) {
            return array_replace($columns, array_intersect_key($v, $columns));
        }, $table);

        if (isset($params['prefix']) && strlen($params['prefix'])) {
            $prefix = $params['prefix'];
            $table = array_map(function ($v) use ($prefix) {
                if (!empty($v['uri']) && strpos($v['uri'], $prefix) !== 0) {
                    $v['uri'] = $prefix . $v['uri'];
                }
                return $v;
            }, $table);
        }

        // Prepare the keys to search instantly in the mapping.
        $this->valueSuggestMappings[$params['name']] = array_combine(array_column($table, 'source'), $table);
    }

    protected function updateValueSuggestMapping(array $params, array $list): void
    {
        $currentMapping = &$this->valueSuggestMappings[$params['name']];
        $originalDataType = $datatype = $params['datatype'];
        $sourceTerm = $params['source'];

        $columns = [
            'source',
            'items',
            'uri',
            'label',
            'type',
            'info',
        ];
        if ($datatype && !empty($this->configs[$datatype]['headers'])) {
            $extraColumns = array_values(array_unique(array_diff($this->configs[$datatype]['headers'], $columns)));
        } else {
            $extraColumns = [];
        }
        if (!empty($params['properties'])) {
            $extraColumns = array_values(array_unique(array_merge($extraColumns, array_keys($params['properties']))));
        }
        $position = array_search('identifier', $extraColumns);
        if ($position !== false) {
            unset($extraColumns[$position]);
        }

        $params['extra_columns'] = $extraColumns;

        // Update the mapping for missing values, using the suggesters.
        $count = 0;
        $countSingle = 0;
        foreach ($list as $value => $resourceIds) {
            ++$count;
            $value = trim((string) $value);

            // In all cases, update the resource ids for future check.
            if (empty($currentMapping[$value]['items'])) {
                $ids = $resourceIds;
            } else {
                $ids = array_filter(explode(' ', str_replace('#', ' ', $currentMapping[$value]['items']) . ' ' . $resourceIds));
                sort($ids);
                $ids = implode(' ', array_unique($ids));
            }

            // Check if the value is already mapped with one or multiple uris.
            // It may have been checked in a previous step with an empty array.
            // An empty string means a value missing in the mapping of the
            // config, else it is an empty array.
            // TODO Fill missing data too? Not here.
            if (isset($currentMapping[$value]['uri']) && $currentMapping[$value]['uri'] !== '') {
                continue;
            }

            if (isset($currentMapping[$value]['uri'])
                && $currentMapping[$value]['uri'] === ''
                && !empty($currentMapping[$value]['type'])
            ) {
                $datatype = $currentMapping[$value]['type'];
            } else {
                $datatype = $originalDataType;
            }

            // Complete the new mapping.
            $currentMapping[$value]['source'] = $value;
            $currentMapping[$value]['items'] = $resourceIds;
            $currentMapping[$value]['uri'] = [];
            $currentMapping[$value]['label'] = [];
            $currentMapping[$value]['info'] = [];
            $currentMapping[$value]['type'] = [];
            foreach ($extraColumns as $column) {
                $currentMapping[$value][$column] = [];
            }

            $result = $this->valueSuggestQuery($value, $datatype, $params);

            if ($result === null) {
                $this->logger->err(
                    'Operation "{action}": connection issue: skipping next requests for data type "{type}" (term {term}).', // @translate
                    ['action' => $this->operationName, 'type' => $datatype, 'term' => $sourceTerm]
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
                if (isset($r['data']['type'])) {
                    $currentMapping[$value]['type'][] = $r['data']['type'];
                }
                $currentMapping[$value]['info'][] = $r['data']['info'];
                foreach ($extraColumns as $column) {
                    if (isset($r['data'][$column])) {
                        $currentMapping[$value][$column][] = $r['data'][$column];
                    }
                }
            }

            if (count($result) === 1) {
                ++$countSingle;
            }

            if ($count % 100 === 0) {
                $this->logger->info(
                    'Operation "{action}": {count}/{total} unique values for term "{term}" processed, {singles} values with a single uri.', // @translate
                    ['action' => $this->operationName, 'count' => $count, 'total' => count($list), 'term' => $sourceTerm, 'singles' => $countSingle]
                );
                if ($this->isErrorOrStop()) {
                    return;
                }
            }
        }

        $this->logger->notice(
            'Operation "{action}": {total} unique values for data type "{type}" (term "{term}") processed, {singles} new values updated with a single uri.', // @translate
            ['action' => $this->operationName, 'total' => count($list), 'type' => $datatype, 'term' => $sourceTerm, 'singles' => $countSingle]
        );
    }

    protected function processValuesTransformUpdate(): void
    {
        $this->operationSqls[] = <<<'SQL'
# Update values according to the temporary table.
UPDATE `value`
JOIN `_temporary_value`
    ON `_temporary_value`.`id` = `value`.`id`
JOIN `_temporary_mapper`
    ON `_temporary_mapper`.`property_id` = `value`.`property_id`
    AND `_temporary_mapper`.`source` = `value`.`value`
SET
    `value`.`property_id` = `_temporary_mapper`.`property_id`,
    `value`.`value_resource_id` = `_temporary_mapper`.`value_resource_id`,
    `value`.`type` = `_temporary_mapper`.`type`,
    `value`.`lang` = `_temporary_mapper`.`lang`,
    `value`.`value` = `_temporary_mapper`.`value`,
    `value`.`uri` = `_temporary_mapper`.`uri`,
    `value`.`is_public` = `_temporary_mapper`.`is_public`
;
SQL;
    }

    protected function processValuesTransformInsert(array $propertyIds = []): void
    {
        // The property may be different between value and temporary mapper:
        // the new values are created from a list of values.
        $properties = count($propertyIds)
            ? 'WHERE
    `value`.`property_id` IN (' . implode(', ', $propertyIds) . ')'
            : '';

        $this->operationSqls[] = <<<SQL
INSERT INTO `value`
    (`resource_id`, `property_id`, `value_resource_id`, `type`, `lang`, `value`, `uri`, `is_public`)
SELECT DISTINCT
    `value`.`resource_id`,
    `_temporary_mapper`.`property_id`,
    `_temporary_mapper`.`value_resource_id`,
    `_temporary_mapper`.`type`,
    `_temporary_mapper`.`lang`,
    `_temporary_mapper`.`value`,
    `_temporary_mapper`.`uri`,
    `_temporary_mapper`.`is_public`
FROM `value`
JOIN `_temporary_value`
    ON `_temporary_value`.`id` = `value`.`id`
JOIN `_temporary_mapper`
    ON `_temporary_mapper`.`source` = `value`.`value`
$properties
;
SQL;
    }

    protected function processValuesTransformReplace(): void
    {
        // To store the previous max value id is the simplest way to remove
        // updated values without removing other ones.
        // This max value id is saved in the settings for simplicity.
        $random = $this->operationRandoms[$this->operationIndex];
        $this->operationSqls[] = <<<SQL
# Explode values according to the temporary table (step 1/4).
INSERT INTO `setting`
    (`id`, `value`)
SELECT
    "bulkimport_max_value_id_$random",
    MAX(`value`.`id`)
FROM `value`
;
SQL;

        $this->operationSqls[] = <<<SQL
# Explode values according to the temporary table (step 2/4).
INSERT INTO `value`
    (`resource_id`, `property_id`, `value_resource_id`, `type`, `lang`, `value`, `uri`, `is_public`)
SELECT DISTINCT
    `value`.`resource_id`,
    `_temporary_mapper`.`property_id`,
    `_temporary_mapper`.`value_resource_id`,
    `_temporary_mapper`.`type`,
    `_temporary_mapper`.`lang`,
    `_temporary_mapper`.`value`,
    `_temporary_mapper`.`uri`,
    `_temporary_mapper`.`is_public`
FROM `value`
JOIN `_temporary_value`
    ON `_temporary_value`.`id` = `value`.`id`
JOIN `_temporary_mapper`
    ON `_temporary_mapper`.`property_id` = `value`.`property_id`
    AND `_temporary_mapper`.`source` = `value`.`value`
;
SQL;

        $this->operationSqls[] = <<<SQL
# Explode values according to the temporary table (step 3/4).
DELETE `value`
FROM `value`
JOIN `_temporary_value`
    ON `_temporary_value`.`id` = `value`.`id`
JOIN `_temporary_mapper`
    ON `_temporary_mapper`.`property_id` = `value`.`property_id`
    AND `_temporary_mapper`.`source` = `value`.`value`
JOIN `setting`
WHERE
    `value`.`id` <= `setting`.`value`
    AND `setting`.`id` = "bulkimport_max_value_id_$random"
    AND `setting`.`value` IS NOT NULL
    AND `setting`.`value` > 0
;

SQL;
        $this->operationSqls[] = <<<SQL
# Explode values according to the temporary table (step 4/4).
DELETE `setting`
FROM `setting`
WHERE
    `setting`.`id` = "bulkimport_max_value_id_$random"
;
SQL;
    }

    /**
     * Update the template according to the data type of the identifier.
     *
     * @todo Limit values when needed.
     */
    protected function processUpdateTemplatesFromDataTypes(array $params): void
    {
        $term = empty($params['properties']['identifier']) ? 'dcterms:identifier' : $params['properties']['identifier'];
        $propertyId = $this->getPropertyId($term);
        if (empty($propertyId)) {
            $this->logger->err(
                'The operation "{action}" requires a valid property to set templates: "{term}" does not exist.', // @translate
                ['action' => $this->operationName, 'term' => $term]
            );
            return;
        }

        if (empty($params['identifier_to_templates_and_classes'])) {
            $this->logger->err(
                'A list of templates is required to update templates.' // @translate
            );
            return;
        }

        $templates = $params['identifier_to_templates_and_classes'];
        $templateIds = [];
        $templateClassIds = [];
        $errors = [];
        foreach ($templates as $datatype => $template) {
            if (!$template) {
                $templateIds[$datatype] = null;
                $templateClassIds[$datatype] = null;
            } else {
                $templateId = $this->bulk->getResourceTemplateId($template);
                if ($templateId) {
                    $templateIds[$datatype] = $templateId;
                    $templateClassIds[$datatype] = $this->bulk->getResourceTemplateClassId($templateId);
                } else {
                    $errors[] = $template;
                }
            }
        }

        if (count($errors)) {
            $this->logger->err(
                'The operation "{action}" has invalid templates: "{templates}".', // @translate
                ['action' => $this->operationName, 'templates' => implode('", "', $errors)]
            );
            return;
        }

        if (array_key_exists('', $templateIds) || array_key_exists(0, $templateIds)) {
            $destination = $templateIds[''] ?? $templateIds[0];
            $destinationClass = $this->bulk->getResourceTemplateClassId($destination);
            $destinationClass = $destination && $destinationClass ? $destinationClass : 'NULL';
            $destination = $destination ?: 'NULL';
            $whereDestination = '';
        } elseif (count(array_unique($templateIds)) === 1) {
            $destination = reset($templateIds) ?: 'NULL';
            $destinationClass = $this->bulk->getResourceTemplateClassId(reset($templateIds)) ?: 'NULL';
            $types = [];
            foreach ($templateIds as $datatype => $templateId) {
                $types[] = $this->connection->quote($datatype);
            }
            $whereDestination = '    AND `value`.`type` IN ("' . implode('", "', $types) . '")';
        } else {
            $destination = "    CASE\n";
            foreach ($templateIds as $datatype => $templateId) {
                $templateId = $templateId ?: 'NULL';
                $type = $this->connection->quote($datatype);
                $destination .= "        WHEN `value`.`type` = $type THEN $templateId\n";
            }
            $destination .= "    ELSE `resource`.`resource_template_id`\n";
            $destination .= '    END';

            $destinationClass = "    CASE\n";
            foreach ($templateClassIds as $datatype => $templateClassId) {
                $templateClassId = $templateClassId ?: 'NULL';
                $type = $this->connection->quote($datatype);
                $destinationClass .= "        WHEN `value`.`type` = $type THEN $templateClassId\n";
            }
            $destinationClass .= "    ELSE `resource`.`resource_class_id`\n";
            $destinationClass .= '    END';
            $whereDestination = '';
        }

        // Don't limit values, because values are new.
        $this->operationSqls[] = <<<SQL
# Update values according to the temporary table.
UPDATE `resource`
JOIN `value`
    ON `value`.`resource_id` = `resource`.`id`
JOIN `_temporary_mapper`
    ON `_temporary_mapper`.`property_id` = `value`.`property_id`
    AND `_temporary_mapper`.`source` = `value`.`value`
SET
    `resource`.`resource_template_id` = $destination,
    `resource`.`resource_class_id` = $destinationClass
WHERE
    `value`.`property_id` = $propertyId
    $whereDestination
;
SQL;
    }

    protected function processCreateResources(array $params): void
    {
        // TODO Factorize with createEmptyResource().

        // TODO Use the right owner.
        $ownerIdOrNull = $this->owner ? $this->ownerId : 'NULL';
        if (isset($params['template'])) {
            $templateId = $this->bulk->getResourceTemplateId($params['template']) ?? 'NULL';
            $classId = $this->bulk->getResourceTemplateClassId($params['template']) ?? 'NULL';
        } else {
            $templateId = 'NULL';
            $classId = 'NULL';
        }
        if (isset($params['class'])) {
            $classId = $this->bulk->getResourceClassId($params['class']) ?? 'NULL';
        }
        $resourceType = empty($params['resource_type'])
            ? \Omeka\Entity\Item::class
            : $this->bulk->normalizeResourceType($params['resource_type']) ?? \Omeka\Entity\Item::class;
        if ($resourceType === \Omeka\Entity\Media::class) {
            $this->logger->err(
                'The operation "{action}" cannot create media currently.', // @translate
                ['action' => $this->operationName]
            );
            return;
        }
        $isPublic = (int) (bool) ($params['is_public'] ?? 1);

        $quotedResourceType = $this->connection->quote($resourceType);

        $random = $this->operationRandoms[$this->operationIndex];

        $this->operationSqls[] = <<<SQL
# Create resources.
INSERT INTO `resource`
    (`owner_id`, `resource_class_id`, `resource_template_id`, `is_public`, `created`, `modified`, `resource_type`, `thumbnail_id`, `title`)
SELECT DISTINCT
    $ownerIdOrNull,
    $classId,
    $templateId,
    $isPublic,
    "$this->currentDateTimeFormatted",
    NULL,
    $quotedResourceType,
    NULL,
    CONCAT("$random ", `_temporary_mapper`.`source`)
FROM `_temporary_mapper`
;
SQL;

    $position = strlen($random) + 2;
    $this->operationSqls[] = <<<SQL
# Store new resource ids to speed next steps. and to remove random titles.
DROP TABLE IF EXISTS `_temporary_new_resource`;
CREATE TABLE `_temporary_new_resource` (
    `id` int(11) NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `_temporary_new_resource`
    (`id`)
SELECT DISTINCT
    `resource`.`id`
FROM `resource` AS `resource`
WHERE `resource`.`title` LIKE "$random %"
ON DUPLICATE KEY UPDATE
    `id` = `_temporary_new_resource`.`id`
;
UPDATE `resource`
JOIN `_temporary_new_resource`
    ON `_temporary_new_resource`.`id` = `resource`.`id`
SET
    `resource`.`title` = SUBSTRING(`resource`.`title`, $position)
WHERE
    `resource`.`title` LIKE "$random %"
;

SQL;

    if ($resourceType === \Omeka\Entity\Item::class) {
        $this->operationSqls[] = <<<SQL
# Create items for created resources.
INSERT INTO `item`
    (`id`)
SELECT DISTINCT
    `resource`.`id`
FROM `_temporary_new_resource` AS `resource`
ON DUPLICATE KEY UPDATE
    `id` = `item`.`id`
;
SQL;
    } elseif ($resourceType === \Omeka\Entity\ItemSet::class) {
        $this->operationSqls[] = <<<SQL
# Create item sets for created resources.
INSERT INTO `item_set`
    (`id`, `is_open`)
SELECT DISTINCT
    `resource`.`id`,
    1
FROM `_temporary_new_resource` AS `resource`
ON DUPLICATE KEY UPDATE
    `id` = `item_set`.`id`,
    `is_open` = `item_set`.`is_open`
;
SQL;
    } elseif ($resourceType === \Omeka\Entity\Media::class) {
        $this->operationSqls[] = <<<SQL
# Create medias for created resources.
INSERT INTO `media`
    (`id`, `item_id`, `ingester`, `renderer`, `has_original`, `has_tumbnails`)
SELECT DISTINCT
    `resource`.`id`,
    0,
    "",
    "",
    0,
    0
FROM `_temporary_new_resource` AS `resource`
ON DUPLICATE KEY UPDATE
    `id` = `media`.`id`
;
SQL;
    }

        $this->operationSqls[] = <<<SQL
# Add the main value to new resources.
INSERT INTO `value`
    (`resource_id`, `property_id`, `value_resource_id`, `type`, `lang`, `value`, `uri`, `is_public`)
SELECT DISTINCT
    `resource`.`id`,
    `_temporary_mapper`.`property_id`,
    `_temporary_mapper`.`value_resource_id`,
    `_temporary_mapper`.`type`,
    `_temporary_mapper`.`lang`,
    `_temporary_mapper`.`value`,
    `_temporary_mapper`.`uri`,
    `_temporary_mapper`.`is_public`
FROM `_temporary_mapper`
JOIN `resource`
    ON `resource`.`title` = `_temporary_mapper`.`source`
JOIN `_temporary_new_resource`
    ON `_temporary_new_resource`.`id` = `resource`.`id`
;
SQL;

    }

    protected function processCreateLinkForCreatedResourcesFromValues(array $params): bool
    {
        // TODO Factorize checks with prepareMappingTableFromValues().
        if (empty($params['mapping_properties'])) {
            $this->logger->err(
                'The operation "{action}" requires a mapping of properties.', // @translate
                ['action' => $this->operationName]
            );
            return false;
        }

        $properties = [];
        $errors = [];
        foreach ($params['mapping_properties'] as $source => $destination) {
            $sourceId = $this->bulk->getPropertyId($source);
            if (!$sourceId) {
                $errors[] = $source;
            }
            $destinationId = $this->bulk->getPropertyId($destination);
            if (!$destinationId) {
                $errors[] = $destination;
            }
            if ($sourceId && $destinationId) {
                $properties[$sourceId] = $destinationId;
            }
        }

        if (count($errors)) {
            $this->logger->err(
                'The operation "{action}" has invalid properties: "{terms}".', // @translate
                ['action' => $this->operationName, 'terms' => implode('", "', $errors)]
            );
            return false;
        }

        if (empty($params['reciprocal'])) {
            $reciprocalId = null;
        } else {
            $reciprocalId = $this->getPropertyId($params['reciprocal']);
            if (empty($reciprocalId)) {
                $this->logger->err(
                    'The operation "{action}" specifies an invalid reciprocal property: "{term}".', // @translate
                    ['action' => $this->operationName, 'term' => $params['reciprocal']]
                );
                return false;
            }
        }

        $sourceIds = implode(', ', array_keys($properties));
        $type = $params['type'] ?? 'resource:item';
        $quotedType = $this->connection->quote($type);
        // TODO Use the template for is_public.
        $isPublic = (int) (bool) ($params['is_public'] ?? 1);

        [$sqlExclude, $sqlExcludeWhere] = $this->transformHelperExcludeStart($params);

        $this->operationSqls[] = <<<SQL
# Link created resources.
UPDATE `value`
JOIN `_temporary_value`
    ON `_temporary_value`.`id` = `value`.`id`
JOIN `resource`
    ON `resource`.`title` = `value`.`value`
JOIN `_temporary_new_resource`
    ON `_temporary_new_resource`.`id` = `resource`.`id`
$sqlExclude
SET
    `value`.`value_resource_id` = `resource`.`id`,
    `value`.`type` = $quotedType,
    `value`.`value` = NULL,
    `value`.`uri` = NULL,
    `value`.`lang` = NULL
WHERE
    `value`.`property_id` IN ($sourceIds)
    $sqlExcludeWhere
;
SQL;

        if ($reciprocalId) {
            $this->operationSqls[] = <<<SQL
# Create reciprocal link for created resources.
INSERT INTO `value`
    (`resource_id`, `property_id`, `value_resource_id`, `type`, `is_public`)
SELECT DISTINCT
    `value`.`resource_id`,
    $reciprocalId,
    `resource`.`id`,
    $quotedType,
    $isPublic
FROM `value`
JOIN `_temporary_value`
    ON `_temporary_value`.`id` = `value`.`id`
JOIN `resource`
    ON `resource`.`title` = `value`.`value`
JOIN `_temporary_new_resource`
    ON `_temporary_new_resource`.`id` = `resource`.`id`
$sqlExclude
WHERE
    `value`.`property_id` IN ($sourceIds)
    $sqlExcludeWhere
;
SQL;
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
        if (in_array($datatype, ['valuesuggest:idref:author', 'valuesuggest:idref:person', 'valuesuggest:idref:corporation', 'valuesuggest:idref:conference'])) {
            return $this->valueSuggestQueryIdRefAuthor($value, $datatype, $options);
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
     * @link https://www.iso.org/obp/ui/fr/
     *
     * @todo Prepare list of countries with geonames translations (only English and French currently).
     */
    protected function valueSuggestQueryGeonames(string $value, string $datatype, array $options = [], int $loop = 0): ?array
    {
        static $countries;
        static $language2;
        static $searchType;

        if (is_null($language2)) {
            $countries = $this->loadTableAsKeyValue('countries-iso-3166', 'Code alpha-2');
            $language2 = $this->getParam('language_2') ?: '';
            $searchType = $this->getParam('geonames_search') ?: 'strict';
        }

        if ($value === '') {
            return [];
        }

        /*
        // The list of possible arguments for main geonames search.
        $geonamesArguments = [
            // 'q',
            // 'name',
            // 'name_equals',
            'name_startsWith',
            'country',
            'countryBias',
            'continentCode',
            'adminCode1',
            'adminCode2',
            'adminCode3',
            'adminCode4',
            'adminCode5',
            'featureClass',
            'featureCode',
            'cities',
            'lang',
            'type',
            'style',
            'isNameRequired',
            'tag ',
            'operator',
            'charset',
            'fuzzy',
            'east',
            'west',
            'north',
            'south',
            'searchlang',
            'orderby',
            'inclBbox',
        ];
        */

        // Prepare the default search.
        $originalValue = $value;

        // Query key must be "q", "name" or "name_equals".
        $queryKey = 'name_equals';

        /** @see https://www.geonames.org/export/geonames-search.html */
        $query = [
            $queryKey => $value,
            'name_startsWith' => $value,
            'isNameRequired' => 'true',
            // Fuzzy = 1 means no fuzzy…
            'fuzzy' => 1,
            // Geographical country code ISO-3166 (not political country: Guyane is GF).
            // 'country' => 'FR',
            // 'countryBias' => 'FR',
            // 'continentCode' => 'EU',
            'maxRows' => 20,
            'lang' => strtoupper($language2),
            // TODO Use your own or a privacy aware username for geonames, not "google'.
            'username' => 'google',
        ];

        // First, quick check if the value is a country.
        $lowerValue = mb_strtolower($value);
        if (isset($countries[$lowerValue])) {
            $query['country'] = $countries[$lowerValue];
        }
        // Improve the search if a format is set.
        elseif (!empty($options['formats'])) {
            foreach ($options['formats'] as $format) {
                if (empty($format['arguments'])) {
                    // Skip.
                    continue;
                } elseif (empty($format['separator'])) {
                    // Default case.
                    $argument = is_array($format['arguments']) ? reset($format['arguments']) : $format['arguments'];
                    if ($argument === 'country' || $argument === 'countryBias') {
                        if (isset($countries[$lowerValue])) {
                            $query[$argument] = $countries[$lowerValue];
                        }
                    } else {
                        $query[$argument] = $value;
                    }
                    break;
                } elseif (mb_strpos($value, $format['separator']) !== false) {
                    // Manage location like "France | Paris" or "Paris | France".
                    $valueList = array_map('trim', explode('|', $value));
                    $arguments = is_array($format['arguments']) ? $format['arguments'] : [$format['arguments']];
                    foreach ($arguments as $argument)  {
                        $v = array_shift($valueList);
                        if (is_null($v)) {
                            break;
                        }
                        if ($v === '') {
                            continue;
                        }
                        if ($argument === 'country' || $argument === 'countryBias') {
                            $query[$argument] = $countries[mb_strtolower($v)] ?? $v;
                        } else {
                            $query[$argument] = $v;
                        }
                    }
                    if (isset($query['location'])) {
                        $query[$queryKey] = $query['location'];
                        $query['name_startsWith'] = $query['location'];
                        unset($query['location']);
                    }
                    break;
                }
            }
        }

        $query = array_filter($query, 'strlen');

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

    /**
     * Allow to search authors (person, corporation, conference) in all idref
     * sub-bases simultaneously.
     *
     * @see \ValueSuggest\Suggester\IdRef\IdRefSuggestAll::getSuggestions()
     * @link https://documentation.abes.fr/aideidrefdeveloppeur/index.html#presentation
     */
    protected function valueSuggestQueryIdRefAuthor(string $value, string $datatype, array $options = [], int $loop = 0): ?array
    {
        $cleanValue = strpos($value, ' ')
            ? $value
            : '(' . implode(' AND ', array_filter(explode(' ', $value), 'strlen')) . ')';

        if ($datatype === 'valuesuggest:idref:person') {
            $q = "persname_t:$cleanValue";
        } elseif ($datatype === 'valuesuggest:idref:corporation') {
            $q = "corpname_t:$cleanValue";
        } elseif ($datatype === 'valuesuggest:idref:conference') {
            $q = "conference_t:$cleanValue";
        } else {
            $q = "persname_t:$cleanValue OR corpname_t:$cleanValue OR conference_t:$cleanValue";
        }

        $extraColumns = $options['extra_columns'] ?? [];
        $idrefMap = [
            'dcterms:date' => 'datenaissance_dt',
            'dcterms:created' => 'datenaissance_dt',
            'bio:birth' => 'datenaissance_dt',
            'bio:death' => 'datemort_dt',
            'foaf:lastName' => 'nom_s',
            'foaf:family_name' => 'nom_s',
            'foaf:familyName' => 'nom_s',
            'foaf:firstName' => 'prenom_s',
            'foaf:givenName' => 'prenom_s',
            'foaf:givenname' => 'prenom_s',
        ];
        $extraMap = array_intersect_key($idrefMap, array_flip($extraColumns));
        $fields = array_unique(array_merge(['id', 'ppn_z', 'recordtype_z', 'affcourt_z'], $extraMap));

        $query = [
            'q' => $q,
            'wt' => 'json',
            'version' => '2.2',
            'start' => 0,
            'rows' => 30,
            'sort' => 'score desc',
            'indent' => 'on',
            'fl' => implode(',', $fields),
        ];
        $url = 'https://www.idref.fr/Sru/Solr';
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
                return $this->valueSuggestQueryIdRefAuthor($value, $datatype, $options, ++$loop);
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

        // Parse the JSON response.
        $suggestions = [];
        $results = json_decode($response->getBody(), true);
        if (empty($results['response']['docs'])) {
            return [];
        }

        // Record type : http://documentation.abes.fr/aideidrefdeveloppeur/index.html#filtres
        $recordTypes = [
            'a' => 'valuesuggest:idref:person',
            'b' => 'valuesuggest:idref:corporation',
            's' => 'valuesuggest:idref:conference',
        ];

        // Check the result key.
        foreach ($results['response']['docs'] as $result) {
            if (empty($result['ppn_z'])) {
                continue;
            }
            // "affcourt" may be not present in some results (empty words).
            if (isset($result['affcourt_r'])) {
                $value = is_array($result['affcourt_r']) ? reset($result['affcourt_r']) : $result['affcourt_r'];
            } elseif (isset($result['affcourt_z'])) {
                $value = is_array($result['affcourt_z']) ? reset($result['affcourt_z']) : $result['affcourt_z'];
            } else {
                $value = $result['ppn_z'];
            }
            $recordType = empty($result['recordtype_z']) || !isset($recordTypes[$result['recordtype_z']])
                ? 'valuesuggest:idref:person'
                : $recordTypes[$result['recordtype_z']];
            $suggestion = [
                'value' => $value,
                'data' => [
                    'uri' => 'https://www.idref.fr/' . $result['ppn_z'],
                    'info' => null,
                    'type' => $recordType,
                ],
            ];
            foreach ($extraMap as $term => $column) {
                switch ($column) {
                    // Idref ne renvoie que l'année et le reste est faux.
                    case 'datenaissance_dt':
                    case 'datemort_dt':
                        $suggestion['data'][$term] = isset($result[$column])
                            ? substr($result[$column], 0, 4)
                            : '';
                        break;
                    case 'nom_s':
                    case 'prenom_s':
                    default:
                        $suggestion['data'][$term] = isset($result[$column])
                            ? (is_array($result[$column]) ? reset($result[$column]) : $result[$column])
                            : '';
                        break;
                }
            }
            $suggestions[] = $suggestion;
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
        $columns = [
            'source',
            'items',
            'uri',
            'label',
            'type',
            'info',
        ];
        foreach ($this->valueSuggestMappings as $name => $mapper) {
            // Prepare the list of specific headers one time in order to save
            // the fetched data and the ones from the original file.
            $headers = [];
            foreach ($mapper as $map) {
                $headers = array_unique(array_merge($headers, array_keys($map)));
            }
            $headers = array_flip(array_diff($headers, $columns));
            unset($headers['']);
            unset($headers[0]);
            $headers = array_keys($headers);
            $this->saveValueSuggestMappingToOds($name, $mapper, $headers);
            $this->saveValueSuggestMappingToHtml($name, $mapper, $headers);
        }
    }

    protected function saveValueSuggestMappingToOds(string $name, array $mapper, array $extraColumns = []): void
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
            'type',
            'info',
        ];
        $extraColumns = array_values(array_unique(array_filter(array_diff($extraColumns, $headers))));
        $mostColumns = array_values(array_diff(array_merge($headers, $extraColumns), ['source', 'items']));
        $tableHeaders = array_values(array_merge($headers, $extraColumns));
        $emptyRow = array_fill_keys($tableHeaders, ['']);
        $emptyRow['source'] = '';
        $emptyRow['items'] = '';

        /** @var \Box\Spout\Common\Entity\Row $row */
        $row = WriterEntityFactory::createRowFromArray($tableHeaders, (new StyleBuilder())->setFontBold()->build());
        $spreadsheetWriter->addRow($row);

        $newStyle = (new StyleBuilder())->setBackgroundColor(Color::rgb(208, 228, 245))->build();

        $even = false;
        foreach ($mapper as $map) {
            // Skip useless data.
            if (empty($map['source'])) {
                continue;
            }

            // Fill the map with all columns.
            // There should be at least one uri to keep the row below.
            if (is_array($map['uri'])) {
                foreach ($mostColumns as $column) {
                    $map[$column] = isset($map[$column]) && count($map[$column])
                        ? $map[$column]
                        : [''];
                }
            }
            // In the case where a user mapped value was not updated (not found
            // in database or not migrated).
            else {
                foreach ($mostColumns as $column) {
                    $map[$column] = isset($map[$column]) ? [$map[$column]] : [''];
                }
            }

            // The order should be always the same.
            $map = array_replace($emptyRow, $map);

            $resources = '';
            $list = array_unique(array_filter(explode(' ', str_replace('#', ' ', $map['items']))));
            foreach (array_chunk($list, $this->outputByColumn) as $chunk) {
                foreach ($chunk as $id) {
                    // $resources .= sprintf('%sadmin/item/%d ', $baseUrl, $id);
                    $resources .= "#$id ";
                }
                // $resources .= "\n";
            }
            $resources = trim($resources);

            $data = [
                $map['source'],
                $resources,
            ];

            $dataBase = $data;
            foreach (array_keys($map['uri']) as $key) {
                $data = $dataBase;
                foreach ($mostColumns as $column) {
                    $data[] = $map[$column][$key] ?? '';
                }
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

    protected function saveValueSuggestMappingToHtml(string $name, array $mapper, array $extraColumns = []): void
    {
        $basePath = trim($this->job->getArg('base_path'), '/');
        $baseUrl = $this->job->getArg('base_url') . '/' . ($basePath ? $basePath . '/' : '');
        $filepath = $this->getOutputFilepath($name, 'html');
        $relativePath = $this->getOutputFilepath($name, 'html', true);

        $headers = [
            'source',
            'items',
            'uri',
            'label',
            'type',
            'info',
        ];
        $extraColumns = array_values(array_unique(array_filter(array_diff($extraColumns, $headers))));
        $mostColumns = array_values(array_diff(array_merge($headers, $extraColumns), ['source', 'items']));
        $moreColumns = array_values(array_diff(array_merge($headers, $extraColumns), ['source', 'items', 'uri']));
        $tableHeaders = array_values(array_merge($headers, $extraColumns));
        $emptyRow = array_fill_keys($tableHeaders, ['']);
        $emptyRow['source'] = '';
        $emptyRow['items'] = '';

        $this->prepareValueSuggestMappingToHtml($filepath, 'start', $name, $tableHeaders);

        $fp = fopen($filepath, 'ab');

        foreach ($mapper as $map) {
            // Skip useless data.
            if (empty($map['source'])) {
                continue;
            }

            // Fill the map with all columns.
            // There should be at least one uri to keep the row below.
            if (is_array($map['uri'])) {
                foreach ($mostColumns as $column) {
                    $map[$column] = isset($map[$column]) && count($map[$column])
                        ? $map[$column]
                        : [''];
                }
            }
            // In the case where a user mapped value was not updated (not found
            // in database or not migrated).
            else {
                foreach ($mostColumns as $column) {
                    $map[$column] = isset($map[$column]) ? [$map[$column]] : [''];
                }
            }

            // The order should be always the same.
            $map = array_replace($emptyRow, $map);

            $this->appendValueSuggestMappingToHtml($fp, $map, $baseUrl, $moreColumns);
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

    protected function prepareValueSuggestMappingToHtml(string $filepath, ?string $part = null, ?string $name = null, array $tableHeaders = []): void
    {
        if ($name) {
            $translate = $this->getServiceLocator()->get('ViewHelperManager')->get('translate');
            $title = htmlspecialchars(sprintf($translate('Mapping "%s"'), ucfirst($name)), ENT_NOQUOTES | ENT_HTML5);
        } else {
            $title = '';
        }

        $tableHeadersHtml = '';
        foreach ($tableHeaders as $header) {
            $tableHeadersHtml .= sprintf('                    <th scope="col">%s</th>', $header) . "\n";
        }
        $tableHeadersHtml = trim($tableHeadersHtml);

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
        table th,
        table td {
            min-width: 10%;
            width: auto;
            max-width: 25%;
        }
        </style>
    </head>
    <body>
        <h1>$title</h1>
        <table class="blueTable">
            <thead>
                <tr>
                    $tableHeadersHtml
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

    protected function appendValueSuggestMappingToHtml($fp, array $map, string $baseUrl, array $columns): void
    {
        // Don't repeat same source and items.
        $count = count($map['uri']);
        $rowspan = $count <= 1 ? '' : sprintf(' rowspan="%d"', $count);

        $resources = '';
        $list = array_unique(array_filter(explode(' ', str_replace('#', ' ', $map['items']))));
        foreach ($list as $id) {
            $resources .= sprintf(
                '<a href="%sadmin/item/%s" target="_blank">#%s</a>' . " \n",
                $baseUrl, $id, $id
            );
        }

        $html = "                <tr>\n";
        $html .= sprintf('                    <td scope="row"%s>%s</td>', $rowspan, htmlspecialchars($map['source'], ENT_NOQUOTES | ENT_HTML5)) . "\n";
        $html .= sprintf('                    <td%s>%s</td>', $rowspan, $resources) . "\n";

        $first = true;
        foreach ($map['uri'] as $key => $uri) {
            if ($first) {
                $first = false;
            } else {
                $html .= "                <tr>\n";
            }
            $code = (string) basename(rtrim($uri, '/'));
            $html .= sprintf('                    <td><a href="%s" target="_blank">%s</a></td>', htmlspecialchars($uri, ENT_NOQUOTES | ENT_HTML5), htmlspecialchars($code, ENT_NOQUOTES | ENT_HTML5)) . "\n";
            foreach ($columns as $column) {
                $value = $map[$column][$key] ?? '';
                $html .= sprintf('                    <td>%s</td>', htmlspecialchars($value, ENT_NOQUOTES | ENT_HTML5)) . "\n";
            }
            $html .= "                </tr>\n";
        }

        fwrite($fp, $html);
    }
}
