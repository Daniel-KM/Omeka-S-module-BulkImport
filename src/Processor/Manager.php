<?php declare(strict_types=1);
namespace BulkImport\Processor;

use BulkImport\AbstractPluginManager;

class Manager extends AbstractPluginManager
{
    protected function getName()
    {
        return 'processors';
    }

    protected function getInterface()
    {
        return Processor::class;
    }
}
