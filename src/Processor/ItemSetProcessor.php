<?php declare(strict_types=1);

namespace BulkImport\Processor;

use ArrayObject;
use BulkImport\Form\Processor\ItemSetProcessorConfigForm;
use BulkImport\Form\Processor\ItemSetProcessorParamsForm;

class ItemSetProcessor extends ResourceProcessor
{
    protected $resourceName = 'item_sets';

    protected $resourceLabel = 'Item sets'; // @translate

    protected $configFormClass = ItemSetProcessorConfigForm::class;

    protected $paramsFormClass = ItemSetProcessorParamsForm::class;

    protected $metadataData = [
        // Assets metadata and file.
        'fields' => [
            'file',
            'url',
            'o:id',
            'o:owner',
            // TODO Incomplete, but not used currently
        ],
        'skip' => [],
        // Cf. baseSpecific(), fillItem(), fillItemSet() and fillMedia().
        'boolean' => [
            'o:is_public' => true,
            'o:is_open' => true,
        ],
        'single_data' => [
            // Generic.
            'o:id' => null,
            // Resource.
            'resource_name' => null,
        ],
        'single_entity' => [
            // Generic.
            'o:resource_template' => null,
            'o:resource_class' => null,
            'o:thumbnail' => null,
            'o:owner' => null,
            // Media.
            'o:item' => null,
        ],
        'multiple_entities' => [
            'o:item_set' => null,
            'o:media' => null,
        ],
        'misc' => [
            'o:id' => null,
            'o:email' => null,
            'o:created' => null,
            'o:modified' => null,
        ],
    ];

    protected function handleFormSpecific(ArrayObject $args, array $values): self
    {
        return $this
            ->handleFormItemSet($args, $values);
    }

    protected function baseSpecific(ArrayObject $resource): self
    {
        return $this
            ->baseResourceCommon($resource)
            ->baseItemSet($resource);
    }

    protected function fillSpecific(ArrayObject $resource, array $data, ?string $mainResourceName = null): self
    {
        return $this
            ->fillItemSet($resource, $data);
    }
}
