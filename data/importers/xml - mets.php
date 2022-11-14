<?php declare(strict_types=1);

return [
    'owner' => null,
    'label' => 'Xml - Mets', // @translate
    'config' => [],
    'readerClass' => \BulkImport\Reader\XmlReader::class,
    'readerConfig' => [
        'xsl_sheet' => 'module:xsl/mets_to_omeka.xsl',
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
