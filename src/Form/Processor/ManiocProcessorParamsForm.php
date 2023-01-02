<?php declare(strict_types=1);

namespace BulkImport\Form\Processor;

use BulkImport\Form\Element\OptionalUrl;
use BulkImport\Traits\ServiceLocatorAwareTrait;
use Laminas\Form\Element;
use Omeka\Form\Element as OmekaElement;

/**
 * @todo Factorize with Spip, Eprints, and Omeka S processor.
 */
class ManiocProcessorParamsForm extends ManiocProcessorConfigForm
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
                    'value' => '',
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
                    'value_options' => [
                        'users' => 'Users', // @translate
                        'items' => 'Items', // @translate
                        // 'media' => 'Media', // @translate
                        'item_sets' => 'Item sets', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'types',
                    'value' => [
                        'users',
                        'items',
                        // 'media',
                        'item_sets',
                    ],
                    'required' => false,
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
                    'value' => '1',
                ],
            ])
            ->add([
                'name' => 'endpoint',
                'type' => OptionalUrl::class,
                'options' => [
                    'label' => 'Url of original site to fetch files', // @translate
                ],
                'attributes' => [
                    'id' => 'endpoint',
                    'value' => 'http://manioc.org/',
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
                    'value' => 'fra',
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
                    'value' => 'fr',
                ],
            ])
            ->add([
                'name' => 'geonames_search',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Search for geonames', // @translate
                    'value_options' => [
                        'strict' => 'Strict', // @translate
                        'fuzzy' => 'Fuzzy', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'geonames_search',
                    'value' => 'strict',
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
