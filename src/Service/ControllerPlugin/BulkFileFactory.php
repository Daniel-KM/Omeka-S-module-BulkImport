<?php declare(strict_types=1);

namespace BulkImport\Service\ControllerPlugin;

use BulkImport\Mvc\Controller\Plugin\BulkFile;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class BulkFileFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $plugins = $services->get('ControllerPluginManager');
        $settings = $services->get('Omeka\Settings');

        /** @var \Omeka\Module\Manager $moduleManager */
        $moduleManager = $services->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule('FileSideload');
        $isFileSideloadActive = $module
            && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE;

        $config = $services->get('Config');

        return new BulkFile(
            $plugins->get('bulk'),
            $plugins->get('bulkFileUploaded'),
            $services->get('Omeka\Logger'),
            $services->get('Omeka\File\TempFileFactory'),
            $services->get('Omeka\File\Store'),
            $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files'),
            $config['temp_dir'] ?? sys_get_temp_dir(),
            $isFileSideloadActive,
            (bool) $settings->get('disable_file_validation'),
            $settings->get('media_type_whitelist') ?: [],
            $settings->get('extension_whitelist') ?: [],
            (bool) $settings->get('allow_empty_files'),
            (string) $settings->get('file_sideload_directory'),
            $settings->get('file_sideload_delete_file') === 'yes'
        );
    }
}
