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
        ],
        'single_data' => [
            // Generic.
            'o:id' => null,
            // Resource.
            'resource_name' => null,
             // But there can be multiple urls, files, etc. for an item.
             // Media.
             'o:lang' => null,
             'o:ingester' => null,
             'o:source' => null,
             'ingest_filename' => null,
             'ingest_directory' => null,
             'ingest_url' => null,
             'html' => null,
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
            ->handleFormMedia($args, $values);
    }

    protected function baseSpecific(ArrayObject $resource): self
    {
        return $this
            ->baseResourceCommon($resource)
            ->baseMedia($resource);
    }

    protected function fillSpecific(ArrayObject $resource, array $data, ?string $mainResourceName = null): self
    {
        return $this
            ->fillMedia($resource, $data);
    }
}
