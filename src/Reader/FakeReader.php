<?php declare(strict_types=1);

namespace BulkImport\Reader;

class FakeReader extends AbstractReader
{
    protected $label = 'Fake reader'; // @translate
    protected $configFormClass = null;
    protected $paramsFormClass = null;

    public function getAvailableFields(): array
    {
        return [];
    }

    public function isReady(): bool
    {
        return true;
    }

    public function count(): int
    {
        return 0;
    }

    public function current()
    {
        return null;
    }

    public function key()
    {
        return null;
    }

    public function next(): void
    {
    }

    public function valid(): bool
    {
        return false;
    }

    public function rewind(): void
    {
    }

    protected function reset(): \BulkImport\Reader\Reader
    {
        return $this;
    }

    protected function prepareIterator(): \BulkImport\Reader\Reader
    {
        return $this;
    }
}
