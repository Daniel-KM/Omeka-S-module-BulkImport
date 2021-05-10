<?php declare(strict_types=1);
namespace BulkImport\Form\Reader;

use BulkImport\Traits\ServiceLocatorAwareTrait;
use Laminas\Form\Element;
use Laminas\Form\Form;

class XmlReaderConfigForm extends Form
{
    use ServiceLocatorAwareTrait;

    public function init(): void
    {
        $this->add([
            'name' => 'xsl_sheet',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'XSLT file used to convert source', // @translate
                'info' => 'Default sheets are located in "modules/BulkImport/data/xsl".',
            ],
            'attributes' => [
                'id' => 'xsl_sheet',
                'value' => '',
                'placeholder' => 'modules/BulkImport/data/xsl/identity.xslt1.xsl',
            ],
        ]);

        parent::init();
    }
}
