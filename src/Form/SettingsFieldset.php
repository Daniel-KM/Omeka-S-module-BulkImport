<?php declare(strict_types=1);

namespace BulkImport\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;

class SettingsFieldset extends Fieldset
{
    /**
     * @var string
     */
    protected $label = 'Bulk Import module'; // @translate

    protected $elementGroups = [
        'bulk_import' => 'Bulk import', // @translate
    ];

    public function init(): void
    {
        // TODO Add a way to override this option for manual import.
        $this
            ->setAttribute('id', 'bulk-import')
            ->setOption('element_groups', $this->elementGroups)
            ->add([
                'name' => 'bulkimport_extract_metadata',
                'type' => Element\Checkbox::class,
                'options' => [
                    'element_group' => 'bulk_import',
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
                    'element_group' => 'bulk_import',
                    'label' => 'Output extracted metadata temporary in logs to help preparing mapping', // @translate
                ],
                'attributes' => [
                    'id' => 'bulkimport_extract_metadata_log',
                ],
            ])
        ;
    }
}
