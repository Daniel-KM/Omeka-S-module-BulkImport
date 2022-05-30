<?php

/**
 * Pour utiliser, renommer ce fichier config.php.
 *
 * Config for the import of eprints.
 *
 * @todo Use standard mapping to transform source.
 *
 * @todo Remplacer "dante:typeAccesDocument" => curation:access ou dcterms:accessRights.
 */

return [
    // Types from eprint type to template name.
    'types_to_resource_templates' => [
        'article' => 'Travail étudiant',
        'master' => 'Mémoire',
        // 'pfpa' => 'Parcours de Formation Professionnelle Adapté',
        'pfpa' => 'Travail scientifique de nature réflexive',
        'projettutore' => 'Projet tutoré',
        'rapportstage' => 'Rapport de stage',
        'thesis' => 'Thèse',
    ],
    'types_to_resource_classes' => [
        'article' => 'dante:Article',
        'master' => 'dante:Master',
        // 'pfpa' => 'dante:Pfpa',
        'pfpa' => 'dante:TravailScientifiqueDeNatureReflexive',
        'projettutore' => 'dante:ProjetTutore',
        'rapportstage' => 'dante:RapportStage',
        'thesis' => 'bibo:Thesis',
    ],
    'item_templates_to_media_templates' => [
        'Mémoire' => 'Fichier (travail étudiant)',
        'Projet tutoré' => 'Fichier (travail étudiant)',
        'Rapport de stage' => 'Fichier (travail étudiant)',
        'Travail étudiant' => 'Fichier (travail étudiant)',
        'Travail scientifique de nature réflexive' => 'Fichier (travail étudiant)',
        'Thèse' => 'Fichier (thèse)',
    ],
    'custom_vocabs' => [
        // Single-valued fields.
        'eprint_status' => 'customvocab:Statut de publication',
        'eprint_subjects' => 'customvocab:Sujets',
        'full_text_status' => null,
        'institution' => null,
        'institution_partner' => null,
        'ispublished' => null,
        'master_type' => 'customvocab:Type de diplôme',
        'monograph_type' => 'customvocab:Type de document',
        'pres_type' => null,
        'thesis_type' => 'customvocab:Type de diplôme',
        'type' => 'customvocab:Type de document',
        'file_version' => 'customvocab:Versions',
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
        // Automatic custom vocabs when subjects are available.
        'degrees' => 'customvocab:Thesaurus degrees',
        'divisions' => 'customvocab:Thesaurus divisions',
        'doctoral_school' => 'customvocab:Thesaurus doctoral_school',
        'research_unit' => 'customvocab:Thesaurus research_unit',
        'subjects' => 'customvocab:Thesaurus subjects',
    ],
    'transform' => [
        'dcterms:type' => [
            'article' => 'Article',
            'master' => 'Mémoire',
            // 'pfpa' => 'Parcours de Formation Professionnelle Adapté',
            'pfpa' => 'Travail scientifique de nature réflexive',
            'projettutore' => 'Projet tutoré',
            'rapportstage' => 'Rapport de stage',
            'thesis' => 'Thèse de doctorat',
        ],
        'file_version' => [
            'before_viva' => 'Pré-soutenance',
            'before_viva_annex' => 'Pré-soutenance, annexes',
            'diffusion' => 'Diffusion',
            'diffusion_annex' => 'Diffusion, annexes',
            'validated' => 'Conservation',
            'validated_annex' => 'Conservation, annexes',
        ],
    ],
];
