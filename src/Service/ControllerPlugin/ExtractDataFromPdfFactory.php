<?php declare(strict_types=1);

namespace BulkImport\Service\ControllerPlugin;

use BulkImport\Mvc\Controller\Plugin\ExtractDataFromPdf;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ExtractDataFromPdfFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $settings = $services->get('ControllerPluginManager')->get('settings');
        $config = $services->get('Config');
        return new ExtractDataFromPdf(
            $settings()->get('bulkimport_pdftk'),
            $config['cli']['execute_strategy'] ?? 'exec',
            $services->get('Omeka\Logger')
        );
    }
}
