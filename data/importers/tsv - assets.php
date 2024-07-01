<?php declare(strict_types=1);

return [
    'o:owner' => null,
    'o:label' => 'TSV (tab-separated values) - Assets', // @translate
    'o-bulk:reader' => \BulkImport\Reader\TsvReader::class,
    'o-bulk:mapper' => 'manual',
    'o-bulk:processor' => \BulkImport\Processor\AssetProcessor::class,
    'o:config' => [
        'reader' => [
            'separator' => '|',
        ],
        'mapper' => [
        ],
        'processor' => [
            'entries_to_skip' => 0,
            'entries_max' => null,
            'o:owner' => "current",
            'action' => 'create',
            'action_unidentified' => 'error',
            'identifier_name' => [
                'o:id',
            ],
        ],
    ],
];
