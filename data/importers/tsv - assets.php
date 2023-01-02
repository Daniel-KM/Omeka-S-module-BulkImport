<?php declare(strict_types=1);

return [
    'owner' => null,
    'label' => 'TSV (tab-separated values) - Assets', // @translate
    'config' => [],
    'readerClass' => \BulkImport\Reader\TsvReader::class,
    'readerConfig' => [
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
