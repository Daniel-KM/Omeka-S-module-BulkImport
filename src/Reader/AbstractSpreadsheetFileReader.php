<?php declare(strict_types=1);
namespace BulkImport\Reader;

use BulkImport\Entry\SpreadsheetEntry;

abstract class AbstractSpreadsheetFileReader extends AbstractFileReader
{
    public function current()
    {
        $this->isReady();
        /** @var \Box\Spout\Common\Entity\Row */
        $this->currentData = $this->iterator->current();
        if (is_null($this->currentData)) {
            return null;
        }
        if (!is_array($this->currentData)) {
            $this->currentData = $this->currentData->toArray();
        }
        return $this->currentEntry();
    }

    protected function currentEntry()
    {
        // TODO Use the Box Spout Row as current data? Probably useless.
        return new SpreadsheetEntry($this->availableFields, $this->currentData, $this->getParams());
    }
}
