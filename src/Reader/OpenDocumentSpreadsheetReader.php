<?php declare(strict_types=1);

namespace BulkImport\Reader;

use BulkImport\Entry\Entry;
use BulkImport\Entry\SpreadsheetEntry;
use BulkImport\Form\Reader\OpenDocumentSpreadsheetReaderConfigForm;
use BulkImport\Form\Reader\OpenDocumentSpreadsheetReaderParamsForm;
use Log\Stdlib\PsrMessage;
use OpenSpout\Common\Type;
use OpenSpout\Reader\Common\Creator\ReaderEntityFactory;
use OpenSpout\Reader\ReaderInterface;

class OpenDocumentSpreadsheetReader extends AbstractSpreadsheetFileReader
{
    protected $label = 'OpenDocument Spreadsheet'; // @translate
    protected $mediaType = 'application/vnd.oasis.opendocument.spreadsheet';
    protected $configFormClass = OpenDocumentSpreadsheetReaderConfigForm::class;
    protected $paramsFormClass = OpenDocumentSpreadsheetReaderParamsForm::class;
    protected $entryClass = SpreadsheetEntry::class;

    protected $configKeys = [
        'url',
        'multisheet',
        'separator',
    ];

    protected $paramsKeys = [
        'filename',
        'url',
        'multisheet',
        'separator',
    ];

    /**
     * @var bool
     */
    protected $processAllSheets = false;

    /**
     * @var \OpenSpout\Reader\IteratorInterface
     */
    protected $sheetIterator;

    /**
     * @var ?int
     */
    protected $sheetIndex = null;

    /**
     * @var ?string
     */
    protected $sheetName = null;

    /**
     * @var array
     */
    protected $availableFieldsMultiSheets = [];

    /**
     * Total of rows of each sheet, excluding headers.
     *
     * @var array
     */
    protected $sheetsRowCount = [];

    /**
     * @var \OpenSpout\Reader\ODS\Reader
     */
    protected $iterator;

    /**
     * Type of spreadsheet.
     *
     * @var string
     */
    protected $spreadsheetType = Type::ODS;

    /**
     * @var ReaderInterface
     */
    protected $spreadsheetReader;

    public function isValid(): bool
    {
        if (!extension_loaded('zip')) {
            $this->lastErrorMessage = new PsrMessage(
                'To process import of "{label}", the php extension "{extension}" is required.', // @translate
                ['label' => $this->getLabel(), 'extension' => 'zip']
            );
        }
        if (!extension_loaded('xml')) {
            $this->lastErrorMessage = new PsrMessage(
                'To process import of "{label}", the php extension "{extension}" is required.', // @translate
                ['label' => $this->getLabel(), 'extension' => 'xml']
            );
        }
        if (!extension_loaded('zip') || !extension_loaded('xml')) {
            return false;
        }
        return parent::isValid();
    }

    protected function currentEntry(): Entry
    {
        return new SpreadsheetEntry(
            $this->currentData,
            $this->key(),
            $this->processAllSheets
                ? $this->availableFieldsMultiSheets[$this->sheetIndex]
                : $this->availableFields,
            $this->getParams() + ['metaMapper' => $this->metaMapper]
        );
    }

    #[\ReturnTypeWillChange]
    public function key()
    {
        // The first row should be numbered 0 for the data, not the headers.
        // TODO Check key() for multisheets.
        return parent::key() - 1;
    }

    /**
     * Spout Reader doesn't support rewind for xml (doesSupportStreamWrapper()),
     * so the iterator should be reinitialized.
     *
     * Reader use a foreach loop to get data. So the first output should not be
     * the available fields, but the data (numbered as 0-based).
     *
     * {@inheritDoc}
     * @see \BulkImport\Reader\AbstractReader::rewind()
     */
    public function rewind(): void
    {
        // Disable check isReady().
        // $this->isReady();
        $this->sheetIndex = null;
        $this->sheetName = null;
        $this->initializeReader();
        $this->next();
        // TODO Why two next() here, not in csv (init and headers)?
        $this->next();
    }

