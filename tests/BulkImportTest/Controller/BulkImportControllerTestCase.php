<?php declare(strict_types=1);

namespace BulkImportTest\Controller;

use CommonTest\AbstractHttpControllerTestCase;

abstract class BulkImportControllerTestCase extends AbstractHttpControllerTestCase
{
    protected function getSettings()
    {
        return [];
    }

    public function setUp(): void
    {
        parent::setUp();
    }

    public function tearDown(): void
    {
        parent::tearDown();
    }
}
