<?php declare(strict_types=1);

namespace BulkImportTest\Mvc\Controller\Plugin;

use BulkImport\Mvc\Controller\Plugin\BulkFile;
use CommonTest\AbstractHttpControllerTestCase;
use Omeka\Stdlib\ErrorStore;

/**
 * Check of files in the sideload directory.
 *
 * @see https://gitlab.com/Daniel-KM/Omeka-S-module-BulkImport/-/work_items/19
 */
class BulkFileSideloadTest extends AbstractHttpControllerTestCase
{
    protected $tempDir;

    public function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/bulkimport_sideload_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir . '/real/sub', 0775, true);
        file_put_contents($this->tempDir . '/real/sub/file.txt', 'text');
        mkdir($this->tempDir . '/real_other', 0775, true);
        file_put_contents($this->tempDir . '/real_other/file.txt', 'text');
        symlink($this->tempDir . '/real', $this->tempDir . '/link');
    }

    public function tearDown(): void
    {
        @unlink($this->tempDir . '/link');
        @unlink($this->tempDir . '/real/sub/file.txt');
        @unlink($this->tempDir . '/real_other/file.txt');
        @rmdir($this->tempDir . '/real/sub');
        @rmdir($this->tempDir . '/real');
        @rmdir($this->tempDir . '/real_other');
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    protected function bulkFile(string $sideloadPath): BulkFile
    {
        $services = $this->getApplicationServiceLocator();
        $plugins = $services->get('ControllerPluginManager');
        return new BulkFile(
            $plugins->get('bulk'),
            $plugins->get('bulkFileUploaded'),
            $services->get('Omeka\Logger'),
            $services->get('Omeka\File\TempFileFactory'),
            $services->get('Omeka\File\Store'),
            OMEKA_PATH . '/files',
            sys_get_temp_dir(),
            true,
            true,
            [],
            [],
            [],
            [],
            false,
            $sideloadPath,
            false
        );
    }

    public function testSideloadDirectoryBehindSymlink(): void
    {
        $bulkFile = $this->bulkFile($this->tempDir . '/link');
        $errorStore = new ErrorStore();
        $this->assertTrue($bulkFile->checkFile('sub/file.txt', $errorStore), json_encode($errorStore->getErrors()));
        $this->assertTrue($bulkFile->checkFile($this->tempDir . '/link/sub/file.txt'));
        // A path starting with a slash may be relative to the directory.
        $this->assertTrue($bulkFile->checkFile('/sub/file.txt'));
    }

    public function testFileOutsideSideloadDirectory(): void
    {
        $bulkFile = $this->bulkFile($this->tempDir . '/real');
        // A sibling directory sharing the same prefix is not inside.
        $this->assertFalse($bulkFile->checkFile('../real_other/file.txt'));
        $this->assertFalse($bulkFile->checkFile($this->tempDir . '/real_other/file.txt'));
    }
}
