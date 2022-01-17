<?php declare(strict_types=1);

namespace BulkImport\Form\Processor;

use BulkImport\Form\EntriesByBatchTrait;
use BulkImport\Form\EntriesToProcessTrait;

class ItemSetProcessorParamsForm extends ItemSetProcessorConfigForm
{
    use EntriesByBatchTrait;
    use EntriesToProcessTrait;

    public function init(): void
    {
        $this
            ->baseFieldset()
            ->addFieldsets()
            ->addEntriesToProcess()
            ->addEntriesByBatch()
            ->addMapping();

        $this
            ->baseInputFilter()
            ->addInputFilter()
            ->addEntriesToProcessInputFilter()
            ->addEntriesByBatchInputFilter()
            ->addMappingFilter();
    }

    protected function prependMappingOptions(): array
    {
        $mapping = parent::prependMappingOptions();
        return array_merge_recursive($mapping, [
            'metadata' => [
                'label' => 'Resource metadata', // @translate
                'options' => [
                    'o:is_open' => 'Openness', // @translate
                ],
            ],
        ]);
    }
}
