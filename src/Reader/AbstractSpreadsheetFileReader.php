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
        /* @var \OpenSpout\Common\Entity\Row */
        $this->currentData = $this->iterator->current();
        if (is_null($this->currentData)) {
            return null;
        }
        if (!is_array($this->currentData)) {
            $this->currentData = $this->currentData->toArray();
        }
        return $this->currentEntry();
    }
}
