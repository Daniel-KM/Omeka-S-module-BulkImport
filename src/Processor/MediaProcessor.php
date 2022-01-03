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
        $this->baseMedia($resource);
        return $this;
    }

    protected function fillSpecific(ArrayObject $resource, $target, array $values): bool
    {
        switch ($target['target']) {
            case $this->fillMedia($resource, $target, $values):
                return true;
            default:
                return false;
        }
        return false;
    }

    protected function checkEntity(ArrayObject $resource): bool
    {
        parent::checkEntity($resource);
        $this->checkMedia($resource);
        return !$resource['messageStore']->hasErrors();
    }
}
