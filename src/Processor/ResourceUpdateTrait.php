<?php
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
     * Update a resource (append, revise or update with a deduplication check).
     *
     * Currently, Omeka S has no method to deduplicate, so a first call is done
     * to get all the data and to update them here, with a deduplication for
     * values, then a full replacement (not partial).
     *
     * The difference between revise and update is that all data that are set
     * replace current ones with "update", but only the filled ones replace
     * current one with "revise".
     *
     * Note: when the targets are seton multiple columns, all data are removed.
     *
     * @todo What to do with other data, and external data?
     *
     * @param string $resourceType
     * @param array $data Should have an existing and checked "o:id".
     * @param string $action "update" or "revise"
     * @return array
     */
    protected function updateData($resourceType, $data, $action)
    {
        $resource = $this->api()->read($resourceType, $data['o:id'])->getContent();

        // Use arrays to simplify process.
        $currentData = json_decode(json_encode($resource), true);
        switch ($action) {
            case self::ACTION_APPEND:
                $merged = $this->mergeMetadata($currentData, $data, true);
                $data = array_replace($data, $merged);
                $newData = array_replace($currentData, $data);
                break;
            case self::ACTION_REVISE:
                $data = $this->removeEmptyData($data);
                $replaced = $this->replacePropertyValues($currentData, $data);
                $newData = array_replace($data, $replaced);
                break;
            case self::ACTION_UPDATE:
                $data = $this->fillEmptyData($data);
                $replaced = $this->replacePropertyValues($currentData, $data);
                $newData = array_replace($data, $replaced);
                break;
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
     * Merge current and new property values from two full resource metadata.
     *
     * @param array $currentData
     * @param array $newData
     * @param bool $keepIfNull Specify what to do when a value is null.
     * @return array Merged values extracted from the current and new data.
     */
    protected function mergeMetadata(array $currentData, array $newData, $keepIfNull = false)
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
    protected function replacePropertyValues(array $currentData, array $newData)
    {
        $currentValues = $this->extractPropertyValuesFromResource($currentData);
        $newValues = $this->extractPropertyValuesFromResource($newData);
        $updatedValues = array_replace($currentValues, $newValues);
        return $updatedValues ;
    }

    /**
     * Extract property values from a full array of metadata of a resource json.
     *
     * @param array $resourceJson
     * @return array
     */
    protected function extractPropertyValuesFromResource($resourceJson)
    {
        static $listOfTerms;
        if (empty($listOfTerms)) {
            $response = $this->api()->search('properties', []);
            foreach ($response->getContent() as $member) {
                $term = $member->term();
                $listOfTerms[$term] = $term;
            }
        }
        return array_intersect_key($resourceJson, $listOfTerms);
        // TODO Replace this method by:
        return array_intersect_key($resourceJson, $this->getPropertyIds());
    }

    /**
     * Deduplicate data ids for collections of items set, items, media…
     *
     * @param array $data
     * @return array
     */
    protected function deduplicateIds($data)
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
        $data = array_intersect_key($dataBase, $data);
        return $data;
    }

    /**
     * Deduplicate property values.
     *
     * @param array $values
     * @return array
     */
    protected function deduplicatePropertyValues($values)
    {
        // Base to normalize data in order to deduplicate them in one pass.
        $base = [];

        $isOldOmeka = version_compare(\Omeka\Module::VERSION, '1.3.0', '<');
        if ($isOldOmeka) {
            $base['literal'] = ['property_id' => 0, 'type' => 'literal', '@language' => null, '@value' => ''];
            $base['resource'] = ['property_id' => 0, 'type' => 'resource', 'value_resource_id' => 0];
            $base['uri'] = ['o:label' => null, 'property_id' => 0, 'type' => 'uri', '@id' => ''];
        } else {
            $base['literal'] = ['is_public' => true, 'property_id' => 0, 'type' => 'literal', '@language' => null, '@value' => ''];
            $base['resource'] = ['is_public' => true, 'property_id' => 0, 'type' => 'resource', 'value_resource_id' => 0];
            $base['uri'] = ['is_public' => true, 'o:label' => null, 'property_id' => 0, 'type' => 'uri', '@id' => ''];
        }
        foreach ($values as $key => $value) {
            $values[$key] = array_values(
                // Deduplicate values.
                array_map('unserialize', array_unique(array_map(
                    'serialize',
                    // Normalize values.
                    array_map(function ($v) use ($base, $isOldOmeka) {
                        // Data types "resource" and "uri" have "@id" (in json).
                        $mainType = array_key_exists('value_resource_id', $v)
                            ? 'resource'
                            : (array_key_exists('@id', $v) ? 'uri' : 'literal');
                        // Keep order and meaning keys.
                        $r = array_replace($base[$mainType], array_intersect_key($v, $base[$mainType]));
                        if (!$isOldOmeka) {
                            $r['is_public'] = (bool) $r['is_public'];
                        }
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
                    }, $value)
                )))
            );
        }
        return $values;
    }
}
