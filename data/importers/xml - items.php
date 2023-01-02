<?php declare(strict_types=1);

return [
    'owner' => null,
    'label' => 'Xml - Items', // @translate
    'config' => [],
    'readerClass' => \BulkImport\Reader\XmlReader::class,
    'readerConfig' => [
        'xsl_sheet_pre' => '',
        'xsl_sheet' => 'module:xsl/identity.xslt1.xsl',
        'mapping_config' => '',
        'xsl_params' => [],
    ],
    'processorClass' => \BulkImport\Processor\ItemProcessor::class,
    'processorConfig' => [
        'skip_missing_files' => false,
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
