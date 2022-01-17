<?php declare(strict_types=1);

namespace BulkImport\Reader;

use Box\Spout\Common\Type;
use Box\Spout\Reader\Common\Creator\ReaderEntityFactory;
use Box\Spout\Reader\ReaderInterface;
use BulkImport\Entry\Entry;
use BulkImport\Entry\SpreadsheetEntry;
use BulkImport\Form\Reader\OpenDocumentSpreadsheetReaderParamsForm;
use BulkImport\Form\Reader\SpreadsheetReaderConfigForm;
use Log\Stdlib\PsrMessage;

class OpenDocumentSpreadsheetReader extends AbstractSpreadsheetFileReader
{
    protected $label = 'OpenDocument Spreadsheet'; // @translate
    protected $mediaType = 'application/vnd.oasis.opendocument.spreadsheet';
    protected $configFormClass = SpreadsheetReaderConfigForm::class;
    protected $paramsFormClass = OpenDocumentSpreadsheetReaderParamsForm::class;

    protected $configKeys = [
        'separator',
    ];

    protected $paramsKeys = [
        'filename',
        'multisheet',
        'separator',
    ];

    /**
     * @var bool
     */
    protected $isMultiSheet = false;

    /**
     * @var \Box\Spout\Reader\IteratorInterface
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
     * @var \Box\Spout\Reader\ODS\Reader
     */
    protected $iterator;

    /**
     * Type of spreadsheet.
     *
     * @var string
     */
    protected $spreadsheetType = Type::ODS;

    /**
     * @var bool
     */
    protected $isOldBoxSpout = false;

    /**
     * @var ReaderInterface
     */
    protected $spreadsheetReader;

    public function isValid(): bool
    {
        if (!extension_loaded('zip') || !extension_loaded('xml')) {
            $this->lastErrorMessage = new PsrMessage(
                'To process import of "{label}", the php extensions "zip" and "xml" are required.', // @translate
                ['label' => $this->getLabel()]
            );
            return false;
        }
        return parent::isValid();
    }

