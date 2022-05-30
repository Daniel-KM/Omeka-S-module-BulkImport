<?php

/**
 * Config for the import of eprints.
 *
 * @todo Use standard mapping to transform source.
 */

return [
    // Types from eprint type to template name.
    'types_to_resource_templates' => [
    ],
    'types_to_resource_classes' => [
    ],
    'item_templates_to_media_templates' => [
    ],
    'custom_vocabs' => [
        // Single-valued fields.
        'eprint_status' => null,
        'full_text_status' => null,
        'institution' => null,
        'institution_partner' => null,
        'ispublished' => null,
        'master_type' => null,
        'monograph_type' => null,
        'pres_type' => null,
        'thesis_type' => null,
        'type' => null,
        // Multi-valued fields.
        'creators' => null,
        'contributors' => null,
        'directors' => null,
        'directors_other' => null,
        'producers' => null,
        'editors' => null,
        'conductors' => null,
        'exhibitors' => null,
        'lyricists' => null,
        'issues' => null,
        // Automatic custom vocabs.
    ],
    'transform' => [
        'dcterms:type' => [
        ],
    ],
];
