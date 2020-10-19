<?php declare(strict_types=1);
namespace BulkImport\Reader;

use BulkImport\Entry\SpreadsheetEntry;

abstract class AbstractSpreadsheetFileReader extends AbstractFileReader
{
    protected function currentEntry()
    {
        return new SpreadsheetEntry($this->availableFields, $this->currentData, $this->getParams());
    }
}
