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
use Omeka\Entity\Job;
use Omeka\Job\AbstractJob;

/**
 * @todo Make the importer manages whole process with reader, mapping and processor? So the processor will be api / create / update.
 */
class Import extends AbstractJob
{
    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var \BulkImport\Stdlib\MetaMapper
     */
    protected $metaMapper;

    /**
     * @var \BulkImport\Api\Representation\ImportRepresentation
     */
    protected $import;

    /**
     * @var \BulkImport\Api\Representation\ImporterRepresentation
     */
    protected $importer;

    /**
     * @var \BulkImport\Reader\Reader
     */
    protected $reader;

    /**
     * @var array
     */
    protected $mapping;

    /**
     * @var \BulkImport\Processor\Processor
     */
    protected $processor;

    public function perform(): void
    {
        // TODO Manage "\r" manually for "auto_detect_line_endings": check if all processes purge windows and mac issues for end of lines "\r".
        if (PHP_VERSION_ID < 80100) {
            ini_set('auto_detect_line_endings', '1');
        }

        $services = $this->getServiceLocator();
        $this->logger = $services->get('Omeka\Logger');
        $this->api = $services->get('Omeka\ApiManager');

        $bulkImportId = $this->getArg('bulk_import_id');
        if (!$bulkImportId) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'Import record id does not set.', // @translate
            );
            return;
        }

        $this->import = $this->api->search('bulk_imports', ['id' => $id, 'limit' => 1])->getContent();
        if (!count($this->import)) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'Import record id #{id} does not exist.', // @translate
                ['id' => $id]
            );
            return;
        }
        $this->import = reset($this->import);
        $this->importer = $this->import->importer();

        // The reference id is the job id for now.
        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('bulk/import/' . $this->import->id());
        $this->logger->addProcessor($referenceIdProcessor);

        // Make compatible with EasyAdmin tasks, that may use a fake job.
        if ($this->job->getId()) {
            $this->api->update('bulk_imports', $this->import->id(), ['o:job' => $this->job], [], ['isPartial' => true]);
        }

        $this->reader = $this->getReader();
        if (!$this->reader) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'Reader "{reader}" is not available.', // @translate
                ['reader' => $this->importer->readerClass()]
            );
            return;
        }

        $this->processor = $this->getProcessor();
        if (!$this->processor) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'Processor "{processor}" is not available.', // @translate
                ['processor' => $this->importer->processorClass()]
            );
            return;
        }

        $this->reader
            ->setLogger($this->logger);

        $this->processor
            ->setReader($this->reader)
            ->setLogger($this->logger)
            // This is not the job entity, but the job itself, so it has no id.
            // FIXME Clarify name of job for job id/import id.
            ->setJob($this);

        $this->prepareDefaultSite();

        $this->logger->log(Logger::NOTICE, 'Import started'); // @translate

        $this->processor->process();

        // Try to clean remaining uploaded files.
        if ($this->processor instanceof Parametrizable) {
            $files = $this->processor->getParams()['files'] ?? [];
            foreach ($files as $file) {
                @unlink($file['filename']);
                if (!empty($file['dirpath'])) {
                    $this->rmDir($file['dirpath']);
                }
            }
        }

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

    protected function getReader(): ?Reader
    {
        $services = $this->getServiceLocator();
        $readerClass = $this->importer->readerClass();
        $readerManager = $services->get(ReaderManager::class);
        if (!$readerManager->has($readerClass)) {
            return null;
        }
        $reader = $readerManager->get($readerClass);
        $reader->setServiceLocator($services);
        if ($reader instanceof Configurable) {
            $reader->setConfig($this->importer->readerConfig());
        }
        if ($reader instanceof Parametrizable) {
            $reader->setParams($this->import->readerParams());
        }
        return $reader;
    }

    protected function getProcessor(): ?Processor
    {
        $services = $this->getServiceLocator();
        $processorClass = $this->importer->processorClass();
        $processorManager = $services->get(ProcessorManager::class);
        if (!$processorManager->has($processorClass)) {
            return null;
        }
        $processor = $processorManager->get($processorClass);
        $processor->setServiceLocator($services);
        if ($processor instanceof Configurable) {
            $processor->setConfig($this->importer->processorConfig());
        }
        if ($processor instanceof Parametrizable) {
            $processor->setParams($this->import->processorParams());
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
                $defaultSiteSlug = $this->api->read('sites', ['id' => $defaultSiteId], [], ['initialize' => false, 'finalize' => false, 'responseContent' => 'resource'])->getContent()->getSlug();
            } catch (NotFoundException $e) {
            }
        }

        if (empty($defaultSiteSlug)) {
            $defaultSiteSlugs = $this->api->search('sites', ['limit' => 1], ['initialize' => false, 'returnScalar' => 'slug'])->getContent();
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
        $urlPlugin = $services->get('ControllerPluginManager')->get('url');
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
                    htmlspecialchars($urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'id' => $jobId], ['force_canonical' => true]))
                ),
                'jobId' => $jobId,
                'link_close' => '</a>',
                'link_open_log' => sprintf(
                    '<a href="%s">',
                    htmlspecialchars($urlPlugin->fromRoute('admin/bulk/id', ['controller' => 'import', 'action' => 'logs', 'id' => $this->import->id()], ['force_canonical' => true]))
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

    /**
     * Remove a dir from filesystem.
     *
     * @param string $dirpath Absolute path.
     */
    private function rmDir(string $dirPath): bool
    {
        if (!file_exists($dirPath)) {
            return true;
        }
        if (strpos($dirPath, '/..') !== false || substr($dirPath, 0, 1) !== '/') {
            return false;
        }
        $files = array_diff(scandir($dirPath), ['.', '..']);
        foreach ($files as $file) {
            $path = $dirPath . '/' . $file;
            if (is_dir($path)) {
                $this->rmDir($path);
            } else {
                unlink($path);
            }
        }
        return rmdir($dirPath);
    }
}
