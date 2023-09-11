<?php declare(strict_types=1);

namespace BulkImport\Form;

use BulkImport\Traits\ServiceLocatorAwareTrait;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Laminas\Form\Form;

class ImporterConfirmForm extends Form
{
    use ServiceLocatorAwareTrait;

    public function init(): void
    {
        $this
            ->setAttribute('id', 'form-bulk-importer')
            ->add([
                'name' => 'form_submit',
                'type' => Fieldset::class,
            ]);

        $this->get('form_submit')
            ->add([
                'name' => 'submit',
                'type' => Element\Submit::class,
                'attributes' => [
                    'value' => 'Start import', // @translate
                    'required' => true,
                ],
            ]);
    }
}
