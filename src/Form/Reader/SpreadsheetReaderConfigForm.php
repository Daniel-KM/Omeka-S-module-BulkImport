<?php declare(strict_types=1);

namespace BulkImport\Form\Reader;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;

class SpreadsheetReaderConfigForm extends AbstractReaderConfigForm
{
    public function init(): void
    {
        parent::init();

        $this
            ->add([
                'name' => 'url',
                'type' => CommonElement\OptionalUrl::class,
                'options' => [
                    'label' => 'Spreadsheet url', // @translate
                ],
                'attributes' => [
                    'id' => 'url',
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'separator',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Multi-value separator', // @translate
                    'info' => 'If cells are multivalued, it is recommended to use a character that is never used, like "|" or a random string.', // @translate
                ],
                'attributes' => [
                    'id' => 'separator',
                    'value' => '',
                ],
            ]);
    }
}
