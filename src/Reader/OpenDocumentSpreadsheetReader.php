<?php declare(strict_types=1);
namespace BulkImport\Reader;

use Box\Spout\Common\Type;
use Box\Spout\Reader\Common\Creator\ReaderEntityFactory;
use Box\Spout\Reader\ReaderInterface;
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
        'separator',
    ];

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
        $this->isReady;
        $this->initializeReader();
        $this->next();
    }

    protected function reset(): void
    {
        parent::reset();
        if ($this->spreadsheetReader) {
            $this->spreadsheetReader->close();
        }
    }

    protected function prepareIterator(): void
    {
        parent::prepareIterator();
        $this->next();
    }

    protected function initializeReader(): void
    {
        if ($this->spreadsheetReader) {
            $this->spreadsheetReader->close();
        }

        $this->spreadsheetReader = ReaderEntityFactory::createODSReader();

        $filepath = $this->getParam('filename');
        try {
            $this->spreadsheetReader->open($filepath);
        } catch (\Box\Spout\Common\Exception\IOException $e) {
            throw new \Omeka\Service\Exception\RuntimeException(
                new PsrMessage(
                    'File "{filepath}" cannot be open.', // @translate
                    ['filepath' => $filepath]
                )
            );
        }

        $this->spreadsheetReader
            // ->setTempFolder($this->config['temp_dir'])
            ->setShouldFormatDates(false);

        // Process first sheet only.
        $this->iterator = null;
        foreach ($this->spreadsheetReader->getSheetIterator() as $sheet) {
            $this->iterator = $sheet->getRowIterator();
            break;
        }
    }

    protected function finalizePrepareIterator(): void
    {
        $this->totalEntries = iterator_count($this->iterator) - 1;
        $this->initializeReader();
    }

    protected function prepareAvailableFields(): void
    {
        /** @var \Box\Spout\Common\Entity\Row $row */
        foreach ($this->iterator as $row) {
            break;
        }
        if (!$row) {
            $this->lastErrorMessage = 'File has no available fields.'; // @translate
            throw new \Omeka\Service\Exception\RuntimeException($this->getLastErrorMessage());
        }
        // The data should be cleaned, since it's not an entry.
        $this->availableFields = $this->cleanData($row->toArray());
        $this->initializeReader();
    }
}
