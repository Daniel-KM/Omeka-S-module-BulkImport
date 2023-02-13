<?php declare(strict_types=1);

namespace BulkImport\Processor;

use BulkImport\Interfaces\Parametrizable;
use BulkImport\Reader\FakeReader;
use BulkImport\Traits\ConfigurableTrait;
use BulkImport\Traits\ParametrizableTrait;
use Laminas\Form\Form;
use Omeka\Api\Representation\VocabularyRepresentation;
use Omeka\Entity\ItemSet;

/**
 * @todo The processor is only parametrizable currently.
 * @deprecated Use MetaMapper and convert full processors into standard processors and make a multi-processor for full migration.
 */
abstract class AbstractFullProcessor extends AbstractProcessor implements Parametrizable
{
    use AssetTrait;
    use ConfigTrait;
    use ConfigurableTrait;
    use CountEntitiesTrait;
    use CustomVocabTrait;
    use DateTimeTrait;
    use FileTrait;
    use InternalIntegrityTrait;
    use LanguageTrait;
    use MappingTrait;
    use ParametrizableTrait;
    use ResourceTemplateTrait;
    use ResourceTrait;
    use StatisticsTrait;
    use ThesaurusTrait;
    use ToolsTrait;
    use UserTrait;
    use VocabularyTrait;

    /**
     * The max number of entities before a flush/clear.
     *
     * @var int
     */
    const CHUNK_ENTITIES = 100;

    /**
     * The max number of entities before a flush/clear.
     *
     * @var int
     */
    const CHUNK_SIMPLE_RECORDS = 1000;

    /**
     * The max number of the rows to build the temporary table of ids.
     *
     * @var int
     */
    const CHUNK_RECORD_IDS = 10000;

    /**
     * @var string
     */
    protected $resourceLabel;

    /**
     * @var string
     */
    protected $configFormClass;

    /**
     * @var string
     */
    protected $paramsFormClass;

    /**
     * @var array
     */
    protected $configDefault = [];

    /**
     * @var array
     */
    protected $paramsDefault = [];

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
     * @var bool
     */
    protected $isStopping = false;

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
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    /**
     * @var \Omeka\Api\Adapter\AdapterInterface
     */
    protected $adapterManager;

    /**
     * @var \Omeka\DataType\Manager
     */
    protected $datatypeManager;

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
     * @var \DateTime
     */
    protected $currentDateTime;

    /**
     * The date is formatted for mysql, not iso.
     *
     * @var string
     */
    protected $currentDateTimeFormatted;

    /**
     * @var string
     */
    protected $tempPath;

    /**
     * @var int
     */
    protected $srid;

    /**
     * @var array
     */
    protected $modules = [];

    /**
     * @var array
     */
    protected $modulesOptional = [
        'BulkCheck',
        'BulkEdit',
        'CustomVocab',
        'DataTypeGeometry',
        'DataTypeRdf',
        'Mapping',
        'NumericDataTypes',
        'ValueSuggest',
    ];

    /**
     * @var array
     */
    protected $modulesRequired = [];

    /**
     * @var array
     */
    protected $modulesActive = [];

    /**
     * List of importables Omeka resources for generic purposes.
     *
     * The default is derived from Omeka database, different from api endpoint.
     * A specific processor can override this property or use $moreImportables.
     * Nevertheless, it is related to the omeka database, so it is generally
     * useless to override data.
     *
     * The keys to use for automatic management are:
     * - name: api resource name
     * - class: the Omeka class for the entity manager
     * - main_entity: name of the destination for derivated resource,
     *   for example "resources" for "items".
     * - table: destination (specific table when there is a main table)
     * - key_id: name of the key to get the id of a record
     * - column_keep_id: string column used to keep the id of source temporary.
     *   It must be reset or overridden in a second time.
     *
     * @var array
     */
    protected $importables = [
        // For internal process (mainly to get table), data must be overridden.
        'resources' => [
            'name' => 'resources',
            'class' => \Omeka\Entity\Resource::class,
            'table' => 'resource',
            'column_keep_id' => 'title',
        ],
        // Common importables.
        'users' => [
            'name' => 'users',
            'class' => \Omeka\Entity\User::class,
            'table' => 'user',
            'fill' => 'fillUser',
            'column_keep_id' => 'email',
        ],
        'assets' => [
            'name' => 'assets',
            'class' => \Omeka\Entity\Asset::class,
            'table' => 'asset',
            'column_keep_id' => 'alt_text',
        ],
        'items' => [
            'name' => 'items',
            'class' => \Omeka\Entity\Item::class,
            'main_entity' => 'resources',
            'table' => 'item',
            'fill' => 'fillItem',
        ],
        'media' => [
            'name' => 'media',
            'class' => \Omeka\Entity\Media::class,
            'main_entity' => 'resources',
            'table' => 'media',
            'fill' => 'fillMedia',
            'parent' => 'items',
        ],
        'item_sets' => [
            'name' => 'item_sets',
            'class' => \Omeka\Entity\ItemSet::class,
            'main_entity' => 'resources',
            'table' => 'item_set',
            'fill' => 'fillItemSet',
        ],
        'vocabularies' => [
            'name' => 'vocabularies',
            'class' => \Omeka\Entity\Vocabulary::class,
            'table' => 'vocabulary',
        ],
        'properties' => [
            'name' => 'properties',
            'class' => \Omeka\Entity\Property::class,
            'table' => 'property',
        ],
        'resource_classes' => [
            'name' => 'resource_classes',
            'class' => \Omeka\Entity\ResourceClass::class,
            'table' => 'resource_class',
        ],
        'resource_templates' => [
            'name' => 'resource_templates',
            'class' => \Omeka\Entity\ResourceTemplate::class,
            'table' => 'resource_template',
        ],
        // Allow to import the source as item + media, not separately.
        'media_items' => [
            'name' => 'items',
            'class' => \Omeka\Entity\Item::class,
            'main_entity' => 'resources',
            'table' => 'item',
            'fill' => 'fillMediaItem',
            'sub' => 'media_items_sub',
        ],
        'media_items_sub' => [
            'name' => 'media',
            'class' => \Omeka\Entity\Media::class,
            'main_entity' => 'resources',
            'table' => 'media',
            'fill' => 'fillMediaItemMedia',
            'parent' => 'media_items',
        ],
        // Modules.
        'custom_vocabs' => [
            'name' => 'custom_vocabs',
            'class' => \CustomVocab\Entity\CustomVocab::class,
            'table' => 'custom_vocab',
        ],
        'mappings' => [
            'name' => 'mappings',
            'class' => \Mapping\Entity\Mapping::class,
            'table' => 'mapping',
            'fill' => 'fillMappingMapping',
        ],
        'mapping_markers' => [
            'name' => 'mapping_markers',
            'class' => \Mapping\Entity\MappingMarker::class,
            'table' => 'mapping_marker',
            'fill' => 'fillMappingMarker',
        ],
        'concepts' => [
            'name' => 'items',
            'class' => \Omeka\Entity\Item::class,
            'main_entity' => 'resources',
            'table' => 'item',
            'fill' => 'fillConcept',
            'is_resource' => true,
        ],
        'hits' => [
            'name' => 'hits',
            'class' => \Statistics\Entity\Hit::class,
            'table' => 'hit',
            'column_keep_id' => 'url',
        ],
    ];

