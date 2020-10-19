<?php declare(strict_types=1);
namespace BulkImport\Processor;

use ArrayObject;
use BulkImport\Form\Processor\ItemProcessorConfigForm;
use BulkImport\Form\Processor\ItemProcessorParamsForm;

class ItemProcessor extends ResourceProcessor
{
    protected $resourceType = 'items';

    protected $resourceLabel = 'Items'; // @translate

    protected $configFormClass = ItemProcessorConfigForm::class;

    protected $paramsFormClass = ItemProcessorParamsForm::class;

    protected function handleFormSpecific(ArrayObject $args, array $values): void
    {
        $this->handleFormItem($args, $values);
    }

    protected function baseSpecific(ArrayObject $resource): void
    {
        $this->baseItem($resource);
    }

    protected function fillSpecific(ArrayObject $resource, $target, array $values)
    {
        switch ($target['target']) {
            case $this->fillItem($resource, $target, $values):
                return true;
            default:
                return false;
        }
    }

    protected function checkEntity(ArrayObject $resource)
    {
        parent::checkEntity($resource);
        $this->checkItem($resource);
        return !$resource['has_error'];
    }
}
