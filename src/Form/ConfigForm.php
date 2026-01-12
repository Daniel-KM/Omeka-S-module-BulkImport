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
                'name' => 'bulkimport_xslt_processor',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Command of the xslt processor', // @translate
                    'info' => 'Needed to import metadata in xml files, when the process uses xslt2 sheets or xsl:import. Leave empty to use PHP internal processor (may crash with xsl:import).', // @translate
                    'documentation' => 'https://gitlab.com/Daniel-KM/Omeka-S-module-BulkImport#Install',
                ],
                'attributes' => [
                    'id' => 'bulkimport_xslt_processor',
                    'placeholder' => 'java -jar /usr/share/java/Saxon-HE.jar -s:%s -xsl:%s -o:%s',
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
        ;
    }
}
