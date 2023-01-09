<?php declare(strict_types=1);

namespace BulkImport\Processor;

use Log\Stdlib\PsrMessage;
use Omeka\Entity\Resource;

/**
 * @todo Rename all methods to avoid possible override of ResourceProcessor, even if they are fully separated for now.
 * @todo Use only the same array as json-ld resources.
 */
trait ResourceTrait
{
    /**
     * The current key id.
     *
     * @var string
     */
    protected $sourceKeyId;

    protected function prepareResources(iterable $sources, string $sourceType): void
    {
        // Normally already checked in AbstractFullProcessor.
        if (empty($this->mapping[$sourceType]['source'])) {
            return;
        }

        $this->map[$sourceType] = [];

        $keyId = $this->mapping[$sourceType]['key_id'];
        if (empty($keyId)) {
            $this->hasError = true;
            $this->logger->err(
                'There is no key identifier for "{source}".', // @translate
                ['source' => $sourceType]
            );
            return;
        }

        $this->sourceKeyId = $keyId;
        $classId = empty($this->mapping[$sourceType]['resource_class_id']) ? null : $this->bulk->getResourceClassId($this->mapping[$sourceType]['resource_class_id']);
        $templateId = empty($this->mapping[$sourceType]['resource_template_id']) ? null : $this->bulk->getResourceTemplateId($this->mapping[$sourceType]['resource_template_id']);
        $thumbnailId = $this->mapping[$sourceType]['thumbnail_id'] ?? null;

        // Check the size of the import.
        $this->countEntities($sources, $sourceType);
        if ($this->hasError) {
            return;
        }

        if (empty($this->totals[$sourceType])) {
            $this->logger->warn(
                'There is no "{source}".', // @translate
                ['source' => $sourceType]
            );
            return;
        }

        $this->logger->notice(
            'Preparation of {total} resources "{source}".', // @translate
            ['total' => $this->totals[$sourceType], 'source' => $sourceType]
        );

        // Use direct query to speed process and to reserve a whole list of ids.
        // The indexation, api events, etc. will be done when the resources will
        // be really filled via update in a second time.

        // Prepare the list of all ids.
        // Only the ids are needed here, except for media, that require the item
        // id (mapped below).
        $mediaItems = [];
        if ($sourceType === 'media') {
            $keyItemId = $this->mapping['media']['key_parent_id'] ?? null;
            if ($keyItemId) {
                // Manage sql or any flat source.
                foreach ($sources as $source) {
                    $this->map[$sourceType][(int) $source[$keyId]] = null;
                    $mediaItems[(int) $source[$keyId]] = (int) $source[$keyItemId];
                }
            } else {
                // Manage standard json-ld source.
                foreach ($sources as $source) {
                    $this->map[$sourceType][(int) $source[$keyId]] = null;
                    // TODO item o:id should be generic.
                    $mediaItems[(int) $source[$keyId]] = (int) $source['o:item']['o:id'];
                }
            }
        } else {
            foreach ($sources as $source) {
                $this->map[$sourceType][(int) $source[$keyId]] = null;
            }
        }
        if (!count($this->map[$sourceType])) {
            $this->logger->notice(
                'No resource "{source}" available on the source.', // @translate
                ['source' => $sourceType]
            );
            return;
        }

        // TODO Allows media without items (see spip).
        // Currently, it's not possible to import media without the
        // items, because the mapping of the ids is not saved.
        // TODO Allow to use a media identifier to identify the item.
        if ($sourceType === 'media' && !count($this->map['items'])) {
            $this->logger->warn(
                'Media cannot be imported without items currently.' // @translate
            );
            return;
        }

        $sourceTypeSub = $this->importables[$sourceType]['sub'] ?? null;
        $hasSub = !empty($sourceTypeSub);
        if ($hasSub) {
            $this->map[$sourceTypeSub] = $this->map[$sourceType];
        }

        $resourceColumns = [
            'id' => 'id',
            'owner_id' => $this->owner ? $this->ownerId : 'NULL',
            'resource_class_id' => $classId ?: 'NULL',
            'resource_template_id' => $templateId ?: 'NULL',
            'is_public' => '0',
            'created' => '"' . $this->currentDateTimeFormatted . '"',
            'modified' => 'NULL',
            'resource_type' => $this->connection->quote($this->importables[$sourceType]['class']),
            'thumbnail_id' => $thumbnailId ?: 'NULL',
            'title' => 'id',
        ];
        $this->createEmptyEntities($sourceType, $resourceColumns, false, true);
        $this->createEmptyResourcesSpecific($sourceType, $mediaItems);

        if ($hasSub) {
            $resourceColumns['resource_type'] = $this->connection->quote($this->importables[$sourceTypeSub]['class']);
            $this->createEmptyEntities($sourceTypeSub, $resourceColumns, false, true);
            $this->createEmptyResourcesSpecific($sourceTypeSub);
        }

        $this->logger->notice(
            '{total} resources "{source}" have been created.', // @translate
            ['total' => count($this->map[$sourceType]), 'source' => $sourceType]
        );
    }

