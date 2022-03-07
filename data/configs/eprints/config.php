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
        'degrees' => 'customvocab:Thesaurus degrees',
        'divisions' => 'customvocab:Thesaurus divisions',
        'doctoral_school' => 'customvocab:Thesaurus doctoral_school',
        'research_unit' => 'Thesaurus research_unit',
        'subjects' => 'Thesaurus subjects',
    ],
    'transform' => [
        'dcterms:type' => [
            'article' => 'Article',
            'master' => 'Mémoire',
            'pfpa' => 'Parcours de Formation Professionnelle Adapté',
            'projettutore' => 'Projet tutoré',
            'rapportstage' => 'Rapport de stage',
            'thesis' => 'Thèse de doctorat',
        ],
    ],
];
