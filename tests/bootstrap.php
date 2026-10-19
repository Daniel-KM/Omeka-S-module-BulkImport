<?php declare(strict_types=1);

require dirname(__DIR__, 3) . '/modules/Common/tests/Bootstrap.php';

\CommonTest\Bootstrap::bootstrap(
    [
        'Common',
        'Log',
        'Mapper',
        'BulkImport',
        // Optional: import of digital objects.
        '?DigitalObject',
        // Optional: import of concepts.
        '?Thesaurus',
    ],
    'BulkImportTest',
    __DIR__ . '/BulkImportTest'
);
