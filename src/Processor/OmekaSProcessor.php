<?php declare(strict_types=1);

namespace BulkImport\Processor;

use ArrayObject;
use BulkImport\Form\Processor\OmekaSProcessorConfigForm;
use BulkImport\Form\Processor\OmekaSProcessorParamsForm;
use BulkImport\Interfaces\Parametrizable;
use BulkImport\Traits\ConfigurableTrait;
use BulkImport\Traits\ParametrizableTrait;
use finfo;
use Laminas\Form\Form;
use Log\Stdlib\PsrMessage;
use Omeka\Api\Representation\VocabularyRepresentation;

/**
 * @todo The processor is only parametrizable currently.
 */
class OmekaSProcessor extends AbstractProcessor implements Parametrizable
{
    use ConfigurableTrait;
    use CustomVocabTrait;
    use InternalIntegrityTrait;
    use ParametrizableTrait;
    use ResourceTemplateTrait;
    use UserTrait;
    use VocabularyTrait;

    /**
     * The max number of entities before a flush/clear.
     *
     * @var int
     */
    const CHUNK_ENTITIES = 100;

    /**
     * The max number of the rows to build the temporary table of ids.
     *
     * @var int
     */
    const CHUNK_RECORD_IDS = 10000;

    /**
     * @var string
     */
    protected $resourceLabel = 'Omeka S'; // @translate

    /**
     * @var string
     */
    protected $configFormClass = OmekaSProcessorConfigForm::class;

    /**
     * @var string
     */
    protected $paramsFormClass = OmekaSProcessorParamsForm::class;

    /**
     * @var int
     */
    protected $indexResource = 0;

    /**
     * @var int
     */
    protected $processing = 0;

    /**
     * @var int
     */
    protected $totalIndexResources = 0;

    /**
     * @var int
     */
    protected $totalSkipped = 0;

    /**
     * @var int
     */
    protected $totalProcessed = 0;

    /**
     * @var int
     */
    protected $totalErrors = 0;

    /**
     * @var bool
     */
    protected $hasError = false;

    /**
     * @var array
     */
    protected $map = [];

    /**
     * @var array
     */
    protected $totals = [];

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

    /**
     * @var \Doctrine\DBAL\Connection $connection
     */
    protected $connection;

    /**
     * @var \Omeka\Api\Adapter\AdapterInterface $adapter
     */
    protected $adapterManager;

    /**
     * @var \Omeka\DataType\Manager
     */
    protected $datatypeManager;

    /**
     * @var \Omeka\File\TempFileFactory $tempFileFactory
     */
    protected $tempFileFactory;

    /**
     * List of allowed datatypes (except dynamic ones, like valuesuggest), for
     * quick check.
     *
     * @var array
     */
    protected $allowedDataTypes = [];

    /**
     * @var \Omeka\Entity\User|null
     */
    protected $owner;

    /**
     * @var int|null
     */
    protected $ownerId;

    /**
     * @var array|null
     */
    protected $ownerOId;

    /**
     * @var string
     */
    protected $defaultDate;

    /**
     * @var string
     */
    protected $basePath;

    /**
     * @var string
     */
    protected $tempPath;

    /**
     * @var bool
     */
    protected $disableFileValidation = false;

    /**
     * @var array
     */
    protected $allowedMediaTypes = [];

    /**
     * @var array
     */
    protected $allowedExtensions = [];

    /**
     * @var int
     */
    protected $srid;

    /**
     * @var array
     */
    protected $modules = [];

    /**
     * The entity being inserted.
     *
     * @var \Omeka\Entity\EntityInterface
     */
    protected $entity;

    public function getLabel()
    {
        return $this->resourceLabel;
    }

    public function getConfigFormClass()
    {
        return $this->configFormClass;
    }

    public function getParamsFormClass()
    {
        return $this->paramsFormClass;
    }

    public function handleConfigForm(Form $form): void
    {
        $values = $form->getData();
        $config = new ArrayObject;
        $this->handleFormGeneric($config, $values);
        $defaults = [
            'endpoint' => null,
            'key_identity' => null,
            'key_credential' => null,
        ];
        $config = array_intersect_key($config->getArrayCopy(), $defaults);
        $this->setConfig($config);
    }

    public function handleParamsForm(Form $form): void
    {
        $values = $form->getData();
        $params = new ArrayObject;
        $this->handleFormGeneric($params, $values);
        $defaults = [
            'o:owner' => null,
            'types' => [
                'users',
                'items',
                'media',
                'item_sets',
                'assets',
                'vocabularies',
                'resource_templates',
                'custom_vocabs',
            ],
        ];
        $params = array_intersect_key($params->getArrayCopy(), $defaults);
        // TODO Finalize skip import of vocabularies, resource_templates and custom vocabs.
        $params['types'][] = 'vocabularies';
        $params['types'][] = 'resource_templates';
        $params['types'][] = 'custom_vocabs';
        $this->setParams($params);
    }

    protected function handleFormGeneric(ArrayObject $args, array $values): void
    {
        $defaults = [
            'endpoint' => null,
            'key_identity' => null,
            'key_credential' => null,
            'o:owner' => null,
            'types' => [],
        ];
        $result = array_intersect_key($values, $defaults) + $args->getArrayCopy() + $defaults;
        // TODO Manage check of duplicate identifiers during dry-run.
        // $result['allow_duplicate_identifiers'] = (bool) $result['allow_duplicate_identifiers'];
        $args->exchangeArray($result);
    }

