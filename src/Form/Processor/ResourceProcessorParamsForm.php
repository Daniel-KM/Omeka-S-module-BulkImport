<?php declare(strict_types=1);

namespace BulkImport\Form\Processor;

class ResourceProcessorParamsForm extends ResourceProcessorConfigForm
{
    public function init(): void
    {
        $this
            ->setAttribute('id', 'form-bulk-importer')
            ->baseFieldset()
            ->addFieldsets()
            ->addFiles();

        $this
            ->baseInputFilter()
            ->addInputFilter()
            ->addFilesFilter();
    }
}
