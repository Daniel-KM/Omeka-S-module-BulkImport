<?php declare(strict_types = 1);

namespace BulkImport\Processor;

use BulkImport\Form\Processor\SpipProcessorConfigForm;
use BulkImport\Form\Processor\SpipProcessorParamsForm;
use DateTime;
use Laminas\Validator\EmailAddress;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Stdlib\Message;

/**
 * Spip (Système de Publication pour un Internet Partagé)
 *
 * @link https://code.spip.net/autodoc
 * @link https://www.spip.net/aide/fr-aide.html
 */
class SpipProcessor extends AbstractFullProcessor
{
    protected $resourceLabel = 'Spip'; // @translate
    protected $configFormClass = SpipProcessorConfigForm::class;
    protected $paramsFormClass = SpipProcessorParamsForm::class;
    protected $moduleAreRequired = true;

    protected $configDefault = [
        'database' => null,
        'host' => null,
        'port' => null,
        'username' => null,
        'password' => null,
    ];

    protected $paramsDefault = [
        'o:owner' => null,
        'types' => [],
        'fake_files' => false,
        'menu' => false,
        'language' => null,
        'endpoint' => null,
    ];

    protected $requiredModules = [
        'AdvancedResourceTemplate',
        'Article',
        // 'Comment',
        'CustomVocab',
        'Log',
        'NumericDataTypes',
        'Spip',
    ];

    protected $optionalModules = [
        'BulkCheck',
        'BulkEdit',
        'DataTypeRdf',
        'Menu',
        'Thesaurus',
    ];

    protected $moreImportables = [
        'breves' => [
            'name' => 'items',
            'class' => \Omeka\Entity\Item::class,
            'table' => 'item',
            'fill' => 'fillBreve',
            'is_resource' => true,
        ],
        'auteurs' => [
            'name' => 'items',
            'class' => \Omeka\Entity\Item::class,
            'table' => 'item',
            'fill' => 'fillAuteur',
            'is_resource' => true,
        ],
        'groupes_mots' => [
            'name' => 'items',
            'class' => \Omeka\Entity\Item::class,
            'table' => 'item',
            'fill' => 'fillMotCleGroup',
            'is_resource' => true,
        ],
        'mots' => [
            'name' => 'items',
            'class' => \Omeka\Entity\Item::class,
            'table' => 'item',
            'fill' => 'fillMotCle',
            'is_resource' => true,
        ],
    ];

    protected $mapping = [
        'users' => [
            'source' => 'auteurs',
            'key_id' => 'id_auteur',
        ],
        // Les documents peuvent être des assets (avec quelques métadonnées) ou
        // des médias (mais sans item), ou un seul item avec toutes les images,
        // ou un nouveau type "advanced asset" qui serait un media non rattaché
        // à un item ?
        // Ici, on crée des items + media.
        'assets' => [
            'source' => null,
            'key_id' => 'id_document',
        ],
        'items' => [
            'source' => 'articles',
            'key_id' => 'id_article',
        ],
        'media' => [
            'source' => null,
            'key_id' => 'id_document',
        ],
        'media_items' => [
            'source' => 'documents',
            'key_id' => 'id_document',
        ],
        // TODO Convertir en item avec relations?
        'item_sets' => [
            'source' => 'albums',
            'key_id' => 'id_album',
        ],
        'vocabularies' => [
            'source' => null,
            'key_id' => 'id',
        ],
        'properties' => [
            'source' => null,
            'key_id' => 'id',
        ],
        'resource_classes' => [
            'source' => null,
            'key_id' => 'id',
        ],
        // Les modèles sont ceux des modules Article et Spip.
        'resource_templates' => [
            'source' => null,
            'key_id' => 'id',
        ],
        // Modules.
        'custom_vocabs' => [
            'source' => 'groupes_mots',
            'key_id' => 'id_groupe',
        ],
        'custom_vocab_keywords' => [
            'source' => 'mots',
            'key_id' => 'id_mot',
        ],
        'mappings' => [
            'source' => null,
            'key_id' => 'id',
        ],
        'mapping_markers' => [
            'source' => null,
            'key_id' => 'id',
        ],
        'concepts' => [
            'source' => 'rubriques',
            'key_id' => 'id_rubrique',
            'key_parent_id' => 'id_parent',
            'key_label' => 'titre',
            'key_definition' => 'descriptif',
            'key_scope_note' => 'texte',
            'key_created' => 'date',
            'key_modified' => 'maj',
            'narrowers_sort' => 'alpha',
        ],
        // Fiches mots-clés : mots + groupes_mots + mots_liens.
        'groupes_mots' => [
            'source' => 'groupes_mots',
            'key_id' => 'id_groupe',
            'key_parent_id' => null,
            'key_label' => 'titre',
            'key_definition' => 'descriptif',
            'key_scope_note' => 'texte',
            'key_created' => null,
            'key_modified' => 'maj',
            // De toute façon géré spécifiquement.
            'narrowers_sort' => 'alpha',
        ],
        'mots' => [
            'source' => 'mots',
            'key_id' => 'id_mot',
            'key_parent_id' => 'id_groupe',
            'key_label' => 'titre',
            'key_definition' => 'descriptif',
            'key_scope_note' => 'texte',
            'key_created' => null,
            'key_modified' => 'maj',
            'narrowers_sort' => 'alpha',
        ],
        // Module non géré actuellement.
        'comments' => [
            'source' => 'messages',
            'key_id' => 'id_message',
        ],
        // Données spécifiques de Spip (relations).
        // Brèves (items).
        'breves' => [
            'source' => 'breves',
            'key_id' => 'id_breve',
            // Filled later.
            // fabio:Micropost
            'resource_class_id' => null,
            'resource_template_id' => null,
        ],
        // Fiches auteurs (items) et relations avec les contenus.
        'auteurs' => [
            'source' => 'auteurs',
            'key_id' => 'id_auteur',
            // foaf:Person
            'resource_class_id' => 94,
            // Filled later.
            'resource_template_id' => null,
        ],
        'album_liens' => [
            'source' => 'album_liens',
            'key_id' => 'id_album',
        ],
        'documents_liens' => [
            'source' => 'documents_liens',
            'key_id' => 'id_document',
        ],
        'auteurs_liens' => [
            'source' => 'auteurs_liens',
            'key_id' => 'id_auteur',
        ],
        'mots_liens' => [
            'source' => 'mots_liens',
            'key_id' => 'id_mot',
        ],
    ];

    protected $main = [
        'article' => [
            'template' => 'Article éditorial',
            'class' => 'bibo:Article',
            'custom_vocab' => 'Article - Statuts',
        ],
        'concept' => [
            'template' => 'Thesaurus Concept',
            'class' => 'skos:Concept',
            'item' => null,
            'item_set' => null,
            'custom_vocab' => null,
        ],
        'groupe_mot' => [
            'template' => 'Thesaurus Concept',
            'class' => 'skos:Concept',
            'item' => null,
            'item_set' => null,
            'custom_vocab' => null,
        ],
        'mot' => [
            'template' => 'Thesaurus Concept',
            'class' => 'skos:Concept',
            'item' => null,
            'item_set' => null,
            'custom_vocab' => null,
        ],
        'breve' => [
            'template' => 'Brève',
            'class' => 'fabio:Micropost',
            'item_set' => null,
        ],
        'auteur' => [
            'template' => 'Auteur',
            'class' => 'foaf:Person',
            'item_set' => null,
            'custom_vocab' => 'Auteur - Statuts',
        ],
        'templates' => [
            'Auteur' => null,
            'Article' => null,
            // 'Actualité' => null,
            'Brève' => null,
            'Fichier' => null,
            'Thesaurus Concept' => null,
            'Thesaurus Scheme' => null,
        ],
        'classes' => [
            'bibo:Document' => null,
            'dctype:MovingImage' => null,
            'dctype:Sound' => null,
            'dctype:StillImage' => null,
            'dctype:Text' => null,
            'foaf:Person' => null,
            // 'fabio:NewsItem' => null,
            'fabio:Micropost' => null,
            'skos:Concept' => null,
            'skos:ConceptScheme' => null,
        ],
    ];

    /**
     * @var array
     */
    protected $mappingTypesToClasses = [
        '' => 'bibo:Document',
        'file' => 'bibo:Document',
        'video' => 'dctype:MovingImage',
        'audio' => 'dctype:Sound',
        'image' => 'dctype:StillImage',
        'text' => 'dctype:Text',
    ];

    protected $thesaurusConfigs = [
        'concepts' => [
            'label' => 'Thesaurus',
            'mapping_name' => 'concepts',
            'main_name' => 'concept',
        ],
        'groupes_mots' => [
            'label' => 'Mots-clés',
            'mapping_name' => 'groupes_mots',
            'main_name' => 'groupe_mot',
        ],
        'mots' => [
            'label' => 'Mots-clés',
            'mapping_name' => 'mots',
            'main_name' => 'mot',
        ],
    ];

    protected function preImport(): void
    {
        // TODO Remove these fixes.
        $args = $this->getParams();

        if (empty($args['types_selected'])) {
            $this->hasError = true;
            $this->logger->err(
                'The job cannot be restarted.' // @translate
            );
            return;
        }

        if (empty($args['types'])) {
            $this->hasError = true;
            $this->logger->err(
                'The job cannot be restarted. Restart import from the beginning.' // @translate
            );
            return;
        }

        foreach (['vocabularies', 'assets', 'resource_templates'] as $remove) {
            $key = array_search($remove, $args['types']);
            if ($key !== false) {
                unset($args['types'][$key]);
            }
        }

        // Import des articles.
        if (array_search('items', $args['types_selected']) !== false) {
            $args['types'][] = 'items';
            $args['types'] = array_unique($args['types']);
        }

        $endpoint = rtrim(trim($args['endpoint'] ?? ''), ' /');
        $args['endpoint'] = $endpoint ? $endpoint . '/' : '';

        $args['language'] = trim($args['language']);
        $args['language'] = empty($args['language'])
            ? null
            : $this->isoCode3letters($args['language']);

        $this->setParams($args);

        $this->thesaurusConfigs['concepts'] += $this->mapping['concepts'];
        $this->thesaurusConfigs['groupes_mots'] += $this->mapping['groupes_mots'];
        $this->thesaurusConfigs['mots'] += $this->mapping['mots'];

        $this->map['statuts'] = [
            'prepa' => 'en cours de rédaction',
            'prop' => 'proposé à l’évaluation',
            'publie' => 'publié en ligne',
            'refuse' => 'refusé',
            'poubelle' => 'à la poubelle',
        ];

        $this->map['statuts_auteur'] = [
            '0minirezo' => 'Mini réseau',
            '1comite' => 'Comité',
            '5poubelle' => 'Poubelle',
            '6forum' => 'Forum',
        ];

        $this->prepareInternalVocabularies();
    }

