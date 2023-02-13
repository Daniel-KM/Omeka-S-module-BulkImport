<?php declare(strict_types=1);

namespace BulkImport\Reader;

use BulkImport\Form\Reader\CsvReaderConfigForm;
use BulkImport\Form\Reader\CsvReaderParamsForm;
use Log\Stdlib\PsrMessage;
use OpenSpout\Common\Type;
use SplFileObject;

/**
 * OpenSpout Spreadshet reader doesn't support escape for csv (even if it
 * manages end of line and encoding). So the basic file handler is used for csv.
 * The format tsv uses the Spout reader, because there is no escape.
 *
 * @todo Check if OpenSpout supports escape for csv.
 */
class CsvReader extends AbstractSpreadsheetFileReader
{
    const DEFAULT_DELIMITER = ',';
    const DEFAULT_ENCLOSURE = '"';
    const DEFAULT_ESCAPE = '\\';

    protected $label = 'CSV'; // @translate
    protected $mediaType = 'text/csv';
    protected $configFormClass = CsvReaderConfigForm::class;
    protected $paramsFormClass = CsvReaderParamsForm::class;

    protected $configKeys = [
        'url',
        'delimiter',
        'enclosure',
        'escape',
        'separator',
    ];

    protected $paramsKeys = [
        'filename',
        'url',
        'delimiter',
        'enclosure',
        'escape',
        'separator',
    ];

    /**
     * @var \SplFileObject.
     */
    protected $iterator;

    /**
     * Type of spreadsheet.
     *
     * @var string
     */
    protected $spreadsheetType = Type::CSV;

    /**
     * @var string
     */
    protected $delimiter = self::DEFAULT_DELIMITER;

    /**
     * @var string
     */
    protected $enclosure = self::DEFAULT_ENCLOSURE;

    /**
     * @var string
     */
    protected $escape = self::DEFAULT_ESCAPE;

    #[\ReturnTypeWillChange]
    public function key()
    {
        // The first row should be numbered 0 for the data, not the headers.
        return parent::key() - 1;
    }

    /**
     * Reader use a foreach loop to get data. So the first output should not be
     * the available fields, but the data (numbered as 0-based).
     *
     * {@inheritDoc}
     * @see \Iterator::rewind()
     */
    public function rewind(): void
    {
        $this->isReady();
        $this->iterator->rewind();
        // Skip headers, already stored.
        $this->next();
    }

    protected function reset(): self
    {
        parent::reset();
        $this->delimiter = self::DEFAULT_DELIMITER;
        $this->enclosure = self::DEFAULT_ENCLOSURE;
        $this->escape = self::DEFAULT_ESCAPE;
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
        $filepath = $this->getParam('filename');
        $this->iterator = new SplFileObject($filepath);

        $this->delimiter = $this->getParam('delimiter', self::DEFAULT_DELIMITER);
        $this->enclosure = $this->getParam('enclosure', self::DEFAULT_ENCLOSURE);
        $this->escape = $this->getParam('escape', self::DEFAULT_ESCAPE);
        $this->iterator->setFlags(
            SplFileObject::READ_CSV
            | SplFileObject::READ_AHEAD
            | SplFileObject::SKIP_EMPTY
            | SplFileObject::DROP_NEW_LINE
        );
        $this->iterator->setCsvControl($this->delimiter, $this->enclosure, $this->escape);
        return $this;
    }

    protected function finalizePrepareIterator(): self
    {
        // Warning: it should skip last empty rows, but it is rare for csv.
        $this->totalEntries = iterator_count($this->iterator) - 1;
        return $this;
    }

    protected function prepareAvailableFields(): self
    {
        $this->iterator->rewind();
        $fields = $this->iterator->current();
        if (!is_array($fields)) {
            $this->lastErrorMessage = 'File has no available fields.'; // @translate
            throw new \Omeka\Service\Exception\RuntimeException((string) $this->getLastErrorMessage());
        }
        // The data should be cleaned, since it's not an entry.
        $this->availableFields = $this->cleanData($fields);
        return $this;
    }

    protected function isValidFilepath($filepath, array $file = []): bool
    {
        // On some servers, type for csv is "application/vnd.ms-excel".
        if (!empty($file['type']) && $file['type'] === 'application/vnd.ms-excel') {
            $file['type'] = 'text/csv';
        }

        if (!parent::isValidFilepath($filepath, $file)) {
            return false;
        }

        if (!$this->isUtf8($filepath)) {
            $this->lastErrorMessage = new PsrMessage(
                'File "{filename}" is not fully utf-8.', // @translate
                ['filename' => $file['name']]
            );
            return false;
        }

        return true;
    }

    /**
     * Check if the file is utf-8 formatted.
     *
     * @param string $filepath
     * @return bool
     */
    protected function isUtf8($filepath): bool
    {
        // TODO Use another check when mb is not installed.
        if (!function_exists('mb_detect_encoding')) {
            return true;
        }

        // Check all the file, because the headers are generally ascii.
        // Nevertheless, check the lines one by one as text to avoid a memory
        // overflow with a big csv file.
        $iterator = new SplFileObject($filepath);
        foreach ($iterator as $line) {
            if (mb_detect_encoding($line, 'UTF-8', true) !== 'UTF-8') {
                return false;
            }
        }
        return true;
    }
}
