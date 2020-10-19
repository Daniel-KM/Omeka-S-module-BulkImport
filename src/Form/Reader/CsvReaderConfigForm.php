<?php declare(strict_types=1);
namespace BulkImport\Form\Reader;

use Laminas\Form\Element;

class CsvReaderConfigForm extends SpreadsheetReaderConfigForm
{
    public function init(): void
    {
        $this->add([
            'name' => 'delimiter',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Delimiter', // @translate
            ],
            'attributes' => [
                'id' => 'delimiter',
                'value' => ',',
            ],
        ]);
        $this->add([
            'name' => 'enclosure',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Enclosure', // @translate
            ],
            'attributes' => [
                'id' => 'enclosure',
                'value' => '"',
            ],
        ]);
        $this->add([
            'name' => 'escape',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Escape', // @translate
            ],
            'attributes' => [
                'id' => 'escape',
                'value' => '\\',
            ],
        ]);

        parent::init();
    }
}
