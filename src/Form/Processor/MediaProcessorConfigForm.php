<?php declare(strict_types=1);

namespace BulkImport\Form\Processor;

use Omeka\Form\Element as OmekaElement;

class MediaProcessorConfigForm extends AbstractResourceProcessorConfigForm
{
    protected function addFieldsets(): \Laminas\Form\Form
    {
        parent::addFieldsets();

        $services = $this->getServiceLocator();
        $urlHelper = $services->get('ViewHelperManager')->get('url');

        $this
            ->add([
                'name' => 'o:item',
                'type' => OmekaElement\ResourceSelect::class,
                'options' => [
                    'label' => 'Item', // @translate
                    'empty_option' => 'Select one item…', // @translate
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
                ],
            ]);
        return $this;
    }

    protected function addInputFilter(): \Laminas\Form\Form
    {
        parent::addInputFilter();

        $this->getInputFilter()
            ->add([
                'name' => 'o:item',
                'required' => false,
            ]);
        return $this;
    }
}
