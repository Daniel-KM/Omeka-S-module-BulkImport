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
    ];
}
