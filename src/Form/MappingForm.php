<?php declare(strict_types=1);

namespace BulkImport\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;

class MappingForm extends Form
{
    public function init(): void
    {
        parent::init();

        $this
            ->add([
                'name' => 'o:label',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Label', // @translate
                ],
                'attributes' => [
                    'id' => 'o-label',
                    'required' => true,
                ],
            ])

            ->add([
                'name' => 'o-module-bulk:mapping',
                'type' => Element\Textarea::class,
                'options' => [
                    'label' => 'Mapping', // @translate
                ],
                'attributes' => [
                    'id' => 'o-module-bulk-mapping',
                    'rows' => 30,
                    'class' => 'codemirror-code',
                ],
            ])

            ->add([
                'name' => 'submit',
                'type' => Element\Submit::class,
                'attributes' => [
                    'id' => 'submitbutton',
                    'value' => 'Save', // @translate
                ],
            ]);
    }
}
