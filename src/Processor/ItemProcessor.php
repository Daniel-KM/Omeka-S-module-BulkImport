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

    protected function handleFormSpecific(ArrayObject $args, array $values): self
    {
        $this->handleFormItem($args, $values);
        return $this;
    }

    protected function baseSpecific(ArrayObject $resource): self
    {
        $this->baseResourceCommon($resource);
        $this->baseItem($resource);
        return $this;
    }

    protected function fillSpecific(ArrayObject $resource, $target, array $values): bool
    {
        return $this->fillItem($resource, $target, $values);
    }
}
