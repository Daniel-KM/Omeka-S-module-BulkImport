<?php declare(strict_types=1);

namespace BulkImport\Service\ControllerPlugin;

use BulkImport\Mvc\Controller\Plugin\UpdateResource;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class UpdateResourceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $plugins = $services->get('ControllerPluginManager');
        return new UpdateResource(
            $services->get('Omeka\ApiManager'),
            $services->get('Omeka\ApiAdapterManager'),
            $plugins->get('bulk'),
            $services->get('Omeka\Logger')
        );
    }
}
