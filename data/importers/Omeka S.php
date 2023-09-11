<?php declare(strict_types=1);

return [
    'o:owner' => null,
    'o:label' => 'Omeka S', // @translate
    'o-bulk:reader' => \BulkImport\Reader\OmekaSReader::class,
    'o-bulk:mapper' => null,
    'o-bulk:processor' => \BulkImport\Processor\OmekaSProcessor::class,
    'o:config' => [
        'reader' => [
        ],
        'mapper' => [
        ],
        'processor' => [
            'skip_missing_files' => false,
            'entries_to_skip' => 0,
            'entries_max' => null,
            'allow_duplicate_identifiers' => false,
        ],
    ],
];
