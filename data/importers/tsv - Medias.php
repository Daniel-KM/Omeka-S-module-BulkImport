<?php declare(strict_types=1);

return [
    'owner' => null,
    'label' => 'TSV (tab-separated values) - Medias', // @translate
    'config' => [],
    'readerClass' => \BulkImport\Reader\TsvReader::class,
    'readerConfig' => [
        'separator' => '|',
    ],
    'processorClass' => \BulkImport\Processor\MediaProcessor::class,
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
