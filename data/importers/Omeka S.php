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
        'allow_duplicate_identifiers' => false,
        'entries_to_skip' => 0,
        'entries_max' => null,
    ],
];