    public function process(): void
    {
        // TODO Add a dry-run.
        // TODO Add an option to use api or not.
        // TODO Add an option to stop/continue on error.

        $services = $this->getServiceLocator();
        $this->entityManager = $services->get('Omeka\EntityManager');
        $this->connection = $services->get('Omeka\Connection');

        $this->adapterManager = $services->get('Omeka\ApiAdapterManager');
        $this->datatypeManager = $services->get('Omeka\DataTypeManager');

        $this->tempFileFactory = $services->get('Omeka\File\TempFileFactory');
        $this->allowedDataTypes = $services->get('Omeka\DataTypeManager')->getRegisteredNames();

        // The owner should be reloaded each time the entity manager is flushed.
        $ownerIdParam = $this->getParam('o:owner', 'current') ?: 'current';
        if ($ownerIdParam === 'current') {
            $identity = $services->get('ControllerPluginManager')->get('identity');
            $this->owner = $identity();
        } elseif ($ownerIdParam) {
            $this->owner = $this->entityManager->find(\Omeka\Entity\User::class, $ownerIdParam);
        }
        $this->ownerId = $this->owner ? $this->owner->getId() : null;
        $this->ownerOId = $this->owner ? ['o:id' => $this->owner->getId()] : null;

        $config = $services->get('Config');
        $this->basePath = $config['file_store']['local']['base_path'] ?: OMEKA_PATH . '/files';
        $this->tempPath = $config['temp_dir'] ?: sys_get_temp_dir();
        $this->defaultDate = (new \DateTime())->format('Y-m-d H:i:s');

        $settings = $services->get('Omeka\Settings');
        $this->disableFileValidation = (bool) $settings->get('disable_file_validation');
        $this->allowedMediaTypes = $settings->get('media_type_whitelist', []);
        $this->allowedExtensions = $settings->get('extension_whitelist', []);

        $this->srid = $services->get('Omeka\Settings')->get('datatypegeometry_locate_srid', 4326);

        $this->checkAvailableModules();

        if (!$this->reader->isValid()) {
            $this->hasError = true;
            $this->logger->err(
                'The endpoint is unavailable. Check connection.' // @translate
            );
            return;
        }

        $toImport = $this->getParam('types') ?: [];

        // Pre-process: check the Omeka database for assets and resources.

        // Check database integrity for assets.
        $this->logger->info(
            'Check integrity of assets.' // @translate
        );
        $this->checkAssets();
        if ($this->hasError) {
            return;
        }

        // Check database integrity for resources.
        $this->logger->info(
            'Check integrity of resources.' // @translate
        );
        $this->checkResources();
        if ($this->hasError) {
            return;
        }

        // FIXME  Check for missing modules for datatypes (value suggest, custom vocab, numeric datatype, rdf datatype, geometry datatype).

        // First step: check and create all vocabularies

        // Users are prepared first to include the owner anywhere.
        if (in_array('users', $toImport)) {
            $this->logger->info(
                'Import of users.' // @translate
            );
            $this->prepareUsers();
            if ($this->hasError) {
                return;
            }
        }

        if (in_array('vocabularies', $toImport)) {
            $this->logger->info(
                'Check vocabularies.' // @translate
            );
            $this->checkVocabularies();
            if ($this->hasError) {
                return;
            }

            $this->logger->info(
                'Preparation of vocabularies.' // @translate
            );
            $this->prepareVocabularies();
            if ($this->hasError) {
                return;
            }

            $this->logger->info(
                'Preparation of properties.' // @translate
            );
            $this->prepareProperties();
            if ($this->hasError) {
                return;
            }

            $this->logger->info(
                'Preparation of resource classes.' // @translate
            );
            $this->prepareResourceClasses();
            if ($this->hasError) {
                return;
            }
        }

        // TODO Refresh the bulk lists for properties, resource classes and templates.

        if (in_array('custom_vocabs', $toImport)) {
            $this->logger->info(
                'Check custom vocabs.' // @translate
            );
            $this->prepareCustomVocabsInitialize();
            if ($this->hasError) {
                return;
            }
        }

        if (in_array('resource_templates', $toImport)) {
            $this->logger->info(
                'Preparation of resource templates.' // @translate
            );
            $this->prepareResourceTemplates();
            if ($this->hasError) {
                return;
            }
        }

        // Second step: import the data.

        // The process uses two loops: creation of all resources empty, then
        // filling them. This process is required to manage relations between
        // resources, while any other user can create new resources at the same
        // time. This process is required for assets too in order to get the
        // mapping of ids for thumbnails.

        // First loop: create one resource by resource.
        $this->logger->info(
            'Initialization of all resources and assets.' // @translate
        );
        $this->initializeEntities();
        if ($this->hasError) {
            return;
        }

        if (in_array('assets', $toImport)) {
            // Second loop.
            $this->logger->info(
                'Finalization of assets.' // @translate
            );
            $this->fillAssets();
        }

        $this->logger->info(
            'Preparation of metadata of all resources.' // @translate
        );
        $this->fillResources();

        // Additional metadata from modules (available only by reference).
        if (!empty($this->modules['Mapping'])) {
            $this->logger->info(
                'Preparation of metadata of module Mapping.' // @translate
            );
            $this->fillMapping();
        }

        /*
        $this->logger->notice(
            'End of process: {total_resources} resources to process, {total_skipped} skipped or blank, {total_processed} processed, {total_errors} errors inside data. Note: errors can occur separately for each imported file.', // @translate
            [
                'total_resources' => $this->totalIndexResources,
                'total_skipped' => $this->totalSkipped,
                'total_processed' => $this->totalProcessed,
                'total_errors' => $this->totalErrors,
            ]
        );
        */
        $this->logger->notice(
            'End of process. Note: errors can occur separately for each imported file.' // @translate
        );
    }

    protected function checkVocabularies(): void
    {
        foreach ($this->reader->setObjectType('vocabularies') as $vocabulary) {
            $result = $this->checkVocabulary($vocabulary);
            if ($result['status'] !== 'success') {
                $this->hasError = true;
            }
        }
    }

    protected function checkVocabularyProperties(array $vocabulary, VocabularyRepresentation $vocabularyRepresentation)
    {
        // TODO Add a filter to the reader.
        foreach ($this->reader->setObjectType('properties') as $property) {
            if ($property['o:vocabulary']['o:id'] === $vocabulary['o:id']) {
                $vocabularyProperties[] = $property['o:local_name'];
            }
        }
        return $vocabularyProperties;
    }

    protected function prepareVocabularies(): void
    {
        $this->prepareVocabulariesProcess($this->reader->setObjectType('vocabularies'));
    }

    protected function prepareProperties(): void
    {
        $this->prepareVocabularyMembers(
            $this->reader->setObjectType('properties'),
            'properties'
        );
    }

    protected function prepareResourceClasses(): void
    {
        $this->prepareVocabularyMembers(
            $this->reader->setObjectType('resource_classes'),
            'resource_classes'
        );
    }

    protected function prepareUsers(): void
    {
        $this->prepareUsersProcess($this->reader->setObjectType('users'));
    }

    protected function prepareCustomVocabsInitialize(): void
    {
        $this->map['custom_vocabs'] = [];
        if (empty($this->modules['CustomVocab'])) {
            return;
        }
        $this->prepareCustomVocabsProcess($this->reader->setObjectType('custom_vocabs'));
    }

    protected function prepareResourceTemplates(): void
    {
        $this->prepareResourceTemplatesProcess($this->reader->setObjectType('resource_templates'));
    }

