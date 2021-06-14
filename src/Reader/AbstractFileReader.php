<?php declare(strict_types=1);

namespace BulkImport\Reader;

use BulkImport\Entry\Entry;
use Iterator;
use Laminas\Form\Form;
use Log\Stdlib\PsrMessage;

abstract class AbstractFileReader extends AbstractReader
{
    /**
     * @var string
     */
    protected $mediaType;

    /**
     * @var \Iterator
     */
    protected $iterator;

    /**
     * @var int
     */
    protected $totalEntries;

    /**
     * @var array
     */
    protected $currentData = [];

    /**
     * @var bool
     */
    protected $isUrl = false;

    public function isValid(): bool
    {
        $this->lastErrorMessage = null;

        $url = $this->getParam('url');
        $url = trim((string) $url);
        $this->isUrl = !empty($url);

        if ($this->isUrl) {
            if (!$this->isValidUrl($url)) {
                return false;
            }

            $filename = $this->fetchUrlToTempFile($url);
            if (!$filename) {
                $this->lastErrorMessage = new PsrMessage(
                    'Url "{url}" is invalid or empty or unavailable.', // @translate
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

    public function handleParamsForm(Form $form)
    {
        $this->lastErrorMessage = null;
        $values = $form->getData();
        $params = array_intersect_key($values, array_flip($this->paramsKeys));

        // TODO Store fetched url between form step.
        if (array_search('url', $this->paramsKeys) !== false) {
            $url = $form->get('url')->getValue();
            $url = trim($url);
            $this->isUrl = !empty($url);
        }

        if ($this->isUrl) {
            $filename = $this->fetchUrlToTempFile($url);
            $params ['filename'] = $filename;
            unset($params['file']);
        } else {
            $file = $this->getUploadedFile($form);
            $params['filename'] = $file['filename'];
            // Remove temp names for security purpose.
            unset($file['filename']);
            unset($file['tmp_name']);
            $params['file'] = $file;
        }

        $this->setParams($params);
        $this->appendInternalParams();
        $this->reset();
        return $this;
    }

    public function current()
    {
        $this->isReady();
        $this->currentData = $this->iterator->current();
        return is_array($this->currentData)
            ? $this->currentEntry()
            : null;
    }

    /**
     * Helper to manage the current entry.
     *
     * May be overridden with a different entry sub-class.
     *
     * @return \BulkImport\Entry\Entry
     */
    protected function currentEntry()
    {
        return new Entry($this->currentData, $this->availableFields, $this->getParams());
    }

    public function key()
    {
        $this->isReady();
        return $this->iterator->key();
    }

    public function next(): void
    {
        $this->isReady();
        $this->iterator->next();
    }

    public function rewind(): void
    {
        $this->isReady();
        $this->iterator->rewind();
    }

    public function valid(): bool
    {
        $this->isReady();
        return $this->iterator->valid();
    }

    public function count(): int
    {
        $this->isReady();
        return $this->totalEntries;
    }

    /**
     * Reset the iterator to allow to use it with different params.
     */
    protected function reset(): \BulkImport\Interfaces\Reader
    {
        parent::reset();
        $this->iterator = null;
        $this->totalEntries = null;
        $this->currentData = [];
        return $this;
    }

    /**
     * @throws \Omeka\Service\Exception\RuntimeException
     */
    protected function prepareIterator(): \BulkImport\Interfaces\Reader
    {
        $this->reset();
        if (!$this->isValid()) {
            throw new \Omeka\Service\Exception\RuntimeException((string) $this->getLastErrorMessage());
        }

        $this->initializeReader();

        $this->finalizePrepareIterator();
        $this->prepareAvailableFields();

        $this->isReady = true;
        return $this;
    }

    /**
     * Initialize the reader iterator.
     */
    abstract protected function initializeReader(): \BulkImport\Interfaces\Reader;

    /**
     * Called only by prepareIterator() after opening reader.
     */
    protected function finalizePrepareIterator(): \BulkImport\Interfaces\Reader
    {
        $this->totalEntries = iterator_count($this->iterator);
        $this->iterator->rewind();
        return $this;
    }

    /**
     * The fields are an array.
     */
    protected function prepareAvailableFields(): \BulkImport\Interfaces\Reader
    {
        return $this;
    }

    /**
     * @todo Use the upload mechanism / temp file of Omeka.
     *
     * @param Form $form
     * @throws \Omeka\Service\Exception\RuntimeException
     * @return array The file array with the temp filename.
     */
    protected function getUploadedFile(Form $form): array
    {
        $file = $form->get('file')->getValue();
        if (empty($file)) {
            throw new \Omeka\Service\Exception\RuntimeException(
                'Unable to upload file.' // @translate
            );
        }

        $systemConfig = $this->getServiceLocator()->get('Config');
        $tempDir = $systemConfig['temp_dir'] ?? null;
        if (!$tempDir) {
            throw new \Omeka\Service\Exception\RuntimeException(
                'The "temp_dir" is not configured' // @translate
            );
        }

        $filename = tempnam($tempDir, 'omk_');
        if (!move_uploaded_file($file['tmp_name'], $filename)) {
            throw new \Omeka\Service\Exception\RuntimeException(
                (string) new PsrMessage(
                    'Unable to move uploaded file to {filename}', // @translate
                    ['filename' => $filename]
                )
            );
        }
        $file['filename'] = $filename;
        return $file;
    }

    /**
     * @todo Merge with FetchFileTrait::fetchUrl().
     */
    protected function fetchUrlToTempFile(string $url): ?string
    {
        $services = $this->getServiceLocator();
        $config = $services->get('Config');
        $tempPath = $config['temp_dir'] ?: sys_get_temp_dir();

        $tempname = tempnam($tempPath, 'omkbulk_');

        // @see https://stackoverflow.com/questions/724391/saving-image-from-php-url
        // Curl is faster than copy or file_get_contents/file_put_contents.
        // $result = copy($url, $tempname);
        // $result = file_put_contents($tempname, file_get_contents($url), \LOCK_EX);
        $ch = curl_init($url);
        $fp = fopen($tempname, 'wb');
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_exec($ch);
        curl_close($ch);
        fclose($fp);

        if (!filesize($tempname)) {
            return null;
        }

        return $tempname;
    }

    /**
     * @param string $filepath The full and real filepath.
     * @param array $file Data of the file info (original name, type). If data
     * are not present, checks may be skipped.
     * @return bool
     */
    protected function isValidFilepath($filepath, array $file = []): bool
    {
        $file += ['name' => '[unknown]', 'type' => null];

        if (empty($filepath)) {
            $this->lastErrorMessage = new PsrMessage(
                'File "{filename}" doesn’t exist.', // @translate
                ['filename' => $file['name']]
            );
            return false;
        }
        if (!filesize($filepath)) {
            $this->lastErrorMessage = new PsrMessage(
                'File "{filename}" is empty.', // @translate
                ['filename' => $file['name']]
            );
            return false;
        }
        if (!is_readable($filepath)) {
            $this->lastErrorMessage = new PsrMessage(
                'File "{filename}" is not readable.', // @translate
                ['filename' => $file['name']]
            );
            return false;
        }

        if (is_array($this->mediaType)) {
            if (!in_array($file['type'], $this->mediaType)) {
                $this->lastErrorMessage = new PsrMessage(
                    'File "{filename}" has media type "{file_media_type}" and is not managed.', // @translate
                    ['filename' => $file['name'], 'file_media_type' => $file['type']]
                );
                return false;
            }
        } elseif ($file['type'] && $file['type'] !== $this->mediaType) {
            $this->lastErrorMessage = new PsrMessage(
                'File "{filename}" has media type "{file_media_type}", not "{media_type}".', // @translate
                ['filename' => $file['name'], 'file_media_type' => $file['type'], 'media_type' => $this->mediaType]
            );
            return false;
        }
        return true;
    }

    protected function isValidUrl(string $url): bool
    {
        if (empty($url)) {
            $this->lastErrorMessage = new PsrMessage(
                'Url is empty.' // @translate
            );
            return false;
        }

        // Remove all illegal characters from a url
        $sanitizedUrl = filter_var($url, FILTER_SANITIZE_URL);
        if ($sanitizedUrl !== $url) {
            $this->lastErrorMessage = new PsrMessage(
                'Url should not contain illegal characters.' // @translate
            );
            return false;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    protected function cleanData(array $data): array
    {
        return array_map([$this, 'trimUnicode'], $data);
    }

    /**
     * Trim all whitespace, included the unicode ones.
     *
     * @param string $string
     * @return string
     */
    protected function trimUnicode($string): string
    {
        return preg_replace('/^[\h\v\s[:blank:][:space:]]+|[\h\v\s[:blank:][:space:]]+$/u', '', (string) $string);
    }
}
