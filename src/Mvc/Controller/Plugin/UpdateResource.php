<?php declare(strict_types=1);

namespace BulkImport\Mvc\Controller\Plugin;

use BulkImport\Processor\AbstractProcessor;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Laminas\Log\Logger;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Adapter\Manager as AdapterManager;

/**
 * Helper to manage specific update modes.
 *
 * The functions are adapted from the module Csv Import. Will be simplified later.
 *
 * @see \CSVImport\Job\Import
 */
class UpdateResource extends AbstractPlugin
{
    use BulkResourceTrait;

    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var \Omeka\Api\Adapter\Manager
     */
    protected $adapterManager;

    /**
     * @var \BulkImport\Mvc\Controller\Plugin\Bulk
     */
    protected $bulk;

    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

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
     * @var string
     */
    protected $action;

    /**
     * @var string
     */
    protected $actionItemSet;

    /**
     * @var string
     */
    protected $actionMedia;

    /**
     * @var string
     */
    protected $actionIdentifier;

    /**
     * @var array
     */
    protected $identifierNames;

    /**
     * @var string
     */
    protected $indexResource;

    /**
     * @var array
     */
    protected $metaMapping;

    /**
     * @var int
     */
    protected $totalErrors;

    public function __construct(
        ApiManager $api,
        AdapterManager $adapterManager,
        Bulk $bulk,
        Logger $logger
    ) {
        $this->api = $api;
        $this->adapterManager = $adapterManager;
        $this->bulk = $bulk;
        $this->logger = $logger;
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
     * current one, so existing values are not removed when a cell is empty.
     *
     * Note: when the targets are set on multiple columns, all data are removed.
     *
     * @todo What to do with external data?
     *
     * Warning: Unlike previous version, the target field is used, not the
     * "from" string (spreadsheet header). So the result may be slightly
     * different, but simpler to understand when checking results.
     *
     * @param array $data Should have an existing and checked "o:id".
     * @return array
     */
    public function __invoke(array $data, array $options): ?array
    {
        $this->action = $options['action'];
        $this->actionItemSet = $options['actionItemSet'];
        $this->actionMedia = $options['actionMedia'];
        $this->actionIdentifier = $options['actionIdentifier'];
        $this->identifierNames = $options['identifierNames'];
        $this->metaMapping = $options['metaMapping'];

        $this->indexResource = $data['source_index'] ?? 0;
        $resourceName = $data['resource_name'] ?? 'resources';

        // Use arrays to simplify process.
        $this->prepareResourceToUpdate($resourceName, (int) $data['o:id']);

        if (!$this->resourceToUpdate) {
            $this->logger->warn(
                'Index #{index}: The resource {resource} #{id} is not available and cannot be really updated.', // @translate
                ['index' => $this->indexResource, 'resource' => $resourceName, 'id', $data['o:id']]
            );
        }

        $currentData = $this->resourceToUpdateArray;

        switch ($this->action) {
            case AbstractProcessor::ACTION_APPEND:
                $merged = $this->mergeMetadata($currentData, $data, true);
                $data = array_replace($data, $merged);
                $newData = array_replace($currentData, $data);
                break;
            case AbstractProcessor::ACTION_REVISE:
            case AbstractProcessor::ACTION_UPDATE:
                $data = $this->action === AbstractProcessor::ACTION_REVISE
                    ? $this->removeEmptyData($data)
                    : $this->fillEmptyData($data);
                if ($this->actionIdentifier !== AbstractProcessor::ACTION_UPDATE) {
                    $data = $this->keepExistingIdentifiers($currentData, $data);
                }
                if ($resourceName === 'items') {
                    if ($this->actionMedia !== AbstractProcessor::ACTION_UPDATE) {
                        $data = $this->keepExistingMedia($currentData, $data);
                    }
                    if ($this->actionItemSet !== AbstractProcessor::ACTION_UPDATE) {
                        $data = $this->keepExistingItemSets($currentData, $data);
                    }
                }
                $replaced = $this->replacePropertyValues($currentData, $data);
                $newData = array_replace($data, $replaced);
                break;
            case AbstractProcessor::ACTION_REPLACE:
                if ($resourceName === 'items') {
                    if ($this->actionMedia !== AbstractProcessor::ACTION_UPDATE) {
                        $newData = $this->keepExistingMedia($currentData, $data);
                    }
                    if ($this->actionItemSet !== AbstractProcessor::ACTION_UPDATE) {
                        $newData = $this->keepExistingItemSets($currentData, $data);
                    }
                }
                break;
            default:
                $this->logger->err(
                    'Index #{index}: Unable to update data with action "{action}".', // @translate
                    ['index' => $this->indexResource, 'action' => $this->action]
                );
                return null;
        }

        // To keep the markers during update, they must be developed.
        if (!empty($newData['o-module-mapping:mapping']['o:id']) && empty($newData['o-module-mapping:mapping']['o-module-mapping:bounds'])) {
            try {
                $newData['o-module-mapping:mapping'] = $this->api->read('mappings', ['id' => $newData['o-module-mapping:mapping']['o:id']])->getContent();
                $newData['o-module-mapping:mapping'] = json_decode(json_encode($newData['o-module-mapping:mapping']), true);
            } catch (\Exception $e) {
                $this->logger->err(
                    'Index #{index}: Unable to find mappings #{mapping_id}.', // @translate
                    ['index' => $this->indexResource, 'mapping_id' => $newData['o-module-mapping:mapping']['o:id']]
                );
                return null;
            }
        }
        if (!empty($newData['o-module-mapping:marker'][0]['o:id']) && !isset($newData['o-module-mapping:marker'][0]['o-module-mapping:lat'])) {
            $markers = [];
            foreach ($newData['o-module-mapping:marker'] as $value) {
                try {
                    $value = $this->api->read('mapping_markers', ['id' => $value['o:id']])->getContent();
                    $markers[$value->id()] = json_decode(json_encode($value), true);
                } catch (\Exception $e) {
                    $this->logger->err(
                        'Index #{index}: Unable to find mapping marker #{mapping_marker_id}.', // @translate
                        ['index' => $this->indexResource, 'mapping_marker_id' => $value['o:id']]
                    );
                    return null;
                }
            }
            $newData['o-module-mapping:marker'] = $markers;
        }

        return $newData;
    }

    /**
     * @param string $resourceName
     * @param int $resourceId
     */
    protected function prepareResourceToUpdate(string $resourceName, int $resourceId): self
    {
        if (!$resourceName || !$resourceId) {
            $this->resourceToUpdateEntity = null;
            $this->resourceToUpdate = null;
            $this->resourceToUpdateArray = [];
        } else {
            // Always reload the resource that is currently managed to manage
            // multiple update of the same resource.
            try {
                $this->resourceToUpdateEntity = $this->api->read($resourceName, $resourceId, [], ['responseContent' => 'resource'])->getContent();
                $this->resourceToUpdate = $this->adapterManager->get($resourceName)->getRepresentation($this->resourceToUpdateEntity);
                $this->resourceToUpdateArray = $this->resourceJson($this->resourceToUpdate);
            } catch (\Exception $e) {
                $this->resourceToUpdateEntity = null;
                $this->resourceToUpdate = null;
                $this->resourceToUpdateArray = [];
            }
        }
        return $this;
    }

    /**
     * Remove empty values from passed data in order not to change current ones.
     *
     * This is an internal method that allow to process the action "revise" with
     * a simple array_replace().
     *
     * @todo Use the mechanism of preprocessBatchUpdate() of the adapter?
     */
    protected function removeEmptyData(array $data): array
    {
        foreach ($data as $field => $metadata) switch ($field) {
            case 'o:resource_template':
            case 'o:resource_class':
            case 'o:thumbnail':
            case 'o:owner':
            case 'o:item':
            case 'o:media':
            case 'o:item_set':
                if (!$metadata || empty($metadata['o:id'])) {
                    unset($data[$field]);
                }
                break;
            case 'o:ingester':
            case 'o:source':
            case 'ingest_filename':
            case 'ingest_directory':
            case 'ingest_url':
            case 'o:size':
                // These values are not updatable and are removed in all cases.
                unset($data[$field]);
                break;
            case 'o:is_public':
            case 'o:is_open':
            case 'o:is_active':
                if (!is_bool($metadata)) {
                    unset($data[$field]);
                }
                break;
            default:
                // Properties.
                if ($metadata === null || $metadata === []) {
                    unset($data[$field]);
                }
                break;
        }
        return $data;
    }

    /**
     * Fill empty values from passed data in order to remove current ones.
     *
     * This is an internal method that allow to process the action "update" with
     * a simple array_replace().
     *
     * Unlike previous version, the target field is used, not the "from" string
     * (spreadsheet header). So the result may be slightly different, but
     * simpler to understand.
     */
    protected function fillEmptyData(array $data): array
    {
        foreach ($this->metaMapping as $map) {
            $field = $map['to']['field'] ?? null;
            if (!$field
                // Add an empty field only when there is no value, of course.
                || array_key_exists($field, $data)
            ) {
                continue;
            }
            switch ($field) {
                case 'o:resource_template':
                case 'o:resource_class':
                case 'o:thumbnail':
                case 'o:owner':
                case 'o:item':
                case 'o:media':
                case 'o:item_set':
                    $data[$field] = null;
                    break;
                case 'o:ingester':
                case 'o:source':
                case 'ingest_filename':
                case 'ingest_directory':
                case 'ingest_url':
                case 'o:size':
                    // These values are not updatable and are removed.
                    unset($data[$field]);
                    break;
                case 'o:is_public':
                case 'o:is_open':
                case 'o:is_active':
                    // Nothing to do for boolean: keep original for now.
                    // TODO Manage action "update" for boolean.
                    break;
                default:
                    // Properties.
                    $data[$field] = [];
                    break;
            }
        }
        return $data;
    }

    /**
     * Prepend existing identifiers to new data.
     */
    protected function keepExistingIdentifiers(array $currentData, array $data): array
    {
        // Keep only identifiers that are properties.
        $identifierNames = array_filter($this->identifierNames, 'is_numeric');
        foreach (array_keys(array_intersect_key($identifierNames, $currentData)) as $propertyTerm) {
            if (isset($data[$propertyTerm]) && count($data[$propertyTerm])) {
                $newData = array_merge(
                    array_values($currentData[$propertyTerm]),
                    array_values($data[$propertyTerm])
                );
                $data[$propertyTerm] = $this->deduplicateSinglePropertyValues($propertyTerm, $newData);
            } else {
                $data[$propertyTerm] = $currentData[$propertyTerm];
            }
        }
        return $data;
    }

    /**
     * Prepend existing media to new data.
     */
    protected function keepExistingMedia(array $currentData, array $data): array
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
     */
    protected function keepExistingItemSets(array $currentData, array $data): array
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
        return array_intersect_key($resourceJson, $this->bulk->propertyIds());
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
        foreach ($valuesByProperty as $term => &$vals) {
            $newVals = $this->normalizePropertyValues($term, $vals);
            // array_unique() does not work on array, so serialize them first.
            $vals = count($newVals) <= 1
                ? $newVals
                : array_map('unserialize', array_unique(array_map('serialize', $newVals)));
        }
        unset($vals);
        return array_filter($valuesByProperty);
    }

    /**
     * Deduplicate values of a single property.
     */
    protected function deduplicateSinglePropertyValues(string $term, array $values): array
    {
        $newVals = $this->normalizePropertyValues($term, $values);
        return count($newVals) <= 1
            ? $newVals
            : array_map('unserialize', array_unique(array_map('serialize', $newVals)));}
}
