<?php
namespace BulkImport;

require_once __DIR__ . '/src/Module/AbstractGenericModule.php';

use BulkImport\Module\AbstractGenericModule;
use Zend\ModuleManager\ModuleManager;

class Module extends AbstractGenericModule
{
    protected $dependency = 'Log';

    public function init(ModuleManager $moduleManager)
    {
        require_once __DIR__ . '/vendor/autoload.php';
    }
}
