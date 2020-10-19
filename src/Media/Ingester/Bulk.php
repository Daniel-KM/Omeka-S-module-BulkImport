<?php declare(strict_types=1);
namespace BulkImport\Media\Ingester;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Request;
use Omeka\Entity\Media;
use Omeka\File\TempFile;
use Omeka\Media\Ingester\IngesterInterface;
use Omeka\Stdlib\ErrorStore;

class Bulk implements IngesterInterface
{
    public function getLabel()
    {
        return 'Bulk import'; // @translate
    }

    public function getRenderer()
    {
        return 'file';
    }

    /**
     * Ingest from a bulk process.
     *
     * {@inheritDoc}
     */
    public function ingest(Media $media, Request $request, ErrorStore $errorStore): void
    {
        $data = $request->getContent();
        if (!isset($data['ingest_ingester'])) {
            $errorStore->addError('error', 'No sub-ingester specified'); // @translate
            return;
        }

        if (empty($data['ingest_tempfile'])) {
            $errorStore->addError('error', 'The file is not preloaded.'); // @translate
            return;
        }

        $tempFile = $data['ingest_tempfile'];
        if (!is_object($tempFile) || !($tempFile instanceof TempFile)) {
            $errorStore->addError('error', 'The file is not a temp file.'); // @translate
            return;
        }

        // Keep standard ingester name to simplify management.
        $media->setIngester($data['ingest_ingester']);

        if (!array_key_exists('o:source', $data)) {
            $media->setSource($tempFile->getSourceName());
        }
        // $storeOriginal = (!isset($data['store_original']) || $data['store_original']);
        $deleteTempFile = !empty($data['ingest_delete_file']);
        $tempFile->mediaIngestFile($media, $request, $errorStore, true, true, $deleteTempFile, true);
    }

    public function form(PhpRenderer $view, array $options = [])
    {
        return $view->translate('Used only for internal bulk process.'); // @translate
    }
}
