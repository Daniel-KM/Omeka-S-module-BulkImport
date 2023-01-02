<?php declare(strict_types=1);

namespace BulkImport\Form\Reader;

use BulkImport\Form\Element as BulkImportElement;
use BulkImport\Reader\MappingsTrait;
use BulkImport\Traits\ServiceLocatorAwareTrait;
use Laminas\Form\Element;
use Laminas\Form\Form;
use Omeka\Form\Element as OmekaElement;

class XmlReaderConfigForm extends Form
{
    use MappingsTrait;
    use ServiceLocatorAwareTrait;

    public function init(): void
    {
        $xslConfig = $this->listMappings([['xsl' => 'xsl']]);
        $xslConfig['mapping']['label'] = 'Configured xsl'; // @translate
        $xslConfig['user']['label'] = 'User xsl files'; // @translate
        $xslConfig['module']['label'] = 'Module xsl files'; // @translate

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
                'name' => 'xsl_sheet_pre',
                'type' => BulkImportElement\OptionalSelect::class,
                'options' => [
                    'label' => 'XSLT file used to preprocess xml or normalize source', // @translate
                    'value_options' => $xslConfig,
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'xsl_sheet_pre',
                    'value' => '',
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select the xsl for transformation…', // @translate
                ],
            ])
            ->add([
                'name' => 'xsl_sheet',
                'type' => BulkImportElement\OptionalSelect::class,
                'options' => [
                    'label' => 'XSLT file used to separate resources or normalize source', // @translate
                    'value_options' => $xslConfig,
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
                    'required' => false,
                    'data-placeholder' => 'Select the mapping for conversion…', // @translate
                ],
            ])
            ->add([
                'name' => 'xsl_params',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'label' => 'Params to pass to xsl sheets', // @translate
                    // 'info' => 'Check the xsl sheets or the documentation to know about params.', // @translate
                    'as_key_value' => true,
                ],
                'attributes' => [
                    'id' => 'xsl_params',
                    'rows' => '6',
                    'placeholder' => 'basepath = xxx/
toc_xml = 1
param_3 = yyy',
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
