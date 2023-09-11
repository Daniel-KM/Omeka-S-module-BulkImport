<?php declare(strict_types=1);

namespace BulkImport\Form\Processor;

class ItemProcessorParamsForm extends ItemProcessorConfigForm
{
    public function init(): void
    {
        $this
            ->baseFieldset()
            ->addFieldsets()
            ->addFiles();

        $this
            ->baseInputFilter()
            ->addInputFilter()
            ->addFilesFilter();
    }
}
