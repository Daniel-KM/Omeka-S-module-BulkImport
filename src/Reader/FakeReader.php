<?php declare(strict_types=1);

namespace BulkImport\Reader;

use BulkImport\Entry\BaseEntry;
use BulkImport\Entry\Entry;

class FakeReader extends AbstractReader
{
    protected $label = 'Fake reader'; // @translate
    protected $configFormClass = null;
    protected $paramsFormClass = null;

    public function getAvailableFields(): array
    {
        return [];
    }

    public function count(): int
    {
        return 0;
    }

    protected function currentEntry(): Entry
    {
        return new BaseEntry([], $this->key(), []);
    }

    protected function isReady(): bool
    {
        return true;
    }

    protected function reset(): \BulkImport\Reader\Reader
    {
        return $this;
    }

    protected function initializeReader(): \BulkImport\Reader\Reader
    {
        $this->iterator = new \ArrayIterator([]);
        return $this;
    }

    protected function prepareIterator(): \BulkImport\Reader\Reader
    {
        $this->totalEntries = 0;
        return $this;
    }
}
