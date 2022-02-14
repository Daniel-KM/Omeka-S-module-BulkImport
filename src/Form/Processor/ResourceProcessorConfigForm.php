<?php declare(strict_types=1);

namespace BulkImport\Form\Processor;

use Laminas\Form\Element;
use Omeka\Form\Element as OmekaElement;

class ResourceProcessorConfigForm extends AbstractResourceProcessorConfigForm
{
    protected function addFieldsets(): \Laminas\Form\Form
    {
        parent::addFieldsets();

        $this
            ->add([
                'name' => 'resource_name',
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
            ])
            ->addFieldsetItem()
            ->addFieldsetItemSet()
            ->addFieldsetMedia()
        ;

        return $this;
    }

    protected function addFieldsetItem(): \Laminas\Form\Form
    {
        $this
            ->add([
                'name' => 'o:item_set',
                'type' => OmekaElement\ItemSetSelect::class,
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

    protected function addFieldsetItemSet(): \Laminas\Form\Form
    {
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

    protected function addFieldsetMedia(): \Laminas\Form\Form
    {
        $services = $this->getServiceLocator();
        $urlHelper = $services->get('ViewHelperManager')->get('url');

        $this
            ->add([
                'name' => 'o:item',
                // Disabled, because not usable with a big base.
                // 'type' => OmekaElement\ResourceSelect::class,
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
        return $this;
    }

    protected function addInputFilter(): \Laminas\Form\Form
    {
        parent::addInputFilter();

        $this->getInputFilter()
            ->add([
                'name' => 'resource_name',
                'required' => false,
            ]);

        $this
            ->addInputFilterItem()
            ->addInputFilterItemSet()
            ->addInputFilterMedia();
        return $this;
    }

    protected function addInputFilterItem(): \Laminas\Form\Form
    {
        $this->getInputFilter()
            ->add([
                'name' => 'o:item_set',
                'required' => false,
            ]);
        return $this;
    }

    protected function addInputFilterItemSet(): \Laminas\Form\Form
    {
        $this->getInputFilter()
            ->add([
                'name' => 'o:is_open',
                'required' => false,
            ]);
        return $this;
    }

    protected function addInputFilterMedia(): \Laminas\Form\Form
    {
        $this->getInputFilter()
            ->add([
                'name' => 'o:item',
                'required' => false,
            ]);
        return $this;
    }
}
