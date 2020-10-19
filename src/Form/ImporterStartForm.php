<?php declare(strict_types=1);
namespace BulkImport\Form;

use BulkImport\Traits\ServiceLocatorAwareTrait;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Laminas\Form\Form;

class ImporterStartForm extends Form
{
    use ServiceLocatorAwareTrait;

    public function init(): void
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
