<?php declare(strict_types=1);

namespace BulkImport\Service\Ingester;

use BulkImport\Media\Ingester\BulkUploadDir;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class BulkUploadDirFactory implements FactoryInterface
{
    /**
     * Create the BulkUploadDir media ingester service.
     *
     * @return BulkUploadDir
     */
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $tempDir = $services->get('Config')['temp_dir'] ?: sys_get_temp_dir();
        return new BulkUploadDir(
            $services->get(\Omeka\File\TempFileFactory::class),
            $services->get(\Omeka\File\Validator::class),
            rtrim($tempDir, '/\\')
        );
    }
}
