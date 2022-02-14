<?php declare(strict_types=1);

namespace BulkImport\Form\Reader;

use BulkImport\Form\Element as BulkImportElement;
use BulkImport\Reader\MappingFilesTrait;
use BulkImport\Traits\ServiceLocatorAwareTrait;
use Laminas\Form\Element;
use Laminas\Form\Form;
use Omeka\Form\Element as OmekaElement;

class JsonReaderConfigForm extends Form
{
    use ServiceLocatorAwareTrait;
    use MappingFilesTrait;

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
                'name' => 'mapping_file',
                'type' => BulkImportElement\OptionalSelect::class,
                'options' => [
                    'label' => 'Mapping file used to convert source', // @translate
                    'info' => 'Default mapping are located in "modules/BulkImport/data/mapping/json" and user ones in "files/mapping/json".', // @translate
                    'value_options' => $this->listFiles('json', 'ini'),
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'mapping_file',
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
