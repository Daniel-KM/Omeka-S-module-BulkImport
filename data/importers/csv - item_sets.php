<?php declare(strict_types=1);
return [
    'owner' => null,
    'label' => 'CSV - Item sets', // @translate
    'readerClass' => \BulkImport\Reader\CsvReader::class,
    'readerConfig' => [
        'delimiter' => ',',
        'enclosure' => '"',
        'escape' => '\\',
        'separator' => '|',
    ],
    'processorClass' => \BulkImport\Processor\ItemSetProcessor::class,
    'processorConfig' => [
        'o:resource_template' => '',
        'o:resource_class' => '',
        'o:owner' => "current",
        'o:is_public' => null,
        'action' => 'create',
        'action_unidentified' => 'skip',
        'identifier_name' => [
            'o:id',
            'dcterms:identifier',
        ],
        'allow_duplicate_identifiers' => false,
        'entries_to_skip' => 0,
        'entries_by_batch' => '',
        'resource_type' => '',
    ],
];