    protected function initializeEntities(): void
    {
        $resourceTypes = [
            'assets' => \Omeka\Entity\Asset::class,
            'items' => \Omeka\Entity\Item::class,
            'media' => \Omeka\Entity\Media::class,
            'item_sets' => \Omeka\Entity\ItemSet::class,
        ];
        $resourceTables = [
            'assets' => 'asset',
            'items' => 'item',
            'media' => 'media',
            'item_sets' => 'item_set',
        ];

        $toImport = $this->getParam('types') ?: [];
        $resourceTypes = array_intersect_key($resourceTypes, array_flip($toImport));
        if (isset($resourceTypes['media']) && !isset($resourceTypes['items'])) {
            $this->hasError = true;
            $this->logger->err(
                'Resource "media" cannot be imported without items.' // @translate
            );
            return;
        }

        $ownerIdOrNull = $this->owner ? $this->ownerId : 'NULL';

        // Check the size of the import.
        foreach (array_keys($resourceTypes) as $resourceType) {
            $this->totals[$resourceType] = $this->reader->setObjectType($resourceType)->count();
            if ($this->totals[$resourceType] > 10000000) {
                $this->hasError = true;
                $this->logger->err(
                    'Resource "{type}" has too much records ({total}).', // @translate
                    ['type' => $resourceType, 'total' => $this->totals[$resourceType]]
                );
                return;
            }
            $this->logger->notice(
                'Preparation of {total} resource "{type}".', // @translate
                ['total' => $this->totals[$resourceType], 'type' => $resourceType]
            );
        }

        // Use direct query to speed process and to reserve a whole list of ids.
        // The indexation, api events, etc. will be done when the resources will
        // be really filled via update.

        // Prepare the list of all ids.
        $mediaItems = [];
        $assets = [];
        foreach (array_keys($resourceTypes) as $resourceType) {
            $this->map[$resourceType] = [];
            // Only the ids are needed here, except for media, that require the
            // item id (mapped below).
            if ($resourceType === 'media') {
                foreach ($this->reader->setObjectType($resourceType) as $resource) {
                    $this->map[$resourceType][(int) $resource['o:id']] = null;
                    $mediaItems[(int) $resource['o:id']] = (int) $resource['o:item']['o:id'];
                }
            } elseif ($resourceType === 'assets') {
                // Get the storage ids.
                foreach ($this->reader->setObjectType($resourceType) as $resource) {
                    $this->map[$resourceType][(int) $resource['o:id']] = null;
                    // Remove extension manually because module ebook uses a
                    // specific storage id.
                    $extension = pathinfo($resource['o:filename'], PATHINFO_EXTENSION);
                    $assets[(int) $resource['o:id']] = mb_strlen($extension)
                        ? mb_substr($resource['o:filename'], 0, -mb_strlen($extension) - 1)
                        : $resource['o:filename'];
                }
            } else {
                foreach ($this->reader->setObjectType($resourceType) as $resource) {
                    $this->map[$resourceType][(int) $resource['o:id']] = null;
                }
            }
        }

        foreach ($resourceTypes as $resourceType => $class) {
            if (!count($this->map[$resourceType])) {
                $this->logger->notice(
                    'No resource "{type}" available on the source.', // @translate
                    ['type' => $resourceType]
                );
                continue;
            }

            // Currently, it's not possible to import media without the
            // items, because the mapping of the ids is not saved.
            // TODO Allow to use a media identifier to identify the item.
            if ($resourceType === 'media' && !count($this->map['items'])) {
                $this->logger->warn(
                    'Media cannot be imported without items currently.' // @translate
                );
                continue;
            }

            $resourceClass = $this->connection->quote($class);

            if ($resourceType === 'assets') {
                if ($assets) {
                    $storageIds = implode(',', array_map([$this->connection, 'quote'], $assets));
                    // Get existing duplicates for reimport.
                    $sql = <<<SQL
SELECT `asset`.`id` AS `d`
FROM `asset` AS `asset`
WHERE `asset`.`storage_id` IN ($storageIds);
SQL;
                    $existingAssets = array_column($this->connection->query($sql)->fetchAll(\PDO::FETCH_ASSOC), 'd');

                    $sql = '';
                    // Save the ids as storage, it should be unique anyway, except
                    // in case of reimport.
                    $toCreate = array_diff_key($this->map[$resourceType], array_flip($existingAssets));
                    foreach (array_chunk(array_keys($toCreate), self::CHUNK_RECORD_IDS) as $chunk) {
                        $sql .= 'INSERT INTO `asset` (`name`,`media_type`,`storage_id`) VALUES("","",' . implode('),("","",', $chunk) . ');' . "\n";
                    }
                    if ($sql) {
                        $this->connection->query($sql);
                    }

                    // Get the mapping of source and destination ids.
                    $sql = <<<SQL
SELECT `asset`.`storage_id` AS `s`, `asset`.`id` AS `d`
FROM `asset` AS `asset`
WHERE `asset`.`name` = ""
    AND `asset`.`media_type` = ""
    AND (`asset`.`extension` IS NULL OR `asset`.`extension` = "")
    AND `asset`.`owner_id` IS NULL;
SQL;
                    // Fetch by key pair is not supported by doctrine 2.0.
                    $this->map[$resourceType] = array_column($this->connection->query($sql)->fetchAll(\PDO::FETCH_ASSOC), 'd', 's');
                }
                continue;
            }

            // For compatibility with old database, a temporary table is used in
            // order to create a generator of enough consecutive rows.
            $sql = <<<SQL
DROP TABLE IF EXISTS `temporary_source_resource`;
CREATE TEMPORARY TABLE `temporary_source_resource` (`id` INT unsigned NOT NULL, PRIMARY KEY (`id`));

SQL;
            foreach (array_chunk(array_keys($this->map[$resourceType]), self::CHUNK_RECORD_IDS) as $chunk) {
                $sql .= 'INSERT INTO `temporary_source_resource` (`id`) VALUES(' . implode('),(', $chunk) . ");\n";
            }
            $sql .= <<<SQL
INSERT INTO `resource`
    (`owner_id`, `resource_class_id`, `resource_template_id`, `is_public`, `created`, `modified`, `resource_type`, `thumbnail_id`, `title`)
SELECT
    $ownerIdOrNull, NULL, NULL, 0, "$this->defaultDate", NULL, $resourceClass, NULL, id
FROM `temporary_source_resource`;

DROP TABLE IF EXISTS `temporary_source_resource`;
SQL;
            $this->connection->query($sql);

            // Get the mapping of source and destination ids.
            $sql = <<<SQL
SELECT `resource`.`title` AS `s`, `resource`.`id` AS `d`
FROM `resource` AS `resource`
LEFT JOIN {$resourceTables[$resourceType]} AS `spec` ON `spec`.`id` = `resource`.`id`
WHERE `spec`.`id` IS NULL
    AND `resource`.`resource_type` = $resourceClass;
SQL;
            // Fetch by key pair is not supported by doctrine 2.0.
            $this->map[$resourceType] = array_column($this->connection->query($sql)->fetchAll(\PDO::FETCH_ASSOC), 'd', 's');

            // Create the resource in the specific resource table.
            switch ($resourceType) {
                case 'items':
                    $sql = <<<SQL
INSERT INTO `item`
SELECT `resource`.`id`
SQL;
                    break;

                case 'media':
                    // Attach all media to first item id for now, updated below.
                    $itemId = (int) reset($this->map['items']);
                    $sql = <<<SQL
INSERT INTO `media`
    (`id`, `item_id`, `ingester`, `renderer`, `data`, `source`, `media_type`, `storage_id`, `extension`, `sha256`, `has_original`, `has_thumbnails`, `position`, `lang`, `size`)
SELECT
    `resource`.`id`, $itemId, '', '', NULL, NULL, NULL, NULL, NULL, NULL, 0, 0, 0, NULL, NULL
SQL;
                    break;

                case 'item_sets':
                    // Finalize custom vocabs early: item sets map is available.
                    $this->prepareCustomVocabsFinalize();
                    $sql = <<<SQL
INSERT INTO `item_set`
SELECT `resource`.`id`, 0
SQL;
                    break;
                default:
                    return;
            }
            $sql .= PHP_EOL . <<<SQL
FROM `resource` AS `resource`
LEFT JOIN {$resourceTables[$resourceType]} AS `spec` ON `spec`.`id` = `resource`.`id`
WHERE `spec`.`id` IS NULL
    AND `resource`.`resource_type` = $resourceClass;
SQL;
            $this->connection->query($sql);

            // Manage the exception for media, that require the good item id.
            if ($resourceType === 'media') {
                foreach (array_chunk($mediaItems, self::CHUNK_RECORD_IDS, true) as $chunk) {
                    $sql = str_repeat("UPDATE `media` SET `item_id`=? WHERE `id`=?;\n", count($chunk));
                    $bind = [];
                    foreach ($chunk as $sourceMediaId => $sourceItemId) {
                        $bind[] = $this->map['items'][$sourceItemId];
                        $bind[] = $this->map['media'][$sourceMediaId];
                    }
                    $this->connection->executeUpdate($sql, $bind);
                }
            }

            $this->logger->notice(
                '{total} resource "{type}" have been created.', // @translate
                ['total' => count($this->map[$resourceType]), 'type' => $resourceType]
            );
        }
    }

