<?php declare(strict_types=1);
namespace BulkImport\Reader;

use BulkImport\Interfaces\Configurable;
use BulkImport\Interfaces\Parametrizable;

abstract class AbstractGenericFileReader extends AbstractFileReader
{
    protected $mediaTypeReaders = [];

    /**
     * @var \BulkImport\Interfaces\Reader
     */
    protected $reader;

    public function isValid(): bool
    {
        $this->lastErrorMessage = null;
        // TODO Currently, the generic reader requires an uploaded file to get the specific reader.
        $file = $this->getParam('file');
        if (!$file) {
            return false;
        }
        if (!parent::isValid()) {
            return false;
        }
        $this->initializeReader();
        $this->isReady = true;
        return $this->reader->isValid();
    }

    public function getLastErrorMessage()
    {
        if ($this->reader && $message = $this->reader->getLastErrorMessage()) {
            return $message;
        }
        return parent::getLastErrorMessage();
    }

    public function getAvailableFields()
    {
        $this->isReady();
        return $this->reader->getAvailableFields();
    }

    public function current()
    {
        $this->isReady();
        return $this->reader->current();
    }

    public function key()
    {
        $this->isReady();
        return $this->reader->key();
    }

    public function next(): void
    {
        $this->isReady();
        $this->reader->next();
    }

    public function rewind(): void
    {
        $this->isReady();
        $this->reader->rewind();
    }

    public function valid()
    {
        $this->isReady();
        return $this->reader->valid();
    }

    public function count(): int
    {
        $this->isReady();
        return $this->reader->count();
    }

    protected function isReady()
    {
        if ($this->isReady) {
            return true;
        }

        $this->prepareIterator();
        return $this->isReady;
    }

    protected function prepareIterator()
    {
        $this->reset();
        if (!$this->isValid()) {
            throw new \Omeka\Service\Exception\RuntimeException($this->getLastErrorMessage());
        }
        $this->initializeReader();
        $this->isReady = true;
        return $this;
    }

    protected function initializeReader()
    {
        $file = $this->getParam('file');
        $readerClass = $this->mediaTypeReaders[$file['type']];
        $this->reader = new $readerClass($this->getServiceLocator());
        if ($this->reader instanceof Configurable) {
            $this->reader->setConfig($this->getConfig());
        }
        if ($this->reader instanceof Parametrizable) {
            $this->reader->setParams($this->getParams());
        }
        return $this;
    }
}
