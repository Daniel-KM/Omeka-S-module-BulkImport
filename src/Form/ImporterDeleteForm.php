<?php
namespace BulkImport\Form;

use BulkImport\Traits\ServiceLocatorAwareTrait;
use Zend\Form\Element;
use Zend\Form\Fieldset;
use Zend\Form\Form;

class ImporterDeleteForm extends Form
{
    use ServiceLocatorAwareTrait;

    public function init()
    {
        parent::init();

        $this->add([
            'name' => 'importer_submit',
            'type' => Fieldset::class,
        ]);

        $fieldset = $this->get('importer_submit');

        $fieldset->add([
            'name' => 'submit',
            'type' => Element\Submit::class,
            'attributes' => [
                'id' => 'submitbutton',
                'value' => 'Delete importer', // @translate
            ],
        ]);
    }
}
