<?php declare(strict_types=1);

namespace BulkImport\Job;

use Omeka\Job\AbstractJob;

class FileDerivative extends AbstractJob
{
    /**
     * Limit for the loop to avoid heavy sql requests.
     *
     * @var int
     */
    const SQL_LIMIT = 25;

    public function perform(): void
    {
        $services = $this->getServiceLocator();

        // Log is a dependency of the module, so add some data.
        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('bulk/upload/job_' . $this->job->getId());

        $logger = $services->get('Omeka\Logger');
        $logger->addProcessor($referenceIdProcessor);

        // The api cannot update value "has_thumbnails", so use entity manager.

        /**
         * @var \Omeka\File\TempFileFactory $tempFileFactory
         * @var \Doctrine\ORM\EntityManager $entityManager
         * @var \Doctrine\ORM\EntityRepository $mediaRepository
         */
        $tempFileFactory = $services->get('Omeka\File\TempFileFactory');
        $entityManager = $services->get('Omeka\EntityManager');
        $mediaRepository = $entityManager->getRepository(\Omeka\Entity\Media::class);

        $basePath = $services->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        $itemId = (int) $this->getArg('item_id');
        if (!$itemId) {
            return;
        }

        $criterias = [
            'item' => $itemId,
            'hasOriginal' => true,
            'renderer' => 'file',
        ];

        $ingester = $this->getArg('ingester');
        if ($ingester) {
            $criterias['ingester'] = $ingester;
        }

        if ($this->getArg('only_missing')) {
            $criterias['hasThumbnails'] = false;
        }

        $medias = $mediaRepository->findBy($criterias);
        if (!count($medias)) {
            return;
        }

        /** @var \Omeka\Entity\Media $media */
        foreach ($medias as $key => $media) {
            // Thumbnails are created only if the original file exists.
            $filename = $media->getFilename();
            $sourcePath = $basePath . '/original/' . $filename;

            if (!file_exists($sourcePath)) {
                $this->logger->err(
                    'Media #{media_id}: the original file "{filename}" does not exist.', // @translate
                    ['media_id' => $media->getId(), 'filename' => $filename]
                );
                continue;
            }

            if (!is_readable($sourcePath)) {
                $this->logger->err(
                    'Media #{media_id}: the original file "{filename}" is not readable.', // @translate
                    ['media_id' => $media->getId(), 'filename' => $filename]
                );
                continue;
            }

            $tempFile = $tempFileFactory->build();
            $tempFile->setTempPath($sourcePath);
            $tempFile->setSourceName($media->getSource());
            $tempFile->setStorageId($media->getStorageId());
            $result = $tempFile->storeThumbnails();
            // No deletion of course.

            // Update the media.
            if (!$result) {
                $this->logger->err(
                    'Media #{media_id}: an issue occurred during thumbnail creation.', // @translate
                    ['media_id' => $media->getId()]
                );
                continue;
            }

            $media->setHasThumbnails(true);
            $entityManager->persist($media);
            unset($media);
            if (++$key % self::SQL_LIMIT === 0) {
                $entityManager->flush();
            }
        }

        // Remaining medias.
        $entityManager->flush();
    }
}
