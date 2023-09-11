<?php declare(strict_types=1);

namespace BulkImport\Processor;

use ArrayObject;
use BulkImport\Form\Processor\ItemProcessorConfigForm;
use BulkImport\Form\Processor\ItemProcessorParamsForm;

class ItemProcessor extends ResourceProcessor
{
    protected $resourceName = 'items';

    protected $resourceLabel = 'Items'; // @translate

    protected $configFormClass = ItemProcessorConfigForm::class;

    protected $paramsFormClass = ItemProcessorParamsForm::class;

    /**
     * @see \Omeka\Api\Representation\ItemRepresentation
     *
     * @var array
     */
    protected $fieldTypes = [
        // Internal keys of the base entities to skip.
        'checked_id' => 'skip',
        'source_index' => 'skip',
        'messageStore' => 'skip',
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
        'o:resource_template' => 'entity',
        'o:resource_class' => 'entity',
        'o:thumbnail' => 'entity',
        'o:owner' => 'entity',
        // Alias of 'o:owner'.
        'o:email' => 'entity',
        // A common, but special and complex key, so managed in meta config too.
        'property' => 'arrays',
        // Item.
        'o:item_set' => 'entities',
        // There can be multiple medias, urls, files, etc. for an item.
        'o:media' => 'entities',
        'o:lang' => 'strings',
        'o:ingester' => 'strings',
        'o:source' => 'strings',
        'ingest_filename' => 'strings',
        'ingest_directory' => 'strings',
        'ingest_url' => 'strings',
        'html' => 'strings',
        // Modules.
        // Module Mapping.
        // There can be only one mapping zone.
        'o-module-mapping:bounds' => 'string',
        'o-module-mapping:marker' => 'strings',
    ];

    protected function handleFormSpecific(ArrayObject $args, array $values): self
    {
        return $this
            ->handleFormItem($args, $values);
    }

    protected function baseSpecific(ArrayObject $resource): self
    {
        return $this
            ->baseResourceCommon($resource)
            ->baseItem($resource);
    }

    protected function fillResourceSpecific(ArrayObject $resource, array $data, ?string $mainResourceName = null): self
    {
        return $this
            ->fillItem($resource, $data);
    }
}