    public function valid(): bool
    {
        $this->isReady();

        // In some cases (false empty rows), the iterator doesn't stop until
        // last row of the spreadsheet, so check the number of processed rows.
        $sheet = $this->sheetIterator->current();
        // There should be at least one filled row, excluding the header.
        $index = $sheet->getIndex();
        if (empty($this->sheetsRowCount[$index])
            || ($this->processAllSheets && empty($this->availableFieldsMultiSheets[$index]))
        ) {
            return false;
        }

        // The check is done with the zero-based key.
        if ($this->iterator->key() - 1 > $this->sheetsRowCount[$index]) {
            if (!$this->processAllSheets) {
                return false;
            }
        }

        // Check if current row is really valid.
        if ($this->iterator->valid()) {
            return true;
        }

        // Don't check all sheets if processing only one sheet.
        // So return false because the iterator above should be valid.
        // Or compare the current count.
        if (!$this->processAllSheets) {
            return false;
        }

        // When the row is false (invalid), prepare next sheet until last one.
        $this->sheetIndex = null;
        $this->sheetName = null;
        do {
            $this->sheetIterator->next();
            if (!$this->sheetIterator->valid()) {
                return false;
            }
            /** @var \OpenSpout\Reader\SheetInterface $sheet */
            $sheet = $this->sheetIterator->current();
            if (!$sheet->isVisible()) {
                continue;
            }

            // There should be at least one filled row, excluding the header.
            $index = $sheet->getIndex();
            if (empty($this->availableFieldsMultiSheets[$index])
                || empty($this->sheetsRowCount[$index])
            ) {
                continue;
            }
            break;
        } while (true);

        $this->sheetIndex = $sheet->getIndex();
        $this->sheetName = $sheet->getName();

        // Reset the iterator.
        $this->prepareIterator();

        return true;
    }

    /**
     * Get the current sheet index (0-based).
     */
    public function currentSheetIndex(): ?int
    {
        if (!$this->valid()) {
            $this->sheetIndex = null;
            $this->sheetName = null;
        } elseif (is_null($this->sheetIndex)) {
            $sheet = $this->sheetIterator->current();
            $this->sheetIndex = $sheet->getIndex();
            $this->sheetName = $sheet->getName();
        }
        return $this->sheetIndex;
    }

    /**
     * Get the current sheet name.
     */
    public function currentSheetName(): ?string
    {
        if (!$this->valid()) {
            $this->sheetIndex = null;
            $this->sheetName = null;
        } elseif (is_null($this->sheetIndex)) {
            $sheet = $this->sheetIterator->current();
            $this->sheetIndex = $sheet->getIndex();
            $this->sheetName = $sheet->getName();
        }
        return $this->sheetName;
    }

    /**
     * Get current sheet row count, header excluded.
     */
    public function currentSheetRowCount(): ?int
    {
        if (!$this->valid()) {
            return null;
        } elseif (is_null($this->sheetIndex) || !isset($this->sheetsRowCount[$this->sheetIndex])) {
            $sheet = $this->sheetIterator->current();
            $this->sheetIndex = $sheet->getIndex();
            $this->sheetName = $sheet->getName();
            $total = $this->countSheetRows($sheet->getRowIterator());
            $this->sheetsRowCount[$this->sheetIndex] = max(0, $total - 1);
        }
        return $this->sheetsRowCount[$this->sheetIndex];
    }

    protected function reset(): self
    {
        parent::reset();
        if ($this->spreadsheetReader) {
            $this->spreadsheetReader->close();
        }
        return $this;
    }

    protected function prepareIterator(): self
    {
        parent::prepareIterator();
        // Skip headers, already stored.
        $this->next();
        return $this;
    }