    protected function fillAssets(): void
    {
        $this->refreshOwner();

        /** @var \Omeka\Api\Adapter\AssetAdapter $adapter */
        $adapter = $this->adapterManager->get('assets');
        $index = 0;
        $created = 0;
        $skipped = 0;
        foreach ($this->reader->setObjectType('assets') as $resource) {
            ++$index;
            // Some new resources created since first loop.
            if (!isset($this->map['assets'][$resource['o:id']])) {
                ++$skipped;
                $this->logger->notice(
                    'Skipped resource "{type}" #{source_id} existing or added in source.', // @translate
                    ['type' => 'asset', 'source_id' => $resource['o:id']]
                );
                continue;
            }

            if (($pos = mb_strrpos($resource['o:filename'], '.')) === false) {
                ++$skipped;
                $this->logger->warn(
                    'Asset {id} has no filename or no extension.', // @translate
                    ['id' => $resource['o:id']]
                );
                continue;
            }

            // Api can't be used because the asset should be downloaded locally.
            // $resourceId = $resource['o:id'];
            // unset($resource['@id'], $resource['o:id']);
            // $response = $this->api()->create('assets', $resource);

            // TODO Keep the original storage id of assets (so check existing one as a whole).
            // $storageId = substr($resource['o:filename'], 0, $pos);
            // @see \Omeka\File\TempFile::getStorageId()
            $storageId = bin2hex(\Laminas\Math\Rand::getBytes(20));
            $extension = substr($resource['o:filename'], $pos + 1);

            $result = $this->fetchUrl('asset', $resource['o:name'], $resource['o:filename'], $storageId, $extension, $resource['o:asset_url']);
            if ($result['status'] !== 'success') {
                ++$skipped;
                $this->logger->err($result['message']);
                continue;
            }

            $this->entity = $this->entityManager->find(\Omeka\Entity\Asset::class, $this->map['assets'][$resource['o:id']]);

            // Omeka entities are not fluid.
            $this->entity->setOwner($this->userOrDefaultOwner($resource['o:owner']));
            $this->entity->setName($resource['o:name']);
            $this->entity->setMediaType($result['data']['media_type']);
            $this->entity->setStorageId($storageId);
            $this->entity->setExtension($extension);

            $errorStore = new \Omeka\Stdlib\ErrorStore;
            $adapter->validateEntity($this->entity, $errorStore);
            if ($errorStore->hasErrors()) {
                ++$skipped;
                $this->logErrors($this->entity, $errorStore);
                continue;
            }

            // TODO Trigger an event for modules (or manage them here).

            $this->entityManager->persist($this->entity);
            ++$created;

            if ($created % self::CHUNK_ENTITIES === 0) {
                $this->entityManager->flush();
                $this->entityManager->clear();
                $this->refreshOwner();
                $this->logger->notice(
                    '{count}/{total} resource "{type}" imported, {skipped} skipped.', // @translate
                    ['count' => $created, 'total' => count($this->map['assets']), 'type' => 'asset', 'skipped' => $skipped]
                );
            }
        }

        // Remaining entities.
        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->refreshOwner();

        $this->logger->notice(
            '{count}/{total} resource "{type}" imported, {skipped} skipped.', // @translate
            ['count' => $created, 'total' => $index, 'type' => 'asset', 'skipped' => $skipped]
        );
    }

