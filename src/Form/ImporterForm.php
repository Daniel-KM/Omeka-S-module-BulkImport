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

        $this->add([
            'name' => 'o:label',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Label', // @translate
            ],
            'attributes' => [
                'id' => 'o-label',
            ],
        ]);

        $this->add([
            'name' => 'o-module-bulk:reader_class',
            'type' => Element\Select::class,
            'options' => [
                'label' => 'Reader', // @translate
                'value_options' => $this->getReaderOptions(),
            ],
            'attributes' => [
                'id' => 'o-module-bulk-reader-class',
            ],
        ]);

        $this->add([
            'name' => 'o-module-bulk:processor_class',
            'type' => Element\Select::class,
            'options' => [
                'label' => 'Processor', // @translate
                'value_options' => $this->getProcessorOptions(),
            ],
            'attributes' => [
                'id' => 'o-module-bulk-processor-class',
            ],
        ]);

        $this->add([
            'name' => 'importer_submit',
            'type' => Fieldset::class,
        ]);

        $fieldset = $this->get('importer_submit');

        $fieldset->add([
            'name' => 'submit',
            'type' => Element\Submit::class,
            'attributes' => [
                'id' => 'submitbutton',
                'value' => 'Save', // @translate
            ],
        ]);
    }

    public function setImporter(Importer $importer): void
    {
        $this->importer = $importer;
    }

    protected function getReaderOptions()
    {
        $options = [];
        $readerManager = $this->getServiceLocator()->get(ReaderManager::class);
        $readers = $readerManager->getPlugins();
        foreach ($readers as $key => $reader) {
            $options[$key] = $reader->getLabel();
        }
        return $options;
    }

    protected function getProcessorOptions()
    {
        $options = [];
        $processorManager = $this->getServiceLocator()->get(ProcessorManager::class);
        $processors = $processorManager->getPlugins();
        foreach ($processors as $key => $processor) {
            $options[$key] = $processor->getLabel();
        }
        return $options;
    }
}