    protected function prepareUsers(): void
    {
        // In spip, emails, logins and names are not unique or can be empty…
        $validator = new EmailAddress();

        $sourceUsers = [];
        $emails = [];
        foreach ($this->prepareReader('users') as $auteur) {
            $auteur = array_map('trim', array_map('strval', $auteur));

            // Check email, since it should be well formatted and unique.
            $originalEmail = $auteur['email'];
            $email = mb_strtolower($auteur['email']);
            if (!strlen($email) || !$validator->isValid($auteur['email'])) {
                $cleanName = mb_strtolower(preg_replace('/[^\da-z]/i', '_', ($auteur['login'] ?: $auteur['nom'])));
                $email = $cleanName . '@spip.net';
                $auteur['email'] = $email;
                $this->logger->warn(
                    'The user "{name}" has no email or an invalid email, so "{email}" was attribued for login.', // @translate
                    ['name' => $auteur['login'] ?: $auteur['nom'], 'email' => $email]
                );
            }
            if (isset($emails[$email])) {
                $email = $auteur['id_auteur'] . '-' . $email;
                $auteur['email'] = $email;
                $this->logger->warn(
                    'The email "{email}" is not unique, so it was renamed too "{email2}".', // @translate
                    ['email' => $originalEmail, 'email2' => $email]
                );
            }
            $emails[$email] = $auteur['email'];

            $isActive = !empty($auteur['en_ligne']) && $auteur['en_ligne'] !== '0000-00-00 00:00:00';
            $role = $auteur['webmestre'] === 'non' ? ($isActive ? 'author' : 'guest') : 'editor';
            $userCreated = empty($auteur['maj']) ? null : DateTime::createFromFormat('Y-m-d H:i:s', $auteur['maj']);
            if ($userCreated) {
                $userCreated = $userCreated->format('Y-m-d H:i:s');
                $userModified = $userCreated;
            } else {
                $userCreated = $this->currentDateTimeFormatted;
                $userModified = null;
            }

            $sourceUsers[] = [
                'o:id' => $auteur['id_auteur'],
                'o:name' => $auteur['nom'] ?: ($auteur['login'] ?: $auteur['email']),
                'o:email' => $auteur['email'],
                'o:created' => [
                    '@value' => $userCreated,
                ],
                'o:modified' => $userModified ? [
                    '@value' => $userModified,
                ] : null,
                'o:role' => $role,
                'o:is_active' => $isActive,
                'o:settings' => [
                    'locale' => $auteur['lang'] ?: null,
                    'userprofile_bio' => $auteur['bio'] ?: null,
                    'userprofile_nom_site' => $auteur['nom_site'] ?: null,
                    'userprofile_url_site' => $auteur['url_site'] ?: null,
                    'userprofile_statut' => $auteur['statut'] ?: null,
                    'userprofile_en_ligne' => $isActive ? $auteur['en_ligne'] : null,
                    'userprofile_alea_actuel' => $auteur['alea_actuel'] ?: null,
                    'userprofile_alea_futur' => $auteur['alea_futur'] ?: null,
                    'userprofile_prefs' => $auteur['prefs'] ? unserialize($auteur['prefs']) : null,
                    'userprofile_source' => $auteur['source'] ?: null,
                    'userprofile_imessage	' => $auteur['imessage'] ?: null,
                    'userprofile_messagerie' => $auteur['messagerie'] ?: null,
                ],
            ];
        }

        $this->prepareUsersProcess($sourceUsers);
        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->refreshMainResources();
    }

    protected function prepareCustomVocabsInitialize(): void
    {
        $this->map['custom_vocabs'] = [];

        if (empty($this->modules['CustomVocab'])) {
            return;
        }

        $toImport = $this->getParam('types') ?: [];
        if (!in_array('custom_vocabs', $toImport)) {
            return;
        }

        $sourceCustomVocabs = [];
        // "id_groupe" et "titre" sont en doublon dans la table "mots", donc la
        // table est inutile actuellement, sauf pour avoir les vocabulaires
        // vides.
        foreach ($this->prepareReader('custom_vocabs') as $groupeMots) {
            $label = trim((string) $groupeMots['titre']);
            if (!strlen($label)) {
                $label = 'Groupe de mots #' . $groupeMots['id_groupe'];
            }
            $sourceCustomVocabs[$label] = [
                'o:id' => $groupeMots['id_groupe'],
                'o:label' => $label,
                'o:lang' => '',
                'o:terms' => '',
                'o:item_set' => null,
                'o:owner' => $this->owner,
            ];
        }

        // TODO Les mots sont sauvegardés séparément actuellement.
        $this->main['_mots'] = [];

        foreach ($this->prepareReader('custom_vocab_keywords') as $mot) {
            $keyword = trim((string) $mot['titre']);
            if (!strlen($keyword)) {
                continue;
            }
            if (!isset($sourceCustomVocabs[$mot['type']])) {
                $label = trim((string) $mot['type']);
                if (!strlen($label)) {
                    $label = 'Groupe de mots #' . $mot['id_groupe'];
                }
                $sourceCustomVocabs[$label] = [
                    'o:id' => $mot['id_groupe'],
                    'o:label' => $label,
                    'o:lang' => '',
                    'o:terms' => '',
                    'o:item_set' => null,
                    'o:owner' => $this->owner,
                ];
            }
            strlen($sourceCustomVocabs[$mot['type']]['o:terms'])
                ? $sourceCustomVocabs[$mot['type']]['o:terms'] .= "\n" . $keyword
                : $sourceCustomVocabs[$mot['type']]['o:terms'] = $keyword;

            $this->main['_mots'][$mot['id_mot']] = [
                'titre' => $keyword,
                'id_groupe' => $mot['id_groupe'],
            ];
        }

        $this->prepareCustomVocabsProcess($sourceCustomVocabs);
    }

    protected function prepareOthers(): void
    {
        parent::prepareOthers();

        $toImport = $this->getParam('types') ?: [];

        if (in_array('breves', $toImport)
            && $this->prepareImport('breves')
        ) {
            // TODO La colonne rang_lien n'est pas gérée actuellement (toujours 0 dans la base actuelle). La colonne vu non plus, mais inutile.
            $this->mapping['breves']['resource_class_id'] = $this->main['classes']['fabio:Micropost']->getId();
            $this->mapping['breves']['resource_template_id'] = $this->main['templates']['Brève']->getId();
            $this->prepareResources($this->prepareReader('breves'), 'breves');
            if ($this->isErrorOrStop()) {
                return;
            }

            $itemSet = $this->findOrCreateItemSet('Brèves');
            $this->main['breve']['item_set'] = $itemSet;
            $this->main['breve']['item_set_id'] = $itemSet->getId();
        }

        if (in_array('auteurs', $toImport)
            && $this->prepareImport('auteurs')
        ) {
            $this->mapping['auteurs']['resource_template_id'] = $this->main['templates']['Auteur']->getId();
            $this->prepareResources($this->prepareReader('auteurs'), 'auteurs');
            if ($this->isErrorOrStop()) {
                return;
            }

            $itemSet = $this->findOrCreateItemSet('Auteurs');
            $this->main['auteur']['item_set'] = $itemSet;
            $this->main['auteur']['item_set_id'] = $itemSet->getId();
        }

        if (in_array('mots', $toImport)
            && $this->prepareImport('groupes_mots')
            && $this->prepareImport('mots')
        ) {
            $this->configThesaurus = 'groupes_mots';
            $this->prepareThesaurus();
            if ($this->isErrorOrStop()) {
                return;
            }

            $this->configThesaurus = 'groupes_mots';
            $this->prepareImport('groupes_mots');
            $this->prepareConcepts($this->prepareReader('groupes_mots'));
            if ($this->isErrorOrStop()) {
                return;
            }

            // Un seul thésaurus pour les deux tables.
            $this->main['mot']['item'] = $this->main['groupe_mot']['item'];
            $this->main['mot']['item_id'] = $this->main['groupe_mot']['item_id'];
            $this->main['mot']['item_set'] = $this->main['groupe_mot']['item_set'];
            $this->main['mot']['item_set_id'] = $this->main['groupe_mot']['item_set_id'];
            $this->main['mot']['custom_vocab'] = $this->main['groupe_mot']['custom_vocab'];
            $this->main['mot']['custom_vocab_id'] = $this->main['groupe_mot']['custom_vocab_id'];
            $this->map['mots'] = [];
            $this->thesaurus['mots']['tops'] = [];
            $this->thesaurus['mots']['parents'] = [];
            $this->thesaurus['mots']['narrowers'] = [];

            $this->configThesaurus = 'mots';
            $this->prepareImport('mots');
            $this->prepareConcepts($this->prepareReader('mots'));
            if ($this->isErrorOrStop()) {
                return;
            }
        }
    }

    /**
     * Tri special pour Spip.
     *
     * Ne pas supprimer les numéros initiaux des rubriques avant le tri !
     * Mais supprimer quand même le <multi> et le numéro qui le suit pour en
     * conserver un s'il y en a un ou deux.
     */
    protected function labelKeyForSort($labelKey, $id): string
    {
        return sprintf('%s#%s', preg_replace('~^(\s*\d+\.\s*)(\s*\d+\.\s*)~', '$2', trim(str_replace(['<multi>', '  '], ['', ' '], $labelKey))), $id);
    }

    protected function fillItem(array $source): void
    {
        if (!empty($source['id_article'])) {
            $this->fillArticle($source);
        }
    }

