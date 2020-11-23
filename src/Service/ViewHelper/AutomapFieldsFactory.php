<?php declare(strict_types=1);

namespace BulkImport\Service\ViewHelper;

use BulkImport\View\Helper\AutomapFields;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class AutomapFieldsFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $map = require dirname(__DIR__, 3) . '/data/mappings/fields_to_metadata.php';
        $viewHelpers = $services->get('ViewHelperManager');
        return new AutomapFields(
            $map,
            $viewHelpers->get('api'),
            $viewHelpers->get('translate')
        );
    }
}
