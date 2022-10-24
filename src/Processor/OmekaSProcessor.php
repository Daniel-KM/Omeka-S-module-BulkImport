<?php declare(strict_types=1);

namespace BulkImport\Processor;

use BulkImport\Form\Processor\OmekaSProcessorConfigForm;
use BulkImport\Form\Processor\OmekaSProcessorParamsForm;

class OmekaSProcessor extends AbstractFullProcessor
{
    protected $resourceLabel = 'Omeka S'; // @translate
    protected $configFormClass = OmekaSProcessorConfigForm::class;
    protected $paramsFormClass = OmekaSProcessorParamsForm::class;

    protected $configDefault = [
        'endpoint' => null,
        'key_identity' => null,
        'key_credential' => null,
    ];

    protected $paramsDefault = [
        'o:owner' => null,
        'types' => [
            'users',
            'items',
            'media',
            'item_sets',
            'assets',
            'vocabularies',
            'resource_templates',
            'custom_vocabs',
        ],
    ];

    protected $mapping = [
        'users' => [
            'source' => 'users',
            'key_id' => 'o:id',
            'key_email' => 'o:email',
            'key_name' => 'o:name',
        ],
        'assets' => [
            'source' => 'assets',
            'key_id' => 'o:id',
        ],
        'items' => [
            'source' => 'items',
            'key_id' => 'o:id',
        ],
        'media' => [
            'source' => 'media',
            'key_id' => 'o:id',
            'key_parent_id' => 'item_id',
        ],
        'item_sets' => [
            'source' => 'item_sets',
            'key_id' => 'o:id',
        ],
        'vocabularies' => [
            'source' => 'vocabularies',
            'key_id' => 'o:id',
        ],
        'properties' => [
            'source' => 'properties',
            'key_id' => 'o:id',
        ],
        'resource_classes' => [
            'source' => 'resource_classes',
            'key_id' => 'o:id',
        ],
        'resource_templates' => [
            'source' => 'resource_templates',
            'key_id' => 'o:id',
        ],
        'custom_vocabs' => [
            'source' => 'custom_vocabs',
            'key_id' => 'o:id',
        ],
        'mappings' => [
            'source' => 'mappings',
            'key_id' => 'o:id',
        ],
        'mapping_markers' => [
            'source' => 'mapping_markers',
            'key_id' => 'o:id',
        ],
        'concepts' => [
            'source' => null,
        ],
        'hits' => [
            'source' => 'hit',
            'key_id' => 'id',
            // The mode "sql" allows to import hits directly and is recommended
            // because the list of hit is generally very big.
            'mode' => 'sql',
        ],
    ];

