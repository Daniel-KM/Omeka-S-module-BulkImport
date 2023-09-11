<?php declare(strict_types=1);

return [
    'o:owner' => null,
    'o:label' => 'Xml - Ead', // @translate
    'o-bulk:reader' => \BulkImport\Reader\XmlReader::class,
    'o-bulk:mapper' => null,
    'o-bulk:processor' => \BulkImport\Processor\ItemProcessor::class,
    'o:config' => [
        'reader' => [
            'xsl_sheet_pre' => '',
            'xsl_sheet' => 'module:xsl/ead_to_resources.xsl',
            'xsl_params' => [],
        ],
        'mapper' => [
            'mapper' => 'module:xml/ead_to_omeka.xml',
        ],
        'processor' => [
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
    ],
];
