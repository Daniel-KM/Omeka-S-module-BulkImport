<?php declare(strict_types=1);

namespace BulkImport\Controller\Admin;

use BulkImport\Traits\ServiceLocatorAwareTrait;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Model\ViewModel;

class MappingController extends AbstractActionController
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
        return $this->redirect()->toRoute('admin/bulk/default', ['controller' => 'mapping', 'action' => 'browse']);
    }

    public function browseAction()
    {
        $this->setBrowseDefaults('label', 'asc');

        $response = $this->api()->search('bulk_mappings', ['sort_by' => 'label', 'sort_order' => 'asc']);
        $mappings = $response->getContent();

        return new ViewModel([
            'mappings' => $mappings,
            'resources' => $mappings,
        ]);
    }
}
