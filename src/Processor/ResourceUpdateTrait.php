<?php declare(strict_types=1);

namespace BulkImport\Processor;

/**
 * Helper to manage specific update modes.
 *
 * The functions are adapted from the module Csv Import. Will be simplified later.
 *
 * @see \CSVImport\Job\Import
 */
trait ResourceUpdateTrait
{
    /**
     * @var array
     */
    protected $skippedSourceFields;

    /**
     * @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation
     */
    protected $resourceToUpdate;

    /**
     * @var \Omeka\Entity\Resource
     */
    protected $resourceToUpdateEntity;

    /**
     * @var array
     */
    protected $resourceToUpdateArray;

    /**
     * @param string $resourceName
     * @param int $resourceId
     */
    protected function prepareResourceToUpdate($resourceName, $resourceId): void
    {
        if (!$resourceName || !$resourceId) {
            $this->resourceToUpdateEntity = null;
            $this->resourceToUpdate = null;
            $this->resourceToUpdateArray = [];
        } else {
            // Always reload the resource that is currently managed to manage
            // multiple update of the same resource.
            try {
                $this->resourceToUpdateEntity = $this->bulk->api()->read($resourceName, $resourceId, [], ['responseContent' => 'resource'])->getContent();
                $this->resourceToUpdate = $this->adapterManager->get($resourceName)->getRepresentation($this->resourceToUpdateEntity);
                $this->resourceToUpdateArray = $this->resourceToUpdateToArray();
            } catch (\Exception $e) {
                $this->resourceToUpdateEntity = null;
                $this->resourceToUpdate = null;
                $this->resourceToUpdateArray = [];
            }
        }
    }

    /**
     * Convert a resource into an array (json serialize), without issue.
     */
    protected function resourceToUpdateToArray(): array
    {
        if (!$this->resourceToUpdate) {
            return [];
        }

        // Direct jsonSerialize() keeps sub-objects, so use json decode/encode.
        // return json_decode(json_encode($resource), true);

        // But in some cases, for linked resources, there may be rights issues,
        // or the resource may be not reloaded but a partial doctrine entity
        // converted into a partial representation. So there may be missing
        // linked resources, so a fatal error can occur when converting a value
        // resource to its reference. So extract properties manually.
        // TODO Find where the issues occurs (during a spreadsheed update on the second row).

        $propertyIds = $this->bulk->getPropertyIds();

        // This serialization does not serialize sub-objects as array.
        $resourceArray = $this->resourceToUpdate->jsonSerialize();

        // There is only issue for properties.
        $repr = array_diff_key($resourceArray, $propertyIds);
        $repr = json_decode(json_encode($repr), true);

        $propertiesWithoutResource = array_intersect_key($resourceArray, $propertyIds);
        foreach ($propertiesWithoutResource as $term => $values) {
            /** @var \Omeka\Api\Representation\ValueRepresentation|array $value */
            foreach ($values as $value) {
                // In some cases (module event), the value is already an array.
                if (is_object($value)) {
                    $valueType = $value->type();
                    // The issue occurs only for linked resources.
                    if ($vr = $value->valueResource()) {
                        $repr[$term][] = [
                            'type' => $valueType,
                            'property_id' => $propertyIds[$term],
                            'is_public' => $value->isPublic(),
                            // '@id' => $vr->apiUrl(),
                            'value_resource_id' => (int) $vr->id(),
                            'value_resource_name' => $vr->resourceName(),
                            '@language' => $value->lang() ?: null,
                            // 'url' => null,
                            // 'display_title' => $vr->displayTitle(),
                        ];
                    } elseif ($this->bulk->getMainDataType($valueType) === 'resource') {
                        $this->logger->warn(
                            'Index #{index}: The resource {resource} #{id} has a linked resource for term {term} that is not available and cannot be really updated.', // @translate
                            ['index' => $this->indexResource, 'resource' => $this->resourceToUpdate->resourceName(), 'id' => $this->resourceToUpdate->id(), 'term' => $term]
                        );
                    } else {
                        $repr[$term][] = json_decode(json_encode($value), true);
                    }
                } else {
                    $repr[$term][] = $value;
                }
            }
        }

        return $repr;
    }

