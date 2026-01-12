<?php declare(strict_types=1);

// Load Mapper autoloader for shared dependencies (OpenSpout, etc.).
$mapperAutoload = dirname(__DIR__, 2) . '/Mapper/vendor/autoload.php';
if (file_exists($mapperAutoload)) {
    require_once $mapperAutoload;
}

require dirname(__DIR__, 3) . '/modules/Common/tests/Bootstrap.php';

\CommonTest\Bootstrap::bootstrap(
    ['Common', 'Log', 'Mapper', 'BulkImport'],
    'BulkImportTest',
    __DIR__ . '/BulkImportTest'
);
