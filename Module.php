<?php
namespace BulkImport;

require_once __DIR__ . '/src/Module/AbstractGenericModule.php';

use BulkImport\Module\AbstractGenericModule;

class Module extends AbstractGenericModule
{
    protected $dependency = 'Log';
}
