<?php
namespace BulkImport\Form\Processor;

use BulkImport\Form\EntriesByBatchTrait;
use BulkImport\Form\EntriesToSkipTrait;

class MediaProcessorParamsForm extends MediaProcessorConfigForm
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
            'item' => [
                'label' => 'Item', // @translate
                'options' => [
                    'o:item' => 'Identifier / Internal id', // @translate
                ],
            ],
            'media' => [
                'label' => 'Media', // @translate
                'options' => [
                    'url' => 'Url', // @translate
                    'file' => 'File', // @translate
                    'html' => 'Html', // @translate
                ],
            ],
        ]);
    }
}
