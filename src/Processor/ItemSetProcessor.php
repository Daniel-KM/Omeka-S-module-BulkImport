<?php declare(strict_types=1);

namespace BulkImport\Processor;

use ArrayObject;
use BulkImport\Form\Processor\ItemSetProcessorConfigForm;
use BulkImport\Form\Processor\ItemSetProcessorParamsForm;

class ItemSetProcessor extends ResourceProcessor
{
    protected $resourceName = 'item_sets';

    protected $resourceLabel = 'Item sets'; // @translate

    protected $configFormClass = ItemSetProcessorConfigForm::class;

    protected $paramsFormClass = ItemSetProcessorParamsForm::class;

    protected function handleFormSpecific(ArrayObject $args, array $values): \BulkImport\Processor\Processor
    {
        $this->handleFormItemSet($args, $values);
        return $this;
    }

    protected function baseSpecific(ArrayObject $resource): \BulkImport\Processor\Processor
    {
        $this->baseResourceCommon($resource);
        $this->baseItemSet($resource);
        return $this;
    }

    protected function fillSpecific(ArrayObject $resource, $target, array $values): bool
    {
        return $this->fillItemSet($resource, $target, $values);
    }
}
