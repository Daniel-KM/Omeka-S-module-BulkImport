<?php declare(strict_types=1);

namespace BulkImport\Reader;

use Laminas\Form\Form;
use Log\Stdlib\PsrMessage;

/**
 * @todo Replace all abstract reader by a single IteratorIterator and prepare data separately.
 * @todo Extend AbstractFileReader from AbstractFileMultipleReader.
 */
abstract class AbstractFileReader extends AbstractReader
{
    use FileAndUrlTrait;

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
            $params ['filename'] = $filename;
            unset($params['file']);
        } else {
            $file = $this->getUploadedFile($form);
            if (is_null($file)) {
                $params['file'] = null;
            } else {
                $params['filename'] = $file['filename'];
                // Remove temp names for security purpose.
                unset($file['filename']);
                unset($file['tmp_name']);
                $params['file'] = $file;
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

        $url = $this->getParam('url');
        $url = trim((string) $url);
        $isUrl = !empty($url);

        if ($isUrl) {
            if (!$this->isValidUrl($url)) {
                return false;
            }

            $filename = $this->fetchUrlToTempFile($url);
            if (!$filename) {
                $this->lastErrorMessage = new PsrMessage(
                    'Url "{url}" is invalid, empty or unavailable.', // @translate
                    ['url' => $url]
                );
                return false;
            }
            $this->params['filename'] = $filename;
            return $this->isValidFilepath($filename);
        }

        if (array_search('filename', $this->paramsKeys) === false) {
            return true;
        }

        // The file may not be uploaded (or return false directly).
        $file = $this->getParam('file') ?: [];
        $filepath = $this->getParam('filename');
        return $this->isValidFilepath($filepath, $file);
    }
}
