<?php declare(strict_types=1);

namespace BulkImport\Job;

use BulkImport\Interfaces\Configurable;
use BulkImport\Interfaces\Parametrizable;
use BulkImport\Processor\Manager as ProcessorManager;
use BulkImport\Processor\Processor;
use BulkImport\Reader\Manager as ReaderManager;
use BulkImport\Reader\Reader;
use Laminas\Log\Logger;
use Laminas\Router\Http\RouteMatch;
use Common\Stdlib\PsrMessage;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Job\AbstractJob;

class Import extends AbstractJob
{
    use ImportTrait;

    public function perform(): void
    {
        // TODO Manage "\r" manually for "auto_detect_line_endings": check if all processes purge windows and mac issues for end of lines "\r".
        if (PHP_VERSION_ID < 80100) {
            ini_set('auto_detect_line_endings', '1');
        }

        $services = $this->getServiceLocator();
        $plugins = $services->get('ControllerPluginManager');
        $this->adapterManager = $services->get('Omeka\ApiAdapterManager');
        $this->api = $services->get('Omeka\ApiManager');
        $this->bulkCheckLog = $plugins->get('bulkCheckLog');
        $this->bulkIdentifiers = $plugins->get('bulkIdentifiers');
        $this->easyMeta = $services->get('Common\EasyMeta');
        $this->entityManager = $services->get('Omeka\EntityManager');
        $this->logger = $services->get('Omeka\Logger');
        $this->metaMapper = $services->get('Bulk\MetaMapper');
        $this->settings = $services->get('Omeka\Settings');
        $this->translator = $services->get('MvcTranslator');

        $bulkImportId = $this->getArg('bulk_import_id');
        if (!$bulkImportId) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'Import record id is not set.', // @translate
            );
            return;
        }

        $this->import = $this->api->search('bulk_imports', ['id' => $bulkImportId, 'limit' => 1])->getContent();
        if (!count($this->import)) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'Import record id #{id} does not exist.', // @translate
                ['id' => $bulkImportId]
            );
            return;
        }

        $this->import = reset($this->import);
        $this->importer = $this->import->importer();

        $processAsTask = $this->importer->configOption('importer', 'as_task');
        if ($processAsTask) {
            // jsonSerialize() keeps sub keys as unserialized objects.
            $newImport = $this->import->jsonSerialize();
            $newImport = array_diff_key($newImport, array_flip(['@id', 'o:id', 'o:job', 'o:undo_job']));
            $newImport['o-bulk:importer'] = $this->entityManager->getReference(\BulkImport\Entity\Importer::class, $this->importer->id());
            $this->import = $this->api->create('bulk_imports', $newImport)->getContent();
        }

        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('bulk/import/' . $this->import->id());
        $this->logger->addProcessor($referenceIdProcessor);

        if ($processAsTask) {
            $this->logger->notice(
                'Import as task based on import #{import_id}.', // @translate
                ['import_id' => $bulkImportId]
            );
        }

        // Make compatible with EasyAdmin tasks, that may use a fake job.
        $jobId = $this->job->getId();
        if ($jobId) {
            // Refresh job to avoid a doctrine issue.
            $jobReference = $this->entityManager->getReference(\Omeka\Entity\Job::class, $jobId);
            $this->api->update('bulk_imports', $this->import->id(), ['o:job' => $jobReference], [], ['isPartial' => true]);
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

        $this->prepareDefaultSite();

        $this->reader
            ->setLogger($this->logger);
        $this->processor
            ->setLogger($this->logger);

        // Prepare identifier names one time before validation.
        $this
            ->prepareIdentifierNames();
        if ($this->totalErrors) {
            return;
        }

        $this->bulkIdentifiers->setIdentifierNames($this->identifierNames);

        if (!$this->reader->isValid()) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            return;
        }

        if (!$this->processor->isValid()) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            return;
        }

        // TODO Finalize separation of metaMapper and metaMapperConfig.
        // Init the mapping first before storing it as default.
        $mapping = $this->import->mapping();
        if ($mapping['has_error']) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->log(Logger::NOTICE, 'Import ended: error in the mapping.'); // @translate
            return;
        }

        $mapper = $this->import->mapper();
        $this->metaMapper->setMappingName($mapper);

        $this->logger->log(Logger::NOTICE, 'Import started'); // @translate

        $this->process();

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

        $notify = (bool) $this->importer->configOption('importer', 'notify_end');
        if ($notify) {
            $this->notifyJobEnd();
        }
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
            $params = $this->import->readerParams();
            $params['mapping_config'] = $this->import->importer()->mapper();
            $reader->setParams($params);
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
            $params = $this->import->processorParams();
            $params['mapping'] = $this->import->mappingParams();
            $processor->setParams($params);
        }
        return $processor;
    }

    /**
     * The public site should be set, because it may be needed to get all values
     * of a resource during json encoding in UpdateResource::prepareResourceToUpdate().
     *
     * @todo Use job arguments.
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
        $urlHelper = $services->get('ViewHelperManager')->get('url');
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
                    htmlspecialchars($urlHelper('admin/id', ['controller' => 'job', 'id' => $jobId], ['force_canonical' => true]))
                ),
                'jobId' => $jobId,
                'link_close' => '</a>',
                'link_open_log' => sprintf(
                    '<a href="%s">',
                    htmlspecialchars($urlHelper('admin/bulk/id', ['controller' => 'import', 'action' => 'logs', 'id' => $this->import->id()], ['force_canonical' => true]))
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
            $this->logger->err(
                'Error when sending email to notify end of process.' // @translate
            );
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
        $files = array_diff(scandir($dirPath) ?: [], ['.', '..']);
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
