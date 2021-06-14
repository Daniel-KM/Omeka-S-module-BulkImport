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

        if (!$this->reader->canReadDirectly()) {
            $this->hasError = true;
            $this->logger->err(
                "The Omeka database user should be able to read the source database, so run this query or similar with the database admin user: 'GRANT SELECT ON `{database}`.* TO '{omeka_database_user}'@'{host}';",  // @translate
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

        $this->connection->query($sql);
        unlink($filepath);

        if (!empty($this->modules['BulkEdit'])) {
            /** @var \BulkEdit\Mvc\Controller\Plugin\TrimValues $trimValues */
            $trimValues = $this->getServiceLocator()->get('ControllerPluginManager')->get('trimValues');
            $trimValues();
        }

        $total = $this->connection->query('SELECT count(`id`) FROM `value`;')->fetchColumn();
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
WHERE `resource`.`resource_type` = 'Omeka\\Entity\\Item'
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
        $this->connection->executeUpdate($sql, $bind);
        $bind = [
            'template_id' => $this->map['resource_templates']['Audio'],
            'class_id' => $this->bulk->getResourceTemplateClassId('Audio'),
            'vocabulary_prefix' => 'dcterms',
            'property_name' => 'format',
            'value' => 'audio/mp3',
        ];
        $this->connection->executeUpdate($sql, $bind);
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
WHERE `resource`.`resource_type` = 'Omeka\\Entity\\Item'
    AND `vocabulary`.`prefix` = :vocabulary_prefix
    AND `property`.`local_name` = :property_name;

SQL;
        $bind = [
            'template_id' => $this->map['resource_templates']["Document d'archives"],
            'class_id' => $this->bulk->getResourceTemplateClassId("Document d'archives"),
            'vocabulary_prefix' => 'manioc',
            'property_name' => 'archive',
        ];
        $this->connection->executeUpdate($sql, $bind);
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
WHERE `resource`.`resource_type` = 'Omeka\\Entity\\Item'
    AND (
        `vocabulary`.`prefix` = :vocabulary_prefix
        AND `property`.`local_name` = :property_name
        AND `value`.`value` LIKE :value
    )
    AND `resource`.`id` NOT IN (
        SELECT `value`.`resource_id`
        FROM `value`
        INNER JOIN `property` ON `property`.`id` = `value`.`property_id`
        INNER JOIN `vocabulary` ON `vocabulary`.`id` = `property`.`vocabulary_id`
        WHERE `vocabulary`.`prefix` = :vocabulary_prefix_2
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

        $this->connection->executeUpdate($sql, $bind);
        $result = $this->connection->executeQuery($sqlTotal, ['template_id' => $bind['template_id']])->fetchColumn();
        $this->logger->notice(
            'The template "{label}" has been set for {total} resources.',  // @translate
            ['label' => 'Image', 'total' => $result]
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
        $this->connection->executeUpdate($sql, $bind);
        $result = $this->connection->executeQuery($sqlTotal, ['template_id' => $bind['template_id']])->fetchColumn();
        $this->logger->notice(
            'The template "{label}" has been set for {total} resources.',  // @translate
            ['label' => 'Mémoire et thèse', 'total' => $result]
        );

        // Numéro de revue : type de document revue (0 items).
        /*
        SELECT id_fichier
        FROM metadata
        WHERE nom LIKE 'man.type' AND valeur LIKE 'Numéro de périodique';
        */
        $sql = $sqlSimple;
        $bind = [
            'template_id' => $this->map['resource_templates']['Numéro de revue'],
            'class_id' => $this->bulk->getResourceTemplateClassId('Numéro de revue'),
            'vocabulary_prefix' => 'manioc',
            'property_name' => 'type',
            'value' => 'Numéro de périodique',
        ];
        $this->connection->executeUpdate($sql, $bind);
        $result = $this->connection->executeQuery($sqlTotal, ['template_id' => $bind['template_id']])->fetchColumn();
        $this->logger->notice(
            'The template "{label}" has been set for {total} resources.',  // @translate
            ['label' => 'Numéro de revue', 'total' => $result]
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
WHERE `resource`.`resource_type` = 'Omeka\\Entity\\Item'
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
        $this->connection->executeUpdate($sql, $bind);
        $result = $this->connection->executeQuery($sqlTotal, ['template_id' => $bind['template_id']])->fetchColumn();
        $this->logger->notice(
            'The template "{label}" has been set for {total} resources.',  // @translate
            ['label' => 'Vidéo', 'total' => $result]
        );

        // Livre : collection ouvrage sauf vient d’une archive sauf type de document revue (2627 items).
        // On le met en dernier de façon à l'appliquer à tous ceux sans modèle dans la collection.
        /*
        SELECT id_fichier
        FROM fichiers
        WHERE id_collection = 1
        AND id_fichier NOT IN (SELECT  id_fichier FROM metadata WHERE nom LIKE 'man.archive')
        AND id_fichier NOT IN (SELECT id_fichier FROM metadata WHERE nom LIKE 'man.type' AND valeur LIKE 'Numéro de périodique');
        */
        $sql = <<<'SQL'
UPDATE `resource`
INNER JOIN `value` ON `value`.`resource_id` = `resource`.`id`
INNER JOIN `property` ON `property`.`id` = `value`.`property_id`
INNER JOIN `vocabulary` ON `vocabulary`.`id` = `property`.`vocabulary_id`
SET
    `resource`.`resource_template_id` = :template_id,
    `resource`.`resource_class_id` = :class_id
WHERE `resource`.`resource_type` = 'Omeka\\Entity\\Item'
    AND (
        `vocabulary`.`prefix` = :vocabulary_prefix
        AND `property`.`local_name` = :property_name
        AND `value`.`value` LIKE :value
    )
    AND `resource`.`resource_template_id` IS NULL;

SQL;
        $bind = [
            'template_id' => $this->map['resource_templates']['Livre'],
            'class_id' => $this->bulk->getResourceTemplateClassId('Livre'),
            'vocabulary_prefix' => 'dcterms',
            'property_name' => 'identifier',
            'value' => 'greenstone_collection_id: 1',
        ];
        $this->connection->executeUpdate($sql, $bind);
        $result = $this->connection->executeQuery($sqlTotal, ['template_id' => $bind['template_id']])->fetchColumn();
        $this->logger->notice(
            'The template "{label}" has been set for {total} resources.',  // @translate
            ['label' => 'Livre', 'total' => $result]
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

        // Les règles varient selon les 4 types d'origine, qui ont été remplacés
        // par des modèles dans l'étape précédente.
        $templates = $this->loadKeyValuePair('templates');

        $templates = array_filter($templates);
        if (is_null($templates)) {
            $templates = [];
            $this->logger->warn(
                'There is no mapping for old and new templates.' // @translate
            );
        } elseif (!count($templates)) {
            $this->logger->warn(
                'The mapping for old and new templates is empty (file "{file}").', // @translate
                ['file' => $this->configs['templates']['file']]
            );
        }

        // Add the common transformation for all resources.
        // It should be the first template.
        $templates = ['all' => 'all'] + $templates;

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
            $this->connection->executeUpdate($sql);
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
        foreach ($templates as $templateLabel => $header) {
            $processAll = $header === 'all';
            if ($processAll) {
                $templateId = null;
                $this->logger->notice(
                    'Processing values for all templates.' // @translate
                );
            } else {
                $templateId = $this->bulk->getResourceTemplateId($templateLabel);
                if (!$templateId) {
                    $this->logger->warn(
                        'No template for "{label}".', // @translate
                        ['label' => $templateLabel]
                    );
                    continue;
                }
                $this->logger->notice(
                    'Processing values for template "{template}".', // @translate
                    ['template' => $templateLabel]
                );
            }

            if ($processAll) {
                $sqlAndWhere = '';
                $baseBind = [];
            } else {
                $sqlAndWhere = 'AND `resource`.`resource_template_id` = :resource_template_id';
                $baseBind = ['resource_template_id' => $templateId];
            }

            foreach ($migrationMapping as $map) {
                if (empty($map['map'][$header])) {
                    continue;
                }
                // All is the first process, so skip next ones when all is set.
                if ($header !== 'all' && !empty($map['map']['all'])) {
                    continue;
                }
                if ($this->isErrorOrStop()) {
                    break 2;
                }

                $this->transformValues($map, [
                    'templateLabel' => $templateLabel,
                    'header' => $header,
                    'sqlAndWhere' => $sqlAndWhere,
                    'baseBind' => $baseBind,
                ]);
            }
        }

        // Check if value suggest is available in order to prepare a temp table.
        if (!empty($this->modules['ValueSuggest'])) {
            $this->saveValueSuggestMappings();
            $sql = <<<SQL
DROP TABLE IF EXISTS `_temporary_valuesuggest`;
SQL;
            $this->connection->exec($sql);
        }

        $sql = <<<SQL
DROP TABLE IF EXISTS `_temporary_value_id`;
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
        $header = $options['header'];
        $templateLabel = $options['templateLabel'];
        $sqlAndWhere = $options['sqlAndWhere'];
        $baseBind = $options['baseBind'];

        $actions = [
            '+' => 'append',
            '-' => 'remove',
            '*' => 'transform',
            '?' => 'keep private',
        ];

        $bind = [];
        $value = $map['map'][$header];
        $action = mb_substr($value, 0, 1);
        if (!isset($actions[$action])) {
            $this->logger->warn(
                'Template "{template}": action {action} not managed (value: {value}).', // @translate
                ['template' => $templateLabel, 'action' => $action, 'value' => $value]
            );
            return;
        }
        $action = $actions[$action];
        $value = trim(mb_substr($value, 1));

        $this->logger->info(
            'Template "{template}": processing action "{action}" with value "{value}".', // @translate
            ['template' => $templateLabel, 'action' => $action, 'value' => $value]
        );

        switch ($action) {
            case 'append':
                // Nothing to do because already migrated in bulk.
                break;

            case 'remove':
                $sql = <<<SQL
DELETE `value`
FROM `value`
JOIN `resource` ON `resource`.`id` = `value`.`resource_id`
WHERE `value`.`property_id` = :property_id
    $sqlAndWhere;

SQL;
                $bind = $baseBind + [
                    'property_id' => $map['property_id'],
                ];
                $result = $this->connection->executeUpdate($sql, $bind);
                if ($result) {
                    $this->stats['removed'] += $result;
                    $this->logger->info(
                        'Template "{template}": {total} values removed for source "{source}", mapped to property "{term}".', // @translate
                        ['template' => $templateLabel, 'total' => $result, 'source' => $map['source'], 'term' => $map['destination']]
                    );
                }
                break;

            case 'keep private':
                $sql = <<<SQL
UPDATE `value`
JOIN `resource` ON `resource`.`id` = `value`.`resource_id`
SET `value`.`is_public` = 0
WHERE `value`.`property_id` = :property_id
    $sqlAndWhere;

SQL;
                $bind = $baseBind + [
                    'property_id' => $map['property_id'],
                ];
                $this->connection->executeUpdate($sql, $bind);
                break;

            // Manage exceptions for values.
            case 'transform':
                // The exception applies to all values included in a
                // temporary table.
                $sql = <<<SQL
DROP TABLE IF EXISTS `_temporary_value_id`;
CREATE TABLE `_temporary_value_id` (
    `id` int(11) NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `_temporary_value_id`
    (`id`)
SELECT
    `value`.`id`
FROM `value`
JOIN `resource`
    ON `resource`.`id` = `value`.`resource_id`
WHERE
    `value`.`property_id` = :property_id
    AND (`value`.`type` = 'literal' OR `value`.`type` = '' OR `value`.`type` IS NULL)
    $sqlAndWhere;

SQL;
                $bind = $baseBind + [
                    'property_id' => $map['property_id'],
                ];
                // TODO Check why the count of the result is not good, but the table is good.
                $this->connection->executeUpdate($sql, $bind);
                $result = $this->connection->query('SELECT count(`id`) FROM `_temporary_value_id`;')->fetchColumn();
                if ($result) {
                    $this->logger->info(
                        'Template "{template}": Updating {total} values from source "{source}" to property "{term}".', // @translate
                        ['template' => $templateLabel, 'total' => $result, 'source' => $map['source'], 'term' => $map['destination']]
                    );
                    switch ($value) {
                        case 'Auteur':
                        case 'Auteur secondaire':
                        case 'Personne (sujet)':
                            $this->transformLiteralToValueSuggest($map['property_id'], [
                                'mapping' => 'valuesuggest:idref:author',
                                'partial' => true,
                                'name' => 'auteurs',
                                'datatype' => 'valuesuggest:idref:person',
                                'prefix' => 'https://www.idref.fr/',
                            ]);
                            break;

                        case 'Droits':
                            $this->transformLiteralToVarious($map['property_id'], [
                                'mapping' => 'dcterms:rights',
                                'name' => 'droits',
                            ]);
                            break;

                        case 'Editeur':
                            switch ($header) {
                                case 'livres anciens':
                                    // Lieu d’édition : éditeur
                                    // dcterms:publisher => bio:place (geonames) / dcterms:publisher (literal)
                                    // Mais certains éditeurs ne sont pas des lieux : "ANR : Agence Nationale de la Recherche".
                                    $this->transformLiteralWithOperations([
                                        [
                                            'action' => 'cut',
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
                                    ]);
                                    // Fonctionne car la liste des valeurs a été mise à jour dans l'opération précédente.
                                    $this->transformLiteralToValueSuggest($this->getPropertyId('bio:place'), [
                                        'mapping' => 'geonames',
                                        'partial' => true,
                                        'name' => 'lieux',
                                        'datatype' => 'valuesuggest:geonames:geonames',
                                        'prefix' => 'http://www.geonames.org/',
                                    ]);
                                    break;
                                case 'audio-video':
                                    // dcterms:publisher => dcterms:publisher (idref collectivité)
                                    $this->transformLiteralToValueSuggest($map['property_id'],  [
                                        'mapping' => 'dcterms:publisher',
                                        'partial' => true,
                                        'name' => 'editeurs',
                                        'datatype' => 'valuesuggest:idref:corporation',
                                        'prefix' => 'https://www.idref.fr/',
                                    ]);
                                    break;
                                default:
                                    break;
                            }
                            break;

                        case 'Fait partie de':
                            break;

                        case 'Indice Dewey':
                            // TODO Mapping Dewey.
                            // $this->transformLiteralToValueSuggest($map['property_id'], 'valuesuggest:lc:dewey', [
                            //     'mapping' => 'valuesuggest:lc:dewey',
                            //     'name' => 'dewey',
                            //     'prefix' => '',
                            // ]);
                            break;

                        case 'Langue':
                            $this->transformLiteralToValueSuggest($map['property_id'], [
                                'mapping' => 'dcterms:language',
                                'datatype' => 'valuesuggest:lc:iso6392',
                                'prefix' => 'http://id.loc.gov/vocabulary/iso639-2/',
                            ]);
                            break;

                        case 'Pays|Ville (sujet)':
                        case 'Sujet géographique':
                            $this->transformLiteralToValueSuggest($map['property_id'], [
                                'mapping' => 'geonames',
                                'partial' => true,
                                'name' => 'lieux',
                                'datatype' => 'valuesuggest:geonames:geonames',
                                'prefix' => 'http://www.geonames.org/',
                            ]);
                            break;

                        case 'Mot-clé':
                        case 'Thématique audio-vidéo':
                        case 'Thématique images':
                        case 'Thématique ouvrages numérisés':
                        case 'Thématique recherche':
                            $this->transformLiteralToValueSuggest($map['property_id'], [
                                'mapping' => 'valuesuggest:idref:rameau',
                                'partial' => true,
                                'name' => 'thematiques',
                                'datatype' => 'valuesuggest:idref:rameau',
                                'prefix' => 'https://www.idref.fr/',
                            ]);
                            break;

                        case 'Titre':
                            break;

                        default:
                            $this->stats['not_managed'][] = $value;
                            break;
                    }
                }
                break;

            default:
                // Let empty values, probably none.
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
        $this->connection->executeUpdate($sql);
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
                'Cannot save table "table" to a temporary file.', // @translate
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
