<?php
namespace BulkImport;

if (!class_exists(\Generic\AbstractModule::class)) {
    require file_exists(dirname(__DIR__) . '/Generic/AbstractModule.php')
        ? dirname(__DIR__) . '/Generic/AbstractModule.php'
        : __DIR__ . '/src/Generic/AbstractModule.php';
}

use Generic\AbstractModule;
use Zend\ModuleManager\ModuleManager;

class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

    protected $dependency = 'Log';

    public function init(ModuleManager $moduleManager)
    {
        require_once __DIR__ . '/vendor/autoload.php';
    }

    protected function postInstall()
    {
        $services = $this->getServiceLocator();
        $user = $services->get('Omeka\AuthenticationService')->getIdentity();
        /** @var \Omeka\Api\Manager $api */
        $api = $services->get('Omeka\ApiManager');

        $directory = new \RecursiveDirectoryIterator(__DIR__ . '/data/importers', \RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($directory);
        foreach ($iterator as $filepath => $file) {
            $data = include $filepath;
            $data['owner'] = $user;
            $api->create('bulk_importers', $data);
        }
    }
}
