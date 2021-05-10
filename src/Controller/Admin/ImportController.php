<?php declare(strict_types=1);

namespace BulkImport\Controller\Admin;

use BulkImport\Traits\ServiceLocatorAwareTrait;
use Laminas\Log\Logger;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Model\ViewModel;
use Log\Stdlib\PsrMessage;

class ImportController extends AbstractActionController
{
    use ServiceLocatorAwareTrait;

    /**
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function __construct(ServiceLocatorInterface $serviceLocator)
    {
        $this->setServiceLocator($serviceLocator);
    }

    public function indexAction()
    {
        $this->setBrowseDefaults('started');

        $page = $this->params()->fromQuery('page', 1);
        $query = $this->params()->fromQuery();

        $response = $this->api()->search('bulk_imports', $query);
        $this->paginator($response->getTotalResults(), $page);

        $imports = $response->getContent();

        return new ViewModel([
            'imports' => $imports,
            'resources' => $imports,
        ]);
    }

    public function showAction()
    {
        $id = $this->params()->fromRoute('id');
        $import = $this->api()->read('bulk_imports', $id)->getContent();

        return new ViewModel([
            'import' => $import,
            'resource' => $import,
        ]);
    }

    public function stopAction()
    {
        $id = $this->params()->fromRoute('id');
        /** @var \BulkImport\Api\Representation\ImportRepresentation $import */
        $import = $this->api()->read('bulk_imports', $id)->getContent();

        $job = $import->job();
        if ($job) {
            $status = $job->status();
            if (in_array($status, [\Omeka\Entity\Job::STATUS_STARTING, \Omeka\Entity\Job::STATUS_STOPPING, \Omeka\Entity\Job::STATUS_IN_PROGRESS])) {
                $this->jobDispatcher()->stop($job->id());
                $this->messenger()->addSuccess('Attempting to stop the job.'); // @translate
            } else {
                $this->messenger()->addWarning('The job is not running.'); // @translate
            }
        }

        return $this->redirect()->toRoute(null, ['action' => 'logs'], true);
    }

    public function logsAction()
    {
        $id = $this->params()->fromRoute('id');
        $import = $this->api()->read('bulk_imports', $id)->getContent();

        $this->setBrowseDefaults('created');

        $severity = $this->params()->fromQuery('severity', Logger::NOTICE);
        $severity = (int) preg_replace('/[^0-9]+/', '', $severity);
        $page = $this->params()->fromQuery('page', 1);
        $query = $this->params()->fromQuery();
        $query['reference'] = 'bulk/import/' . $id;
        $query['severity'] = '<=' . $severity;

        $response = $this->api()->read('bulk_imports', $id);
        $this->paginator($response->getTotalResults(), $page);

        $response = $this->api()->search('logs', $query);
        $this->paginator($response->getTotalResults(), $page);

        $logs = $response->getContent();

        return new ViewModel([
            'import' => $import,
            'resource' => $import,
            'logs' => $logs,
            'severity' => $severity,
        ]);
    }

    public function undoAction()
    {
        $importId = (int) $this->params()->fromRoute('id');
        /** @var \BulkImport\Api\Representation\ImportRepresentation import */
        $import = $this->api()->searchOne('bulk_imports', ['id' => $importId])->getContent();
        if (!$importId || !$import) {
            $message = new PsrMessage(
                'Import #{import} does not exist.', // @translate
                ['import' => $importId]
            );
            $this->messenger()->addError($message);
            return $this->redirect()->toRoute(null, ['action' => 'logs'], true);
        }

        if (!$import->isUndoable()) {
            $message = new PsrMessage(
                'The import #{import} is not undoable currently.', // @translate
                ['import' => $importId]
            );
            $this->messenger()->addError($message);
            return $this->redirect()->toRoute(null, ['action' => 'logs'], true);
        }

        $dispatcher = $this->jobDispatcher();
        $job = $dispatcher->dispatch(\BulkImport\Job\Undo::class, ['bulkImportId' => $import->id()]);
        $this->api()->update('bulk_imports', $import->id(), ['undo_job' => $job]);

        $message = new PsrMessage(
            'Undo in progress for import #{import} with job #{job}.', // @translate
            ['import' => $importId, 'job' => $job->getId()]
        );
        $this->messenger()->addSuccess($message);

        return $this->redirect()->toRoute(null, ['action' => 'logs'], true);
    }
}
