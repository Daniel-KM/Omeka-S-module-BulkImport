<?php declare(strict_types=1);
namespace BulkImport\Form;

use Laminas\Form\Element;

trait EntriesToSkipTrait
{
    protected function addEntriesToSkip(): void
    {
        $this
            ->add([
                'name' => 'entries_to_skip',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Number of entries to skip', // @translate
                    'info' => 'Allows to start on a specific entry index, in particular when there was an issue in first rows of a spreadsheet. Note: don’t include the header row.', // @translate
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

    protected function addEntriesToSkipInputFilter(): void
    {
        $inputFilter = $this->getInputFilter();
        $inputFilter->add([
            'name' => 'entries_to_skip',
            'required' => false,
        ]);
    }
}
