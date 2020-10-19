<?php declare(strict_types=1);
namespace BulkImport\Processor;

use ArrayObject;
use BulkImport\Form\Processor\ItemSetProcessorConfigForm;
use BulkImport\Form\Processor\ItemSetProcessorParamsForm;

class ItemSetProcessor extends ResourceProcessor
{
    protected $resourceType = 'item_sets';

    protected $resourceLabel = 'Item sets'; // @translate

    protected $configFormClass = ItemSetProcessorConfigForm::class;

    protected $paramsFormClass = ItemSetProcessorParamsForm::class;

    protected function handleFormSpecific(ArrayObject $args, array $values): void
    {
        $this->handleFormItemSet($args, $values);
    }

    protected function baseSpecific(ArrayObject $resource): void
    {
        $this->baseItemSet($resource);
    }

    protected function fillSpecific(ArrayObject $resource, $target, array $values)
    {
        switch ($target['target']) {
            case $this->fillItemSet($resource, $target, $values):
                return true;
            default:
                return false;
        }
    }

    protected function checkEntity(ArrayObject $resource)
    {
        parent::checkEntity($resource);
        $this->checkItemSet($resource);
        return !$resource['has_error'];
    }
}
