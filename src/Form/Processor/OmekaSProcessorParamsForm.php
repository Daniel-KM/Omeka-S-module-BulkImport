<?php declare(strict_types=1);
namespace BulkImport\Form\Processor;

use BulkImport\Traits\ServiceLocatorAwareTrait;
use Laminas\Form\Element;
use Omeka\Form\Element\ResourceSelect;

class OmekaSProcessorParamsForm extends OmekaSProcessorConfigForm
{
    use ServiceLocatorAwareTrait;

    public function init(): void
    {
        $this->baseFieldset();

        $this->baseInputFilter();
    }

    protected function baseFieldset(): void
    {
        $services = $this->getServiceLocator();
        $urlHelper = $services->get('ViewHelperManager')->get('url');

        $this
            ->add([
                'name' => 'comment',
                'type' => Element\Textarea::class,
                'options' => [
                    'label' => 'Comment', // @translate
                    'info' => 'This optional comment will help admins for future reference.', // @translate
                ],
                'attributes' => [
                    'id' => 'comment',
                    'value' => '',
                    'placeholder' => 'Optional comment for future reference.', // @translate
                ],
            ])
            ->add([
                'name' => 'o:owner',
                'type' => ResourceSelect::class,
                'options' => [
                    'label' => 'Owner', // @translate
                    'prepend_value_options' => [
                        'current' => 'Current user', // @translate
                    ],
                    'resource_value_options' => [
                        'resource' => 'users',
                        'query' => ['sort_by' => 'name', 'sort_dir' => 'ASC'],
                        'option_text_callback' => function ($user) {
                            return sprintf('%s (%s)', $user->name(), $user->email());
                        },
                    ],
                ],
                'attributes' => [
                    'id' => 'select-owner',
                    'value' => 'current',
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select a user', // @translate
                    'data-api-base-url' => $urlHelper('api/default', ['resource' => 'users'], ['query' => ['sort_by' => 'email', 'sort_dir' => 'ASC']]),
                ],
            ])
            ->add([
                'name' => 'types',
                'type' => Element\MultiCheckbox::class,
                'options' => [
                    'label' => 'Types to import', // @translate
                    'value_options' => [
                        'items' => 'Items', // @translate
                        'media' => 'Media', // @translate
                        'item_sets' => 'Item sets', // @translate
                        'assets' => 'Assets', // @translate
                        // 'vocabularies' => 'Vocabularies', // @translate
                        // 'resource_templates' => 'Resource templates', // @translate
                        // 'custom_vocabs' => 'Custom vocabs', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'types',
                    'value' => [
                        'items',
                        'media',
                        'item_sets',
                        'assets',
                        // 'vocabularies',
                        // 'resource_templates',
                        // 'custom_vocabs',
                    ],
                    'required' => false,
                ],
            ])
        ;
    }

    protected function baseInputFilter(): void
    {
        $this->getInputFilter()
            ->add([
                'name' => 'o:owner',
                'required' => false,
            ])
            ->add([
                'name' => 'types',
                'required' => false,
            ])
        ;
    }
}
