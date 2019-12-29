<?php
namespace BulkImport\Job;

use BulkImport\Api\Representation\ImportRepresentation;
use BulkImport\Interfaces\Configurable;
use BulkImport\Interfaces\Parametrizable;
use BulkImport\Processor\Manager as ProcessorManager;
use BulkImport\Reader\Manager as ReaderManager;
use Log\Stdlib\PsrMessage;
use Omeka\Job\AbstractJob;
use Zend\Log\Logger;

class Import extends AbstractJob
{
    /**
     * @var ImportRepresentation
     */
    protected $import;

    /**
     * @var \Zend\Log\Logger
     */
    protected $logger;

    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    public function perform()
    {
        ini_set('auto_detect_line_endings', true);

        $logger = $this->getLogger();
        $import = $this->getImport();
        $this->api()->update('bulk_imports', $import->id(), ['o:job' => $this->job], [], ['isPartial' => true]);
        $reader = $this->getReader();
        $processor = $this->getProcessor();
        $processor->setReader($reader);
        $processor->setLogger($logger);
        $processor->setJob($this);

        $logger->log(Logger::NOTICE, 'Import started'); // @translate

        $processor->process();

        $logger->log(Logger::NOTICE, 'Import completed'); // @translate
    }

    /**
     * Get the logger for the bulk process (the Omeka one, with reference id).
     *
     * @return \Zend\Log\Logger
     */
    protected function getLogger()
    {
        if ($this->logger) {
            return $this->logger;
        }
        $this->logger = $this->getServiceLocator()->get('Omeka\Logger');
        $referenceIdProcessor = new \Zend\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('bulk/import/' . $this->getImport()->id());
        $this->logger->addProcessor($referenceIdProcessor);
        return $this->logger;
    }

    /**
     * @return \Omeka\Api\Manager
     */
    protected function api()
    {
        if (!$this->api) {
            $this->api = $this->getServiceLocator()->get('Omeka\ApiManager');
        }
        return $this->api;
    }

    /**
     * @return \BulkImport\Api\Representation\ImportRepresentation|null
     */
    protected function getImport()
    {
        if ($this->import) {
            return $this->import;
        }

        $id = $this->getArg('bulk_import_id');
        if ($id) {
            $content = $this->api()->search('bulk_imports', ['id' => $id, 'limit' => 1])->getContent();
            $this->import = is_array($content) && count($content) ? reset($content) : null;
        }

        if (empty($this->import)) {
            // TODO Avoid the useless trace in the log for jobs.
            throw new \Omeka\Job\Exception\InvalidArgumentException('Import record does not exist'); // @translate
        }

        return $this->import;
    }

    /**
     * @throws \Omeka\Job\Exception\InvalidArgumentException
     * @return \BulkImport\Interfaces\Reader
     */
    protected function getReader()
    {
        $services = $this->getServiceLocator();
        $import = $this->getImport();
        $importer = $import->importer();
        $readerClass = $importer->readerClass();
        $readerManager = $services->get(ReaderManager::class);
        if (!$readerManager->has($readerClass)) {
            throw new \Omeka\Job\Exception\InvalidArgumentException(
                new PsrMessage(
                    'Reader "{reader}" is not available.', // @translate
                    ['reader' => $readerClass]
                )
            );
        }
        $reader = $readerManager->get($readerClass);
        $reader->setServiceLocator($services);
        if ($reader instanceof Configurable && $reader instanceof Parametrizable) {
            $reader->setConfig($importer->readerConfig());
            $reader->setParams($import->readerParams());
        }
        return $reader;
    }

    /**
     * @throws \Omeka\Job\Exception\InvalidArgumentException
     * @return \BulkImport\Interfaces\Processor
     */
    protected function getProcessor()
    {
        $services = $this->getServiceLocator();
        $import = $this->getImport();
        $importer = $import->importer();
        $processorClass = $importer->processorClass();
        $processorManager = $services->get(ProcessorManager::class);
        if (!$processorManager->has($processorClass)) {
            throw new \Omeka\Job\Exception\InvalidArgumentException(
                new PsrMessage(
                    'Processor "{processor}" is not available.', // @translate
                    ['processor' => $processorClass]
                )
            );
        }
        $processor = $processorManager->get($processorClass);
        $processor->setServiceLocator($services);
        if ($processor instanceof Configurable && $processor instanceof Parametrizable) {
            $processor->setConfig($importer->processorConfig());
            $processor->setParams($import->processorParams());
        }
        return $processor;
    }
}
