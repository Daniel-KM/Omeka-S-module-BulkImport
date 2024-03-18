<?php declare(strict_types=1);

namespace BulkImport\Reader;

use Laminas\Form\Form;
use Common\Stdlib\PsrMessage;
use SplFileObject;

/**
 * @todo Replace all abstract reader by a single IteratorIterator and prepare data separately.
 * @todo Extend AbstractFileReader from AbstractFileMultipleReader.
 */
abstract class AbstractFileReader extends AbstractReader
{
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
            $filename = $this->bulkFile->fetchUrlToTempFile($url);
            $params ['filename'] = $filename;
            unset($params['file']);
        } else {
            $file = $this->bulkFile->getUploadedFile($form);
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
            if (!$this->bulkFile->isValidUrl($url, $this->lastErrorMessage)) {
                return parent::isValid();
            }

            $filename = $this->bulkFile->fetchUrlToTempFile($url);
            if (!$filename) {
                $this->lastErrorMessage = new PsrMessage(
                    'Url "{url}" is invalid, empty or unavailable.', // @translate
                    ['url' => $url]
                );
                return parent::isValid();
            }

            $this->params['filename'] = $filename;
            if (!$this->bulkFile->isValidFilepath($filename, [], null, $this->lastErrorMessage)) {
                return parent::isValid();
            }
            return parent::isValid();
        }

        if (array_search('filename', $this->paramsKeys) === false) {
            return parent::isValid();
        }

        // The file may not be uploaded (or return false directly).
        $file = $this->getParam('file') ?: [];
        $filepath = $this->getParam('filename');

        if (!$file && !$filepath) {
            return parent::isValid();
        } elseif (!$filepath) {
            // The error occurs when the form is reloaded.
            $this->lastErrorMessage = new PsrMessage(
                'There is a file "{filename}", but no filepath. Check if you reloaded the form.', // @translate
                ['filename' => $file['name']]
            );
            return parent::isValid();
        }

        // Fix issues with tsv/csv.
        if (method_exists($this, 'checkFileArray')) {
            $file = $this->checkFileArray($file);
        }

        // Check utf-8 (useless for ods).
        if (method_exists($this, 'isUtf8') && !$this->isUtf8($filepath)) {
            $this->lastErrorMessage = new PsrMessage(
                'File "{filename}" is not fully utf-8.', // @translate
                ['filename' => $file['name']]
            );
            return parent::isValid();
        }

        if (!$this->bulkFile->isValidFilepath($filepath, $file, $this->mediaType ?? null, $this->lastErrorMessage)) {
            return parent::isValid();
        }

        return parent::isValid();
    }
}
