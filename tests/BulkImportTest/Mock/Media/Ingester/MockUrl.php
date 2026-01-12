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

        // Use local mock fixture files for testing.
        // Map specific fixture filenames to actual test files.
        $fixturesPath = dirname(__DIR__, 4) . '/fixtures/files/';
        $basename = basename($uri);
        $extension = strtolower(pathinfo($uri, PATHINFO_EXTENSION));

        // Check if the exact fixture file exists.
        if (file_exists($fixturesPath . $basename)) {
            $uripath = $fixturesPath . $basename;
        } else {
            // Fallback to generic test files based on extension.
            $uripath = $extension === 'png'
                ? $fixturesPath . 'image_test_1.png'
                : $fixturesPath . 'image_test.jpg';
        }

        if (!file_exists($uripath)) {
            $errorStore->addError('error', sprintf('Mock file not found: %s', $uripath));
            return;
        }
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
