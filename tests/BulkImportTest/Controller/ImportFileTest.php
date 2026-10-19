<?php declare(strict_types=1);

namespace BulkImportTest\Controller;

use CommonTest\AbstractHttpControllerTestCase;

/**
 * The files of the imports (logs, diffs) are served through an admin route,
 * because the directory files/bulk_import is protected from a direct access.
 */
class ImportFileTest extends AbstractHttpControllerTestCase
{
    protected $importId;

    protected $files = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAsAdmin();

        $services = $this->getApplicationServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');
        $user = $entityManager->find(\Omeka\Entity\User::class, $services->get('Omeka\AuthenticationService')->getIdentity()->getId());

        $importer = new \BulkImport\Entity\Importer;
        $importer
            ->setOwner($user)
            ->setLabel('Test files')
            ->setReader(\BulkImport\Reader\SpreadsheetReader::class)
            ->setProcessor(\BulkImport\Processor\ResourceProcessor::class)
            ->setConfig([]);
        $entityManager->persist($importer);
        $import = new \BulkImport\Entity\Import;
        $import
            ->setImporter($importer)
            ->setParams([]);
        $entityManager->persist($import);
        $entityManager->flush();
        $this->importId = $import->getId();
    }

    public function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    protected function createFile(string $filename): void
    {
        $filepath = OMEKA_PATH . '/files/bulk_import/' . $filename;
        @mkdir(dirname($filepath), 0775, true);
        file_put_contents($filepath, "id\tmessage\n");
        $this->files[] = $filepath;
    }

    public function testDownloadLogOfImport(): void
    {
        $filename = $this->importId . '-log.tsv';
        $this->createFile($filename);
        // The file is sent directly to the output and the output buffers are
        // closed, so restore them for phpunit.
        $level = ob_get_level();
        $this->dispatch('/admin/bulk/file/' . $filename);
        while (ob_get_level() < $level) {
            ob_start();
        }
        $this->assertResponseStatusCode(200);
        $this->assertResponseHeaderContains('Content-Length', '11');
        $this->assertResponseHeaderRegex('Content-Disposition', '~attachment; filename="' . preg_quote($filename) . '"~');
    }

    public function testFileNotRelatedToAnImport(): void
    {
        $this->createFile('other-file.tsv');
        $this->dispatch('/admin/bulk/file/other-file.tsv');
        $this->assertResponseStatusCode(404);
    }

    public function testMissingFile(): void
    {
        $this->dispatch('/admin/bulk/file/' . $this->importId . '-missing.tsv');
        $this->assertResponseStatusCode(404);
    }

    public function testUrlOfImportLog(): void
    {
        $services = $this->getApplicationServiceLocator();
        $url = $services->get('ControllerPluginManager')->get('bulk')->fileUrl($this->importId . '-log.tsv');
        $this->assertStringEndsWith('/admin/bulk/file/' . $this->importId . '-log.tsv', $url);
    }
}
