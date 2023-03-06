<?php declare(strict_types=1);

namespace BulkImport\Form;

use BulkImport\Form\Element as BulkImportElement;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;

class SettingsFieldset extends Fieldset
{
    /**
     * @var string
     */
    protected $label = 'Bulk Import module'; // @translate

    protected $elementGroups = [
        'import' => 'Import', // @translate
    ];

    public function init(): void
    {
        $this
            ->setAttribute('id', 'bulk-import')
            ->setOption('element_groups', $this->elementGroups)
            ->add([
                'name' => 'bulkimport_extract_metadata',
                'type' => Element\Checkbox::class,
                'options' => [
                    'element_group' => 'import',
                    'label' => 'Extract metadata from files on save (manual or import)', // @translate
                ],
                'attributes' => [
                    'id' => 'bulkimport_extract_metadata',
                ],
            ])
            ->add([
                'name' => 'bulkimport_extract_metadata_log',
                'type' => Element\Checkbox::class,
                'options' => [
                    'element_group' => 'import',
                    'label' => 'Output extracted metadata temporary in logs to help preparing mapping', // @translate
                ],
                'attributes' => [
                    'id' => 'bulkimport_extract_metadata_log',
                ],
            ])
            // TODO Option "bulkimport_convert_html" is too specific and should be moved somewhere else.
            ->add([
                'name' => 'bulkimport_convert_html',
                'type' => BulkImportElement\OptionalMultiCheckbox::class,
                'options' => [
                    'element_group' => 'import',
                    'label' => 'Convert new files to html (styles are kept when html purifier is disabled)', // @translate
                    'value_options' => [
                        'doc' => 'Microsoft .doc', // @translate
                        'docx' => 'Microsoft .docx', // @translate
                        'odt' => 'OpenDocument .odt (partial)', // @translate
                        'rtf' => '.rtf', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'bulkimport_convert_html',
                    'required' => false,
                ],
            ])
        ;
    }
}
