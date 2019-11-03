<?php
namespace BulkImport\Controller\Admin;

use BulkImport\Traits\ServiceLocatorAwareTrait;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\View\Model\ViewModel;

class BulkImportController extends AbstractActionController
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
        // Importers.
        $response = $this->api()->search('bulk_importers', ['sort_by' => 'label', 'sort_order' => 'asc']);
        $importers = $response->getContent();

        $this->setBrowseDefaults('label', 'asc');

        // Imports.
        $perPage = 25;
        $query = [
            'page' => 1,
            'per_page' => $perPage,
            'sort_by' => 'id',
            'sort_order' => 'desc',
        ];
        $response = $this->api()->search('bulk_imports', $query);
        $this->paginator($response->getTotalResults(), 1);

        $imports = $response->getContent();

        $view = new ViewModel;
        $view->setVariable('importers', $importers);
        $view->setVariable('imports', $imports);
        return $view;
    }
}
