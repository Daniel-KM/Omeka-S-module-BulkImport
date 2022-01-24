<?php declare(strict_types=1);

namespace BulkImport\Service\Controller;

use BulkImport\Controller\Admin\UploadController;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class UploadControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new UploadController(
            $services->get(\Omeka\File\TempFileFactory::class),
            $services->get(\Omeka\File\Validator::class)
        );
    }
}
