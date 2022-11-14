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

    #[\ReturnTypeWillChange]
    public function current()
    {
        return null;
    }

    #[\ReturnTypeWillChange]
    public function key()
    {
        return null;
    }

    public function next(): void
    {
    }

    public function rewind(): void
    {
    }

    public function valid(): bool
    {
        return false;
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
