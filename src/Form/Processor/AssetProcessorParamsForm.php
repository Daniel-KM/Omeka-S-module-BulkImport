<?php declare(strict_types=1);

namespace BulkImport\Form\Processor;

class AssetProcessorParamsForm extends AssetProcessorConfigForm
{
    public function init(): void
    {
        $this
            ->setAttribute('id', 'form-bulk-importer')
            ->baseFieldset();
            // TODO Files.

        $this
            ->baseInputFilter();
    }
}
