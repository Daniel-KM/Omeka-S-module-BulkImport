<?php declare(strict_types=1);

namespace BulkImport\Processor;

use Log\Stdlib\PsrMessage;

trait ResourceTrait
{
    /**
     * The current key id.
     *
     * @var string
     */
    protected $sourceKeyId;

    protected function prepareItems(): void
    {
        // Create empty items and keeps the mapping of ids.
    }

    protected function prepareMedias(): void
    {
        // Media should be managed after items currently.
        // Create empty medias and keeps the mapping of ids.
    }

    protected function prepareMediaItems(): void
    {
        // Create empty items and empty medias and keeps the mapping of ids.
    }

    protected function prepareItemSets(): void
    {
        // Create empty item sets and keeps the mapping of ids.
    }

    protected function fillItems(): void
    {
    }

    protected function fillMedias(): void
    {
    }

    protected function fillMediaItems(): void
    {
    }

    protected function fillItemSets(): void
    {
    }

    protected function prepareResources(iterable $sources, string $sourceType): void
    {
        $this->map[$sourceType] = [];

        $keyId = $this->mapping[$sourceType]['key_id'];
        $this->sourceKeyId = $keyId;

        // Check the size of the import.
        $this->countEntities($sources, $sourceType);
        if ($this->hasError) {
            return;
        }

        $this->logger->notice(
            'Preparation of {total} resources "{type}".', // @translate
            ['total' => $this->totals[$sourceType], 'type' => $sourceType]
        );

        // Use direct query to speed process and to reserve a whole list of ids.
        // The indexation, api events, etc. will be done when the resources will
        // be really filled via update in a second time.

        // Prepare the list of all ids.
        // Only the ids are needed here, except for media, that require the item
        // id (mapped below).
        $mediaItems = [];
        if ($sourceType === 'media') {
            foreach ($sources as $source) {
                $this->map[$sourceType][(int) $source[$keyId]] = null;
                // TODO item o:id should be generic.
                $mediaItems[(int) $source[$keyId]] = (int) $source['o:item']['o:id'];
            }
        } else {
            foreach ($sources as $source) {
                $this->map[$sourceType][(int) $source[$keyId]] = null;
            }
        }
        if (!count($this->map[$sourceType])) {
            $this->logger->notice(
                'No resource "{type}" available on the source.', // @translate
                ['type' => $sourceType]
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

        $hasSub = !empty($this->importables[$sourceType]['sub']);
        if ($hasSub) {
            $sourceTypeSub = $this->importables[$sourceType]['sub'];
            $this->map[$sourceTypeSub] = $this->map[$sourceType];
        }

        $this->createEmptyResources($sourceType);
        $this->createEmptyResourcesSpecific($sourceType, $mediaItems);

        if ($hasSub) {
            $this->createEmptyResources($sourceTypeSub);
            $this->createEmptyResourcesSpecific($sourceTypeSub);
        }

        $this->logger->notice(
            '{total} resources "{type}" have been created.', // @translate
            ['total' => count($this->map[$sourceType]), 'type' => $sourceType]
        );
    }

    protected function createEmptyResources(
        string $sourceType,
        int $resourceClassId = null,
        int $resourceTemplateId = null
    ): void {
        // The pre-import is done with the default owner and updated later.
        $ownerIdOrNull = $this->owner ? $this->ownerId : 'NULL';
        $resourceClass = $resourceClassId ?: 'NULL';
        $resourceTemplate = $resourceTemplateId ?: 'NULL';
        $class = $this->importables[$sourceType]['class'];
        $resourceTypeClass = $this->connection->quote($class);

        // For compatibility with old databases, a temporary table is used in
        // order to create a generator of enough consecutive rows.
        // Don't use "primary key", but "unique key" in order to allow the key
        // "0" as key, used in some cases (the scheme of the thesaurus).
        $sql = <<<SQL
DROP TABLE IF EXISTS `temporary_source_resource`;
CREATE TEMPORARY TABLE `temporary_source_resource` (`id` INT unsigned NOT NULL, UNIQUE (`id`));

SQL;
        foreach (array_chunk(array_keys($this->map[$sourceType]), self::CHUNK_RECORD_IDS) as $chunk) {
            $sql .= 'INSERT INTO `temporary_source_resource` (`id`) VALUES(' . implode('),(', $chunk) . ");\n";
        }
        $sql .= <<<SQL
INSERT INTO `resource`
    (`owner_id`, `resource_class_id`, `resource_template_id`, `is_public`, `created`, `modified`, `resource_type`, `thumbnail_id`, `title`)
SELECT
    $ownerIdOrNull, $resourceClass, $resourceTemplate, 0, "$this->currentDateTimeFormatted", NULL, $resourceTypeClass, NULL, id
FROM `temporary_source_resource`;

DROP TABLE IF EXISTS `temporary_source_resource`;
SQL;
        $this->connection->query($sql);
    }

    protected function createEmptyResourcesSpecific(string $sourceType, ?array $mediaItems = null): void
    {
        $resourceType = $this->importables[$sourceType]['name'];
        $class = $this->importables[$sourceType]['class'];
        $table = $this->importables[$sourceType]['table'];
        $resourceClass = $this->connection->quote($class);

        // Get the mapping of source and destination ids without specific data.
        $sql = <<<SQL
SELECT `resource`.`title` AS `s`, `resource`.`id` AS `d`
FROM `resource` AS `resource`
LEFT JOIN `$table` AS `spec` ON `spec`.`id` = `resource`.`id`
WHERE `spec`.`id` IS NULL
    AND `resource`.`resource_type` = $resourceClass;
SQL;
        // Fetch by key pair is not supported by doctrine 2.0.
        $this->map[$sourceType] = array_column($this->connection->query($sql)->fetchAll(\PDO::FETCH_ASSOC), 'd', 's');

        // Create the resource in the specific resource table.
        switch ($resourceType) {
            case 'items':
                $sql = <<<SQL
INSERT INTO `item`
SELECT `resource`.`id`
SQL;
                break;

            case 'media':
                // Attach all media to first item id for now, updated below.
                $parent = $this->importables[$sourceType]['parent'] ?? 'items';
                $itemId = (int) reset($this->map[$parent]);
                $sql = <<<SQL
INSERT INTO `media`
    (`id`, `item_id`, `ingester`, `renderer`, `data`, `source`, `media_type`, `storage_id`, `extension`, `sha256`, `has_original`, `has_thumbnails`, `position`, `lang`, `size`)
SELECT
    `resource`.`id`, $itemId, '', '', NULL, NULL, NULL, NULL, NULL, NULL, 0, 0, 0, NULL, NULL
SQL;
                break;

            case 'item_sets':
                // Finalize custom vocabs early: item sets map is available.
                $this->prepareCustomVocabsFinalize();
                $sql = <<<SQL
INSERT INTO `item_set`
SELECT `resource`.`id`, 0
SQL;
                break;

            default:
                return;
        }
        $sql .= PHP_EOL . <<<SQL
FROM `resource` AS `resource`
LEFT JOIN `$table` AS `spec` ON `spec`.`id` = `resource`.`id`
WHERE `spec`.`id` IS NULL
    AND `resource`.`resource_type` = $resourceClass;
SQL;
        $this->connection->query($sql);

        // Manage the exception for media, that require the good item id.
        if ($sourceType === 'media_items_sub') {
            foreach (array_chunk($this->map['media_items_sub'], self::CHUNK_RECORD_IDS, true) as $chunk) {
                $sql = str_repeat("UPDATE `media` SET `item_id`=? WHERE `id`=?;\n", count($chunk));
                $bind = [];
                foreach ($chunk as $sourceMediaItemId => $mediaId) {
                    $bind[] = $this->map[$parent][$sourceMediaItemId];
                    $bind[] = $mediaId;
                }
                $this->connection->executeUpdate($sql, $bind);
            }
        } elseif ($resourceType === 'media' && !empty($mediaItems)) {
            foreach (array_chunk($mediaItems, self::CHUNK_RECORD_IDS, true) as $chunk) {
                $sql = str_repeat("UPDATE `media` SET `item_id`=? WHERE `id`=?;\n", count($chunk));
                $bind = [];
                foreach ($chunk as $sourceMediaId => $sourceItemId) {
                    $bind[] = $this->map[$parent][$sourceItemId];
                    $bind[] = $this->map[$sourceType][$sourceMediaId];
                }
                $this->connection->executeUpdate($sql, $bind);
            }
        }
    }

    protected function fillResources(iterable $sources, string $sourceType): void
    {
        $this->refreshMainResources();

        // $resourceType = $this->importables[$sourceType]['name'];
        $class = $this->importables[$sourceType]['class'];
        $method = $this->importables[$sourceType]['fill'];
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
                    'Skipped resource "{type}" #{source_id} added in source.', // @translate
                    ['type' => $sourceType, 'source_id' => $sourceId]
                );
                continue;
            }

            $entity = $this->entityManager->find($class, $this->map[$sourceType][$sourceId]);
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
                $this->entityManager->flush();
                $this->entityManager->clear();
                $this->refreshMainResources();
                $this->logger->info(
                    '{count}/{total} resource "{type}" imported, {skipped} skipped, {excluded} excluded.', // @translate
                    ['count' => $created, 'total' => count($this->map[$sourceType]), 'type' => $sourceType, 'skipped' => $skipped, 'excluded' => $excluded]
                );
            }
        }

        // Remaining entities.
        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->refreshMainResources();

        $this->logger->notice(
            '{count}/{total} resource "{type}" imported, {skipped} skipped, {excluded} excluded.', // @translate
            ['count' => $created, 'total' => count($this->map[$sourceType]), 'type' => $sourceType, 'skipped' => $skipped, 'excluded' => $excluded]
        );

        // Check total in case of an issue in the network or with Omeka < 2.1.
        // In particular, an issue occurred when a linked resource is private.
        if ($this->totals[$sourceType] !== count($this->map[$sourceType])) {
            $this->hasError = true;
            $this->logger->err(
                'The total {total} of resources {type} is not the same than the count {count}.', // @translate
                ['total' => $this->totals[$sourceType], 'count' => count($this->map[$sourceType]), 'type' => $sourceType]
            );
        }
    }

    protected function fillResource(array $source): void
    {
        // Omeka entities are not fluid.
        $this->entity->setOwner($this->userOrDefaultOwner($source['o:owner']));

        if (!empty($source['@type'][1])
            && !empty($this->map['resource_classes'][$source['@type'][1]])
        ) {
            $resourceClass = $this->entityManager->find(\Omeka\Entity\ResourceClass::class, $this->map['resource_classes'][$source['@type'][1]]['id']);
            $this->entity->setResourceClass($resourceClass);
        }

        if (!empty($source['o:resource_template']['o:id'])
            && !empty($this->map['resource_templates'][$source['o:resource_template']['o:id']])
        ) {
            $resourceTemplate = $this->entityManager->find(\Omeka\Entity\ResourceTemplate::class, $this->map['resource_templates'][$source['o:resource_template']['o:id']]);
            $this->entity->setResourceTemplate($resourceTemplate);
        }

        if (!empty($source['o:thumbnail']['o:id'])) {
            if (isset($this->map['assets'][$source['o:thumbnail']['o:id']])) {
                $asset = $this->entityManager->find(\Omeka\Entity\Asset::class, $this->map['assets'][$source['o:thumbnail']['o:id']]);
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

        $sqlDate = function ($value) {
            return substr(str_replace('T', ' ', $value), 0, 19) ?: $this->currentDateTimeFormatted;
        };

        $created = new \DateTime($sqlDate($source['o:created']['@value']));
        $this->entity->setCreated($created);

        if ($source['o:modified']['@value']) {
            $modified = new \DateTime($sqlDate($source['o:modified']['@value']));
            $this->entity->setModified($modified);
        }

        $this->fillValues($source);
    }

    protected function fillValues(array $source): void
    {
        $classes = [
            'items' => \Omeka\Entity\Item::class,
            'media' => \Omeka\Entity\Media::class,
            'item_sets' => \Omeka\Entity\ItemSet::class,
        ];
        $resourceType = array_search(get_class($this->entity), $classes);

        // Terms that don't exist can't be imported, or data that are not terms.
        $sourceValues = array_intersect_key($source, $this->map['properties']);
        $entityValues = $this->entity->getValues();

        foreach ($sourceValues as $term => $values) {
            $property = $this->entityManager->find(\Omeka\Entity\Property::class, $this->map['properties'][$term]['id']);
            foreach ($values as $value) {
                $datatype = $value['type'];
                // Convert unknown custom vocab into a literal.
                if (strtok($datatype, ':') === 'customvocab') {
                    if (!empty($this->map['custom_vocabs'][$datatype]['datatype'])) {
                        $datatype = $value['type'] = $this->map['custom_vocabs'][$datatype]['datatype'];
                    } else {
                        $this->logger->warn(
                            'Value with datatype "{type}" for resource #{id} is changed to "literal".', // @translate
                            ['type' => $datatype, 'id' => $this->entity->getId()]
                        );
                        $datatype = $value['type'] = 'literal';
                    }
                }

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
                        case strtok($datatype, ':') === 'numeric':
                            $datatype = $value['type'] = 'literal';
                            $toInstall = 'Numeric Data Types';
                            break;
                        case isset($mapDataTypes[$datatype]):
                            if (in_array($mapDataTypes[$datatype], $this->allowedDataTypes)) {
                                $datatype = $value['type'] = $mapDataTypes;
                                break;
                            }
                            $datatype = $value['type'] = 'literal';
                            $toInstall = 'Data Type Rdf';
                            break;
                        // Module RdfDataType.
                        case 'xsd:integer':
                            if (!empty($this->modules['NumericDataTypes'])) {
                                $datatype = $value['type'] = 'numeric:integer';
                                break;
                            }
                            $datatype = $value['type'] = 'literal';
                            $toInstall = 'Data Type Rdf / Numeric Data Types';
                            break;
                        case 'xsd:date':
                        case 'xsd:dateTime':
                        case 'xsd:gYear':
                        case 'xsd:gYearMonth':
                            if (!empty($this->modules['NumericDataTypes'])) {
                                try {
                                    $value['@value'] = \NumericDataTypes\DataType\Timestamp::getDateTimeFromValue($value['@value']);
                                    $datatype = $value['type'] = 'numeric:timestamp';
                                } catch (\Exception $e) {
                                    $datatype = $value['type'] = 'literal';
                                    $this->logger->warn(
                                        'Value of resource {type} #{id} with data type {datatype} is not managed and skipped.', // @translate
                                        ['type' => $resourceType, 'id' => $source[$this->sourceKeyId], 'datatype' => $value['type']]
                                    );
                                }
                                break;
                            }
                            $datatype = $value['type'] = 'literal';
                            $toInstall = 'Data Type Rdf / Numeric Data Types';
                            break;
                        case 'xsd:decimal':
                        case 'xsd:gDay':
                        case 'xsd:gMonth':
                        case 'xsd:gMonthDay':
                        case 'xsd:time':
                        // Module DataTypeGeometry.
                        case 'geometry:geography':
                        case 'geometry:geometry':
                            $datatype = $value['type'] = 'literal';
                            $toInstall = 'Data Type Geometry';
                            break;
                        // Module IdRef.
                        case 'idref':
                            if (!empty($this->modules['ValueSuggest'])) {
                                $datatype = $value['type'] = 'valuesuggest:idref:person';
                                break;
                            }
                            $datatype = $value['type'] = 'literal';
                            $toInstall = 'Value Suggest';
                            break;
                        default:
                            $datatype = $value['type'] = 'literal';
                            $toInstall = $datatype;
                            break;
                    }

                    if ($toInstall) {
                        $this->logger->warn(
                            'Value of resource {type} #{id} with data type {datatype} was changed to literal.', // @translate
                            ['type' => $resourceType, 'id' => $source[$this->sourceKeyId], 'datatype' => $value['type']]
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
                if (!empty($value['value_resource_id'])) {
                    if (!empty($value['value_resource_name'])
                        && $this->map[$value['value_resource_name']][$value['value_resource_id']]
                    ) {
                        $valueResource = $this->entityManager->find($classes[$value['value_resource_name']], $this->map[$value['value_resource_name']][$value['value_resource_id']]);
                    }
                    if (!$valueResource) {
                        if (isset($this->map['items'][$value['value_resource_id']])) {
                            $valueResource = $this->entityManager->find(\Omeka\Entity\Item::class, $this->map['items'][$value['value_resource_id']]);
                        } elseif (isset($this->map['media'][$value['value_resource_id']])) {
                            $valueResource = $this->entityManager->find(\Omeka\Entity\Media::class, $this->map['media'][$value['value_resource_id']]);
                        } elseif (isset($this->map['item_sets'][$value['value_resource_id']])) {
                            $valueResource = $this->entityManager->find(\Omeka\Entity\ItemSet::class, $this->map['item_sets'][$value['value_resource_id']]);
                        }
                    }
                    if (!$valueResource) {
                        $this->logger->warn(
                            'Value of resource {type} #{id} with linked resource for term {term} is not found.', // @translate
                            ['type' => $resourceType, 'id' => $source[$this->sourceKeyId], 'term' => $term]
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
                    case 'geometry:geography':
                    case 'geometry:geometry':
                        $datatypeAdapter = $this->datatypeManager->get($datatype);
                        $class = $datatypeAdapter->getEntityClass();
                        $dataValue = new $class;
                        $dataValue->setResource($this->entity);
                        $dataValue->setProperty($property);
                        $dataValueValue = $datatypeAdapter->getGeometryFromValue($valueValue);
                        if ($this->srid
                            && $datatype === 'geometry:geography'
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

    protected function appendValue(array $metadata, $source = null): void
    {
        $metadata += [
            'type' => 'literal',
            'value' => null,
            'uri' => null,
            'value_resource' => null,
            'lang' => null,
            'is_public' => true,
        ];

        $term = $metadata['term'];
        $property = $this->entityManager->find(\Omeka\Entity\Property::class, $this->map['properties'][$term]['id']);

        if (!$source) {
            $source = $this->entity;
        }

        $entityValue = new \Omeka\Entity\Value;
        $entityValue->setResource($source);
        $entityValue->setProperty($property);
        $entityValue->setType($metadata['type']);
        $entityValue->setValue($metadata['value']);
        $entityValue->setUri($metadata['uri']);
        $entityValue->setValueResource($metadata['value_resource']);
        $entityValue->setLang(empty($metadata['lang']) ? null : $metadata['lang']);
        $entityValue->setIsPublic(!empty($metadata['is_public']));

        $entityValues = $source->getValues();
        $entityValues->add($entityValue);
    }

    protected function fillItem(array $source): void
    {
        $this->fillResource($source);

        $itemSets = $this->entity->getItemSets();
        $itemSetIds = [];
        foreach ($itemSets as $itemSet) {
            $itemSetIds[] = $itemSet->getId();
        }
        foreach ($source['o:item_set'] as $itemSet) {
            if (isset($this->map['item_sets'][$itemSet['o:id']])
                // This check avoids a core bug.
                && !in_array($this->map['item_sets'][$itemSet['o:id']], $itemSetIds)
            ) {
                $itemSets->add($this->entityManager->find(\Omeka\Entity\ItemSet::class, $this->map['item_sets'][$itemSet['o:id']]));
            }
        }

        // Media are updated separately in order to manage files.
    }

    protected function fillMedia(array $source): void
    {
        $this->fillResource($source);

        $item = $this->entityManager->find(\Omeka\Entity\Item::class, $this->map['items'][$source['o:item']['o:id']]);
        $this->entity->setItem($item);

        // TODO Keep the original storage id of assets (so check existing one as a whole).
        // $storageId = substr($asset['o:filename'], 0, $pos);
        // @see \Omeka\File\TempFile::getStorageId()
        if ($source['o:filename']
            && ($pos = mb_strrpos($source['o:filename'], '.')) !== false
        ) {
            $storageId = bin2hex(\Laminas\Math\Rand::getBytes(20));
            $extension = substr($source['o:filename'], $pos + 1);

            $result = $this->fetchUrl('original', $source['o:source'], $source['o:filename'], $storageId, $extension, $source['o:original_url']);
            if ($result['status'] !== 'success') {
                $this->logger->err($result['message']);
            }
            // Continue in order to update other metadata, in particular item.
            else {
                if ($source['o:media_type'] !== $result['data']['media_type']) {
                    $this->logger->err(new PsrMessage(
                        'Media type of media #{id} is different from the original one ({media_type}).', // @translate
                        ['id' => $this->entity->getId(), $source['o:media_type']]
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
        // TODO See spip.
    }

    protected function fillItemSet(array $source): void
    {
        $this->fillResource($source);

        $this->entity->setIsOpen(!empty($source['o:is_open']));
    }
}
