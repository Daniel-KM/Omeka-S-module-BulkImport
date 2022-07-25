<?php declare(strict_types=1);

namespace BulkImport\Form\Processor;

class ItemSetProcessorParamsForm extends ItemSetProcessorConfigForm
{
    public function init(): void
    {
        $this
            ->baseFieldset()
            ->addFieldsets()
            ->addMapping();

        $this
            ->baseInputFilter()
            ->addInputFilter()
            ->addMappingFilter();
    }

    protected function prependMappingOptions(): array
    {
        $mapping = parent::prependMappingOptions();
        return array_merge_recursive($mapping, [
            'metadata' => [
                // Don't indicate it to avoid issue with recursive merge.
                // 'label' => 'Resource metadata', // @translate
                'options' => [
                    'o:is_open' => 'Openness', // @translate
                ],
            ],
        ]);
    }
}