    /**
     * To be overridden by specific processor. Merged with importables.
     *
     * @var array
     */
    protected $moreImportables = [];

    /**
     * Mapping of Omeka resources according to the reader.
     *
     * Simply set null to the resource source to skip its process.
     *
     * The default is derived from Omeka database, different from api endpoint.
     *
     * The keys to use for automatic management are:
     * - source: table or path. If null, not importable.
     * - key_id: the id of the key in the source output.
     * - sort_by: the name of the sort value (key_id by default).
     * - sort_dir: "asc" (default) or "desc".
     *
     * Some specific keys can be added for some entities.
     *
     * @var array
     */
    protected $mapping = [
        'users' => [
            'source' => 'user',
            'key_id' => 'id',
            'key_email' => 'email',
            // A unique name is not required in Omeka.
            'key_name' => null,
        ],
        'assets' => [
            'source' => 'asset',
            'key_id' => 'id',
        ],
        'items' => [
            'source' => 'item',
            'key_id' => 'id',
        ],
        // Warning: media has no final "s".
        'media' => [
            'source' => 'media',
            'key_id' => 'id',
            'key_parent_id' => 'item_id',
        ],
        'media_items' => [
            'source' => null,
            'key_id' => null,
        ],
        'item_sets' => [
            'source' => 'item_set',
            'key_id' => 'id',
        ],
        'vocabularies' => [
            'source' => 'vocabulary',
            'key_id' => 'id',
        ],
        'properties' => [
            'source' => 'property',
            'key_id' => 'id',
        ],
        'resource_classes' => [
            'source' => 'resource_class',
            'key_id' => 'id',
        ],
        'resource_templates' => [
            'source' => 'resource_template',
            'key_id' => 'id',
        ],
        // Modules.
        'custom_vocabs' => [
            'source' => 'custom_vocab',
            'key_id' => 'id',
        ],
        'custom_vocab_keywords' => [
            'source' => null,
        ],
        'mappings' => [
            'source' => 'mapping',
            'key_id' => 'id',
        ],
        'mapping_markers' => [
            'source' => 'mapping_marker',
            'key_id' => 'id',
        ],
        'concepts' => [
            'source' => null,
        ],
        'hits' => [
            'source' => 'hit',
            'key_id' => 'id',
            // The mode "sql" allows to import hits directly and is recommended
            // because the list of hit is generally very big.
            'mode' => 'sql',
        ],
    ];

    /**
     * A list of specific resources to reload when the entity manager is cleared.
     *
     * @see SpipProcessor
     *
     * @var array
     */
    protected $main = [
        'templates' => [],
        'classes' => [],
    ];

    /**
     * The entity being inserted.
     *
     * @var \Omeka\Entity\EntityInterface
     */
    protected $entity;

    /**
     * The current resource type (Omeka api name).
     *
     * @var string
     */
    protected $resourceName;

    /**
     * The current object type (source name).
     *
     * @var string|null
     */
    protected $objectType;

    /**
     * @var array
     */
    protected $storageIds = [];

    public function getLabel(): string
    {
        return $this->resourceLabel;
    }

    public function getConfigFormClass(): string
    {
        return $this->configFormClass;
    }

    public function getParamsFormClass(): string
    {
        return $this->paramsFormClass;
    }

    public function handleConfigForm(Form $form): self
    {
        $values = $form->getData();
        $result = array_intersect_key($values, $this->configDefault) + $this->configDefault;
        $this->setConfig($result);
        return $this;
    }

    public function handleParamsForm(Form $form): self
    {
        $values = $form->getData();
        $result = array_intersect_key($values, $this->paramsDefault) + $this->paramsDefault;
        $this->setParams($result);
        return $this;
    }

