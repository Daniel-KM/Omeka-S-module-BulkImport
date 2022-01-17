<?php declare(strict_types=1);

namespace BulkImport\Form\Processor;

use BulkImport\Form\Element as BulkImportElement;
use BulkImport\Form\EntriesToProcessTrait;
use BulkImport\Traits\ServiceLocatorAwareTrait;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Laminas\Form\Form;
use Omeka\Form\Element as OmekaElement;

abstract class AbstractResourceProcessorConfigForm extends Form
{
    use ServiceLocatorAwareTrait;
    use EntriesToProcessTrait;

    public function init(): void
    {
        $this
            ->baseFieldset()
            ->addFieldsets();

        $this
            ->baseInputFilter()
            ->addInputFilter();
    }

    protected function baseFieldset(): \Laminas\Form\Form
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
                'name' => 'processing',
                'type' => BulkImportElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Process control', // @translate
                    'info' => 'In all cases, the check of identifiers, linked resources, template values, and files presence is done during a first loop.', // @translate
                    'value_options' => [
                        'dry_run' => 'Dry run', // @translate
                        'stop_on_error' => 'Stop on error', // @translate
                        'continue_on_error' => 'Continue on error', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'processing',
                    'value' => 'stop_on_error',
                ],
            ])

            ->add([
                'name' => 'value_datatype_literal',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Use data type "literal" when a value is invalid', // @translate
                    'info' => 'The mapping can be used for more precise process with "^^xxx; literal".', // @translate
                ],
                'attributes' => [
                    'id' => 'value_datatype_literal',
                ],
            ])

            ->addEntriesToProcess()

            ->add([
                'name' => 'action',
                'type' => Element\Select::class,
                'options' => [
                    'label' => 'Action', // @translate
                    'info' => 'In addition to the default "Create" and to the common "Delete", to manage most of the common cases, four modes of update are provided:
- append: add new data to complete the resource;
- revise: replace existing data by the ones set in each entry, except if empty (don’t modify data that are not provided, except for default values);
- update: replace existing data by the ones set in each entry, even empty (don’t modify data that are not provided, except for default values);
- replace: remove all properties of the resource, and fill new ones from the entry.', // @translate
                    'value_options' => [
                        \BulkImport\Processor\AbstractProcessor::ACTION_CREATE => 'Create new resources', // @translate
                        \BulkImport\Processor\AbstractProcessor::ACTION_APPEND => 'Append data to resources', // @translate
                        \BulkImport\Processor\AbstractProcessor::ACTION_REVISE => 'Revise data of resources', // @translate
                        \BulkImport\Processor\AbstractProcessor::ACTION_UPDATE => 'Update data of resources', // @translate
                        \BulkImport\Processor\AbstractProcessor::ACTION_REPLACE => 'Replace all data of resources', // @translate
                        \BulkImport\Processor\AbstractProcessor::ACTION_DELETE => 'Delete resources', // @translate
                        \BulkImport\Processor\AbstractProcessor::ACTION_SKIP => 'Skip entries (dry run)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'action',
                    'multiple' => false,
                    'required' => false,
                    'class' => 'chosen-select',
                ],
            ])

            ->add([
                'name' => 'action_unidentified',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Action on unidentified resources', // @translate
                    'info' => 'What to do when a resource to update does not exist.', // @translate
                    'value_options' => [
                        \BulkImport\Processor\AbstractProcessor::ACTION_SKIP => 'Skip entry', // @translate
                        \BulkImport\Processor\AbstractProcessor::ACTION_CREATE => 'Create a new resource', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'action_unidentified',
                    'value' => \BulkImport\Processor\AbstractProcessor::ACTION_SKIP,
                ],
            ])

            ->add([
                'name' => 'identifier_name',
                'type' => OmekaElement\PropertySelect::class,
                'options' => [
                    'label' => 'Identifier to use for linked resources or update', // @translate
                    'info' => 'Allows to identify existing resources, for example to attach a media to an existing item or to update a resource. It is always recommended to set one ore more unique identifiers to all resources, with a prefix.', // @translate
                    'empty_option' => '',
                    'prepend_value_options' => [
                        'o:id' => 'Internal id', // @translate
                        'media_identifier' => [
                            'label' => 'Media identifier', // @translate
                            'options' => [
                                'o:source' => 'Source', // @translate
                                'o:filename' => 'File name', // @translate
                                // When used with module Archive Repertory.
                                'o:basename' => 'Base file name', // @translate
                                'o:storage_id' => 'Storage id', // @translate
                                'o:sha256' => 'Hash', // @translate
                            ],
                        ],
                    ],
                    'term_as_value' => true,
                ],
                'attributes' => [
                    'id' => 'identifier_name',
                    'multiple' => true,
                    'required' => false,
                    'value' => [
                        'o:id',
                    ],
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select an identifier name…', // @translate
                ],
            ])

            ->add([
                'name' => 'allow_duplicate_identifiers',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Allow duplicate identifiers', // @translate
                    'info' => 'Not recommended, but needed to be compliant with old databases. Duplicates are logged.', // @translate
                ],
                'attributes' => [
                    'id' => 'allow_duplicate_identifiers',
                ],
            ])

            ->add([
                'name' => 'action_identifier_update',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Action on identifier', // @translate
                    'info' => 'When a "revise" or an "update" is done, the identifier may be updated, but you may want to keep existing identifiers if all of them are not provided.', // @translate
                    'value_options' => [
                        \BulkImport\Processor\AbstractProcessor::ACTION_APPEND => 'Keep and append new identifiers', // @translate
                        \BulkImport\Processor\AbstractProcessor::ACTION_UPDATE => 'Process as main action above', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'action_identifier_update',
                    'value' => \BulkImport\Processor\AbstractProcessor::ACTION_APPEND,
                ],
            ])

            ->add([
                'name' => 'action_media_update',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Action on media', // @translate
                    'info' => 'When a "revise" or an "update" is done, the media may be updated, but you may want to keep existing ones.', // @translate
                    'value_options' => [
                        \BulkImport\Processor\AbstractProcessor::ACTION_APPEND => 'Keep media', // @translate
                        \BulkImport\Processor\AbstractProcessor::ACTION_UPDATE => 'Process as main action above', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'action_media_update',
                    'value' => \BulkImport\Processor\AbstractProcessor::ACTION_APPEND,
                ],
            ])

            ->add([
                'name' => 'action_item_set_update',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Action on item set', // @translate
                    'info' => 'When a "revise" or an "update" is done, the item set may be updated, but you may want to keep existing ones.', // @translate
                    'value_options' => [
                        \BulkImport\Processor\AbstractProcessor::ACTION_APPEND => 'Keep item sets', // @translate
                        \BulkImport\Processor\AbstractProcessor::ACTION_UPDATE => 'Process as main action above', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'action_item_set_update',
                    'value' => \BulkImport\Processor\AbstractProcessor::ACTION_APPEND,
                ],
            ])

            ->add([
                'name' => 'o:resource_template',
                'type' => OmekaElement\ResourceSelect::class,
                'options' => [
                    'label' => 'Resource template', // @translate
                    'empty_option' => '',
                    'resource_value_options' => [
                        'resource' => 'resource_templates',
                        'query' => [],
                        'option_text_callback' => function ($resourceTemplate) {
                            return $resourceTemplate->label();
                        },
                    ],
                ],
                'attributes' => [
                    'id' => 'o-resource-template',
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select a template…', // @translate
                    'data-api-base-url' => $urlHelper('api/default', ['resource' => 'resource_templates']),
                ],
            ])

            ->add([
                'name' => 'o:resource_class',
                'type' => OmekaElement\ResourceClassSelect::class,
                'options' => [
                    'label' => 'Resource class', // @translate
                    'empty_option' => '',
                    'term_as_value' => true,
                ],
                'attributes' => [
                    'id' => 'resource-class-select',
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select a class…', // @translate
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
                'name' => 'o:is_public',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Visibility', // @translate
                    'value_options' => [
                        'true' => 'Public', // @translate
                        'false' => 'Private', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'o-is-public',
                ],
            ])

        ;
        return $this;
    }

    protected function addFieldsets(): \Laminas\Form\Form
    {
        return $this;
    }

    protected function addMapping(): \Laminas\Form\Form
    {
        /** @var \BulkImport\Processor\Processor $processor */
        $processor = $this->getOption('processor');
        /** @var \BulkImport\Reader\Reader $reader */
        $reader = $processor->getReader();

        // Add all columns from file as inputs.
        $availableFields = $reader->getAvailableFields();
        if (!count($availableFields)) {
            return $this;
        }

        $services = $this->getServiceLocator();
        /** @var \BulkImport\Mvc\Controller\Plugin\AutomapFields $automapFields */
        $automapFields = $services->get('ControllerPluginManager')->get('automapFields');

        $this
            ->add([
                'name' => 'mapping',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Mapping from sources to resources', // @translate
                ],
            ]);

        $fieldset = $this->get('mapping');

        $fields = $automapFields($availableFields);
        foreach ($availableFields as $index => $name) {
            if (!strlen(trim($name))) {
                continue;
            }
            $fieldset
                ->add([
                    'name' => $name,
                    'type' => OmekaElement\PropertySelect::class,
                    'options' => [
                        // Fix an issue when a header of a csv file is "0".
                        'label' => is_numeric($name) && intval($name) === 0 ? "[$name]" : $name,
                        'term_as_value' => true,
                        'prepend_value_options' => $this->prependMappingOptions(),
                    ],
                    'attributes' => [
                        'value' => $fields[$index] ?? null,
                        'required' => false,
                        'multiple' => true,
                        'class' => 'chosen-select',
                        'data-placeholder' => 'Select one or more targets…', // @translate
                    ],
                ]);
        }
        return $this;
    }

    protected function prependMappingOptions(): array
    {
        return [
            // Id is only for update.
            'o:id' => 'Internal id', // @translate
            'media_identifier' => [
                'label' => 'Media identifier', // @translate
                'options' => [
                    'o:source' => 'Source', // @translate
                    'o:filename' => 'File name', // @translate
                    // When used with module Archive Repertory.
                    'o:basename' => 'Base file name', // @translate
                    'o:storage_id' => 'Storage id', // @translate
                    'o:sha256' => 'Hash', // @translate],
                ],
            ],
            'metadata' => [
                'label' => 'Resource metadata', // @translate
                'options' => [
                    'o:resource_template' => 'Resource template', // @translate
                    'o:resource_class' => 'Resource class', // @translate
                    'o:thumbnail' => 'Thumbnail', // @translate
                    'o:owner' => 'Owner', // @translate
                    'o:is_public' => 'Visibility public/private', // @translate
                ],
            ],
        ];
    }

    protected function baseInputFilter(): \Laminas\Form\Form
    {
        $this
            ->getInputFilter()
            ->add([
                'name' => 'o:resource_template',
                'required' => false,
            ])
            ->add([
                'name' => 'o:resource_class',
                'required' => false,
            ])
            ->add([
                'name' => 'o:owner',
                'required' => false,
            ])
            ->add([
                'name' => 'o:is_public',
                'required' => false,
            ])
            ->add([
                'name' => 'action',
                'required' => false,
            ])
            ->add([
                'name' => 'action_unidentified',
                'required' => false,
            ])
            ->add([
                'name' => 'identifier_name',
                'required' => false,
            ])
            ->add([
                'name' => 'action_identifier_update',
                'required' => false,
            ])
            ->add([
                'name' => 'action_media_update',
                'required' => false,
            ])
            ->add([
                'name' => 'action_item_set_update',
                'required' => false,
            ])
            ->add([
                'name' => 'allow_duplicate_identifiers',
                'required' => false,
            ]);
        return $this
            ->addEntriesToProcessInputFilter();
    }

    protected function addInputFilter(): \Laminas\Form\Form
    {
        return $this;
    }

    protected function addMappingFilter(): \Laminas\Form\Form
    {
        $inputFilter = $this->getInputFilter();
        if (!$inputFilter->has('mapping')) {
            return $this;
        }

        $inputFilter = $inputFilter->get('mapping');
        // Change required to false.
        foreach ($inputFilter->getInputs() as $input) {
            $input->setRequired(false);
        }
        return $this;
    }

    /**
     * Check if a module is active.
     */
    protected function isModuleActive(string $moduleClass): bool
    {
        $services = $this->getServiceLocator();
        /** @var \Omeka\Module\Manager $moduleManager */
        $moduleManager = $services->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule($moduleClass);
        return $module
            && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE;
    }
}
