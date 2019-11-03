<?php
namespace BulkImport\Form\Processor;

use BulkImport\Traits\ServiceLocatorAwareTrait;
use BulkImport\Form\EntriesByBatchTrait;
use Omeka\Form\Element\PropertySelect;
use Omeka\Form\Element\ResourceClassSelect;
use Omeka\Form\Element\ResourceSelect;
use Zend\Form\Element;
use Zend\Form\Fieldset;
use Zend\Form\Form;

abstract class AbstractResourceProcessorConfigForm extends Form
{
    use ServiceLocatorAwareTrait;
    use EntriesByBatchTrait;

    public function init()
    {
        $this->baseFieldset();
        $this->addFieldsets();
        $this->addEntriesByBatch();

        $this->baseInputFilter();
        $this->addInputFilter();
        $this->addEntriesByBatchInputFilter();
    }

    protected function baseFieldset()
    {
        $services = $this->getServiceLocator();
        $urlHelper = $services->get('ViewHelperManager')->get('url');

        $this->add([
            'name' => 'o:resource_template',
            'type' => ResourceSelect::class,
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
        ]);

        $this->add([
            'name' => 'o:resource_class',
            'type' => ResourceClassSelect::class,
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
        ]);

        $this->add([
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
        ]);

        $this->add([
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
        ]);

        $this->add([
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
        ]);

        $this->add([
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
        ]);

        $this->add([
            'name' => 'identifier_name',
            'type' => PropertySelect::class,
            'options' => [
                'label' => 'Identifier name', // @translate
                'info' => 'Allows to identify existing resources, for example to attach a media to an existing item or to update a resource. It is always recommended to set one ore more unique identifiers to all resources, with a prefix.', // @translate
                'empty_option' => '', // @translate
                'prepend_value_options' => [
                    'o:id' => 'Internal id', // @translate
                ],
                'term_as_value' => true,
            ],
            'attributes' => [
                'id' => 'identifier_name',
                'multiple' => true,
                'required' => false,
                'value' => [
                    'o:id',
                    'dcterms:identifier',
                ],
                'class' => 'chosen-select',
                'data-placeholder' => 'Select an identifier name…', // @translate
            ],
        ]);

        $this->add([
            'name' => 'allow_duplicate_identifiers',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Allow duplicate identifiers', // @translate
                'info' => 'Not recommended, but needed to be compliant with old databases. Duplicates are logged.', // @translate
            ],
            'attributes' => [
                'id' => 'allow_duplicate_identifiers',
            ],
        ]);
    }

    protected function addFieldsets()
    {
    }

    protected function addMapping()
    {
        /** @var \BulkImport\Interfaces\Processor $processor */
        $processor = $this->getOption('processor');
        /** @var \BulkImport\Interfaces\Reader $reader */
        $reader = $processor->getReader();

        // Add all columns from file as inputs.
        $availableFields = $reader->getAvailableFields();

        if (!count($availableFields)) {
            return;
        }

        $services = $this->getServiceLocator();
        /** @var \BulkImport\View\Helper\AutomapFields $automapFields */
        $automapFields = $services->get('ViewHelperManager')->get('automapFields');

        $this->add([
            'name' => 'mapping',
            'type' => Fieldset::class,
            'options' => [
                'label' => 'Mapping', // @translate
            ],
        ]);

        $fieldset = $this->get('mapping');

        $fields = $automapFields($availableFields);
        foreach ($availableFields as $index => $name) {
            if (!strlen(trim($name))) {
                continue;
            }
            $fieldset->add([
                'name' => $name,
                'type' => PropertySelect::class,
                'options' => [
                    // Fix an issue when a header of a csv file is "0".
                    'label' => is_numeric($name) && intval($name) === 0 ? "[$name]" : $name,
                    'term_as_value' => true,
                    'prepend_value_options' => $this->prependMappingOptions(),
                ],
                'attributes' => [
                    'value' => isset($fields[$index]) ? $fields[$index] : null,
                    'required' => false,
                    'multiple' => true,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select one or more targets…', // @translate
                ],
            ]);
        }
    }

    protected function prependMappingOptions()
    {
        return [
            'metadata' => [
                'label' => 'Resource metadata', // @translate
                'options' => [
                    // Id is only for update.
                    'o:id' => 'Internal id', // @translate
                    'o:resource_template' => 'Resource template', // @translate
                    'o:resource_class' => 'Resource class', // @translate
                    'o:owner' => 'Owner', // @translate
                    'o:is_public' => 'Visibility public/private', // @translate
                ],
            ],
        ];
    }

    protected function baseInputFilter()
    {
        $inputFilter = $this->getInputFilter();
        $inputFilter->add([
            'name' => 'o:resource_template',
            'required' => false,
        ]);
        $inputFilter->add([
            'name' => 'o:resource_class',
            'required' => false,
        ]);
        $inputFilter->add([
            'name' => 'o:owner',
            'required' => false,
        ]);
        $inputFilter->add([
            'name' => 'o:is_public',
            'required' => false,
        ]);
        $inputFilter->add([
            'name' => 'action',
            'required' => false,
        ]);
        $inputFilter->add([
            'name' => 'action_unidentified',
            'required' => false,
        ]);
        $inputFilter->add([
            'name' => 'identifier_name',
            'required' => false,
        ]);
        $inputFilter->add([
            'name' => 'allow_duplicate_identifiers',
            'required' => false,
        ]);
    }

    protected function addInputFilter()
    {
    }

    protected function addMappingFilter()
    {
        $inputFilter = $this->getInputFilter();
        if (!$inputFilter->has('mapping')) {
            return;
        }

        $inputFilter = $inputFilter->get('mapping');
        // Change required to false.
        foreach ($inputFilter->getInputs() as $input) {
            $input->setRequired(false);
        }
    }
}
