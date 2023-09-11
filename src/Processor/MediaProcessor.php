<?php declare(strict_types=1);

namespace BulkImport\Processor;

use ArrayObject;
use BulkImport\Form\Processor\MediaProcessorConfigForm;
use BulkImport\Form\Processor\MediaProcessorParamsForm;

class MediaProcessor extends ResourceProcessor
{
    protected $resourceName = 'media';

    protected $resourceLabel = 'Media'; // @translate

    protected $configFormClass = MediaProcessorConfigForm::class;

    protected $paramsFormClass = MediaProcessorParamsForm::class;

    /**
     * @see \Omeka\Api\Representation\MediaRepresentation
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
        'o:resource_template' => 'entity',
        'o:resource_class' => 'entity',
        'o:thumbnail' => 'entity',
        // A common, but special and complex key, so managed in meta config too.
        'property' => 'arrays',
        // Media.
        'o:item' => 'entity',
        'o:lang' => 'string',
        'o:ingester' => 'string',
        'o:source' => 'string',
        'ingest_filename' => 'string',
        'ingest_directory' => 'string',
        'ingest_url' => 'string',
        'html' => 'string',
    ];

    protected function handleFormSpecific(ArrayObject $args, array $values): self
    {
        return $this
            ->handleFormMedia($args, $values);
    }

    protected function baseSpecific(ArrayObject $resource): self
    {
        return $this
            ->baseResourceCommon($resource)
            ->baseMedia($resource);
    }

    protected function fillResourceSpecific(ArrayObject $resource, array $data, ?string $mainResourceName = null): self
    {
        return $this
            ->fillMedia($resource, $data);
    }
}
