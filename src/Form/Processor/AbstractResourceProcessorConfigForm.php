<?php declare(strict_types=1);

namespace BulkImport\Form\Processor;

use Laminas\Form\Element;
use Laminas\Form\Form;
use Omeka\Form\Element as OmekaElement;

abstract class AbstractResourceProcessorConfigForm extends Form
{
    use CommonProcessTrait;

    public function init(): void
    {
        $this
            ->setAttribute('id', 'form-bulk-importer')
            ->baseFieldset()
            ->addFieldsets();

        $this
            ->baseInputFilter()
            ->addInputFilter();
    }

    protected function baseFieldset(): self
    {
        $this
            ->addCommonProcess()

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
                    // 'used_terms' => true,
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
                'name' => 'value_datatype_literal',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Use data type "literal" when a value is invalid', // @translate
                    'info' => 'The mapping can be used for automatic and more precise process when specifying data types "^^resource:item ^^literal", for example.', // @translate
                ],
                'attributes' => [
                    'id' => 'value_datatype_literal',
                ],
            ])

            ->add([
                'name' => 'allow_duplicate_identifiers',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Allow missing identifiers and duplicate identifiers', // @translate
                    'info' => 'Not recommended, but needed to be compliant with old databases. Missings and duplicates are logged.', // @translate
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
                'type' => OmekaElement\ResourceTemplateSelect::class,
                'options' => [
                    'label' => 'Resource template', // @translate
                    'empty_option' => '',
                    'disable_group_by_owner' => true,
                ],
                'attributes' => [
                    'id' => 'o-resource-template',
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select a template…', // @translate
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

            ->addOwner()

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

    protected function addFieldsets(): self
    {
        return $this;
    }

    protected function baseInputFilter(): self
    {
        $this
            ->addCommonProcessInputFilter()
            ->addOwnerInputFilter()

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
        return $this;
    }

    protected function addInputFilter(): self
    {
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
