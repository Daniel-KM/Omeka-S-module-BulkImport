<?php
namespace BulkImport\Service\Controller;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $serviceLocator, $requestedName, array $options = null)
    {
        $requestedName .= 'Controller';
        $controller = new $requestedName($serviceLocator);
        return $controller;
    }
}
