<?php declare(strict_types=1);
namespace BulkImport\Service\Plugin;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class PluginManagerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $serviceLocator, $requestedName, array $options = null)
    {
        $pluginManager = new $requestedName($serviceLocator);
        return $pluginManager;
    }
}
