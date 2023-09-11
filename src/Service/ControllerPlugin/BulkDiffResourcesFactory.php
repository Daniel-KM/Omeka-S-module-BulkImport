<?php declare(strict_types=1);

namespace BulkImport\Service\ControllerPlugin;

use BulkImport\Mvc\Controller\Plugin\BulkDiffResources;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class BulkDiffResourcesFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $plugins = $services->get('ControllerPluginManager');

        $config = $services->get('Config');
        $baseUrl = $config['file_store']['local']['base_uri'] ?: $services->get('Router')->getBaseUrl() . '/files';
        $basePath = $config['file_store']['local']['base_path'] ?: OMEKA_PATH . '/files';

        return new BulkDiffResources(
            $plugins->get('bulk'),
            $plugins->get('bulkCheckLog'),
            $plugins->get('diffResources'),
            $services->get('Omeka\Logger'),
            $basePath,
            $baseUrl
        );
    }
}
