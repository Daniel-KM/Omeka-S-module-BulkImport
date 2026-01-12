<?php declare(strict_types=1);
namespace BulkImportTest\Reader;

use BulkImport\Reader\TsvReader;

if (!class_exists('BulkImportTest\Reader\AbstractReader')) {
    require __DIR__ . '/AbstractReader.php';
}

class TsvReaderTest extends AbstractReader
{
    protected $ReaderClass = TsvReader::class;

    public function ReaderProvider()
    {
        // TSV requires specific delimiter/enclosure/escape params.
        $tsvParams = [
            'delimiter' => "\t",
            'enclosure' => chr(0),
            'escape' => chr(0),
        ];

        return [
            // filepath, options, expected for each test: [isValid, count (data rows only), availableFields].
            // Files with missing/excess columns are now valid.
            ['test_column_missing.tsv', $tsvParams, [true, 3, ['Identifier', 'Title', 'Description']]],
            ['test_column_in_excess.tsv', $tsvParams, [true, 4, ['Identifier', 'Title', 'Description']]],
        ];
    }
}
