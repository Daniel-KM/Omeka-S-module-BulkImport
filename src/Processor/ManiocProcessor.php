<?php declare(strict_types=1);

namespace BulkImport\Processor;

use BulkImport\Form\Processor\ManiocProcessorConfigForm;
use BulkImport\Form\Processor\ManiocProcessorParamsForm;

/**
 * FIXME Some sql queries apply on all the database: limit them to the item mapping.
 * TODO Remove hard coded data (templates).
 */
class ManiocProcessor extends AbstractFullProcessor
{
    use MetadataTransformTrait;

    const TYPE_ALL = 'all';
    const TYPE_AUDIO_VIDEO = 'audio-vidéo';
    const TYPE_IMAGE = 'images';
    const TYPE_LIVRE = 'livres anciens';
    const TYPE_RECHERCHE = 'recherche';
    const TYPE_PERSONNE_COLLECTIVITE = 'personnes et collectivités';
    const TYPE_MANIFESTATION = 'manifestations';

    protected $resourceLabel = 'Manioc'; // @translate
    protected $configFormClass = ManiocProcessorConfigForm::class;
    protected $paramsFormClass = ManiocProcessorParamsForm::class;

    protected $configDefault = [
        'endpoint' => null,
        'key_identity' => null,
        'key_credential' => null,
    ];

    protected $paramsDefault = [
        'o:owner' => null,
        'types' => [
            'users',
            'items',
            'media',
            'item_sets',
        ],
        'fake_files' => true,
        'endpoint' => null,
        'language' => null,
        'language_2' => null,
        'geonames_search' => null,
    ];

    protected $mapping = [
        'users' => [
            'source' => 'users',
            'key_id' => 'id',
        ],
        'items' => [
            'source' => null,
            'key_id' => 'id_fichier',
        ],
        'media' => [
            'source' => null,
            'key_id' => 'id_fichier',
        ],
        'media_items' => [
            'source' => 'fichiers',
            'key_id' => 'id_fichier',
        ],
        'item_sets' => [
            'source' => 'collections',
            'key_id' => 'id_collection',
        ],
        'etablissements' => [
            'source' => 'etablissement',
            'key_id' => 'id_etabl',
        ],
        'values' => [
            'source' => 'metadata',
            'key_id' => 'id_metadata',
            'key_resource' => 'id_fichier',
            'key_field' => 'nom',
            'key_value' => 'valeur',
        ],
    ];

    protected $tables = [
        'fichiers',
        'metadata',
    ];

    protected $stats = [
        'removed' => 0,
        'not_managed' => [],
    ];

    protected function preImport(): void
    {
        $this->logger->warn(
            'Currently, this importer must be run on an empty database.', // @translate
        );

        $this->prepareConfig('config.php', 'manioc');

        // With this processor, direct requests are done to the source database,
        // so check right of the current database user.
        // Some processes below copy some tables for simplicity purpose.
        // TODO Process with user database only.

        if (!$this->reader->canReadDirectly()) {
            $this->hasError = true;
            $dbConfig = $this->reader->getDbConfig();
            $this->logger->err(
                'The Omeka database user should be able to read the source database, so run this query or a similar one with a database admin user: "{sql}".',  // @translate
                ['sql' => sprintf("GRANT SELECT ON `%s`.* TO '%s'@'%s';", $dbConfig['database'], $dbConfig['username'], $dbConfig['hostname'])]
            );
            return;
        }

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

        // TODO Check if the properties of the mapping are all presents.

        $this->logger->info(
            'Copying {total} tables from the source.',  // @translate
            ['total' => count($this->tables)]
        );
        foreach ($this->tables as $table) {
            $result = $this->copyTable($table);
            if (!$result) {
                $this->hasError = true;
                $this->logger->err(
                    'Unable to copy source table "{table}".',  // @translate
                    ['table' => $table]
                );
                return;
            }
        }

        foreach ($this->prepareReader('etablissements') as $etablissement) {
            $this->map['etablissements'][$etablissement['id_etabl']] = $etablissement['nom_etabl'];
        }

        // Simplifie la création des urls pour les fichiers.
        foreach ($this->prepareReader('item_sets') as $itemSet) {
            $this->map['collections'][$itemSet['id_collection']] = $itemSet['code_collection'];
        }

        $this->prepareInternalVocabularies();
        $this->prepareInternalTemplates();
    }

    protected function postImport(): void
    {
        foreach ($this->tables as $table) {
            $this->removeTable($table);
        }
    }

    protected function prepareUsers(): void
    {
        $userSources = [];
        $emails = [];
        $importId = $this->job->getImportId();
        foreach ($this->prepareReader('users') as $userSource) {
            $user = [];
            $userSource = array_map('trim', array_map('strval', $userSource));
            $cleanName = mb_strtolower(preg_replace('/[^\da-z]/i', '_', ($userSource['login'])));
            $email = $cleanName . '@manioc.net';
            $user['name'] = $cleanName;
            $user['email'] = $email;
            if (isset($emails[$email])) {
                $email = $userSource['id'] . '-' . $importId . '-' . $email;
                $user['email'] = $email;
            }
            $this->logger->warn(
                'The email "{email}" has been attributed to user "{name}" for login.', // @translate
                ['email' => $email, 'name' => $cleanName]
            );
            $emails[$email] = $user['email'];

            $isActive = true;
            $role = 'researcher';
            $userCreated = $this->currentDateTimeFormatted;
            $userModified = null;

            $userSources[] = [
                'o:id' => $userSource['id'],
                'o:name' => $user['name'],
                'o:email' => $user['email'],
                'o:created' => [
                    '@value' => $userCreated,
                ],
                'o:modified' => $userModified,
                'o:role' => $role,
                'o:is_active' => $isActive,
                'o:settings' => [
                    'locale' => 'fr',
                    'userprofile_organisation' => $this->map['etablissements'][$userSource['etabl']] ?? null,
                ],
            ];
        }

        $this->prepareUsersProcess($userSources);
        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->refreshMainResources();
    }

    protected function fillMediaItems(): void
    {
        parent::fillMediaItems();

        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->refreshMainResources();

        $this->fillValues($this->prepareReader('values'));
    }

