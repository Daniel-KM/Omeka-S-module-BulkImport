<?php declare(strict_types=1);

namespace BulkImport\Api\Representation;

use Omeka\Api\Representation\AbstractEntityRepresentation;
use Omeka\Api\Representation\JobRepresentation;

class ImportRepresentation extends AbstractEntityRepresentation
{
    public function getControllerName()
    {
        return 'import';
    }

    public function getJsonLdType()
    {
        return 'o-bulk:Import';
    }

    public function getJsonLd()
    {
        $importer = $this->importer();
        $job = $this->job();
        $undoJob = $this->undoJob();
        return [
            'o:id' => $this->id(),
            'o-bulk:importer' => $importer ? $importer->getReference() : null,
            'o-bulk:comment' => $this->comment(),
            'o:job' => $job ? $job->getReference() : null,
            'o:undo_job' => $undoJob ? $undoJob->getReference() : null,
            'o:status' => $this->status(),
            'o:started' => $this->started(),
            'o:ended' => $this->ended(),
            'o:params' => $this->params(),
        ];
    }

    public function importer(): ?ImporterRepresentation
    {
        $importer = $this->resource->getImporter();
        return $importer
            ? $this->getAdapter('bulk_importers')->getRepresentation($importer)
            : null;
    }

    public function comment(): ?string
    {
        return $this->resource->getComment();
    }

    public function job(): ?JobRepresentation
    {
        $job = $this->resource->getJob();
        return $job
            ? $this->getAdapter('jobs')->getRepresentation($job)
            : null;
    }

    public function undoJob(): ?JobRepresentation
    {
        $job = $this->resource->getUndoJob();
        return $job
            ? $this->getAdapter('jobs')->getRepresentation($job)
            : null;
    }

    public function params(): array
    {
        return $this->resource->getParams();
    }

    public function readerParams(): array
    {
        $parameters = $this->params();
        return $parameters['reader'] ?? [];
    }

    public function readerParam(string $name, $default = null)
    {
        $parameters = $this->readerParams();
        return array_key_exists($name, $parameters)
            ? $parameters[$name]
            : $default;
    }

    public function mapper(): string
    {
        $mapper = $this->importer()->mapper();
        return in_array((string) $mapper, ['', 'automatic', 'manual'])
            ? 'import:' . $this->id()
            : $mapper;
    }

    public function mapping(): ?array
    {
        $metaMapperMapping = $this->importer()->mapping();
        if ($metaMapperMapping !== null) {
            return $metaMapperMapping;
        }

        // Create the manual or automatic mapping from the params.
        /** @var \BulkImport\Stdlib\MetaMapperConfig $metaMapperConfig */
        $metaMapperConfig = $this->getServiceLocator()->get('Bulk\MetaMapperConfig');
        $mapper = $this->mapper();
        $mappingParams = $this->mappingParams();
        $processor = $this->importer()->processor();
        return $metaMapperConfig($mapper, $mappingParams, [
            'resource_name' => $processor->getResourceName(),
            'field_types' => $processor->getFieldTypes(),
            // TODO Temp option waiting for the full manual form outputing right format, not only the term.
            'is_single_manual' => $this->importer()->mapper() === 'manual',
        ]);
    }

    public function mappingParams(): array
    {
        $parameters = $this->params();
        return $parameters['mapping'] ?? [];
    }

    public function mappingParam(string $name, $default = null)
    {
        $parameters = $this->mappingParams();
        return array_key_exists($name, $parameters)
            ? $parameters[$name]
            : $default;
    }

    public function processorParams(): array
    {
        $parameters = $this->params();
        return $parameters['processor'] ?? [];
    }

    public function processorParam(string $name, $default = null)
    {
        $parameters = $this->processorParams();
        return array_key_exists($name, $parameters)
            ? $parameters[$name]
            : $default;
    }

    public function status(): string
    {
        $job = $this->job();
        return $job ? $job->status() : 'ready'; // @translate
    }

    public function statusLabel(): string
    {
        $job = $this->job();
        return $job ? $job->statusLabel() : 'Task ready'; // @translate
    }

    public function started(): ?\DateTime
    {
        $job = $this->job();
        return $job ? $job->started() : null;
    }

    public function ended(): ?\DateTime
    {
        $job = $this->job();
        return $job ? $job->ended() : null;
    }

    public function isInProgress(): bool
    {
        $job = $this->job();
        return $job && $job->status() === \Omeka\Entity\Job::STATUS_IN_PROGRESS;
    }

