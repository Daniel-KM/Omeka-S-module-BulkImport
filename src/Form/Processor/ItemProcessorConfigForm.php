<?php declare(strict_types=1);

namespace BulkImport\Form\Processor;

use Omeka\Form\Element\ItemSetSelect;

class ItemProcessorConfigForm extends AbstractResourceProcessorConfigForm
{
    protected function addFieldsets(): \Laminas\Form\Form
    {
        parent::addFieldsets();

        $this
            ->add([
                'name' => 'o:item_set',
                'type' => ItemSetSelect::class,
                'options' => [
                    'label' => 'Item set', // @translate
                ],
                'attributes' => [
                    'id' => 'o-item-set',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'required' => false,
                    'data-placeholder' => 'Select one or more item sets…', // @translate
                ],
            ]);
        return $this;
    }

    protected function addInputFilter(): \Laminas\Form\Form
    {
        parent::addInputFilter();

        $this->getInputFilter()
            ->add([
                'name' => 'o:item_set',
                'required' => false,
            ]);
        return $this;
    }
}
