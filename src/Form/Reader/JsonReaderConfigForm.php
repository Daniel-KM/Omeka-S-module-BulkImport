<?php declare(strict_types=1);

namespace BulkImport\Form\Reader;

use BulkImport\Form\Element as BulkImportElement;
use BulkImport\Traits\ServiceLocatorAwareTrait;
use Laminas\Form\Element;
use Laminas\Form\Form;
use Omeka\Form\Element as OmekaElement;

class JsonReaderConfigForm extends Form
{
    use ServiceLocatorAwareTrait;

    public function init(): void
    {
        /** @var \BulkImport\Mvc\Controller\Plugin\MetaMapperConfigList $metaMapperConfigList */
        $metaMapperConfigList = $this->services->get('ControllerPluginManager')->get('metaMapperConfigList');

        $convertMapping = $metaMapperConfigList->listMappings([
            ['mapping' => true],
            ['xml' => 'xml'],
            ['json' => 'ini'],
        ]);

        $this
            ->add([
                'name' => 'url',
                'type' => BulkImportElement\OptionalUrl::class,
                'options' => [
                    'label' => 'Json url', // @translate
                ],
                'attributes' => [
                    'id' => 'url',
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'list_files',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'label' => 'List of files or urls', // @translate
                ],
                'attributes' => [
                    'id' => 'list_files',
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'mapping_config',
                'type' => BulkImportElement\OptionalSelect::class,
                'options' => [
                    'label' => 'Mapping to convert source', // @translate
                    'value_options' => $convertMapping,
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'mapping_config',
                    'value' => '',
                    'class' => 'chosen-select',
                    'required' => true,
                    'data-placeholder' => 'Select the mapping for conversion…', // @translate
                ],
            ])
            ->add([
                'name' => 'mapping_automatic',
                'type' => Element\Hidden::class,
                'attributes' => [
                    'id' => 'mapping_automatic',
                    'value' => '1',
                ],
            ])
        ;

        parent::init();
    }
}