    protected function initializeReader(): self
    {
        if ($this->spreadsheetReader) {
            $this->spreadsheetReader->close();
        }

        $this->spreadsheetReader = ReaderEntityFactory::createODSReader();
        // Important, else next rows will be skipped.
        // Nevertheless, the count should remove all last empty rows.
        $this->spreadsheetReader->setShouldPreserveEmptyRows(true);

        $filepath = $this->getParam('filename');
        try {
            $this->spreadsheetReader->open($filepath);
        } catch (\OpenSpout\Common\Exception\IOException $e) {
            throw new \Omeka\Service\Exception\RuntimeException(
                (string) new PsrMessage(
                    'File "{filename}" cannot be open.', // @translate
                    ['filename' => $filepath]
                )
            );
        }

        $this->spreadsheetReader
            // ->setTempFolder($this->getServiceLocator()->get('Config')['temp_dir'])
            // Read the dates as text. See fix #179 in CSVImport.
            // TODO Read the good format in spreadsheet entry.
            ->setShouldFormatDates(true);

        // Initialize first sheet and sheet iterator.
        $this->sheetIterator = $this->spreadsheetReader->getSheetIterator();
        $this->sheetIterator->rewind();
        $sheet = null;
        $processMultisheet = $this->getParam('multisheet', 'active');
        $this->processAllSheets = $processMultisheet === 'all';
        if ($processMultisheet === 'active') {
            /** @var \OpenSpout\Reader\ODS\Sheet $currentSheet */
            foreach ($this->sheetIterator as $currentSheet) {
                if ($currentSheet->isActive() && $currentSheet->isVisible()) {
                    $sheet = $currentSheet;
                    break;
                }
            }
        } elseif ($processMultisheet === 'first') {
            /** @var \OpenSpout\Reader\ODS\Sheet $currentSheet */
            foreach ($this->sheetIterator as $currentSheet) {
                if ($currentSheet->isVisible()) {
                    $sheet = $currentSheet;
                    break;
                }
            }
        } else {
            // Multisheet.
            if (is_null($this->sheetIndex)) {
                /** @var \OpenSpout\Reader\ODS\Sheet $currentSheet */
                foreach ($this->sheetIterator as $currentSheet) {
                    if ($currentSheet->isVisible()) {
                        $sheet = $currentSheet;
                        break;
                    }
                }
            } else {
                // Set the current sheet when iterating all sheets.
                /** @var \OpenSpout\Reader\ODS\Sheet $currentSheet */
                foreach ($this->sheetIterator as $currentSheet) {
                    if ($currentSheet->getIndex() === $this->sheetIndex) {
                        $sheet = $currentSheet;
                        break;
                    }
                }
            }
        }
        if (empty($sheet)) {
            $this->lastErrorMessage = 'No sheet.'; // @translate
            throw new \Omeka\Service\Exception\RuntimeException((string) $this->getLastErrorMessage());
        }

        $this->iterator = $sheet->getRowIterator();

        $this->sheetIndex = $sheet->getIndex();
        $this->sheetName = $sheet->getName();

        return $this;
    }

    protected function finalizePrepareIterator(): self
    {
        if ($this->processAllSheets) {
            return $this->finalizePrepareIteratorMultiSheets();
        }

        // Don't count the header.
        $total = $this->countSheetRows($this->iterator);
        $this->totalEntries = max(0, $total - 1);
        $this->sheetsRowCount[$this->sheetIndex] = $this->totalEntries;

        $this->initializeReader();
        return $this;
    }

    protected function finalizePrepareIteratorMultiSheets(): self
    {
        $this->totalEntries = 0;
        $this->sheetsRowCount = [];
        /** @var \OpenSpout\Reader\ODS\Sheet $sheet */
        foreach ($this->sheetIterator as $sheet) {
            if (!$sheet->isVisible()) {
                continue;
            }
            // Don't count the header.
            $total = $this->countSheetRows($sheet->getRowIterator());
            $total = max(0, $total - 1);
            $this->sheetsRowCount[$sheet->getIndex()] = $total;
            $this->totalEntries += $total;
        }

        $this->initializeReader();
        return $this;
    }

    /**
     * Count rows of a sheet, skipping all last empty rows but headers included.
     *
     * Blank rows before a filled row are included.
     */
    protected function countSheetRows($rowIterator): int
    {
        // Iterator count cannot be used: empty rows are included since a
        // previous issue.
        // @link https://github.com/omeka-s-modules/CSVImport/pull/190
        // So simply remove all empty last empty rows.
        // Do it manually, because row method "isEmptyRow()" is not public.
        /** @see \OpenSpout\Reader\ODS\RowIterator::isEmptyRow() */

        // $this->totalEntries = iterator_count($this->iterator) - 1;
        $total = 0;
        // TODO Why in some cases, the index starts from 0 or 1?
        $firstIndexIsOneBased = null;
        foreach ($rowIterator as $index => $row) {
            if (is_null($firstIndexIsOneBased)) {
                $firstIndexIsOneBased = (int) empty($index);
            }
            $data = array_filter($row->getCells(), function (\OpenSpout\Common\Entity\Cell $cell) {
                return $cell->getValue() !== '';
            });
            if (count($data)) {
                // Index is 0-based, but the header should be included.
                $total = $index + $firstIndexIsOneBased;
            }
        }
        return $total;
    }

