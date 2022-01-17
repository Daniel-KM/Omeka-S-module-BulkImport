<?php declare(strict_types=1);

namespace BulkImport\Form;

use Laminas\Form\Element;

trait EntriesToProcessTrait
{
    protected function addEntriesToProcess(): \Laminas\Form\Form
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
        return $this;
    }

    protected function addEntriesToProcessInputFilter(): \Laminas\Form\Form
    {
        $this->getInputFilter()
            ->add([
                'name' => 'entries_to_skip',
                'required' => false,
            ]);
        return $this;
    }
}
