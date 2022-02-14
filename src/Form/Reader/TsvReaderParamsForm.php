<?php declare(strict_types=1);

namespace BulkImport\Form\Reader;

use Laminas\Form\Element;

class TsvReaderParamsForm extends SpreadsheetReaderConfigForm
{
    public function init(): void
    {
        // Set binary content encoding
        $this->setAttribute('enctype', 'multipart/form-data');

        $this
            ->add([
                'name' => 'file',
                'type' => Element\File::class,
                'options' => [
                    'label' => 'File (tsv)', // @translate
                ],
                'attributes' => [
                    'id' => 'file',
                    'required' => false,
                    // Some servers don't detect tsv, so add csv too.
                    // Some computers don't detect tsv, so add excel too.
                    'accept' => 'text/tab-separated-values,text/csv,application/csv,csv,tsv,application/vnd.ms-excel',
                ],
            ]);

        parent::init();
    }
}
