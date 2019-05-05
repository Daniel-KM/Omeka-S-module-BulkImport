<?php
namespace BulkImport\Service\ControllerPlugin;

use BulkImport\Mvc\Controller\Plugin\ProcessXslt;
use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;

class ProcessXsltFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedNamed, array $options = null)
    {
        return new ProcessXslt(
            $services->get('Omeka\Settings')->get('bulkimport_xslt_processor')
        );
    }
}