    protected function fillResource(array $source): void
    {
        if (!empty($source['o:resource_class']['o:id'])) {
            $originalId = $source['o:resource_class']['o:id'];
            $source['o:resource_class']['o:id'] = $this->map['by_id']['resource_classes'][$originalId] ?? null;
            if (!$source['o:resource_class']['o:id']) {
                $this->logger->warn(
                    'Resource class (source class id: {original_id}) for resource #{id} (source #{source_id}) is not available.', // @translate
                    ['original_id' => $originalId, 'id' => $this->entity->getId(), 'source_id' => $source[$this->sourceKeyId]]
                );
            }
        }

        if (!empty($source['o:resource_template']['o:id'])) {
            $originalId = $source['o:resource_template']['o:id'];
            $source['o:resource_template']['o:id'] = $this->map['resource_templates'][$originalId] ?? null;
            if (!$source['o:resource_template']['o:id']) {
                $this->logger->warn(
                    'Resource template (source template id: {original_id}) for resource #{id} (source #{source_id}) is not available.', // @translate
                    ['original_id' => $originalId, 'id' => $this->entity->getId(), 'source_id' => $source[$this->sourceKeyId]]
                );
            }
        }

        if (!empty($source['o:thumbnail']['o:id'])) {
            $originalId = $source['o:thumbnail']['o:id'];
            $source['o:thumbnail']['o:id'] = $this->map['assets'][$originalId] ?? null;
            if (!$source['o:thumbnail']['o:id']) {
                $this->logger->warn(
                    'Thumbnail (source thumbnail id: {original_id}) for resource #{id} (source #{source_id}) is not available.', // @translate
                    ['original_id' => $originalId, 'id' => $this->entity->getId(), 'source_id' => $source[$this->sourceKeyId]]
                );
            }
        }

        // Update data type (custom vocab) and value resources to destination values.
        foreach ($source as $term => $values) {
            if (!is_array($values)) {
                continue;
            }
            $value = reset($values);
            if (!is_array($value) || empty($value)) {
                continue;
            }
            $termId = $this->bulk->getPropertyId($term);
            if (!$termId) {
                continue;
            }
            foreach ($values as $indexValue => $value) {
                $datatype = (string) $value['type'];
                if (mb_substr($datatype, 0, 12) === 'customvocab:') {
                    if (!empty($this->map['custom_vocabs'][$datatype]['datatype'])) {
                        $source[$term][$indexValue]['type'] = $this->map['custom_vocabs'][$datatype]['datatype'];
                    } else {
                        $datatypeResult = $this->getCustomVocabDataTypeName($datatype);
                        if ($datatypeResult) {
                            $source[$term][$indexValue]['type'] = $datatypeResult;
                        } else {
                            // TODO Use new option to force "literal".
                            $this->logger->warn(
                                'Value with datatype "{type}" for resource #{id} is changed to "literal".', // @translate
                                ['type' => $datatype, 'id' => $this->entity->getId()]
                            );
                            $source[$term][$indexValue]['type'] = 'literal';
                        }
                    }
                }
                if (!empty($value['value_resource_id'])) {
                    $vrName = $value['value_resource_name'] ?? null;
                    if (in_array($vrName, ['items', 'media', 'item_sets'])) {
                        $vrid = $this->map[$vrName][$value['value_resource_id']] ?? null;
                    } else {
                        if ($vrid = $this->map['items'][$value['value_resource_id']] ?? null) {
                            $vrName = 'items';
                        } elseif ($vrid = $this->map['media'][$value['value_resource_id']] ?? null) {
                            $vrName = 'media';
                        } elseif ($vrid = $this->map['item_sets'][$value['value_resource_id']] ?? null) {
                            $vrName = 'item_sets';
                        } else {
                            $vrid = null;
                        }
                    }
                    if ($vrid && $vrName) {
                        $source[$term][$indexValue]['value_resource_id'] = $vrid;
                        $source[$term][$indexValue]['value_resource_name'] = $vrName;
                    } else {
                        $this->logger->warn(
                            'Value of resource "{source}" #{id} with linked resource for term {term} is not found.', // @translate
                            ['source' => $vrName ?: '?', 'id' => $source[$this->sourceKeyId], 'term' => $term]
                        );
                    }
                }
            }
        }

        parent::fillResource($source);
    }

    protected function fillItem(array $source): void
    {
        // Don't check entity twice, so no log here.
        $source['o:id'] = $this->map['items'][$source['o:id']] ?? null;
        parent::fillItem($source);
    }

    protected function fillMedia(array $source): void
    {
        // Don't check entity twice, so no log here.
        $source['o:id'] = $this->map['media'][$source['o:id']] ?? null;
        $source['o:item']['o:id'] = $this->map['items'][$source['o:item']['o:id']] ?? null;
        parent::fillmedia($source);
    }

    protected function fillItemSet(array $source): void
    {
        // Don't check entity twice, so no log here.
        $source['o:id'] = $this->map['item_sets'][$source['o:id']] ?? null;
        parent::fillItemSet($source);
    }

    protected function fillMappingMapping(array $source): void
    {
        // Don't check entity twice, so no log here.
        $source['o:item']['o:id'] = $this->map['items'][$source['o:item']['o:id']] ?? null;
        parent::fillMappingMapping($source);
    }

    protected function fillMappingMarker(array $source): void
    {
        // Don't check entity twice, so no log here.
        $source['o:item']['o:id'] = $this->map['items'][$source['o:item']['o:id']] ?? null;
        if (!empty($source['o:item']['o:id']) && !empty($source['o:media']['o:id'])) {
            $source['o:media']['o:id'] = $this->map['media'][$source['o:media']['o:id']] ?? null;
        }
        parent::fillMappingMapping($source);
    }
}