    protected function fillResources(): void
    {
        $resourceTypes = [
            'item_sets' => \Omeka\Entity\ItemSet::class,
            'items' => \Omeka\Entity\Item::class,
            'media' => \Omeka\Entity\Media::class,
        ];
        $methods = [
            'item_sets' => 'fillItemSet',
            'items' => 'fillItem',
            'media' => 'fillMedia',
        ];

        $toImport = $this->getParam('types') ?: [];
        $resourceTypes = array_intersect_key($resourceTypes, array_flip($toImport));
        if (isset($resourceTypes['media']) && !isset($resourceTypes['items'])) {
            unset($resourceTypes['media']);
        }

        $this->refreshOwner();

        foreach ($resourceTypes as $resourceType => $class) {
            /** @var \Omeka\Api\Adapter\AbstractResourceEntityAdapter $adapter */
            $adapter = $this->adapterManager->get($resourceType);
            $index = 0;
            $created = 0;
            $skipped = 0;
            $method = $methods[$resourceType];
            foreach ($this->reader->setObjectType($resourceType) as $resource) {
                ++$index;

                // Some new resources created between first loop.
                if (!isset($this->map[$resourceType][$resource['o:id']])) {
                    ++$skipped;
                    $this->logger->notice(
                        'Skipped resource "{type}" #{source_id} added in source.', // @translate
                        ['type' => $resourceType, 'source_id' => $resource['o:id']]
                    );
                    continue;
                }

                $this->entity = $this->entityManager->find($class, $this->map[$resourceType][$resource['o:id']]);
                $this->$method($resource);

                $errorStore = new \Omeka\Stdlib\ErrorStore;
                $adapter->validateEntity($this->entity, $errorStore);
                if ($errorStore->hasErrors()) {
                    ++$skipped;
                    $this->logErrors($this->entity, $errorStore);
                    continue;
                }

                // TODO Trigger an event for modules (or manage them here).
                // TODO Manage special datatypes (numeric and geometry).

                $this->entityManager->persist($this->entity);
                ++$created;

                if ($created % self::CHUNK_ENTITIES === 0) {
                    $this->entityManager->flush();
                    $this->entityManager->clear();
                    $this->refreshOwner();
                    $this->logger->notice(
                        '{count}/{total} resource "{type}" imported, {skipped} skipped.', // @translate
                        ['count' => $created, 'total' => count($this->map[$resourceType]), 'type' => $resourceType, 'skipped' => $skipped]
                    );
                }
            }

            // Remaining entities.
            $this->entityManager->flush();
            $this->entityManager->clear();
            $this->refreshOwner();

            $this->logger->notice(
                '{count}/{total} resource "{type}" imported, {skipped} skipped.', // @translate
                ['count' => $created, 'total' => count($this->map[$resourceType]), 'type' => $resourceType, 'skipped' => $skipped]
            );

            // Check total in case of an issue in the network or with Omeka < 2.1.
            // In particular, an issue occurred when a linked resource is private.
            if ($this->totals[$resourceType] !== count($this->map[$resourceType])) {
                $this->hasError = true;
                $this->logger->err(
                    'The total {total} of resources {type} is not the same than the count {count}.', // @translate
                    ['total' => $this->totals[$resourceType], 'count' => count($this->map[$resourceType]), 'type' => $resourceType]
                );
            }
        }
    }

    protected function fillResource(array $resource): void
    {
        // Omeka entities are not fluid.
        $this->entity->setOwner($this->userOrDefaultOwner($resource['o:owner']));

        if (!empty($resource['@type'][1])
            && !empty($this->map['resource_classes'][$resource['@type'][1]])
        ) {
            $resourceClass = $this->entityManager->find(\Omeka\Entity\ResourceClass::class, $this->map['resource_classes'][$resource['@type'][1]]['id']);
            $this->entity->setResourceClass($resourceClass);
        }

        if (!empty($resource['o:resource_template']['o:id'])
            && !empty($this->map['resource_templates'][$resource['o:resource_template']['o:id']])
        ) {
            $resourceTemplate = $this->entityManager->find(\Omeka\Entity\ResourceTemplate::class, $this->map['resource_templates'][$resource['o:resource_template']['o:id']]);
            $this->entity->setResourceTemplate($resourceTemplate);
        }

        if (!empty($resource['o:thumbnail']['o:id'])) {
            if (isset($this->map['assets'][$resource['o:thumbnail']['o:id']])) {
                $asset = $this->entityManager->find(\Omeka\Entity\Asset::class, $this->map['assets'][$resource['o:thumbnail']['o:id']]);
                $this->entity->setThumbnail($asset);
            } else {
                $this->logger->warn(
                    'Specific thumbnail for resource #{id} (source #{source_id}) is not available.', // @translate
                    ['id' => $this->entity->getId(), 'source_id' => $resource['o:id']]
                );
            }
        }

        if (array_key_exists('o:title', $resource) && strlen((string) $resource['o:title'])) {
            $this->entity->setTitle($resource['o:title']);
        }

        $this->entity->setIsPublic(!empty($resource['o:is_public']));

        $sqlDate = function ($value) {
            return substr(str_replace('T', ' ', $value), 0, 19) ?: $this->defaultDate;
        };

        $created = new \DateTime($sqlDate($resource['o:created']['@value']));
        $this->entity->setCreated($created);

        if ($resource['o:modified']['@value']) {
            $modified = new \DateTime($sqlDate($resource['o:modified']['@value']));
            $this->entity->setCreated($modified);
        }

        $this->fillValues($resource);
    }

