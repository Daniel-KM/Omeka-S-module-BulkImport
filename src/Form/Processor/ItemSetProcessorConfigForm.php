<?php declare(strict_types=1);

namespace BulkImport\Form\Processor;

use Laminas\Form\Element;

class ItemSetProcessorConfigForm extends AbstractResourceProcessorConfigForm
{
    protected function addFieldsets(): \Laminas\Form\Form
    {
        parent::addFieldsets();

        $this
            ->add([
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
        return $this;
    }

    protected function addInputFilter(): \Laminas\Form\Form
    {
        parent::addInputFilter();

        $this->getInputFilter()
            ->add([
                'name' => 'o:is_open',
                'required' => false,
            ]);
        return $this;
    }
}
