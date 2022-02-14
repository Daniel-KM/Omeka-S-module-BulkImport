<?php declare(strict_types=1);

namespace BulkImport\Form\Processor;

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
                    'id' => 'entries_to_skip',
                    'min' => '0',
                    'step' => '1',
                    'aria-label' => 'Number of entries to skip', // @translate
                ],
            ])
            ->add([
                'name' => 'entries_max',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Max number of entries to process', // @translate
                ],
                'attributes' => [
                    'id' => 'entries_max',
                    'min' => '0',
                    'step' => '1',
                    'aria-label' => 'Max number of entries to process', // @translate
                ],
            ])
            ->add([
                'name' => 'entries_by_batch',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Entries by batch', // @translate
                    'info' => 'This value has no impact on process, but when it is set to "1" (default), the order of internal ids will be in the same order than the input and medias will follow their items. If it is greater, the order will follow the number of entries by resource types.', // @translate
                ],
                'attributes' => [
                    'id' => 'entries_by_batch',
                    'min' => '0',
                    'step' => '1',
                    'aria-label' => 'Entries by batch', // @translate
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
            ])
            ->add([
                'name' => 'entries_max',
                'required' => false,
            ])
            ->add([
                'name' => 'entries_by_batch',
                'required' => false,
            ]);
        return $this;
    }
}