    protected function fillValues(array $resource): void
    {
        $resourceTypes = [
            'assets' => \Omeka\Entity\Asset::class,
            'items' => \Omeka\Entity\Item::class,
            'media' => \Omeka\Entity\Media::class,
            'item_sets' => \Omeka\Entity\ItemSet::class,
        ];
        $resourceType = array_search(get_class($this->entity), $resourceTypes);

        // Terms that don't exist can't be imported, or data that are not terms.
        $resourceValues = array_intersect_key($resource, $this->map['properties']);
        $entityValues = $this->entity->getValues();

        foreach ($resourceValues as $term => $values) {
            $property = $this->entityManager->find(\Omeka\Entity\Property::class, $this->map['properties'][$term]['id']);
            foreach ($values as $value) {
                $datatype = $value['type'];
                // Convert unknown custom vocab into a literal.
                if (strtok($datatype, ':') === 'customvocab') {
                    if (!empty($this->map['custom_vocabs'][$datatype]['datatype'])) {
                        $datatype = $value['type'] = $this->map['custom_vocabs'][$datatype]['datatype'];
                    } else {
                        $this->logger->warn(
                            'Value with datatype "{type}" for resource #{id} is changed to "literal".', // @translate
                            ['type' => $datatype, 'id' => $this->entity->getId()]
                        );
                        $datatype = $value['type'] = 'literal';
                    }
                }

                if (!in_array($datatype, $this->allowedDataTypes)) {
                    $mapDataTypes = [
                        'rdf:HTML' => 'html',
                        'rdf:XMLLiteral' => 'xml',
                        'xsd:boolean' => 'boolean',
                    ];
                    $toInstall = false;

                    // Try to manage some types when matching module is not installed.
                    switch ($datatype) {
                        // When here, the module NumericDataTypes is not installed.
                        case strtok($datatype, ':') === 'numeric':
                            $datatype = $value['type'] = 'literal';
                            $toInstall = 'Numeric Data Types';
                            break;
                        case isset($mapDataTypes[$datatype]):
                            if (in_array($mapDataTypes[$datatype], $this->allowedDataTypes)) {
                                $datatype = $value['type'] = $mapDataTypes;
                                break;
                            }
                            $datatype = $value['type'] = 'literal';
                            $toInstall = 'Data Type Rdf';
                            break;
                        // Module RdfDataType.
                        case 'xsd:integer':
                            if (!empty($this->modules['NumericDataTypes'])) {
                                $datatype = $value['type'] = 'numeric:integer';
                                break;
                            }
                            $datatype = $value['type'] = 'literal';
                            $toInstall = 'Data Type Rdf / Numeric Data Types';
                            break;
                        case 'xsd:date':
                        case 'xsd:dateTime':
                        case 'xsd:gYear':
                        case 'xsd:gYearMonth':
                            if (!empty($this->modules['NumericDataTypes'])) {
                                try {
                                    $value['@value'] = \NumericDataTypes\DataType\Timestamp::getDateTimeFromValue($value['@value']);
                                    $datatype = $value['type'] = 'numeric:timestamp';
                                } catch (\Exception $e) {
                                    $datatype = $value['type'] = 'literal';
                                    $this->logger->warn(
                                        'Value of resource {type} #{id} with data type {datatype} is not managed and skipped.', // @translate
                                        ['type' => $resourceType, 'id' => $resource['o:id'], 'datatype' => $value['type']]
                                    );
                                }
                                break;
                            }
                            $datatype = $value['type'] = 'literal';
                            $toInstall = 'Data Type Rdf / Numeric Data Types';
                            break;
                        case 'xsd:decimal':
                        case 'xsd:gDay':
                        case 'xsd:gMonth':
                        case 'xsd:gMonthDay':
                        case 'xsd:time':
                        // Module DataTypeGeometry.
                        case 'geometry:geography':
                        case 'geometry:geometry':
                            $datatype = $value['type'] = 'literal';
                            $toInstall = 'Data Type Geometry';
                            break;
                        // Module IdRef.
                        case 'idref':
                            if (!empty($this->modules['ValueSuggest'])) {
                                $datatype = $value['type'] = 'valuesuggest:idref:person';
                                break;
                            }
                            $datatype = $value['type'] = 'literal';
                            $toInstall = 'Value Suggest';
                            break;
                        default:
                            $datatype = $value['type'] = 'literal';
                            $toInstall = $datatype;
                            break;
                    }

                    if ($toInstall) {
                        $this->logger->warn(
                            'Value of resource {type} #{id} with data type {datatype} was changed to literal.', // @translate
                            ['type' => $resourceType, 'id' => $resource['o:id'], 'datatype' => $value['type']]
                        );
                        $this->logger->info(
                            'It’s recommended to install module {module}.', // @translate
                            ['module' => $toInstall]
                        );
                    }
                }

                // Don't keep undetermined value type, in all cases.
                if ($datatype === 'literal') {
                    $value['@id'] = null;
                    $value['value_resource_id'] = null;
                }

                $valueValue = $value['@value'];
                $valueUri = null;
                $valueResource = null;
                if (!empty($value['value_resource_id'])) {
                    if (!empty($value['value_resource_name'])
                        && $this->map[$value['value_resource_name']][$value['value_resource_id']]
                    ) {
                        $valueResource = $this->entityManager->find($resourceTypes[$value['value_resource_name']], $this->map[$value['value_resource_name']][$value['value_resource_id']]);
                    }
                    if (!$valueResource) {
                        if (isset($this->map['items'][$value['value_resource_id']])) {
                            $valueResource = $this->entityManager->find(\Omeka\Entity\Item::class, $this->map['items'][$value['value_resource_id']]);
                        } elseif (isset($this->map['media'][$value['value_resource_id']])) {
                            $valueResource = $this->entityManager->find(\Omeka\Entity\Media::class, $this->map['media'][$value['value_resource_id']]);
                        } elseif (isset($this->map['item_sets'][$value['value_resource_id']])) {
                            $valueResource = $this->entityManager->find(\Omeka\Entity\ItemSet::class, $this->map['item_sets'][$value['value_resource_id']]);
                        }
                    }
                    if (!$valueResource) {
                        $this->logger->warn(
                            'Value of resource {type} #{id} with linked resource for term {term} is not found.', // @translate
                            ['type' => $resourceType, 'id' => $resource['o:id'], 'term' => $term]
                        );
                        continue;
                    }
                    $valueValue = null;
                    $value['@id'] = null;
                    $value['lang'] = null;
                }

                if (!empty($value['@id'])) {
                    $valueUri = $value['@id'];
                    $valueValue = isset($value['o:label']) && strlen((string) $value['o:label']) ? $value['o:label'] : null;
                }

                $entityValue = new \Omeka\Entity\Value;
                $entityValue->setResource($this->entity);
                $entityValue->setProperty($property);
                $entityValue->setType($datatype);
                $entityValue->setValue($valueValue);
                $entityValue->setUri($valueUri);
                $entityValue->setValueResource($valueResource);
                $entityValue->setLang(empty($value['lang']) ? null : $value['lang']);
                $entityValue->setIsPublic(!empty($value['is_public']));

                $entityValues->add($entityValue);

                // Manage specific datatypes (without validation: it's an Omeka source).
                switch ($datatype) {
                    case 'numeric:timestamp':
                    case 'numeric:integer':
                    case 'numeric:duration':
                    case 'numeric:interval':
                        $datatypeAdapter = $this->datatypeManager->get($datatype);
                        $class = $datatypeAdapter->getEntityClass();
                        $dataValue = new $class;
                        $dataValue->setResource($this->entity);
                        $dataValue->setProperty($property);
                        $datatypeAdapter->setEntityValues($dataValue, $entityValue);
                        $this->entityManager->persist($dataValue);
                        break;
                    case 'geometry:geography':
                    case 'geometry:geometry':
                        $datatypeAdapter = $this->datatypeManager->get($datatype);
                        $class = $datatypeAdapter->getEntityClass();
                        $dataValue = new $class;
                        $dataValue->setResource($this->entity);
                        $dataValue->setProperty($property);
                        $dataValueValue = $datatypeAdapter->getGeometryFromValue($valueValue);
                        if ($this->srid
                            && $datatype === 'geometry:geography'
                            && empty($dataValueValue->getSrid())
                        ) {
                            $dataValueValue->setSrid($this->srid);
                        }
                        $dataValue->setValue($dataValueValue);
                        $this->entityManager->persist($dataValue);
                        break;
                    default:
                        break;
                }
            }
        }
    }

