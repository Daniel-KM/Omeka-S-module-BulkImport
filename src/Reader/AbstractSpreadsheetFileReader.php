<?php declare(strict_types=1);
namespace BulkImport\Reader;

use BulkImport\Entry\SpreadsheetEntry;

abstract class AbstractSpreadsheetFileReader extends AbstractFileReader
{
    public function isValid(): bool
    {
        // The version of Box/Spout should be >= 3.0, but there is no version
        // inside the library, so check against a class.
        // This check is needed, because CSV Import still uses version 2.7.
        if (class_exists(\Box\Spout\Reader\ReaderFactory::class)) {
            $this->lastErrorMessage ='The dependency Box/Spout version should be >= 3.0. See readme.'; // @translate
            return false;
        }
        return parent::isValid();
    }

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
