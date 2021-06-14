<?php declare(strict_types=1);

namespace BulkImport\Processor;

use BulkImport\Form\Processor\ManiocProcessorConfigForm;
use BulkImport\Form\Processor\ManiocProcessorParamsForm;

class ManiocProcessor extends AbstractFullProcessor
{
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
            // 'media',
            'item_sets',
        ],
    ];

    protected $mapping = [
        'users' => [
            'source' => 'users',
            'key_id' => 'id',
        ],
        'items' => [
            'source' => 'fichiers',
            'key_id' => 'id_fichier',
        ],
        // 'media' => [
        //     'source' => 'media',
        //     'key_id' => 'o:id',
        // ],
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

    protected $importConfigFile = 'manioc/mapping.tsv';

    protected $tables = [
        'fichiers',
        'metadata',
    ];

    protected function preImport(): void
    {
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
        $jobId = $this->job->getJobId();
        foreach ($this->prepareReader('users') as $userSource) {
            $user = [];
            $userSource = array_map('trim', array_map('strval', $userSource));
            $cleanName = mb_strtolower(preg_replace('/[^\da-z]/i', '_', ($userSource['login'])));
            $email = $cleanName . '@manioc.net';
            $user['name'] = $cleanName;
            $user['email'] = $email;
            if (isset($emails[$email])) {
                $email = $userSource['id'] . '-' . $jobId . '-' . $email;
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

    protected function fillItems(): void
    {
        $this->fillResources($this->prepareReader('items'), 'items');
        $this->fillValues($this->prepareReader('values'));
    }

    protected function fillItemSets(): void
    {
        $this->fillResources($this->prepareReader('item_sets'), 'item_sets');
    }

    protected function fillItem(array $source): void
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

        // Media are updated separately in order to manage files.
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
            'value' => $source['nom_collection'],
        ];
        $values[] = [
            'term' => 'dcterms:identifier',
            'lang' => null,
            'value' => $source['code_collection'],
        ];
        $values[] = [
            'term' => 'dcterms:identifier',
            'lang' => null,
            'value' => 'greenstone_id: ' . $source['id_collection'],
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
        // The mapping is already checked during initial checks.
        $configMapping = $this->getNormalizedMapping();
        if (!$configMapping) {
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

        $resources = array_filter($this->map['items']);
        if (!count($resources)) {
            $this->logger->warn(
                'There is no resource for values.' // @translate
            );
            return;
        }

        // Update values in temporary tables to simplify final copy.

        // Copy the mapping of source ids and resource ids.
        // It's quicker to use a temp file and it avoids a large query.
        $filepath = $this->saveKeyPairToTsv('items', true);
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
        foreach ($configMapping as $map) {
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
        foreach (array_chunk(array_filter($this->map['items']), self::CHUNK_RECORD_IDS, true) as $chunk) {
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
