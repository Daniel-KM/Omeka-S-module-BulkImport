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

    protected function handleFormSpecific(ArrayObject $args, array $values): \BulkImport\Processor\Processor
    {
        $this->handleFormMedia($args, $values);
        return $this;
    }

    protected function baseSpecific(ArrayObject $resource): \BulkImport\Processor\Processor
    {
        $this->baseResourceCommon($resource);
        $this->baseMedia($resource);
        return $this;
    }

    protected function fillSpecific(ArrayObject $resource, $target, array $values): bool
    {
        return $this->fillMedia($resource, $target, $values);
    }
}
