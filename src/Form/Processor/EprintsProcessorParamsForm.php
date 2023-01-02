<?php declare(strict_types=1);

namespace BulkImport\Form\Processor;

use BulkImport\Form\Element as BulkElement;
use BulkImport\Traits\ServiceLocatorAwareTrait;
use Laminas\Form\Element;
use Omeka\Form\Element as OmekaElement;

/**
 * @todo Factorize with Manioc processor.
 */
class EprintsProcessorParamsForm extends EprintsProcessorConfigForm
{
    public function init(): void
    {
        $this
            ->baseFieldset();

        $this
            ->baseInputFilter();
    }

    protected function baseFieldset(): \Laminas\Form\Form
    {
        $services = $this->getServiceLocator();
        $urlHelper = $services->get('ViewHelperManager')->get('url');

        $this
            ->add([
                'name' => 'comment',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Label or comment', // @translate
                    'info' => 'This optional comment will help admins for future reference.', // @translate
                ],
                'attributes' => [
                    'id' => 'comment',
                    'placeholder' => 'Optional label or comment for future reference.', // @translate
                ],
            ])
            ->add([
                'name' => 'o:owner',
                'type' => OmekaElement\ResourceSelect::class,
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
                    'info' => 'Warning: import of statistics is slow currently.', // @translate
                    'value_options' => [
                        [
                            'value' => 'users',
                            'label' => 'Users', // @translate
                            'selected' => true,
                            // 'disabled' => false,
                            // 'attributes' => [],
                            // 'label_attributes' => [],
                        ],
                        [
                            'value' => 'items',
                            'label' => 'Items', // @translate
                            'selected' => true,
                        ],
                        [
                            'value' => 'media',
                            'label' => 'Medias', // @translate
                            'selected' => true,
                        ],
                        [
                            'value' => 'item_sets',
                            'label' => 'Item sets', // @translate
                            'selected' => false,
                            'disabled' => true,
                        ],
                        [
                            'value' => 'concepts',
                            'label' => 'Subjects', // @translate
                            'selected' => true,
                        ],
                        [
                            'value' => 'contact_messages',
                            'label' => 'Contact messages', // @translate
                            'selected' => true,
                        ],
                        [
                            'value' => 'search_requests',
                            'label' => 'Saved searches', // @translate
                            'selected' => true,
                        ],
                        [
                            'value' => 'hits',
                            'label' => 'Statistics', // @translate
                            'selected' => false,
                        ],
                    ],
                ],
                'attributes' => [
                    'id' => 'types',
                    'value' => [
                        'users',
                        'items',
                        'media',
                        // 'item_sets',
                        'concepts',
                        'contact_messages',
                        'search_requests',
                        // 'hits',
                    ],
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'people_to_items',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Import people as item', // @translate
                ],
                'attributes' => [
                    'id' => 'people_to_items',
                ],
            ])
            ->add([
                'name' => 'fake_files',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Fake files', // @translate
                    'info' => 'This option avoids fetching and processing files.', // @translate
                ],
                'attributes' => [
                    'id' => 'fake_files',
                ],
            ])
            ->add([
                'name' => 'endpoint',
                'type' => BulkElement\OptionalUrl::class,
                'options' => [
                    'label' => 'Base url', // @translate
                ],
                'attributes' => [
                    'id' => 'endpoint',
                ],
            ])
            ->add([
                'name' => 'url_path',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Url or filepath to fetch original files', // @translate
                ],
                'attributes' => [
                    'id' => 'url_path',
                ],
            ])
            ->add([
                'name' => 'language',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Code of the default language', // @translate
                ],
                'attributes' => [
                    'id' => 'language',
                    'placeholder' => 'fra',
                ],
            ])
            ->add([
                'name' => 'language_2',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Two letters code of the default language', // @translate
                ],
                'attributes' => [
                    'id' => 'language',
                    'placeholder' => 'fr',
                ],
            ])
        ;
        return $this;
    }

    protected function baseInputFilter(): \Laminas\Form\Form
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
        return $this;
    }
}