    /**
     * La ressource Spip Article est convertie en item.
     *
     * La précédente version utilisait les médias data html/article pour les
     * champs sur-titre, sous-titre, chapeau, texte et post-scriptum.
     *
     * @param array $source
     */
    protected function fillArticle(array $source): void
    {
        /*
        // Non géré actuellement.
        [
            'export', // ?
            // Module statistique.
            'visites',
            'referers',
            'popularite',
            // Inutile : Module comment
            'accepter_forum',
            // Inutile.
            'langue_choisie',
        ];
         */

        $source = array_map('trim', array_map('strval', $source));

        // Le titre est obligatoire pour le modèle, mais peut être vide dans Spip.
        if (!mb_strlen($source['titre'])) {
            $source['titre'] = sprintf($this->translator->translate('[Untitled article #%s]'), $source['id_article']); // @translate
        }

        // Ne pas supprimer les numéros initiaux des rubriques avant le tri !
        // Tâche ajoutée séparément post-import.
        // $source['titre'] = preg_replace('~^(\d+\.\s+)(\d+\.\s+)~', '', $source['titre']);

        $titles = $this->polyglotte($source['titre']);
        $title = reset($titles);

        $status = $this->map['statuts'][$source['statut']] ?? $source['statut'];
        $isPublic = $source['statut'] === 'publie';
        $createdDate = $this->getSqlDateTime($source['date']) ?? $this->currentDateTime;
        $majDate = $this->getSqlDateTime($source['maj']);

        $language = empty($source['lang'])
            ? $this->params['language']
            : $this->isoCode3letters($source['lang']);

        $values = [];

        // Omeka entities are not fluid.
        /* @var \Omeka\Entity\Item */
        $this->entity->setOwner($this->owner);
        $this->entity->setResourceClass($this->main['article']['class']);
        $this->entity->setResourceTemplate($this->main['article']['template']);
        $this->entity->setTitle($title);
        $this->entity->setIsPublic($isPublic);
        $this->entity->setCreated($createdDate);
        if ($majDate) {
            $this->entity->setModified($majDate);
        }

        // Cf. module Article.

        $fromTo = [
            'surtitre' => 'dcterms:coverage',
            'titre' => 'dcterms:title',
            'soustitre' => 'dcterms:alternative',
            // Néanmoins, la langue est utilisée directement dans les valeurs.
            'lang' => 'dcterms:language',
        ];
        foreach ($fromTo as $sourceName => $term) {
            if (!strlen($source[$sourceName])) {
                continue;
            }
            foreach ($this->polyglotte($source[$sourceName]) as $lang => $value) {
                $values[] = [
                    'term' => $term,
                    'lang' => $lang ? $this->isoCode3letters($lang) : $language,
                    'value' => $value,
                ];
            }
        }

        // Tout le contenu est converti en html + shortcodes.
        $fromTo = [
            'descriptif' => 'bibo:shortDescription',
            'chapo' => 'article:prescript',
            'texte' => 'bibo:content',
            'ps' => 'article:postscript',
        ];
        foreach ($fromTo as $sourceName => $term) {
            if (!strlen($source[$sourceName])) {
                continue;
            }
            foreach ($this->polyglotte($source[$sourceName]) as $lang => $value) {
                $value = $this->majModelesSpip($value);
                $value = $this->majLiensSourceSpip($value);
                $value = $this->majFichiersSpip($value);
                $values[] = [
                    'term' => $term,
                    'lang' => $lang ? $this->isoCode3letters($lang) : $language,
                    'value' => $value,
                    'type' => 'spip',
                ];
            }
        }

        // Le secteur est la rubrique de tête = "module" = lieux & acteurs, thèmes & disciplines, etc.
        // Le secteur est enregistré dans curation:theme et la rubrique dans curation:categorie.
        // Cf. fillBreve().
        foreach ([
            'id_secteur' => 'curation:theme',
            'id_rubrique' => 'curation:category',
        ] as $keyId => $mainTerm) {
            $value = (int) $source[$keyId];
            if (!$value) {
                continue;
            }
            // Le concept (numéro) est conservé même si vide, mais en privé.
            if (empty($this->map['concepts'][$value])) {
                $values[] = [
                    'term' => $mainTerm,
                    'type' => 'literal',
                    'lang' => null,
                    'value' => $value,
                    'is_public' => false,
                ];
            } else {
                $linkedResource = $this->entityManager->find(\Omeka\Entity\Item::class, $this->map['concepts'][$value]);
                $values[] = [
                    'term' => $mainTerm,
                    'type' => 'resource:item',
                    'value_resource' => $linkedResource,
                ];
            }
        }

        if ($status) {
            $values[] = [
                'term' => 'bibo:status',
                'type' => 'customvocab:' . $this->main['article']['custom_vocab_id'],
                'value' => $status,
                'is_public' => false,
            ];
        }

        // TODO Conserver toutes les dates en valeur ?
        // Généralement vide. Utilisé pour les articles préalablement migrés dans Spip.
        $redacDate = $this->getSqlDateTime($source['date_redac']);
        if ($redacDate) {
            $values[] = [
                'term' => 'dcterms:dateSubmitted',
                'value' => $this->literalFullDateOrDateTime($redacDate),
                'type' => 'numeric:timestamp',
            ];
        }
        if ($createdDate) {
            $values[] = [
                'term' => 'dcterms:created',
                'value' => $this->literalFullDateOrDateTime($createdDate),
                'type' => 'numeric:timestamp',
            ];
        }
        $modifiedDate = $this->getSqlDateTime($source['date_modif']);
        if ($modifiedDate) {
            $values[] = [
                'term' => 'dcterms:modified',
                'value' => $this->literalFullDateOrDateTime($modifiedDate),
                'type' => 'numeric:timestamp',
            ];
        }
        if ($majDate) {
            $values[] = [
                'term' => 'dcterms:dateAccepted',
                'value' => $this->literalFullDateOrDateTime($majDate),
                'type' => 'numeric:timestamp',
            ];
        }

        if ($source['url_site']) {
            $values[] = [
                'term' => 'dcterms:references',
                'type' => 'uri',
                '@id' => $source['url_site'],
                '@label' => $source['nom_site'],
            ];
        } elseif ($source['nom_site']) {
            $values[] = [
                'term' => 'dcterms:references',
                'type' => 'literal',
                'value' => $source['nom_site'],
            ];
        }

        // Un article virtuel peut être un article externe ou interne.
        if ($source['virtuel']) {
            if ($this->bulk->isUrl($source['virtuel'])) {
                $values[] = [
                    'term' => 'bibo:uri',
                    'type' => 'uri',
                    '@id' => $source['virtuel'],
                    '@label' => '',
                ];
            } else {
                $mapped = $this->relatedLink($source['virtuel']);
                if ($mapped) {
                    $values[] = [
                        'term' => 'bibo:uri',
                        'type' => $mapped['type'],
                        'value_resource' => $mapped['value_resource'],
                    ];
                } else {
                    $this->logger->warn(
                        'The virtuel resource #{identifier} of item #{item_id} (source #{source_id}) was not found.', // @translate
                        ['identifier' => $source['virtuel'], 'item_id' => $this->entity->getId(), 'source_id' => $source['id_article']]
                    );
                }
            }
        }

        $value = (int) $source['id_trad'];
        if ($value) {
            if (empty($this->map['items'][$value])) {
                $this->logger->warn(
                    'The translation #{id_trad} of item #{item_id} (source {source}) was not found.', // @translate
                    ['id_trad' => $value, 'item_id' => $this->entity->getId(), 'source' => $source['id_article']]
                );
            } else {
                $linkedResource = $this->entityManager->find(\Omeka\Entity\Item::class, $this->map['items'][$value]);
                $values[] = [
                    // Impossible de déterminer laquelle est la traduction de l'autre.
                    'term' => 'bibo:translationOf',
                    'type' => 'resource:item',
                    'value_resource' => $linkedResource,
                ];
            }
        }

        $this->orderAndAppendValues($values);
    }

