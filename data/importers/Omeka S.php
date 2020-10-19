<?php declare(strict_types=1);
return [
    'owner' => null,
    'label' => 'Omeka S',
    'readerClass' => \BulkImport\Reader\OmekaSReader::class,
    'readerConfig' => [
    ],
    'processorClass' => \BulkImport\Processor\OmekaSProcessor::class,
    'processorConfig' => [
    ],
];
