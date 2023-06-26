<?php declare(strict_types=1);

namespace BulkImport\Form;

use BulkImport\Entity\Importer;
use BulkImport\Processor\Manager as ProcessorManager;
use BulkImport\Reader\Manager as ReaderManager;
use BulkImport\Traits\ServiceLocatorAwareTrait;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Laminas\Form\Form;

class ImporterForm extends Form
{
    use ServiceLocatorAwareTrait;

    /**
     * @var Importer
     */
    protected $importer;

    public function init(): void
    {
        parent::init();

        $this
            ->setAttribute('id', 'bulk-importer-form')
            ->add([
                'name' => 'o:label',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Label', // @translate
                ],
                'attributes' => [
                    'id' => 'o-label',
                ],
            ])

            ->add([
                'name' => 'o-bulk:reader_class',
                'type' => Element\Select::class,
                'options' => [
                    'label' => 'Reader', // @translate
                    'value_options' => $this->getReaderOptions(),
                ],
                'attributes' => [
                    'id' => 'o-bulk-reader-class',
                ],
            ])

            ->add([
                'name' => 'o-bulk:processor_class',
                'type' => Element\Select::class,
                'options' => [
                    'label' => 'Processor', // @translate
                    'value_options' => $this->getProcessorOptions(),
                ],
                'attributes' => [
                    'id' => 'o-bulk-processor-class',
                ],
            ])

            ->add([
                'name' => 'o:config',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Other params', // @translate
                ],
            ]);

        $fieldset = $this->get('o:config');
        $fieldset
            ->add([
                'name' => 'notify_end',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Notify by email when finished', // @translate
                ],
                'attributes' => [
                    'id' => 'notify_end',
                ],
            ]);

        $this
            ->add([
                'name' => 'importer_submit',
                'type' => Fieldset::class,
            ]);

        $this->get('importer_submit')
            ->add([
                'name' => 'submit',
                'type' => Element\Submit::class,
                'attributes' => [
                    'id' => 'submitbutton',
                    'value' => 'Save', // @translate
                ],
            ]);
    }

    public function setImporter(Importer $importer): \Laminas\Form\Form
    {
        $this->importer = $importer;
        return $this;
    }

    protected function getReaderOptions(): array
    {
        return $this->getServiceLocator()->get(ReaderManager::class)
            ->getRegisteredLabels();
    }

    protected function getProcessorOptions(): array
    {
        return $this->getServiceLocator()->get(ProcessorManager::class)
            ->getRegisteredLabels();
    }
}
