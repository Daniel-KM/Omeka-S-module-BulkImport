<?php
namespace BulkImport\Form;

use Zend\Form\Element;
use Zend\Form\Form;

class ConfigForm extends Form
{
    public function init()
    {
        $this->add([
            'name' => 'bulkimport_local_path',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Folder for local files', // @translate
                'info' => 'For security reasons, local files to import should be inside this folder.', // @translate
            ],
        ]);

        $this->add([
            'name' => 'bulkimport_xslt_processor',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Command of the xslt processor', // @translate
                'info' => 'Needed to import metadata in xml files, when the process uses xslt2 sheets.', // @translate
                'documentation' => 'https://github.com/Daniel-KM/Omeka-S-module-BulkImport#Install',
            ],
        ]);
    }
}
