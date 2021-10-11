<?php declare(strict_types=1);

namespace BulkImport\Form\Processor;

use BulkImport\Form\Element\OptionalUrl;
use BulkImport\Traits\ServiceLocatorAwareTrait;
use Laminas\Form\Element;
use Omeka\Form\Element\ResourceSelect;

/**
 * @todo Factorize with Omeka S processor.
 */
class SpipProcessorParamsForm extends SpipProcessorConfigForm
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
                        'users' => 'Users', // @translate
                        'items' => 'Items', // @translate
                        'media' => 'Media', // @translate
                        'item_sets' => 'Item sets', // @translate
                        'assets' => 'Assets', // @translate
                        // 'vocabularies' => 'Vocabularies', // @translate
                        // 'resource_templates' => 'Resource templates', // @translate
                        // 'custom_vocabs' => 'Custom vocabs', // @translate
                        'concepts' => 'Rubriques (thésaurus)', // @translate
                        'breves' => 'Brèves', // @translate
                        'auteurs' => 'Fiches auteurs', // @translate
                        'mots' => 'Mots-clés en tant que thésaurus', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'types',
                    'value' => [
                        'users',
                        'items',
                        'media',
                        'item_sets',
                        'assets',
                        // 'vocabularies',
                        // 'resource_templates',
                        // 'custom_vocabs',
                        'concepts',
                        'breves',
                        'auteurs',
                        'mots',
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
                ],
            ])
            ->add([
                'name' => 'menu',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Créer le menu (module Menu)', // @translate
                ],
                'attributes' => [
                    'id' => 'menu',
                    'value' => '1',
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
                'name' => 'endpoint',
                'type' => OptionalUrl::class,
                'options' => [
                    'label' => 'Url of original site to fetch files', // @translate
                ],
                'attributes' => [
                    'id' => 'endpoint',
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
