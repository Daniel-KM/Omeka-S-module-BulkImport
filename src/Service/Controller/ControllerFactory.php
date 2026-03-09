<?php declare(strict_types=1);

namespace BulkImport\Service\Controller;

use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $requestedName .= 'Controller';
        return new $requestedName($services);
    }
}
