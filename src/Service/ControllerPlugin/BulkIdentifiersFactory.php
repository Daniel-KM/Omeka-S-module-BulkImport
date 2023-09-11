<?php declare(strict_types=1);

namespace BulkImport\Service\ControllerPlugin;

use BulkImport\Mvc\Controller\Plugin\BulkIdentifiers;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class BulkIdentifiersFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $plugins = $services->get('ControllerPluginManager');
        return new BulkIdentifiers(
            $services->get('Omeka\ApiManager'),
            $plugins->get('bulk'),
            // Use class name to use it even when CsvImport is installed.
            $plugins->get(\BulkImport\Mvc\Controller\Plugin\FindResourcesFromIdentifiers::class),
            $services->get('Omeka\Logger')
        );
    }
}
