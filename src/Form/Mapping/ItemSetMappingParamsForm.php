<?php declare(strict_types=1);

namespace BulkImport\Form\Mapping;

class ItemSetMappingParamsForm extends AbstractResourceMappingParamsForm
{
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