    /**
     * Update a resource (append, revise or update with a deduplication check).
     *
     * Data should be already checked.
     *
     * Currently, Omeka S has no method to deduplicate, so a first call is done
     * to get all the data and to update them here, with a deduplication for
     * values, then a full replacement (not partial).
     *
     * The difference between "revise" and "update" is that, with "update", all
     * data that are set in the source (generally a column in a spreadsheet)
     * replace current ones, but, with "revise", only the filled ones replace
     * current one.
     *
     * Note: when the targets are set on multiple columns, all data are removed.
     *
     * @todo What to do with external data?
     *
     * @param string $resourceName
     * @param array $data Should have an existing and checked "o:id".
     * @return array
     */
    protected function updateData($resourceName, $data): array
    {
        // Use arrays to simplify process.
        $this->prepareResourceToUpdate($resourceName, $data['o:id']);

        if (!$this->resourceToUpdate) {
            $this->logger->warn(
                'Index #{index}: The resource {resource} #{id} is not available and cannot be really updated.', // @translate
                ['index' => $this->indexResource, 'resource' => $resourceName, 'id', $data['o:id']]
            );
        }

        $currentData = $this->resourceToUpdateArray;

        switch ($this->action) {
            case \BulkImport\Processor\AbstractProcessor::ACTION_APPEND:
                $merged = $this->mergeMetadata($currentData, $data, true);
                $data = array_replace($data, $merged);
                $newData = array_replace($currentData, $data);
                break;
            case \BulkImport\Processor\AbstractProcessor::ACTION_REVISE:
            case \BulkImport\Processor\AbstractProcessor::ACTION_UPDATE:
                $data = $this->action === \BulkImport\Processor\AbstractProcessor::ACTION_REVISE
                    ? $this->removeEmptyData($data)
                    : $this->fillEmptyData($data);
                if ($this->actionIdentifier !== \BulkImport\Processor\AbstractProcessor::ACTION_UPDATE) {
                    $data = $this->keepExistingIdentifiers($currentData, $data, $this->bulk->getIdentifierNames());
                }
                if ($resourceName === 'items') {
                    if ($this->actionMedia !== \BulkImport\Processor\AbstractProcessor::ACTION_UPDATE) {
                        $data = $this->keepExistingMedia($currentData, $data);
                    }
                    if ($this->actionItemSet !== \BulkImport\Processor\AbstractProcessor::ACTION_UPDATE) {
                        $data = $this->keepExistingItemSets($currentData, $data);
                    }
                }
                $replaced = $this->replacePropertyValues($currentData, $data);
                $newData = array_replace($data, $replaced);
                break;
            case \BulkImport\Processor\AbstractProcessor::ACTION_REPLACE:
                if ($resourceName === 'items') {
                    if ($this->actionMedia !== \BulkImport\Processor\AbstractProcessor::ACTION_UPDATE) {
                        $newData = $this->keepExistingMedia($currentData, $data);
                    }
                    if ($this->actionItemSet !== \BulkImport\Processor\AbstractProcessor::ACTION_UPDATE) {
                        $newData = $this->keepExistingItemSets($currentData, $data);
                    }
                }
                break;
            default:
                $this->logger->err(
                    'Index #{index}: Unable to update data with action "{action}".', // @translate
                    ['index' => $this->indexResource, 'action' => $this->action]
                );
                ++$this->totalErrors;
                return $currentData;
        }

        // To keep the markers during update, they must be developed.
        if (!empty($newData['o-module-mapping:mapping']['o:id']) && empty($newData['o-module-mapping:mapping']['o-module-mapping:bounds'])) {
            $newData['o-module-mapping:mapping'] = $this->bulk->api()->read('mappings', ['id' => $newData['o-module-mapping:mapping']['o:id']])->getContent();
            $newData['o-module-mapping:mapping'] = json_decode(json_encode($newData['o-module-mapping:mapping']), true);
        }
        if (!empty($newData['o-module-mapping:marker'][0]['o:id']) && !isset($newData['o-module-mapping:marker'][0]['o-module-mapping:lat'])) {
            $markers = [];
            foreach ($newData['o-module-mapping:marker'] as $value) {
                $value = $this->bulk->api()->read('mapping_markers', ['id' => $value['o:id']])->getContent();
                $markers[$value->id()] = json_decode(json_encode($value), true);
            }
            $newData['o-module-mapping:marker'] = $markers;
        }

        return $newData;
    }

    /**
     * Remove empty values from passed data in order not to change current ones.
     *
     * @todo Use the mechanism of preprocessBatchUpdate() of the adapter?
     *
     * @param array $data
     * @return array
     */
    protected function removeEmptyData(array $data)
    {
        foreach ($data as $name => $metadata) {
            switch ($name) {
                case 'o:resource_template':
                case 'o:resource_class':
                case 'o:thumbnail':
                case 'o:owner':
                case 'o:item':
                    if (empty($metadata) || empty($metadata['o:id'])) {
                        unset($data[$name]);
                    }
                    break;
                case 'o:media':
                case 'o:item-set':
                    if (empty($metadata)) {
                        unset($data[$name]);
                    } elseif (array_key_exists('o:id', $metadata) && empty($metadata['o:id'])) {
                        unset($data[$name]);
                    }
                    break;
                // These values are not updatable and are removed.
                case 'o:ingester':
                case 'o:source':
                case 'ingest_filename':
                case 'ingest_directory':
                case 'ingest_url':
                case 'o:size':
                    unset($data[$name]);
                    break;
                case 'o:is_public':
                case 'o:is_open':
                    if (!is_bool($metadata)) {
                        unset($data[$name]);
                    }
                    break;
                // Properties.
                default:
                    if (is_array($metadata) && empty($metadata)) {
                        unset($data[$name]);
                    }
                    break;
            }
        }
        return $data;
    }

