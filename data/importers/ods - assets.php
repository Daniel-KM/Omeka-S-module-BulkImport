<?php declare(strict_types=1);

return [
    'owner' => null,
    'label' => 'OpenDocument spreadsheet (ods) - Assets', // @translate
    'config' => [],
    'readerClass' => \BulkImport\Reader\OpenDocumentSpreadsheetReader::class,
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
