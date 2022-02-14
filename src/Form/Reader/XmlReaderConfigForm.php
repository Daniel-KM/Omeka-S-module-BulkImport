<?php declare(strict_types=1);

namespace BulkImport\Form\Reader;

use BulkImport\Form\Element as BulkImportElement;
use BulkImport\Reader\MappingsTrait;
use BulkImport\Traits\ServiceLocatorAwareTrait;
use Laminas\Form\Element;
use Laminas\Form\Form;

class XmlReaderConfigForm extends Form
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
                    'label' => 'XML url', // @translate
                ],
                'attributes' => [
                    'id' => 'url',
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'xsl_sheet',
                'type' => BulkImportElement\OptionalSelect::class,
                'options' => [
                    'label' => 'XSLT file used to normalize source', // @translate
                    'value_options' => $this->listMappings([['xsl' => 'xsl']]),
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'xsl_sheet',
                    'value' => '',
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select the xsl for conversion…', // @translate
                ],
            ])
            ->add([
                'name' => 'mapping_file',
                'type' => BulkImportElement\OptionalSelect::class,
                'options' => [
                    'label' => 'Mapping to convert source', // @translate
                    'value_options' => $this->listMappings([['xml' => 'xml'], ['json' => 'ini']]),
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'mapping_file',
                    'value' => '',
                    'class' => 'chosen-select',
                    'required' => false,
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
