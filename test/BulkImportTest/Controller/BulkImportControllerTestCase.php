<?php declare(strict_types=1);

namespace BulkImportTest\Controller;

use OmekaTestHelper\Controller\OmekaControllerTestCase;

abstract class BulkImportControllerTestCase extends OmekaControllerTestCase
{
    protected function getSettings()
    {
        return [];
    }

    public function setUp(): void
    {
        $this->loginAsAdmin();
    }

    public function tearDown(): void
    {
    }
}
