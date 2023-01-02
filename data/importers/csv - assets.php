<?php declare(strict_types=1);

return [
    'owner' => null,
    'label' => 'CSV - Assets', // @translate
    'config' => [],
    'readerClass' => \BulkImport\Reader\CsvReader::class,
    'readerConfig' => [
        'delimiter' => ',',
        'enclosure' => '"',
        'escape' => '\\',
        'separator' => '|',
    ],
    'processorClass' => \BulkImport\Processor\AssetProcessor::class,
    'processorConfig' => [
        'entries_to_skip' => 0,
        'entries_max' => null,
        'o:owner' => "current",
        'action' => 'create',
        'action_unidentified' => 'skip',
        'identifier_name' => [
            'o:id',
        ],
    ],
];
