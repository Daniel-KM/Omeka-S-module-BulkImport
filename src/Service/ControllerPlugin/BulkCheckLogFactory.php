<?php declare(strict_types=1);

namespace BulkImport\Service\ControllerPlugin;

use BulkImport\Mvc\Controller\Plugin\BulkCheckLog;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class BulkCheckLogFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $plugins = $services->get('ControllerPluginManager');

        $identity = $services->get('Omeka\AuthenticationService')->getIdentity();
        $userSettings = $services->get('Omeka\Settings\User');
        $userSettings->setTargetId($identity ? $identity->getId() : null);

        $config = $services->get('Config');
        $baseUrl = $config['file_store']['local']['base_uri'] ?: $services->get('Router')->getBaseUrl() . '/files';
        $basePath = $config['file_store']['local']['base_path'] ?: OMEKA_PATH . '/files';

        return new BulkCheckLog(
            $plugins->get('bulk'),
            $services->get('Omeka\Connection'),
            $services->get('Omeka\Logger'),
            $services->get('MvcTranslator'),
            $userSettings,
            $basePath,
            $baseUrl
        );
    }
}
