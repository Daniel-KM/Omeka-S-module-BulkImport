<?php declare(strict_types=1);

namespace BulkImport\Form\Processor;

class MediaProcessorParamsForm extends MediaProcessorConfigForm
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
                    'directory' => 'Directory', // @translate
                    'html' => 'Html', // @translate
                    'iiif' => 'IIIF Image', // @translate
                    // Removed since Image Server 3.6.13.
                    // 'tile' => 'Tile', // @translate
                ],
            ],
        ]);

        if (!$this->isModuleActive('FileSideload')) {
            unset($mapping['media']['options']['file']);
            unset($mapping['media']['options']['directory']);
        }

        /*
        if (!$this->isModuleActive('ImageServer')) {
            unset($mapping['media']['options']['tile']);
        }
        */

        return $mapping;
    }
}
