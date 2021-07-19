<?php declare(strict_types=1);

namespace BulkImport\Job;

use BulkImport\Api\Representation\ImportRepresentation;
use BulkImport\Interfaces\Configurable;
use BulkImport\Interfaces\Parametrizable;
use BulkImport\Processor\Manager as ProcessorManager;
use BulkImport\Reader\Manager as ReaderManager;
use Laminas\Log\Logger;
use Laminas\Router\Http\RouteMatch;
use Log\Stdlib\PsrMessage;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Job\AbstractJob;

class Import extends AbstractJob
{
    /**
     * @var ImportRepresentation
     */
    protected $import;

    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    public function perform(): void
    {
        ini_set('auto_detect_line_endings', '1');

        $this->getLogger();
        $this->getImport();

        $this->api()->update('bulk_imports', $this->import->id(), ['o:job' => $this->job], [], ['isPartial' => true]);
        $reader = $this->getReader();
        $processor = $this->getProcessor()
            ->setReader($reader)
            ->setLogger($this->logger)
            // This is not the job entity, but the job itself, so it has no id.
            // TODO Clarify name of job for job id/import id.
            ->setJob($this);

        $this->prepareDefaultSite();

        $this->logger->log(Logger::NOTICE, 'Import started'); // @translate

        $processor->process();

        $this->logger->log(Logger::NOTICE, 'Import completed'); // @translate
    }

    public function getImportId()
    {
        return $this->import->id();
    }

    public function getJobId()
    {
        return $this->job->getId();
    }

    /**
     * Get the logger for the bulk process (the Omeka one, with reference id).
     *
     * @return \Laminas\Log\Logger
     */
    protected function getLogger()
    {
        if ($this->logger) {
            return $this->logger;
        }
        $this->logger = $this->getServiceLocator()->get('Omeka\Logger');
        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
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
                (string) new PsrMessage(
                    'Reader "{reader}" is not available.', // @translate
                    ['reader' => $readerClass]
                )
            );
        }
        $reader = $readerManager->get($readerClass);
        $reader->setServiceLocator($services);
        if ($reader instanceof Configurable) {
            $reader->setConfig($importer->readerConfig());
        }
        if ($reader instanceof Parametrizable) {
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
                (string) new PsrMessage(
                    'Processor "{processor}" is not available.', // @translate
                    ['processor' => $processorClass]
                )
            );
        }
        $processor = $processorManager->get($processorClass);
        $processor->setServiceLocator($services);
        if ($processor instanceof Configurable) {
            $processor->setConfig($importer->processorConfig());
        }
        if ($processor instanceof Parametrizable) {
            $processor->setParams($import->processorParams());
        }
        return $processor;
    }

    /**
     * The public site should be set, because it may be needed to get all values
     * of a resource during json encoding in ResourceUpdateTrait, line 60.
     */
    protected function prepareDefaultSite(): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $defaultSiteId = $settings->get('default_site');
        if ($defaultSiteId) {
            try {
                $defaultSiteSlug = $this->api()->read('sites', ['id' => $defaultSiteId], [], ['initialize' => false, 'finalize' => false, 'responseContent' => 'resource'])->getContent()->getSlug();
            } catch (NotFoundException $e) {
            }
        }

        if (empty($defaultSiteSlug)) {
            $defaultSiteSlugs = $this->api()->search('sites', ['limit' => 1], ['initialize' => false, 'returnScalar' => 'slug'])->getContent();
            if (empty($defaultSiteSlugs)) {
                // This is a very rare case, so avoid an exception here.
                $defaultSiteSlug = '-';
            } else {
                $defaultSiteSlug = reset($defaultSiteSlugs);
            }
        }

        /** @var \Laminas\Router\Http\RouteMatch $routeMatch */
        $event = $services->get('Application')->getMvcEvent();
        $routeMatch = $event->getRouteMatch();
        if ($routeMatch) {
            if (!$routeMatch->getParam('site-slug')) {
                $routeMatch->setParam('site-slug', $defaultSiteSlug);
            }
        } else {
            $routeMatch = new RouteMatch(['site-slug' => $defaultSiteSlug]);
            $routeMatch->setMatchedRouteName('site');
            $event->setRouteMatch($routeMatch);
        }
    }
}