    public function process(): void
    {
        // TODO Add a dry-run.
        // TODO Add an option to use api or not.
        // TODO Add an option to stop/continue on error.
        // TODO Manage check of duplicate identifiers during dry-run.
        // $result['allow_duplicate_identifiers'] = (bool) $result['allow_duplicate_identifiers'];

        $services = $this->getServiceLocator();
        $this->entityManager = $services->get('Omeka\EntityManager');
        $this->connection = $services->get('Omeka\Connection');

        $this->adapterManager = $services->get('Omeka\ApiAdapterManager');
        $this->datatypeManager = $services->get('Omeka\DataTypeManager');
        $this->allowedDataTypes = $this->datatypeManager->getRegisteredNames();

        // The owner should be reloaded each time the entity manager is flushed.
        $ownerIdParam = $this->getParam('o:owner', 'current') ?: 'current';
        if ($ownerIdParam === 'current') {
            $identity = $services->get('ControllerPluginManager')->get('identity');
            $this->owner = $identity();
        } elseif ($ownerIdParam) {
            // TODO Use getReference() when possible in all the module.
            $this->owner = $this->entityManager->find(\Omeka\Entity\User::class, $ownerIdParam);
        }
        $this->ownerId = $this->owner ? $this->owner->getId() : null;
        $this->ownerOId = $this->owner ? ['o:id' => $this->owner->getId()] : null;

        $this->currentDateTime = new \DateTime();
        $this->currentDateTimeFormatted = $this->currentDateTime->format('Y-m-d H:i:s');

        $this->initFileTrait();

        $settings = $services->get('Omeka\Settings');
        $this->srid = $settings->get('datatypegeometry_locate_srid', 4326);

        $this->checkAvailableModules();

        $requireds = array_intersect_key(array_filter($this->modules), array_flip($this->modulesRequired));
        if (count($this->modulesRequired) && count($requireds) !== count($this->modulesRequired)) {
            $missings = array_diff($this->modulesRequired, array_keys($requireds));
            $this->hasError = true;
            $this->logger->err(
                'The process requires the missing modules {modules}. The following modules are already enabled: {enabled}.', // @translate
                [
                    'modules' => '"' . implode('", "', $missings) . '"',
                    'enabled' => '"' . implode('", "', array_intersect($this->modulesRequired, array_keys($requireds))) . '"',
                ]
            );
            return;
        }

        // Prepare main maps to avoid checks and strict type issues.
        $this->map['items'] = [];
        $this->map['item_sets'] = [];
        $this->map['media'] = [];
        $this->map['users'] = [];
        $this->map['custom_vocabs'] = [];
        $this->map['concepts'] = [];

        // Set the template id when a label is used.
        foreach ($this->mapping as $sourceType => $configData) {
            if (isset($configData['resource_template_id'])) {
                $templateId = $this->bulk->getResourceTemplateId($configData['resource_template_id']);
                if ($templateId) {
                    $this->mapping[$sourceType]['resource_template_id'] = $templateId;
                } else {
                    $templateId = $this->bulk->getResourceTemplateId($this->translator->translate($configData['resource_template_id']));
                    if ($templateId) {
                        $this->mapping[$sourceType]['resource_template_id'] = $templateId;
                    }
                }
            }
        }

        if (!$this->reader->isValid()) {
            $this->hasError = true;
            $this->logger->err('The source is unavailable. Check params, rights or connection.'); // @translate
            return;
        }

        foreach (array_keys($this->main) as $name) {
            $this->prepareMainResource($name);
        }
        if ($this->hasError) {
            return;
        }

        // TODO Finalize skip import of vocabularies, resource templates and custom vocabs.
        $args = $this->getParams();
        $args['types_selected'] = $args['types'];
        $args['types'][] = 'vocabularies';
        $args['types'][] = 'resource_templates';
        $args['types'][] = 'custom_vocabs';

        // Manage the case where the source manage media as items.
        if (!empty($this->mapping['media_items']['source'])) {
            $hasItem = array_search('items', $args['types']);
            $hasMedia = array_search('media', $args['types']);
            if ($hasItem !== false) {
                unset($args['types'][$hasItem]);
            }
            if ($hasMedia !== false) {
                unset($args['types'][$hasMedia]);
            }
            $args['types'][] = 'media_items';
        }

        $args['types'] = array_unique($args['types']);

        $args['fake_files'] = !empty($args['fake_files']);

        $this->setParams($args);

        $this->importables = array_replace($this->importables, $this->moreImportables);

        $this->preImport();
        if ($this->isErrorOrStop()) {
            return;
        }

        if ($this->hasConfigFile('properties') && !$this->loadTable('properties')) {
            $this->hasError = true;
            $this->logger->err(
                'Missing file "{filepath}" for the mapping of values.', // @translate
                ['filepath' => $this->configs['properties']['file']]
            );
            return;
        }

        $this->check();
        if ($this->isErrorOrStop()) {
            return;
        }

        /** @var \Laminas\EventManager\EventManager $eventManager */
        $eventManager = $services->get('EventManager');
        $args = $eventManager->prepareArgs([
            'job' => $this->job,
            'processor' => $this,
            'logger' => $this->logger,
        ]);
        // TODO Add a generic identifier for any processor?
        $eventManager->setIdentifiers([get_class($this)]);
        $eventManager->trigger('bulk.import.before', $this, $args);

        $this->importMetadata();
        if ($this->isErrorOrStop()) {
            return;
        }

        // Simplify next steps
        if ($this->isModuleActive('CustomVocab')) {
            $this->prepareCustomVocabCleanIds();
        }

        $this->importData();
        if ($this->isErrorOrStop()) {
            return;
        }

        $this->postImport();
        if ($this->isErrorOrStop()) {
            return;
        }

        $counts = array_combine(array_keys($this->map), array_map('count', $this->map));
        $counts = array_intersect_key($counts, array_flip($this->getParam('types', [])));
        $this->logger->notice(
            'Totals of data existing and imported: {json}.', // @translate
            ['json' => json_encode($counts)]
        );

        $this->completionJobs();
        if ($this->isErrorOrStop()) {
            return;
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

    protected function isErrorOrStop(): bool
    {
        if ($this->hasError || $this->isStopping) {
            return true;
        }
        if ($this->job->shouldStop()) {
            $this->isStopping = true;
            $this->logger->warn('The job was stopped.'); // @translate
            return true;
        }
        return false;
    }

    /**
     * A call to simplify sub-classes.
     */
    protected function preImport(): void
    {
    }

    protected function check(): void
    {
        $toImport = $this->getParam('types') ?: [];

        // Pre-process: check the Omeka database for assets and resources.
        // Use "media_items" if needed.
        if (in_array('media', $toImport) && !in_array('items', $toImport)) {
            $this->hasError = true;
            $this->logger->err('Resource "media" cannot be imported without items.'); // @translate
        }

        // Check database integrity for assets.
        $this->logger->info(
            'Check integrity of assets.' // @translate
        );
        $this->checkAssets();

        // Check database integrity for resources.
        $this->logger->info('Check integrity of resources.'); // @translate
        $this->checkResources();
    }

    protected function importMetadata(): void
    {
        $toImport = $this->getParam('types') ?: [];

        // FIXME Check for missing modules for datatypes (value suggest, custom vocab, numeric datatype, rdf datatype, geometry datatype).

        // Users are prepared first to include the owner anywhere.
        if (in_array('users', $toImport)
            && $this->prepareImport('users')
        ) {
            $this->logger->info('Import of users.'); // @translate
            $this->prepareUsers();
            if ($this->isErrorOrStop()) {
                return;
            }
        }

        if ($this->isErrorOrStop()) {
            return;
        }

        if (in_array('vocabularies', $toImport)
            && $this->prepareImport('vocabularies')
        ) {
            $this->logger->info('Check vocabularies.'); // @translate
            $this->checkVocabularies();
            if ($this->isErrorOrStop()) {
                return;
            }

            $this->prepareImport('vocabularies');
            $this->prepareVocabularies();
            if ($this->isErrorOrStop()) {
                return;
            }

            if ($this->prepareImport('properties')) {
                $this->logger->info('Preparation of properties.'); // @translate
                $this->prepareProperties();
                if ($this->isErrorOrStop()) {
                    return;
                }
            }

            if ($this->prepareImport('resource_classes')) {
                $this->logger->info('Preparation of resource classes.'); // @translate
                $this->prepareResourceClasses();
                if ($this->isErrorOrStop()) {
                    return;
                }
            }
        }

        // TODO Refresh the bulk lists for properties, resource classes and templates.

        if (in_array('custom_vocabs', $toImport)
            && !empty($this->modulesActive['CustomVocab'])
            && $this->prepareImport('custom_vocabs')
        ) {
            $this->logger->info('Check custom vocabs.'); // @translate
            $this->prepareCustomVocabsInitialize();
            if ($this->isErrorOrStop()) {
                return;
            }
        }

        if (in_array('resource_templates', $toImport)
            && $this->prepareImport('resource_templates')
        ) {
            $this->logger->info('Preparation of resource templates.'); // @translate
            $this->prepareResourceTemplates();
            if ($this->isErrorOrStop()) {
                return;
            }
        }
    }

    protected function importData(): void
    {
        $toImport = $this->getParam('types') ?: [];

        // The process uses two loops: creation of all resources empty, then
        // filling them. This process is required to manage relations between
        // resources, while any other user can create new resources at the same
        // time.

        // Users and assets are prepared first, because they can be used by
        // other resources.

        // First loop: create one resource by resource.

        if (in_array('assets', $toImport)
            && $this->prepareImport('assets')
        ) {
            $this->logger->info('Initialization of all assets.'); // @translate
            $this->prepareAssets();
            if ($this->isErrorOrStop()) {
                return;
            }
        }

        if (array_intersect(['items', 'media', 'media_items', 'item_sets'], $toImport)) {
            $this->logger->info('Initialization of all resources.'); // @translate
            if (in_array('items', $toImport)
                && $this->prepareImport('items')
            ) {
                $this->prepareItems();
                if ($this->isErrorOrStop()) {
                    return;
                }
            }
            if (in_array('media', $toImport)
                && $this->prepareImport('media')
            ) {
                $this->prepareMedias();
                if ($this->isErrorOrStop()) {
                    return;
                }
            }
            if (in_array('media_items', $toImport)
                && $this->prepareImport('media_items')
            ) {
                $this->prepareMediaItems();
                if ($this->isErrorOrStop()) {
                    return;
                }
            }
            if (in_array('item_sets', $toImport)
                && $this->prepareImport('item_sets')
            ) {
                $this->prepareItemSets();
                if ($this->isErrorOrStop()) {
                    return;
                }
            }
        }

        // For child processors.
        $this->prepareOthers();
        if ($this->isErrorOrStop()) {
            return;
        }

        // Second loop.

        if (in_array('users', $toImport)
            && $this->prepareImport('users')
        ) {
            $this->logger->info('Finalization of users.'); // @translate
            $this->fillUsers();
        }

        if (in_array('assets', $toImport)
            && $this->prepareImport('assets')
        ) {
            $this->logger->info('Finalization of assets.'); // @translate
            $this->fillAssets();
        }

        if (array_intersect(['items', 'media', 'media_items', 'item_sets'], $toImport)) {
            $this->logger->info('Preparation of metadata of all resources.'); // @translate
            if (in_array('items', $toImport)
                && $this->prepareImport('items')
            ) {
                $this->fillItems();
                if ($this->isErrorOrStop()) {
                    return;
                }
            }
            if (in_array('media', $toImport)
                && $this->prepareImport('media')
            ) {
                $this->fillMedias();
                if ($this->isErrorOrStop()) {
                    return;
                }
            }
            if (in_array('media_items', $toImport)
                && $this->prepareImport('media_items')
            ) {
                $this->fillMediaItems();
                if ($this->isErrorOrStop()) {
                    return;
                }
            }
            if (in_array('item_sets', $toImport)
                && $this->prepareImport('item_sets')
            ) {
                $this->fillItemSets();
                if ($this->isErrorOrStop()) {
                    return;
                }
            }
        }

        // For child processors.
        $this->fillOthers();
        if ($this->isErrorOrStop()) {
            return;
        }

        // For long child processors.
        $this->fillOthersLong();
        // if ($this->isErrorOrStop()) {
        //     return;
        // }
    }

    /**
     * A call to simplify sub-classes.
     */
    protected function postImport(): void
    {
    }

    /**
     * Prepare main resources that should be available along the loops.
     *
     * @param string $name
     * @param array $data
     */
    protected function prepareMainResource(string $name): void
    {
        if (!empty($this->main[$name]['template'])) {
            $entity = $this->bulk->api()->searchOne('resource_templates', ['label' => $this->main[$name]['template']], ['initialize' => false, 'finalize' => false, 'responseContent' => 'resource'])->getContent();
            if (!$entity) {
                $this->hasError = true;
                $this->logger->err(
                    'The resource template "{label}" is required to import {name}.', // @translate
                    ['label' => $this->main[$name]['template'], 'name' => $this->resourceLabel]
                );
                return;
            }
            $this->main[$name]['template'] = $entity;
            $this->main[$name]['template_id'] = $entity->getId();
        }

        if (!empty($this->main[$name]['class'])) {
            $entity = $this->bulk->api()->searchOne('resource_classes', ['term' => $this->main[$name]['class']], ['initialize' => false, 'finalize' => false, 'responseContent' => 'resource'])->getContent();
            if (!$entity) {
                $this->hasError = true;
                $this->logger->err(
                    'The resource class "{label}" is required to import {name}.', // @translate
                    ['label' => $this->main[$name]['class'], 'name' => $this->resourceLabel]
                );
                return;
            }
            $this->main[$name]['class'] = $entity;
            $this->main[$name]['class_id'] = $entity->getId();
        }

        if (!empty($this->main[$name]['custom_vocab'])) {
            $entity = $this->bulk->api()->searchOne('custom_vocabs', ['label' => $this->main[$name]['custom_vocab']], ['initialize' => false, 'finalize' => false, 'responseContent' => 'resource'])->getContent();
            if (!$entity) {
                $this->hasError = true;
                $this->logger->err(
                    'The custom vocab"{label}" is required to import {name}.', // @translate
                    ['label' => $this->main[$name]['custom_vocab'], 'name' => $this->resourceLabel]
                );
                return;
            }
            $this->main[$name]['custom_vocab'] = $entity;
            $this->main[$name]['custom_vocab_id'] = $entity->getId();
        }
    }

    protected function prepareImport(string $resourceName): bool
    {
        $this->resourceName = $resourceName;
        $this->objectType = $this->mapping[$resourceName]['source'] ?? null;
        if (empty($this->objectType)) {
            $this->logger->debug(
                'The source "{type}" is skipped of the mapping or not managed, so resources cannot be prepared or filled.', // @translate
                ['type' => $resourceName]
            );
            return false;
        }
        return true;
    }

    /**
     * When no vocabulary is imported, the mapping should be filled for
     * properties and resource classes to simplify resource filling.
     *
     * @see \BulkImport\Processor\VocabularyTrait::prepareVocabularyMembers()
     */
    protected function prepareInternalVocabularies(): void
    {
        foreach ($this->bulk->getPropertyIds() as $term => $id) {
            $this->map['properties'][$term] = [
                'term' => $term,
                'source' => $id,
                'id' => $id,
            ];
        }
        $this->map['by_id']['properties'] = array_map('intval', array_column($this->map['properties'], 'id', 'source'));

        foreach ($this->bulk->getResourceClassIds() as $term => $id) {
            $this->map['resource_classes'][$term] = [
                'term' => $term,
                'source' => $id,
                'id' => $id,
            ];
        }
        $this->map['by_id']['resource_classes'] = array_map('intval', array_column($this->map['resource_classes'], 'id', 'source'));
    }

    /**
     * When no template is imported, the mapping should be filled for templates
     * to simplify resource filling.
     *
     * @see \BulkImport\Processor\VocabularyTrait::prepareVocabularyMembers()
     */
    protected function prepareInternalTemplates(): void
    {
        $this->map['resource_templates'] = $this->bulk->getResourceTemplateIds();
    }

    protected function checkVocabularies(): void
    {
        // The clone is needed because the properties use the reader inside the
        // loop.
        foreach ($this->prepareReader('vocabularies', true) as $vocabulary) {
            $result = $this->checkVocabulary($vocabulary);
            if ($result['status'] !== 'success') {
                $this->hasError = true;
            }
        }
    }

    protected function checkVocabularyProperties(array $vocabulary, VocabularyRepresentation $vocabularyRepresentation): array
    {
        // TODO Add a filter to the reader.
        $vocabularyProperties = [];
        foreach ($this->prepareReader('properties') as $property) {
            if ($property['o:vocabulary']['o:id'] === $vocabulary['o:id']) {
                $vocabularyProperties[] = $property['o:local_name'];
            }
        }
        return $vocabularyProperties;
    }

    protected function prepareVocabularies(): void
    {
        // The clone is needed because the properties use the reader inside the
        // loop.
        // TODO Avoid the second check of properties.
        $this->prepareVocabulariesProcess($this->prepareReader('vocabularies', true));
    }

    protected function prepareProperties(): void
    {
        $this->prepareVocabularyMembers($this->prepareReader('properties'), 'properties');
    }

    protected function prepareResourceClasses(): void
    {
        $this->prepareVocabularyMembers($this->prepareReader('resource_classes'), 'resource_classes');
    }

    protected function prepareUsers(): void
    {
        $this->prepareUsersProcess($this->prepareReader('users'));
    }

    protected function prepareCustomVocabsInitialize(): void
    {
        $this->prepareCustomVocabsProcess($this->prepareReader('custom_vocabs'));
    }

    protected function prepareResourceTemplates(): void
    {
        $this->prepareResourceTemplatesProcess($this->prepareReader('resource_templates'));
    }

    protected function prepareAssets(): void
    {
        $this->prepareAssetsProcess($this->prepareReader('assets'));
    }

    protected function prepareItems(): void
    {
        $this->prepareResources($this->prepareReader('items'), 'items');
    }

    protected function prepareMedias(): void
    {
        $this->prepareResources($this->prepareReader('media'), 'media');
    }

    protected function prepareMediaItems(): void
    {
        $this->prepareResources($this->prepareReader('media_items'), 'media_items');
    }

    protected function prepareItemSets(): void
    {
        $this->prepareResources($this->prepareReader('item_sets'), 'item_sets');
    }

    protected function prepareOthers(): void
    {
        $toImport = $this->getParam('types') ?: [];

        if (!empty($this->modulesActive['Thesaurus'])
            && in_array('concepts', $toImport)
            && $this->prepareImport('concepts')
        ) {
            // It's possible to change the values in $this->thesaurusProcess
            // to manage multiple thesaurus, before and after each step.
            $this->logger->notice('Preparation of metadata of module Thesaurus.'); // @translate
            $this->configThesaurus = 'concepts';
            $this->prepareThesaurus();
            $this->prepareConcepts($this->prepareReader('concepts'));
        }

        if (!empty($this->modulesActive['Statistics'])
            && in_array('hits', $toImport)
            && $this->prepareImport('hits')
            && $this->mapping['hits']['mode'] !== 'sql'
        ) {
            $this->logger->notice('Preparation of metadata of module Statistics.'); // @translate
            $this->prepareHits();
        }
    }

    protected function fillUsers(): void
    {
        $this->fillUsersProcess($this->prepareReader('users'));
    }

    protected function fillAssets(): void
    {
        $this->fillAssetsProcess($this->prepareReader('assets'));
    }

    protected function fillItems(): void
    {
        $this->fillResourcesProcess($this->prepareReader('items'), 'items');
    }

    protected function fillMedias(): void
    {
        $this->fillResourcesProcess($this->prepareReader('media'), 'media');
    }

    protected function fillMediaItems(): void
    {
        $this->fillResourcesProcess($this->prepareReader('media_items'), 'media_items');
    }

    protected function fillItemSets(): void
    {
        $this->fillResourcesProcess($this->prepareReader('item_sets'), 'item_sets');
    }

    protected function fillOthers(): void
    {
        $toImport = $this->getParam('types') ?: [];

        if (!empty($this->modulesActive['Thesaurus'])
            && in_array('concepts', $toImport)
            && $this->prepareImport('concepts')
        ) {
            $this->configThesaurus = 'concepts';
            $this->fillConcepts();
        }

        if (!empty($this->modulesActive['Mapping'])
            && in_array('mappings', $toImport)
        ) {
            $this->logger->info('Preparation of metadata of module Mapping.'); // @translate
            // Not prepare: there are mappings and mapping markers.
            $this->fillMapping();
        }
    }

    protected function fillOthersLong(): void
    {
        $toImport = $this->getParam('types') ?: [];

        if (!empty($this->modulesActive['Statistics'])
            && in_array('hits', $toImport)
            && $this->prepareImport('hits')
        ) {
            // The mode is not checked for now: the method is simply overridden.
            // @see EprintsProcessor::fillHits() (and git for by-one import).
            $this->fillHits();
        }
    }

    protected function findOrCreateItemSet(string $name): ItemSet
    {
        $itemSet = $this->entityManager->getRepository(\Omeka\Entity\ItemSet::class)->findOneBy(['title' => $name]);
        if ($itemSet) {
            return $itemSet;
        }

        $itemSet = new \Omeka\Entity\ItemSet();
        $itemSet->setOwner($this->owner);
        $itemSet->setTitle($name);
        $itemSet->setCreated($this->currentDateTime);
        $itemSet->setIsOpen(true);
        $this->appendValue([
            'term' => 'dcterms:title',
            'value' => $name,
        ], $itemSet);
        $this->entityManager->persist($itemSet);
        $this->entityManager->flush();
        return $this->entityManager->getRepository(\Omeka\Entity\ItemSet::class)->findOneBy(['title' => $name]);
    }

    protected function completionJobs(): void
    {
        // Save the map as a php array for future purpose (cf. lien rubrique spip).
        $filepath = $this->basePath . '/bulk_import/' . 'import_' . $this->job->getImportId() . '.json';
        if (!is_dir(dirname($filepath))) {
            @mkdir(dirname($filepath), 0775, true);
        }
        file_put_contents($filepath, json_encode($this->map, 448));

        $baseUrlPath = $this->services->get('Router')->getBaseUrl();
        $this->logger->notice(
            'Mapping saved in "{url}".', // @translate
            ['url' => $baseUrlPath . '/files/' . mb_substr($filepath, strlen($this->basePath) + 1)]
        );

        $this->logger->notice('Running jobs for reindexation and finalization. Check next jobs in admin interface.'); // @translate

        /** @var \Omeka\Mvc\Controller\Plugin\JobDispatcher $dispatcher */
        $services = $this->getServiceLocator();
        $plugins = $services->get('ControllerPluginManager');

        // Process only new resource ids.
        $ids = array_merge(
            $this->map['items'] ?? [],
            $this->map['media'] ?? [],
            $this->map['item_sets'] ?? [],
            $this->map['annotations'] ?? [],
            $this->map['concepts'] ?? [],
            $this->map['media_items'] ?? [],
            $this->map['media_items_sub'] ?? []
        );
        // Add other resources.
        foreach ($this->importables as $name => $importable) {
            if (!empty($importable['is_resource']) && !empty($this->map[$name])) {
                $ids = array_merge($ids, $this->map[$name]);
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));

        $this->logger->notice('Assigning items to sites.'); // @translate
        $sitePools = $this->bulk->api()->search('sites', [], ['returnScalar' => 'itemPool'])->getContent();
        $sitePools = array_map(function ($v) {
            return is_array($v) ? $v : [];
        }, $sitePools);
        $this->dispatchJob(\Omeka\Job\UpdateSiteItems::class, [
            'sites' => $sitePools,
            'action' => 'add',
        ]);
        $this->logger->notice('Items assigned to sites.'); // @translate

        // Short job to deduplicate values.
        if ($plugins->has('deduplicateValues')) {
            $this->logger->notice('Deduplicating values.'); // @translate
            $plugins->get('deduplicateValues')->__invoke();
            $this->logger->notice('Values deduplicated.'); // @translate
        } else {
            $this->logger->warn(
                'To deduplicate metadata, run the job "Deduplicate values" with module Bulk Edit.' // @translate
            );
        }

        // Short required job.
        // Process all resources, it's a quick job and there may be more ids.
        $this->logger->notice(
            'Updating titles for all resources.' // @translate
        );
        $this->dispatchJob(\BulkImport\Job\UpdateResourceTitles::class);
        $this->logger->notice(
            'Titles updated for all resources.' // @translate
        );

        $this->completionShortJobs($ids);

        if ($this->isErrorOrStop()) {
            return;
        }

        /** @var \Laminas\EventManager\EventManager $eventManager */
        $eventManager = $services->get('EventManager');
        $args = $eventManager->prepareArgs([
            'job' => $this->job,
            'processor' => $this,
            'logger' => $this->logger,
        ]);
        // TODO Add a generic identifier for any processor?
        $eventManager->setIdentifiers([get_class($this)]);
        $eventManager->trigger('bulk.import.after', $this, $args);

        if ($this->isErrorOrStop()) {
            return;
        }

        $this->completionLongJobs($ids);

        // TODO Reorder values according to template.
    }

    protected function completionShortJobs(array $resourceIds): void
    {
        if (!empty($this->modulesActive['Thesaurus'])) {
            $this->logger->notice('Reindexing thesaurus.'); // @translate
            foreach ($this->thesaurusConfigs as $config) {
                if (!empty($this->main[$config['main_name']]['item_id'])) {
                    $args = [
                        'scheme' => $this->main[$config['main_name']]['item_id'],
                    ];
                    $this->dispatchJob(\Thesaurus\Job\Indexing::class, $args);
                }
            }
            $this->logger->notice('Thesaurus reindexed.'); // @translate
        }
    }

    protected function completionLongJobs(array $resourceIds): void
    {
        // In all cases, index numeric timestamp with a single request.
        $this->logger->notice('Reindexing numeric data. It may take a while.'); // @translate
        $this->reindexNumericTimestamp($resourceIds);
        $this->logger->notice('Numeric data reindexed.'); // @translate

        // When data are created though sql, default events are not triggered,
        // so run an empty update for each resource type.

        // Use the same list for all resources types, because there may be
        // special resources to merge. The job will filter them at first.
        $ids = array_merge(
            $this->map['items'] ?? [],
            $this->map['media'] ?? [],
            $this->map['item_sets'] ?? [],
            $this->map['annotations'] ?? [],
            $this->map['concepts'] ?? [],
            $this->map['media_items'] ?? [],
            $this->map['media_items_sub'] ?? []
        );
        // Add other resources.
        foreach ($this->importables as $name => $importable) {
            if (!empty($importable['is_resource']) && !empty($this->map[$name])) {
                $ids = array_merge($ids, $this->map[$name]);
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        foreach (['items', 'media', 'item_sets', 'annotations'] as $resourceName) {
            if ($resourceName === 'annotations' && !$this->isModuleActive('Annotate')) {
                continue;
            }
            $this->logger->notice(
                'Reindexing "{resources}". It may take a while.', // @translate
                ['resources' => $resourceName]
            );
            $this->dispatchJob(\Omeka\Job\BatchUpdate::class, [
                'resource' => $resourceName,
                'query' => ['id' => $ids],
            ]);
        }

        // TODO Fix issue on value annotation indexing.
        $this->logger->notice('Reindexing full text search. It may take about some minutes to one hour.'); // @translate
        try {
            $this->dispatchJob(\Omeka\Job\IndexFulltextSearch::class);
        } catch (\Exception $e) {
        }
        $this->logger->notice('Full text search reindexed.'); // @translate

        // TODO Run derivative files job.
        if (isset($this->map['media']) && count($this->map['media'])) {
            $this->logger->warn('Derivative files should be recreated with module Bulk Check.'); // @translate
        }
    }

    protected function dispatchJob(string $jobClass, ?array $args = null, bool $asynchronous = false): void
    {
        /** @var \Omeka\Mvc\Controller\Plugin\JobDispatcher $dispatcher */
        $services = $this->getServiceLocator();
        $strategy = $asynchronous ? null : $services->get('Omeka\Job\DispatchStrategy\Synchronous');
        $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);
        $dispatcher->dispatch($jobClass, $args, $strategy);
    }

    protected function prepareReader(string $resourceName, bool $clone = false): self
    {
        // When no source is set, it means that the reader is disabled.
        // For example, disable processing of items and medias when mediaItems
        // is used.
        if (empty($this->mapping[$resourceName]['source'])) {
            $this->logger->debug(
                'No source set for {resource_name}.', // @translate
                ['resource_name' => $resourceName]
            );
            return new FakeReader($this->getServiceLocator());
        }
        $reader = $clone ? clone $this->reader : $this->reader;
        return $reader
            // TODO Fixme: AbstractPaginatedReader requires the query be set before object type (get first current page).
            ->setFilters($this->mapping[$resourceName]['filters'] ?? [])
            // TODO Fixme: AbstractPaginatedReader requires the order be set before object type (get first current page).
            ->setOrders(
                $this->mapping[$resourceName]['sort_by'] ?? $this->mapping[$resourceName]['key_id'],
                $this->mapping[$resourceName]['sort_dir'] ?? 'ASC'
            )
            ->setObjectType($this->mapping[$resourceName]['source']);
    }

    /**
     * Check if managed modules are available.
     */
    protected function checkAvailableModules(): void
    {
        /** @var \Omeka\Module\Manager $moduleManager */
        $moduleManager = $this->getServiceLocator()->get('Omeka\ModuleManager');

        $this->modulesActive = array_fill_keys(
            array_keys($moduleManager->getModulesByState(\Omeka\Module\Manager::STATE_ACTIVE)),
            true
        );

        foreach ([$this->modulesOptional, $this->modulesRequired] as $moduleNames) {
            foreach ($moduleNames as $moduleName) {
                $this->modules[$moduleName] = isset($this->modulesActive[$moduleName]);
            }
        }
    }

    /**
     * Check if a module is active.
     */
    protected function isModuleActive(string $moduleName): bool
    {
        /** @var \Omeka\Module\Manager $moduleManager */
        $moduleManager = $this->getServiceLocator()->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule($moduleName);
        return $module
            && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE;
    }

    /**
     * Static resources should be reloaded each time the entity manager is
     * cleared, so it is saved and reloaded.
     */
    protected function refreshMainResources(): void
    {
        $this->user = $this->entityManager->find(\Omeka\Entity\User::class, $this->userId);
        if ($this->ownerId) {
            $this->owner = $this->entityManager->find(\Omeka\Entity\User::class, $this->ownerId);
        }
        foreach ($this->main as $name => $data) {
            if (empty($data) || !is_array($data)) {
                continue;
            }
            if (!empty($data['template_id'])) {
                $this->main[$name]['template'] = $this->entityManager->find(\Omeka\Entity\ResourceTemplate::class, $data['template_id']);
            }
            if (!empty($data['class_id'])) {
                $this->main[$name]['class'] = $this->entityManager->find(\Omeka\Entity\ResourceClass::class, $data['class_id']);
            }
            if (!empty($data['item_set_id'])) {
                $this->main[$name]['item_set'] = $this->entityManager->find(\Omeka\Entity\ItemSet::class, $data['item_set_id']);
            }
            if (!empty($data['item_id'])) {
                $this->main[$name]['item'] = $this->entityManager->find(\Omeka\Entity\Item::class, $data['item_id']);
            }
            if (!empty($data['custom_vocab_id'])) {
                $this->main[$name]['custom_vocab'] = $this->entityManager->find(\Omeka\Entity\Item::class, $data['custom_vocab_id']);
            }
        }
        if (!empty($this->main['templates'])) {
            foreach (array_keys($this->main['templates']) as $name) {
                $this->main['templates'][$name] = $this->entityManager->getRepository(\Omeka\Entity\ResourceTemplate::class)->findOneBy(['label' => $name]);
            }
        }
        if (!empty($this->main['classes'])) {
            foreach (array_keys($this->main['classes']) as $name) {
                [$prefix, $localName] = explode(':', $name);
                $vocabulary = $this->entityManager->getRepository(\Omeka\Entity\Vocabulary::class)->findOneBy(['prefix' => $prefix]);
                $this->main['classes'][$name] = $this->entityManager->getRepository(\Omeka\Entity\ResourceClass::class)->findOneBy(['vocabulary' => $vocabulary, 'localName' => $localName]);
            }
        }
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