    protected function currentEntry(): Entry
    {
        return new SpreadsheetEntry(
            $this->currentData,
            $this->processAllSheets
                ? $this->availableFieldsMultiSheets[$this->sheetIndex]
                : $this->availableFields,
            $this->getParams()
        );
    }

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
            /** @var \Box\Spout\Reader\SheetInterface $sheet */
            $sheet = $this->sheetIterator->current();
            if (!$this->isOldBoxSpout && !$sheet->isVisible()) {
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

    protected function reset(): \BulkImport\Reader\Reader
    {
        parent::reset();
        if ($this->spreadsheetReader) {
            $this->spreadsheetReader->close();
        }
        return $this;
    }

    protected function prepareIterator(): \BulkImport\Reader\Reader
    {
        parent::prepareIterator();
        // Skip headers, already stored.
        $this->next();
        return $this;
    }

    protected function initializeReader(): \BulkImport\Reader\Reader
    {
        if ($this->spreadsheetReader) {
            $this->spreadsheetReader->close();
        }

        // TODO Remove when patch https://github.com/omeka-s-modules/CSVImport/pull/182 will be included.
        // Manage compatibility with old version of CSV Import.
        // For now, it should be first checked.
        if (class_exists(\Box\Spout\Reader\ReaderFactory::class)) {
            $this->spreadsheetReader = \Box\Spout\Reader\ReaderFactory::create($this->spreadsheetType);
            $this->isOldBoxSpout = true;
        } elseif (class_exists(ReaderEntityFactory::class)) {
            $this->spreadsheetReader = ReaderEntityFactory::createODSReader();
            // Important, else next rows will be skipped.
            // Nevertheless, the count should remove all last empty rows.
            $this->spreadsheetReader->setShouldPreserveEmptyRows(true);
        } else {
            throw new \Omeka\Service\Exception\RuntimeException(
                (string) new PsrMessage(
                    'The library to manage OpenDocument spreadsheet is not available.' // @translate
                )
            );
        }

        $filepath = $this->getParam('filename');
        try {
            $this->spreadsheetReader->open($filepath);
        } catch (\Box\Spout\Common\Exception\IOException $e) {
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
        $multisheet = $this->getParam('multisheet', 'active');
        $this->processAllSheets = $multisheet === 'all';
        if ($multisheet === 'active') {
            /** @var \Box\Spout\Reader\ODS\Sheet $currentSheet */
            foreach ($this->sheetIterator as $currentSheet) {
                if ($currentSheet->isActive() && ($this->isOldBoxSpout || $currentSheet->isVisible())) {
                    $sheet = $currentSheet;
                    break;
                }
            }
        } elseif ($multisheet === 'first') {
            /** @var \Box\Spout\Reader\ODS\Sheet $currentSheet */
            foreach ($this->sheetIterator as $currentSheet) {
                if ($this->isOldBoxSpout || $currentSheet->isVisible()) {
                    $sheet = $currentSheet;
                    break;
                }
            }
        } else {
            // Multisheet.
            if (is_null($this->sheetIndex)) {
                /** @var \Box\Spout\Reader\ODS\Sheet $currentSheet */
                foreach ($this->sheetIterator as $currentSheet) {
                    if ($this->isOldBoxSpout || $currentSheet->isVisible()) {
                        $sheet = $currentSheet;
                        break;
                    }
                }
            } else {
                // Set the current sheet when iterating all sheets.
                /** @var \Box\Spout\Reader\ODS\Sheet $currentSheet */
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

    protected function finalizePrepareIterator(): \BulkImport\Reader\Reader
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

    protected function finalizePrepareIteratorMultiSheets(): \BulkImport\Reader\Reader
    {
        $this->totalEntries = 0;
        $this->sheetsRowCount = [];
        /** @var \Box\Spout\Reader\ODS\Sheet $sheet */
        foreach ($this->sheetIterator as $sheet) {
            if (!$this->isOldBoxSpout && !$sheet->isVisible()) {
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

        // $this->totalEntries = iterator_count($this->iterator) - 1;
        $total = 0;
        // TODO Why in some cases, the index starts from 0 or 1?
        $firstIndexIsOneBased = null;
        foreach ($rowIterator as $index => $row) {
            if (is_null($firstIndexIsOneBased)) {
                $firstIndexIsOneBased = (int) empty($index);
            }
            $data = array_filter($row->getCells(), function (\Box\Spout\Common\Entity\Cell $cell) {
                return $cell->getValue() !== '';
            });
            if (count($data)) {
                // Index is 0-based, but the header should be included.
                $total = $index + $firstIndexIsOneBased;
            }
        }
        return $total;
    }

    protected function prepareAvailableFields(): \BulkImport\Reader\Reader
    {
        if ($this->isOldBoxSpout) {
            $this->prepareAvailableFieldsOld();
            return $this;
        }

        if ($this->processAllSheets) {
            return $this->prepareAvailableFieldsMultiSheets();
        }

        /** @var \Box\Spout\Common\Entity\Row $row */
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

    protected function prepareAvailableFieldsMultiSheets(): \BulkImport\Reader\Reader
    {
        $this->availableFields = [];
        $this->availableFieldsMultiSheets = [];
        /** @var \Box\Spout\Reader\ODS\Sheet $sheet */
        foreach ($this->sheetIterator as $sheet) {
            if (!$this->isOldBoxSpout && !$sheet->isVisible()) {
                continue;
            }
            /** @var \Box\Spout\Common\Entity\Row $row */
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
        $this->availableFields = array_values(array_unique(array_merge(...$this->availableFieldsMultiSheets)));
        $this->initializeReader();
        return $this;
    }

    /**
     * @todo Remove support of old CSV Import when patch https://github.com/omeka-s-modules/CSVImport/pull/182 will be included.
     */
    protected function prepareAvailableFieldsOld(): \BulkImport\Reader\Reader
    {
        if ($this->processAllSheets) {
            return $this->prepareAvailableFieldsMultiSheetsOld();
        }

        /** @var \Box\Spout\Common\Entity\Row $fields */
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
    protected function prepareAvailableFieldsMultiSheetsOld(): \BulkImport\Reader\Reader
    {
        $this->availableFields = [];
        $this->availableFieldsMultiSheets = [];
        /** @var \Box\Spout\Reader\ODS\Sheet $sheet */
        foreach ($this->sheetIterator as $sheet) {
            if (!$this->isOldBoxSpout && !$sheet->isVisible()) {
                continue;
            }

            /** @var \Box\Spout\Common\Entity\Row $fields */
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
        $this->availableFields = array_values(array_unique(array_merge(...$this->availableFieldsMultiSheets)));
        $this->initializeReader();
        return $this;
    }
}
