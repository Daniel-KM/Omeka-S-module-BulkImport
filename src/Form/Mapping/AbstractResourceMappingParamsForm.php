<?php declare(strict_types=1);

namespace BulkImport\Form\Mapping;

use BulkImport\Traits\ServiceLocatorAwareTrait;
use Laminas\Form\Fieldset;
use Laminas\Form\Form;
use Omeka\Form\Element as OmekaElement;

abstract class AbstractResourceMappingParamsForm extends Form
{
    use ServiceLocatorAwareTrait;

    public function init(): void
    {
        $this
            ->setAttribute('id', 'form-bulk-importer')
            ->addMapping();

        $this
            ->addMappingFilter();
    }

    protected function addMapping(): self
    {
        // Add all columns from file as inputs.
        $availableFields = $this->getOption('availableFields');
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
        $prependedMappingOptions = $this->prependMappingOptions();
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
                        'prepend_value_options' => $prependedMappingOptions,
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
                    'o:sha256' => 'Hash', // @translate
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

    protected function addInputFilter(): self
    {
        return $this;
    }

    protected function addMappingFilter(): self
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
