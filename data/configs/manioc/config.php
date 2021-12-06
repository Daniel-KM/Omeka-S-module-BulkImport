<?php

/**
 * Contient la liste des tableaux et des entêtes permettant d’effectuer la
 * correspondance avec les noms standard utilisés en interne dans le module.
 *
 * Lorque le fichier indiqué n'a pas d'extension, le module recherche avec les
 * extensions ".php", ".ods", ".tsv", ".csv" et ".txt".
 *
 * Attention : le module recherche d’abord dans le dossier "files/" d'Omeka, et
 * ensuite dans le dossier "data/configs/" du module.
 *
 * Attention : les noms des clés et colonnes tiennent compte de la casse.
 *
 * Les noms de clés servent uniquement comme référence dans le code. Ils peuvent
 * être une propriété, un type de donnée ou tout autre chose.
 *
 * Attention : dans les tableaux annexes, seules les destinations doivent
 * commencer par des noms valides de métadonnées. En particulier, les colonnes à
 * ne pas importer ne doivent pas commencer par un nom de propriété.
 */

return [
    // Liste des fichiers avec les codes de contrôle sha256 pour réimport avec BulkCheck.
    'sha256' => [
        'file'    => 'fichiers_sha256',
        // Pas d'entêtes : table clé/valeur issue de la commande `sha256`.
    ],

    /**
     * TODO Fusionner ces trois tableaux.
     */

    // Liste des groupes de modèles.
    'templates' => [
        // Modèle de ressource              => Entête dans le tableau de migration
        //                                     L’entête est normalisé comme ci-dessous.
        'Audio'                             => 'audio-vidéo', // 'Intitulé Audio-vidéo',
        'Vidéo'                             => 'audio-vidéo', // 'Intitulé Audio-vidéo',
        'Image'                             => 'images', // 'Intitulé Images',
        'Photographie'                      => 'images', // 'Intitulé Images',
        'Livre'                             => 'livres anciens', // 'Intitulé Livres anciens',
        'Mémoire et thèse'                  => 'recherche', // 'Intitulé Etudes et rech.',

        // Prises en compte des ressources créées lors des traitements précédents.
        // Personnes ou collectivités depuis créateur, contributeur, sujet et éditeur audio-vidéo.
        'Collectivité'                      => 'personnes et collectivités',
        'Personne'                          => 'personnes et collectivités',
        // Manifestations audio-vidéo.
        'Manifestation'                     => 'manifestations',
    ],

    // Listes des propriétés à migrer pour les groupes de modèles.
    'migration' => [
        'file'    => 'migration',
        'headers' => [
            'Tous Pre'                  => 'tout / pre',
            'Tous Post'                 => 'tout / post',
            'Elément'                   => 'source',
            'Intitulé Audio-vidéo'      => 'audio-vidéo',
            'Intitulé Images'           => 'images',
            'Intitulé Livres anciens'   => 'livres anciens',
            'Intitulé Etudes et rech.'  => 'recherche',
        ],
    ],

    // Pour la transformation
    'properties' => [
        'file'    => 'proprietes',
        'headers' => [
            'Source'        => 'source',
            'Destination'   => 'destination',
            'Type'          => 'type',
            'Public'        => 'is_public',
            'Langue'        => 'language',
        ],
    ],

    /**
     * Fichiers spécifiques
     *
     * @todo Ne pas rendre obligatoire l'ajout des fichiers dans ce fichier.
     */

    // Conversion des lieux et sujets géographiques en géonames.
    'countries_iso-3166' => [
        'file'    => 'countries_iso-3166',
    ],

    // Conversion des lieux et sujets géographiques en géonames.
    'languages_iso-639-2' => [
        'file'    => 'languages_iso-639-2',
    ],

    /**
     * Correspondances communes à tous les types.
     */

    // Conversion des langues en iso.
    'dcterms:language' => [
        'file'    => 'langues',
    ],

    // Conversion des éditeurs en idref.
    'dcterms:publisher' => [
        'file'    => 'editeurs',
    ],

    // Conversion des droits en valeur uri ou littéral.
    'dcterms:rights' => [
        'file'    => 'droits',
    ],

    // Conversion des mots-clés en sujets rameau.
    'dcterms:subject' => [
        'file'    => 'sujets',
    ],

    // Conversion des siècles en nombre.
    'dcterms:temporal' => [
        'file'    => 'siecles',
    ],

    // Conversion des établissements en liste.
    'dcterms:rightsHolder' => [
        'file'    => 'etablissements',
    ],

    // Conversion des numéros Dewey en thème (vocabulaire personnalisé).
    'dewey_themes' => [
        // Fichier complet.
        // 'file'    => 'dewey.e23.fr',
        'file'    => 'dewey.manioc.ods',
        'headers' => [
            'Indice Dewey' => 'source',
            // 'Libellé' => 'dcterms:subject ^^uri',
            'Libellé' => 'dcterms:subject ^^customvocab:Thématiques @fra',
        ],
    ],

    // Conversion des lieux et sujets géographiques en géonames.
    'geonames' => [
        'file'    => 'lieux',
    ],

    // Conversion en uri auteurs (createur, contributeur et personnes sujet).
    // valuesuggest:idref:author n’est pas un vrai type de données et sert à la
    // fois pour les personnes et les organisations.
    'valuesuggest:idref:author' => [
        'file'    => 'auteurs_et_contributeurs',
        // Les uris peuvent ne pas avoir le préfixe.
        'prefix'  => 'https://www.idref.fr/',
        'headers' => [
            'Nom-Prenom-Dates (appellation Manioc)' => 'source',
            // Liste d’id d’item ayant cette appelation. Peut être vide.
            ''                                      => 'items',
            'Type'                                  => 'type',
            // Le préfixe est utilisé pour avoir une uri complète, avec le label.
            'IdRef'                                 => 'uri',
            'appellationidref'                      => 'label',
            // Utiles pour la création des ressources auteurs.
            'Notebio-300'                           => 'info',
            'Notebio-340'                           => 'bio:biography',
            'datenaiss-103$a'                       => 'bio:birth',
            'datemort-103$b'                        => 'bio:death',
            'Source'                                => 'dcterms:bibliographicCitation',
        ],
    ],

    // Conversion en termes Rameau.
    'valuesuggest:idref:rameau' => [
        'file'    => 'thematiques',
        'headers' => [
        ],
    ],

    // Normalisation des dates.
    'dates' => [
        'file'    => 'dates',
        'headers' => [
            'Source'        => 'source',
        ],
    ],

    /**
     * Correspondances spécifiques Audio-video.
     */

    'dcterms:audience' => [
        'file'    => 'audiences',
    ],

    'manifestations' => [
        'file'    => 'manifestations',
        'headers' => [
            'Source'        => 'source',
        ],
    ],

    /**
     * Correspondances spécifiques Images.
     */

    'partie_images' => [
        'file'    => 'partie_images',
        'headers' => [
            'Source'        => 'source',
        ],
    ],

    /**
     * Correspondances spécifiques Livres anciens.
     */

    // Conversion des titres avec des tomes ou volumes pour les livres anciens.
    'titres_livres_anciens' => [
        'file'    => 'titres_livres_anciens',
        'headers' => [
            'Source'        => 'source',
        ],
    ],

    // Conversion des éditeurs en idref.
    'editeurs_sans_lieu' => [
        'file'    => 'editeurs_sans_lieu',
    ],

    /**
     * Correspondances spécifiques Études et recherche.
     */
];
