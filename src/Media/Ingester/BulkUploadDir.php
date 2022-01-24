<?php declare(strict_types=1);

namespace BulkImport\Media\Ingester;

use Laminas\Form\Element\File;
use Laminas\View\Renderer\PhpRenderer;

class BulkUploadDir extends BulkUpload
{
    public function getLabel()
    {
        return 'Folders'; // @translate
    }

    public function form(PhpRenderer $view, array $options = [])
    {
        $fileInput = $this->getFileInput($view, $options)
            ->setLabel('Upload folders'); // @translate

        $html = $view->formRow($fileInput)
            . <<<HTML
<input type="hidden" name="o:media[__index__][file_index]" value="__index__"/>
<input type="hidden" name="filesData[file][__index__]" value='{"append":{},"remove":[]}' class="filesdata"/>
<div class="media-files-input-full-progress empty"><span class="progress-current"></span> / <span class="progress-total"></span></div>
<div class="media-files-input-preview"></div>
HTML;
        // Attributes "directory" and "webkitdirectory" are invalid for html,
        // and Laminas removes them. So they are added directly.
        return str_replace(' accept=', ' directory="directory" webkitdirectory="webkitdirectory" accept=', $html);
    }

    protected function getFileInput($view, $options): File
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

        $maxSizeFile = $this->parseSize(ini_get('upload_max_filesize'));
        $maxSizePost = $this->parseSize(ini_get('post_max_size'));
        // "max_file_uploads" is no more a limit since files are sent one by one.

        $fileInput = new File('file[__index__]');
        return $fileInput
            ->setOptions([
                'label' => 'Upload files', // @translate
                'info' => $view->uploadLimit(),
            ])
            ->setAttributes([
                'id' => 'media-file-input-__index__',
                'class' => 'media-files-input',
                'required' => false,
                'multiple' => true,
                'accept' => $accept,
                'style' => 'visibility: hidden; position: absolute;',
                'data-allowed-media-types' => $allowedMediaTypes,
                'data-allowed-extensions' => $allowedExtensions,
                'data-max-size-file' => $maxSizeFile,
                'data-max-size-post' => $maxSizePost,
                'data-translate-no-file' => $view->translate('No files currently selected for upload'), // @translate
                'data-translate-invalid-file' => $view->translate('Not a valid file type, extension or size. Update your selection.'), // @translate
                'data-translate-max-size-post' => sprintf($view->translate('The total size of the uploaded files is greater than the server limit (%d bytes). Remove some new files.'), $maxSizePost), // @translate
            ]);
    }
}
