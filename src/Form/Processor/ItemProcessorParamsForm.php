<?php declare(strict_types=1);

namespace BulkImport\Form\Processor;

class ItemProcessorParamsForm extends ItemProcessorConfigForm
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
