<?php declare(strict_types=1);
namespace BulkImport\Processor;

use ArrayObject;
use BulkImport\Form\Processor\MediaProcessorConfigForm;
use BulkImport\Form\Processor\MediaProcessorParamsForm;

class MediaProcessor extends ResourceProcessor
{
    protected $resourceType = 'media';

    protected $resourceLabel = 'Media'; // @translate

    protected $configFormClass = MediaProcessorConfigForm::class;

    protected $paramsFormClass = MediaProcessorParamsForm::class;

    protected function handleFormSpecific(ArrayObject $args, array $values): void
    {
        $this->handleFormMedia($args, $values);
    }

    protected function baseSpecific(ArrayObject $resource): void
    {
        $this->baseMedia($resource);
    }

    protected function fillSpecific(ArrayObject $resource, $target, array $values)
    {
        switch ($target['target']) {
            case $this->fillMedia($resource, $target, $values):
                return true;
            default:
                return false;
        }
    }

    protected function checkEntity(ArrayObject $resource)
    {
        parent::checkEntity($resource);
        $this->checkMedia($resource);
        return !$resource['has_error'];
    }
}
