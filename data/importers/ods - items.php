<?php declare(strict_types=1);

return [
    'owner' => null,
    'label' => 'OpenDocument spreadsheet (ods) - Items', // @translate
    'config' => [],
    'readerClass' => \BulkImport\Reader\OpenDocumentSpreadsheetReader::class,
    'readerConfig' => [
        'separator' => '|',
    ],
    'processorClass' => \BulkImport\Processor\ItemProcessor::class,
    'processorConfig' => [
        'o:resource_template' => '',
        'o:resource_class' => '',
        'o:owner' => "current",
        'o:is_public' => null,
        'action' => 'create',
        'action_unidentified' => 'skip',
        'identifier_name' => [
            'o:id',
        ],
        'allow_duplicate_identifiers' => false,
        'entries_to_skip' => 0,
        'entries_max' => null,
        'resource_name' => '',
    ],
];
