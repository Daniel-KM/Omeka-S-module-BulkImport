<?php declare(strict_types=1);

namespace BulkImport\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;

class ConfigForm extends Form
{
    public function init(): void
    {
        $this
            ->add([
                'name' => 'bulkimport_pdftk',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'PdfTk path', // @translate
                    'info' => 'Set the path if it is not automatically detected. PdfTk is the library used to extract metadata from pdf files.', // @translate
                ],
                'attributes' => [
                    'id' => 'bulkimport_pdftk',
                ],
            ])
        ;
    }
}
