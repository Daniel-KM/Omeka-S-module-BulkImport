<?php declare(strict_types=1);
return [
    'owner' => null,
    'label' => 'TSV (tab-separated values) - Mixed resources', // @translate
    'readerClass' => \BulkImport\Reader\TsvReader::class,
    'readerConfig' => [
        'separator' => '|',
    ],
    'processorClass' => \BulkImport\Processor\ResourceProcessor::class,
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
