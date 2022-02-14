<?php declare(strict_types=1);

namespace BulkImport\Form\Reader;

use Laminas\Form\Element;

class CsvReaderParamsForm extends CsvReaderConfigForm
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
                    'label' => 'CSV file', // @translate
                ],
                'attributes' => [
                    'id' => 'file',
                    'required' => false,
                    // Some servers don't detect csv, so add csv too.
                    // Some computers don't detect csv, so add excel too.
                    'accept' => 'text/csv,application/csv,csv,application/vnd.ms-excel',
                ],
            ]);

        parent::init();
    }
}