    protected function fillItemSet(array $resource): void
    {
        $this->fillResource($resource);

        $this->entity->setIsOpen(!empty($resource['o:is_open']));
    }

    protected function fillItem(array $resource): void
    {
        $this->fillResource($resource);

        $itemSets = $this->entity->getItemSets();
        $itemSetIds = [];
        foreach ($itemSets as $itemSet) {
            $itemSetIds[] = $itemSet->getId();
        }
        foreach ($resource['o:item_set'] as $itemSet) {
            if (isset($this->map['item_sets'][$itemSet['o:id']])
                // This check avoids a core bug.
                && !in_array($this->map['item_sets'][$itemSet['o:id']], $itemSetIds)
            ) {
                $itemSets->add($this->entityManager->find(\Omeka\Entity\ItemSet::class, $this->map['item_sets'][$itemSet['o:id']]));
            }
        }

        // Media are updated separately in order to manage files.
    }

    protected function fillMedia(array $resource): void
    {
        $this->fillResource($resource);

        $item = $this->entityManager->find(\Omeka\Entity\Item::class, $this->map['items'][$resource['o:item']['o:id']]);
        $this->entity->setItem($item);

        // TODO Keep the original storage id of assets (so check existing one as a whole).
        // $storageId = substr($asset['o:filename'], 0, $pos);
        // @see \Omeka\File\TempFile::getStorageId()
        if ($resource['o:filename']
            && ($pos = mb_strrpos($resource['o:filename'], '.')) !== false
        ) {
            $storageId = bin2hex(\Laminas\Math\Rand::getBytes(20));
            $extension = substr($resource['o:filename'], $pos + 1);

            $result = $this->fetchUrl('original', $resource['o:source'], $resource['o:filename'], $storageId, $extension, $resource['o:original_url']);
            if ($result['status'] !== 'success') {
                $this->logger->err($result['message']);
            // Continue in order to update other metadata, in particular item.
            } else {
                if ($resource['o:media_type'] !== $result['data']['media_type']) {
                    $this->logger->err(new PsrMessage(
                        'Media type of media #{id} is different from the original one ({media_type}).', // @translate
                        ['id' => $this->entity->getId(), $resource['o:media_type']]
                    ));
                }
                if ($resource['o:sha256'] !== $result['data']['sha256']) {
                    $this->logger->err(new PsrMessage(
                        'Hash of media #{id} is different from the original one.', // @translate
                        ['id' => $this->entity->getId()]
                    ));
                }
                $this->entity->setStorageId($storageId);
                $this->entity->setExtension($extension);
                $this->entity->setSha256($result['data']['sha256']);
                $this->entity->setMediaType($result['data']['media_type']);
                $this->entity->setHasOriginal(true);
                $this->entity->setHasThumbnails($result['data']['has_thumbnails']);
                $this->entity->setSize($result['data']['size']);
            }
        }

        // TODO Check and manage ingesters and renderers.
        $this->entity->setIngester($resource['o:ingester']);
        $this->entity->setRenderer($resource['o:renderer']);

        $this->entity->setData($resource['data'] ?? null);
        $this->entity->setSource($resource['o:source'] ?: null);
        $this->entity->setLang(!empty($resource['o:lang']) ? $resource['o:lang'] : null);

        $position = 0;
        $resourceId = $resource['o:id'];
        foreach ($this->entity->getItem()->getMedia() as $media) {
            ++$position;
            if ($resourceId === $media->getId()) {
                $this->entity->setPosition($position);
                break;
            }
        }
    }

    protected function fillMapping(): void
    {
        if (empty($this->modules['Mapping'])) {
            return;
        }

        $resourceTypes = [
            'mappings' => \Mapping\Entity\Mapping::class,
            'mapping_markers' => \Mapping\Entity\MappingMarker::class,
        ];
        $methods = [
            'mappings' => 'fillMappingMapping',
            'mapping_markers' => 'fillMappingMarkers',
        ];

        foreach ($resourceTypes as $resourceType => $class) {
            $this->totals[$resourceType] = $this->reader->setObjectType($resourceType)->count();
            /** @var \Omeka\Api\Adapter\AbstractResourceEntityAdapter $adapter */
            $adapter = $this->adapterManager->get($resourceType);
            $index = 0;
            $created = 0;
            $skipped = 0;
            $method = $methods[$resourceType];
            foreach ($this->reader->setObjectType($resourceType) as $resource) {
                ++$index;

                $this->entity = new $class;
                $this->$method($resource);

                $errorStore = new \Omeka\Stdlib\ErrorStore;
                $adapter->validateEntity($this->entity, $errorStore);
                if ($errorStore->hasErrors()) {
                    ++$skipped;
                    $this->logErrors($this->entity, $errorStore);
                    continue;
                }

                $this->entityManager->persist($this->entity);
                ++$created;

                if ($created % self::CHUNK_ENTITIES === 0) {
                    $this->entityManager->flush();
                    $this->entityManager->clear();
                    $this->logger->notice(
                        '{count}/{total} resource "{type}" imported, {skipped} skipped.', // @translate
                        ['count' => $created, 'total' => $this->totals[$resourceType], 'type' => $resourceType, 'skipped' => $skipped]
                    );
                }
            }

            // Remaining entities.
            $this->entityManager->flush();
            $this->entityManager->clear();
            $this->logger->notice(
                '{count}/{total} resource "{type}" imported, {skipped} skipped.', // @translate
                ['count' => $created, 'total' => count($this->map[$resourceType]), 'type' => $resourceType, 'skipped' => $skipped]
            );
        }
    }

