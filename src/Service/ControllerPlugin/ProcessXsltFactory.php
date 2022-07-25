<?php declare(strict_types=1);

namespace BulkImport\Service\ControllerPlugin;

use BulkImport\Mvc\Controller\Plugin\ProcessXslt;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ProcessXsltFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new ProcessXslt(
            $services->get('Omeka\Settings')->get('bulkimport_xslt_processor'),
            $services->get('Config')['temp_dir'] ?: sys_get_temp_dir()
        );
    }
}
