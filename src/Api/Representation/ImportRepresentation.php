<?php declare(strict_types=1);
namespace BulkImport\Api\Representation;

use Omeka\Api\Representation\AbstractEntityRepresentation;
use Omeka\Api\Representation\JobRepresentation;

class ImportRepresentation extends AbstractEntityRepresentation
{
    public function getJsonLd()
    {
        return [
            'o:id' => $this->id(),
            'o-module-bulk:importer' => $this->importer()->getReference(),
            'o-module-bulk:comment' => $this->comment(),
            'o:job' => $this->job(),
            'o:undo_job' => $this->undoJob(),
            'o:status' => $this->status(),
            'o:started' => $this->started(),
            'o:ended' => $this->ended(),
            'o-module-bulk:reader_params' => $this->readerParams(),
            'o-module-bulk:processor_params' => $this->processorParams(),
        ];
    }

    public function getControllerName()
    {
        return 'import';
    }

    public function getJsonLdType()
    {
        return 'o-module-bulk:Import';
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

    public function readerParams(): ?array
    {
        return $this->resource->getReaderParams();
    }

    public function processorParams(): ?array
    {
        return $this->resource->getProcessorParams();
    }

    public function status(): string
    {
        $job = $this->job();
        return $job ? $job->status() : 'ready'; // @translate
    }

    public function statusLabel(): string
    {
        $job = $this->job();
        return $job ? $job->statusLabel() : 'Ready'; // @translate
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

    /**
     * Check if an import is undoable.
     *
     * An import is undoable only if the job process is not running and if it is
     * not a creation process.
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
        $params = $this->processorParams();
        return !empty($params['action'])
            && $params['action'] === 'create';
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
