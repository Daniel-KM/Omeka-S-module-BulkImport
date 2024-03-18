<?php declare(strict_types=1);

namespace BulkImport\Service\ControllerPlugin;

use BulkImport\Mvc\Controller\Plugin\BulkDiffValues;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class BulkDiffValuesFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $plugins = $services->get('ControllerPluginManager');

        $config = $services->get('Config');
        $baseUrl = $config['file_store']['local']['base_uri'] ?: $services->get('Router')->getBaseUrl() . '/files';
        $basePath = $config['file_store']['local']['base_path'] ?: OMEKA_PATH . '/files';

        $isOldOmeka = version_compare(\Omeka\Module::VERSION, '4', '<');

        return new BulkDiffValues(
            $plugins->get('diffResources'),
            $services->get('EasyMeta'),
            $services->get('Omeka\EntityManager'),
            $services->get('Omeka\Logger'),
            $basePath,
            $baseUrl,
            $isOldOmeka
        );
    }
}
