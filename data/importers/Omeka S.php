<?php declare(strict_types=1);

return [
    'owner' => null,
    'label' => 'Omeka S',
    'config' => [],
    'readerClass' => \BulkImport\Reader\OmekaSReader::class,
    'readerConfig' => [
    ],
    'processorClass' => \BulkImport\Processor\OmekaSProcessor::class,
    'processorConfig' => [
        'skip_missing_files' => false,
        'entries_to_skip' => 0,
        'entries_max' => null,
        'allow_duplicate_identifiers' => false,
    ],
];
