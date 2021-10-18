<?php declare(strict_types=1);

namespace BulkImport\Form;

use BulkImport\Traits\ServiceLocatorAwareTrait;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Laminas\Form\Form;

class ImporterDeleteForm extends Form
{
    use ServiceLocatorAwareTrait;

    public function init(): void
    {
        parent::init();

        $this
            ->add([
                'name' => 'importer_submit',
                'type' => Fieldset::class,
            ]);

        $this->get('importer_submit')
            ->add([
                'name' => 'submit',
                'type' => Element\Submit::class,
                'attributes' => [
                    'id' => 'submitbutton',
                    'value' => 'Delete importer', // @translate
                ],
            ]);
    }
}