    /**
     * La ressource Spip Document est convertie en item + media.
     *
     * Au cas où la ressource est attachée à un article, le media y est attaché
     * directement (à l'item correspondant). S'il est ajouté en tant qu'illustration,
     * les assets d'Omeka ne sont pas utilisés afin de conserver les quelques
     * trois métadonnées (titre, descriptif, droits).
     *
     * @link https://www.spip.net/fr_article5632.html
     *
     * @param array $source
     */
    protected function fillMediaItem(array $source): void
    {
        $source = array_map('trim', array_map('strval', $source));

        // L'item et le media contiennent les mêmes métadonnées.

        // Attention: les médias sont déplacés dans un second temps via la table documents_liens.
        // TODO Le document attaché à un album est un asset, mais avec quelques métadonnées.

        /*
         media   // file / image / video / audio ==> inutile
         mode   // document / image / vignette => vignette supprimée ; inutile
         distant    // oui/non // Inutile car url présente
         brise  // lien brisé
         */

        // Le titre est obligatoire pour le modèle, mais peut être vide dans Spip.
        if (!mb_strlen($source['titre'])) {
            $source['titre'] = sprintf($this->translator->translate('[Untitled document #%s]'), $source['id_document']); // @translate
        }
        $titles = $this->polyglotte($source['titre']);
        $title = reset($titles);

        /** @var \Omeka\Entity\Media $media */
        $media = $this->entityManager
            ->find(\Omeka\Entity\Media::class, $this->map['media_items_sub'][$source[$this->mapping['media_items']['key_id']]]);

        if ('mode' === 'vignette') {
            $this->entityManager->remove($media->getItem());
            $this->entityManager->remove($media);
            $this->map['media_items'][$source['id_document']] = null;
            $this->map['media_items_sub'][$source['id_document']] = null;
            $this->entity = null;
            $this->logger->warn(
                'The document #{identifier} is a simple thumbnail and was excluded.', // @translate
                ['identifier' => $source['id_document']]
            );
            return;
        }

        $class = $this->mappingTypesToClasses[$source['media']] ?? 'bibo:Document';

        $status = $this->map['statuts'][$source['statut']] ?? $source['statut'];
        $isPublic = $source['statut'] === 'publie';
        $createdDate = $this->getSqlDateTime($source['date']) ?? $this->currentDateTime;
        $majDate = $this->getSqlDateTime($source['maj']);

        $isUrl = $this->bulk->isUrl($source['fichier']);

        // Omeka entities are not fluid.
        /* @var \Omeka\Entity\Item */
        $this->entity->setOwner($this->owner);
        $this->entity->setResourceClass($this->main['classes'][$class]);
        $this->entity->setResourceTemplate($this->main['templates']['Fichier']);
        $this->entity->setTitle($title);
        $this->entity->setIsPublic($isPublic);
        $this->entity->setCreated($createdDate);
        if ($majDate) {
            $this->entity->setModified($majDate);
        }

        $mediaData = [];
        if ((int) $source['largeur'] || (int) $source['duree']) {
            if ((int) $source['largeur']) {
                $mediaData['dimensions']['original']['width'] = (int) $source['largeur'];
                $mediaData['dimensions']['original']['height'] = (int) $source['hauteur'];
            }
            if ((int) $source['duree']) {
                $mediaData['dimensions']['original']['duration'] = (int) $source['duree'];
            }
        }

        $media->setOwner($this->entity->getOwner());
        $media->setItem($this->entity);
        $media->setResourceClass($this->main['classes'][$class]);
        $media->setResourceTemplate($this->main['templates']['Fichier']);
        $media->setTitle($title);
        $media->setIsPublic($isPublic);
        $media->setIngester($isUrl ? 'url' : 'upload');
        $media->setRenderer('file');
        $media->setData($mediaData);
        $media->setSource($source['fichier']);
        $media->setPosition(1);
        $media->setCreated($createdDate);
        if ($majDate) {
            $media->setModified($majDate);
        }
        if ((int) $source['taille']) {
            $media->setSize($source['taille']);
        }

        // TODO Keep the original storage id of assets (so check existing one as a whole).
        /*
        if ($isUrl) {
            $ingester = 'url';
            $url = parse_url($source['fichier'], PHP_URL_PATH);
            $storageId = pathinfo($url, PATHINFO_FILENAME);
            $extension = pathinfo($url, PATHINFO_EXTENSION);
        } else {
            // Ou "sideload" (source ftp).
            $ingester = 'upload';
            $storageId = pathinfo($source['fichier'], PATHINFO_FILENAME);
            $extension = pathinfo($source['fichier'], PATHINFO_EXTENSION);
        }
        $n = 1;
        $baseStorageId = $storageId;
        while (isset($this->storageIds[$storageId])) {
            $storageId = $baseStorageId . '-' . ++$n;
        }
        $this->storageIds[$storageId] = true;
         */
        if ($source['fichier']) {
            // @see \Omeka\File\TempFile::getStorageId()
            $storageId = bin2hex(\Laminas\Math\Rand::getBytes(20));
            $extension = $source['extension'];
            $sourceFile = '';
            if ($isUrl) {
                $sourceFile = $source['fichier'];
            } elseif ($endpoint = $this->getParam('endpoint')) {
                // Spip place tous les fichiers originaux dans un dossier.
                $sourceFile = $endpoint . 'IMG/' . $source['fichier'];
            }
            if ($sourceFile) {
                $result = $this->fetchUrl('original', $source['fichier'], $source['fichier'], $storageId, $extension, $sourceFile);
                if ($result['status'] !== 'success') {
                    $this->logger->err($result['message']);
                }
                // Continue in order to update other metadata, in particular item.
                else {
                    $media->setStorageId($storageId);
                    $media->setExtension(mb_strtolower($extension));
                    $media->setSha256($result['data']['sha256']);
                    $media->setMediaType($result['data']['media_type']);
                    $media->setHasOriginal(true);
                    $media->setHasThumbnails($result['data']['has_thumbnails']);
                    $media->setSize($result['data']['size']);
                }
            }
        }

        $language = $this->params['language'];

        foreach ([$this->entity, $media] as $document) {
            $values = [];

            $fromTo = [
                'titre' => 'dcterms:title',
                'descriptif' => 'bibo:shortDescription',
                'credits' => 'dcterms:rights',
            ];
            foreach ($fromTo as $sourceName => $term) {
                if (!strlen($source[$sourceName])) {
                    continue;
                }
                foreach ($this->polyglotte($source[$sourceName]) as $lang => $value) {
                    $values[] = [
                        'term' => $term,
                        'lang' => $lang ? $this->isoCode3letters($lang) : $language,
                        'value' => $value,
                        'is_public' => $sourceName !== 'descriptif',
                    ];
                }
            }

            if ($status) {
                $values[] = [
                    'term' => 'bibo:status',
                    'type' => 'customvocab:' . $this->main['article']['custom_vocab_id'],
                    'value' => $status,
                    'is_public' => false,
                ];
            }

            if ($createdDate) {
                $values[] = [
                    'term' => 'dcterms:created',
                    'value' => $this->literalFullDateOrDateTime($createdDate),
                    'type' => 'numeric:timestamp',
                ];
            }
            if ($majDate) {
                $values[] = [
                    'term' => 'dcterms:dateAccepted',
                    'value' => $this->literalFullDateOrDateTime($majDate),
                    'type' => 'numeric:timestamp',
                ];
            }
            $publicationDate = $this->getSqlDateTime($source['date_publication']);
            if ($publicationDate) {
                $values[] = [
                    'term' => 'dcterms:issued',
                    'value' => $this->literalFullDateOrDateTime($publicationDate),
                    'type' => 'numeric:timestamp',
                ];
            }

            $this->orderAndAppendValues($values, $document);

            $this->entityManager->persist($document);
        }
    }

    protected function fillItemSet(array $source): void
    {
        // FIXME Convertir les albums en item avec relation ?
        $source = array_map('trim', array_map('strval', $source));
        if (!mb_strlen($source['titre'])) {
            $source['titre'] = sprintf($this->translator->translate('[Untitled album #%s]'), $source['id_album']); // @translate
        }
        $titles = $this->polyglotte($source['titre']);
        $title = reset($titles);

        $status = $this->map['statuts'][$source['statut']] ?? $source['statut'];
        $isPublic = $source['statut'] === 'publie';
        $createdDate = $this->getSqlDateTime($source['date']) ?? $this->currentDateTime;
        $majDate = $this->getSqlDateTime($source['maj']);

        // Omeka entities are not fluid.
        /* @var \Omeka\Entity\ItemSet */
        $this->entity->setOwner($this->owner);
        $this->entity->setTitle($title);
        $this->entity->setIsPublic($isPublic);
        $this->entity->setCreated($createdDate);
        if ($majDate) {
            $this->entity->setModified($majDate);
        }
        $this->entity->setIsOpen(true);

        $values = [];

        $language = $this->params['language'];

        $fromTo = [
            'titre' => 'dcterms:title',
            'descriptif' => 'bibo:shortDescription',
            'lang' => 'dcterms:language',
        ];
        foreach ($fromTo as $sourceName => $term) {
            if (!strlen($source[$sourceName])) {
                continue;
            }
            foreach ($this->polyglotte($source[$sourceName]) as $lang => $value) {
                $values[] = [
                    'term' => $term,
                    'lang' => $lang ? $this->isoCode3letters($lang) : $language,
                    'value' => $value,
                ];
            }
        }

        if ($status) {
            $values[] = [
                'term' => 'bibo:status',
                'type' => 'customvocab:' . $this->main['article']['custom_vocab_id'],
                'value' => $status,
                'is_public' => false,
            ];
        }

        if ($createdDate) {
            $values[] = [
                'term' => 'dcterms:created',
                'value' => $this->literalFullDateOrDateTime($createdDate),
                'type' => 'numeric:timestamp',
            ];
        }
        if ($majDate) {
            $values[] = [
                'term' => 'dcterms:dateAccepted',
                'value' => $this->literalFullDateOrDateTime($majDate),
                'type' => 'numeric:timestamp',
            ];
        }

        $value = (int) $source['id_trad'];
        if ($value) {
            if (empty($this->map['item_sets'][$value])) {
                $this->logger->warn(
                    'The translation #{id_trad} of item set #{item_set_id} (source {source}) was not found.', // @translate
                    ['id_trad' => $value, 'item_set_id' => $this->entity->getId(), 'source' => $source['id_article']]
                );
            } else {
                $linkedResource = $this->entityManager->find(\Omeka\Entity\ItemSet::class, $this->map['items'][$value]);
                $values[] = [
                    'term' => 'bibo:translationOf',
                    'type' => 'resource:item',
                    'value_resource' => $linkedResource,
                ];
            }
        }

        $this->orderAndAppendValues($values);
    }

    /**
     * La resource spip "rubrique" est convertie en item Concept.
     */
    protected function fillConcept(array $source): void
    {
        // TODO Id secteur ?  Ce sont les 4 modules de tête.
        /*
         * Id secteur
         * statut_tmp
         * date_tmp
         * profondeur // Inutile
         */

        // rubrique
        /*
         * id_rubrique
         * id_parent
         * titre
         * descriptif
         * texte
         * id_secteur
         * maj
         * statut
         * date
         * lang
         * langue_choisie
         * statut_tmp
         * date_tmp
         * profondeur
         */

        $source = array_map('trim', array_map('strval', $source));

        // Ne pas supprimer les numéros initiaux des rubriques avant le tri !
        // Tâche ajoutée séparément post-import.
        // $source['titre'] = preg_replace('~^(\d+\.\s+)(\d+\.\s+)~', '', $source['titre']);

        parent::fillConceptProcess($source);

        // Au cas où le titre est traduit, il faut choisir le premier.
        $title = $this->polyglotte($source['titre'] ?: sprintf($this->translator->translate('[Untitled %s #%s]'), $this->thesaurusConfigs[$this->configThesaurus]['main_name'], $source['id_rubrique'])); // @translate;
        $this->entity->setTitle(reset($title));

        $status = $this->map['statuts'][$source['statut']] ?? $source['statut'];
        if ($status) {
            $this->appendValue([
                'term' => 'bibo:status',
                'type' => 'customvocab:' . $this->main['article']['custom_vocab_id'],
                'value' => $status,
                'is_public' => false,
            ]);
        }
        $isPublic = $source['statut'] === 'publie';
        $this->entity->setIsPublic($isPublic);
    }

    protected function fillOthers(): void
    {
        parent::fillOthers();

        $toImport = $this->getParam('types') ?: [];

        // Les relations sont importées séparément, car elles se trouvent dans
        // des tables séparées et que le lecteur sql ne permet pas de gérer
        // plusieurs requêtes en parallèle.
        // TODO Faire en sorte que le lecteur sql gère plusieurs requêtes en parallèle.

        $this->logger->info(
            'Import des autres données et des relations.' // @translate
        );

        if (in_array('breves', $toImport)
            && $this->prepareImport('breves')
        ) {
            $this->fillResources($this->prepareReader('breves'), 'breves');
            if ($this->isErrorOrStop()) {
                return;
            }
        }

        if (in_array('item_sets', $toImport)
            && $this->prepareImport('albums_liens')
        ) {
            foreach ($this->prepareReader('albums_liens') as $source) {
                $this->fillAlbumLien($source);
            }
            if ($this->isErrorOrStop()) {
                return;
            }
        }

        if (in_array('media_items', $toImport)
            && $this->prepareImport('documents_liens')
        ) {
            // TODO La colonne rang_lien n'est pas gérée actuellement (toujours 0 dans la base actuelle). La colonne vu non plus, mais inutile.
            foreach ($this->prepareReader('documents_liens') as $source) {
                $this->fillDocumentLien($source);
            }
            if ($this->isErrorOrStop()) {
                return;
            }
        }

        if (in_array('auteurs', $toImport)
            && $this->prepareImport('auteurs')
        ) {
            $this->fillResources($this->prepareReader('auteurs'), 'auteurs');
            if ($this->isErrorOrStop()) {
                return;
            }

            // Ajout des relations.
            if ($this->prepareImport('auteurs_liens')) {
                foreach ($this->prepareReader('auteurs_liens') as $source) {
                    $this->fillAuteurLien($source);
                }
                if ($this->isErrorOrStop()) {
                    return;
                }
            }
        }

        if (in_array('mots', $toImport)
            && $this->prepareImport('groupes_mots')
            && $this->prepareImport('mots')
        ) {
            $this->configThesaurus = 'groupes_mots';
            $this->prepareImport('groupes_mots');
            $this->fillConcepts();
            if ($this->isErrorOrStop()) {
                return;
            }

            $this->configThesaurus = 'mots';
            $this->prepareImport('mots');
            $this->fillConcepts();
            if ($this->isErrorOrStop()) {
                return;
            }
            // Les relations sont ajoutées ci-dessous.
        }

        // Ajout des mots-clés via relation et custom vocab.
        if ($this->prepareImport('mots_liens')) {
            foreach ($this->prepareReader('mots_liens') as $source) {
                $this->fillMotCleLien($source);
            }
            if ($this->isErrorOrStop()) {
                return;
            }
        }
    }

