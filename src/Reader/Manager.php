<?php declare(strict_types=1);

namespace BulkImport\Reader;

use BulkImport\AbstractPluginManager;

class Manager extends AbstractPluginManager
{
    protected function getName()
    {
        return 'readers';
    }

    protected function getInterface()
    {
        return Reader::class;
    }
}
