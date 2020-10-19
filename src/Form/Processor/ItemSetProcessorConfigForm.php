<?php declare(strict_types=1);
namespace BulkImport\Form\Processor;

use Laminas\Form\Element;

class ItemSetProcessorConfigForm extends AbstractResourceProcessorConfigForm
{
    protected function addFieldsets(): void
    {
        parent::addFieldsets();

        $this->add([
            'name' => 'o:is_open',
            'type' => Element\Radio::class,
            'options' => [
                'label' => 'Openness', // @translate
                'value_options' => [
                    'true' => 'Open', // @translate
                    'false' => 'Close', // @translate
                ],
            ],
            'attributes' => [
                'id' => 'o-is-open',
            ],
        ]);
    }

    protected function addInputFilter(): void
    {
        parent::addInputFilter();

        $inputFilter = $this->getInputFilter();
        $inputFilter->add([
            'name' => 'o:is_open',
            'required' => false,
        ]);
    }
}
