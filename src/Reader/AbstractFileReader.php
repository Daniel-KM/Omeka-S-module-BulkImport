<?php declare(strict_types=1);

namespace BulkImport\Reader;

use BulkImport\Entry\BaseEntry;
use BulkImport\Entry\Entry;
use Iterator;
use Laminas\Form\Form;
use Log\Stdlib\PsrMessage;

/**
 * @todo Replace all abstract reader by a single IteratorIterator and prepare data separately.
 */
abstract class AbstractFileReader extends AbstractReader
{
    use FileAndUrlTrait;

    /**
     * @var \Iterator
     */
    protected $iterator;

    /**
     * For spreadsheets, the total entries should not include headers.
     *
     * @var int
     */
    protected $totalEntries;

    /**
     * @var array
     */
    protected $currentData = [];

    public function handleParamsForm(Form $form)
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

    #[\ReturnTypeWillChange]
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
     */
    protected function currentEntry(): Entry
    {
        return new BaseEntry($this->currentData, $this->key(), $this->availableFields, $this->getParams());
    }

    #[\ReturnTypeWillChange]
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
    protected function reset(): \BulkImport\Reader\Reader
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
    protected function prepareIterator(): \BulkImport\Reader\Reader
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
    abstract protected function initializeReader(): \BulkImport\Reader\Reader;

    /**
     * Called only by prepareIterator() after opening reader.
     */
    protected function finalizePrepareIterator(): \BulkImport\Reader\Reader
    {
        $this->totalEntries = iterator_count($this->iterator);
        $this->iterator->rewind();
        return $this;
    }

    /**
     * The fields are an array.
     */
    protected function prepareAvailableFields(): \BulkImport\Reader\Reader
    {
        return $this;
    }
}
