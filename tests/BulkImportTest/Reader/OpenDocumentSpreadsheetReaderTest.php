<?php declare(strict_types=1);
namespace BulkImportTest\Reader;

use BulkImport\Reader\OpenDocumentSpreadsheetReader;

if (!class_exists('BulkImportTest\Reader\AbstractReader')) {
    require __DIR__ . '/AbstractReader.php';
}

class OpenDocumentSpreadsheetReaderTest extends AbstractReader
{
    protected $ReaderClass = OpenDocumentSpreadsheetReader::class;

    public function ReaderProvider()
    {
        return [
            // filepath, options, expected for each test: [isValid, count (data rows only), availableFields].
            // The heritage file has 19 columns including 2 dcterms:identifier columns.
            ['test_resources_heritage.ods', [], [true, 24, [
                'Identifier', 'Resource Type', 'Collection Identifier', 'Item Identifier', 'Media Url',
                'Resource class', 'Title', 'Dublin Core : Creator', 'Date', 'Rights', 'Description',
                'Dublin Core:Format', 'dcterms:identifier', 'dcterms:identifier',
                'Dublin Core : Spatial Coverage', 'Tags', 'Latitude', 'Longitude', 'Default Zoom',
            ]]],
            // ODS files include trailing empty columns.
            ['test_column_missing.ods', [], [true, 3, ['Identifier', 'Title', 'Description', '']]],
            ['test_column_in_excess.ods', [], [true, 5, ['Identifier', 'Title', 'Description', '']]],
        ];
    }

}
