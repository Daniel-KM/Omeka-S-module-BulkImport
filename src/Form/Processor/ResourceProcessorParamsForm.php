<?php
namespace BulkImport\Form\Processor;

use BulkImport\Form\EntriesByBatchTrait;
use BulkImport\Form\EntriesToSkipTrait;

class ResourceProcessorParamsForm extends ResourceProcessorConfigForm
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
        return [
            'metadata' => [
                'label' => 'Resource metadata', // @translate
                'options' => [
                    // Id is only for update.
                    'o:id' => 'Internal id', // @translate
                    'resource_type' => 'Resource type', // @translate
                    'o:resource_template' => 'Resource template', // @translate
                    'o:resource_class' => 'Resource class', // @translate
                    'o:owner' => 'Owner', // @translate
                    'o:is_public' => 'Visibility public/private', // @translate
                ],
            ],
            'item' => [
                'label' => 'Item', // @translate
                'options' => [
                    'o:item' => 'Identifier / Internal id', // @translate
                ],
            ],
            'item_sets' => [
                'label' => 'Item sets', // @translate
                'options' => [
                    'o:item_set' => 'Identifier / Internal id', // @translate
                    'o:is_open' => 'Openness', // @translate
                ],
            ],
            'media' => [
                'label' => 'Media', // @translate
                'options' => [
                    'o:media' => 'Identifier / Internal id', // @translate
                    'url' => 'Url', // @translate
                    'file' => 'File', // @translate
                    'html' => 'Html', // @translate
                    'o:media {dcterms:title}' => 'Title', // @translate
                    'o:media {o:is_public}' => 'Visibility public/private', // @translate
                ],
            ],
        ];
    }
}
