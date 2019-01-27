<?php
namespace BulkImport\Form;

use BulkImport\Traits\ServiceLocatorAwareTrait;
use Zend\Form\Element;
use Zend\Form\Fieldset;
use Zend\Form\Form;

class ImporterStartForm extends Form
{
    use ServiceLocatorAwareTrait;

    public function init()
    {
        $this->add([
            'name' => 'start_submit',
            'type' => Fieldset::class,
        ]);

        $fieldset = $this->get('start_submit');

        $fieldset->add([
            'name' => 'submit',
            'type' => Element\Submit::class,
            'attributes' => [
                'value' => 'Start import', // @translate
                'required' => true,
            ],
        ]);
    }
}
