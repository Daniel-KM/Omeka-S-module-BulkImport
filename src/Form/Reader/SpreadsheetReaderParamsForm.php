<?php declare(strict_types=1);
namespace BulkImport\Form\Reader;

use Laminas\Form\Element;

class SpreadsheetReaderParamsForm extends CsvReaderConfigForm
{
    public function init(): void
    {
        // Set binary content encoding
        $this->setAttribute('enctype', 'multipart/form-data');

        $this->add([
            'name' => 'file',
            'type' => Element\File::class,
            'options' => [
                'label' => 'Spreadsheet (csv, tsv, OpenDocument ods)', // @translate
            ],
            'attributes' => [
                'id' => 'file',
                'required' => true,
            ],
        ]);

        parent::init();
    }
}