    protected function createEmptyResourcesSpecific(string $sourceType, ?array $mediaItems = null): void
    {
        $resourceName = $this->importables[$sourceType]['name'];
        $class = $this->importables[$sourceType]['class'];
        $table = $this->importables[$sourceType]['table'];
        $resourceClass = $this->connection->quote($class);

        // Create the resource in the specific resource table.
        switch ($resourceName) {
            default:
                return;

            case 'items':
                $sql = <<<SQL
INSERT INTO `item`
SELECT `resource`.`id`
FROM `resource` AS `resource`
LEFT JOIN `$table` AS `specific` ON `specific`.`id` = `resource`.`id`
WHERE `specific`.`id` IS NULL
    AND `resource`.`resource_type` = $resourceClass;
SQL;
                $this->connection->executeStatement($sql);
                return;

            case 'item_sets':
                // Finalize custom vocabs early: item sets map is available.
                $this->prepareCustomVocabsFinalize();
                $sql = <<<SQL
INSERT INTO `item_set`
SELECT `resource`.`id`, 0
FROM `resource` AS `resource`
LEFT JOIN `$table` AS `specific` ON `specific`.`id` = `resource`.`id`
WHERE `specific`.`id` IS NULL
    AND `resource`.`resource_type` = $resourceClass;
SQL;
                $this->connection->executeStatement($sql);
                return;

            case 'media':
                // Manage the exception for media, that requires the good item id.
                // These checks are normally used only in development.
                // Sometime there is no media, so it may not be an error.
                if (($sourceType === 'media' && empty($mediaItems))
                    || ($sourceType === 'media_items_sub' && empty($this->map['media_items_sub']))
                ) {
                    $this->logger->warn(
                        'Media item ids are required to create media resources.' // @translate
                    );
                    return;
                }

                // To update with a temporary table and without big bind is quicker.
                $sql = <<<SQL
# Copy the mapping of source ids and destination item ids.
DROP TABLE IF EXISTS `_temporary_source_media`;
CREATE TEMPORARY TABLE `_temporary_source_media` (
    `id` INT unsigned NOT NULL,
    `item_id` INT unsigned NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB;

SQL;
                $parent = $this->importables[$sourceType]['parent'] ?? 'items';

                if ($sourceType === 'media_items_sub') {
                    foreach (array_chunk($this->map['media_items_sub'], self::CHUNK_RECORD_IDS, true) as $chunk) {
                        array_walk($chunk, function (&$sourceItemId, $sourceMediaItemId) use ($parent): void {
                            $sourceItemId .= ',' . $this->map[$parent][$sourceMediaItemId];
                        });
                        $sql .= 'INSERT INTO `_temporary_source_media` (`id`,`item_id`) VALUES(' . implode('),(', $chunk) . ");\n";
                    }
                } else {
                    foreach (array_chunk($mediaItems, self::CHUNK_RECORD_IDS, true) as $chunk) {
                        array_walk($chunk, function (&$sourceItemId, $sourceMediaId) use ($parent, $sourceType): void {
                            $sourceItemId = $this->map[$sourceType][$sourceMediaId] . ',' . $this->map[$parent][$sourceItemId];
                        });
                        $sql .= 'INSERT INTO `_temporary_source_media` (`id`,`item_id`) VALUES(' . implode('),(', $chunk) . ");\n";
                    }
                }
                $sql .= <<<'SQL'
INSERT INTO `media`
    (`id`, `item_id`, `ingester`, `renderer`, `data`, `source`, `media_type`, `storage_id`, `extension`, `sha256`, `has_original`, `has_thumbnails`, `position`, `lang`, `size`)
SELECT
    `resource`.`id`, `_temporary_source_media`.`item_id`, '', '', NULL, NULL, NULL, NULL, NULL, NULL, 0, 0, 0, NULL, NULL
FROM `resource` AS `resource`
JOIN `_temporary_source_media` ON `_temporary_source_media`.`id` = `resource`.`id`;
DROP TABLE IF EXISTS `_temporary_source_media`;

SQL;
                $this->connection->executeStatement($sql);
                break;
        }
    }

    protected function fillResourcesProcess(iterable $sources, string $sourceType): void
    {
        // Normally already checked in AbstractFullProcessor.
        if (empty($this->mapping[$sourceType]['source'])) {
            return;
        }

        if (empty($this->totals[$sourceType])) {
            return;
        }

        $this->refreshMainResources();

        // $resourceName = $this->importables[$sourceType]['name'];
        $class = $this->importables[$sourceType]['class'];
        $method = $this->importables[$sourceType]['fill'] ?? null;
        $keyId = $this->mapping[$sourceType]['key_id'];
        $this->sourceKeyId = $keyId;

        /** @var \Omeka\Api\Adapter\AbstractResourceEntityAdapter $adapter */
        $adapter = $this->adapterManager->get($this->importables[$sourceType]['name']);

        $index = 0;
        $created = 0;
        $skipped = 0;
        $excluded = 0;
        foreach ($sources as $source) {
            ++$index;
            $sourceId = $source[$keyId];

            // Some new resources may have been created since first loop.
            if (!isset($this->map[$sourceType][$sourceId])) {
                ++$skipped;
                $this->logger->notice(
                    'Skipped resource "{source}" #{source_id} added in source.', // @translate
                    ['source' => $sourceType, 'source_id' => $sourceId]
                );
                continue;
            }

            $entity = $this->entityManager->find($class, $this->map[$sourceType][$sourceId]);
            if (!$entity) {
                ++$skipped;
                $this->logger->notice(
                    'Unknown resource "{source}" #{source_id}. Probably removed during by another user.', // @translate
                    ['source' => $sourceType, 'source_id' => $sourceId]
                );
                continue;
            }
            $this->entity = $entity;

            // Fill anything for this entity.
            $this->$method($source);

            // In some cases, the source is useless, so it is skipped, so the
            // original entity should be deleted.
            if ($this->entity === null) {
                ++$excluded;
                $this->entityManager->remove($entity);
                continue;
            }

            $errorStore = new \Omeka\Stdlib\ErrorStore;
            $adapter->validateEntity($this->entity, $errorStore);
            if ($errorStore->hasErrors()) {
                ++$skipped;
                $this->logErrors($this->entity, $errorStore);
                continue;
            }

            // TODO Trigger an event for modules (or manage them here).
            // TODO Manage special datatypes (numeric and geometry).

            $this->entityManager->persist($this->entity);
            ++$created;

            if ($created % self::CHUNK_ENTITIES === 0) {
                if ($this->isErrorOrStop()) {
                    break;
                }
                $this->entityManager->flush();
                $this->entityManager->clear();
                $this->refreshMainResources();
                $this->logger->info(
                    '{count}/{total} resource "{source}" imported, {skipped} skipped, {excluded} excluded.', // @translate
                    ['count' => $created, 'total' => count($this->map[$sourceType]), 'source' => $sourceType, 'skipped' => $skipped, 'excluded' => $excluded]
                );
            }
        }

        // Remaining entities.
        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->refreshMainResources();

        $this->logger->notice(
            '{count}/{total} resource "{source}" imported, {skipped} skipped, {excluded} excluded.', // @translate
            ['count' => $created, 'total' => count($this->map[$sourceType]), 'source' => $sourceType, 'skipped' => $skipped, 'excluded' => $excluded]
        );

        // Check total in case of an issue in the network or with Omeka < 2.1.
        // In particular, an issue occurred when a linked resource is private.
        if ($this->totals[$sourceType] !== count($this->map[$sourceType])) {
            $this->hasError = true;
            $this->logger->err(
                'The total {total} of resources "{source}" is not the same than the count {count}.', // @translate
                ['total' => $this->totals[$sourceType], 'count' => count($this->map[$sourceType]), 'source' => $sourceType]
            );
        }
    }

    protected function fillResource(array $source): void
    {
        // Omeka entities are not fluid.
        $this->entity->setOwner($this->userOrDefaultOwner($source['o:owner']));

        if (!empty($source['o:resource_class']['o:id'])) {
            $resourceClass = $this->entityManager->find(\Omeka\Entity\ResourceClass::class, $source['o:resource_class']['o:id']);
            if ($resourceClass) {
                $this->entity->setResourceClass($resourceClass);
            } else {
                $this->logger->warn(
                    'Resource class for resource #{id} (source #{source_id}) is not available.', // @translate
                    ['id' => $this->entity->getId(), 'source_id' => $source[$this->sourceKeyId]]
                );
            }
        }

        if (!empty($source['o:resource_template']['o:id'])) {
            $resourceTemplate = $this->entityManager->find(\Omeka\Entity\ResourceTemplate::class, $source['o:resource_template']['o:id']);
            if ($resourceTemplate) {
                $this->entity->setResourceTemplate($resourceTemplate);
            } else {
                $this->logger->warn(
                    'Resource template for resource #{id} (source #{source_id}) is not available.', // @translate
                    ['id' => $this->entity->getId(), 'source_id' => $source[$this->sourceKeyId]]
                );
            }
        }

        if (!empty($source['o:thumbnail']['o:id'])) {
            $asset = $this->entityManager->find(\Omeka\Entity\Asset::class, $source['o:thumbnail']['o:id']);
            if ($asset) {
                $this->entity->setThumbnail($asset);
            } else {
                $this->logger->warn(
                    'Specific thumbnail for resource #{id} (source #{source_id}) is not available.', // @translate
                    ['id' => $this->entity->getId(), 'source_id' => $source[$this->sourceKeyId]]
                );
            }
        }

        if (array_key_exists('o:title', $source) && strlen((string) $source['o:title'])) {
            $this->entity->setTitle($source['o:title']);
        }

        $this->entity->setIsPublic(!empty($source['o:is_public']));

        // TODO Replace by implodeDate() in previous steps.
        $sqlDate = function ($value) {
            return substr(str_replace('T', ' ', $value), 0, 19) ?: $this->currentDateTimeFormatted;
        };

        $created = new \DateTime($sqlDate($source['o:created']['@value']));
        $this->entity->setCreated($created);

        if ($source['o:modified']['@value']) {
            $modified = new \DateTime($sqlDate($source['o:modified']['@value']));
            $this->entity->setModified($modified);
        }

        $this->fillResourceValues($source);
    }

    protected function fillResourceValues(array $source): void
    {
        $classes = [
            'items' => \Omeka\Entity\Item::class,
            'media' => \Omeka\Entity\Media::class,
            'item_sets' => \Omeka\Entity\ItemSet::class,
        ];
        $resourceName = array_search(get_class($this->entity), $classes);

        // Terms that don't exist can't be imported, or data that are not terms.
        $sourceValues = array_intersect_key($source, $this->map['properties']);
        $entityValues = $this->entity->getValues();

        foreach ($sourceValues as $term => $values) {
            $property = $this->entityManager->find(\Omeka\Entity\Property::class, $this->map['properties'][$term]['id']);
            foreach ($values as $value) {
                $datatype = $value['type'];
                // The check of custom vocab shoud be done in main processor.

                if (!in_array($datatype, $this->allowedDataTypes)) {
                    $mapDataTypes = [
                        'rdf:HTML' => 'html',
                        'rdf:XMLLiteral' => 'xml',
                        'xsd:boolean' => 'boolean',
                    ];
                    $toInstall = false;

                    // Try to manage some types when matching module is not installed.
                    switch ($datatype) {
                        // When here, the module NumericDataTypes is not installed.
                        case substr($datatype, 0, 8) === 'numeric:':
                            $datatype = 'literal';
                            $value['type'] = 'literal';
                            $toInstall = 'Numeric Data Types';
                            break;
                        case isset($mapDataTypes[$datatype]):
                            if (in_array($mapDataTypes[$datatype], $this->allowedDataTypes)) {
                                $datatype = $mapDataTypes[$datatype];
                                $value['type'] = $mapDataTypes[$datatype];
                                break;
                            }
                            $datatype = 'literal';
                            $value['type'] = 'literal';
                            $toInstall = 'Data Type Rdf';
                            break;
                        // Module RdfDataType.
                        case 'xsd:integer':
                            if (!empty($this->modulesActive['NumericDataTypes'])) {
                                $datatype = 'numeric:integer';
                                $value['type'] = 'numeric:integer';
                                break;
                            }
                            $datatype = 'literal';
                            $value['type'] = 'literal';
                            $toInstall = 'Numeric Data Types';
                            break;
                        case 'xsd:date':
                        case 'xsd:dateTime':
                        case 'xsd:gYear':
                        case 'xsd:gYearMonth':
                            if (!empty($this->modulesActive['NumericDataTypes'])) {
                                try {
                                    $value['@value'] = \NumericDataTypes\DataType\Timestamp::getDateTimeFromValue($value['@value']);
                                    $datatype = 'numeric:timestamp';
                                    $value['type'] = 'numeric:timestamp';
                                } catch (\Exception $e) {
                                    $datatype = 'literal';
                                    $value['type'] = 'literal';
                                    $this->logger->warn(
                                        'Value of resource "{source}" #{id} with data type {datatype} is not managed and skipped.', // @translate
                                        ['source' => $resourceName, 'id' => $source[$this->sourceKeyId], 'datatype' => $value['type']]
                                    );
                                }
                                break;
                            }
                            $datatype = 'literal';
                            $value['type'] = 'literal';
                            $toInstall = 'Numeric Data Types';
                            break;
                        case 'xsd:decimal':
                        case 'xsd:gDay':
                        case 'xsd:gMonth':
                        case 'xsd:gMonthDay':
                        case 'xsd:time':
                        // Module DataTypeGeometry.
                        case 'geography':
                        case 'geometry':
                        case 'geography:coordinates':
                        case 'geometry:coordinates':
                        case 'geometry:position':
                        // Deprecated.
                        case 'geometry:geography':
                        case 'geometry:geography:coordinates':
                        case 'geometry:geometry':
                        case 'geometry:geometry:coordinates':
                        case 'geometry:geometry:position':
                            $datatype = 'literal';
                            $value['type'] = 'literal';
                            $toInstall = 'Data Type Geometry';
                            break;
                        // Module IdRef.
                        case 'idref':
                            if (!empty($this->modulesActive['ValueSuggest'])) {
                                $datatype = 'valuesuggest:idref:person';
                                $value['type'] = 'valuesuggest:idref:person';
                                break;
                            }
                            $datatype = 'literal';
                            $value['type'] = 'literal';
                            $toInstall = 'Value Suggest';
                            break;
                        // This is useless here, and same as below.
                        case (substr($datatype, 0, 12) === 'valuesuggest'):
                            $datatype = 'literal';
                            $value['type'] = 'literal';
                            $toInstall = 'Value Suggest';
                            break;
                        default:
                            $datatype = 'literal';
                            $value['type'] = 'literal';
                            $toInstall = $datatype;
                            break;
                    }

                    if ($toInstall) {
                        // TODO Use new option to force "literal".
                        $this->logger->warn(
                            'Value of resource "{source}" #{id} with data type {datatype} was changed to literal.', // @translate
                            ['source' => $resourceName, 'id' => $source[$this->sourceKeyId], 'datatype' => $value['type']]
                        );
                        $this->logger->info(
                            'It’s recommended to install module {module}.', // @translate
                            ['module' => $toInstall]
                        );
                    }
                }

                // Don't keep undetermined value type, in all cases.
                if ($datatype === 'literal') {
                    $value['@id'] = null;
                    $value['value_resource_id'] = null;
                }

                $valueValue = $value['@value'] ?? null;
                $valueUri = null;
                $valueResource = null;
                // Check of linked resource should be done in main processor.
                if (!empty($value['value_resource_id']) && !empty($value['value_resource_name'])) {
                    $valueResource = $this->entityManager->find($classes[$value['value_resource_name']], $value['value_resource_id']);
                    if (!$valueResource) {
                        $this->logger->warn(
                            'Value of resource "{source}" #{id} with linked resource for term {term} is not found.', // @translate
                            ['source' => $resourceName, 'id' => $source[$this->sourceKeyId], 'term' => $term]
                        );
                        continue;
                    }
                    $valueValue = null;
                    $value['@id'] = null;
                    $value['lang'] = null;
                }

                if (!empty($value['@id'])) {
                    $valueUri = $value['@id'];
                    $valueValue = isset($value['o:label']) && strlen((string) $value['o:label']) ? $value['o:label'] : null;
                }

                $entityValue = new \Omeka\Entity\Value;
                $entityValue->setResource($this->entity);
                $entityValue->setProperty($property);
                $entityValue->setType($datatype);
                $entityValue->setValue($valueValue);
                $entityValue->setUri($valueUri);
                $entityValue->setValueResource($valueResource);
                $entityValue->setLang(empty($value['lang']) ? null : $value['lang']);
                $entityValue->setIsPublic(!empty($value['is_public']));

                $entityValues->add($entityValue);

                // Manage specific datatypes (without validation: it's an Omeka source).
                switch ($datatype) {
                    case 'numeric:timestamp':
                    case 'numeric:integer':
                    case 'numeric:duration':
                    case 'numeric:interval':
                        $datatypeAdapter = $this->datatypeManager->get($datatype);
                        $class = $datatypeAdapter->getEntityClass();
                        $dataValue = new $class;
                        $dataValue->setResource($this->entity);
                        $dataValue->setProperty($property);
                        $datatypeAdapter->setEntityValues($dataValue, $entityValue);
                        $this->entityManager->persist($dataValue);
                        break;
                    // Deprecated Geometry data types.
                    case 'geometry:geography':
                    case 'geometry:geography:coordinates':
                    case 'geometry:geometry':
                    case 'geometry:geometry:coordinates':
                    case 'geometry:geometry:position':
                        $mapGeometry = [
                            'geometry:geography' => 'geography',
                            'geometry:geography:coordinates' => 'geography:coordinates',
                            'geometry:geometry' => 'geometry',
                            'geometry:geometry:coordinates' => 'geometry:coordinates',
                            'geometry:geometry:position' => 'geometry:position',
                        ];
                        $datatype = $mapGeometry[$datatype];
                        // No break.
                    case 'geography':
                    case 'geography:coordinates':
                    case 'geometry':
                    case 'geometry:coordinates':
                    case 'geometry:position':
                        $datatypeAdapter = $this->datatypeManager->get($datatype);
                        $class = $datatypeAdapter->getEntityClass();
                        $dataValue = new $class;
                        $dataValue->setResource($this->entity);
                        $dataValue->setProperty($property);
                        $dataValueValue = $datatypeAdapter->getGeometryFromValue($valueValue);
                        if ($this->srid
                            && strpos($datatype, 'geography') !== false
                            && empty($dataValueValue->getSrid())
                        ) {
                            $dataValueValue->setSrid($this->srid);
                        }
                        $dataValue->setValue($dataValueValue);
                        $this->entityManager->persist($dataValue);
                        break;
                    default:
                        break;
                }
            }
        }
    }

    /**
     * Append a list of value to a resource, ordered according to the template.
     *
     * The existing values of the resource are not reordered.
     * Use `fillResourceValues()` if values are Omeka values or `appendValues()` if the
     * values are already ordered.
     *
     * @param array $values A list of values. Value is not a representation, but
     *   a simple mapping with the database table.
     * @param \Omeka\Entity\Resource $source
     */
    protected function orderAndAppendValues(array $values, ?Resource $source = null): void
    {
        if (!count($values)) {
            return;
        }

        if (!$source) {
            $source = $this->entity;
        }

        $values = $this->reorderListOfValues($values, $source);
        $this->appendValues($values, $source);
    }

    /**
     * Append a list of value to a resource.
     *
     * The existing values of the resource are not reordered.
     * Use `fillResourceValues()` if the values are already Omeka values.
     *
     * @param array $values A list of values. Value is not a representation, but
     *   a simple mapping with the database table.
     * @param \Omeka\Entity\Resource $source
     */
    protected function appendValues(array $values, ?Resource $source = null): void
    {
        if (!count($values)) {
            return;
        }

        if (!$source) {
            $source = $this->entity;
        }

        foreach ($values as $value) {
            $this->appendValue($value, $source);
        }
    }

    /**
     * Add a value to a resource.
     *
     * This method is not recommended to be used alone when there is a template,
     * because the order won't be kept.
     *
     * @param array $value A single value. This is not the representation, but a
     *   simple mapping with the database table.
     * @param \Omeka\Entity\Resource $source
     */
    protected function appendValue(array $value, ?Resource $source = null): void
    {
        $value += [
            'term' => null,
            'type' => 'literal',
            'lang' => null,
            'value' => null,
            'uri' => null,
            'value_resource' => null,
            'is_public' => true,
        ];

        $term = $value['term'];
        if (!$term || empty($this->map['properties'][$term]['id'])) {
            return;
        }

        $property = $this->entityManager->find(\Omeka\Entity\Property::class, $this->map['properties'][$term]['id']);
        if (!$property) {
            return;
        }

        if (!$source) {
            $source = $this->entity;
        }

        if (strpos($value['type'], 'customvocab:') === 0
            && !is_numeric(mb_substr($value['type'], 12))
        ) {
            $value['type'] = $this->bulk->getCustomVocabDataTypeName($value['type']) ?? 'literal';
        }

        if (!empty($value['value_resource']) && !is_object($value['value_resource'])) {
            $value['value_resource'] = $this->entityManager->find(\Omeka\Entity\Resource::class, $value['value_resource']);
        }

        $entityValue = new \Omeka\Entity\Value;
        $entityValue->setResource($source);
        $entityValue->setProperty($property);
        $entityValue->setType($value['type']);
        $entityValue->setValue($value['value']);
        $entityValue->setUri($value['uri']);
        $entityValue->setValueResource($value['value_resource']);
        $entityValue->setLang(empty($value['lang']) ? null : $value['lang']);
        $entityValue->setIsPublic(!empty($value['is_public']));

        $source->getValues()
            ->add($entityValue);
    }

    /**
     * Sort a list of values according to a template.
     *
     * The existing values of the resource are not reordered.
     *
     * @param array $values A list of values. Value is not a representation, but
     *   a simple mapping with the database table.
     * @param \Omeka\Entity\Resource $source
     */
    protected function reorderListOfValues(array $values, ?Resource $source = null): array
    {
        if (!count($values)) {
            return $values;
        }

        $orderOfProperties = $this->orderedListTemplatePropertyTerms($source);
        if (!count($orderOfProperties)) {
            return $values;
        }
        $orderOfProperties = array_fill_keys($orderOfProperties, []);

        $result = [];
        foreach ($values as $value) {
            $result[$value['term']][] = $value;
        }

        $values = array_filter(array_replace($orderOfProperties, $result));
        return array_merge(...array_values($values));
    }

    /**
     * Reorder all values of a resource according to template or specific order.
     *
     * The values must not have been saved (it sorts by id).
     *
     * @param array $orderOfProperties If null, the template of the resource is used.
     * @param \Omeka\Entity\Resource $source
     */
    protected function reorderValues(?array $orderOfProperties = null, ?Resource $source = null): void
    {
        if (!$source) {
            $source = $this->entity;
        }

        if (is_null($orderOfProperties)) {
            $orderOfProperties = $this->orderedListTemplatePropertyTerms($source);
            $orderOfProperties = array_fill_keys($orderOfProperties, []);
        }

        if (!count($orderOfProperties)) {
            return;
        }

        $entityValues = $source->getValues();
        if (!$entityValues->count()) {
            return;
        }

        // Extract by property.
        $result = [];
        /** @var \Omeka\Entity\Value $value */
        foreach ($entityValues as $value) {
            $property = $value->getProperty();
            $term = $property->getVocabulary()->getPrefix()
                . ':'
                . $property->getLocalName();
            $result[$term][] = $value;
        }

        $result = array_filter(array_replace($orderOfProperties, $result));

        $entityValues->clear();
        foreach (array_merge(...array_values($result)) as $value) {
            $entityValues->add($value);
        }
    }

    protected function moveValuesToProperty(?array $values, $termOrId): array
    {
        if (empty($values) || empty($termOrId)) {
            return [];
        }
        $termId = $this->bulk->getPropertyId($termOrId);
        if (!$termId) {
            return [];
        }
        $termLabel = $this->bulk->getPropertyLabel($termId);
        foreach ($values as &$value) {
            $value['property_id'] = $termId;
            $value['property_label'] = $termLabel;
        }
        unset($value);
        return $values;
    }

    /**
     * Get the list of used terms of the template of a resource.
     */
    protected function orderedListTemplatePropertyTerms(?Resource $source = null): array
    {
        static $templateOrders = [];

        if (!$source) {
            $source = $this->entity;
        }

        /** @var \Omeka\Entity\ResourceTemplate $template */
        $template = $source->getResourceTemplate();
        if (!$template) {
            return [];
        }

        $templateId = $template->getId();
        if (!isset($templateOrders[$templateId])) {
            $orderOfProperties = [];
            /** @var \Omeka\Entity\ResourceTemplateProperty $rtp */
            foreach ($template->getResourceTemplateProperties() as $rtp) {
                $property = $rtp->getProperty();
                $term = $property->getVocabulary()->getPrefix()
                    . ':'
                    . $property->getLocalName();
                $orderOfProperties[$term] = $term;
            }
            $templateOrders[$templateId] = array_values($orderOfProperties);
        }

        return $templateOrders[$templateId];
    }

    protected function fillItem(array $source): void
    {
        $this->fillResource($source);

        $itemSets = $this->entity->getItemSets();
        $itemSetIds = [];
        foreach ($itemSets as $itemSet) {
            $itemSetIds[] = $itemSet->getId();
        }
        foreach ($source['o:item_set'] ?? [] as $itemSet) {
            // This check avoids a core bug that omeka team doesn't want to fix
            // in core: don't add the same item set twice.
            if (!in_array($itemSet['o:id'], $itemSetIds)) {
                $itemSets->add($this->entityManager->find(\Omeka\Entity\ItemSet::class, $itemSet['o:id']));
            }
        }

        // Media are updated separately in order to manage files.
    }

    protected function fillMedia(array $source): void
    {
        $this->fillResource($source);

        $item = $this->entityManager->find(\Omeka\Entity\Item::class, $source['o:item']['o:id']);
        $this->entity->setItem($item);

        // TODO Keep the original storage id of assets (so check existing one as a whole).
        // $storageId = substr($asset['o:filename'], 0, $pos);
        // @see \Omeka\File\TempFile::getStorageId()
        if ($source['o:filename']
            && ($pos = mb_strrpos($source['o:filename'], '.')) !== false
            && empty($source['_skip_ingest'])
        ) {
            $storageId = bin2hex(\Laminas\Math\Rand::getBytes(20));
            $extension = substr($source['o:filename'], $pos + 1);

            $result = $this->fetchFile('original', $source['o:source'], $source['o:filename'], $storageId, $extension, $source['o:original_url']);
            if ($result['status'] !== 'success') {
                $this->logger->err($result['message']);
            }
            // Continue in order to update other metadata, in particular item.
            else {
                if ($source['o:media_type'] !== $result['data']['media_type']) {
                    $this->logger->err(new PsrMessage(
                        'Media type of media #{id} is different from the original one ({media_type}).', // @translate
                        ['id' => $this->entity->getId(), 'media_type' => $source['o:media_type']]
                    ));
                }
                if ($source['o:sha256'] !== $result['data']['sha256']) {
                    $this->logger->err(new PsrMessage(
                        'Hash of media #{id} is different from the original one.', // @translate
                        ['id' => $this->entity->getId()]
                    ));
                }
                $this->entity->setStorageId($storageId);
                $this->entity->setExtension($extension);
                $this->entity->setSha256($result['data']['sha256']);
                $this->entity->setMediaType($result['data']['media_type']);
                $this->entity->setHasOriginal(true);
                $this->entity->setHasThumbnails($result['data']['has_thumbnails']);
                $this->entity->setSize($result['data']['size']);
            }
        }

        // TODO Check and manage ingesters and renderers.
        $this->entity->setIngester($source['o:ingester']);
        $this->entity->setRenderer($source['o:renderer']);

        $this->entity->setData($source['data'] ?? null);
        $this->entity->setSource($source['o:source'] ?: null);
        $this->entity->setLang(!empty($source['o:lang']) ? $source['o:lang'] : null);

        $position = 0;
        $sourceId = $source[$this->sourceKeyId];
        foreach ($this->entity->getItem()->getMedia() as $media) {
            ++$position;
            if ($sourceId === $media->getId()) {
                $this->entity->setPosition($position);
                break;
            }
        }
    }

    protected function fillMediaItem(array $source): void
    {
        // TODO See spip or manioc.
    }

    protected function fillMediaItemMedia(array $source): void
    {
        // TODO See spip or manioc.
    }

    protected function fillItemSet(array $source): void
    {
        $this->fillResource($source);

        $this->entity->setIsOpen(!empty($source['o:is_open']));
    }
}
