<?php declare(strict_types=1);

namespace BulkImport\Media\Ingester;

use Laminas\Form\Element\File;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Request;
use Omeka\Entity\Media;
use Omeka\File\Uploader;
use Omeka\Media\Ingester\IngesterInterface;
use Omeka\Stdlib\ErrorStore;

class BulkUpload implements IngesterInterface
{
    /**
     * @var Uploader
     */
    protected $uploader;

    public function __construct(Uploader $uploader)
    {
        $this->uploader = $uploader;
    }

    public function getLabel()
    {
        return 'Files and folders'; // @translate
    }

    public function getRenderer()
    {
        return 'file';
    }

    public function ingest(Media $media, Request $request, ErrorStore $errorStore)
    {
        $data = $request->getContent();
        $fileData = $request->getFileData();
        if (!isset($fileData['file'])) {
            $errorStore->addError('error', 'No files were uploaded'); // @translate
            return;
        }

        if (!isset($data['file_index'])) {
            $errorStore->addError('error', 'No file index was specified'); // @translate
            return;
        }

        $index = $data['file_index'];
        if (!isset($fileData['file'][$index])) {
            $errorStore->addError('error', 'No file uploaded for the specified index'); // @translate
            return;
        }

        $subIndex = $data['file_index_sub'];
        if (!isset($fileData['file'][$index][$subIndex])) {
            $errorStore->addError('error', 'No file uploaded for the specified sub-index'); // @translate
            return;
        }

        $tempFile = $this->uploader->upload($fileData['file'][$index][$subIndex], $errorStore);
        if (!$tempFile) {
            // Errors are already stored.
            return;
        }

        $tempFile->setSourceName($fileData['file'][$index][$subIndex]['name']);
        if (!array_key_exists('o:source', $data)) {
            $media->setSource($fileData['file'][$index][$subIndex]['name']);
        }
        $tempFile->mediaIngestFile($media, $request, $errorStore);
    }

    public function form(PhpRenderer $view, array $options = [])
    {
        if ($view->setting('disable_file_validation', false)) {
            $allowedMediaTypes = '';
            $allowedExtensions = '';
            $accept = '';
        } else {
            $allowedMediaTypes = $view->setting('media_type_whitelist', []);
            $allowedExtensions = $view->setting('extension_whitelist', []);
            $accept = implode(',', array_merge($allowedMediaTypes, $allowedExtensions));
            $allowedMediaTypes = implode(',', $allowedMediaTypes);
            $allowedExtensions = implode(',', $allowedExtensions);
        }

        $fileInput = new File('file[__index__]');
        $fileInput
            ->setOptions([
                'label' => 'Upload files', // @translate
                'info' => $view->uploadLimit(),
            ])
            ->setAttributes([
                'id' => 'media-file-input-__index__',
                'class' => 'media-files-input',
                'required' => true,
                'multiple' => true,
                'accept' => $accept,
                'data-allowed-media-types' => $allowedMediaTypes,
                'data-allowed-extensions' => $allowedExtensions,
            ]);
        return $view->formRow($fileInput)
            . <<<HTML
<input type="hidden" name="o:media[__index__][file_index]" value="__index__"/>
<div class="media-files-input-preview"></div>
HTML;
    }
}
