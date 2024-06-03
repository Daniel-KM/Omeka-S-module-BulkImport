<?php declare(strict_types=1);

namespace BulkImport\Service\ControllerPlugin;

use BulkImport\Mvc\Controller\Plugin\ExtractMediaMetadata;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ExtractMediaMetadataFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: OMEKA_PATH . '/files';
        $plugins = $services->get('ControllerPluginManager');
        $settings = $services->get('Omeka\Settings');
        return new ExtractMediaMetadata(
            $services->get('Omeka\Logger'),
            $services->get('Bulk\MetaMapper'),
            $plugins->get('extractDataFromPdf'),
            $basePath,
            (bool) $settings->get('bulkimport_extract_metadata_log', false)
        );
    }
}
