<?php declare(strict_types=1);

namespace BulkImport\Form;

use BulkImport\Form\Element as BulkImportElement;
use BulkImport\Processor\Manager as ProcessorManager;
use BulkImport\Reader\Manager as ReaderManager;
use BulkImport\Traits\ServiceLocatorAwareTrait;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Laminas\Form\Form;

class ImporterForm extends Form
{
    use ServiceLocatorAwareTrait;

    public function init(): void
    {
        parent::init();

        $this
            ->setAttribute('id', 'bulk-importer-form')
            ->add([
                'name' => 'o:label',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Label', // @translate
                ],
                'attributes' => [
                    'id' => 'o-label',
                    'required' => true,
                ],
            ])

            ->add([
                'name' => 'o-bulk:reader',
                'type' => Element\Select::class,
                'options' => [
                    'label' => 'Reader', // @translate
                    'value_options' => $this->getReaderOptions(),
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'o-bulk-reader',
                    'required' => true,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select a reader…', // @translate
                ],
            ])

            ->add([
                'name' => 'o-bulk:mapper',
                'type' => BulkImportElement\OptionalSelect::class,
                'options' => [
                    'label' => 'Mapper', // @translate
                    'value_options' => [
                        // 'automatic' => 'Automatic', // @translate
                        'manual' => 'Manual', // @translate
                    ] + $this->getMapperOptions(),
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'o-bulk-mapper',
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select a mapper…', // @translate
                ],
            ])

            ->add([
                'name' => 'o-bulk:processor',
                'type' => Element\Select::class,
                'options' => [
                    'label' => 'Processor', // @translate
                    'value_options' => $this->getProcessorOptions(),
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'o-bulk-processor',
                    'required' => true,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select a processor…', // @translate
                ],
            ])

            ->add([
                'name' => 'o:config',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Other params', // @translate
                ],
            ]);

        $fieldset = $this->get('o:config');
        $fieldset
            ->add([
                'name' => 'importer',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Importer', // @translate
                ],
            ]);
        $subFieldset = $fieldset->get('importer');
        $subFieldset
            ->add([
                'name' => 'notify_end',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Notify by email when finished', // @translate
                ],
                'attributes' => [
                    'id' => 'notify_end',
                ],
            ]);

        $this
            ->add([
                'name' => 'importer_submit',
                'type' => Fieldset::class,
            ]);

        $this->get('importer_submit')
            ->add([
                'name' => 'submit',
                'type' => Element\Submit::class,
                'attributes' => [
                    'id' => 'submitbutton',
                    'value' => 'Save', // @translate
                ],
            ]);
    }

    protected function getReaderOptions(): array
    {
        return $this->services->get(ReaderManager::class)
            ->getRegisteredLabels();
    }

    protected function getMapperOptions(): array
    {
        /** @var \BulkImport\Mvc\Controller\Plugin\MetaMapperConfigList $metaMapperConfigList */
        $metaMapperConfigList = $this->services->get('ControllerPluginManager')->get('metaMapperConfigList');
        return $metaMapperConfigList->listMappings([
            ['mapping' => true],
            ['xml' => 'xml'],
            ['json' => 'ini'],
        ]);
    }

    protected function getProcessorOptions(): array
    {
        return $this->services->get(ProcessorManager::class)
            ->getRegisteredLabels();
    }
}
