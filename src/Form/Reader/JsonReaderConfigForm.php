<?php declare(strict_types=1);

namespace BulkImport\Form\Reader;

use BulkImport\Traits\ServiceLocatorAwareTrait;
use Common\Form\Element as CommonElement;
use Laminas\Form\Form;
use Omeka\Form\Element as OmekaElement;

class JsonReaderConfigForm extends Form
{
    use ServiceLocatorAwareTrait;

    public function init(): void
    {
        $this
            ->setAttribute('id', 'form-bulk-importer')
            ->add([
                'name' => 'url',
                'type' => CommonElement\OptionalUrl::class,
                'options' => [
                    'label' => 'Json url', // @translate
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
        ;

        parent::init();
    }
}
