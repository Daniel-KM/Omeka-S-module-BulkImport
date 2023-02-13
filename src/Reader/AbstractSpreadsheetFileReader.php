<?php declare(strict_types=1);

namespace BulkImport\Reader;

use BulkImport\Entry\SpreadsheetEntry;

abstract class AbstractSpreadsheetFileReader extends AbstractFileReader
{
    // TODO Use the OpenSpout Row as current data? Probably useless.
    protected $entryClass = SpreadsheetEntry::class;

    public function current()
    {
        $this->isReady();
        try {
            /** @var \OpenSpout\Common\Entity\Row */
            $this->currentData = $this->iterator->current();
        } catch (\TypeError $e) {
            $this->currentData = null;
            return null;
        }
        if (is_null($this->currentData)) {
            return null;
        }
        if (!is_array($this->currentData)) {
            $this->currentData = $this->currentData->toArray();
        }
        return $this->currentEntry();
    }
}
