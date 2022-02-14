<?php declare(strict_types=1);

namespace BulkImport\Form\Reader;

use Laminas\Form\Element;

class OpenDocumentSpreadsheetReaderParamsForm extends OpenDocumentSpreadsheetReaderConfigForm
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
                    'required' => false,
                    'accept' => 'application/vnd.oasis.opendocument.spreadsheet,ods',
                ],
            ]);

        parent::init();
    }
}
