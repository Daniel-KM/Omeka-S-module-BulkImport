<?php declare(strict_types=1);

namespace BulkImport\Form\Processor;

use BulkImport\Form\EntriesToProcessTrait;

class ItemSetProcessorParamsForm extends ItemSetProcessorConfigForm
{
    use EntriesToProcessTrait;

    public function init(): void
    {
        $this
            ->baseFieldset()
            ->addFieldsets()
            ->addEntriesToProcess()
            ->addMapping();

        $this
            ->baseInputFilter()
            ->addInputFilter()
            ->addEntriesToProcessInputFilter()
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
