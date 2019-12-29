<?php
namespace BulkImport\Form\Processor;

use BulkImport\Form\EntriesByBatchTrait;
use BulkImport\Form\EntriesToSkipTrait;

class ItemProcessorParamsForm extends ItemProcessorConfigForm
{
    use EntriesByBatchTrait;
    use EntriesToSkipTrait;

    public function init()
    {
        $this->baseFieldset();
        $this->addFieldsets();
        $this->addEntriesToSkip();
        $this->addEntriesByBatch();
        $this->addMapping();

        $this->baseInputFilter();
        $this->addInputFilter();
        $this->addEntriesToSkipInputFilter();
        $this->addEntriesByBatchInputFilter();
        $this->addMappingFilter();
    }

    protected function prependMappingOptions()
    {
        $mapping = parent::prependMappingOptions();
        return array_merge_recursive($mapping, [
            'item_sets' => [
                'label' => 'Item sets', // @translate
                'options' => [
                    'o:item_set' => 'Identifier / Internal id', // @translate
                ],
            ],
            'media' => [
                'label' => 'Media', // @translate
                'options' => [
                    'url' => 'Url', // @translate
                    'file' => 'File', // @translate
                    'html' => 'Html', // @translate
                    'o:media {dcterms:title}' => 'Title', // @translate
                    'o:media {o:is_public}' => 'Visibility public/private', // @translate
                ],
            ],
        ]);
    }
}
