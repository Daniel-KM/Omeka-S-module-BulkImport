<?php declare(strict_types=1);

namespace BulkImport\Form\Processor;

use BulkImport\Form\EntriesByBatchTrait;
use BulkImport\Form\EntriesToProcessTrait;

class ResourceProcessorParamsForm extends ResourceProcessorConfigForm
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
        $mapping = [
            'metadata' => [
                'label' => 'Resource metadata', // @translate
                'options' => [
                    // Id is only for update.
                    'o:id' => 'Internal id', // @translate
                    'resource_name' => 'Resource type', // @translate
                    'o:resource_template' => 'Resource template', // @translate
                    'o:resource_class' => 'Resource class', // @translate
                    'o:thumbnail' => 'Thumbnail', // @translate
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
                    'directory' => 'Directory', // @translate
                    'html' => 'Html', // @translate
                    'iiif' => 'IIIF Image', // @translate
                    'tile' => 'Tile', // @translate
                    'o:media {dcterms:title}' => 'Title', // @translate
                    'o:media {o:is_public}' => 'Visibility public/private', // @translate
                ],
            ],
        ];

        if (!$this->isModuleActive('FileSideload')) {
            unset($mapping['media']['options']['file']);
            unset($mapping['media']['options']['directory']);
        }

        if ($this->isModuleActive('Mapping')) {
            $mapping['item']['options']['o-module-mapping:marker'] = 'Mapping latitude/longitude'; // @translate
            // $mapping['item']['options']['o-module-mapping:lat'] = 'Mapping latitude'; // @translate
            // $mapping['item']['options']['o-module-mapping:lng'] = 'Mapping longitude'; // @translate
            // $mapping['item']['options']['o-module-mapping:label'] = 'Mapping marker label'; // @translate
            $mapping['item']['options']['o-module-mapping:bounds'] = 'Mapping bounds'; // @translate
        }

        if (!$this->isModuleActive('ImageServer')) {
            unset($mapping['media']['options']['tile']);
        }

        return $mapping;
    }
}
