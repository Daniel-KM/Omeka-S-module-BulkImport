<?php declare(strict_types=1);

namespace BulkImport\Service\ControllerPlugin;

use BulkImport\Mvc\Controller\Plugin\Bulk;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class BulkFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $config = $services->get('Config');
        $plugins = $services->get('ControllerPluginManager');
        return new Bulk(
            $services,
            $plugins->get('api'),
            $services->get('Omeka\Connection'),
            $services->get('Omeka\DataTypeManager'),
            $services->get('EasyMeta'),
            $services->get('Omeka\Logger'),
            $services->get('MvcTranslator'),
            $config['file_store']['local']['base_path'] ?: OMEKA_PATH . '/files'
        );
    }
}