    protected function fillOthers(): void
    {
        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->refreshMainResources();

        // The media data are available only when values are set, even if it is
        // possible to use the original tables.
        $this->sourceFilenamePropertyId = $this->bulk->getPropertyId('greenstone:sourceFilename');
        $this->filenamesToSha256 = $this->loadKeyValuePair('sha256', true) ?: [];
        $reader = $this->prepareReader('media_items');
        $total = $reader->count();
        $count = 0;
        foreach ($reader as $mediaItem) {
            $this->entity = $this->entityManager
                ->find(\Omeka\Entity\Item::class, $this->map['media_items'][$mediaItem['id_fichier']]);
            $this->fillMediaItemMedia($mediaItem);
            if (++$count % 100 === 0) {
                $this->logger->info(
                    '{count}/{total} media processed.', // @translate
                    ['count' => $count, 'total' => $total]
                );
                if ($this->isErrorOrStop()) {
                    break;
                }
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->refreshMainResources();

        if ($this->isErrorOrStop()) {
            return;
        }

        $this->fillItemTemplates();
        $this->migrateValues();
        $this->finalizeValues();
        parent::fillOthers();
    }

    protected function fillMediaItem(array $source): void
    {
        $source = array_map('trim', array_map('strval', $source));

        $created = $source['date_ajout'] ? \DateTime::createFromFormat('Y-m-d', $source['date_ajout']) : null;
        $modified = $source['lastmodified'] && $source['lastmodified'] !== '0000-00-00'
            ? \DateTime::createFromFormat('Y-m-d', $source['lastmodified'])
            : null;

        // Omeka entities are not fluid.
        /** @var \Omeka\Entity\Item */
        $this->entity->setOwner($this->owner);
        // $this->entity->setTitle();
        $this->entity->setIsPublic(true);
        $this->entity->setCreated($created ?? $this->currentDateTime);
        if ($modified) {
            $this->entity->setModified($modified);
        }

        $collection = null;
        if ($source['id_collection'] && $this->map['item_sets'][$source['id_collection']]) {
            $itemSets = $this->entity->getItemSets();
            $itemSetIds = [];
            foreach ($itemSets as $itemSet) {
                $itemSetIds[] = $itemSet->getId();
            }
            // This check avoids a core bug (don't add the same item set twice).
            if (!in_array($this->map['item_sets'][$source['id_collection']], $itemSetIds)) {
                /** @var \Omeka\Entity\ItemSet $collection */
                $collection = $this->entityManager->find(\Omeka\Entity\ItemSet::class, $this->map['item_sets'][$source['id_collection']]);
                $itemSets->add($collection);
            }
        }

        $values = [];
        $values[] = [
            'term' => 'dcterms:identifier',
            'lang' => null,
            'value' => 'greenstone_fichier: ' . $source['id_fichier'],
            'is_public' => false,
        ];
        if ($source['id_collection']) {
            $values[] = [
                'term' => 'dcterms:identifier',
                'lang' => null,
                'value' => 'greenstone_collection_id: ' . $source['id_collection'],
                'is_public' => false,
            ];
            /*
            // The collection is not yet available.
            if ($collection) {
                $values[] = [
                    'term' => 'dcterms:identifier',
                    'lang' => null,
                    'value' => 'greenstone_collection: ' . $collection->getTitle(),
                    'is_public' => false,
                ];
            }
            */
        }
        if (!empty($source['id_greenstone'])) {
            $values[] = [
                'term' => 'dcterms:identifier',
                'lang' => null,
                'value' => 'greenstone_id: ' . $source['id_greenstone'],
                'is_public' => false,
            ];
        }

        $this->appendValues($values);
    }

    /**
     * Le chemin se trouve dans les métadonnées, pas encore disponible ici.
     * Le media est donc importé dans un second temps, après les valeurs.
     */
    protected function fillMediaItemMedia(array $source): void
    {
        $propertyId = &$this->sourceFilenamePropertyId;

        /** @var \Omeka\Entity\Value $value */
        $greenstoneFilename = null;
        foreach ($this->entity->getValues() as $value) {
            if ($value->getProperty()->getId() === $propertyId) {
                $greenstoneFilename = $value->getValue();
                break;
            }
        }
        if (!$greenstoneFilename) {
            $this->logger->warn(
                'No filename to attach a media to item #{item_id}.', // @translate
                ['item_id' => $this->entity->getId()]
            );
            return;
        }

        // Le fichier a forcément une collection, sinon on ne peut pas déterminer son nom.
        if (empty($source['id_collection'])) {
            $this->logger->warn(
                'Pas de collection pour le contenu #{item_id}.', // @translate
                ['item_id' => $this->entity->getId()]
            );
            return;
        }
        $itemSetCode = $this->map['collections'][$source['id_collection']];

        /*
        $identifier = null;
        $propertyId = $this->bulk->getPropertyId('dcterms:identifier');
        foreach ($this->entity->getValues() as $value) {
            if ($value->getProperty()->getId() === $propertyId) {
                $identifier = $value->getValue();
                break;
            }
        }

        $uri = null;
        $propertyId = $this->bulk->getPropertyId('bibo:uri');
        foreach ($this->entity->getValues() as $value) {
            if ($value->getProperty()->getId() === $propertyId) {
                $uri = $value->getValue();
                break;
            }
        }
        */

        $title = $greenstoneFilename;

        // Les fichiers sont gérés par collection.

        // Images.
        // http://www.manioc.org/gsdl/collect/images/index/assoc/BBX17011/-0304i1.dir/BBX17011-0304i1.jpg
        //                                    /gsdl/collect/images/index/assoc/PAP11077/0018i4.dir/PAP110770018i4.jpg
        // Fichier : #15559 / BBX17011-0304i1 / collection 4
        // Identifiant : BBX17011-0304i1
        // greenstoneFilename : import/2018/BBX17011/BBX17011-0304i1.jpg

        // Audio-vidéo.
        // http://www.manioc.org/telecharger.php?collect=patrimon&fichier=http://www.manioc.org/patrimon/PAP11186
        // http://www.manioc.org/gsdl/collect/fichiers/import/video/2014/martinique/Hist-1205-8.mp4
        // greenstoneFilename : import/video/2014/martinique/Hist-1205-8.flv

        // Quand le fichier est un flv, un second fichier en mp4 se trouve dans
        // dcterms:isFormatOf, qui est le même sauf l'extension "mp4".
        if (strtolower(pathinfo($greenstoneFilename, PATHINFO_EXTENSION)) === 'flv') {
            $greenstoneFilename = substr_replace($greenstoneFilename, 'mp4', -3);
        }

        $extension = pathinfo($greenstoneFilename, PATHINFO_EXTENSION);
        $filenameBase = pathinfo($greenstoneFilename, PATHINFO_FILENAME);

        // Le lien n'est pas migré.
        switch ($itemSetCode) {
            case 'images':
                $filename = pathinfo($greenstoneFilename, PATHINFO_BASENAME);
                // Dossier ou 8 premières lettres ?
                $dossier = basename(dirname($greenstoneFilename));
                $sousDossier = substr($filenameBase, strlen($dossier)) . '.dir';
                $url = 'http://www.manioc.org/gsdl/collect/' . $itemSetCode . '/index/assoc/' . $dossier . '/' . $sousDossier . '/' . $filename;
                break;
            default:
                $url = 'http://www.manioc.org/gsdl/collect/' . $itemSetCode . '/' . $greenstoneFilename;
                break;
        }

        /** @var \Omeka\Entity\Media $media */
        $media = $this->entityManager
            ->find(\Omeka\Entity\Media::class, $this->map['media_items_sub'][$source[$this->mapping['media_items']['key_id']]]);
        $media->setOwner($this->entity->getOwner());
        $media->setItem($this->entity);
        // $media->setResourceClass($this->main['classes'][$class]);
        // $media->setResourceTemplate($this->main['templates']['Fichier']);
        $media->setTitle($title);
        $media->setIsPublic(true);
        $media->setIngester('upload');
        $media->setRenderer('file');
        // $media->setData(null);
        $media->setSource($source['fichier']);
        $media->setPosition(1);
        $media->setCreated($this->entity->getCreated());
        $modified = $this->entity->getModified();
        if ($modified) {
            $media->setModified($modified);
        }

        // @see \Omeka\File\TempFile::getStorageId()
        $storageId = $this->entity->getId() . '/' . $filenameBase;
        $result = $this->fetchUrl('original', $greenstoneFilename, $greenstoneFilename, $storageId, $extension, $url);
        if ($result['status'] !== 'success') {
            $this->logger->err($result['message']);
            if (!empty($this->filenamesToSha256[$greenstoneFilename])) {
                $media->setSha256($this->filenamesToSha256[$greenstoneFilename]);
            }
            $media->setStorageId($storageId);
            $media->setExtension(mb_strtolower($extension));
            $media->setHasOriginal(false);
            $media->setHasThumbnails(false);
            $media->setSize(0);
        }
        // Continue in order to update other metadata, in particular item.
        else {
            // Check if it is a fake file in order to reimport it later with the
            // module BulkCheck, that will update other technical data too.
            if (empty($result['data']['is_fake_file'])) {
                $media->setSha256($result['data']['sha256']);
            } elseif (!empty($this->filenamesToSha256[$greenstoneFilename])) {
                $media->setSha256($this->filenamesToSha256[$greenstoneFilename]);
            } else {
                $media->setSha256($result['data']['sha256']);
            }
            $media->setStorageId($storageId);
            $media->setExtension(mb_strtolower($extension));
            $media->setMediaType($result['data']['media_type']);
            $media->setHasOriginal(true);
            $media->setHasThumbnails($result['data']['has_thumbnails']);
            $media->setSize($result['data']['size']);
        }

        $values = [];
        $values[] = [
            'term' => 'dcterms:title',
            'lang' => null,
            'value' => $title,
            'is_public' => true,
        ];
        $this->appendValues($values, $media);
    }

    protected function fillItemSet(array $source): void
    {
        $source = array_map('trim', array_map('strval', $source));

        // Omeka entities are not fluid.
        /* @var \Omeka\Entity\ItemSet */
        $this->entity->setOwner($this->owner);
        $this->entity->setTitle($source['nom_collection']);
        $this->entity->setIsPublic($source['actif'] === 'oui');
        $this->entity->setCreated($this->currentDateTime);
        $this->entity->setIsOpen(true);

        $values = [];
        $values[] = [
            'term' => 'dcterms:title',
            'lang' => 'fra',
            'value' => html_entity_decode($source['nom_collection']),
        ];
        $values[] = [
            'term' => 'dcterms:identifier',
            'lang' => null,
            'value' => $source['code_collection'],
        ];
        $values[] = [
            'term' => 'dcterms:identifier',
            'lang' => null,
            'value' => 'greenstone_collection_id: ' . $source['id_collection'],
            'is_public' => false,
        ];

        $this->appendValues($values);
    }

    /**
     * Fill values for current resources.
     *
     * Because the source for metadata is structured like Omeka, a quick direct
     * import via some sql can be done. Just get the mapping of resource ids and
     * property ids.
     */
    protected function fillValues(iterable $sources): void
    {
        $normalizedMapping = $this->loadTableWithIds('properties', 'Property');
        if (!$normalizedMapping) {
            $this->logger->warn(
                'The mapping defined for values should use terms or property ids.' // @translate
            );
            return;
        }

        if (!$sources->count()) {
            $this->logger->warn(
                'There is no values in source.' // @translate
            );
            return;
        }

        $resources = array_filter($this->map['media_items']);
        if (!count($resources)) {
            $this->logger->warn(
                'There is no resource for values.' // @translate
            );
            return;
        }

        // Update values in temporary tables to simplify final copy.
        $this->logger->info(
            'Preparing filling of values.' // @translate
        );

        // Copy the mapping of source ids and resource ids.
        // It's quicker to use a temp file and it avoids a large query.
        $filepath = $this->saveKeyValuePairToTsv('media_items', true);
        if (empty($filepath)) {
            return;
        }

        $sql = '';

        // Store the mapping in database (source name => property id).
        $sql .= <<<SQL
# Store the mapping in database (source name => property id).
DROP TABLE IF EXISTS `_temporary_map_property`;
CREATE TEMPORARY TABLE `_temporary_map_property` (
    `nom` VARCHAR(190) NOT NULL,
    `property_id` INT unsigned NOT NULL,
    UNIQUE (`nom`)
);

SQL;
        $data = [];
        foreach ($normalizedMapping as $map) {
            $data[] = '"' . $map['source'] . '",' . $map['property_id'];
        }
        $sql .= 'INSERT INTO `_temporary_map_property` (`nom`, `property_id`) VALUES(' . implode('),(', $data) . ");\n";

        // Warning: a similar temporary table is used in ResourceTrait::createEmptyResources().
        $sql .= <<<SQL
# Copy the mapping of source ids and destination ids.
DROP TABLE IF EXISTS `_temporary_source_resource`;
CREATE TEMPORARY TABLE `_temporary_source_resource` (
    `id_fichier` INT unsigned NOT NULL,
    `resource_id` INT unsigned NOT NULL,
    UNIQUE (`id_fichier`)
);
# Require specific rights that may be not set, so fill ids via sql.
#LOAD DATA INFILE "$filepath"
#    INTO TABLE `_temporary_source_resource`
#    CHARACTER SET utf8;

SQL;

        // Don't use infile, because it may require infile global file rights,
        // that may be not set.
        // Warning: array_chunk() removes keys by default.
        foreach (array_chunk(array_filter($this->map['media_items']), self::CHUNK_RECORD_IDS, true) as $chunk) {
            array_walk($chunk, function (&$v, $k): void {
                $v = "$k,$v";
            });
            $sql .= 'INSERT INTO `_temporary_source_resource` (`id_fichier`,`resource_id`) VALUES(' . implode('),(', $chunk) . ");\n";
        }

        // Copy list of real metadata from source, and only mapped properties.
        $sql .= <<<SQL
# Copy list of real metadata from source.
DROP TABLE IF EXISTS `_temporary_source_value`;
CREATE TEMPORARY TABLE `_temporary_source_value` LIKE `_src_metadata`;
INSERT INTO `_temporary_source_value`
SELECT `_src_metadata`.*
FROM `_src_metadata`
JOIN `_temporary_map_property` ON `_temporary_map_property`.`nom` = `_src_metadata`.`nom`
WHERE (`_src_metadata`.`id_fichier` <> 0 AND `_src_metadata`.`id_fichier` IS NOT NULL)
    AND (`_src_metadata`.`nom` <> '' AND `_src_metadata`.`nom` IS NOT NULL)
    AND (`_src_metadata`.`valeur` <> '' AND `_src_metadata`.`valeur` IS NOT NULL);

SQL;

        // Remove metadata without file.
        $sql .= <<<SQL
# Remove metadata without file.
DELETE `_temporary_source_value`
FROM `_temporary_source_value`
LEFT JOIN `_temporary_source_resource` ON `_temporary_source_resource`.`id_fichier` = `_temporary_source_value`.`id_fichier`
WHERE `_temporary_source_resource`.`id_fichier` IS NULL;

SQL;

        // Decode html entities in values (only common ones).
        // Some values (description) may be html, so keep "<" and ">" in that
        // case. Some values with entities may be remaining.
        $sql .= <<<SQL
# Decode common html entities.
UPDATE `_temporary_source_value`
SET `_temporary_source_value`.`valeur` =
    TRIM(
    REPLACE(
    REPLACE(
    REPLACE(
    REPLACE(
    REPLACE(
    REPLACE(
    REPLACE(
    REPLACE(
    REPLACE(
    REPLACE(
    REPLACE(
    REPLACE(
    REPLACE(
    REPLACE(
    REPLACE(
    REPLACE(
    REPLACE(
        `_temporary_source_value`.`valeur`,
    '""', '"'),
    "&quot;", '"'),
    "&apos;", "'"),
    "&#034;", '"'),
    "&#039;", "'"),
    "&lsqb;", "["),
    "&rsqb;", "]"),
    "&#091;", "["),
    "&#093;", "]"),
    "&#095;", "_"),
    "&laquo;", "«"),
    "&raquo;", "»"),
    "&agrave;", "à"),
    "&eacute;", "é"),
    "&egrave;", "è"),
    "&Eacute;", "É"),
    "&Egrave;", "È")
    )
;
UPDATE `_temporary_source_value`
SET `_temporary_source_value`.`valeur` =
    TRIM(
    REPLACE(
    REPLACE(
    REPLACE(
    REPLACE(
        `_temporary_source_value`.`valeur`,
    "&lt;", "<"),
    "&gt;", '>'),
    "&#060;", "<"),
    "&#062;", '>')
    )
WHERE
    `_temporary_source_value`.`valeur` NOT LIKE "%>%"
    AND `_temporary_source_value`.`valeur` NOT LIKE "%<%"
;

SQL;

        // Replace source id by destination id.
        $sql .= <<<SQL
# Replace source id by destination id.
UPDATE `_temporary_source_value`
JOIN `_temporary_source_resource` ON `_temporary_source_resource`.`id_fichier` = `_temporary_source_value`.`id_fichier`
SET `_temporary_source_value`.`id_fichier` = `_temporary_source_resource`.`resource_id`;

SQL;

        // Replace source field id by property id.
        $sql .= <<<SQL
# Replace source field id by property id.
UPDATE `_temporary_source_value`
INNER JOIN `_temporary_map_property` ON `_temporary_map_property`.`nom` = `_temporary_source_value`.`nom`
SET `_temporary_source_value`.`nom` = `_temporary_map_property`.`property_id`;

SQL;

        // Copy all source values into destination table "value" with a simple
        // and single request.
        $sql .= <<<SQL
# Copy all source values into destination table "value".
INSERT INTO `value`
    (`resource_id`, `property_id`, `value_resource_id`, `type`, `lang`, `value`, `uri`, `is_public`)
SELECT
    `id_fichier`, `nom`, NULL, "literal", NULL, `valeur`, NULL, 1
FROM `_temporary_source_value`;

# Clean temp tables.
DROP TABLE IF EXISTS `_temporary_map_property`;
DROP TABLE IF EXISTS `_temporary_source_resource`;
DROP TABLE IF EXISTS `_temporary_source_value`;

SQL;

        $this->connection->executeQuery($sql);
        unlink($filepath);

        if (!empty($this->modules['BulkEdit'])) {
            /** @var \BulkEdit\Mvc\Controller\Plugin\TrimValues $trimValues */
            $trimValues = $this->getServiceLocator()->get('ControllerPluginManager')->get('trimValues');
            $trimValues();
        }

        $total = $this->connection->executeQuery('SELECT count(`id`) FROM `value`;')->fetchColumn();
        $this->logger->notice(
            '{total} values have been copied from the source.', // @translate
            ['total' => $total]
        );

        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->refreshMainResources();
    }

    /**
     * @todo Convertir les règles en modèles.
     */
    protected function fillItemTemplates(): void
    {
        // TODO Use the query builder.
        $this->logger->info(
            'Preparing filling of templates.' // @translate
        );

        // Check independantly, even if useless most of the time.
        $sqlTotal = 'SELECT count(`id`) FROM `resource` WHERE `resource_template_id` = :template_id;';

        // Les requêtes ne sont pas optimisées afin de faciliter les comparaisons.

        // Audio: extension mp3 (13 items).
        /*
        SELECT DISTINCT id_fichier
        FROM metadata
        WHERE
            (nom LIKE 'gsdlsourcefilename' AND valeur LIKE '%.mp3')
            OR
            (nom LIKE 'dc.Format' AND valeur LIKE 'audio/mp3');
        */
        $sqlSimple = <<<'SQL'
UPDATE `resource`
INNER JOIN `value` ON `value`.`resource_id` = `resource`.`id`
INNER JOIN `property` ON `property`.`id` = `value`.`property_id`
INNER JOIN `vocabulary` ON `vocabulary`.`id` = `property`.`vocabulary_id`
SET
    `resource`.`resource_template_id` = :template_id,
    `resource`.`resource_class_id` = :class_id
WHERE
    `resource`.`resource_type` = 'Omeka\\Entity\\Item'
    AND `vocabulary`.`prefix` = :vocabulary_prefix
    AND `property`.`local_name` = :property_name
    AND `value`.`value` LIKE :value;

SQL;
        $sql = $sqlSimple;
        $bind = [
            'template_id' => $this->map['resource_templates']['Audio'],
            'class_id' => $this->bulk->getResourceTemplateClassId('Audio'),
            'vocabulary_prefix' => 'greenstone',
            'property_name' => 'sourceFilename',
            'value' => '%.mp3',
        ];
        $this->connection->executeStatement($sql, $bind);
        $bind = [
            'template_id' => $this->map['resource_templates']['Audio'],
            'class_id' => $this->bulk->getResourceTemplateClassId('Audio'),
            'vocabulary_prefix' => 'dcterms',
            'property_name' => 'format',
            'value' => 'audio/mp3',
        ];
        $this->connection->executeStatement($sql, $bind);
        $result = $this->connection->executeQuery($sqlTotal, ['template_id' => $bind['template_id']])->fetchColumn();
        $this->logger->notice(
            'The template "{label}" has been set for {total} resources.',  // @translate
            ['label' => 'Audio', 'total' => $result]
        );

        // Document d’archive: a champ vient d’une archive (220 items).
        /*
        SELECT id_fichier
        FROM metadata
        WHERE nom LIKE "man.archive";
        */
        $sql = <<<'SQL'
UPDATE `resource`
INNER JOIN `value` ON `value`.`resource_id` = `resource`.`id`
INNER JOIN `property` ON `property`.`id` = `value`.`property_id`
INNER JOIN `vocabulary` ON `vocabulary`.`id` = `property`.`vocabulary_id`
SET
    `resource`.`resource_template_id` = :template_id,
    `resource`.`resource_class_id` = :class_id
WHERE
    `resource`.`resource_type` = 'Omeka\\Entity\\Item'
    AND `vocabulary`.`prefix` = :vocabulary_prefix
    AND `property`.`local_name` = :property_name;

SQL;
        $bind = [
            'template_id' => $this->map['resource_templates']["Document d'archives"],
            'class_id' => $this->bulk->getResourceTemplateClassId("Document d'archives"),
            'vocabulary_prefix' => 'manioc',
            'property_name' => 'archive',
        ];
        $this->connection->executeStatement($sql, $bind);
        $result = $this->connection->executeQuery($sqlTotal, ['template_id' => $bind['template_id']])->fetchColumn();
        $this->logger->notice(
            'The template "{label}" has been set for {total} resources.',  // @translate
            ['label' => "Document d'archives", 'total' => $result]
        );

        // Image : collection image sauf vient d’une archive (15776 items).
        /*
        SELECT id_fichier
        FROM fichiers
        WHERE id_collection = 4
            AND id_fichier NOT IN (
                SELECT  id_fichier FROM metadata WHERE nom LIKE 'man.archive'
            );
         */
        $sql = <<<'SQL'
UPDATE `resource`
INNER JOIN `value` ON `value`.`resource_id` = `resource`.`id`
INNER JOIN `property` ON `property`.`id` = `value`.`property_id`
INNER JOIN `vocabulary` ON `vocabulary`.`id` = `property`.`vocabulary_id`
SET
    `resource`.`resource_template_id` = :template_id,
    `resource`.`resource_class_id` = :class_id
WHERE
    `resource`.`resource_type` = 'Omeka\\Entity\\Item'
    AND (
        `vocabulary`.`prefix` = :vocabulary_prefix
        AND `property`.`local_name` = :property_name
        AND `value`.`value` LIKE :value
    )
    AND `resource`.`id` NOT IN (
        SELECT DISTINCT `value`.`resource_id`
        FROM `value`
        INNER JOIN `property` ON `property`.`id` = `value`.`property_id`
        INNER JOIN `vocabulary` ON `vocabulary`.`id` = `property`.`vocabulary_id`
        WHERE
            `vocabulary`.`prefix` = :vocabulary_prefix_2
            AND `property`.`local_name` = :property_name_2
    );

SQL;
        $bind = [
            'template_id' => $this->map['resource_templates']['Image'],
            'class_id' => $this->bulk->getResourceTemplateClassId('Image'),
            'vocabulary_prefix' => 'dcterms',
            'property_name' => 'identifier',
            'value' => 'greenstone_collection_id: 4',
            'vocabulary_prefix_2' => 'manioc',
            'property_name_2' => 'archive',
        ];
        $this->connection->executeStatement($sql, $bind);
        $result = $this->connection->executeQuery($sqlTotal, ['template_id' => $bind['template_id']])->fetchColumn();
        $this->logger->notice(
            'The template "{label}" has been set for {total} resources.',  // @translate
            ['label' => 'Image', 'total' => $result]
        );

        // Livre : collection “livres anciens/patrimoine numérisé” moins “vient d’une archive”
        // et moins types de document “numéros de revues” et “extraits de revues” (2466 items).
        /*
         SELECT id_fichier
         FROM fichiers
         WHERE id_collection = 1
         AND id_fichier NOT IN (SELECT  id_fichier FROM metadata WHERE nom LIKE 'man.archive')
         AND id_fichier NOT IN (SELECT id_fichier FROM metadata WHERE nom LIKE 'man.type' AND valeur LIKE 'Numéros de revues')
         AND id_fichier NOT IN (SELECT id_fichier FROM metadata WHERE nom LIKE 'man.type' AND valeur LIKE 'Extraits de revues');
         */
        $sql = <<<'SQL'
UPDATE `resource`
INNER JOIN `value` ON `value`.`resource_id` = `resource`.`id`
INNER JOIN `property` ON `property`.`id` = `value`.`property_id`
INNER JOIN `vocabulary` ON `vocabulary`.`id` = `property`.`vocabulary_id`
SET
    `resource`.`resource_template_id` = :template_id,
    `resource`.`resource_class_id` = :class_id
WHERE
    `resource`.`resource_type` = 'Omeka\\Entity\\Item'
    AND (
        `vocabulary`.`prefix` = :vocabulary_prefix
        AND `property`.`local_name` = :property_name
        AND `value`.`value` LIKE :value
    )
    AND `resource`.`id` NOT IN (
        SELECT DISTINCT `value`.`resource_id`
        FROM `value`
        INNER JOIN `property` ON `property`.`id` = `value`.`property_id`
        INNER JOIN `vocabulary` ON `vocabulary`.`id` = `property`.`vocabulary_id`
        WHERE
            `vocabulary`.`prefix` = :vocabulary_prefix_2
            AND `property`.`local_name` = :property_name_2
    )
    AND `resource`.`id` NOT IN (
        SELECT DISTINCT `value`.`resource_id`
        FROM `value`
        INNER JOIN `property` ON `property`.`id` = `value`.`property_id`
        INNER JOIN `vocabulary` ON `vocabulary`.`id` = `property`.`vocabulary_id`
        WHERE
            `vocabulary`.`prefix` = :vocabulary_prefix_3
            AND `property`.`local_name` = :property_name_3
            AND `value`.`value` LIKE :value_3
    )
    AND `resource`.`id` NOT IN (
        SELECT DISTINCT `value`.`resource_id`
        FROM `value`
        INNER JOIN `property` ON `property`.`id` = `value`.`property_id`
        INNER JOIN `vocabulary` ON `vocabulary`.`id` = `property`.`vocabulary_id`
        WHERE
            `vocabulary`.`prefix` = :vocabulary_prefix_4
            AND `property`.`local_name` = :property_name_4
            AND `value`.`value` LIKE :value_4
    );

SQL;
        $bind = [
            'template_id' => $this->map['resource_templates']['Livre'],
            'class_id' => $this->bulk->getResourceTemplateClassId('Livre'),
            'vocabulary_prefix' => 'dcterms',
            'property_name' => 'identifier',
            'value' => 'greenstone_collection_id: 1',
            'vocabulary_prefix_2' => 'manioc',
            'property_name_2' => 'archive',
            'vocabulary_prefix_3' => 'manioc',
            'property_name_3' => 'type',
            'value_3' => 'Numéros de revues',
            'vocabulary_prefix_4' => 'manioc',
            'property_name_4' => 'type',
            'value_4' => 'Extraits de revues',
        ];
        $this->connection->executeStatement($sql, $bind);
        $result = $this->connection->executeQuery($sqlTotal, ['template_id' => $bind['template_id']])->fetchColumn();
        $this->logger->notice(
            'The template "{label}" has been set for {total} resources.',  // @translate
            ['label' => 'Livre', 'total' => $result]
        );

        // Mémoire et thèse : a le type de document (4 items).
        /*
        SELECT id_fichier
        FROM metadata
        WHERE nom LIKE 'man.type' AND valeur LIKE 'Mémoires, thèses';
        */
        $sql = $sqlSimple;
        $bind = [
            'template_id' => $this->map['resource_templates']['Mémoire et thèse'],
            'class_id' => $this->bulk->getResourceTemplateClassId('Mémoire et thèse'),
            'vocabulary_prefix' => 'manioc',
            'property_name' => 'type',
            'value' => 'Mémoires, thèses',
        ];
        $this->connection->executeStatement($sql, $bind);
        $result = $this->connection->executeQuery($sqlTotal, ['template_id' => $bind['template_id']])->fetchColumn();
        $this->logger->notice(
            'The template "{label}" has been set for {total} resources.',  // @translate
            ['label' => 'Mémoire et thèse', 'total' => $result]
        );

        // Numéro de revue : type de document revue (146 items).
        /*
         SELECT id_fichier
         FROM metadata
         WHERE nom LIKE 'man.type' AND valeur LIKE 'Numéros de revues';
         */
        $sql = $sqlSimple;
        $bind = [
            'template_id' => $this->map['resource_templates']['Numéro de revue'],
            'class_id' => $this->bulk->getResourceTemplateClassId('Numéro de revue'),
            'vocabulary_prefix' => 'manioc',
            'property_name' => 'type',
            'value' => 'Numéros de revues',
        ];
        $this->connection->executeStatement($sql, $bind);
        $result = $this->connection->executeQuery($sqlTotal, ['template_id' => $bind['template_id']])->fetchColumn();
        $this->logger->notice(
            'The template "{label}" has been set for {total} resources.',  // @translate
            ['label' => 'Numéro de revue', 'total' => $result]
        );

        // Extrait de revue : type de document extrait de revue (56 items).
        /*
         SELECT id_fichier
         FROM metadata
         WHERE nom LIKE 'man.type' AND valeur LIKE 'Extraits de revues';
         */
        $sql = $sqlSimple;
        $bind = [
            'template_id' => $this->map['resource_templates']['Extrait de revue'],
            'class_id' => $this->bulk->getResourceTemplateClassId('Extrait de revue'),
            'vocabulary_prefix' => 'manioc',
            'property_name' => 'type',
            'value' => 'Extraits de revues',
        ];
        $this->connection->executeStatement($sql, $bind);
        $result = $this->connection->executeQuery($sqlTotal, ['template_id' => $bind['template_id']])->fetchColumn();
        $this->logger->notice(
            'The template "{label}" has been set for {total} resources.',  // @translate
            ['label' => 'Extrait de revue', 'total' => $result]
        );

        // Photographie => Image

        // Vidéo : collection audio-vidéo sauf audio (2895 items).
        /*
        SELECT id_fichier
        FROM fichiers
        WHERE id_collection = 2
            AND id_fichier NOT IN (
                SELECT id_fichier FROM metadata
                WHERE
                    (nom LIKE 'gsdlsourcefilename' AND valeur LIKE '%.mp3')
                    OR
                    (nom LIKE 'dc.Format' AND valeur LIKE 'audio/mp3')
            );
        */

        $sql = <<<'SQL'
UPDATE `resource`
INNER JOIN `value` ON `value`.`resource_id` = `resource`.`id`
INNER JOIN `property` ON `property`.`id` = `value`.`property_id`
INNER JOIN `vocabulary` ON `vocabulary`.`id` = `property`.`vocabulary_id`
SET
    `resource`.`resource_template_id` = :template_id,
    `resource`.`resource_class_id` = :class_id
WHERE
    `resource`.`resource_type` = 'Omeka\\Entity\\Item'
    AND (
        `vocabulary`.`prefix` = :vocabulary_prefix
        AND `property`.`local_name` = :property_name
        AND `value`.`value` LIKE :value
    )
    AND `resource`.`resource_template_id` IS NULL;

SQL;
        $bind = [
            'template_id' => $this->map['resource_templates']['Vidéo'],
            'class_id' => $this->bulk->getResourceTemplateClassId('Vidéo'),
            'vocabulary_prefix' => 'dcterms',
            'property_name' => 'identifier',
            'value' => 'greenstone_collection_id: 2',
        ];
        $this->connection->executeStatement($sql, $bind);
        $result = $this->connection->executeQuery($sqlTotal, ['template_id' => $bind['template_id']])->fetchColumn();
        $this->logger->notice(
            'The template "{label}" has been set for {total} resources.',  // @translate
            ['label' => 'Vidéo', 'total' => $result]
        );

        $this->logger->notice(
            'Updated templates for resources.' // @translate
        );

        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->refreshMainResources();
    }

    /**
     * Process specific transformations on each property of each template.
     *
     * The base config is in the table of migration and in the table of properties.
     *
     * @todo Normalize and merge the tables of migration and properties.
     */
    protected function migrateValues(): void
    {
        $migrationMapping = $this->loadTable('migration');
        if (!$migrationMapping) {
            $this->logger->warn(
                'No migration file is defined or it is empty.' // @translate
            );
            return;
        }

        // Prepare the template groups.
        // The process for all should be the first.
        $templateGroups = [self::TYPE_ALL => self::TYPE_ALL];

        // Les règles varient selon les 4 types d'origine, qui ont été remplacés
        // par des modèles dans l'étape précédente.
        $templates = $this->configs['templates'] ?? null;
        if (is_null($templates)) {
            $this->logger->warn(
                'There is no mapping for old and new templates.' // @translate
            );
        } else {
            $templates = array_filter($templates);
            if (count($templates)) {
                // Get the templates by group.
                foreach ($templates as $templateLabel => $templateGroup) {
                    $templateGroups[$templateGroup][] = $templateLabel;
                }
            } else {
                $this->logger->warn(
                    'The mapping for old and new templates is empty.' // @translate
                );
            }
        }

        $normalizedMapping = $this->loadTableWithIds('properties', 'Property');
        if (!$normalizedMapping) {
            $this->logger->warn(
                'The mapping defined for values should use terms or property ids.' // @translate
            );
            return;
        }

        // Check if value suggest is available in order to prepare a temp table.
        if (!empty($this->modules['ValueSuggest'])) {
            $sql = <<<SQL
DROP TABLE IF EXISTS `_temporary_valuesuggest`;
CREATE TABLE `_temporary_valuesuggest` (
    `id` int(11) NOT NULL,
    `property_id` int(11) DEFAULT NULL,
    `source` longtext COLLATE utf8mb4_unicode_ci,
    `items` longtext COLLATE utf8mb4_unicode_ci,
    `uri` longtext COLLATE utf8mb4_unicode_ci,
    `label` longtext COLLATE utf8mb4_unicode_ci,
    `info` longtext COLLATE utf8mb4_unicode_ci
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SQL;
            $this->connection->executeStatement($sql);
        }

        // Mettre à jour la colonne des éléments.
        foreach ($migrationMapping as $key => &$map) {
            $field = $map['source'] ?? null;
            if (empty($field)) {
                unset($migrationMapping[$key]);
            } else {
                foreach ($normalizedMapping as $nMap) {
                    if ($field === $nMap['source']) {
                        $mapped = $map;
                        unset($mapped['source']);
                        $map = [
                            'source' => $map['source'],
                            'destination' => $nMap['destination'],
                            'property_id' => $nMap['property_id'],
                            'map' => array_filter($mapped),
                        ];
                        if (empty($map['map'])) {
                            unset($migrationMapping[$key]);
                        }
                        break;
                    }
                }
                if (empty($map['property_id'])) {
                    unset($migrationMapping[$key]);
                    $this->logger->warn(
                        'No map for field "{label}".', // @translate
                        ['label' => $field]
                    );
                }
            }
        }
        unset($map);

        if (!count($migrationMapping)) {
            $this->logger->warn(
                'The mapping defined to migrate values should use terms or property ids.' // @translate
            );
            return;
        }

        $this->stats['removed'] = 0;
        $this->stats['not_managed'] = [];
        foreach ($templateGroups as $group => $templateLabels) {
            $processAll = $group === self::TYPE_ALL;
            if ($processAll) {
                $templateId = null;
                $this->logger->notice(
                    'Processing values for all templates.' // @translate
                );
            } else {
                $templateIds = [];
                foreach ($templateLabels as $templateLabel) {
                    $templateId = $this->bulk->getResourceTemplateId($templateLabel);
                    if (!$templateId) {
                        $this->logger->warn(
                            'Skipping "{template_group}": no template for "{label}".', // @translate
                            ['template_group' => $group,  'label' => $templateLabel]
                        );
                        continue 2;
                    }
                    $templateIds[] = $templateId;
                }
                $this->logger->notice(
                    'Processing values for template group "{template_group}".', // @translate
                    ['template_group' => $group]
                );
            }

            if ($processAll) {
                $sqlAndWhere = '';
                $baseBind = [];
                $baseTypes = [];
            } else {
                $sqlAndWhere = 'AND `resource`.`resource_template_id` IN (:resource_template_ids)';
                $baseBind = ['resource_template_ids' => $templateIds];
                $baseTypes = ['resource_template_ids' => $this->connection::PARAM_INT_ARRAY];
            }

            // Pre-process on whole resource.
            $this->transformValues(
                [
                    'map' => [
                        $group => '* Pre',
                    ],
                ],
                [
                    'group' => $group,
                    'templateLabels' => $templateLabels,
                    'sqlAndWhere' => $sqlAndWhere,
                    'baseBind' => $baseBind,
                    'baseTypes' => $baseTypes,
                ]
            );

            // Main process on each map.
            foreach ($migrationMapping as $map) {
                if (empty($map['map'][$group])) {
                    continue;
                }
                // All is the first process, so skip next ones when all is set.
                if ($group !== self::TYPE_ALL && !empty($map['map'][self::TYPE_ALL])) {
                    continue;
                }
                if ($this->isErrorOrStop()) {
                    break 2;
                }

                $this->transformValues($map, [
                    'group' => $group,
                    'templateLabels' => $templateLabels,
                    'sqlAndWhere' => $sqlAndWhere,
                    'baseBind' => $baseBind,
                    'baseTypes' => $baseTypes,
                ]);
            }

            // Post-process on whole resource.
            $this->transformValues(
                [
                    'map' => [
                        $group => '* Post',
                    ],
                ],
                [
                    'group' => $group,
                    'templateLabels' => $templateLabels,
                    'sqlAndWhere' => $sqlAndWhere,
                    'baseBind' => $baseBind,
                    'baseTypes' => $baseTypes,
                ]
            );
        }

        // Check if value suggest is available in order to prepare a temp table.
        if (!empty($this->modules['ValueSuggest'])) {
            $this->saveMappingsSourceUris();
            $sql = <<<SQL
DROP TABLE IF EXISTS `_temporary_valuesuggest`;
SQL;
            $this->connection->exec($sql);
        }

        $sql = <<<SQL
DROP TABLE IF EXISTS `_temporary_value`;
SQL;
        $this->connection->exec($sql);

        if ($this->stats['removed']) {
            $this->logger->warn(
                'A total of {total} values were removed.',  // @translate
                ['total' => $this->stats['removed']]
            );
        }

        if (count($this->stats['not_managed'])) {
            $this->logger->warn(
                'The following processes are not managed: "{list}".',  // @translate
                ['list' => implode('", "', $this->stats['not_managed'])]
            );
        }

        $this->logger->notice(
            'Values were transformed.' // @translate
        );

        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->refreshMainResources();
    }

    /**
     * Process a specific transform on the values.
     *
     * @todo Simplify config into one file.
     */
    protected function transformValues(array $map, array $options): void
    {
        $group = $options['group'];
        // $templateLabels = $options['templateLabels'];
        $sqlAndWhere = $options['sqlAndWhere'];
        $baseBind = $options['baseBind'];
        $baseTypes = $options['baseTypes'];

        $actions = [
            '+' => 'append',
            '-' => 'remove',
            '*' => 'transform',
            '?' => 'keep private',
        ];

        $bind = [];
        $value = $map['map'][$group];
        $action = mb_substr($value, 0, 1);
        if (!isset($actions[$action])) {
            $this->logger->warn(
                'Template group "{template_group}": action {action} not managed (value: {value}).', // @translate
                ['template_group' => $group, 'action' => $action, 'value' => $value]
            );
            return;
        }

        $action = $actions[$action];
        $value = trim(mb_substr($value, 1));

        if ($value === 'Pre') {
            $this->logger->info(
                'Template group "{template_group}": pre-processing values.', // @translate
                ['template_group' => $group]
            );
        } elseif ($value === 'Post') {
            $this->logger->info(
                'Template group "{template_group}": post-processing values.', // @translate
                ['template_group' => $group]
            );
        } else {
            $this->logger->info(
                'Template group "{template_group}": processing action "{action}" with value "{value}".', // @translate
                ['template_group' => $group, 'action' => $action, 'value' => $value]
            );
        }

        if ($action === 'append') {
            $this->logger->info(
                'Template group "{template_group}": processing action "{action}" with value "{value}": already processed in bulk.', // @translate
                ['template_group' => $group, 'action' => $action, 'value' => $value]
            );
            // Nothing to do because already migrated in bulk.
            return;
        }

        // To simplify process, a temporary table is created even when there is
        // no filter.
        $bind = $baseBind;
        if (empty($map['property_id'])) {
            $sqlAndProperty = '';
        } else {
            $sqlAndProperty = 'AND `value`.`property_id` = :property_id';
            $bind['property_id'] = $map['property_id'];
        }

        $sql = <<<SQL
DROP TABLE IF EXISTS `_temporary_value`;
CREATE TABLE `_temporary_value` (
    `id` int(11) NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `_temporary_value`
    (`id`)
SELECT
    `value`.`id`
FROM `value`
JOIN `resource`
    ON `resource`.`id` = `value`.`resource_id`
WHERE 1 = 1
    $sqlAndProperty
    $sqlAndWhere
;
SQL;

        $this->connection->executeStatement($sql, $bind, $baseTypes);
        $result = $this->connection->executeQuery('SELECT count(`id`) FROM `_temporary_value`;')->fetchColumn();
        if (!$result) {
            $this->logger->info(
                'Template group "{template_group}": no values to process.', // @translate
                ['template_group' => $group]
            );
            return;
        }

        $this->logger->info(
            'Template group "{template_group}": Processing {total} values from source "{source}" to property "{term}".', // @translate
            [
                'template_group' => $group,
                'total' => $result,
                'source' => empty($map['source']) ? '[any]' : $map['source'],
                'term' => empty($map['destination']) ? '[any]' : $map['destination'],
            ]
        );

        // ATTENTION : ne pas supprimer en amont les propriétés dont on a besoin en aval.
        if ($action === 'remove') {
            $this->transformOperations([
                [
                    'action' => 'remove_value',
                    'params' => [
                        'properties' => [
                            $map['property_id'],
                        ],
                    ],
                ],
            ]);
            return;
        }

        if ($action === 'keep private') {
            $this->transformOperations([
                [
                    'action' => 'modify_value',
                    'params' => [
                        'source' => $map['property_id'],
                        'is_public' => false,
                    ],
                ],
            ]);
            return;
        }

        // Action "transform".
        switch ($value) {
            // Effectue des modifications avant toute autre modification.
            case 'Pre':
                if ($group === self::TYPE_ALL) {
                    $this->transformOperations([
                        [
                            // Crée une ressource liée à partir du champ "auteur/contributeur".
                            'action' => 'create_resource',
                            'params' => [
                                'mapping_properties' => [
                                    'dcterms:creator' => 'foaf:name',
                                    'dcterms:contributor' => 'foaf:name',
                                    'manioc:personne' => 'foaf:name',
                                    // Organisateur audio-vidéo ci-après.
                                ],
                                // Il faut le mettre ici, sinon on ne pourra plus
                                // repérer les ressources pour le groupe "personnes et collectivités"
                                // sauf via le code random.
                                // TODO Permettre l'utilisation du code random dans l'étape finale (via un index spécifique à ajouter ici).
                                'template' => 'Personne',
                                'link_resource' => true,
                                'reciprocal' => null,
                            ],
                        ],
                        [
                            'action' => 'create_resource',
                            'params' => [
                                'mapping_properties' => [
                                    'manioc:themeGeneral' => 'dcterms:title',
                                ],
                                'resource_type' => 'item_sets',
                                'template' => 'Corpus et sélection documentaire',
                            ],
                        ],
                        [
                            'action' => 'attach_item_set',
                            'params' => [
                                'source' => 'manioc:themeGeneral',
                                'identifier' => 'dcterms:title',
                            ],
                        ],
                    ]);
                } elseif ($group === self::TYPE_AUDIO_VIDEO) {
                    $this->transformOperations([
                        [
                            'action' => 'create_resource',
                            'params' => [
                                'mapping_properties' => [
                                    'dcterms:publisher' => 'foaf:name',
                                ],
                                'link_resource' => true,
                                'reciprocal' => null,
                            ],
                        ],
                        [
                            'action' => 'create_resource',
                            'params' => [
                                'mapping' => 'manifestations',
                                'template' => 'Manifestation',
                                // Simplifie la création des ressources liées.
                                'source_term' => 'bio:olb',
                            ],
                        ],
                        [
                            'action' => 'link_resource',
                            'params' => [
                                'source' => 'dcterms:isPartOf',
                                'identifier' => 'bio:olb',
                                'destination' => 'dcterms:isPartOf',
                                'reciprocal' => 'dcterms:hasPart',
                                'keep_source' => false,
                            ],
                        ],
                        /* // La source est conservée pour l'enrichissement.
                        [
                            'action' => 'remove_value',
                            'params' => [
                                // Sur les nouvelles manifestations,
                                // pas les audio-vidéos.
                                'on' => [
                                    'resource_random' => -2,
                                ],
                                'properties' => [
                                    'bio:olb',
                                ],
                            ],
                        ],
                        */
                    ]);
                } elseif ($group === self::TYPE_IMAGE) {
                    // Cette opération ne dépend pas de la propriété en cours
                    // mais des ressources.
                    // Attention: la source manioc:internalLink est supprimée
                    // lors d'une étape postérieure.
                    $this->transformOperations([
                        // Le titre est issu de la valeur dc.Relation^IsPartOf,
                        // mise à jour dans l'opération précédente, mais il
                        // est aussi disponible dans manioc:internalLink
                        // et dans manioc:etagere.
                        [
                            'action' => 'link_resource',
                            'params' => [
                                'source' => 'manioc:internalLink',
                                // "man.link" a été déplacé en bibo:uri.
                                'identifier' => 'bibo:uri',
                                'destination' => 'dcterms:isPartOf',
                                // Le titre est à rechercher dans les livres anciens,
                                // même s'il devrait être unique.
                                // 'filter' => [
                                //     'template' => 'Livre',
                                // ],
                                // Ajout des liens inverses dans la notice du livre
                                // dans dcterms:hasPart.
                                'reciprocal' => 'dcterms:hasPart',
                                'keep_source' => true,
                            ],
                        ],
                        [
                            // Il y aura de nombreux doublons, mais ils
                            // seron supprimés lors de la dernière étape
                            // via "DeduplicateValues".
                            'action' => 'link_resource',
                            'params' => [
                                'source' => 'manioc:etagere',
                                'identifier' => 'dcterms:title',
                                'destination' => 'dcterms:isPartOf',
                                'reciprocal' => 'dcterms:hasPart',
                                'keep_source' => true,
                            ],
                        ],
                    ]);
                } elseif ($group === self::TYPE_PERSONNE_COLLECTIVITE) {
                    $this->transformOperations([
                        [
                            'action' => 'append_value',
                            'params' => [
                                'source' => 'foaf:name',
                                // Faux type de données : personnes et organisations.
                                'datatype' => 'valuesuggest:idref:author',
                                'mapping' => 'valuesuggest:idref:author',
                                'partial_mapping' => true,
                                'name' => 'auteurs_et_contributeurs',
                                'prefix' => 'https://www.idref.fr/',
                                // Dans le fichier original, il y a des espaces
                                // et des différences entre le nom à chercher
                                // et le nom standard (exemple : Silva, Joaquim Caetano da  (1810-1873)),
                                // notamment suite à des alignements manuels
                                // qui n'ont pas été reportés dans le tableau,
                                // ce qui fait qu'on ne peut le trouver, même s'il est bien
                                // identifié. On utilise donc également la colonne
                                // standard ou tout autre colonne unique pour
                                // faire le rapprochement.
                                'valid_sources' => [
                                    'label',
                                ],
                                // Les dates, lieux, etc. sont déjà ajoutés via la table.
                                'properties' => [
                                    'identifier' => 'bibo:uri',
                                    'info' => 'bio:biography',
                                    'bio:birth' => 'bio:birth',
                                    'bio:death' => 'bio:death',
                                    'bio:biography' => 'bio:biography',
                                    'dcterms:bibliographicCitation' => 'dcterms:bibliographicCitation',
                                ],
                                'identifier_to_templates_and_classes' => [
                                    'valuesuggest:idref:person' => 'Personne',
                                    'valuesuggest:idref:corporation' => 'Collectivité',
                                    'valuesuggest:idref:conference' => 'Collectivité',
                                ],
                            ],
                        ],
                        // The filling cannot be done here, since bibo:uri is
                        // not yet a uri.
                    ]);
                } elseif ($group === self::TYPE_MANIFESTATION) {
                    $this->transformOperations([
                        [
                            'action' => 'append_value',
                            'params' => [
                                'source' => 'bio:olb',
                                // Faux type de données : personnes et organisations.
                                'datatype' => 'valuesuggest:idref:author',
                                'mapping' => 'valuesuggest:idref:author',
                                'partial_mapping' => true,
                                'name' => 'auteurs_et_contributeurs',
                                'prefix' => 'https://www.idref.fr/',
                                // Les dates, lieux, etc. sont déjà ajoutés via la table.
                                'properties' => [
                                    'identifier' => 'bibo:uri',
                                    'dcterms:bibliographicCitation' => 'dcterms:bibliographicCitation',
                                ],
                            ],
                        ],
                    ]);
                }
            break;

            case 'Audience':
                $this->transformOperations([
                    [
                        'action' => 'replace_table',
                        'params' => [
                            'source' => $map['property_id'],
                            'mapping' => 'dcterms:audience',
                        ],
                    ],
                ]);
                break;

            case 'Auteur':
            case 'Auteur secondaire':
            case 'Personne (sujet)':
                // Rien à faire : ils ont tous été déplacés en notices personne
                // ou collectivité en étape Pre.
                break;

            case 'Droits':
                $this->transformOperations([
                    [
                        'action' => 'replace_table',
                        'params' => [
                            'source' => $map['property_id'],
                            'mapping' => 'dcterms:rights',
                        ],
                    ],
                ]);
                break;

            case 'Editeur':
                switch ($group) {
                    case self::TYPE_LIVRE:
                        // Lieu d’édition : éditeur
                        // dcterms:publisher => bio:place (geonames) / dcterms:publisher (literal)
                        // Mais certains éditeurs ne sont pas des lieux : "ANR : Agence Nationale de la Recherche".
                        $this->transformOperations([
                            [
                                'action' => 'cut_value',
                                'params' => [
                                    'source' => $map['property_id'],
                                    'exclude' => 'editeurs_sans_lieu',
                                    'separator' => ':',
                                    'destination' => [
                                        'bio:place',
                                        'dcterms:publisher',
                                    ],
                                ],
                            ],
                            // Fonctionne car la liste des valeurs a été mise à jour
                            // dans l'opération précédente et que cette opération
                            // applique les requêtes précédentes.
                            [
                                'action' => 'convert_datatype',
                                'params' => [
                                    'datatype' => 'valuesuggest:geonames:geonames',
                                    'source' => $this->getPropertyId('bio:place'),
                                    'mapping' => 'geonames',
                                    'partial_mapping' => true,
                                    'name' => 'lieux',
                                    'prefix' => 'http://www.geonames.org/',
                                ],
                            ],
                        ]);
                        break;

                    default:
                        break;
                }
                break;

            case 'Etablissement':
                $this->transformOperations([
                    [
                        'action' => 'replace_table',
                        'params' => [
                            'source' => $map['property_id'],
                            'mapping' => 'dcterms:rightsHolder',

                        ],
                    ],
                ]);
                break;

            case 'Fait partie de':
                if ($group === self::TYPE_IMAGE) {
                    // dcterms:title : Titre. Tome n° => dcterms:title / bibo:pages
                    $this->transformOperations([
                        [
                            'action' => 'replace_table',
                            'params' => [
                                'source' => $map['property_id'],
                                'mapping' => 'partie_images',
                            ],
                        ],
                    ]);
                }
                break;

            case 'Indice Dewey':
                // dcterms:subject ^^customvocab:Thématiques
                $this->transformOperations([
                    [
                        'action' => 'replace_table',
                        'params' => [
                            'source' => $map['property_id'],
                            // Il est inutile d'indiquer la destination.
                            'destination' => 'dcterms:subject',
                            'mapping' => 'dewey_themes',
                            'settings' => [
                                /*
                                // dcterms:subject ^^uri
                                'dcterms:subject' => [
                                     'replace' => 'http://dewey.info/class/{source}/ {destination}',
                                     'remove_space_source' => true,
                                ],
                                */
                            ],
                        ],
                    ],
                ]);
                break;

            case 'Langue':
                $this->transformOperations([
                    [
                        'action' => 'replace_table',
                        'params' => [
                            'source' => $map['property_id'],
                            'mapping' => 'dcterms:language',
                            'settings' => [
                                'dcterms:language' => [
                                    'prefix' => 'http://id.loc.gov/vocabulary/iso639-2/',
                                ],
                            ],
                        ],
                    ],
                ]);
                break;

            case 'Pays|Ville (sujet)':
                $this->transformOperations([
                    [
                        'action' => 'convert_datatype',
                        'params' => [
                            'datatype' => 'valuesuggest:geonames:geonames',
                            'source' => $map['property_id'],
                            'mapping' => 'geonames',
                            'partial_mapping' => true,
                            'name' => 'lieux',
                            'prefix' => 'http://www.geonames.org/',
                            'formats' => [
                                [
                                    'arguments' => ['country', 'location'],
                                    'separator' => '|',
                                ],
                                [
                                    'arguments' => 'country',
                                ],
                            ],
                        ],
                    ],
                ]);
                break;

            case 'Siècle (sujet)':
                $this->transformOperations([
                    [
                        'action' => 'replace_table',
                        'params' => [
                            'source' => $map['property_id'],
                            'mapping' => 'dcterms:temporal',
                        ],
                    ],
                ]);
                break;

            case 'Sujet géographique':
                $this->transformOperations([
                    [
                        'action' => 'convert_datatype',
                        'params' => [
                            'datatype' => 'valuesuggest:geonames:geonames',
                            'source' => $map['property_id'],
                            'mapping' => 'geonames',
                            'partial_mapping' => true,
                            'name' => 'lieux',
                            'prefix' => 'http://www.geonames.org/',
                        ],
                    ],
                ]);
                break;

            case 'Mot-clé':
            case 'Thématique audio-vidéo':
            case 'Thématique images':
            case 'Thématique ouvrages numérisés':
            case 'Thématique recherche':
                $this->transformOperations([
                    [
                        'action' => 'convert_datatype',
                        'params' => [
                            'datatype' => 'valuesuggest:idref:rameau',
                            'source' => $map['property_id'],
                            'mapping' => 'valuesuggest:idref:rameau',
                            'partial_mapping' => true,
                            'name' => 'thematiques',
                            'prefix' => 'https://www.idref.fr/',
                            'get_top_subject' => true,
                        ],
                    ],
                ]);
                break;

            case 'Titre':
                if ($group === self::TYPE_LIVRE) {
                    // dcterms:title : Titre. Tome n° => dcterms:title / bibo:volume
                    $this->transformOperations([
                        [
                            'action' => 'replace_table',
                            'params' => [
                                'source' => $map['property_id'],
                                'mapping' => 'titres_livres_anciens',
                            ],
                        ],
                    ]);
                }
                break;

            case 'Post':
                if ($group === self::TYPE_AUDIO_VIDEO) {
                    $this->transformOperations([
                        [
                            'action' => 'copy_value_linked',
                            'params' => [
                                'source' => 'dcterms:isPartOf',
                                'properties' => [
                                    'dcterms:language',
                                    'dcterms:audience',
                                ],
                            ],
                        ],
                    ]);
                } elseif ($group === self::TYPE_LIVRE) {
                    $this->transformOperations([
                        [
                            'action' => 'modify_value',
                            'params' => [
                                'source' => 'dcterms:description',
                                'destination' => 'dcterms:abstract',
                            ],
                        ],
                    ]);
                } elseif ($group === self::TYPE_PERSONNE_COLLECTIVITE) {
                    $this->transformOperations([
                        [
                            'action' => 'fill_resource',
                            'params' => [
                                'source' => 'bibo:uri',
                                'properties' => [
                                    '/record/datafield[@tag="200"]/subfield[@code="a"][1]' => 'foaf:familyName',
                                    '/record/datafield[@tag="200"]/subfield[@code="b"][1]' => 'foaf:givenName',
                                    '/record/datafield[@tag="900"]/subfield[@code="a"][1]' => 'dcterms:alternative',
                                    '/record/datafield[@tag="901"]/subfield[@code="a"][1]' => 'dcterms:alternative',
                                    '/record/datafield[@tag="902"]/subfield[@code="a"][1]' => 'dcterms:alternative',
                                    '/record/datafield[@tag="910"]/subfield[@code="a"][1]' => 'dcterms:alternative',
                                    '/record/datafield[@tag="911"]/subfield[@code="a"][1]' => 'dcterms:alternative',
                                    '/record/datafield[@tag="912"]/subfield[@code="a"][1]' => 'dcterms:alternative',
                                    // Dates are already updated with search query.
                                    '/record/datafield[@tag="103"]/subfield[@code="a"][1]' => 'bio:birth',
                                    '/record/datafield[@tag="103"]/subfield[@code="b"][1]' => 'bio:death',
                                    '/record/datafield[@tag="120"]/subfield[@code="a"][1]' => 'foaf:gender',
                                    '/record/datafield[@tag="101"]/subfield[@code="a"][1]' => 'dcterms:language',
                                    '/record/datafield[@tag="102"]/subfield[@code="a"][1]' => 'bio:place',
                                    '/record/datafield[@tag="200"]/subfield[@code="c"][1]' => 'bio:biography',
                                    '/record/datafield[@tag="300"]/subfield[@code="a"][1]' => 'bio:biography',
                                    '/record/datafield[@tag="340"]/subfield[@code="a"][1]' => 'bio:biography',
                                    '/record/datafield[@tag="810"]/subfield[@code="a"][1]' => 'dcterms:bibliographicCitation',
                                    '/record/datafield[@tag="810"]/subfield[@code="b"][1]' => 'dcterms:bibliographicCitation',
                                ],
                            ],
                        ],
                        [
                            'action' => 'modify_value',
                            'params' => [
                                'source' => 'bibo:uri',
                                'filters' => [
                                    'datatypes' => [
                                        'uri',
                                        'valuesuggest',
                                    ],
                                ],
                                'value' => null,
                            ],
                        ],
                    ]);
                }
                break;

            default:
                $this->stats['not_managed'][] = $value;
                break;
        }
    }

    protected function finalizeValues(): void
    {
        // Rendre privées toutes les valeurs des ontologies spécifiques.
        $sql = <<<'SQL'
UPDATE `value`
JOIN `property` ON `property`.`id` = `value`.`property_id`
JOIN `vocabulary` ON `vocabulary`.`id` = `property`.`vocabulary_id`
SET
    `value`.`is_public` = 0
WHERE
    `vocabulary`.`prefix` IN (
        "greenstone",
        "manioc"
    )
;
SQL;
        $this->connection->executeStatement($sql);
    }

    protected function completionOtherJobs(): void
    {
        // TODO Bulk Edit Géonames label et Rameau label.
    }

    /**
     * The source table is prefixed with "_src_".
     */
    protected function copyTable(string $table): bool
    {
        $sourceDatabase = $this->reader->databaseName();
        $sql = <<<SQL
DROP TABLE IF EXISTS `_src_$table`;
CREATE TABLE `_src_$table` LIKE `$sourceDatabase`.`$table`;
SQL;
        $this->connection->exec($sql);
        // Casting is required.
        if ((int) $this->connection->errorCode()) {
            $this->hasError = true;
            $this->logger->err(
                'Cannot copy table "{table}" from the source database: {message}', // @translate
                ['table' => $table, 'message' => reset($this->connection->errorInfo())]
            );
            return false;
        }

        $sql = <<<SQL
INSERT INTO `_src_$table` SELECT * FROM `$sourceDatabase`.`$table`;
SQL;
        $result = $this->connection->exec($sql);
        $this->logger->info(
            'Copied {total} rows from the table "{table}".', // @translate
            ['total' => $result, 'table' => $table]
        );

        return true;
    }

    /**
     * The source table is prefixed with "_src_".
     */
    protected function copyTableViaInfile(string $table): bool
    {
        // @see https://dev.mysql.com/doc/refman/8.0/en/load-data.html
        // Default input is tab-separated values without enclosure.
        $this->reader->setObjectType($table);
        $filepath = $this->reader->saveCsv();
        if (!$filepath) {
            $this->hasError = true;
            $this->logger->err(
                'Cannot save table "{table}" to a temporary file.', // @translate
                ['table' => $table]
            );
            return false;
        }

        $createTableQuery = $this->reader->sqlQueryCreateTable();
        $createTableQuery = str_replace("CREATE TABLE `$table`", "CREATE TABLE `_src_$table`", $createTableQuery);

        $hasCharset = strrpos($createTableQuery, ' CHARSET=');
        $charset = $hasCharset
            ? 'SET NAMES "' . trim(substr($createTableQuery, $hasCharset + 9)) . '";'
            : '';

        $sql = <<<SQL
DROP TABLE IF EXISTS `_src_$table`;
$createTableQuery;
$charset
LOAD DATA INFILE "$filepath"
    INTO TABLE `_src_$table`
    CHARACTER SET utf8;
SQL;

        $this->connection->exec($sql);
        @unlink($filepath);

        // Casting is required.
        if ((int) $this->connection->errorCode()) {
            $this->hasError = true;
            $this->logger->err(
                'Cannot load table "{table}" from a temporary file: {message}', // @translate
                ['table' => $table, 'message' => reset($this->connection->errorInfo())]
            );
            return false;
        }

        return true;
    }

    protected function removeTable(string $table): bool
    {
        $sql = <<<SQL
DROP TABLE IF EXISTS `_src_$table`;
SQL;
        $this->connection->exec($sql);
        return true;
    }
}