    /**
     * Fill empty values from passed data in order to remove current ones.
     *
     * @param array $data
     * @return array
     */
    protected function fillEmptyData(array $data)
    {
        if (!$this->hasMapping) {
            return $data;
        }

        // Note: mapping is not available in the trait.
        $mapping = array_filter(array_intersect_key(
            $this->mapping,
            array_flip($this->skippedSourceFields)
        ));

        foreach ($mapping as $targets) {
            foreach ($targets as $target) {
                $name = $target['target'];
                switch ($name) {
                    case 'o:resource_template':
                    case 'o:resource_class':
                    case 'o:thumbnail':
                    case 'o:owner':
                    case 'o:item':
                        $data[$name] = null;
                        break;
                    case 'o:media':
                    case 'o:item-set':
                        $data[$name] = [];
                        break;
                    // These values are not updatable and are removed.
                    case 'o:ingester':
                    case 'o:source':
                    case 'ingest_filename':
                    case 'ingest_directory':
                    case 'ingest_url':
                    case 'o:size':
                        unset($data[$name]);
                        break;
                    // Nothing to do for boolean.
                    case 'o:is_public':
                    case 'o:is_open':
                        // Noything to do.
                        break;
                    // Properties.
                    default:
                        $data[$name] = [];
                        break;
                }
            }
        }
        return $data;
    }

    /**
     * Prepend existing identifiers to new data.
     *
     * @param array $currentData
     * @param array $data
     * @param array $identifierNames
     * @return array
     */
    protected function keepExistingIdentifiers(array $currentData, $data, array $identifierNames)
    {
        // Keep only identifiers that are properties.
        $identifierNames = array_filter($identifierNames, 'is_numeric');
        foreach (array_keys(array_intersect_key($identifierNames, $currentData)) as $propertyTerm) {
            if (isset($data[$propertyTerm]) && count($data[$propertyTerm])) {
                $newData = array_merge(
                    array_values($currentData[$propertyTerm]),
                    array_values($data[$propertyTerm])
                );
                $data[$propertyTerm] = $this->deduplicateSinglePropertyValues($newData);
            } else {
                $data[$propertyTerm] = $currentData[$propertyTerm];
            }
        }
        return $data;
    }

    /**
     * Prepend existing media to new data.
     *
     * @param array $currentData
     * @param array $data
     * @return array
     */
    protected function keepExistingMedia(array $currentData, $data)
    {
        if (empty($currentData['o:media'])) {
            return $data;
        }
        if (empty($data['o:media'])) {
            $data['o:media'] = $currentData['o:media'];
            return $data;
        }
        $currentIds = array_map(function ($v) {
            return (int) $v['o:id'];
        }, $currentData['o:media']);
        $dataMedias = $data['o:media'];
        $data['o:media'] = $currentData['o:media'];
        foreach ($dataMedias as $newMedia) {
            if (empty($newMedia['o:id']) || !in_array($newMedia['o:id'], $currentIds)) {
                $data['o:media'][] = $newMedia;
            }
        }
        return $data;
    }

    /**
     * Prepend existing item set to new data.
     *
     * @param array $currentData
     * @param array $data
     * @return array
     */
    protected function keepExistingItemSets(array $currentData, $data)
    {
        if (empty($currentData['o:item_set'])) {
            return $data;
        }
        if (empty($data['o:item_set'])) {
            $data['o:item_set'] = $currentData['o:item_set'];
            return $data;
        }
        $currentIds = array_map(function ($v) {
            return (int) $v['o:id'];
        }, $currentData['o:item_set']);
        $dataItemSets = $data['o:item_set'];
        $data['o:item_set'] = $currentData['o:item_set'];
        foreach ($dataItemSets as $newItemSet) {
            if (empty($newItemSet['o:id']) || !in_array($newItemSet['o:id'], $currentIds)) {
                $data['o:item_set'][] = $newItemSet;
            }
        }
        return $data;
    }

