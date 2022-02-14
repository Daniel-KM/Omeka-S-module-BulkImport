<?php declare(strict_types=1);

namespace BulkImport\Form\Reader;

use BulkImport\Form\Element as BulkImportElement;
use BulkImport\Reader\MappingsTrait;
use BulkImport\Traits\ServiceLocatorAwareTrait;
use Laminas\Form\Element;
use Laminas\Form\Form;
use Omeka\Form\Element as OmekaElement;

class JsonReaderConfigForm extends Form
{
    use ServiceLocatorAwareTrait;
    use MappingsTrait;

    public function init(): void
    {
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
                    'value_options' => $this->listMappings([['mapping' => true], ['xml' => 'xml'], ['json' => 'ini']]),
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
