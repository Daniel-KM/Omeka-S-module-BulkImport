<?php declare(strict_types=1);

namespace BulkImport\Processor;

use DomDocument;
use DOMNodeList;
use DOMXPath;
use Laminas\Http\Client\Exception\ExceptionInterface as HttpExceptionInterface;
use Laminas\Http\ClientStatic;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Writer\Common\Creator\Style\StyleBuilder;
use OpenSpout\Writer\Common\Creator\WriterEntityFactory;

// use SimpleXMLElement;

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
     * The content is: $this->mappingsSourceUris[$name][$source][$uri] = $dataArray
     *
     * @var array
     */
    protected $mappingsSourceUris = [];

    /**
     * Store all known unique mappings between unique sources and the sources.
     *
     * It allows to get quickly the source key for the table `mappingsSourceUris`
     * with any known keys, for example in a prefilled table.
     *
     * @var array Associative array of single key and single source name.
     */
    protected $mappingsSourcesToSources = [];

    protected $mappingsSourceItems = [];

    protected $mappingsColumns = [];

    protected $transformIndex = 0;

    protected $operationName = [];

    protected $operationIndex = 0;

    protected $operationSqls = [];

    protected $operationExcludes = [];

    protected $operationRandoms = [];

    protected $operationPartialFlush = false;

    /**
     * Maximum number of resources to display in ods/html output.
     *
     * @var int
     */
    protected $outputByColumn = 10;

    protected function transformOperations(array $operations = []): void
    {
        $this->transformResetProcess();

        // TODO Move all check inside the preprocess.
        // TODO Use a transaction (implicit currently).
        // TODO Bind is not working with multiple queries.

        $hasPartialFlush = false;
        foreach ($operations as $index => $operation) {
            $this->operationName = $operation['action'];
            $this->operationIndex = ++$index;
            $this->operationRandoms[$this->operationIndex] = $this->randomString(8);
            $this->operationPartialFlush = false;

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

                case 'fill_resource':
                    $this->operationPartialFlush = true;
                    $result = $this->operationFillResource($operation['params']);
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
                    $this->operationPartialFlush = true;
                    $this->transformApplyOperations(true);
                    break;

                default:
                    $this->logger->err(
                        'The operation "{action}" is not managed currently.', // @translate
                        ['action' => $this->operationName]
                    );
                    return;
            }

            $hasPartialFlush = $hasPartialFlush || $this->operationPartialFlush;
        }

        if ($hasPartialFlush) {
            $this->transformHelperExcludeEnd();
        }

        $this->transformApplyOperations();
    }

    protected function transformResetProcess(): void
    {
        $this->operationSqls = [];
        $this->operationExcludes = [];
        $this->operationRandoms = [];
        $this->operationPartialFlush = false;
        // The temporary table may be missing.
        $this->storeMappingTablePrepare();
    }

    protected function transformApplyOperations(bool $flush = false): void
    {
        // Skip process when an error occurred.
        $this->operationSqls = array_filter($this->operationSqls);
        if (count($this->operationSqls) && !$flush) {
            $this->transformHelperExcludeEnd();
        }

        // Transaction is implicit.
        $this->connection->executeStatement(implode("\n", $this->operationSqls));

        if ($flush) {
            $this->operationSqls = [];
        }
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

        $sourceId = $this->bulk->getPropertyId($params['source']);
        if (empty($sourceId)) {
            $this->logger->err(
                'The operation "{action}" requires a valid source: "{term}" does not exist.', // @translate
                ['action' => $this->operationName, 'term' => $params['source']]
            );
            return false;
        }

        $sourceIdentifier = $params['identifier'] ?? 'dcterms:identifier';
        $sourceIdentifierId = $this->bulk->getPropertyId($sourceIdentifier);
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
        if (empty($params['source'])) {
            $this->logger->err(
                'The operation "{action}" requires a source.', // @translate
                ['action' => $this->operationName]
            );
            return false;
        }
        $propertyIdSource = $this->bulk->getPropertyId($params['source']);
        if (empty($propertyIdSource)) {
            $this->logger->err(
                'The operation "{action}" requires a valid source: "{term}" does not exist.', // @translate
                ['action' => $this->operationName, 'term' => $params['source']]
            );
            return false;
        }
        if (empty($params['destination'])) {
            // When replace by a table, it may be a multi-columns, so there may
            // be multiple destination properties.
            $propertyIdDest = null;
        } else {
            $propertyIdDest = $this->bulk->getPropertyId($params['destination']);
            if (empty($propertyIdDest)) {
                $this->logger->err(
                    'The operation "{action}" requires a valid destination or no destination: "{term}" does not exist.', // @translate
                    ['action' => $this->operationName, 'term' => $params['destination']]
                );
                return false;
            }
        }

        $result = $this->prepareMappingTable($params);
        if (!$result) {
            return false;
        }

        [$mapper, $hasMultipleDestinations] = $result;

        $this->storeMappingTable($mapper);

        $hasMultipleDestinations
            ? $this->processValuesTransformReplace('value', $propertyIdSource, $propertyIdDest)
            : $this->processValuesTransformUpdate('value', $propertyIdSource, $propertyIdDest);

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

        $sourceId = $this->bulk->getPropertyId($params['source']);
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
            $destinationId = $this->bulk->getPropertyId($params['destination']);
            if (empty($destinationId)) {
                $this->logger->err(
                    'The operation "{action}" requires a valid destination: "{term}" does not exist.', // @translate
                    ['action' => $this->operationName, 'term' => $params['destination']]
                );
                return false;
            }
        }

        $sourceIdentifier = $params['identifier'] ?? 'dcterms:identifier';
        $sourceIdentifierId = $this->bulk->getPropertyId($sourceIdentifier);
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
            $reciprocalId = $this->bulk->getPropertyId($params['reciprocal']);
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

    protected function operationFillResource(array $params): bool
    {
        if (empty($params['source'])) {
            $this->logger->err(
                'The operation "{action}" requires a source.', // @translate
                ['action' => $this->operationName]
            );
            return false;
        }

        $sourceId = $this->bulk->getPropertyId($params['source']);
        if (empty($sourceId)) {
            $this->logger->err(
                'The operation "{action}" requires a valid source: "{term}" does not exist.', // @translate
                ['action' => $this->operationName, 'term' => $params['source']]
            );
            return false;
        }
        $sourceTerm = $this->bulk->getPropertyTerm($sourceId);

        if (empty($params['properties'])) {
            $this->logger->err(
                'The operation "{action}" requires a list of properties.', // @translate
                ['action' => $this->operationName]
            );
            return true;
        }

        $params['properties'] = (array) $params['properties'];

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

        $list = $this->listDataValues([$sourceTerm => $sourceId], ['valuesuggest'], 'uri', false);

        $headers = [
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:60.0) Gecko/20100101 Firefox/116.0',
            'Content-Type' => 'application/xml',
            'Accept-Encoding' => 'gzip, deflate',
        ];

        /** @link http://documentation.abes.fr/sudoc/formats/unma/zones/120.htm */
        $unimarcGenders = [
            'a' => 'féminin',
            'b' => 'masculin',
            'u' => 'inconnu',
            // 'x' => 'non applicable',
        ];
        /** @link http://documentation.abes.fr/sudoc/formats/unma/zones/102.htm */
        $countries = $this->loadTableAsKeyValue('countries_iso-3166', 'URI', true);
        $countries['XX'] = 'Pays inconnu';
        $countries['ZZ'] = 'Pays multiples';

        if ($this->operationPartialFlush) {
            $this->storeMappingTablePrepare();
        }

        $mapper = [];
        $processed = 0;
        $totalNewData = 0;
        $succeed = 0;
        foreach ($list as $source) {
            ++$processed;
            //  TODO Manage other uris than idref.
            if (!$source || mb_substr($source, 0, 21) !== 'https://www.idref.fr/') {
                continue;
            }
            $url = mb_substr($source, -4) === '.xml' ? $source : $source . '.xml';
            try {
                $response = ClientStatic::get($url, [], $headers);
            } catch (HttpExceptionInterface $e) {
                $this->logger->err(
                    'Operation "{action}": connection issue: {exception}', // @translate
                    ['action' => $this->operationName, 'exception' => $e]
                );
                return false;
            }
            if (!$response->isSuccess()) {
                $this->logger->warn(
                    'Operation "{action}": connection issue for uri {url}.', // @translate
                    ['action' => $this->operationName, 'url' => $source]
                );
                continue;
            }

            $xml = $response->getBody();
            if (!$xml) {
                $this->logger->warn(
                    'Operation "{action}": no result for uri {url}.', // @translate
                    ['action' => $this->operationName, 'url' => $source]
                );
                continue;
            }

            // $simpleData = new SimpleXMLElement($xml, LIBXML_BIGLINES | LIBXML_COMPACT | LIBXML_NOBLANKS
            //     | /* LIBXML_NOCDATA | */ LIBXML_NOENT | LIBXML_PARSEHUGE);

            libxml_use_internal_errors(true);
            $doc = new DOMDocument();
            try {
                $doc->loadXML($xml);
            } catch (\Exception $e) {
                $doc = null;
            }
            if (!$doc) {
                $this->logger->warn(
                    'Operation "{action}": invalid xml for uri {url}.', // @translate
                    ['action' => $this->operationName, 'url' => $source]
                );
                continue;
            }

            $xpath = new DOMXPath($doc);

            $hasNew = false;
            foreach ($params['properties'] as $query => $property) {
                // Use evaluate() instead of query(), because DOMXPath->query()
                // and SimpleXML->xpath() don't work with xpath functions like "substring(xpath, 2)".
                $nodeList = $xpath->evaluate($query);
                if ($nodeList === false
                    || ($nodeList === '')
                    || ($nodeList instanceof DOMNodeList && !$nodeList->count())
                ) {
                    continue;
                }
                if (!is_object($nodeList)) {
                    $nodeList = [$nodeList];
                }
                foreach ($nodeList as $item) {
                    $value = trim((string) (is_object($item) ? $item->nodeValue : $item));
                    if ($value === '') {
                        continue;
                    }

                    // Fixes.
                    $uri = null;
                    $type = 'literal';
                    $lang = null;
                    switch ($property) {
                        default:
                            break;
                        case 'dcterms:language':
                            if ($value === 'fre') {
                                $value = 'fra';
                            }
                            $uri = 'http://id.loc.gov/vocabulary/iso639-2/' . $value;
                            $type = 'valuesuggest:lc:languages';
                            break;
                        case 'foaf:gender':
                            $value = $unimarcGenders[substr($value, 0, 1)] ?? null;
                            if (!$value) {
                                continue 2;
                            }
                            break;
                        case 'bio:birth':
                        case 'bio:death':
                            $value = mb_substr($value, 0, 1) === '-'
                                ? rtrim(mb_substr($value, 0, 5) . '-' . mb_substr($value, 5, 2) . '-' . mb_substr($value, 7, 2), '- ')
                                : rtrim(mb_substr($value, 0, 4) . '-' . mb_substr($value, 4, 2) . '-' . mb_substr($value, 6, 2), '- ');
                            break;
                        case 'bio:place':
                            if (isset($countries[$value])) {
                                $uri = $countries[$value];
                                $type = 'valuesuggest:geonames:geonames';
                            }
                            break;
                    }
                    $mapper[] = [
                        'source' => $source,
                        'property_id' => $properties[$property],
                        'value_resource_id' => null,
                        'type' => $type,
                        'value' => $value,
                        'uri' => $uri,
                        'lang' => $lang,
                        'is_public' => 1,
                    ];
                    $hasNew = true;
                    ++$totalNewData;
                }
            }

            if ($hasNew) {
                ++$succeed;
            }
            if ($processed % 100 === 0) {
                $this->logger->info(
                    'Operation "{action}": {count}/{total} uris processed, {succeed} with {total_data} new data.', // @translate
                    ['action' => $this->operationName, 'count' => $processed, 'total' => count($list), 'succeed' => $succeed, 'total_data' => $totalNewData]
                );
                if ($this->isErrorOrStop()) {
                    return true;
                }
                // To avoid memory issue, store into mapping table
                if ($this->operationPartialFlush) {
                    $this->storeMappingTable($mapper, true);
                    $mapper = [];
                }
            }
        }

        $this->storeMappingTable($mapper, $this->operationPartialFlush);

        $this->processValuesTransformInsert([$sourceId], 'uri');

        $this->removeMappingTables();

        $this->logger->notice(
            'Operation "{action}": {total} uris processed, {succeed} with {total_data} new data.', // @translate
            ['action' => $this->operationName, 'total' => count($list), 'succeed' => $succeed, 'total_data' => $totalNewData]
        );

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

        $sourceId = $this->bulk->getPropertyId($params['source']);
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

        $params['properties'] = (array) $params['properties'];

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

        $params['properties'] = (array) $params['properties'];

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

        $sqlFilters = '';
        if (!empty($params['filters'])) {
            $filters = &$params['filters'];
            if (!empty($filters['datatype'])) {
                $datatypes = (array) $filters['datatype'];
                if (count($datatypes)) {
                    $sqlFilters = 'AND (`value`.`type` IN (' . implode(', ', array_map([$this->connection, 'quote'], $datatypes)) . ')';
                    if (in_array('valuesuggest', $datatypes)) {
                        $sqlFilters .= ' OR `value`.`type` LIKE "valuesuggest%"';
                    }
                    if (in_array('', $datatypes)) {
                        $sqlFilters .= ' OR `value`.`type` IS NULL';
                    }
                    $sqlFilters .= ')';
                }
            }
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
    $sqlFilters
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

        $sourceId = $this->bulk->getPropertyId($params['source']);
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
            $destinationId = $this->bulk->getPropertyId($params['destination']);
            if (empty($destinationId)) {
                $this->logger->err(
                    'The operation "{action}" requires a valid destination: "{term}" does not exist.', // @translate
                    ['action' => $this->operationName, 'term' => $params['destination']]
                );
                return false;
            }
        }

        $sqlFilters = '';
        if (!empty($params['filters'])) {
            $filters = &$params['filters'];
            if (!empty($filters['datatype'])) {
                $datatypes = (array) $filters['datatype'];
                if (count($datatypes)) {
                    $sqlFilters = 'AND (`value`.`type` IN (' . implode(', ', array_map([$this->connection, 'quote'], $datatypes)) . ')';
                    if (in_array('valuesuggest', $datatypes)) {
                        $sqlFilters .= ' OR `value`.`type` LIKE "valuesuggest%"';
                    }
                    if (in_array('', $datatypes)) {
                        $sqlFilters .= ' OR `value`.`type` IS NULL';
                    }
                    $sqlFilters .= ')';
                }
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
        if (array_key_exists('value', $params)) {
            $updates['value'] = '`value`.`value` = ' . (strlen((string) $params['value']) ? $this->connection->quote($params['value']) : 'NULL');
        }
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
    $sqlFilters
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
        $binds['property_id_1'] = $this->bulk->getPropertyId($params['destination'][0]);
        $binds['property_id_2'] = $this->bulk->getPropertyId($params['destination'][1]);

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
            return $this->operationAppendRawValue($params);
        }

        $sourceId = $this->bulk->getPropertyId($params['source']);
        if (empty($sourceId)) {
            $this->logger->err(
                'The operation "{action}" requires a valid source: "{term}" does not exist.', // @translate
                ['action' => $this->operationName, 'term' => $params['source']]
            );
            return false;
        }

        $sourceTerm = $params['source'] = $this->bulk->getPropertyTerm($sourceId);

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
            $params['name'] = str_replace(':', '-', $datatype . '_' . $this->transformIndex);
        }

        // Only literal: the already mapped values (label + uri) can be used as
        // mapping, but useless for a new database.
        // For example, when authors are created in a previous step, foaf:name
        // is always literal.
        $list = $this->listDataValues([$sourceTerm => $sourceId], ['', 'literal'], 'value', true);
        if (is_null($list)) {
            return false;
        }
        $totalList = count($list);
        if (!$totalList) {
            return true;
        }

        // Prepare the current mapping if any from params or previous steps.
        $this->prepareMappingSourceUris($params);
        $this->updateMappingSourceUris($params, $list);
        $currentMapping = &$this->mappingsSourceUris[$params['name']];
        if (empty($currentMapping)) {
            return false;
        }

        if ($this->isErrorOrStop()) {
            return false;
        }

        // Fix exception.
        // Fake data type: person or corporation.
        if ($datatype === 'valuesuggest:idref:author') {
            $datatype = 'valuesuggest:idref:person';
        }

        // Save mapped values for the current values. It allows to manage
        // multiple steps, for example to store some authors as creators, then as
        // contributors.
        $mapper = [];
        // Complete the mapper with second sources.
        $currentMappingSourcesToSources = &$this->mappingsSourcesToSources[$params['name']];
        foreach ($currentMapping as $source => $rows) {
            // Only values with a source and a single uri can update resources.
            if (!$source || !count($rows) || count($rows) > 1) {
                continue;
            }
            $uriRow = key($rows);
            if (empty($uriRow)) {
                continue;
            }
            $row = reset($rows);
            // Create map for main source, but complete it with second sources.
            $sourcesToMap = array_unique(array_merge([$source], array_keys($currentMappingSourcesToSources, $source)));
            foreach ($sourcesToMap as $sourceToMap) {
                foreach ($fromTo as $from => $propertyId) {
                    if ($from === 'identifier') {
                        $uri = $uriRow;
                        $label = isset($row['label']) && $row['label'] !== '' ? $row['label'] : null;
                        $type = isset($row['type']) && $row['type'] !== '' ? $row['type'] : $datatype;
                    } elseif (isset($row[$from]) && $row[$from] !== '') {
                        $uri = null;
                        $label = $row[$from];
                        $type = 'literal';
                    } else {
                        continue;
                    }
                    $mapper[] = [
                        'source' => $sourceToMap,
                        'property_id' => $propertyId,
                        'value_resource_id' => null,
                        'type' => $type,
                        'value' => $label,
                        'uri' => $uri,
                        // TODO Check and normalize property language.
                        'lang' => null,
                        // TODO Try to keep original is_public.
                        'is_public' => 1,
                    ];
                }
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

    protected function operationAppendRawValue(array $params): bool
    {
        if (empty($params['properties'])) {
            $this->logger->err(
                'The operation "{action}" requires a list of properties to fill.', // @translate
                ['action' => $this->operationName]
            );
            return false;
        }

        if (!is_array($params['properties'])) {
            $params['properties'] = [$params['properties']];
        }
        $propertyIds = [];
        $errors = [];
        foreach ($params['properties'] as $to) {
            $toId = $this->bulk->getPropertyId($to);
            if ($toId) {
                $propertyIds[] = $toId;
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

        $quotedType = $this->connection->quote($params['datatype'] ?? 'literal');
        $quotedValue = isset($params['value'])
            ? $this->connection->quote($params['value'])
            : 'NULL';
        $quotedUri = isset($params['uri'])
            ? $this->connection->quote($params['uri'])
            : 'NULL';
        $quotedLang = isset($params['lang'])
            ? $this->connection->quote($params['lang'])
            : 'NULL';
        $isPublic = (int) (bool) ($params['is_public'] ?? true);

        $sqlExclude = '';
        if (!empty($params['filters'])) {
            $sqlExclude = <<<'SQL'
JOIN `resource`
    ON `resource`.`id` = `value`.`resource_id`
WHERE 1 = 1
SQL;
            foreach ($params['filters'] as $name => $value) {
                $quotedVal = $this->connection->quote($value);
                switch ($name) {
                    case 'class_id':
                    case 'resource_class_id':
                        $sqlExclude .= "\n    AND `resource`.`resource_class_id` = $quotedVal";
                        break;
                    case 'template_id':
                    case 'resource_template_id':
                        $sqlExclude .= "\n    AND `resource`.`resource_template_id` = $quotedVal";
                        break;
                    default:
                        // Nothing.
                        $this->logger->warn(
                            'Operation "{action}": The filter "{name}" is not managed to append a new value.', // @translate
                            ['action' => $this->operationName, 'name' => $name]
                        );
                        break;
                }
            }
        }

        foreach ($propertyIds as $propertyId) {
            // The "distinct" allows to add the value one time only.
            $this->operationSqls[] = <<<SQL
# Insert a raw value.
INSERT INTO `value`
    (`resource_id`, `property_id`, `value_resource_id`, `type`, `value`, `uri`, `lang`, `is_public`)
SELECT DISTINCT
    `value`.`resource_id`,
    $propertyId,
    NULL,
    $quotedType,
    $quotedValue,
    $quotedUri,
    $quotedLang,
    $isPublic
FROM `value`
JOIN `_temporary_value`
    ON `_temporary_value`.`id` = `value`.`id`
$sqlExclude
;
SQL;
        }

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

        // Fake data type: person or corporation.
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
            $params['name'] = str_replace(':', '-', $datatype . '_' . $this->transformIndex);
        }

        $isValueSuggest = substr($datatype, 0, 12) === 'valuesuggest';

        if ($isValueSuggest
            && (empty($params['mapping']) || !empty($params['partial_mapping']))
        ) {
            $result = $this->transformToValueSuggestWithApi($params);
            if (!$result) {
                return false;
            }

            // TODO It's currently not possible to convert datatype and property at the same time.
            $propertyIdSource = $params['source'];
            $propertyIdDest = $params['source'];

            $this->processValuesTransformUpdate('value', $propertyIdSource, $propertyIdDest);

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
                'Operation "{action}": mapping requires two columns at least (file "{file}").', // @translate
                ['action' => $this->operationName, 'file' => $params['mapping']]
            );
            return null;
        }

        // When mapping table is used differently, there may be no source.
        $hasSource = empty($params['no_source']);

        $firstKeys = array_keys($first);
        $sourceKey = array_search('source', $firstKeys);
        if ($hasSource && $sourceKey === false) {
            $this->logger->err(
                'Operation "{action}": mapping requires a column "source" (file "{file}").', // @translate
                ['action' => $this->operationName, 'file' => $params['mapping']]
            );
            return null;
        }

        // TODO The param "source" is not used here, but in other steps, so move check.
        if ($hasSource && empty($params['source'])) {
            $this->logger->err(
                'Operation "{action}": a source is required (mapping file "{file}").', // @translate
                ['action' => $this->operationName, 'file' => $params['mapping']]
            );
            return null;
        }

        $sourceId = $this->bulk->getPropertyId($params['source']);
        if ($hasSource && empty($sourceId)) {
            $this->logger->err(
                'Operation "{action}": a valid source is required: "{term}" does not exist (mapping file "{file}").', // @translate
                ['action' => $this->operationName, 'term' => $params['source'], 'file' => $params['mapping']]
            );
            return null;
        }

        if (!empty($params['source_term'])) {
            $saveSourceId = $this->bulk->getPropertyId($params['source_term']);
            if (empty($saveSourceId)) {
                $this->logger->err(
                    'Operation "{action}": an invalid property is set to save source: "{term}" (mapping file "{file}").', // @translate
                    ['action' => $this->operationName, 'term' => $params['source_term'], 'file' => $params['mapping']]
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
            if (empty($setting['property_id'])) {
                unset($settings[$term]);
            }
        }
        unset($setting);

        unset($firstKeys[$sourceKey]);

        /** @var \BulkImport\Mvc\Controller\Plugin\AutomapFields $automapFields */
        $automapFields = $this->getServiceLocator()->get('ControllerPluginManager')->get('automapFields');

        $destinations = [];
        $properties = [];
        $propertyIds = $this->bulk->getPropertyIds();
        $fields = $automapFields($firstKeys, ['output_full_matches' => true]);

        foreach (array_filter($fields) as $index => $fieldData) {
            foreach ($fieldData as $field) {
                if (!isset($propertyIds[$field['field']])) {
                    continue;
                }
                $field['header'] = $firstKeys[$index];
                $field['term'] = $field['field'];
                $field['property_id'] = $propertyIds[$field['field']];
                if (empty($field['datatype'])) {
                    $field['type'] = 'literal';
                } else {
                    // TODO Ideally, the value should be checked according to a list of datatypes.
                    $field['type'] = reset($field['datatype']);
                }
                $destinations[] = $field;
                $properties[$field['field']] = $field['property_id'];
            }
        }

        if (!count($destinations)) {
            $this->logger->warn(
                'There are no mapped properties for destination: "{terms}" (mapping file "{file}").', // @translate
                ['terms' => implode('", "', $firstKeys), 'file' => $params['mapping']]
            );
            return null;
        }

        $this->logger->notice(
            'The source {term} is mapped with {count} properties: "{terms}" (mapping file "{file}").', // @translate
            ['term' => $params['source'], 'count' => count($properties), 'terms' => implode('", "', array_keys($properties)), 'file' => $params['mapping']]
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

                $values = array_filter(array_map('trim', explode('|', $value)), 'strlen');
                if (!count($values)) {
                    continue;
                }

                foreach ($values as $value) {
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
                        case 'literal':
                        // TODO Log unmanaged type.
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
                        case 'xsd:integer':
                            if (!is_numeric($value) || ((int) $value) != $value) {
                                $this->logger->err(
                                    'For term "{term}", value "{value}" is not an integer.', // @translate
                                    ['term' => $params['source'], 'value' => $value]
                                );
                            }
                            break;

                        case 'numeric:timestamp':
                            // As a mapping table is used, we may assume clean data.
                            if (class_exists(\NumericDataTypes\DataType\Timestamp::class)) {
                                try {
                                    \NumericDataTypes\DataType\Timestamp::getDateTimeFromValue($value);
                                } catch (\InvalidArgumentException $e) {
                                    $this->logger->err(
                                        'For term "{term}", value "{value}" is not a valid iso 8601 date time.', // @translate
                                        ['term' => $params['source'], 'value' => $value]
                                    );
                                }
                            }
                            break;

                        case 'numeric:interval':
                            // As a mapping table is used, we may assume clean data.
                            if (class_exists(\NumericDataTypes\DataType\Interval::class)) {
                                // See \NumericDataTypes\DataType\Interval.
                                $intervalPoints = explode('/', $value);
                                if (2 !== count($intervalPoints)) {
                                    // There must be a <start> point and an <end> point.
                                    $this->logger->err(
                                        'For term "{term}", value "{value}" is not a valid iso 8601 date time interval, with a start point and a end point separated by a "/".', // @translate
                                        ['term' => $params['source'], 'value' => $value]
                                    );
                                } else {
                                    try {
                                        $dateStart = \NumericDataTypes\DataType\Interval::getDateTimeFromValue($intervalPoints[0]);
                                        $dateEnd = \NumericDataTypes\DataType\Interval::getDateTimeFromValue($intervalPoints[1], false);
                                    } catch (\InvalidArgumentException $e) {
                                        $this->logger->err(
                                            'For term "{term}", value "{value}" is not a valid iso 8601 date time interval, with a start point and a end point separated by a "/".', // @translate
                                            ['term' => $params['source'], 'value' => $value]
                                        );
                                    }
                                    if ($dateStart && $dateEnd) {
                                        $timestampStart = $dateStart['date']->getTimestamp();
                                        $timestampEnd = $dateEnd['date']->getTimestamp();
                                        if ($timestampStart >= $timestampEnd) {
                                            $this->logger->err(
                                                'For term "{term}", value "{value}" is invalid: the start date time should be before the end date time.', // @translate
                                                ['term' => $params['source'], 'value' => $value]
                                            );
                                        }
                                    }
                                }
                            }
                            break;

                        case 'numeric:duration':
                            // As a mapping table is used, we may assume clean data.
                            if (class_exists(\NumericDataTypes\DataType\Duration::class)) {
                                try {
                                    \NumericDataTypes\DataType\Duration::getDurationFromValue($value);
                                } catch (\InvalidArgumentException $e) {
                                    $this->logger->err(
                                        'For term "{term}", value "{value}" is not a valid iso 8601 duration.', // @translate
                                        ['term' => $params['source'], 'value' => $value]
                                    );
                                }
                            }
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

        // Manage the case where a source is mapped multiple times.
        $hasMultipleDestinations = count(array_column($mapper, 'source', 'source')) < count(array_column($mapper, 'source'));
        $message = $hasMultipleDestinations
            ? 'Operation "{action}": Process a table multi-replacement via file "{file}" for {count} data (first 10): {list}.' // @translate
            : 'Operation "{action}": Process a table simple replacement via file "{file}" for {count} data (first 10): {list}.'; // @translate;
        $this->logger->info(
            $message,
            [
                'action' => $this->operationName,
                'file' => $params['mapping'],
                'count' => count($mapper),
                'list' => array_slice(array_diff(array_column($mapper, 'source'), array_column($mapper, 'source', 'source')), 0, 10),
            ]
        );

        $hasMultipleDestinations = $hasMultipleDestinations || count($destinations) > 1;

        return [
            $mapper,
            $hasMultipleDestinations,
        ];
    }

    /**
     * Prepare the mapping table to store data.
     *
     * The mapping table is like the table value with a column "source" and
     * without column "resource_id".
     */
    protected function storeMappingTablePrepare(): void
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
    }

    /**
     * Save a mapping table in a temporary table in database.
     *
     * The mapping table is like the table value with a column "source" and
     * without column "resource_id".
     */
    protected function storeMappingTable(array $mapper, bool $flush = false): void
    {
        if (!$flush) {
            $this->storeMappingTablePrepare();
        }

        foreach (array_chunk($mapper, self::CHUNK_ENTITIES / 2, true) as $chunk) {
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

        if ($flush && $mapper) {
            $this->transformApplyOperations($flush);
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

        $sourceIds = implode(',', array_keys($properties));

        [$sqlExclude, $sqlExcludeWhere] = $this->transformHelperExcludeStart($params);

        // Copy all distinct values in a temporary table.

        $this->storeMappingTablePrepare();

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

        $sourceId = $this->bulk->getPropertyId($params['source']);
        if (empty($sourceId)) {
            $this->logger->err(
                'The operation "{action}" requires a valid source: "{term}" does not exist.', // @translate
                ['action' => $this->operationName, 'term' => $params['source']]
            );
            return false;
        }

        $sourceTerm = $params['source'] = $this->bulk->getPropertyTerm($sourceId);

        // Only literal: the already mapped values (label + uri) can be used as
        // mapping, but useless for a new database.
        // For example, when authors are created in a previous step, foaf:name
        // is always literal.
        $list = $this->listDataValues([$sourceTerm => $sourceId], ['', 'literal'], 'value', true);
        if (is_null($list)) {
            return false;
        }
        $totalList = count($list);
        if (!$totalList) {
            return true;
        }

        // Prepare the current mapping if any from params or previous steps.
        $this->prepareMappingSourceUris($params);
        $this->updateMappingSourceUris($params, $list);
        $currentMapping = &$this->mappingsSourceUris[$params['name']];
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

        // Only values with a source and a single uri can be used.
        $mapper = [];
        foreach ($currentMapping as $source => $row) {
            if (!$source || !count($row) || count($row) > 1 || empty(key($row))) {
                continue;
            }
            $uri = key($row);
            $label = isset($row['label']) && mb_strlen($row['label']) ? $row['label'] : null;
            $type = isset($row['type']) && mb_strlen($row['type']) ? $row['type'] : $datatype;
            $mapper[] = [
                'source' => $source,
                'property_id' => $sourceId,
                'value_resource_id' => null,
                'type' => $type,
                'value' => $label,
                'uri' => $uri,
                // TODO Check and normalize property language.
                'lang' => null,
                // TODO Try to keep original is_public.
                'is_public' => 1,
            ];
        }

        $this->storeMappingTable($mapper);

        // Unset references.
        unset($currentMapping);

        return true;
    }

    protected function listDataValues(
        array $properties,
        array $datatypes = [],
        string $column = 'value',
        bool $asKey = false
    ): ?array {
        if (empty($properties)) {
            $this->logger->info(
                'Operation "{action}": the list of properties is empty.', // @translate
                ['action' => $this->operationName]
            );
            return null;
        }

        if (count($datatypes)) {
            $whereDatatype = 'AND (`value`.`type` IN (' . implode(', ', array_map([$this->connection, 'quote'], $datatypes)) . ')';
            if (in_array('valuesuggest', $datatypes)) {
                $whereDatatype .= ' OR `value`.`type` LIKE "valuesuggest%"';
            }
            if (in_array('', $datatypes)) {
                $whereDatatype .= ' OR `value`.`type` IS NULL';
            }
            $whereDatatype .= ')';
        } else {
            $whereDatatype = '';
        }

        if (!in_array($column, ['value', 'uri', 'value_resource_id'])) {
            $this->logger->info(
                'Operation "{action}": Column should be "value", "uri" or "value_resource_id".', // @translate
                ['action' => $this->operationName]
            );
            return null;
        }

        // Get the list of unique values (case insensitive).
        $sql = <<<SQL
SELECT DISTINCT
    `value`.`$column` AS `v`,
    GROUP_CONCAT(DISTINCT `value`.`resource_id` ORDER BY `value`.`resource_id` SEPARATOR ' ') AS r
FROM `value`
JOIN `_temporary_value`
    ON `_temporary_value`.`id` = `value`.`id`
JOIN `item`
    ON `item`.`id` = `value`.`resource_id`
WHERE
    `value`.`property_id` IN (:property_ids)
    $whereDatatype
    AND `value`.`$column` <> ""
    AND `value`.`$column` IS NOT NULL
GROUP BY `v`
ORDER BY `v`
;
SQL;
        $bind = [
            'property_ids' => $properties,
        ];
        $types = [
            'property_ids' => $this->connection::PARAM_INT_ARRAY,
        ];
        // Warning: array_column() is used because values are distinct and all
        // of them are strings.
        // Note that numeric topics ("1918") are automatically casted to integer.
        $list = $this->connection->executeQuery($sql, $bind, $types)->fetchAllAssociative();
        $list = $asKey
            ? array_column($list, 'r', 'v')
            : array_column($list, 'v');

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

    /**
     * Clean the user provided table for uri mapping.
     *
     * @todo The columns should be the same in all parts of the config and tables. Anyway, loaded once.
     */
    protected function prepareMappingSourceUris(array $params): void
    {
        // If exists, the original table is already loaded (no mix mappings).
        if (isset($this->mappingsSourceUris[$params['name']])) {
            return;
        }

        // Create a mapping for checking and future reimport.
        $table = $this->loadTable($params['mapping']) ?: [];

        $datatype = $params['datatype'] ?? null;

        // Prepare the list of needed columns.
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
        // The column "identifier" is the uri.
        unset($columns['identifier']);

        $this->mappingsColumns[$params['name']] = array_flip($columns);
        $this->mappingsSourceItems[$params['name']] = [];

        // Merge the sources to search them instantly: in some cases, there are
        // multiple times the same source.
        // Warning: because array_column() casts numerical strings in keys, it
        // may break some values, like numeric topic "1918". Nevertheless, this
        // issue doesn't occur here, because all values of the table are strings
        // and there is no floats, etc.
        $this->mappingsSourceUris[$params['name']] = array_fill_keys(array_column($table, 'source'), []);
        $this->mappingsSourcesToSources[$params['name']] = [];
        if (!empty($params['valid_sources'])) {
            $validSources = is_array($params['valid_sources']) ? $params['valid_sources'] : [$params['valid_sources']];
            foreach ($validSources as $validSource) {
                $this->mappingsSourcesToSources[$params['name']] = array_replace(
                    $this->mappingsSourcesToSources[$params['name']],
                    array_column($table, 'source', $validSource)
                );
                // Remove sources without sources.
                unset($this->mappingsSourcesToSources[$params['name']]['']);
            }
        }

        // Use references for simplicity.
        $storedSourceItems = &$this->mappingsSourceItems[$params['name']];
        $sources = &$this->mappingsSourceUris[$params['name']];
        unset($sources['']);

        $prefix = isset($params['prefix']) && strlen($params['prefix'])
            ? $params['prefix']
            : false;

        // Clean the input table.
        foreach ($table as $key => &$row) {
            // Remove row without source.
            if (!isset($row['source']) || $row['source'] === '' || $row['source'] === null || $row['source'] === []) {
                unset($table[$key]);
                continue;
            }

            // Remove columns with empty headers.
            unset($row['']);

            // Keep all needed columns and only them, and order them.
            $row = array_replace($columns, array_intersect_key($row, $columns));

            // Merge the rows by source and uri.
            $source = $row['source'];
            $items = $row['items'] ?? [];
            unset($row['source'], $row['items']);

            // Clean the list of items, merge it with previous ones and store it
            // separately, in all cases.
            if ($items) {
                $items = array_unique(array_filter(array_map('intval', explode(' ', str_replace('#', ' ', $items)))));
                sort($items);
            } else {
                $items = [];
            }
            if (!empty($storedSourceItems[$source])) {
                $items = array_unique(array_merge($storedSourceItems[$source], $items));
                sort($items);
            }
            $storedSourceItems[$source] = $items;

            // Prepend the prefix when missing.
            if ($prefix
                && !empty($row['uri'])
                && mb_strpos($row['uri'], $prefix) !== 0
            ) {
                $row['uri'] = $prefix . $row['uri'];
            }
            $uri = $row['uri'] ?: null;

            // Keep only the first row with the specified uri.
            // Rows are not mixed.
            if (isset($sources[$source][$uri])) {
                unset($table[$key]);
                continue;
            }

            // When there is an uri, the row with an empty uri should be removed
            // because this is a result.
            if ($uri) {
                unset($sources[$source]['']);
            }

            unset($row['uri']);
            $sources[$source][$uri] = $row;
        }

        // Unset references.
        unset($storedSourceItems);
        unset($sources);
    }

    /**
     * Update a mapping of values with missing sources and identifiers.
     *
     * Only the list of sources is updated, not the existing mapping sources,
     * because the list contains the used sources and only them are useful.
     *
     * The original sources are not updated when there is an uri: use operation
     * "append_values" if needed.
     */
    protected function updateMappingSourceUris(array $params, array $list): void
    {
        // Should be prepared first.
        if (!isset($this->mappingsSourceUris[$params['name']])) {
            $this->prepareMappingSourceUris($params);
        }

        // Use references for simplicity.
        $currentMapping = &$this->mappingsSourceUris[$params['name']];
        $currentMappingSourcesToSources = &$this->mappingsSourcesToSources[$params['name']];
        $currentItems = &$this->mappingsSourceItems[$params['name']];

        $datatype = $params['datatype'];
        $originalDataType = $datatype;
        $sourceTerm = $params['source'];

        // TODO Use $this->mappingsColumns.
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

        $params['only_top_subject'] = !empty($params['only_top_subject']);

        $defaultRow = [
            'label' => null,
            'type' => null,
            'info' => null,
        ];
        foreach ($extraColumns as $column) {
            $defaultRow[$column] = null;
        }

        // Update the mapping for missing values in list, using the suggesters.
        $processed = 0;
        $countSingle = 0;
        foreach ($list as $source => $resourceIds) {
            ++$processed;
            $source = trim((string) $source);
            if ($source === '') {
                continue;
            }

            // In all cases, update the resource ids for future check.
            $resourceIds = array_values(array_filter(explode(' ', $resourceIds)));
            if (!empty($currentItems[$source])) {
                $resourceIds = array_filter(array_unique(array_merge($currentItems[$source], $resourceIds)));
                sort($resourceIds);
            }
            $currentItems[$source] = $resourceIds;

            // Check if second sources can be used.
            $sourcesToCheck = empty($currentMappingSourcesToSources[$source])
                ? [$source]
                : array_unique([$source, $currentMappingSourcesToSources[$source]]);
            foreach ($sourcesToCheck as $sourceToCheck) {
                // Check if the value is already mapped with one or multiple uris to
                // avoid to repeat search.
                // So check the keys (uris) in the sources (values): when there is
                // no empty key, it means that the source is already checked.
                if (isset($currentMapping[$sourceToCheck])
                    && (
                        !count($currentMapping[$sourceToCheck])
                        || count($currentMapping[$sourceToCheck]) > 1
                        || (
                            count($currentMapping[$sourceToCheck]) === 1
                            && (key($currentMapping[$sourceToCheck]) !== '' || !empty($currentMapping[$sourceToCheck]['_chk']))
                        )
                    )
                ) {
                    continue 2;
                }
            }

            // There is no value or there is one value without uri.
            // When there is one value, get the type if any.
            $existing = isset($currentMapping[$source]) ? reset($currentMapping[$source]) : null;
            if ($existing) {
                if (!empty($existing['type'])) {
                    $datatype = $existing['type'];
                }
            } else {
                $currentMapping[$source] = [];
                $datatype = $originalDataType;
            }

            $result = $this->valueSuggestQuery($source, $datatype, $params);

            if ($result === null) {
                $this->logger->err(
                    'Operation "{action}": connection issue: skipping next requests for data type "{type}" (term {term}).', // @translate
                    ['action' => $this->operationName, 'type' => $datatype, 'term' => $sourceTerm]
                );
                break;
            }

            if (!count($result)) {
                if ($existing) {
                    // Avoid to check the row during another operation.
                    $currentMapping[$source]['']['_chk'] = true;
                }
                continue;
            }

            // Check if one of the value is exactly the queried value.
            // Many results may be returned but only the good one is needed.
            if (count($result) > 1) {
                $first = null;
                $count = 0;
                foreach ($result as $k => $r) {
                    if ($r['value'] === $source) {
                        ++$count;
                        if (is_null($first)) {
                            $first = $k;
                        }
                    }
                }
                if ($count === 1) {
                    $result = [$result[$first]];
                }
            }

            // Store the results for future steps.
            foreach ($result as $r) {
                $uri = $r['data']['uri'];
                $row = $defaultRow;
                $row['label'] = $r['value'];
                $row['type'] = empty($r['data']['type']) ? $datatype : $r['data']['type'];
                $row['info'] = $r['data']['info'];
                foreach ($extraColumns as $column) {
                    if (isset($r['data'][$column])) {
                        $row[$column] = $r['data'][$column];
                    }
                }
                $currentMapping[$source][$uri] = $row;
            }

            if (count($result) === 1) {
                ++$countSingle;
                // Remove the existing value when a value is found.
                // TODO The value of the user input is not merged with result to avoid mixing data.
                unset($currentMapping[$source]['']);
            }

            if ($processed % 100 === 0) {
                $this->logger->info(
                    'Operation "{action}": {count}/{total} unique values for term "{term}" processed, {singles} values with a single uri.', // @translate
                    ['action' => $this->operationName, 'count' => $processed, 'total' => count($list), 'term' => $sourceTerm, 'singles' => $countSingle]
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

        // Clear reference.
        unset($currentMapping);
        unset($currentItems);
    }

    protected function processValuesTransformUpdate(
        string $joinColumn = 'value',
        ?int $propertyIdSource = null,
        ?int $propertyIdDest = null
    ): void {
        $joinMapper = '';
        $sqlProperty = '';
        if ($propertyIdSource === $propertyIdDest) {
            $joinMapper = '    AND `_temporary_mapper`.`property_id` = `value`.`property_id`';
        } elseif ($propertyIdSource || $propertyIdDest) {
            if ($propertyIdSource) {
                $sqlProperty .= "AND `value`.`property_id` = $propertyIdSource\n";
            }
            if ($propertyIdDest) {
                $sqlProperty .= "AND `_temporary_mapper`.`property_id` = $propertyIdDest\n";
            }
        }

        $this->operationSqls[] = <<<SQL
# Update values according to the temporary table.
UPDATE `value`
JOIN `_temporary_value`
    ON `_temporary_value`.`id` = `value`.`id`
JOIN `_temporary_mapper`
    ON `_temporary_mapper`.`source` = `value`.`$joinColumn`
    $joinMapper
SET
    `value`.`property_id` = `_temporary_mapper`.`property_id`,
    `value`.`value_resource_id` = `_temporary_mapper`.`value_resource_id`,
    `value`.`type` = `_temporary_mapper`.`type`,
    `value`.`lang` = `_temporary_mapper`.`lang`,
    `value`.`value` = `_temporary_mapper`.`value`,
    `value`.`uri` = `_temporary_mapper`.`uri`,
    `value`.`is_public` = `_temporary_mapper`.`is_public`
WHERE 1 = 1
    $sqlProperty
;
SQL;
    }

    protected function processValuesTransformInsert(array $propertyIds = [], string $joinColumn = 'value'): void
    {
        // The property may be different between value and temporary mapper:
        // the new values are created from a list of values.
        $properties = count($propertyIds)
            ? 'WHERE
    `value`.`property_id` IN (' . implode(', ', $propertyIds) . ')'
            : '';

        $this->operationSqls[] = <<<SQL
# Insert values according to the temporary table.
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
    ON `_temporary_mapper`.`source` = `value`.`$joinColumn`
$properties
;
SQL;
    }

    protected function processValuesTransformReplace(
        string $joinColumn = 'value',
        ?int $propertyIdSource = null,
        ?int $propertyIdDest = null
    ): void {
        // To store the previous max value id is the simplest way to remove
        // updated values without removing other ones.
        // This max value id is saved temporary in the settings for simplicity.
        $random = $this->operationRandoms[$this->operationIndex];
        $this->operationSqls[] = <<<SQL
# Explode values according to the temporary table (step 1/4).
# Store the max value id to remove source values after copy.
INSERT INTO `setting`
    (`id`, `value`)
SELECT
    "bulkimport_max_value_id_$random",
    MAX(`value`.`id`)
FROM `value`
;
SQL;

        $joinMapper = '';
        $sqlProperty = '';
        if ($propertyIdSource === $propertyIdDest) {
            $joinMapper = '    AND `_temporary_mapper`.`property_id` = `value`.`property_id`';
        } elseif ($propertyIdSource || $propertyIdDest) {
            if ($propertyIdSource) {
                $sqlProperty .= "AND `value`.`property_id` = $propertyIdSource\n";
            }
            if ($propertyIdDest) {
                $sqlProperty .= "AND `_temporary_mapper`.`property_id` = $propertyIdDest\n";
            }
        }

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
    ON `_temporary_mapper`.`source` = `value`.`$joinColumn`
    $joinMapper
WHERE 1 = 1
    $sqlProperty
;

SQL;

        $this->operationSqls[] = <<<SQL
# Explode values according to the temporary table (step 3/4).
DELETE `value`
FROM `value`
JOIN `_temporary_value`
    ON `_temporary_value`.`id` = `value`.`id`
JOIN `_temporary_mapper`
    ON `_temporary_mapper`.`source` = `value`.`$joinColumn`
JOIN `setting`
WHERE
    `value`.`id` <= `setting`.`value`
    AND `setting`.`id` = "bulkimport_max_value_id_$random"
    AND `setting`.`value` IS NOT NULL
    AND `setting`.`value` > 0
    $sqlProperty
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
        $propertyId = $this->bulk->getPropertyId($term);
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
        $resourceName = empty($params['resource_name'])
            ? \Omeka\Entity\Item::class
            : $this->bulk->getEntityClass($params['resource_name']) ?? \Omeka\Entity\Item::class;
        if ($resourceName === \Omeka\Entity\Media::class) {
            $this->logger->err(
                'The operation "{action}" cannot create media currently.', // @translate
                ['action' => $this->operationName]
            );
            return;
        }
        $isPublic = (int) (bool) ($params['is_public'] ?? 1);

        $quotedResourceName = $this->connection->quote($resourceName);

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
    $quotedResourceName,
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

        if ($resourceName === \Omeka\Entity\Item::class) {
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
        } elseif ($resourceName === \Omeka\Entity\ItemSet::class) {
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
        } elseif ($resourceName === \Omeka\Entity\Media::class) {
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
            $reciprocalId = $this->bulk->getPropertyId($params['reciprocal']);
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

    protected function valueSuggestQuery(string $value, string $datatype, array $params = [], int $loop = 0): ?array
    {
        // An exception is done for geonames, because the username is hardcoded
        // and has few credits for all module users.
        if ($datatype === 'valuesuggest:geonames:geonames') {
            return $this->valueSuggestQueryGeonames($value, $datatype, $params);
        }
        if (in_array($datatype, [
            // Fake data type: person or corporation (or conference here).
            'valuesuggest:idref:author',
            'valuesuggest:idref:person',
            'valuesuggest:idref:corporation',
            'valuesuggest:idref:conference',
        ])) {
            return $this->valueSuggestQueryIdRefAuthor($value, $datatype, $params);
        }
        if ($datatype === 'valuesuggest:idref:rameau') {
            return $this->valueSuggestQueryIdRefRameau($value, $datatype, $params);
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
                return $this->valueSuggestQuery($value, $datatype, $params, ++$loop);
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
     */
    protected function valueSuggestQueryGeonames(string $value, string $datatype, array $params = [], int $loop = 0): ?array
    {
        static $countries;
        static $language2;
        static $searchType;

        if (is_null($language2)) {
            $countries = $this->loadTableAsKeyValue('countries_iso-3166', 'ISO-2', true);
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
        elseif (!empty($params['formats'])) {
            foreach ($params['formats'] as $format) {
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
                    $arguments = (array) $format['arguments'];
                    foreach ($arguments as $argument) {
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
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:60.0) Gecko/20100101 Firefox/116.0',
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
                return $this->valueSuggestQueryGeonames($originalValue, $datatype, $params, ++$loop);
            }
            // Allow to continue next processes.
            if (empty($e)) {
                $this->logger->err(
                    'Connection issue.' // @translate
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
                    // TODO ValueSuggest uses the wrong domain: http://www.geonames.org.
                    // 'uri' => sprintf('http://www.geonames.org/%s', $result['geonameId']),
                    // Example: https://sws.geonames.org/3017382/.
                    'uri' => sprintf('https://sws.geonames.org/%s/', $result['geonameId']),
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
    protected function valueSuggestQueryIdRefAuthor(string $value, string $datatype, array $params = [], int $loop = 0): ?array
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

        $extraColumns = $params['extra_columns'] ?? [];
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
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:60.0) Gecko/20100101 Firefox/116.0',
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
                return $this->valueSuggestQueryIdRefAuthor($value, $datatype, $params, ++$loop);
            }
            // Allow to continue next processes.
            if (empty($e)) {
                $this->logger->err(
                    'Connection issue.' // @translate
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
                $val = is_array($result['affcourt_r']) ? reset($result['affcourt_r']) : $result['affcourt_r'];
            } elseif (isset($result['affcourt_z'])) {
                $val = is_array($result['affcourt_z']) ? reset($result['affcourt_z']) : $result['affcourt_z'];
            } else {
                $val = $result['ppn_z'];
            }
            $recordType = empty($result['recordtype_z']) || !isset($recordTypes[$result['recordtype_z']])
                ? 'valuesuggest:idref:person'
                : $recordTypes[$result['recordtype_z']];
            $suggestion = [
                'value' => $val,
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

    /**
     * Default value suggester, but use return the top subject when possible.
     *
     * @see \ValueSuggest\Suggester\IdRef\IdRefSuggestAll::getSuggestions()
     */
    protected function valueSuggestQueryIdRefRameau(string $value, string $datatype, array $params = [], int $loop = 0): ?array
    {
        $cleanValue = strpos($value, ' ')
            ? $value
            : '(' . implode(' AND ', array_filter(explode(' ', $value), 'strlen')) . ')';

        $q = 'recordtype_z:r AND subjectheading_t:' . $cleanValue;

        $query = [
            'q' => $q,
            'wt' => 'json',
            'version' => '2.2',
            'start' => 0,
            'rows' => 30,
            'sort' => 'score desc',
            'indent' => 'on',
            'fl' => 'id,ppn_z,affcourt_z',
        ];
        $url = 'https://www.idref.fr/Sru/Solr';
        $headers = [
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:60.0) Gecko/20100101 Firefox/116.0',
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
                return $this->valueSuggestQueryIdRefAuthor($value, $datatype, $params, ++$loop);
            }
            // Allow to continue next processes.
            if (empty($e)) {
                $this->logger->err(
                    'Connection issue.' // @translate
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

        // Check the result key.
        foreach ($results['response']['docs'] as $result) {
            if (empty($result['ppn_z'])) {
                continue;
            }
            // "affcourt" may be not present in some results (empty words).
            if (isset($result['affcourt_r'])) {
                $val = is_array($result['affcourt_r']) ? reset($result['affcourt_r']) : $result['affcourt_r'];
            } elseif (isset($result['affcourt_z'])) {
                $val = is_array($result['affcourt_z']) ? reset($result['affcourt_z']) : $result['affcourt_z'];
            } else {
                $val = $result['ppn_z'];
            }
            $suggestion = [
                'value' => $val,
                'data' => [
                    'uri' => 'https://www.idref.fr/' . $result['ppn_z'],
                    'info' => null,
                ],
            ];
            $suggestions[] = $suggestion;
        }

        foreach ($suggestions as $suggestion) {
            if ($suggestion['value'] === $value) {
                return [$suggestion];
            }
        }

        if (empty($params['only_top_subject'])) {
            return $suggestions;
        }

        $filtereds = [];
        foreach ($suggestions as $suggestion) {
            if (strpos($suggestion['value'], '--') === false) {
                $filtereds[] = $suggestion;
            }
        }

        return empty($filtereds)
            ? $suggestions
            : $filtereds;
    }

    protected function getOutputFilepath(string $filename, string $extension, bool $relative = false): ?string
    {
        $relativePath = 'bulk_import/' . 'import_' . $this->job->getImportId() . '_' . str_replace(':', '-', $filename) . '.' . $extension;
        if ($relative) {
            return 'files/' . $relativePath;
        }
        $filepath = $this->basePath . '/' . $relativePath;
        if (!file_exists($filepath)) {
            try {
                if (!is_dir(dirname($filepath))) {
                    @mkdir(dirname($filepath), 0775, true);
                }
                @touch($filepath);
            } catch (\Exception $e) {
                // Don't set error, it can be managed outside.
                // $this->hasError = true;
                $this->logger->warn(
                    'File "{filename}" cannot be created.', // @translate
                    ['filename' => $filepath]
                );
                return null;
            }
        }
        return $filepath;
    }

    /**
     * The original files are not updated: the new mapping are saved inside
     * files/bulk_import/ with the job id in filename.
     *
     * OpenDocument Spreedsheet is used instead of csv/tsv because there may be
     * values with end of lines. Furthermore, it allows to merge cells when
     * there are multiple results (but openspout doesn't manage it).
     */
    protected function saveMappingsSourceUris(): void
    {
        $columns = [
            'source',
            'items',
            'uri',
            'label',
            'type',
            'info',
        ];
        foreach ($this->mappingsSourceUris as $name => $mapper) {
            // Prepare the list of specific headers one time in order to save
            // the fetched data and the ones from the original file.
            $extraColumns = [];
            foreach ($mapper as $rows) {
                foreach ($rows as $row) {
                    $extraColumns = array_unique(array_merge($extraColumns, array_keys($row)));
                }
            }
            $extraColumns = array_flip(array_diff($extraColumns, $columns));
            unset($extraColumns['_chk']);
            $extraColumns = array_keys($extraColumns);
            $this->saveMappingSourceUrisToOds($name, $mapper, $extraColumns);
            $this->saveMappingSourceUrisToHtml($name, $mapper, $extraColumns);
        }
    }

    protected function saveMappingSourceUrisToOds(string $name, array $mapper, array $extraColumns = []): void
    {
        $filepath = $this->getOutputFilepath($name, 'ods');
        $relativePath = $this->getOutputFilepath($name, 'ods', true);
        if (!$filepath || !$relativePath) {
            $this->logger->err(
                'Unable to create output file.' // @translate
            );
            return;
        }

        $serverUrlHelper = $this->services->get('ViewHelperManager')->get('ServerUrl');
        $baseUrlPath = $this->services->get('Router')->getBaseUrl();
        $baseUrl = $serverUrlHelper($baseUrlPath ? $baseUrlPath . '/' : '/');

        /** @var \OpenSpout\Writer\ODS\Writer $spreadsheetWriter */
        $spreadsheetWriter = WriterEntityFactory::createODSWriter();

        try {
            @unlink($filepath);
            $spreadsheetWriter->openToFile($filepath);
        } catch (\OpenSpout\Common\Exception\IOException $e) {
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
        $tableHeaders = array_values(array_merge($headers, $extraColumns));
        $emptyRow = array_fill_keys($tableHeaders, '');

        /** @var \OpenSpout\Common\Entity\Row $row */
        $row = WriterEntityFactory::createRowFromArray($tableHeaders, (new StyleBuilder())->setFontBold()->build());
        $spreadsheetWriter->addRow($row);

        $newStyle = (new StyleBuilder())->setBackgroundColor(Color::rgb(208, 228, 245))->build();

        $even = false;
        foreach ($mapper as $source => $rows) {
            // Skip useless data.
            if (empty($source)) {
                continue;
            }

            // Fill the map with all columns.
            // There should be at least one row to keep the sources.
            if (empty($rows)) {
                $rows[''] = $emptyRow;
            }

            $resourceIds = '';
            $list = $this->mappingsSourceItems[$name][$source] ?? [];
            foreach ($list as $id) {
                $resourceIds .= "#$id ";
            }
            $resourceIds = trim($resourceIds);

            // The rows are already checked.
            foreach ($rows as $uri => $row) {
                $row['source'] = $source;
                $row['items'] = $resourceIds;
                $row['uri'] = $uri;
                $row = array_filter(array_map('strval', $row), 'strlen');
                // The order should be always the same.
                $row = array_replace($emptyRow, array_intersect_key($row, $emptyRow));
                $sheetRow = WriterEntityFactory::createRowFromArray($row);
                if ($even) {
                    $sheetRow->setStyle($newStyle);
                }
                $spreadsheetWriter->addRow($sheetRow);
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

    protected function saveMappingSourceUrisToHtml(string $name, array $mapper, array $extraColumns = []): void
    {
        $filepath = $this->getOutputFilepath($name, 'html');
        $relativePath = $this->getOutputFilepath($name, 'html', true);
        if (!$filepath || !$relativePath) {
            $this->logger->err(
                'Unable to create output file.' // @translate
            );
            return;
        }

        $serverUrlHelper = $this->services->get('ViewHelperManager')->get('ServerUrl');
        $baseUrlPath = $this->services->get('Router')->getBaseUrl();
        $baseUrl = $serverUrlHelper($baseUrlPath ? $baseUrlPath . '/' : '/');

        $headers = [
            'source',
            'items',
            'uri',
            'label',
            'type',
            'info',
        ];
        $extraColumns = array_values(array_unique(array_filter(array_diff($extraColumns, $headers))));
        $tableHeaders = array_values(array_merge($headers, $extraColumns));
        $emptyRow = array_fill_keys($tableHeaders, '');

        $this->prepareMappingSourceUrisToHtml($filepath, 'start', $name, $tableHeaders);

        $fp = fopen($filepath, 'ab');

        foreach ($mapper as $source => $rows) {
            // Skip useless data.
            if (empty($source)) {
                continue;
            }

            // Fill the map with all columns.
            // There should be at least one row to keep the sources.
            if (empty($rows)) {
                $rows[''] = $emptyRow;
            }

            $resourceIds = $this->mappingsSourceItems[$name][$source] ?? [];

            // Warning: numeric keys are cast-ed to integer, so force string here.
            $this->appendMappingSourceUrisToHtml($fp, $rows, $baseUrl, (string) $source, $resourceIds, $emptyRow);
        }

        fclose($fp);
        $this->prepareMappingSourceUrisToHtml($filepath, 'end');

        $this->logger->notice(
            'The mapping checking page for "{name}" is available in "{url}".', // @translate
            [
                'name' => $name,
                'url' => $baseUrl . $relativePath,
            ]
        );
    }

    protected function prepareMappingSourceUrisToHtml(string $filepath, ?string $part = null, ?string $name = null, array $tableHeaders = []): void
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

    protected function appendMappingSourceUrisToHtml(
        $fp,
        array $rows,
        string $baseUrl,
        string $source,
        array $resourceIds,
        array $emptyRow
    ): void {
        // Don't repeat same source and items.
        $count = count($rows);
        $rowspan = $count <= 1 ? '' : sprintf(' rowspan="%d"', $count);

        $resources = '';
        foreach ($resourceIds as $id) {
            $resources .= sprintf(
                '<a href="%sadmin/item/%s" target="_blank">#%s</a>' . " \n",
                $baseUrl, $id, $id
            );
        }

        $html = "                <tr>\n";
        $html .= sprintf('                    <td scope="row"%s>%s</td>', $rowspan, htmlspecialchars($source, ENT_NOQUOTES | ENT_HTML5)) . "\n";
        $html .= sprintf('                    <td%s>%s</td>', $rowspan, $resources) . "\n";

        $first = true;
        foreach ($rows as $uri => $row) {
            if ($first) {
                $first = false;
            } else {
                $html .= "                <tr>\n";
            }
            $code = (string) basename(rtrim($uri, '/'));
            $html .= sprintf('                    <td><a href="%s" target="_blank">%s</a></td>', htmlspecialchars($uri, ENT_NOQUOTES | ENT_HTML5), htmlspecialchars($code, ENT_NOQUOTES | ENT_HTML5)) . "\n";

            $row = array_filter(array_map('strval', $row), 'strlen');
            // The order should be always the same.
            $row = array_replace($emptyRow, array_intersect_key($row, $emptyRow));
            unset($row['source'], $row['items'], $row['uri'], $row['_chk']);
            foreach ($row as $cell) {
                $html .= $cell === ''
                    ? "                    <td></td>\n"
                    : sprintf('                    <td>%s</td>', htmlspecialchars($cell, ENT_NOQUOTES | ENT_HTML5)) . "\n";
            }
            $html .= "                </tr>\n";
        }

        fwrite($fp, $html);
    }
}
