<?php declare(strict_types=1);

namespace BulkImport\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;

class ConfigForm extends Form
{
    public function init(): void
    {
        $this
            ->add([
                'name' => 'bulkimport_local_path',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Folder for local files', // @translate
                    'info' => 'For security reasons, local files to import should be inside this folder.', // @translate
                ],
            ])
            ->add([
                'name' => 'bulkimport_xslt_processor',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Command of the xslt processor', // @translate
                    'info' => 'Needed to import metadata in xml files, when the process uses xslt2 sheets.', // @translate
                    'documentation' => 'https://gitlab.com/Daniel-KM/Omeka-S-module-BulkImport#Install',
                ],
            ])
            ->add([
                'name' => 'bulkimport_pdftk',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'PdfTk path', // @translate
                    'info' => 'Set the path if it is not automatically detected. PdfTk is the library used to extract metadata from pdf files.', // @translate
                ],
                'attributes' => [
                    'id' => 'bulkimport_pdftk',
                ],
            ])
            ->add([
                'name' => 'bulkimport_allow_empty_files',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Allow empty files in manual upload', // @translate
                    'info' => 'In rare cases, an admin may want to upload empty files. This option requires to disable file validation or to add the media type "application/x-empty" in main settings.', // @translate
                ],
            ])
        ;
    }
}
