<?php declare(strict_types=1);

return [
    'owner' => null,
    'label' => 'CSV - Medias', // @translate
    'config' => [],
    'readerClass' => \BulkImport\Reader\CsvReader::class,
    'readerConfig' => [
        'delimiter' => ',',
        'enclosure' => '"',
        'escape' => '\\',
        'separator' => '|',
    ],
    'processorClass' => \BulkImport\Processor\MediaProcessor::class,
    'processorConfig' => [
        'entries_to_skip' => 0,
        'entries_max' => null,
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
        'resource_name' => '',
    ],
];
