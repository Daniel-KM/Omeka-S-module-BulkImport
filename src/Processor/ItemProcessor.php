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

    protected function handleFormSpecific(ArrayObject $args, array $values): \BulkImport\Processor\Processor
    {
        $this->handleFormItem($args, $values);
        return $this;
    }

    protected function baseSpecific(ArrayObject $resource): \BulkImport\Processor\Processor
    {
        $this->baseItem($resource);
        return $this;
    }

    public function process(): void
    {
        $this->reader->setObjectType('items');
        parent::process();
    }

    protected function fillSpecific(ArrayObject $resource, $target, array $values): bool
    {
        switch ($target['target']) {
            case $this->fillItem($resource, $target, $values):
                return true;
            default:
                return false;
        }
        return false;
    }

    protected function checkEntity(ArrayObject $resource): bool
    {
        parent::checkEntity($resource);
        $this->checkItem($resource);
        return !$resource['messageStore']->hasErrors();
    }
}
