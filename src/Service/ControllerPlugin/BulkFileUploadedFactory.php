<?php declare(strict_types=1);

namespace BulkImport\Service\ControllerPlugin;

use BulkImport\Mvc\Controller\Plugin\BulkFileUploaded;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class BulkFileUploadedFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        // Unzip in Omeka temp directory.
        $config = $services->get('Config');
        $baseTempDir = $config['temp_dir'] ?: sys_get_temp_dir();
        if (!$baseTempDir) {
            throw new \Omeka\Service\Exception\RuntimeException(
                'The "temp_dir" is not configured' // @translate
            );
        }

        return new BulkFileUploaded(
            $services->get('Omeka\Logger'),
            $baseTempDir
        );
    }
}
