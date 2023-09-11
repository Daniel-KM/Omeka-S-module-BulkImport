<?php declare(strict_types=1);

namespace BulkImport\Entry;

class JsonEntry extends BaseEntry
{
    /**
     * @var array
     */
    protected $originalData;

    protected function init(): void
    {
        if (!is_array($this->data)) {
            $this->data = [];
        }
    }

    public function isEmpty(): bool
    {
        return $this->data === [];
    }
}
