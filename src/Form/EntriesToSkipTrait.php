<?php
namespace BulkImport\Form;

use Zend\Form\Element;

trait EntriesToSkipTrait
{
    protected function addEntriesToSkip()
    {
        $this
            ->add([
                'name' => 'entries_to_skip',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Number of entries to skip', // @translate
                    'info' => 'Allows to start on the next entry without preparing a new file in case of an issue. Note: don’t include the header row.', // @translate
                ],
                'attributes' => [
                    'attributes' => [
                        'id' => 'entries_to_skip',
                        'min' => '0',
                        'step' => '1',
                        'placeholder' => '1',
                        'aria-label' => 'Number of entries to skip', // @translate
                    ],
                ],
            ])
        ;
    }

    protected function addEntriesToSkipInputFilter()
    {
        $inputFilter = $this->getInputFilter();
        $inputFilter->add([
            'name' => 'entries_to_skip',
            'required' => false,
        ]);
    }
}
