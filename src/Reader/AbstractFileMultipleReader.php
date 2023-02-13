<?php declare(strict_types=1);

namespace BulkImport\Reader;

use Laminas\Form\Form;
use Log\Stdlib\PsrMessage;

/**
 * The multiple files can be uploaded files, a single url or a list of file/url.
 *
 * @todo Manage multiple uploaded files.
 */
abstract class AbstractFileMultipleReader extends AbstractReader
{
    use FileAndUrlTrait;

    /**
     * Local file to process, from uploaded or server file or fetched from url.
     *
     * @var string
     */
    protected $currentFilepath;

    public function handleParamsForm(Form $form): self
    {
        $this->lastErrorMessage = null;
        $values = $form->getData();
        $params = array_intersect_key($values, array_flip($this->paramsKeys));

        // TODO Store fetched url between form step.
        if (array_search('url', $this->paramsKeys) !== false) {
            $url = $form->get('url')->getValue();
            $url = trim($url);
            $isUrl = !empty($url);
        }

        if ($isUrl) {
            $filename = $this->fetchUrlToTempFile($url);
            $params['filename'] = $filename;
            unset($params['file']);
            $params['list_files'] = [];
        } else {
            $file = $this->getUploadedFile($form);
            if (is_null($file)) {
                unset($params['file']);
                $params['list_files'] = $params['list_files']
                    ? array_unique(array_filter(array_map('trim', $params['list_files'])))
                    : [];
            } else {
                $params['filename'] = $file['filename'];
                // Remove temp names for security purpose.
                unset($file['filename']);
                unset($file['tmp_name']);
                $params['file'] = $file;
                $params['list_files'] = [];
            }
        }

        $this->setParams($params);
        $this->appendInternalParams();
        $this->reset();
        return $this;
    }

    public function isValid(): bool
    {
        $this->lastErrorMessage = null;

        // The file may not be uploaded (or return false directly).
        $url = $this->getParam('url');
        $filepath = $this->getParam('filename');

        $url = trim((string) $url);
        if (!empty($url)) {
            $this->listFiles = [$url];
        } elseif ($filepath) {
            $this->listFiles = [$filepath];
            $file = $this->getParam('file') ?: [];
            // Early check for a single uploaded file.
            $this->currentFilepath = $filepath;
            return $this->isValidFilepath($filepath, $file)
                && $this->isValidMore();
        } else {
            $this->listFiles = $this->getParam('list_files') ?: [];
        }

        foreach ($this->listFiles as $fileUrl) {
            if ($this->isRemote($fileUrl)) {
                if (!$this->isValidUrl($fileUrl)) {
                    return false;
                }
                $filename = $this->fetchUrlToTempFile($fileUrl);
                if (!$filename) {
                    $this->lastErrorMessage = new PsrMessage(
                        'Url "{url}" is invalid, empty or unavailable.', // @translate
                        ['url' => $url]
                    );
                    return false;
                }
                $this->params['filename'] = $filename;
                if (!$this->isValidFilepath($filename)) {
                    return false;
                }
            } else {
                $this->params['filename'] = $fileUrl;
                if (!$this->isValidFilepath($fileUrl)) {
                    return false;
                }
            }
            $this->currentFilepath = $this->params['filename'];
            if (!$this->isValidMore()) {
                return false;
            }
        }

        return true;
    }

    protected function isValidMore(): bool
    {
        return true;
    }
}