    /**
     * Merge current and new property values from two full resource metadata.
     *
     * @param array $currentData
     * @param array $newData
     * @param bool $keepIfNull Specify what to do when a value is null.
     * @return array Merged values extracted from the current and new data.
     */
    protected function mergeMetadata(array $currentData, array $newData, $keepIfNull = false): array
    {
        // Merge properties.
        // Current values are cleaned too, because they have the property label.
        // So they are deduplicated too.
        $currentValues = $this->extractPropertyValuesFromResource($currentData);
        $newValues = $this->extractPropertyValuesFromResource($newData);
        $mergedValues = array_merge_recursive($currentValues, $newValues);
        $merged = $this->deduplicatePropertyValues($mergedValues);

        // Merge lists of ids.
        $names = ['o:item_set', 'o:item', 'o:media'];
        foreach ($names as $name) {
            if (isset($currentData[$name])) {
                if (isset($newData[$name])) {
                    $mergedValues = array_merge_recursive($currentData[$name], $newData[$name]);
                    $merged[$name] = $this->deduplicateIds($mergedValues);
                } else {
                    $merged[$name] = $currentData[$name];
                }
            } elseif (isset($newData[$name])) {
                $merged[$name] = $newData[$name];
            }
        }

        // Merge unique and boolean values (manage "null" too).
        $names = [
            'unique' => [
                'o:resource_template',
                'o:resource_class',
                'o:thumbnail',
            ],
            'boolean' => [
                'o:is_public',
                'o:is_open',
                'o:is_active',
            ],
        ];
        foreach ($names as $type => $typeNames) {
            foreach ($typeNames as $name) {
                if (array_key_exists($name, $currentData)) {
                    if (array_key_exists($name, $newData)) {
                        if (is_null($newData[$name])) {
                            $merged[$name] = $keepIfNull
                                ? $currentData[$name]
                                : ($type == 'boolean' ? false : null);
                        } else {
                            $merged[$name] = $newData[$name];
                        }
                    } else {
                        $merged[$name] = $currentData[$name];
                    }
                } elseif (array_key_exists($name, $newData)) {
                    $merged[$name] = $newData[$name];
                }
            }
        }

        // TODO Merge third parties data.

        return $merged;
    }

    /**
     * Replace current property values by new ones that are set.
     *
     * @param array $currentData
     * @param array $newData
     * @return array Merged values extracted from the current and new data.
     */
    protected function replacePropertyValues(array $currentData, array $newData): array
    {
        $currentValues = $this->extractPropertyValuesFromResource($currentData);
        $newValues = $this->extractPropertyValuesFromResource($newData);
        return array_replace($currentValues, $newValues);
    }

    /**
     * Extract property values from a full array of metadata of a resource json.
     */
    protected function extractPropertyValuesFromResource(array $resourceJson): array
    {
        return array_intersect_key($resourceJson, $this->bulk->getPropertyIds());
    }

    /**
     * Deduplicate data ids for collections of items set, items, media…
     */
    protected function deduplicateIds(array $data): array
    {
        $dataBase = $data;
        // Deduplicate data.
        $data = array_map('unserialize', array_unique(array_map(
            'serialize',
            // Normalize data.
            array_map(function ($v) {
                return isset($v['o:id']) ? ['o:id' => $v['o:id']] : $v;
            }, $data)
        )));
        // Keep original data first.
        return array_intersect_key($dataBase, $data);
    }

    /**
     * Deduplicate property values.
     */
    protected function deduplicatePropertyValues(array $valuesByProperty): array
    {
        return array_map([$this, 'deduplicateSinglePropertyValues'], $valuesByProperty);
    }

    /**
     * Deduplicate values of a single property.
     */
    protected function deduplicateSinglePropertyValues(array $values): array
    {
        // Base to normalize data in order to deduplicate them in one pass.
        $base = [
            'literal' => [
                'type' => 'literal',
                'property_id' => 0,
                'is_public' => true,
                '@value' => '',
                '@language' => null,
            ],
            'resource' => [
                'type' => 'resource',
                'property_id' => 0,
                'is_public' => true,
                'value_resource_id' => 0,
            ],
            'uri' => [
                'type' => 'uri',
                'property_id' => 0,
                'is_public' => true,
                '@id' => '',
                'o:label' => null,
            ],
        ];

        return array_values(
            // Deduplicate values.
            array_map('unserialize', array_unique(array_map(
                'serialize',
                // Normalize values.
                array_map(function ($v) use ($base) {
                    // Data types "resource" and "uri" have "@id" (in json).
                    if (array_key_exists('value_resource_id', $v)) {
                        $mainType = 'resource';
                    } else {
                        $mainType = array_key_exists('@id', $v) ? 'uri' : 'literal';
                    }
                    // Keep order and meaning keys.
                    $r = array_replace($base[$mainType], array_intersect_key($v, $base[$mainType]));
                    $r['is_public'] = (bool) $r['is_public'];
                    switch ($mainType) {
                        case 'literal':
                            if (empty($r['@language'])) {
                                $r['@language'] = null;
                            }
                            break;
                        case 'uri':
                            if (empty($r['o:label'])) {
                                $r['o:label'] = null;
                            }
                            break;
                    }
                    return $r;
                }, $values)
            )))
        );
    }
}
