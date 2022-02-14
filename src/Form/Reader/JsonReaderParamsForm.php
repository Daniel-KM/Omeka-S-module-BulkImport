<?php declare(strict_types=1);

namespace BulkImport\Form\Reader;

use Laminas\Form\Element;

class JsonReaderParamsForm extends JsonReaderConfigForm
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
                    'label' => 'Json file', // @translate
                ],
                'attributes' => [
                    'id' => 'file',
                    'required' => false,
                    'accept' => 'application/json,json',
                ],
            ])
        ;

        parent::init();
    }
}
