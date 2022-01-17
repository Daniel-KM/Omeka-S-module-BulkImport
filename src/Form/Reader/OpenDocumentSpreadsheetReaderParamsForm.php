<?php declare(strict_types=1);

namespace BulkImport\Form\Reader;

use Laminas\Form\Element;

class OpenDocumentSpreadsheetReaderParamsForm extends SpreadsheetReaderConfigForm
{
    public function init(): void
    {
        // Set binary content encoding
        $this
            ->setAttribute('enctype', 'multipart/form-data');

        $this
            ->add([
                'name' => 'file',
                'type' => Element\File::class,
                'options' => [
                    'label' => 'OpenDocument Spreadsheet (ods)', // @translate
                ],
                'attributes' => [
                    'id' => 'file',
                    'required' => true,
                    'accept' => 'application/vnd.oasis.opendocument.spreadsheet,ods',
                ],
            ])
            ->add([
                'name' => 'multisheet',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Sheets', // @translate
                    'value_options' => [
                        'active' => 'Active', // @translate
                        'first' => 'First', // @translate
                        'all' => 'All', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'multisheet',
                    'required' => false,
                    'value' => 'active',
                ],
            ]);

        parent::init();
    }
}
