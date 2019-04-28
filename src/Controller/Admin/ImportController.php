<?php
namespace BulkImport\Controller\Admin;

use BulkImport\Traits\ServiceLocatorAwareTrait;
use Zend\Log\Logger;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\View\Model\ViewModel;

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

        $view = new ViewModel;
        $view->setVariable('imports', $imports);
        $view->setVariable('resources', $imports);
        return $view;
    }

    public function showAction()
    {
        $id = $this->params()->fromRoute('id');
        $import = $this->api()->read('bulk_imports', $id)->getContent();

        $view = new ViewModel;
        $view->setVariable('import', $import);
        $view->setVariable('resource', $import);
        return $view;
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

        $view = new ViewModel;
        $view->setVariable('import', $import);
        $view->setVariable('resource', $import);
        $view->setVariable('logs', $logs);
        $view->setVariable('severity', $severity);
        return $view;
    }
}
