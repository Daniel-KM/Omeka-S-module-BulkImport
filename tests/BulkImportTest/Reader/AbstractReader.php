<?php declare(strict_types=1);
namespace BulkImportTest\Reader;

use CommonTest\AbstractHttpControllerTestCase;

abstract class AbstractReader extends AbstractHttpControllerTestCase
{
    protected $ReaderClass;

    protected $basepath;

    protected $reader;

    protected $tempPath;

    public function setUp(): void
    {
        parent::setup();

        $this->basepath = dirname(__DIR__, 2) . '/fixtures/spreadsheet/';

        $this->loginAsAdmin();
    }

    public function tearDown(): void
    {
        if ($this->tempPath && file_exists($this->tempPath)) {
            @unlink($this->tempPath);
        }
    }

    public function ReaderProvider()
    {
        return [];
    }

    /**
     * @dataProvider ReaderProvider
     */
    public function testIsValid($filepath, $options, $expected): void
    {
        $reader = $this->getReader($filepath, $options);
        $this->assertEquals($expected[0], $reader->isValid());
    }

    /**
     * @dataProvider ReaderProvider
     */
    public function testCount($filepath, $options, $expected): void
    {
        $reader = $this->getReader($filepath, $options);
        $this->assertEquals($expected[1], $reader->count());
    }

    /**
     * @dataProvider ReaderProvider
     */
    public function testGetAvailableFields($filepath, $options, $expected): void
    {
        $reader = $this->getReader($filepath, $options);
        $this->assertEquals($expected[2], $reader->getAvailableFields());
    }

    protected function getReader($filepath, array $params = [])
    {
        $originalFilepath = $this->basepath . $filepath;
        $this->tempPath = @tempnam(sys_get_temp_dir(), 'omk_bki_');
        copy($originalFilepath, $this->tempPath);

        $services = $this->getApplicationServiceLocator();
        $readerClass = $this->ReaderClass;
        $reader = new $readerClass($services);
        $reader->setLogger($services->get('Omeka\Logger'));
        $params['filename'] = $this->tempPath;
        $params['file'] = [
            'name' => basename($filepath),
            'tmp_name' => $this->tempPath,
        ];
        $reader->setParams($params);

        $this->reader = $reader;
        return $this->reader;
    }
}
