<?php declare(strict_types=1);

namespace BulkImport\Form\Reader;

use Laminas\Form\Element;

class XmlReaderParamsForm extends XmlReaderConfigForm
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
                    'label' => 'XML file', // @translate
                ],
                'attributes' => [
                    'id' => 'file',
                    'required' => false,
                    'accept' => 'text/xml,application/xml,xml',
                ],
            ])
        ;

        parent::init();
    }
}
