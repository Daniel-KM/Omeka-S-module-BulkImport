<?php declare(strict_types=1);

namespace BulkImport\Reader;

use Box\Spout\Common\Type;
use Box\Spout\Reader\Common\Creator\ReaderEntityFactory;
use Box\Spout\Reader\ReaderInterface;
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
     * @var array
     */
    protected $availableFieldsMultiSheets = [];

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

    protected function currentEntry()
    {
        return new SpreadsheetEntry(
            $this->currentData,
            $this->isMultiSheet ? $this->availableFieldsMultiSheets[$this->sheetIndex] : $this->availableFields,
            $this->getParams()
        );
    }

    public function key()
    {
        // The first row is the headers, not the data, and it's numbered from 1,
        // not 0.
        return parent::key() - 2;
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
        $this->initializeReader();
        $this->next();
    }

    public function valid(): bool
    {
        $this->isReady();
        if ($this->iterator->valid()) {
            return true;
        }

        if (!$this->isMultiSheet) {
            return false;
        }

        // When the row is false (invalid), prepare next sheet until last one.
        $this->sheetIndex = null;
        do {
            $this->sheetIterator->next();
            if (!$this->sheetIterator->valid()) {
                return false;
            }
            /** @var \\Box\Spout\Reader\SheetInterface $sheet */
            $sheet = $this->sheetIterator->current();
            if (!$this->isOldBoxSpout && !$sheet->isVisible()) {
                continue;
            }
            $rowIterator = $sheet->getRowIterator();
            // There should be at least one row, excluding the header.
            $totalRows = iterator_count($rowIterator) - 1;
            if ($totalRows < 1) {
                continue;
            }
            break;
        } while (true);

        $this->sheetIndex = $sheet->getIndex();
        $this->prepareIterator();

        // Go on the second row, headers are already stored.
        $this->next();

        return true;
    }

    protected function reset(): \BulkImport\Interfaces\Reader
    {
        parent::reset();
        if ($this->spreadsheetReader) {
            $this->spreadsheetReader->close();
        }
        return $this;
    }

    protected function prepareIterator(): \BulkImport\Interfaces\Reader
    {
        parent::prepareIterator();
        $this->next();
        return $this;
    }

    protected function initializeReader(): \BulkImport\Interfaces\Reader
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
            // ->setTempFolder($this->config['temp_dir'])
            // Read the dates as text. See fix #179 in CSVImport.
            // TODO Read the good format in spreadsheet entry.
            ->setShouldFormatDates(true);

        // Initialize first sheet and sheet iterator.
        $this->sheetIterator = $this->spreadsheetReader->getSheetIterator();
        $this->sheetIterator->rewind();
        $sheet = null;
        $multisheet = $this->getParam('multisheet', 'active');
        $this->isMultiSheet = $multisheet === 'all';
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
                        $this->sheetIndex = $sheet->getIndex();
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

        return $this;
    }

    protected function finalizePrepareIterator(): \BulkImport\Interfaces\Reader
    {
        if ($this->isMultiSheet) {
            return $this->finalizePrepareIteratorMultiSheets();
        }

        $this->totalEntries = iterator_count($this->iterator) - 1;
        $this->initializeReader();
        return $this;
    }

    protected function finalizePrepareIteratorMultiSheets(): \BulkImport\Interfaces\Reader
    {
        $this->totalEntries = 0;
        /** @var \Box\Spout\Reader\ODS\Sheet $sheet */
        foreach ($this->sheetIterator as $sheet) {
            if (!$this->isOldBoxSpout && !$sheet->isVisible()) {
                continue;
            }
            $this->totalEntries += iterator_count($sheet->getRowIterator()) - 1;
        }
        $this->initializeReader();
        return $this;
    }

    protected function prepareAvailableFields(): \BulkImport\Interfaces\Reader
    {
        if ($this->isOldBoxSpout) {
            $this->prepareAvailableFieldsOld();
            return $this;
        }

        if ($this->isMultiSheet ) {
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

    protected function prepareAvailableFieldsMultiSheets(): \BulkImport\Interfaces\Reader
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
            $this->availableFieldsMultiSheets[$sheet->getIndex()] = $this->cleanData($row->toArray());
        }
        $this->availableFields = array_values(array_unique(array_merge(...$this->availableFieldsMultiSheets)));
        $this->initializeReader();
        return $this;
    }

    /**
     * @todo Remove support of old CSV Import when patch https://github.com/omeka-s-modules/CSVImport/pull/182 will be included.
     */
    protected function prepareAvailableFieldsOld(): \BulkImport\Interfaces\Reader
    {
        if ($this->isMultiSheet ) {
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
    protected function prepareAvailableFieldsMultiSheetsOld(): \BulkImport\Interfaces\Reader
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
