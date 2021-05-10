<?php declare(strict_types=1);

return [
    'owner' => null,
    'label' => 'Xml - Items', // @translate
    'readerClass' => \BulkImport\Reader\XmlReader::class,
    'readerConfig' => [
        'xsl_sheet' => 'modules/BulkImport/data/xsl/identity.xslt1.xsl',
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
            'dcterms:identifier',
        ],
        'allow_duplicate_identifiers' => false,
        'entries_to_skip' => 0,
        'entries_by_batch' => '',
        'resource_type' => '',
    ],
];
