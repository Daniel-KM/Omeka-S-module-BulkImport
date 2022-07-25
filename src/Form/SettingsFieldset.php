<?php declare(strict_types=1);

namespace BulkImport\Form;

use BulkImport\Form\Element as BulkImportElement;
use Laminas\Form\Fieldset;

class SettingsFieldset extends Fieldset
{
    /**
     * @var string
     */
    protected $label = 'Bulk Import module'; // @translate

    public function init(): void
    {
        $this
            ->setAttribute('id', 'bulk-import')
            ->add([
                'name' => 'bulkimport_convert_html',
                'type' => BulkImportElement\OptionalMultiCheckbox::class,
                'options' => [
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
