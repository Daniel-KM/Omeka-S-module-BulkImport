<?php declare(strict_types = 1);

namespace BulkImport\Processor;

use BulkImport\Form\Processor\OmekaSProcessorConfigForm;
use BulkImport\Form\Processor\SpipProcessorParamsForm;
use DateTime;
use Laminas\Validator\EmailAddress;

/**
 * Spip (Système de Publication pour un Internet Partagé)
 *
 * @link https://code.spip.net/autodoc
 * @link https://www.spip.net/aide/fr-aide.html
 */
class SpipProcessor extends AbstractFullProcessor
{
    protected $resourceLabel = 'Spip'; // @translate
    protected $configFormClass = OmekaSProcessorConfigForm::class;
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
        'language' => null,
        'endpoint' => null,
    ];

    protected $modulesRequired = [
        'AdvancedResourceTemplate',
        'Article',
        // 'Comment',
        'CustomVocab',
        'DataTypeRdf',
        'Log',
        'NumericDataTypes',
        'Spip',
        'Thesaurus',
        'UserProfile',
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
            'source' => 'document',
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
        // Modules
        'custom_vocabs' => [
            'source' => 'groupe_mots',
            'key_id' => 'id_groupe',
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
    ];

    protected $main = [
        'article' => [
            'template' => 'Article éditorial',
            'class' => 'bibo:Article',
            'custom_vocab' => 'Article - Statuts',
        ],
        // TODO Move to thesaurus.
        'concept' => [
            'template' => 'Thesaurus Concept',
            'class' => 'skos:Concept',
            'item_set' => null,
        ],
        'scheme' => [
            'template' => 'Thesaurus Scheme',
            'class' => 'skos:ConceptScheme',
            'item' => null,
            'custom_vocab' => null,
        ],
        'templates' => [
            'Fichier' => null,
        ],
        'classes' => [
            'bibo:Document' => null,
            'dctype:MovingImage' => null,
            'dctype:Sound' => null,
            'dctype:StillImage' => null,
            'dctype:Text' => null,
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

    protected function preImport(): void
    {
        // TODO Remove these fixes.
        $args = $this->getParams();
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
        $key = array_search('media', $args['types']);
        if ($key !== false) {
            unset($args['types'][$key]);
            $args['types'][] = 'media_items';
        }
        $args['types'] = array_unique($args['types']);

        $endpoint = rtrim(trim($args['endpoint'] ?? ''), ' /');
        $args['endpoint'] = $endpoint ? $endpoint . '/' : '';

        $this->setParams($args);

        $this->map['statuts'] = [
            'prepa' => 'en cours de rédaction',
            'prop' => 'proposé à l’évaluation',
            'publie' => 'publié en ligne',
            'refuse' => 'refusé',
            'poubelle' => 'à la poubelle',
        ];

        $this->prepareInternalVocabularies();
    }

    protected function prepareUsers(): void
    {
        // In spip, emails, logins and names are not unique or can be empty…
        $validator = new EmailAddress();

        $sourceUsers = [];
        $emails = [];
        foreach ($this->reader->setOrder('id_auteur')->setObjectType('auteurs') as $auteur) {
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
            if ($auteur['maj']) {
                $userCreated = DateTime::createFromFormat('Y-m-d H:i:s', $auteur['maj']);
            }
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

        $sourceCustomVocabs = [];
        // "id_groupe" et "titre" sont en doublon dans la table "mots", donc la
        // table est inutile actuellement, sauf pour avoir les vocabulaires
        // vides.
        // TODO Les descriptions ne sont pas importés actuellement.
        foreach ($this->reader->setOrder('id_groupe')->setObjectType('groupes_mots') as $groupeMots) {
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

        foreach ($this->reader->setOrder('id_mot')->setObjectType('mots') as $mot) {
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
        }

        $this->prepareCustomVocabsProcess($sourceCustomVocabs);
    }

    protected function prepareItems(): void
    {
        $this->prepareResources($this->reader->setOrder('id_article')->setObjectType('articles'), 'items');
    }

    protected function prepareMedias(): void
    {
    }

    protected function prepareMediaItems(): void
    {
        $this->prepareResources($this->reader->setOrder('id_document')->setObjectType('documents'), 'media_items');
    }

    protected function prepareItemSets(): void
    {
        $this->prepareResources($this->reader->setOrder('id_album')->setObjectType('albums'), 'item_sets');
    }

    protected function prepareOthers(): void
    {
        if (!empty($this->modules['Thesaurus'])
            && in_array('concepts', $this->getParam('types') ?: [])
        ) {
            $this->logger->info(
                'Preparation of metadata of module Thesaurus.' // @translate
                );
            if ($this->prepareImport('concepts')) {
                $this->prepareConcepts($this->reader->setOrder('id_rubrique')->setObjectType('rubriques'));
            }
        }
    }

    protected function fillItems(): void
    {
        $this->fillResources($this->reader->setOrder('id_article')->setObjectType('articles'), 'items');
    }

    protected function fillMedias(): void
    {
    }

    protected function fillMediaItems(): void
    {
        $this->fillResources($this->reader->setOrder('id_document')->setObjectType('documents'), 'media_items');
    }

    protected function fillItemSets(): void
    {
        $this->fillResources($this->reader->setOrder('id_album')->setObjectType('albums'), 'item_sets');
    }

    protected function fillItem(array $source): void
    {
        if (!empty($source['id_article'])) {
            $this->fillArticle($source);
        }
    }

    /**
     * La ressource Spip Article est convertie en item + media.
     *
     * @param array $source
     */
    protected function fillArticle(array $source): void
    {
        // FIXME En cours. Actuellement, item et média sont entièrement remplis et mélangés.
        /*
        // Not managed currently.
        [
            'id_secteur', // ?
            'export', // ?
            // Module statistic.
            'visites',
            'referers',
            'popularite',
            'accepter_forum',
            // Inutile.
            'langue_choisie',
        ];
         */

        $source = array_map('trim', array_map('strval', $source));

        // Le titre est obligatoire pour le modèle, mais peut être vide dans Spip.
        if (!mb_strlen($source['titre'])) {
            $source['titre'] = sprintf('[Untitled #]', $source['id_article']); // @translate
        }

        $status = $this->map['statuts'][$source['statut']] ?? $source['statut'];
        $isPublic = $source['statut'] === 'publie';
        $createdDate = $this->getSqlDateTime($source['date']) ?? $this->currentDateTime;
        $majDate = $this->getSqlDateTime($source['maj']);

        // Omeka entities are not fluid.
        /* @var \Omeka\Entity\Item */
        $this->entity->setOwner($this->owner);
        $this->entity->setResourceClass($this->main['article']['class']);
        $this->entity->setResourceTemplate($this->main['article']['template']);
        $this->entity->setTitle($source['titre']);
        $this->entity->setIsPublic($isPublic);
        $this->entity->setCreated($createdDate);
        if ($majDate) {
            $this->entity->setModified($majDate);
        }

        $mediaData = [
            'heading' => $source['surtitre'],
            'subhead' => $source['soustitre'],
            'lead' => $source['chapo'],
            'html' => $source['texte'],
            'complement' => $source['ps'],
        ];

        $media = new \Omeka\Entity\Media();
        $media->setOwner($this->entity->getOwner());
        $media->setItem($this->entity);
        $media->setResourceClass($this->main['article']['class']);
        $media->setResourceTemplate($this->main['article']['template']);
        $media->setTitle($source['titre']);
        $media->setIngester('article');
        $media->setRenderer('article');
        $media->setData($mediaData);
        $media->setPosition(1);
        $media->setCreated($createdDate);
        if ($majDate) {
            $media->setModified($majDate);
        }
        if ($source['lang']) {
            $media->setLang($source['lang']);
        }

        foreach ([$this->entity, $media] as $article) {
            $fromTo = [
                'titre' => 'dcterms:title',
                'descriptif' => 'bibo:shortDescription',
                'lang' => 'dcterms:language',
            ];
            foreach ($fromTo as $sourceName => $term) {
                $value = $source[$sourceName];
                if (strlen($value)) {
                    $this->appendValue([
                        'term' => $term,
                        'lang' => $sourceName === 'lang' ? null : $this->params['language'],
                        'value' => $value,
                    ], $article);
                }
            }

            $value = (int) $source['id_rubrique'];
            if ($value) {
                // Le concept est conservé même si vide.
                if (empty($this->map['concepts'][$value])) {
                    $this->appendValue([
                        'term' => 'dcterms:subject',
                        'type' => 'literal',
                        'lang' => $this->params['language'],
                        'value' => $value,
                    ], $article);
                } else {
                    $linked = $this->entityManager->find(\Omeka\Entity\Item::class, $this->map['concepts'][$value]);
                    $this->appendValue([
                        'term' => 'dcterms:subject',
                        'type' => 'resource:item',
                        'value_resource' => $linked,
                    ], $article);
                }
            }

            if ($status) {
                $this->appendValue([
                    'term' => 'bibo:status',
                    'type' => 'customvocab:' . $this->main['article']['custom_vocab_id'],
                    'value' => $status,
                ], $article);
            }

            // TODO Conserver toutes les dates en valeur ?
            // Généralement vide. Utilisé pour les articles préalablement migrés dans Spip.
            $redacDate = $this->getSqlDateTime($source['date_redac']);
            if ($redacDate) {
                $this->appendValue([
                    'term' => 'dcterms:dateSubmitted',
                    'value' => $redacDate->format('Y-m-d H:i:s'),
                ], $article);
            }
            if ($createdDate) {
                $this->appendValue([
                    'term' => 'dcterms:created',
                    'value' => $createdDate->format('Y-m-d H:i:s'),
                ], $article);
            }
            $modifiedDate = $this->getSqlDateTime($source['date_modif']);
            if ($modifiedDate) {
                $this->appendValue([
                    'term' => 'dcterms:modified',
                    'value' => $modifiedDate->format('Y-m-d H:i:s'),
                ], $article);
            }
            if ($majDate) {
                $this->appendValue([
                    'term' => 'dcterms:dateAccepted',
                    'value' => $majDate->format('Y-m-d H:i:s'),
                ], $article);
            }

            if ($source['url_site'] || $source['nom_site']) {
                $this->appendValue([
                    'term' => 'dcterms:references',
                    'type' => 'uri',
                    '@id' => $source['url_site'],
                    '@label' => $source['nom_site'],
                ], $article);
            }

            // Un article virtuel peut être un article externe ou interne.
            if ($source['virtuel']) {
                if ($this->bulk->isUrl($source['virtuel'])) {
                    $this->appendValue([
                        'term' => 'bibo:uri',
                        'type' => 'uri',
                        '@id' => $source['virtuel'],
                        '@label' => '',
                    ], $article);
                } else {
                    $mapped = $this->relatedLink($source['virtuel']);
                    if ($mapped) {
                        $this->appendValue([
                            'term' => 'bibo:uri',
                            'type' => $mapped['type'],
                            'value_resource' => $mapped['value_resource'],
                        ], $article);
                    } else {
                        $this->logger->warn(
                            'The virtuel resource #{identifier} of item #{item_id} (source {source}) was not found.', // @translate
                            ['identifier' => $source['virtuel'], 'item_id' => $this->entity->getId(), 'source' => $source['id_article']]
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
                    $linked = $this->entityManager->find(\Omeka\Entity\Item::class, $this->map['items'][$value]);
                    $this->appendValue([
                        // Impossible de déterminer laquelle est la traduction de l'autre.
                        'term' => 'bibo:translationOf',
                        'type' => 'resource:item',
                        'value_resource' => $linked,
                    ], $article);
                }
            }

            $this->entityManager->persist($article);
        }

        // TODO Auteurs liens, documents liens.
    }

    protected function fillMedia(array $source): void
    {
    }

    /**
     * La ressource Spip Document est convertie en item + media.
     *
     * Au cas où la ressource est attachée à un article, le media y est attaché
     * directement à l'item correspondant. S'il est ajouté en tant qu'illustration
     * les assets d'Omeka ne sont pas utilisés afin de conerver quelques
     * les trois métadonnées (titre, descriptif, droits).
     *
     * @link https://www.spip.net/fr_article5632.html
     *
     * @param array $source
     */
    protected function fillMediaItem(array $source): void
    {
        $source = array_map('trim', array_map('strval', $source));

        // FIXME Déplacer les médias en tant que fichier attachés aux articles.

        /*
         media	// file / image / video / audio ==> inutile
         mode	// document / image / vignette => vignette supprimés ; inutile
         distant	// oui/non // Inutile car url présente
         brise	// lien brisé ?
         */

        // Le titre est obligatoire pour le modèle, mais peut être vide dans Spip.
        if (!mb_strlen($source['titre'])) {
            $source['titre'] = sprintf('[Untitled #]', $source['id_document']); // @translate
        }

        $media = $this->entityManager->find(\Omeka\Entity\Media::class, $this->map['media_items_sub'][$source['id_document']]);

        if ('mode' === 'vignette') {
            $this->entityManager->remove($media);
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
        $this->entity->setTitle($source['titre']);
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
        $media->setTitle($source['titre']);
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

        foreach ([$this->entity, $media] as $document) {
            $fromTo = [
                'titre' => 'dcterms:title',
                'descriptif' => 'bibo:shortDescription',
                'credits' => 'dcterms:rights',
            ];
            foreach ($fromTo as $sourceName => $term) {
                $value = $source[$sourceName];
                if (strlen($value)) {
                    $this->appendValue([
                        'term' => $term,
                        'lang' => $this->params['language'] ?? null,
                        'value' => $value,
                    ], $document);
                }
            }

            if ($status) {
                $this->appendValue([
                    'term' => 'bibo:status',
                    'type' => 'customvocab:' . $this->main['article']['custom_vocab_id'],
                    'value' => $status,
                ]);
            }

            if ($createdDate) {
                $this->appendValue([
                    'term' => 'dcterms:created',
                    'value' => $createdDate->format('Y-m-d H:i:s'),
                ], $document);
            }
            if ($majDate) {
                $this->appendValue([
                    'term' => 'dcterms:dateAccepted',
                    'value' => $majDate->format('Y-m-d H:i:s'),
                ], $document);
            }
            $publicationDate = $this->getSqlDateTime($source['date_publication']);
            if ($publicationDate) {
                $this->appendValue([
                    'term' => 'dcterms:issued',
                    'value' => $publicationDate->format('Y-m-d H:i:s'),
                ], $document);
            }

            $this->entityManager->persist($document);
        }
    }

    protected function fillItemSet(array $source): void
    {
        // FIXME Convertir les albums en item avec relation ?
        $source = array_map('trim', array_map('strval', $source));
        if (!mb_strlen($source['titre'])) {
            $source['titre'] = sprintf('[Untitled #]', $source['id_album']); // @translate
        }

        $status = $this->map['statuts'][$source['statut']] ?? $source['statut'];
        $isPublic = $source['statut'] === 'publie';
        $createdDate = $this->getSqlDateTime($source['date']) ?? $this->currentDateTime;
        $majDate = $this->getSqlDateTime($source['maj']);

        // Omeka entities are not fluid.
        /* @var \Omeka\Entity\ItemSet */
        $this->entity->setOwner($this->owner);
        $this->entity->setTitle($source['titre']);
        $this->entity->setIsPublic($isPublic);
        $this->entity->setCreated($createdDate);
        if ($majDate) {
            $this->entity->setModified($majDate);
        }
        $this->entity->setIsOpen(true);

        $fromTo = [
            'titre' => 'dcterms:title',
            'descriptif' => 'bibo:shortDescription',
            'lang' => 'dcterms:language',
        ];
        foreach ($fromTo as $sourceName => $term) {
            $value = $source[$sourceName];
            if (strlen($value)) {
                $this->appendValue([
                    'term' => $term,
                    'lang' => $sourceName === 'lang' ? null : $this->params['language'],
                    'value' => $value,
                ]);
            }
        }

        if ($status) {
            $this->appendValue([
                'term' => 'bibo:status',
                'type' => 'customvocab:' . $this->main['article']['custom_vocab_id'],
                'value' => $status,
            ]);
        }

        if ($createdDate) {
            $this->appendValue([
                'term' => 'dcterms:created',
                'value' => $createdDate->format('Y-m-d H:i:s'),
            ]);
        }
        if ($majDate) {
            $this->appendValue([
                'term' => 'dcterms:dateAccepted',
                'value' => $majDate->format('Y-m-d H:i:s'),
            ]);
        }

        $value = (int) $source['id_trad'];
        if ($value) {
            if (empty($this->map['item_sets'][$value])) {
                $this->logger->warn(
                    'The translation #{id_trad} of item set #{item_set_id} (source {source}) was not found.', // @translate
                    ['id_trad' => $value, 'item_set_id' => $this->entity->getId(), 'source' => $source['id_article']]
                );
            } else {
                $linked = $this->entityManager->find(\Omeka\Entity\ItemSet::class, $this->map['items'][$value]);
                $this->appendValue([
                    'term' => 'bibo:translationOf',
                    'type' => 'resource:item',
                    'value_resource' => $linked,
                ]);
            }
        }
    }

    protected function fillConcepts(): void
    {
        $this->fillResources($this->reader->setOrder('id_rubrique')->setObjectType('rubriques'), 'concepts');
    }

    /**
     * La resource spip "rubrique" est convertie en item Concept.
     *
     * @todo Move main part of this in ThesaurusTrait.
     * @param array $source
     */
    protected function fillConcept(array $source): void
    {
        // TODO Id secteur ?
        /*
         * Id secteur
         * statut_tmp
         * date_tmp
         * profondeur // Inutile
         */

        $source = array_map('trim', array_map('strval', $source));

        parent::fillConceptProcess($source);

        $status = $this->map['statuts'][$source['statut']] ?? $source['statut'];
        if ($status) {
            $this->appendValue([
                'term' => 'bibo:status',
                'type' => 'customvocab:' . $this->main['article']['custom_vocab_id'],
                'value' => $status,
            ]);
        }
        $isPublic = $source['statut'] === 'publie';
        $this->entity->setIsPublic($isPublic);
    }

    /**
     * @link https://www.spip.net/aide/fr-aide.html
     *
     * @param string $value
     * @return array|null
     */
    protected function relatedLink($value): ?array
    {
        if (mb_substr($value, 0, 3) === 'art') {
            $value = (int) mb_substr($value, 3);
            if (empty($this->map['items'][$value])) {
                return null;
            }
            $linked = $this->entityManager->find(\Omeka\Entity\Item::class, $this->map['items'][$value]);
            return [
                'type' => 'resource:item',
                'id' => $this->map['items'][$value],
                'value_resource' => $linked,
            ];
        }

        if (mb_substr($value, 0, 3) === 'doc') {
            $value = (int) mb_substr($value, 3);
            if (empty($this->map['media'][$value])) {
                return null;
            }
            $linked = $this->entityManager->find(\Omeka\Entity\Item::class, $this->map['media'][$value]);
            return [
                'type' => 'resource:media',
                'id' => $this->map['media'][$value],
                'value_resource' => $linked,
            ];
        }

        if (mb_substr($value, 0, 2) === 'br') {
            $value = (int) mb_substr($value, 2);
            if (empty($this->map['items'][$value])) {
                return null;
            }
            $linked = $this->entityManager->find(\Omeka\Entity\Item::class, $this->map['items'][$value]);
            return [
                'type' => 'resource:item',
                'id' => $this->map['items'][$value],
                'value_resource' => $linked,
            ];
        }

        return null;
    }
}