    protected function fillAuteur(array $source): void
    {
        // Il est plus simple de remplir l'item auteur directement.
        $source = array_map('trim', array_map('strval', $source));

        $title = $source['nom'];
        $isPublic = !empty($source['statut']) && $source['statut'] !== '5poubelle';
        $createdDate = empty($source['maj']) ? null : DateTime::createFromFormat('Y-m-d H:i:s', $source['maj']);

        // Récupére le propriétaire de sa propre notice.
        if (!empty($this->map['users'][$source['id_auteur']])) {
            $ownerAuteur = $this->entityManager->find(\Omeka\Entity\User::class, $this->map['users'][$source['id_auteur']]);
        }

        $values = [];

        // Omeka entities are not fluid.
        /* @var \Omeka\Entity\Item */
        $this->entity->setOwner($ownerAuteur ?? $this->owner);
        $this->entity->setResourceClass($this->main['classes']['foaf:Person']);
        $this->entity->setResourceTemplate($this->main['templates']['Auteur']);
        $this->entity->setTitle($title);
        $this->entity->setIsPublic($isPublic);
        $this->entity->setCreated($createdDate) ?: $this->currentDateTime;
        if ($createdDate) {
            $this->entity->setModified($createdDate);
        }

        $itemSets = $this->entity->getItemSets();
        $itemSets->add($this->main['auteur']['item_set']);

        // Essai de distinguer le prénom et le nom.
        // Le nom est toujours en majuscule dans la version importée.
        $matches = [];
        $nom = $source['nom'];
        if (mb_strpos($nom, ' ') !== false && preg_match('~^(.*?)([A-ZÉ]{2}[\w -]*)$~mu', $nom, $matches)) {
            $last = $matches[2];
            $first = $matches[1];
        } else {
            $last = $source['nom'];
            $first = '';
        }

        if (empty($last)) {
            $last = $source['login'] ?: '[Inconnu]';
            $this->logger->warn(
                'The user #"{source_id}" has no name, so the login is used.', // @translate
                ['source_id' => $source['id_auteur']]
            );
        }
        $values[] = [
            'term' => 'foaf:name',
            'value' => $nom,
        ];
        $values[] = [
            'term' => 'foaf:familyName',
            'value' => $last,
        ];
        if ($first) {
            $values[] = [
                'term' => 'foaf:givenName',
                'value' => $first,
            ];
        }

        $fromTo = [
            'email' => 'foaf:mbox',
            'lang' => 'dcterms:language',
        ];
        foreach ($fromTo as $sourceName => $term) {
            if (strlen($source[$sourceName])) {
                $values[] = [
                    'term' => $term,
                    'value' => $source[$sourceName],
                ];
            }
        }

        if (strlen($source['bio'])) {
            foreach ($this->polyglotte($source['bio']) as $lang => $value) {
                $values[] = [
                    'term' => 'bio:biography',
                    'lang' => $lang ? $this->isoCode3letters($lang) : $this->params['language'],
                    'value' => $value,
                    'type' => 'spip',
                ];
            }
        }

        // dcterms:references ou foaf:homepage ?
        if ($source['url_site']) {
            $values[] = [
                'term' => 'dcterms:references',
                'type' => 'uri',
                '@id' => $source['url_site'],
                '@label' => $source['nom_site'],
            ];
        } elseif ($source['nom_site']) {
            $values[] = [
                'term' => 'dcterms:references',
                'type' => 'literal',
                'value' => $source['nom_site'],
            ];
        }

        $values[] = [
            'term' => 'menestrel:role',
            'type' => 'literal',
            'value' => 'Rédacteur',
        ];

        $status = $this->map['statuts_auteur'][$source['statut']] ?? $source['statut'];
        if ($status) {
            $values[] = [
                'term' => ' foaf:membershipClass',
                'type' => 'customvocab:' . $this->main['auteur']['custom_vocab_id'],
                'value' => $status,
                'is_public' => false,
            ];
        }

        $this->orderAndAppendValues($values);
    }

    protected function fillBreve(array $source): void
    {
        /*
        // Non géré actuellement.
        [
            // Inutile.
            langue_choisie
        ];
         */

        $source = array_map('trim', array_map('strval', $source));

        // Le titre est obligatoire pour le modèle, mais peut être vide dans Spip.
        if (!mb_strlen($source['titre'])) {
            $source['titre'] = sprintf($this->translator->translate('[Untitled post #%s]'), $source['id_breve']); // @translate
        }
        $titles = $this->polyglotte($source['titre']);
        $title = reset($titles);

        $status = $this->map['statuts'][$source['statut']] ?? $source['statut'];
        $isPublic = $source['statut'] === 'publie';
        $createdDate = $this->getSqlDateTime($source['date_heure']) ?? $this->currentDateTime;
        $majDate = $this->getSqlDateTime($source['maj']);

        $language = empty($source['lang'])
            ? $this->params['language']
            : $this->isoCode3letters($source['lang']);

        $values = [];

        // Omeka entities are not fluid.
        /* @var \Omeka\Entity\Item */
        $this->entity->setOwner($this->owner);
        $this->entity->setResourceClass($this->main['breve']['class']);
        $this->entity->setResourceTemplate($this->main['breve']['template']);
        $this->entity->setTitle($title);
        $this->entity->setIsPublic($isPublic);
        $this->entity->setCreated($createdDate);
        if ($majDate) {
            $this->entity->setModified($majDate);
        }

        // Cf. module Article.
        $fromTo = [
            'titre' => 'dcterms:title',
            'texte' => 'bibo:content',
            // La langue est utilisée directement dans les valeurs.
            // 'lang' => 'dcterms:language',
        ];
        foreach ($fromTo as $sourceName => $term) {
            if (!strlen($source[$sourceName])) {
                continue;
            }
            foreach ($this->polyglotte($source[$sourceName]) as $lang => $value) {
                $values[] = [
                    'term' => $term,
                    'lang' => $lang ? $this->isoCode3letters($lang) : $language,
                    'value' => $value,
                ];
            }
        }

        // Le secteur est la rubrique de tête = "module" = lieux & acteurs, thèmes & disciplines, etc.
        // Le secteur est enregistré dans curation:theme et la rubrique dans curation:categorie.
        // Cf. fillArticle().
        foreach ([
            'id_secteur' => 'curation:theme',
            'id_rubrique' => 'curation:category',
        ] as $keyId => $mainTerm) {
            $value = (int) $source[$keyId];
            if (!$value) {
                continue;
            }
            // Le concept (numéro) est conservé même si vide, mais en privé.
            if (empty($this->map['concepts'][$value])) {
                $values[] = [
                    'term' => $mainTerm,
                    'type' => 'literal',
                    'lang' => null,
                    'value' => $value,
                    'is_public' => false,
                ];
            } else {
                $linkedResource = $this->entityManager->find(\Omeka\Entity\Item::class, $this->map['concepts'][$value]);
                $values[] = [
                    'term' => $mainTerm,
                    'type' => 'resource:item',
                    'value_resource' => $linkedResource,
                ];
            }
        }

        if ($status) {
            $values[] = [
                'term' => 'bibo:status',
                'type' => 'customvocab:' . $this->main['article']['custom_vocab_id'],
                'value' => $status,
                'is_public' => false,
            ];
        }

        if ($createdDate) {
            $values[] = [
                'term' => 'dcterms:created',
                'value' => $this->literalFullDateOrDateTime($createdDate),
                'type' => 'numeric:timestamp',
            ];
        }
        $modifiedDate = $this->getSqlDateTime($source['maj']);
        if ($modifiedDate) {
            $values[] = [
                'term' => 'dcterms:modified',
                'value' => $this->literalFullDateOrDateTime($modifiedDate),
                'type' => 'numeric:timestamp',
            ];
        }

        if ($source['lien_url']) {
            $values[] = [
                'term' => 'dcterms:references',
                'type' => 'uri',
                '@id' => $source['lien_url'],
                '@label' => $source['lien_titre'],
            ];
        } elseif ($source['lien_titre']) {
            $values[] = [
                'term' => 'dcterms:references',
                'type' => 'literal',
                'value' => $source['lien_titre'],
            ];
        }

        $this->orderAndAppendValues($values);
    }

    /**
     * Compléter les items groupes de mots-clés (thésaurus).
     * Les groupes de mots sont des top-concepts. Les mots y sont liés.
     */
    protected function fillMotCleGroup(array $source): void
    {
        // groupes_mots
        /*
         * id_groupe
         * titre
         * descriptif
         * texte
         * unseul
         * obligatoire
         * tables_liees
         * minirezo
         * comite
         * forum
         * maj
         */

        $source = array_map('trim', array_map('strval', $source));

        // Rien de particulier : c'est la première partie du thésaurus des mots.
        parent::fillConceptProcess($source);
        $this->entity->setIsPublic(true);
    }

    /**
     * Compléter les items mots-clés (thésaurus).
     * Les groupes de mots sont des top-concepts. Les mots y sont liés.
     */
    protected function fillMotCle(array $source): void
    {
        // mots
        /*
         * id_mot
         * titre
         * descriptif
         * texte
         * id_groupe
         * type // Correspond au titre du groupe (groupes_mots)
         * maj
         */

        $source = array_map('trim', array_map('strval', $source));

        // Dans ce thésaurus, la table des parents est separée.
        parent::fillConceptProcess($source);
        $this->entity->setIsPublic(true);
    }

