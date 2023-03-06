<?php declare(strict_types=1);

namespace BulkImport\Form\Processor;

class ItemProcessorParamsForm extends ItemProcessorConfigForm
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
        $mapping = array_merge_recursive($mapping, [
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
                    'directory' => 'Directory', // @translate
                    'html' => 'Html', // @translate
                    'iiif' => 'IIIF Image', // @translate
                    // Removed since Image Server 3.6.13.
                    // 'tile' => 'Tile', // @translate
                    'o:media {dcterms:title}' => 'Title', // @translate
                    'o:media {o:is_public}' => 'Visibility public/private', // @translate
                ],
            ],
        ]);

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

        /*
        if (!$this->isModuleActive('ImageServer')) {
            unset($mapping['media']['options']['tile']);
        }
        */

        return $mapping;
    }
}
