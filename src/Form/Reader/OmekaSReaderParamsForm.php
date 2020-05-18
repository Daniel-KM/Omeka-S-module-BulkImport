<?php
namespace BulkImport\Form\Reader;

use Zend\Form\Element;

class OmekaSReaderParamsForm extends OmekaSReaderConfigForm
{
    public function init()
    {
        $this
            ->add([
                'name' => 'endpoint',
                'type' => Element\Url::class,
                'options' => [
                    'label' => 'Omeka S api endpoint', // @translate
                ],
                'attributes' => [
                    'id' => 'endpoint',
                    'required' => true,
                ],
            ])
            ->add([
                'name' => 'key_identity',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Omeka S api identity key', // @translate
                ],
                'attributes' => [
                    'id' => 'key_identity',
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'key_credential',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Omeka S api credential key', // @translate
                ],
                'attributes' => [
                    'id' => 'key_credential',
                    'required' => false,
                ],
            ])
        ;

        parent::init();
    }
}
