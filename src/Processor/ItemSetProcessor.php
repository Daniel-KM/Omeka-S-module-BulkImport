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

    /**
     * @see \Omeka\Api\Representation\ItemSetRepresentation
     *
     * @var array
     */
    protected $fieldTypes = [
        // Common metadata.
        'resource_name' => 'string',
        // "o:id" may be an identifier.
        'o:id' => 'string',
        'o:created' => 'datetime',
        'o:modified' => 'datetime',
        'o:is_public' => 'boolean',
        'o:owner' => 'entity',
        // Alias of "o:owner" here.
        'o:email' => 'entity',
        // Generic.
        'o:resource_template' => 'entity',
        'o:resource_class' => 'entity',
        'o:thumbnail' => 'entity',
        // A common, but special and complex key, so managed in meta config too.
        'property' => 'arrays',
        // Item set.
        'o:is_open' => 'boolean',
        'o:items' => 'entities',

        // Modules.
        // Advanced Resource Template.
        'item_set_query_items' => 'string',
    ];

    protected function handleFormSpecific(ArrayObject $args, array $values): self
    {
        return $this
            ->handleFormItemSet($args, $values);
    }

    protected function prepareBaseEntitySpecific(ArrayObject $resource): self
    {
        return $this
            ->prepareBaseResourceCommon($resource)
            ->prepareBaseItemSet($resource);
    }

    protected function fillResourceSpecific(ArrayObject $resource, array $data, ?string $mainResourceName = null): self
    {
        return $this
            ->fillItemSet($resource, $data);
    }
}