    protected function fillConceptProcessParent(array &$values, array $source, string $mappingName, string $mainName, $keyId, $keyParentId)
    {
        // Ici, les parents sont dans une autre table.
        if ($this->configThesaurus === 'mots') {
            $mappingName = 'groupes_mots';
        }

        // Comme trait pour "concepts" (rubriques) et "groupes_mots".
        // TODO Supprimer cette copie du trait.
        if ($keyParentId && $source[$keyParentId]) {
            $linked = $this->entityManager->find(\Omeka\Entity\Item::class, $this->map[$mappingName][$source[$keyParentId]]);
            if ($linked) {
                $values[] = [
                    'term' => 'skos:broader',
                    'type' => 'resource:item',
                    'value_resource' => $linked,
                ];
            } else {
                $this->logger->warn(
                    'The broader concept #{identifier} of items #{item_id} (source {main} {source}) was not found.', // @translate
                    ['identifier' => $source[$keyParentId], 'item_id' => $this->entity->getId(), 'main' => $mainName, 'source' => $source[$keyId]]
                );
            }
        } else {
            $values[] = [
                'term' => 'skos:topConceptOf',
                'type' => 'resource:item',
                'value_resource' => $this->main[$mainName]['item'],
            ];
        }
    }

    protected function fillConceptProcessNarrowers(array &$values, array $source, string $mappingName, string $mainName, $keyId, $keyParentId)
    {
        if ($this->configThesaurus === 'mots') {
            // Ici, pas d'enfants.
            return;
        }

        if ($this->configThesaurus === 'groupes_mots') {
            $mappingName = 'mots';
        }

        // Comme trait pour "concepts" (rubriques).
        // TODO Supprimer cette copie du trait.
        if (empty($this->thesaurus[$mappingName]['narrowers'][$source[$keyId]])) {
            return;
        }

        foreach ($this->thesaurus[$mappingName]['narrowers'][$source[$keyId]] as $value) {
            // A literal value when the narrower item does not exist.
            if (empty($this->map[$mappingName][$value])) {
                $values[] = [
                    'term' => 'skos:narrower',
                    'value' => $value,
                ];
            } else {
                $linked = $this->entityManager->find(\Omeka\Entity\Item::class, $this->map[$mappingName][$value]);
                $values[] = [
                    'term' => 'skos:narrower',
                    'type' => 'resource:item',
                    'value_resource' => $linked,
                ];
            }
        }
    }

    protected function fillAlbumLien(array $source): void
    {
        $data = $this->mapSourceLienObjet($source, 'item_sets', 'id_album', \Omeka\Entity\ItemSet::class);
        if (!$data) {
            return;
        }

        // L'objet lié est un contenu de la collection (album).
        $itemSets = $data['linked_resource']->getItemSets();
        $itemSets->add($data['resource']);
        // TODO Persist() est sans doute inutile ici.
        $this->entityManager->persist($data['linked_resource']);
    }

    protected function fillAuteurLien(array $source): void
    {
        $data = $this->mapSourceLienObjet($source, 'auteurs', 'id_auteur', \Omeka\Entity\Item::class);
        if (!$data) {
            return;
        }

        // L'auteur de la ressource liée est à la fois propriétaire et auteur.
        $user = $this->entityManager->getRepository(\Omeka\Entity\user::class)->find($this->map['users'][$source['id_auteur']]);
        if ($user) {
            $data['linked_resource']->setOwner($user);
        }
        $this->appendValue([
            'term' => 'dcterms:creator',
            'type' => 'resource:item',
            'value_resource' => $data['resource'],
        ], $data['linked_resource']);
        $this->entityManager->persist($data['linked_resource']);
    }

    protected function fillDocumentLien(array $source): void
    {
        // Un document est un item dans media_items et media_items_sub.
        // Par construction, l'item et le media sont quasi-identiques pour les
        // documents. L'item original du document est donc inutile quand on peut
        // rattacher le media à un autre item.
        $sourceId = $source['id_document'];
        $data = $this->mapSourceLienObjet($source, 'media_items_sub', 'id_document', \Omeka\Entity\Item::class);
        if (!$data) {
            return;
        }

        // Le document (fichier) lié est un media ou un item de la collection
        // (album) ou du contenu (article, article...).
        $media = $data['resource'];
        $item = $media->getItem();
        if ($data['linked_resource'] instanceof \Omeka\Entity\ItemSet) {
            // TODO Le document attaché à un album pourrait être un asset, mais avec quelques métadonnées.
            $itemSets = $item->getItemSets();
            $itemSets->add($data['linked_resource']);
            $this->entityManager->persist($data['resource']);
        } else {
            $media->setItem($data['linked_resource']);
            $this->entityManager->persist($media);
            $this->entityManager->remove($item);
            $this->map['media_items'][$sourceId] = $data['linked_resource']->getId();
        }
    }

    protected function fillMotCleLien(array $source): void
    {
        static $asCustomVocab;
        static $asResourceLink;

        if (is_null($asCustomVocab)) {
            $toImport = $this->getParam('types') ?: [];
            $asCustomVocab = in_array('custom_vocabs', $toImport);
            $asResourceLink = in_array('mots', $toImport);
        }

        if (!$asCustomVocab && !$asResourceLink) {
            return;
        }

        // mots_liens
        /*
         * id_mot
         * id_objet
         * objet // rubrique, article, etc.
         */

        $data = $this->mapSourceLienObjet($source, 'mots', 'id_mot', \Omeka\Entity\Item::class, true);
        if (!$data) {
            return;
        }

        if ($asCustomVocab
            && !empty($this->main['_mots'][$source['id_mot']])
            && !empty($this->map['custom_vocabs']['customvocab:' . $this->main['_mots'][$source['id_mot']]['id_groupe']])
        ) {
            $this->appendValue([
                'term' => 'dcterms:subject',
                'type' => $this->map['custom_vocabs']['customvocab:' . $this->main['_mots'][$source['id_mot']]['id_groupe']]['datatype'],
                '@value' => $this->main['_mots'][$source['id_mot']]['titre'],
            ], $data['linked_resource']);
        }

        if ($asResourceLink
            && !empty($data['resource'])
        ) {
            $this->appendValue([
                'term' => 'dcterms:subject',
                'type' => 'resource:item',
                'value_resource' => $data['resource'],
            ], $data['linked_resource']);
        }

        $this->entityManager->persist($data['linked_resource']);
    }

    /**
     * Récupère le sujet et l'objet d'une table de liens spip.
     * L'objet (ressource liée) est soit une collection, soit un contenu.
     */
    protected function mapSourceLienObjet(
        array $source,
        string $sourceMapType,
        string $sourceIdKey,
        string $sourceClass,
        ?bool $linkedResourceOnly = false
    ): ?array {
        // Pas de log spécifique : une table incomplète ne peut être récupérée.

        if (empty($source['id_objet'])
            || empty($source['objet'])
            || empty($source[$sourceIdKey])
            || empty($this->map[$sourceMapType][$source[$sourceIdKey]])
        ) {
            return null;
        }

        switch ($source['objet']) {
            case 'album':
                $linkedResourceMapType = 'item_sets';
                $linkedResourceClass = \Omeka\Entity\ItemSet::class;
                break;
            case 'article':
                $linkedResourceMapType = 'items';
                $linkedResourceClass = \Omeka\Entity\Item::class;
                break;
            case 'rubrique':
                $linkedResourceMapType = 'concepts';
                $linkedResourceClass = \Omeka\Entity\Item::class;
                break;
            case 'groupes_mots':
                $linkedResourceMapType = 'groupes_mots';
                $linkedResourceClass = \Omeka\Entity\Item::class;
                break;
            case 'mots':
                $linkedResourceMapType = 'mots';
                $linkedResourceClass = \Omeka\Entity\Item::class;
                break;
            case 'message':
                // TODO Gérer les messages (commentaires internes).
                return null;
            default:
                return null;
        }

        if (empty($this->map[$linkedResourceMapType][$source['id_objet']])) {
            return null;
        }

        $linkedResourceId = $this->map[$linkedResourceMapType][$source['id_objet']];
        $linkedResource = $this->entityManager->find($linkedResourceClass, $linkedResourceId);
        if (!$linkedResource) {
            return null;
        }

        $resourceId = $this->map[$sourceMapType][$source[$sourceIdKey]];
        $resource = $this->entityManager->find($sourceClass, $resourceId);
        if (!$resource && !$linkedResourceOnly) {
            return null;
        }

        return [
            'resource' => $resource,
            'linked_resource' => $linkedResource,
        ];
    }

    /**
     * @link https://www.spip.net/aide/fr-aide.html
     * @see spip/ecrire/inc/lien.php, fonction typer_raccourci().
     *
     * @param string $value Uniquement la partie url du lien.
     * @return array|null
     */
    protected function relatedLink($value): ?array
    {
        $matches = [];
        $regex = '/^\s*(\w*?)\s*(\d+)(\?(.*?))?(#([^\s]*))?\s*$/u';
        if (!preg_match($regex, (string) $value, $matches)) {
            return null;
        }

        $sourceId = $matches[2];
        if (empty($sourceId)) {
            return null;
        }

        $types = [
            '' => 'article',
            'article' => 'article',
            'art' => 'art',
            'breve' => 'breve',
            'brève' => 'breve',
            'br' => 'breve',
            'rubrique' => 'rubrique',
            'rub' => 'rubrique',
            'auteur' => 'auteur',
            'aut' => 'auteur',
            'document' => 'document',
            'doc' => 'document',
            'im' => 'document',
            'img' => 'document',
            'emb' => 'document',
        ];
        $type = (string) $matches[1];
        if (!isset($types[$type])) {
            return null;
        }

        $sourceType = $types[$type];
        switch ($sourceType) {
            case 'article':
                $mapType = 'items';
                $linkedResourceType = 'resource:item';
                break;
            case 'breve':
                $mapType = 'news';
                $linkedResourceType = 'resource:item';
                break;
            case 'rubrique':
                $mapType = 'concepts';
                $linkedResourceType = 'resource:item';
                break;
            case 'auteur':
                $mapType = 'auteurs';
                $linkedResourceType = 'resource:item';
                break;
            case 'document':
                // Le document peut être un item ou un media (media_items ou
                // media_items_sub) et dans certains cas (simple vignette), il
                // est supprimé. De plus, le media peut avoir été déplacé en
                // item/media.
                if (!empty($this->map['media_items'][$sourceId])) {
                    $mapType = 'media_items';
                    $linkedResourceType = 'resource:item';
                } elseif (!empty($this->map['items'][$sourceId])) {
                    $mapType = 'items';
                    $linkedResourceType = 'resource:item';
                } elseif (!empty($this->map['media_items_sub'][$sourceId])) {
                    $mapType = 'media_items_sub';
                    $linkedResourceType = 'resource:media';
                } elseif (!empty($this->map['media'][$sourceId])) {
                    $mapType = 'media';
                    $linkedResourceType = 'resource:media';
                } else {
                    return null;
                }
                break;
            default:
                return null;
        }

        if (empty($this->map[$mapType][$sourceId])) {
            return null;
        }

        $class = $linkedResourceType === 'resource:media'
            ? \Omeka\Entity\Media::class
            : \Omeka\Entity\Item::class;

        $linkedResourceId = $this->map[$mapType][$sourceId];
        $linkedResource = $this->entityManager->find($class, $linkedResourceId);
        if (empty($linkedResource)) {
            return null;
        }

        return [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'type' => $linkedResourceType,
            'value_resource_id' => $linkedResourceId,
            'value_resource' => $linkedResource,
        ];
    }

