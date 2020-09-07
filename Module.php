<?php
namespace BulkImport;

if (!class_exists(\Generic\AbstractModule::class)) {
    require file_exists(dirname(__DIR__) . '/Generic/AbstractModule.php')
        ? dirname(__DIR__) . '/Generic/AbstractModule.php'
        : __DIR__ . '/src/Generic/AbstractModule.php';
}

use Generic\AbstractModule;
use Zend\EventManager\Event;
use Zend\EventManager\SharedEventManagerInterface;
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
        $entityManager = $services->get('Omeka\EntityManager');

        $user = $services->get('Omeka\AuthenticationService')->getIdentity();

        // The resource "bulk_importers" is not available during upgrade.
        require_once __DIR__ . '/src/Entity/Import.php';
        require_once __DIR__ . '/src/Entity/Importer.php';

        $directory = new \RecursiveDirectoryIterator(__DIR__ . '/data/importers', \RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($directory);
        foreach ($iterator as $filepath => $file) {
            $data = include $filepath;
            $data['owner'] = $user;
            $entity = new \BulkImport\Entity\Importer();
            foreach ($data as $key => $value) {
                $method = 'set' . ucfirst($key);
                $entity->$method($value);
            }
            $entityManager->persist($entity);
        }
        $entityManager->flush();
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $sharedEventManager->attach(
            \Omeka\Media\Ingester\Manager::class,
            'service.registered_names',
            [$this, 'handleMediaIngesterRegisteredNames']
        );
    }

    /**
     * Avoid to display ingester in item edit, because it's an internal one.
     *
     * @param Event $event
     */
    public function handleMediaIngesterRegisteredNames(Event $event)
    {
        $names = $event->getParam('registered_names');
        $key = array_search('bulk', $names);
        unset($names[$key]);
        $event->setParam('registered_names', $names);
    }
}
