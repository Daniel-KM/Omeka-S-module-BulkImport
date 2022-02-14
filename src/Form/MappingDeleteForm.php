<?php declare(strict_types=1);

namespace BulkImport\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;

class MappingDeleteForm extends Form
{
    public function init(): void
    {
        parent::init();

        $this
            ->add([
                'name' => 'submit',
                'type' => Element\Submit::class,
                'attributes' => [
                    'id' => 'submitbutton',
                    'value' => 'Delete mapping', // @translate
                ],
            ]);
    }
}
