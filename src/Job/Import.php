<?php declare(strict_types=1);

namespace BulkImport\Job;

use BulkImport\Api\Representation\ImportRepresentation;
use BulkImport\Interfaces\Configurable;
use BulkImport\Interfaces\Parametrizable;
use BulkImport\Processor\Manager as ProcessorManager;
use BulkImport\Processor\Processor;
use BulkImport\Reader\Manager as ReaderManager;
use BulkImport\Reader\Reader;
use Laminas\Log\Logger;
use Laminas\Router\Http\RouteMatch;
use Log\Stdlib\PsrMessage;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Api\Manager as ApiManager;
use Omeka\Entity\Job;
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
        // TODO Manage "\r" manually for "auto_detect_line_endings": check if all processes purge windows and mac issues for end of lines "\r".
        if (PHP_VERSION_ID < 80100) {
            ini_set('auto_detect_line_endings', '1');
        }

        $this->getLogger();
        $this->getImport();

        // Make compatible with EasyAdmin tasks, that may use a fake job.
        if ($this->job->getId()) {
            $this->api()->update('bulk_imports', $this->import->id(), ['o:job' => $this->job], [], ['isPartial' => true]);
        }

        $reader = $this->getReader();
        $processor = $this->getProcessor()
            ->setReader($reader)
            ->setLogger($this->logger)
            // This is not the job entity, but the job itself, so it has no id.
            // FIXME Clarify name of job for job id/import id.
            ->setJob($this);

        $this->prepareDefaultSite();

        $this->logger->log(Logger::NOTICE, 'Import started'); // @translate

        $processor->process();

        $this->logger->log(Logger::NOTICE, 'Import completed'); // @translate

        $notify = $this->job->getArgs()['notify_end'] ?? false;
        if ($notify) {
            $this->notifyJobEnd();
        }
    }

    public function getImportId(): ?int
    {
        return $this->import->id();
    }

    /**
     * @todo Remove this direct access to job to set status or to check if there is an id for task.
     */
    public function getJob(): Job
    {
        return $this->job;
    }

    /**
     * Get the logger for the bulk process (the Omeka one, with reference id).
     */
    protected function getLogger(): Logger
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
    protected function api(): ApiManager
    {
        if (!$this->api) {
            $this->api = $this->getServiceLocator()->get('Omeka\ApiManager');
        }
        return $this->api;
    }

    protected function getImport(): ?ImportRepresentation
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
     */
    protected function getReader(): Reader
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
     */
    protected function getProcessor(): Processor
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
    protected function prepareDefaultSite(): self
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
        return $this;
    }

    protected function notifyJobEnd(): self
    {
        $owner = $this->job->getOwner();
        if (!$owner) {
            $this->logger->log(Logger::ERR, 'No owner to notify end of process.'); // @translate
            return $this;
        }

        /**
         * @var \Omeka\Stdlib\Mailer $mailer
         */
        $services = $this->getServiceLocator();
        $mailer = $services->get('Omeka\Mailer');
        $urlHelper = $services->get('ViewHelperManager')->get('Url');
        $to = $owner->getEmail();
        $jobId = (int) $this->job->getId();
        $subject = new PsrMessage(
            '[Omeka Bulk Import] #{job_id}', // @translate
            ['job_id' => $jobId]
        );
        $body = new PsrMessage(
            'Import ended (job {link_open_job}#{jobId}{link_close}, {link_open_log}logs{link_close}).', // @translate
            [
                'link_open_job' => sprintf(
                    '<a href="%s">',
                    htmlspecialchars($urlHelper->fromRoute('admin/id', ['controller' => 'job', 'id' => $jobId], ['force_canonical' => true]))
                ),
                'jobId' => $jobId,
                'link_close' => '</a>',
                'link_open_log' => sprintf(
                    '<a href="%s">',
                    htmlspecialchars($urlHelper->fromRoute('admin/bulk/id', ['controller' => 'import', 'action' => 'logs', 'id' => $this->getImport()->id()], ['force_canonical' => true]))
                ),
            ]
        );
        $body->setEscapeHtml(false);

        $message = $mailer->createMessage();
        $message
            ->setSubject($subject)
            ->setBody((string) $body)
            ->addTo($to);

        try {
            $mailer->send($message);
        } catch (\Exception $e) {
            $this->logger->log(Logger::ERR, new \Omeka\Stdlib\Message(
                'Error when sending email to notify end of process.' // @translate
            ));
        }

        return $this;
    }
}
