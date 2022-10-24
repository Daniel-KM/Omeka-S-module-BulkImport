<?php declare(strict_types=1);

namespace BulkImport\Form\Processor;

use Laminas\Form\Element;
use Laminas\Form\Fieldset;

class AssetProcessorParamsForm extends AssetProcessorConfigForm
{
    public function init(): void
    {
        $this
            ->baseFieldset()
            ->addMapping();

        $this
            ->baseInputFilter()
            ->addMappingFilter();
    }

    protected function addMapping(): \Laminas\Form\Form
    {
        /**
         * @var \BulkImport\Processor\Processor $processor
         * @var \BulkImport\Reader\Reader $reader
         */
        $processor = $this->getOption('processor');
        $reader = $processor->getReader();

        // Add all columns from file as inputs.
        $availableFields = $reader->getAvailableFields();
        if (!count($availableFields)) {
            return $this;
        }

        $this
            ->add([
                'name' => 'mapping',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Mapping from sources to resources', // @translate
                ],
            ]);

        $fieldset = $this->get('mapping');

        $fields = $this->automapFields($availableFields);
        $valueOptions = [
            'file' => 'File', // @translate
            'url' => 'Url', // @translate
            'o:id' => 'Internal id of the asset', // @translate
            'o:name' => 'File name', // @translate
            'o:storage_id' => 'Storage id', // @translate
            'o:owner' => 'Owner', // @translate
            'o:alt_text' => 'Alternative text', // @translate
            'o:resource' => 'Resource identifier', // @translate
        ];
        foreach ($availableFields as $index => $name) {
            if (!strlen(trim($name))) {
                continue;
            }
            $fieldset
                ->add([
                    'name' => $name,
                    'type' => Element\Select::class,
                    'options' => [
                        // Fix an issue when a header of a csv file is "0".
                        'label' => is_numeric($name) && intval($name) === 0 ? "[$name]" : $name,
                        'empty_option' => '',
                        'value_options' => $valueOptions,
                    ],
                    'attributes' => [
                        'value' => $fields[$index] ?? null,
                        'required' => false,
                        // Unlike resource processor, there is only one target.
                        'multiple' => false,
                        'class' => 'chosen-select',
                        'data-placeholder' => 'Select one target…', // @translate
                    ],
                ]);
        }
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
     * @todo Use helper automapFields.
     */
    protected function automapFields(array $availableFields): array
    {
        if (!count($availableFields)) {
            return $this;
        }

        $translate = $this->getServiceLocator()->get('ViewHelperManager')->get('translate');

        $maps = [
            'file' => 'file',
            'url' => 'url',
            'oid' => 'o:id',
            'oname' => 'o:name',
            'ofilename' => 'o:name',
            'ostorageid' => 'o:storage_id',
            'oowner' => 'o:owner',
            'oalttext' => 'o:alt_text',
            'oresource' => 'o:resource',
            'omedia' => 'o:resource',
            'oitem' => 'o:resource',
            'oitemset' => 'o:resource',
            'oannotation' => 'o:resource',
            'id' => 'o:id',
            'name' => 'o:name',
            'filename' => 'o:name',
            'storageid' => 'o:storage_id',
            'owner' => 'o:owner',
            'alttext' => 'o:alt_text',
            'resource' => 'o:resource',
            'media' => 'o:resource',
            'item' => 'o:resource',
            'itemset' => 'o:resource',
            'annotation' => 'o:resource',
            'storage' => 'o:storage_id',
            'resource' => 'o:resource',
            'media' => 'o:resource',
            'item' => 'o:resource',
            'itemset' => 'o:resource',
            'annotation' => 'o:resource',
            'collection' => 'o:resource',
            $translate('file') => 'file',
            $translate('name') => 'o:name',
            $translate('file name') => 'o:name',
            $translate('storage') => 'o:storage_id',
            $translate('alternative text') => 'o:alt_text',
            $translate('owner') => 'o:owner',
            $translate('alt text') => 'o:alt_text',
            $translate('resource') => 'o:resource',
            $translate('media') => 'o:resource',
            $translate('item') => 'o:resource',
            $translate('item set') => 'o:resource',
            $translate('annotation') => 'o:resource',
        ];

        $result = array_fill_keys($availableFields, null);
        foreach ($availableFields as $index => $availableField) {
            $lower = mb_strtolower($availableField);
            if (in_array($lower, $maps)) {
                $result[$index] = $maps[$lower];
            } else {
                $alphaLower = preg_replace('~[^a-z0-9]~', '', $lower);
                if (isset($maps[$alphaLower])) {
                    $result[$index] = $maps[$alphaLower];
                }
            }
        }

        return $result;
    }
}
