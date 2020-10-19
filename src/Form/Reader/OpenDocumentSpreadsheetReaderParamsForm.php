<?php
namespace BulkImport\Form\Reader;

use Laminas\Form\Element;

class OpenDocumentSpreadsheetReaderParamsForm extends SpreadsheetReaderConfigForm
{
    public function init()
    {
        // Set binary content encoding
        $this->setAttribute('enctype', 'multipart/form-data');

        $this->add([
            'name' => 'file',
            'type' => Element\File::class,
            'options' => [
                'label' => 'OpenDocument Spreadsheet (ods)', // @translate
            ],
            'attributes' => [
                'id' => 'file',
                'required' => true,
            ],
        ]);

        parent::init();
    }
}
