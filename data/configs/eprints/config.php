<?php

/**
 * Config for the import of eprints.
 */

return [
    // Types from eprint type to template name.
    'types_to_resource_templates' => [
    ],
    'types_to_resource_classes' => [
    ],
    'custom_vocabs' => [
        'eprint_status' => null,
        'full_text_status' => null,
        'institution' => null,
        'institution_partner' => null,
        'ispublished' => null,
        'master_type' => null,
        'monograph_type' => null,
        'pres_type' => null,
        'thesis_type' => null,
        // Automatic custom vocabs.
        'degrees' => 'customvocab:Thesaurus degrees',
        'divisions' => 'customvocab:Thesaurus divisions',
        'doctoral_school' => 'customvocab:Thesaurus doctoral_school',
        'research_unit' => 'Thesaurus research_unit',
        'subjects' => 'Thesaurus subjects',
    ],
];
