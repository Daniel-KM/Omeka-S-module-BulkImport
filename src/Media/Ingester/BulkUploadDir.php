<?php declare(strict_types=1);

namespace BulkImport\Media\Ingester;

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
<div class="media-files-input-preview"></div>
HTML;
        // Attributes "directory" and "webkitdirectory" are invalid for html,
        // and Laminas removes them. So they are added directly.
        return str_replace(' accept=', ' directory="directory" webkitdirectory="webkitdirectory" accept=', $html);
    }
}
