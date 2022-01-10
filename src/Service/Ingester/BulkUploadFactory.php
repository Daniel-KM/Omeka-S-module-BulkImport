<?php declare(strict_types=1);

namespace BulkImport\Service\Ingester;

use BulkImport\Media\Ingester\BulkUpload;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class BulkUploadFactory implements FactoryInterface
{
    /**
     * Create the BulkUpload media ingester service.
     *
     * @return BulkUpload
     */
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new BulkUpload(
            $services->get(\Omeka\File\Uploader::class)
        );
    }
}
