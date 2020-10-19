<?php declare(strict_types=1);
namespace BulkImportTest\Mock\Media\Ingester;

use Omeka\Api\Request;
use Omeka\Entity\Media;
use Omeka\File\TempFileFactory;
use Omeka\Media\Ingester\Url;
use Omeka\Stdlib\ErrorStore;

class MockUrl extends Url
{
    protected $tempFileFactory;

    public function setTempFileFactory(TempFileFactory $tempFileFactory): void
    {
        $this->tempFileFactory = $tempFileFactory;
    }

    public function ingest(Media $media, Request $request, ErrorStore $errorStore): void
    {
        $data = $request->getContent();
        if (!isset($data['ingest_url'])) {
            $errorStore->addError('error', 'No ingest URL specified');
            return;
        }
        $uri = $data['ingest_url'];

        // Replace a remote url by a local mock one.
        if (strpos($uri, 'http://localhost/') !== 0) {
            $extension = strtolower(pathinfo($uri, PATHINFO_EXTENSION));
            $uri = $extension === 'png'
                ? 'http://localhost/modules/BulkImport/test/BulkImportTest/_files/image_test_1.png'
                : 'http://localhost/modules/BulkImport/test/BulkImportTest/_files/image_test.jpg';
        }

        $uripath = realpath(str_replace('http://localhost/', __DIR__ . '/../../../../../../../', $uri));
        $tempFile = $this->tempFileFactory->build();
        $tempFile->setSourceName($uripath);

        copy($uripath, $tempFile->getTempPath());

        $media->setStorageId($tempFile->getStorageId());
        $media->setExtension($tempFile->getExtension());
        $media->setMediaType($tempFile->getMediaType());
        $media->setSha256($tempFile->getSha256());
        $media->setSize($tempFile->getSize());
        // $hasThumbnails = $tempFile->storeThumbnails();
        $hasThumbnails = false;
        $media->setHasThumbnails($hasThumbnails);
        if (!array_key_exists('o:source', $data)) {
            $media->setSource($uri);
        }
        if (!isset($data['store_original']) || $data['store_original']) {
            $tempFile->storeOriginal();
            $media->setHasOriginal(true);
        }
        $tempFile->delete();
    }
}
