<?php

namespace BulkImport\Service\ControllerPlugin;

use BulkImport\Mvc\Controller\Plugin\Bulk;
use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;

class BulkFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedNamed, array $options = null)
    {
        return new Bulk($services);
    }
}