    public function isCompleted(): bool
    {
        $job = $this->job();
        return $job && $job->status() === \Omeka\Entity\Job::STATUS_COMPLETED;
    }

    public function isStoppable(): bool
    {
        $job = $this->job();
        return $job && in_array($job->status(), [
            \Omeka\Entity\Job::STATUS_STARTING,
            // \Omeka\Entity\Job::STATUS_STOPPING,
            \Omeka\Entity\Job::STATUS_IN_PROGRESS,
        ]);
    }

    /**
     * Check if an import is undoable.
     *
     * An import is undoable only if the undo job is not done, if a job exists,
     * if the process is not running, and if it is not a dry run, if this is a
     * "create" action, and if there are recorded data.
     */
    public function isUndoable(): bool
    {
        $job = $this->undoJob();
        if ($job) {
            return false;
        }
        $job = $this->job();
        if (!$job) {
            return false;
        }
        if (!in_array($job->status(), [
            \Omeka\Entity\Job::STATUS_COMPLETED,
            \Omeka\Entity\Job::STATUS_STOPPED,
            \Omeka\Entity\Job::STATUS_ERROR,
        ])) {
            return false;
        }
        if ($this->isDryRun()) {
            return false;
        }
        $params = $this->processorParams();
        if (empty($params['action']) || $params['action'] !== 'create') {
            return false;
        }
        return (bool) $this->importedCount();
    }

    public function undoStatus(): string
    {
        $job = $this->undoJob();
        return $job ? $job->status() : 'ready'; // @translate
    }

    public function undoStatusLabel(): string
    {
        $job = $this->undoJob();
        return $job ? $job->statusLabel() : 'Ready'; // @translate
    }

    public function undoStarted(): ?\DateTime
    {
        $job = $this->undoJob();
        return $job ? $job->started() : null;
    }

    public function undoEnded(): ?\DateTime
    {
        $job = $this->undoJob();
        return $job ? $job->ended() : null;
    }

    public function isUndoInProgress(): bool
    {
        $job = $this->undoJob();
        return $job && $job->status() === \Omeka\Entity\Job::STATUS_IN_PROGRESS;
    }

    public function isUndoCompleted(): bool
    {
        $job = $this->undoJob();
        return $job && $job->status() === \Omeka\Entity\Job::STATUS_COMPLETED;
    }

    public function isUndoStoppable(): bool
    {
        $job = $this->undoJob();
        return $job && in_array($job->status(), [
            \Omeka\Entity\Job::STATUS_STARTING,
            // \Omeka\Entity\Job::STATUS_STOPPING,
            \Omeka\Entity\Job::STATUS_IN_PROGRESS,
        ]);
    }

    /**
     * Check if an import or an undo is stoppable.
     */
    public function isProcessStoppable(): bool
    {
        return $this->isStoppable()
            || $this->isUndoStoppable();
    }

    public function isDryRun(): bool
    {
        return $this->processorParam('processing') === 'dry_run';
    }

    public function checkFileUrl(): ?string
    {
        $job = $this->job();
        if (!$job) {
            return null;
        }
        $jobArgs = $job->args();
        if (empty($jobArgs['filename_log'])) {
            return null;
        }
        $services = $this->getServiceLocator();
        $baseUrl = $services->get('Config')['file_store']['local']['base_uri'] ?: $services->get('Router')->getBaseUrl() . '/files';
        return $baseUrl . '/bulk_import/' . $jobArgs['filename_log'];
    }

    /**
     * Get total resources imported by the job.
     *
     * The resources that were removed later are not included.
     */
    public function importedCount(): int
    {
        $job = $this->job();
        if (!$job) {
            return 0;
        }
        $response = $this->getServiceLocator()->get('Omeka\ApiManager')
            ->search('bulk_importeds', ['job_id' => $this->job()->id()]);
        return $response->getTotalResults();
    }

    public function logCount(): int
    {
        $job = $this->job();
        if (!$job) {
            return 0;
        }

        $response = $this->getServiceLocator()->get('Omeka\ApiManager')
            ->search('logs', ['job_id' => $job->id(), 'limit' => 0]);
        return $response->getTotalResults();
    }

    public function adminUrl($action = null, $canonical = false)
    {
        $url = $this->getViewHelper('Url');
        return $url(
            'admin/bulk/id',
            [
                'controller' => $this->getControllerName(),
                'action' => $action,
                'id' => $this->id(),
            ],
            ['force_canonical' => $canonical]
        );
    }
}
