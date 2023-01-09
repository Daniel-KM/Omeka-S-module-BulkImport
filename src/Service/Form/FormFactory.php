<?php declare(strict_types=1);

namespace BulkImport\Service\Form;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class FormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $serviceLocator, $requestedName, array $options = null)
    {
        $form = new $requestedName(null, $options ?? []);
        return $form
            ->setServiceLocator($serviceLocator);
    }
}
