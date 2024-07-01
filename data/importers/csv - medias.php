<?php declare(strict_types=1);

return [
    'o:owner' => null,
    'o:label' => 'CSV - Medias', // @translate
    'o-bulk:reader' => \BulkImport\Reader\CsvReader::class,
    'o-bulk:mapper' => 'manual',
    'o-bulk:processor' => \BulkImport\Processor\MediaProcessor::class,
    'o:config' => [
        'reader' => [
            'delimiter' => ',',
            'enclosure' => '"',
            'escape' => '\\',
            'separator' => '|',
        ],
        'mapper' => [
        ],
        'processor' => [
            'entries_to_skip' => 0,
            'entries_max' => null,
            'o:resource_template' => '',
            'o:resource_class' => '',
            'o:owner' => "current",
            'o:is_public' => null,
            'action' => 'create',
            'action_unidentified' => 'error',
            'identifier_name' => [
                'o:id',
            ],
            'allow_duplicate_identifiers' => false,
            'resource_name' => '',
        ],
    ],
];
