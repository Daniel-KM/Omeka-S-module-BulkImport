<?php
namespace BulkImport\Form\Processor;

use Omeka\Form\Element\ItemSetSelect;
use Omeka\Form\Element\ResourceSelect;
use Zend\Form\Element;

class ResourceProcessorConfigForm extends AbstractResourceProcessorConfigForm
{
    protected function addFieldsets()
    {
        parent::addFieldsets();

        $this->add([
            'name' => 'resource_type',
            'type' => Element\Select::class,
            'options' => [
                'label' => 'Resource type', // @translate
                'empty_option' => '',
                'value_options' => [
                    'items' => 'Item', // @translate
                    'item_sets' => 'Item set', // @translate
                    'media' => 'Media', // @translate
                ],
            ],
            'attributes' => [
                'id' => 'resource-type',
                'class' => 'chosen-select',
                'multiple' => false,
                'required' => false,
                'data-placeholder' => 'Select the resource type…', // @translate
            ],
        ]);

        $this->addFieldsetItem();
        $this->addFieldsetItemSet();
        $this->addFieldsetMedia();
    }

    protected function addFieldsetItem()
    {
        $this->add([
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
    }

    protected function addFieldsetItemSet()
    {
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

    protected function addFieldsetMedia()
    {
        $services = $this->getServiceLocator();
        $urlHelper = $services->get('ViewHelperManager')->get('url');

        $this->add([
            'name' => 'o:item',
            // Disabled, because not usable with a big base.
            // 'type' => ResourceSelect::class,
            'type' => Element\Select::class,
            'options' => [
                'label' => 'Item', // @translate
                'empty_option' => '',
                'resource_value_options' => [
                    'resource' => 'items',
                    'query' => [],
                    'option_text_callback' => function ($resource) {
                        return $resource->displayTitle();
                    },
                ],
            ],
            'attributes' => [
                'id' => 'o-item',
                'class' => 'chosen-select',
                'multiple' => false,
                'required' => false,
                'data-placeholder' => 'Select one item…', // @translate
                'data-api-base-url' => $urlHelper('api/default', ['resource' => 'items']),
                // Disabled, because not usable with a big base.
                'disabled' => true,
            ],
        ]);
    }

    protected function addInputFilter()
    {
        parent::addInputFilter();

        $inputFilter = $this->getInputFilter();
        $inputFilter->add([
            'name' => 'resource_type',
            'required' => false,
        ]);

        $this->addInputFilterItem();
        $this->addInputFilterItemSet();
        $this->addInputFilterMedia();
    }

    protected function addInputFilterItem()
    {
        $inputFilter = $this->getInputFilter();
        $inputFilter->add([
            'name' => 'o:item_set',
            'required' => false,
        ]);
    }

    protected function addInputFilterItemSet()
    {
        $inputFilter = $this->getInputFilter();
        $inputFilter->add([
            'name' => 'o:is_open',
            'required' => false,
        ]);
    }

    protected function addInputFilterMedia()
    {
        $inputFilter = $this->getInputFilter();
        $inputFilter->add([
            'name' => 'o:item',
            'required' => false,
        ]);
    }
}
