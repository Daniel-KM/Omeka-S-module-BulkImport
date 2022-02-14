<?php declare(strict_types=1);

namespace BulkImport\Controller\Admin;

use BulkImport\Traits\ServiceLocatorAwareTrait;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Model\ViewModel;

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
        $this->setBrowseDefaults('label', 'asc');

        // Importers.
        $response = $this->api()->search('bulk_importers', ['sort_by' => 'label', 'sort_order' => 'asc']);
        $importers = $response->getContent();

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

        return new ViewModel([
            'importers' => $importers,
            'imports' => $imports,
        ]);
    }

    public function browseAction()
    {
        $view = $this->indexAction();
        return $view
            ->setTemplate('bulk/admin/index/index.phtml');
    }
}