    protected function fillMappingMappings(array $resource): void
    {
        $item = $this->entityManager->find(\Omeka\Entity\Item::class, $this->map['items'][$resource['o:item']['o:id']]);
        if (!$item) {
            $this->logger->warn(
                'The source item #{source_id} is not found for its mapping zone.', // @translate
                ['source_id' => $resource['o:item']['o:id']]
            );
        } else {
            $this->entity->setItem($item);
        }
        $this->entity->setBounds($resource['o-module-mapping:bounds']);
    }

    protected function fillMappingMarkers(array $resource): void
    {
        $item = $this->entityManager->find(\Omeka\Entity\Item::class, $this->map['items'][$resource['o:item']['o:id']]);
        if (!$item) {
            $this->logger->warn(
                'The source item #{source_id} is not found for its mapping marker.', // @translate
                ['source_id' => $resource['o:item']['o:id']]
            );
        } else {
            $this->entity->setItem($item);
        }

        if (!empty($resource['o:item']['o:id'])) {
            $media = $this->entityManager->find(\Omeka\Entity\Media::class, $this->map['media'][$resource['o:media']['o:id']]);
            if (!$media) {
                $this->logger->warn(
                    'The source media #{source_id} is not found for its mapping marker.', // @translate
                    ['source_id' => $resource['o:media']['o:id']]
                );
            } else {
                $this->entity->setMedia($media);
            }
        }

        $this->entity->setLat($resource['o-module-mapping:lat']);
        $this->entity->setLng($resource['o-module-mapping:lng']);
        if (array_key_exists('o-module-mapping:label', $resource)) {
            $this->entity->setLabel($resource['o-module-mapping:label']);
        }
    }

    /**
     * Check if managed modules are available.
     */
    protected function checkAvailableModules(): void
    {
        // Modules managed by the module.
        $moduleClasses = [
            'CustomVocab',
            'DataTypeGeometry',
            'DataTypeRdf',
            'Mapping',
            'NumericDataTypes',
            'ValueSuggest',
        ];
        /** @var \Omeka\Module\Manager $moduleManager */
        $moduleManager = $this->getServiceLocator()->get('Omeka\ModuleManager');
        foreach ($moduleClasses as $moduleClass) {
            $module = $moduleManager->getModule($moduleClass);
            $this->modules[$moduleClass] = $module
                && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE;
        }
    }

    /**
     * The owner should be reloaded each time the entity manager is cleared, so
     * it is saved and reloaded.
     *
     * @todo Check if the users should always be reloaded.
     */
    protected function refreshOwner(): void
    {
        if ($this->ownerId) {
            $this->owner = $this->entityManager->find(\Omeka\Entity\User::class, $this->ownerId);
        }
    }

    /**
     * Fetch, check and save a file.
     *
     * @todo Create derivative files (thumbnails) with the tempfile factory.
     *
     * @param string $type
     * @param string $sourceName
     * @param string $filename
     * @param string $storageId
     * @param string $extension
     * @param string $url
     * @return array
     */
    protected function fetchUrl($type, $sourceName, $filename, $storageId, $extension, $url)
    {
        // Quick check.
        if (!$this->disableFileValidation
            && $type !== 'asset'
            && !in_array($extension, $this->allowedExtensions)
        ) {
            return [
                'status' => 'error',
                'message' => new PsrMessage(
                    'File {url} has not an allowed extension.', // @translate
                    ['url' => $url]
                ),
            ];
        }

        $tempname = tempnam($this->tempPath, 'omkbulk_');
        // @see https://stackoverflow.com/questions/724391/saving-image-from-php-url
        // Curl is faster than copy or file_get_contents/file_put_contents.
        // $result = copy($url, $tempname);
        // $result = file_put_contents($tempname, file_get_contents($url), \LOCK_EX);
        $ch = curl_init($url);
        $fp = fopen($tempname, 'wb');
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_exec($ch);
        curl_close($ch);
        fclose($fp);

        if (!filesize($tempname)) {
            return [
                'status' => 'error',
                'message' => new PsrMessage(
                    'Unable to download asset {url}.', // @translate
                    ['url' => $url]
                ),
            ];
        }

        // In all cases, the media type is checked for aliases.
        // @see \Omeka\File\TempFile::getMediaType().
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mediaType = $finfo->file($tempname);
        if (array_key_exists($mediaType, \Omeka\File\TempFile::MEDIA_TYPE_ALIASES)) {
            $mediaType = \Omeka\File\TempFile::MEDIA_TYPE_ALIASES[$mediaType];
        }

        // Check the mime type for security.
        if (!$this->disableFileValidation) {
            if ($type === 'asset') {
                if (!in_array($mediaType, \Omeka\Api\Adapter\AssetAdapter::ALLOWED_MEDIA_TYPES)) {
                    unlink($tempname);
                    return [
                        'status' => 'error',
                        'message' => new PsrMessage(
                            'Asset {url} is not an image.', // @translate
                            ['url' => $url]
                        ),
                    ];
                }
            } elseif (!in_array($mediaType, $this->allowedMediaTypes)) {
                unlink($tempname);
                return [
                    'status' => 'error',
                    'message' => new PsrMessage(
                        'File {url} is not an allowed file.', // @translate
                        ['url' => $url]
                    ),
                ];
            }
        }

        $destPath = $this->basePath . '/' . $type . '/' . $storageId . '.' . $extension;

        /** @var \Omeka\File\TempFile $tempFile */
        $tempFile = $this->tempFileFactory->build();
        $tempFile->setTempPath($tempname);
        $tempFile->setStorageId($storageId);
        $tempFile->setSourceName($filename);

        $tempFile->store($type, $extension, $tempname);
        /*
        $result = rename($tempname, $destPath);
        if (!$result) {
            unlink($tempname);
            return [
                'status' => 'error',
                'message' => new PsrMessage(
                    'File {url} cannot be saved.', // @translate
                    ['url' => $url]
                ),
            ];
        }
        */

        $hasThumbnails = $type !== 'asset';
        if ($hasThumbnails) {
            $hasThumbnails = $tempFile->storeThumbnails();
        }

        return [
            'status' => 'success',
            'data' => [
                'fullpath' => $destPath,
                'media_type' => $tempFile->getMediaType(),
                'sha256' => $tempFile->getSha256(),
                'has_thumbnails' => $hasThumbnails,
                'size' => $tempFile->getSize(),
            ],
        ];
    }

    protected function logErrors($entity, $errorStore): void
    {
        foreach ($errorStore->getErrors() as $messages) {
            if (!is_array($messages)) {
                $messages = [$messages];
            }
            foreach ($messages as $message) {
                $this->logger->err($message);
            }
        }
    }
}
