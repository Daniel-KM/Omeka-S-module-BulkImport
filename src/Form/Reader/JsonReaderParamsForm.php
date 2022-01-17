<?php declare(strict_types=1);

namespace BulkImport\Form\Reader;

use BulkImport\Form\Element as BulkImportElement;
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
            ->add([
                'name' => 'url',
                'type' => BulkImportElement\OptionalUrl::class,
                'options' => [
                    'label' => 'Json url', // @translate
                ],
                'attributes' => [
                    'id' => 'url',
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'mapping_automatic',
                'type' => Element\Hidden::class,
                'attributes' => [
                    'id' => 'mapping_automatic',
                    'value' => '1',
                ],
            ])
        ;

        parent::init();
    }
}