    protected function toArrayValue($value): array
    {
        return $this->polyglotte($value);
    }

    /**
     * Extrait la valeur dans toutes les langues.
     *
     * En l'absence de langue multiple, la chaine est renvoyée dans un tableau.
     *
     * @link https://code.spip.net/autodoc/tree/ecrire/inc/filtres.php.html#function_extraire_multi
     * @link https://code.spip.net/autodoc/tree/ecrire/base/abstract_sql.php.html#function_sql_multi
     */
    protected function polyglotte($value): array
    {
        $value = trim((string) $value);
        if (!$value) {
            return [$value];
        }

        // Corrige un bug dans certaines données sources.
        $value = str_replace(['<multi<'], ['<multi>'], $value);

        if (empty($value)
            || strpos($value, '<multi>') === false
            || strpos($value, '</multi>') === false
        ) {
            return [$value];
        }

        // Prendre la chaîne générale et insérer la partie de chaque bloc
        // par la traduction.
        // Adaptation de la fonction extraire_multi() de Spip.
        $matches = [];
        $extraireMulti = '@<multi>(.*?)</multi>@sS';
        if (!preg_match_all($extraireMulti, $value, $matches, PREG_SET_ORDER)) {
            return [$value];
        }

        $result = [];
        $strReplaces = [];
        foreach ($matches as $match) {
            $trads = $this->extraire_trads($match[1]);
            foreach ($trads as $lang => $trad) {
                $strReplaces[$lang][$match[0]] = $trad;
            }
        }
        foreach ($strReplaces as $lang => $strReplace) {
            $result[$lang] = str_replace(array_keys($strReplace), array_values($strReplace), $value);
        }
        return $result;
    }

    /**
     * Convertit le contenu d'une balise `<multi>` en un tableau
     *
     * @link https://git.spip.net/spip/spip
     * @see spip/ecrire/inc/filtres.php
     * @link https://code.spip.net/autodoc/tree/ecrire/inc/filtres.php.html#function_extraire_trads
     *
     * Exemple de blocs.
     * - `texte par défaut [fr] en français [en] in English`
     * - `en français [en] in English`
     *
     * @param string $bloc
     *     Le contenu intérieur d'un bloc multi
     * @return array [code de langue => texte]
     *     Peut retourner un code de langue vide, lorsqu'un texte par défaut est indiqué.
     **/
    protected function extraire_trads($bloc): array
    {
        $lang = '';
        $regs = [];
        // ce reg fait planter l'analyse multi s'il y a de l'{italique} dans le champ
        //	while (preg_match("/^(.*?)[{\[]([a-z_]+)[}\]]/siS", $bloc, $regs)) {
        while (preg_match("/^(.*?)[\[]([a-z_]+)[\]]/siS", $bloc, $regs)) {
            $texte = trim($regs[1]);
            if ($texte or $lang) {
                $trads[$lang] = $texte;
            }
            $bloc = substr($bloc, strlen($regs[0]));
            $lang = $regs[2];
        }
        $trads[$lang] = $bloc;

        return $trads;
    }

    /**
     * Convertit les noms et numéros des ressources des raccourcis spip.
     *
     * Par exemple `<img1|right>` devient `<image243|right>`.
     * Les règles spip ne sont pas appliquées, mais gérées par le type de
     * données "spip" ou converties via une tâche.
     *
     * Les liens sans relation sont conservés tels quels, mais avec trois zéros
     * devant l’identifiant pour pouvoir conserver les raccourcis originaux dans
     * les contenus.
     * "item", item_set", "collection", "media", "user", etc. ne sont pas
     * utilisés par défaut (hors les plugins éventuellement).
     *
     * C’est une adaptation de traiter_modeles() dans liens.
     *
     * @see https://code.spip.net/@traiter_modeles
     * @see https://www.spip.net/aide/?exec=aide_index&aide=raccourcis&frame=body&var_lang=fr
     * @see https://info.spip.net/la-mise-en-forme-des-contenus-dans
     */
    protected function majModelesSpip($texte): string
    {
        // Vérification rapide.
        if (strpos($texte, '<') === false) {
            return $texte;
        }

        $matches = [];
        $pattern = '~<(?<type>[a-z_-]{2,})\s*(?<id>[0-9]+)~iS';
        preg_match_all($pattern, $texte, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if (!$matches) {
            return $texte;
        }

        $normalizeds = [
            'art' => 'article',
            'article' => 'article',
            'album' => 'album',
            'br' => 'breve',
            'breve' => 'breve',
            'brève' => 'breve',
            'bréve' => 'breve',
            'brêve' => 'breve',
            'rub' => 'concept',
            'rubrique' => 'concept',
            'doc' => 'document',
            'document' => 'document',
            'im' => 'image',
            'img' => 'image',
            'image' => 'image',
            'emb' => 'embed',
            'embed' => 'embed',
            'aut' => 'auteur',
            'auteur' => 'auteur',
        ];

        $importeds = [
            'article' => 'items',
            'album' => 'item_sets',
            'breve' => 'breves',
            'rubrique' => 'concepts',
            'document' => 'media_items_sub',
            'image' => 'media_items_sub',
            'embed' => 'media_items_sub',
            'auteur' => 'auteurs',
        ];

        // Commence par le dernier pour éviter les problèmes de position dans la
        // nouvelle chaîne.
        foreach (array_reverse($matches) as $match) {
            $type = $match['type'][0];
            $id = $match['id'][0];
            $type = $normalizeds[$type] ?? $type;
            $importedType = $importeds[$type] ?? null;
            if (empty($this->map[$importedType][$id])) {
                $resourceId = '000' . $id;
            } else {
                $resourceId = $this->map[$importedType][$id];
            }
            $newResource = '<' . $type . $resourceId;
            $pos = $match[0][1];
            // Don't use "mb_" functions: preg_match_all() returns byte offsets.
            $texte = substr($texte, 0, $pos)
                . $newResource
                . substr($texte, $pos + strlen($match[0][0]));
        }

        return $texte;
    }

    /**
     * Convertit les liens du site source en raccourcis Omeka (shortcodes).
     *
     * Par exemple `http://www.menestrel.fr/ecrire/?exec=articles&id_article=2307` devient `[item id=2307]`.
     *
     * Les liens sans relation sont conservés tels quels.
     *
     * Gère les liens tels que :
     * - http://www.menestrel.fr/ecrire/?exec=articles&id_article=2307
     * - http://www.menestrel.fr/spip.php?rubrique378&lang=fr&art=en#128
     * - http://www.menestrel.fr/spip.php?article196#bulletin
     *
     * @todo Les urls suivantes ne sont pas traitées.
     * - http://www.menestrel.fr/?-croisade-et-guerre-sainte-
     *
     * Les urls vers les fichiers sont traitées séparément.
     *
     * @see https://www.spip.net/aide/?exec=aide_index&aide=raccourcis&frame=body&var_lang=fr
     * @see https://info.spip.net/la-mise-en-forme-des-contenus-dans
     */
    protected function majLiensSourceSpip($texte): string
    {
        static $endpoint;
        static $patterns;

        if ($endpoint === null) {
            $endpoint = $this->getParam('endpoint');
            $endpoint = trim(str_replace(['http://', 'https://'], ['', ''], $endpoint), ' /');
            if ($endpoint) {
                $patterns = [
                    '~(?<url>(?:https://|http://|//)?' . preg_quote($endpoint) . '/ecrire/\?exec=(?<type>article|album|breve|rubrique|document|image|embed|auteur)s&id_(?:article|album|breve|rubrique|document|image|embed|auteur)=(?<id>\d+)[\w&=#%-]*)\s*(?<raccourci>\])?~m',
                    '~(?<url>(?:https://|http://|//)?' . preg_quote($endpoint) . '/spip\.php\?(?<type>article|album|breve|rubrique|document|image|embed|auteur)(?<id>\d+)[\w&=#%-]*)\s*(?<raccourci>\])?~m',
                ];
            }
        }

        // Vérification rapide.
        if (!$endpoint || strpos($texte, $endpoint) === false) {
            return $texte;
        }

        $importeds = [
            'article' => 'items',
            'album' => 'item_sets',
            'breve' => 'breves',
            'rubrique' => 'concepts',
            'document' => 'media_items_sub',
            'image' => 'media_items_sub',
            'embed' => 'media_items_sub',
            'auteur' => 'auteurs',
        ];

        $maps = [
            'article' => 'items',
            'album' => 'item_sets',
            'breve' => 'items',
            'rubrique' => 'items',
            'document' => 'resources',
            'image' => 'resources',
            'embed' => 'resources',
            'auteur' => 'items',
        ];

        $mapSingles = [
            'article' => 'item',
            'album' => 'item_set',
            'breve' => 'item',
            'rubrique' => 'item',
            'document' => 'resource',
            'image' => 'resource',
            'embed' => 'resource',
            'auteur' => 'item',
        ];

        $matches = [];
        $replace = [];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $texte, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            if (!count($matches)) {
                continue;
            }
            // Commence par le dernier pour éviter les problèmes de position dans la
            // nouvelle chaîne.
            foreach (array_reverse($matches) as $match) {
                $type = $match['type'][0];
                $id = $match['id'][0];
                $isRaccourci = !empty($match['raccourci'][0]);
                $importedType = $importeds[$type] ?? null;
                if (empty($this->map[$importedType][$id])) {
                    $resourceId = '000' . $id;
                } else {
                    $resourceId = $this->map[$importedType][$id];
                }
                // Si c'est un raccourci Spip, remplacer par l'url relative, car
                // on ne connait pas les autres éléments du raccourci.
                if ($isRaccourci) {
                    $resourceType = $maps[$type] ?? 'resources';
                    $replace[$match['url'][0]] = "/$resourceType/$resourceId";
                } else {
                    $resourceType = $mapSingles[$type] ?? 'resource' ;
                    $replace[$match['url'][0]] = "[$resourceType id=$resourceId]";
                }
            }
        }

        if (!$replace) {
            return $texte;
        }

        $this->logger->info(new Message(
            'Renommage : %s', // @translate
            json_encode($replace, 320)
        ));

        return str_replace(array_keys($replace), array_values($replace), $texte);
    }

