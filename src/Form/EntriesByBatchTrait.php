<?php declare(strict_types=1);
namespace BulkImport\Form;

use Laminas\Form\Element;

trait EntriesByBatchTrait
{
    protected function addEntriesByBatch(): void
    {
        $this->add([
            'name' => 'entries_by_batch',
            'type' => Element\Number::class,
            'options' => [
                'label' => 'Entries by batch', // @translate
                'info' => 'This value has no impact on process, but when it is set to "1" (default), the order of internal ids will be in the same order than the input and medias will follow their items. If it is greater, the order will follow the number of entries by resource types.', // @translate
            ],
            'attributes' => [
                'attributes' => [
                    'id' => 'entries_by_batch',
                    'min' => '0',
                    'step' => '1',
                    'placeholder' => '1',
                    'aria-label' => 'Entries by batch', // @translate
                ],
            ],
        ]);
    }

    protected function addEntriesByBatchInputFilter(): void
    {
        $inputFilter = $this->getInputFilter();
        $inputFilter->add([
            'name' => 'entries_by_batch',
            'required' => false,
        ]);
    }
}
