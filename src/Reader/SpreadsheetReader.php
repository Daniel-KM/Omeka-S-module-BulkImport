<?php declare(strict_types=1);

namespace BulkImport\Reader;

use BulkImport\Entry\BaseEntry;
use BulkImport\Form\Reader\CsvReaderConfigForm;
use BulkImport\Form\Reader\SpreadsheetReaderParamsForm;

class SpreadsheetReader extends AbstractGenericFileReader
{
    protected $label = 'Spreadsheet'; // @translate
    protected $mediaType = [
        'application/vnd.oasis.opendocument.spreadsheet',
        'text/csv',
        'text/tab-separated-values',
        'application/csv',
    ];
    protected $configFormClass = CsvReaderConfigForm::class;
    protected $paramsFormClass = SpreadsheetReaderParamsForm::class;
    protected $entryClass = BaseEntry::class;

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
        // TODO Multisheet for generic spreadsheet.
        'separator',
    ];

    protected $mediaTypeReaders = [
        'application/vnd.oasis.opendocument.spreadsheet' => OpenDocumentSpreadsheetReader::class,
        'application/csv' => CsvReader::class,
        'text/csv' => CsvReader::class,
        'text/tab-separated-values' => TsvReader::class,
    ];
}