    protected function prepareAvailableFields(): self
    {
        if ($this->processAllSheets) {
            return $this->prepareAvailableFieldsMultiSheets();
        }

        /** @var \OpenSpout\Common\Entity\Row $row */
        foreach ($this->iterator as $row) {
            break;
        }
        if (!$row) {
            $this->lastErrorMessage = 'File has no available fields.'; // @translate
            throw new \Omeka\Service\Exception\RuntimeException((string) $this->getLastErrorMessage());
        }
        // The data should be cleaned, since it's not an entry.
        $this->availableFields = $this->cleanData($row->toArray());
        $this->initializeReader();
        return $this;
    }

    protected function prepareAvailableFieldsMultiSheets(): self
    {
        $this->availableFields = [];
        $this->availableFieldsMultiSheets = [];
        /** @var \OpenSpout\Reader\ODS\Sheet $sheet */
        foreach ($this->sheetIterator as $sheet) {
            if (!$sheet->isVisible()) {
                continue;
            }
            /** @var \OpenSpout\Common\Entity\Row $row */
            foreach ($sheet->getRowIterator() as $row) {
                break;
            }
            if (!$row) {
                $this->lastErrorMessage = 'File has no available fields.'; // @translate
                throw new \Omeka\Service\Exception\RuntimeException((string) $this->getLastErrorMessage());
            }
            // The data should be cleaned, since it's not an entry.
            $index = $sheet->getIndex();
            $this->availableFieldsMultiSheets[$index] = $this->cleanData($row->toArray());
        }
        $this->availableFields = array_values(array_unique(array_merge(...array_values($this->availableFieldsMultiSheets))));
        $this->initializeReader();
        return $this;
    }

    /**
     * @todo Remove support of old CSV Import when patch https://github.com/omeka-s-modules/CSVImport/pull/182 will be included.
     */
    protected function prepareAvailableFieldsOld(): self
    {
        if ($this->processAllSheets) {
            return $this->prepareAvailableFieldsMultiSheetsOld();
        }

        /** @var \OpenSpout\Common\Entity\Row $fields */
        foreach ($this->iterator as $fields) {
            break;
        }
        if (!is_array($fields)) {
            $this->lastErrorMessage = 'File has no available fields.'; // @translate
            throw new \Omeka\Service\Exception\RuntimeException((string) $this->getLastErrorMessage());
        }
        // The data should be cleaned, since it's not an entry.
        $this->availableFields = $this->cleanData($fields);
        $this->initializeReader();
        return $this;
    }

    /**
     * @todo Remove support of old CSV Import when patch https://github.com/omeka-s-modules/CSVImport/pull/182 will be included.
     */
    protected function prepareAvailableFieldsMultiSheetsOld(): self
    {
        $this->availableFields = [];
        $this->availableFieldsMultiSheets = [];
        /** @var \OpenSpout\Reader\ODS\Sheet $sheet */
        foreach ($this->sheetIterator as $sheet) {
            if (!$sheet->isVisible()) {
                continue;
            }

            /** @var \OpenSpout\Common\Entity\Row $fields */
            foreach ($sheet->getRowIterator() as $fields) {
                break;
            }
            if (!is_array($fields)) {
                $this->lastErrorMessage = 'File has no available fields.'; // @translate
                throw new \Omeka\Service\Exception\RuntimeException((string) $this->getLastErrorMessage());
            }
            // The data should be cleaned, since it's not an entry.
            $this->availableFieldsMultiSheets[$sheet->getIndex()] = $this->cleanData($fields);
        }
        $this->availableFields = array_values(array_unique(array_merge(...array_values($this->availableFieldsMultiSheets))));
        $this->initializeReader();
        return $this;
    }
}
