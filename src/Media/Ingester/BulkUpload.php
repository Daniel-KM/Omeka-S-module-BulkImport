<?php declare(strict_types=1);

namespace BulkImport\Media\Ingester;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Request;
use Omeka\Entity\Media;
use Omeka\File\TempFileFactory;
use Omeka\File\Validator;
use Omeka\Media\Ingester\IngesterInterface;
use Omeka\Stdlib\ErrorStore;

class BulkUpload implements IngesterInterface
{
    /**
     * @var TempFileFactory
     */
    protected $tempFileFactory;

    /**
     * @var Validator
     */
    protected $validator;

    /**
     * @var string
     */
    protected $tempDir;

    public function __construct(TempFileFactory $tempFileFactory, Validator $validator, string $tempDir)
    {
        $this->tempFileFactory = $tempFileFactory;
        $this->validator = $validator;
        $this->tempDir = $tempDir;
    }

    public function getLabel()
    {
        return 'Files'; // @translate
    }

    public function getRenderer()
    {
        return 'file';
    }

    public function ingest(Media $media, Request $request, ErrorStore $errorStore)
    {
        $data = $request->getContent();

        $fileData = $data['ingest_file_data'] ?? [];
        if (empty($fileData) || empty($fileData['name']) || empty($fileData['tmp_name'])) {
            $errorStore->addError('error', 'No files were uploaded'); // @translate
            return;
        }

        $filepath = $this->tempDir . DIRECTORY_SEPARATOR . $fileData['tmp_name'];
        if (!file_exists($filepath)) {
            $errorStore->addError('error', 'File is missing'); // @translate
            return;
        }

        $fileinfo = new \SplFileInfo($filepath);
        $realPath = $this->verifyFile($fileinfo);
        if (is_null($realPath)) {
            $errorStore->addError('ingest_file_data', sprintf(
                'Cannot upload file "%s". File does not exist or does not have sufficient permissions', // @translate
                $filepath
            ));
            return;
        }

        $tempFile = $this->tempFileFactory->build();
        $tempFile->setSourceName($fileData['name']);
        $tempFile->setTempPath($realPath);

        if (!$this->validator->validate($tempFile, $errorStore)) {
            // Errors are already stored.
            @$tempFile->delete();
            return;
        }

        if (!array_key_exists('o:source', $data)) {
            $media->setSource($fileData['name']);
        }
        $storeOriginal = (!isset($data['store_original']) || $data['store_original']);
        $tempFile->mediaIngestFile($media, $request, $errorStore, $storeOriginal, true, true, true);
        @$tempFile->delete();
    }

    public function form(PhpRenderer $view, array $options = [])
    {
        if ($view->setting('disable_file_validation', false)) {
            $allowedMediaTypes = '';
            $allowedExtensions = '';
        } else {
            $allowedMediaTypes = $view->setting('media_type_whitelist', []);
            $allowedExtensions = $view->setting('extension_whitelist', []);
            $allowedMediaTypes = implode(',', $allowedMediaTypes);
            $allowedExtensions = implode(',', $allowedExtensions);
        }

        // "upload_max_filesize", "post_max_size", "max_file_uploads" are no
        // more a limit since files are sent one by one, and by small chunks.

        $data = [
            'data-allowed-media-types' => $allowedMediaTypes,
            'data-allowed-extensions' => $allowedExtensions,
            'data-translate-pause' => $view->translate('Pause'), // @translate
            'data-translate-resume' => $view->translate('Resume'), // @translate
            'data-translate-no-file' => $view->translate('No files currently selected for upload'), // @translate
            'data-translate-invalid-file' => $view->translate('Not a valid file type, extension or size. Update your selection.'), // @translate
        ];

        $dataAttributes = $this->arrayToAttributes($view, $data);

        $uploadFiles = $view->translate('Upload files'); // @translate
        $divDrop = $view->translate('Drag and drop'); // @translate
        $browseFiles = $view->translate('Browse files'); // @translate
        $browseDirectory = $view->translate('Select directory'); // @translate
        $wait = $view->translate('Wait before submission…'); // @translate
        $buttonPause = $data['data-translate-pause'];

        return <<<HTML
<div class="field media-bulk-upload" data-main-index="__index__" $dataAttributes>
    <div class="field-meta">
        <label for="media-file-input-__index__">$uploadFiles</label>
    </div>
    <div class="inputs bulk-drop">
        <span>$divDrop</span>
        <button type="button" class="button-browse button-browse-files">$browseFiles</button>
        <button type="button" class="button-browse button-browse-directory" webkitdirectory="webkitdirectory">$browseDirectory</button>
        <button type="button" class="button-pause">$buttonPause</button>
    </div>
</div>
<input type="hidden" name="o:media[__index__][file_index]" value="__index__"/>
<input type="file" value="" class="submit-ready" style="display: none; visibility: hidden"/>
<input type="hidden" name="filesData[file][__index__]" value="{}" class="filesdata"/>
<div class="media-files-input-full-progress empty">
    <span class="progress-current"></span> / <span class="progress-total"></span>
    <span class="progress-wait">$wait</span>
</div>
<div class="media-files-input-preview"><ol></ol></div>
HTML;
    }

    /**
     * Get the size in bytes represented by the given php ini config string.
     *
     * @see \Omeka\View\Helper\UploadLimit::parseSize()
     */
    protected function parseSize($sizeString): int
    {
        $value = intval($sizeString);
        $lastChar = strtolower(substr($sizeString, -1));
        // Note: these cases fall through purposely
        switch ($lastChar) {
            case 'g':
                $value *= 1024;
                // no break.
            case 'm':
                $value *= 1024;
                // no break.
            case 'k':
                $value *= 1024;
        }
        return $value;
    }

    /**
     * Verify the passed file.
     */
    protected function verifyFile(\SplFileInfo $fileinfo): ?string
    {
        if (!$this->tempDir) {
            return null;
        }
        $realPath = $fileinfo->getRealPath();
        if (false === $realPath) {
            return null;
        }
        if (strpos($realPath, $this->tempDir) !== 0) {
            return null;
        }
        if (!$fileinfo->isFile() || !$fileinfo->isReadable()) {
            return null;
        }
        return $realPath;
    }

    /**
     * @todo Keys are not checked, but this is only use internaly.
     */
    protected function arrayToAttributes(PhpRenderer $view, array $attributes): string
    {
        $escapeAttr = $view->plugin('escapeHtmlAttr');
        return implode(' ', array_map(function($key) use ($attributes, $escapeAttr) {
            if (is_bool($attributes[$key])) {
                return $attributes[$key] ? $key . '="' . $key . '"' : '';
            }
            return $key . '="' . $escapeAttr($attributes[$key]) . '"';
        }, array_keys($attributes)));
    }
}