    /**
     * Convertit les liens vers les fichiers du site source en lien Omeka.
     *
     * Exemples :
     * - http://www.menestrel.fr/IMG/jpg/w-921-f11.jpg
     * - http://www.menestrel.fr/IMG/pdf/Charte_typographique-2.pdf
     *
     * Les liens sans relation sont conservés tels quels.
     */
    protected function majFichiersSpip($texte): string
    {
        static $endpoint;
        static $pattern;

        if ($endpoint === null) {
            $endpoint = $this->getParam('endpoint');
            $endpoint = trim(str_replace(['http://', 'https://'], ['', ''], $endpoint), ' /');
            if ($endpoint) {
                $pattern = '~(?<url>(?:https://|http://|//)?' . preg_quote($endpoint) . '/IMG/(?<filename>[^\n\]\"\']+))\b~m';
            }
        }

        // Vérification rapide.
        if (!$endpoint || strpos($texte, $endpoint) === false) {
            return $texte;
        }

        $matches = [];
        $replace = [];
        preg_match_all($pattern, $texte, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            $filename = $match['filename'][0];
            // Api doesn't allow to search with "source", so use read.
            try {
                /** @var \Omeka\Api\Representation\MediaRepresentation $media */
                $media = $this->api()->read('media', ['source' => $filename])->getContent();
            } catch (\Exception $e) {
                continue;
            }
            $replace[$match['url'][0]] = $media->originalUrl();
        }

        if (!$replace) {
            return $texte;
        }

        $this->logger->info(new Message(
            'Renommage : %s', // @translate
            json_encode($replace, 320)
        ));

        return str_replace(array_keys($replace), array_values($replace), $texte);
    }

    protected function completionShortJobs(array $resourceIds): void
    {
        parent::completionShortJobs($resourceIds);

        // Les titres doivent être recrées auparavant de façon à les trier.
        if ($this->getParam('menu')) {
            $this->createMenu();
            $this->sortMenu();
        }
    }

    /**
     * Créer le menu à partir des rubriques, sommaires et listes d'articles.
     *
     * Le menu est converti depuis le thésaurus, puis on ajoute les articles liés.
     */
    protected function createMenu(): void
    {
        if (empty($this->modules['Menu'])) {
            $this->logger->err(
                'Le module "Menu" est nécessaire pour créer le menu' // @translate
            );
            return;
        }

        if (empty($this->main['concept']['item'])) {
            $this->logger->err(
                'Le thésaurus est vide.' // @translate
            );
            return;
        }

        if (empty($this->thesaurusConfigs['concepts']) || empty($this->modules['Thesaurus'])) {
            $this->logger->err(
                'Le thésaurus doit être créé à partir des rubriques (module "Thesaurus").' // @translate
            );
            return;
        }

        /** @var \Thesaurus\Mvc\Controller\Plugin\Thesaurus $thesaurus */
        $thesaurus = $this->getServiceLocator()->get('ControllerPluginManager')->get('thesaurus');
        $scheme = $this->api()->read('resources', ['id' => $this->main['concept']['item_id']])->getContent();
        $thesaurus = $thesaurus($scheme);
        if (!$thesaurus->isSkos()) {
            $this->logger->err(
                'Le thésaurus pour la création du menu est incorrect.' // @translate
            );
            return;
        }

        $tree = $thesaurus->tree();
        if (!$tree) {
            $this->logger->warn(
                'Le thésaurus pour la création du menu est vide.' // @translate
            );
            return;
        }

        // L'arborescence du module Thésaurus distingue l'élément et ses enfants :
        /*
        {
            "10631": {
                "self": [
                    "id": 10631,
                    "title": "title",
                    "top": true,
                    "parent": null,
                    "children": [
                        10632
                    ]
                ],
                "children": [
                    "10632": {
                    }
                ]
            }
        }
        */

        // Le menu se présente comme suit ("structure" est le nom du premier menu et
        // "links" permet d'imbriquer les sous-menus) :
        /*
        {
            "structure": [
                {
                    "type": "resource",
                    "data": {
                        "label": null,
                        "id": 10631
                    },
                    "links": [
                    ]
                }
            ]
        }
        */

        // array_walk_recursive() ne peut pas être utilisé car chaque élément
        // est un array.
        $conceptsArticles = [];
        $conceptsSansArticles = [];
        $buildMenu= null;
        $buildMenu = function (array $branches, int $level = 0) use (&$buildMenu, &$conceptsArticles, &$conceptsSansArticles): array {
            $menu = [];
            foreach ($branches as $branch) {
                /** @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $concept */
                $id = $branch['self']['id'];
                $concept = $this->api()->read('resources', ['id' => $id])->getContent();
                $children = $branch['children'] ?? [];
                // Ajout des relations non présentes dans le menu (articles liés
                // à un concept mais qui n'apparaissent pas dans la structure).
                /** @var \Omeka\Api\Representation\ValueRepresentation[] $linkedResources */
                $articles = $this->lienArticles($concept);
                if (count($articles)) {
                    $conceptsArticles[$id] = array_keys($articles);
                } else {
                    $class = $concept->resourceClass();
                    if ($class && $class->term() === 'skos:Concept') {
                        $conceptsSansArticles[] = $id;
                    }
                }
                foreach ($articles as $article) {
                    // Les articles n'ont pas d'enfant.
                    $children[$article->id()] = [
                        'self' => [
                            'id' => $article->id(),
                            'title' => $article->title(),
                            'top' => false,
                            'parent' => $id,
                            'children' => [],
                        ],
                        'children' => [],
                    ];
                }
                $element = [
                    'type' => 'resource',
                    'data' => [
                        'label' => null,
                        'id' => $id,
                    ],
                    // Création récursive de la structure.
                    'links' => $buildMenu($children, $level + 1),
                ];
                $menu[] = $element;
            }
            return $menu;
        };
        $menu = $buildMenu($tree);

        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->refreshMainResources();

        /** @var \Omeka\Settings\SiteSettings $siteSettings */
        $siteSettings = $this->getServiceLocator()->get('Omeka\Settings\Site');
        $siteSettings->setTargetId(1);
        $label = str_replace([' ', ':'], ['-', '-'], 'Menu Spip ' . $this->currentDateTimeFormatted);
        $menus = $siteSettings->get('menu_menus', []);
        $menus[$label] = $menu;
        $siteSettings->set('menu_menus', $menus);

        $this->logger->notice(
            'Le menu a été créé sous le nom "{label}".', // @translate
            ['label' => $label]
        );
        if ($conceptsArticles) {
            $this->logger->notice(
                'Correspondance concepts ⬌ articles : {list}', // @translate
                ['list' => str_replace(['[', ']', ','], ["[\n", "\n]", ",\n"], json_encode($conceptsArticles, 320))]
            );
        }
        if ($conceptsSansArticles) {
            $this->logger->warn(
                'Les concepts suivants ne disposent pas d’articles associés : {item_ids}', // @translate
                ['item_ids' => '' . implode(",\n", $conceptsSansArticles)]
            );
        }
    }

    /**
     * Tri du menu (qui doit être complet).
     *
     * Le tri se fait avant la suppression des faux éléments structurels, car il
     * faut connaître les numéros, qui ne sont pas dans les articles.
     */
    protected function sortMenu()
    {
        /** @var \Omeka\Settings\SiteSettings $siteSettings */
        $siteSettings = $this->getServiceLocator()->get('Omeka\Settings\Site');
        $siteSettings->setTargetId(1);
        $label = str_replace([' ', ':'], ['-', '-'], 'Menu Spip ' . $this->currentDateTimeFormatted);
        $menus = $siteSettings->get('menu_menus', []);
        if (empty($menus[$label])) {
            return;
        }

        $orderMenu = null;
        $orderMenu = function (array $elements, int $level = 0) use (&$orderMenu): array {
            foreach ($elements as &$element) {
                // Normalement, tous le menu est item.
                if ($element['type'] === 'resource'
                    && !empty($element['links'])
                    && count($element['links']) > 1
                ) {
                    $links = [];
                    $titles = [];
                    foreach ($element['links'] as $link) {
                        $childId = $link['data']['id'];
                        /** @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $childItem */
                        $childItem = $this->api()->read('resources', ['id' => $childId])->getContent();
                        $links[$childId] = $link;
                        $titles[$childId] = $this->labelKeyForSort($childItem->displayTitle(), $childItem->id());
                    }
                    natcasesort($titles);
                    $element['links'] = array_values(array_replace($titles, $links));
                }
                $element['links'] = $orderMenu($element['links'], $level + 1);
            }
            return $elements;
        };
        $menu = $menus[$label];
        $menu = $orderMenu($menu);

        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->refreshMainResources();

        $label .= '-tri';
        $menus[$label] = $menu;
        $siteSettings->set('menu_menus', $menus);
        $this->logger->notice(
            'Le menu a été trié sous le nom "{label}".', // @translate
            ['label' => $label]
        );
    }

    private function lienArticle(?ItemRepresentation $item): ?ItemRepresentation
    {
        $resources = $this->lienArticles($item);
        return count($resources) ? reset($resources) : null;
    }

    private function lienArticles(?ItemRepresentation $item): array
    {
        static $propertyId;
        if (!$item) {
            return [];
        }
        if (is_null($propertyId)) {
            $propertyId = $this->bulk->getPropertyId('curation:category');
        }
        $values = $item->subjectValues(null, null, $propertyId);
        if (!count($values)) {
            return [];
        }
        $result = [];
        foreach ($values['curation:category'] as $value) {
            $resource = $value->resource();
            $result[$resource->id()] = $resource;
        }
        return $result;
    }
}
