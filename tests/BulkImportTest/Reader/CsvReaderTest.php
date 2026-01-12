<?php declare(strict_types=1);
namespace BulkImportTest\Reader;

use BulkImport\Reader\CsvReader;

if (!class_exists('BulkImportTest\Reader\AbstractReader')) {
    require __DIR__ . '/AbstractReader.php';
}

class CsvReaderTest extends AbstractReader
{
    protected $ReaderClass = CsvReader::class;

    public function ReaderProvider()
    {
        return [
            // filepath, options, expected for each test: [isValid, count (data rows only), availableFields].
            ['test.csv', [], [true, 3, ['title', 'creator', 'description', 'tags', 'file']]],
            ['test_automap_columns.csv', [], [true, 3, [
                'Identifier', 'Dublin Core:Title', 'dcterms:creator', 'Description', 'Date', 'Publisher',
                'Collections', 'Tags', 'Resource template', 'Resource class',
                'Media url',
            ]]],
            // Files with missing/excess columns are now valid.
            ['test_column_missing.csv', [], [true, 3, ['Identifier', 'Title', 'Description']]],
            ['test_column_in_excess.csv', [], [true, 4, ['Identifier', 'Title', 'Description']]],
        ];
    }

    /**
     * Test that non-UTF8 files are marked as invalid.
     */
    public function testCyrillicFileIsInvalid(): void
    {
        $reader = $this->getReader('test_cyrillic.csv', []);
        $this->assertFalse($reader->isValid());
    }

    /**
     * Test that empty files with only a header are valid but have 0 data rows.
     */
    public function testEmptyFileWithHeader(): void
    {
        $reader = $this->getReader('empty.csv', []);
        $this->assertTrue($reader->isValid());
        $this->assertEquals(0, $reader->count());
        // Empty header line results in single empty string field.
        $this->assertEquals([''], $reader->getAvailableFields());
    }

    /**
     * Test that completely empty files are marked as invalid.
     */
    public function testCompletelyEmptyFileIsInvalid(): void
    {
        $reader = $this->getReader('empty_really.csv', []);
        $this->assertFalse($reader->isValid());
    }
}
