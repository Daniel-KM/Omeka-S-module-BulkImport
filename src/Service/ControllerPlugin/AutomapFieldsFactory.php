<?php declare(strict_types=1);

namespace BulkImport\Service\ControllerPlugin;

use BulkImport\Mvc\Controller\Plugin\AutomapFields;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class AutomapFieldsFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $map = require dirname(__DIR__, 3) . '/data/mappings/fields_to_metadata.php';
        $plugins = $services->get('ControllerPluginManager');
        return new AutomapFields(
            $map,
            $services->get('Omeka\Logger'),
            $plugins->get('messenger'),
            $services->get('Omeka\ApiManager'),
            $plugins->get('bulk')
        );
    }
}
