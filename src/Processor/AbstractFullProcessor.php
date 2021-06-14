<?php declare(strict_types=1);

namespace BulkImport\Processor;

use Box\Spout\Reader\Common\Creator\ReaderEntityFactory;
use BulkImport\Interfaces\Parametrizable;
use BulkImport\Reader\FakeReader;
use BulkImport\Traits\ConfigurableTrait;
use BulkImport\Traits\ParametrizableTrait;
use DateTime;
use Laminas\Form\Form;
use Omeka\Api\Representation\VocabularyRepresentation;
use Omeka\Entity\ItemSet;

/**
 * @todo The processor is only parametrizable currently.
 */
abstract class AbstractFullProcessor extends AbstractProcessor implements Parametrizable
{
    use AssetTrait;
    use ConfigurableTrait;
    use CountEntitiesTrait;
    use CustomVocabTrait;
    use FetchFileTrait;
    use InternalIntegrityTrait;
    use LanguageTrait;
    use MappingTrait;
    use ParametrizableTrait;
    use ResourceTemplateTrait;
    use ResourceTrait;
    use ThesaurusTrait;
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
     * @var \Omeka\File\Store\StoreInterface
     */
    protected $store;

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
     * @var string
     */
    protected $currentDateTimeFormatted;

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
     * @var array
     */
    protected $optionalModules = [
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
    protected $requiredModules = [];

    /**
     * List of importables Omeka resources for generic purposes.
     *
     * The default is derived from Omeka database, different from api endpoint.
     * A specific processor can override this property or use $moreImportables.
     *
     * The keys to use for automatic management are:
     * - name: api resource name
     * - class: the Omeka class for the entity manager
     * - dest: table
     * - key_id: name of the key to get the id of a record
     *
     * @var array
     */
    protected $importables = [
        'users' => [
            'name' => 'users',
            'class' => \Omeka\Entity\User::class,
            'table' => 'user',
        ],
        'assets' => [
            'name' => 'assets',
            'class' => \Omeka\Entity\Asset::class,
            'table' => 'asset',
        ],
        'items' => [
            'name' => 'items',
            'class' => \Omeka\Entity\Item::class,
            'table' => 'item',
            'fill' => 'fillItem',
        ],
        'media' => [
            'name' => 'media',
            'class' => \Omeka\Entity\Media::class,
            'table' => 'media',
            'fill' => 'fillMedia',
            'parent' => 'items',
        ],
        'item_sets' => [
            'name' => 'item_sets',
            'class' => \Omeka\Entity\ItemSet::class,
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
            'table' => 'item',
            'fill' => 'fillMediaItem',
            'sub' => 'media_items_sub',
        ],
        'media_items_sub' => [
            'name' => 'media',
            'class' => \Omeka\Entity\Media::class,
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
            'table' => 'item',
            'fill' => 'fillConcept',
            'is_resource' => true,
        ],
    ];

    /**
     * To be overridden by specific processor. Merged with importables.
     *
     * @var array
     */
    protected $moreImportables = [
    ];

    /**
     * Mapping of Omeka resources according to the reader.
     *
     * The default is derived from Omeka database, different from api endpoint.
     *
     * The keys to use for automatic management are:
     * - source: table or path. If null, not importable.
     * - key_id: the id of the key in the source output.
     * - sort_by: the name of the sort value (key_id by default).
     * - sort_dir: "asc" (default) or "desc".
     *
     * @var array
     */
    protected $mapping = [
        'users' => [
            'source' => 'user',
            'key_id' => 'id',
        ],
        'assets' => [
            'source' => 'asset',
            'key_id' => 'id',
        ],
        'items' => [
            'source' => 'item',
            'key_id' => 'id',
        ],
        'media' => [
            'source' => 'media',
            'key_id' => 'id',
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
     * The paths to the mapping files.
     *
     * List of relative paths to Omeka root or inside the folder data/imports of
     * the module.
     *
     * @var string[]
     */
    protected $mappingFiles = [
        'properties' => '',
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

    public function handleConfigForm(Form $form)
    {
        $values = $form->getData();
        $result = array_intersect_key($values, $this->configDefault) + $this->configDefault;
        $this->setConfig($result);
        return $this;
    }

    public function handleParamsForm(Form $form)
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

        $this->tempFileFactory = $services->get('Omeka\File\TempFileFactory');
        $this->store = $services->get('Omeka\File\Store');
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
        $this->currentDateTime = new \DateTime();
        $this->currentDateTimeFormatted = $this->currentDateTime->format('Y-m-d H:i:s');

        $settings = $services->get('Omeka\Settings');
        $this->disableFileValidation = (bool) $settings->get('disable_file_validation');
        $this->allowedMediaTypes = $settings->get('media_type_whitelist', []);
        $this->allowedExtensions = $settings->get('extension_whitelist', []);

        $this->srid = $settings->get('datatypegeometry_locate_srid', 4326);

        $this->checkAvailableModules();

        $requireds = array_intersect_key(array_filter($this->modules), array_flip($this->requiredModules));
        if (count($this->requiredModules) && count($requireds) !== count($this->requiredModules)) {
            $missings = array_diff($this->requiredModules, array_keys($requireds));
            $this->hasError = true;
            $this->logger->err(
                'The process requires the missing modules {modules}. The following modules are already enabled: {enabled}.', // @translate
                [
                    'modules' => '"' . implode('", "', $missings) . '"',
                    'enabled' => '"' . implode('", "', array_intersect($this->requiredModules, array_keys($requireds))) . '"',
                ]
            );
            return;
        }

        if (!$this->reader->isValid()) {
            $this->hasError = true;
            $this->logger->err('The source is unavailable. Check params, rights or connection.'); // @translate
            return;
        }

        if ($this->mappingFiles['properties'] && !$this->getTableFromFile($this->mappingFiles['properties'])) {
            $this->hasError = true;
            $this->logger->err(
                'Missing file "{filepath}" for the mapping of values.', // @translate
                ['filepath' => $this->mappingFiles['properties']]
            );
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

        $this->check();
        if ($this->isErrorOrStop()) {
            return;
        }

        $this->importMetadata();
        if ($this->isErrorOrStop()) {
            return;
        }

        // Simplify next steps
        $this->prepareCustomVocabCleanIds();

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
        $this->logger->notice('Totals of data existing and imported: {json}.', // @translate
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
        $this->logger->info('Check integrity of assets.' // @translate
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
            && !empty($this->modules['CustomVocab'])
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
        // time. This process is required for assets too in order to get the
        // mapping of ids for thumbnails.

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
                && $this->prepareImport('medias')
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
            $entity = $this->api()->searchOne('resource_templates', ['label' => $this->main[$name]['template']], ['initialize' => false, 'finalize' => false, 'responseContent' => 'resource'])->getContent();
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
            $entity = $this->api()->searchOne('resource_classes', ['term' => $this->main[$name]['class']], ['initialize' => false, 'finalize' => false, 'responseContent' => 'resource'])->getContent();
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
            $entity = $this->api()->searchOne('custom_vocabs', ['label' => $this->main[$name]['custom_vocab']], ['initialize' => false, 'finalize' => false, 'responseContent' => 'resource'])->getContent();
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
        return !empty($this->objectType);
    }

    /**
     * When no vocabulary is imported, the mapping should be filled for
     * properties and resource classes to simplify resource filling.
     *
     * @see \BulkImport\Processor\VocabularyTrait::prepareVocabularyMembers()
     */
    protected function prepareInternalVocabularies(): void
    {
        foreach ($this->getPropertyIds() as $term => $id) {
            $this->map['properties'][$term] = [
                'term' => $term,
                'source' => $id,
                'id' => $id,
            ];
        }
        $this->map['by_id']['properties'] = array_map('intval', array_column($this->map['properties'], 'id', 'source'));

        foreach ($this->getResourceClassIds() as $term => $id) {
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
        $this->map['resource_templates'] = $this->getResourceTemplateIds();
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

        if (!empty($this->modules['Thesaurus'])
            && in_array('concepts', $toImport)
            && $this->prepareImport('concepts')
        ) {
            $this->logger->info('Preparation of metadata of module Thesaurus.'); // @translate
            $this->prepareConcepts($this->prepareReader('concepts'));
        }
    }

    protected function fillAssets(): void
    {
        $this->fillAssetsProcess($this->prepareReader('assets'));
    }

    protected function fillItems(): void
    {
        $this->fillResources($this->prepareReader('items'), 'items');
    }

    protected function fillMedias(): void
    {
        $this->fillResources($this->prepareReader('media'), 'media');
    }

    protected function fillMediaItems(): void
    {
        $this->fillResources($this->prepareReader('media_items'), 'media_items');
    }

    protected function fillItemSets(): void
    {
        $this->fillResources($this->prepareReader('item_sets'), 'item_sets');
    }

    protected function fillOthers(): void
    {
        $toImport = $this->getParam('types') ?: [];

        if (!empty($this->modules['Thesaurus'])
            && in_array('concepts', $toImport)
            && $this->prepareImport('concepts')
        ) {
            $this->fillConcepts();
        }

        if (!empty($this->modules['Mapping'])
            && in_array('mappings', $toImport)
        ) {
            $this->logger->info('Preparation of metadata of module Mapping.'); // @translate
            // Not prepare: there are mappings and mapping markers.
            $this->fillMapping();
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
        $config = $this->getServiceLocator()->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $filepath = $basePath . '/bulk_import/' . 'import_' . $this->job->getJobId() . '.json';
        if (!is_dir(dirname($filepath))) {
            @mkdir(dirname($filepath, 0775, true));
        }
        file_put_contents($filepath, json_encode($this->map, 448));
        $this->logger->notice(
            'Mapping saved in "{url}".', // @translate
            // TODO Add domain to url.
            ['url' => '/files/' . mb_substr($filepath, strlen($basePath) + 1)]
        );

        $this->logger->info('Running jobs for reindexation and finalization. Check next jobs in admin interface.'); // @translate

        /** @var \Omeka\Mvc\Controller\Plugin\JobDispatcher $dispatcher */
        $services = $this->getServiceLocator();
        $synchronous = $services->get('Omeka\Job\DispatchStrategy\Synchronous');
        $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);

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
        if (count($ids)) {
            $dispatcher->dispatch(\BulkImport\Job\UpdateResourceTitles::class, ['resource_ids' => $ids], $synchronous);
        }
        $dispatcher->dispatch(\Omeka\Job\IndexFulltextSearch::class, [], $synchronous);
        if (!empty($this->modules['Thesaurus'])) {
            $dispatcher->dispatch(\Thesaurus\Job\Indexing::class, [], $synchronous);
        }

        // TODO Run derivative files job.
        if (count($this->map['media'])) {
            $this->logger->warning('Derivative files should be recreated with module Bulk Check.'); // @translate
        }
    }

    protected function prepareReader(string $resourceName, bool $clone = false): \BulkImport\Interfaces\Reader
    {
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
            ->setOrder(
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
        foreach ([$this->optionalModules, $this->requiredModules] as $moduleNames) {
            foreach ($moduleNames as $moduleName) {
                $module = $moduleManager->getModule($moduleName);
                $this->modules[$moduleName] = $module
                    && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE;
            }
        }
    }

    /**
     * Static resources should be reloaded each time the entity manager is
     * cleared, so it is saved and reloaded.
     */
    protected function refreshMainResources(): void
    {
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

    protected function getNormalizedMapping(): ?array
    {
        $table = $this->getTableFromFile($this->mappingFiles['properties']);
        if (!$table) {
            $this->hasError = true;
            $this->logger->err(
                'Missing file "{filepath}" for the mapping of values.', // @translate
                ['filepath' => $this->mappingFiles['properties']]
            );
            return null;
        }

        foreach ($table as $key => &$map) {
            // Mysql is case insensitive, but not php array.
            $map = array_change_key_case($map);
            $field = $map['source'] ?? null;
            $term = $map['destination'] ?? null;
            if (empty($field) || empty($term)) {
                unset($table[$key]);
            } else {
                $termId = $this->bulk->getPropertyId($term);
                if ($termId) {
                    $map['property_id'] = $termId;
                } else {
                    unset($table[$key]);
                }
            }
        }
        unset($map);

        return $table;
    }

    /**
     * Copy the mapping of source ids and resource ids into a temp csv file.
     *
     * The csv is a tab-separated values.
     */
    protected function saveKeyPairToTsv(string $resourceName, bool $skipEmpty = false): string
    {
        $resources = $skipEmpty
            ? array_filter($this->map[$resourceName])
            : $this->map[$resourceName];

        $content = '';
        array_walk($resources, function (&$v, $k) use ($content): void {
            $content .= "$k\t$v\n";
        });

        // TODO Use omeka temp directory (but check if mysql has access to it).
        $filepath = tempnam(sys_get_temp_dir(), 'omk_bki_');
        touch($filepath . '.csv');
        @unlink($filepath);
        $filepath .= '.csv';

        $result = file_put_contents($filepath, $content);
        if ($result === false) {
            $this->hasError = true;
            $this->logger->warn(
                'Unable to put content in a temp file.' // @translate
            );
        }

        return $filepath;
    }

    /**
     * Get a two columns table from a file (php, ods, tsv or csv).
     */
    protected function loadKeyPairFromFile(?string $filename): ?array
    {
        return $this->loadTableFromFile($filename, true);
    }

    /**
     * Get a table from a file (php, ods, tsv or csv).
     */
    protected function getTableFromFile(?string $filename): ?array
    {
        return $this->loadTableFromFile($filename, false);
    }

    /**
     * Get a table from a file (php, ods, tsv or csv).
     */
    protected function loadTableFromFile(?string $filename, bool $keyPair = false): ?array
    {
        $filename = trim((string) $filename, '/\\ ');
        if (empty($filename)) {
            return null;
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $baseFilename = mb_strlen($extension) ? mb_substr($filename, 0, mb_strlen($filename) - mb_strlen($extension) - 1) : $filename;
        $extensions = [
            'php',
            'ods',
            'tsv',
            'csv',
        ];
        $filepath = null;
        foreach ($extensions as $extension) {
            $file = "$baseFilename.$extension";
            if (file_exists(OMEKA_PATH . '/' . $file)) {
                $filepath = OMEKA_PATH . '/' . $file;
                break;
            } elseif (file_exists(dirname(__DIR__, 2) . '/data/imports/' . $file)) {
                $filepath = dirname(__DIR__, 2) . '/data/imports/' . $file;
                break;
            }
        }
        if (empty($filepath)) {
            return null;
        }

        if ($extension === 'php') {
            $mapper = include $filepath;
        } elseif ($extension === 'ods') {
            $mapper = $this->odsToArray($filepath, $keyPair);
        } elseif ($extension === 'tsv') {
            $mapper = $this->tsvToArray($filepath, $keyPair);
        } elseif ($extension === 'csv') {
            $mapper = $this->csvToArray($filepath, $keyPair);
        } else {
            $this->hasError = true;
            $this->logger->err(
                'Unmanaged extension for file "{filepath}".', // @translate
                ['filepath' => $filename]
            );
            return null;
        }

        if (empty($mapper)) {
            $this->hasError = true;
            $this->logger->err(
                'Empty file "{filepath}".', // @translate
                ['filepath' => $filename]
            );
            return null;
        }

        // No cleaning for key pair.
        if ($keyPair) {
            return $mapper;
        }

        // Trim all values of all rows.
        foreach ($mapper as $key => &$map) {
            // Fix trailing rows.
            if (!is_array($map)) {
                unset($mapper[$key]);
                continue;
            }
            // The values are already strings, except for php.
            if ($extension === 'php') {
                $map = array_map('trim', array_map('strval', $map));
            }
            if (!array_filter($map, 'strlen')) {
                unset($mapper[$key]);
            }
        }
        unset($map);

        return $mapper;
    }

    /**
     * Quick import a small ods config file into an array with headers as keys.
     *
     * Empty or partial rows are managed.
     *
     * @see \BulkImport\Reader\OpenDocumentSpreadsheetReader::initializeReader()
     */
    protected function odsToArray(string $filepath, bool $keyPair = false): ?array
    {
        if (!file_exists($filepath) || !is_readable($filepath) || !filesize($filepath)) {
            return null;
        }

        // TODO Remove when patch https://github.com/omeka-s-modules/CSVImport/pull/182 will be included.
        // Manage compatibility with old version of CSV Import.
        // For now, it should be first checked.
        if (class_exists(\Box\Spout\Reader\ReaderFactory::class)) {
            $spreadsheetReader = \Box\Spout\Reader\ReaderFactory::create(\Box\Spout\Common\Type::ODS);
        } elseif (class_exists(ReaderEntityFactory::class)) {
            /** @var \Box\Spout\Reader\ODS\Reader $spreadsheetReader */
            $spreadsheetReader = ReaderEntityFactory::createODSReader();
        } else {
            $this->hasError = true;
            $this->logger->err(
                'The library to manage OpenDocument spreadsheet is not available.' // @translate
            );
            return null;
        }

        try {
            $spreadsheetReader->open($filepath);
        } catch (\Box\Spout\Common\Exception\IOException $e) {
            $this->hasError = true;
            $this->logger->err(
                'File "{filename}" cannot be open.', // @translate
                ['filename' => $filepath]
            );
            return null;
        }

        $spreadsheetReader
            // ->setTempFolder($this->config['temp_dir'])
            // Read the dates as text. See fix #179 in CSVImport.
            // TODO Read the good format in spreadsheet entry.
            ->setShouldFormatDates(true);

        // Process first sheet only.
        foreach ($spreadsheetReader->getSheetIterator() as $sheet) {
            $iterator = $sheet->getRowIterator();
            break;
        }

        if (empty($iterator)) {
            return null;
        }

        $data = [];

        if ($keyPair) {
            foreach ($iterator as $row) {
                $cells = $row->getCells();
                // Simplify management of empty or partial rows.
                $cells[] = '';
                $cells[] = '';
                $cells = array_slice($cells, 0, 2);
                $data[trim((string) $cells[0])] = $data[trim((string) $cells[1])];
            }
            $spreadsheetReader->close();
            return $data;
        }

        $first = true;
        $headers = [];
        foreach ($iterator as $row) {
            $cells = $row->getCells();
            $cells = array_map('trim', $cells);
            if ($first) {
                $first = false;
                $headers = $cells;
                $countHeaders = count($headers);
                $emptyRow = array_fill(0, $countHeaders, '');
            } else {
                $data[] = array_combine(
                    $headers,
                    array_slice(array_map('trim', array_map('strval', $cells)) + $emptyRow, 0, $countHeaders)
                );
            }
        }

        $spreadsheetReader->close();
        return $data;
    }

    /**
     * Quick import a small tsv config file into an array with headers as keys.
     *
     * Empty or partial rows are managed.
     */
    protected function tsvToArray(string $filepath, bool $keyPair = false): ?array
    {
        return $this->tcsvToArray($filepath, $keyPair, "\t", '"', '\\');
    }

    /**
     * Quick import a small csv config file into an array with headers as keys.
     *
     * Empty or partial rows are managed.
     */
    protected function csvToArray(string $filepath, bool $keyPair = false): ?array
    {
        return $this->tcsvToArray($filepath, $keyPair, ",", '"', '\\');
    }

    /**
     * Quick import a small tsv/csv config file into an array with headers as keys.
     *
     * Empty or partial rows are managed.
     */
    protected function tcsvToArray(string $filepath, bool $keyPair = false, string $delimiter = null, string $enclosure = null, string $escape = null): ?array
    {
        if (!file_exists($filepath) || !is_readable($filepath) || !filesize($filepath)) {
            return null;
        }

        $content = file_get_contents($filepath);
        $rows = explode("\n", $content);

        $data = [];

        if ($keyPair) {
            foreach ($rows as $row) {
                $cells = str_getcsv($row, $delimiter, $enclosure, $escape);
                $cells[] = '';
                $cells[] = '';
                $cells = array_slice($cells, 0, 2);
                $data[trim((string) $cells[0])] = $data[trim((string) $cells[1])];
            }
            return $data;
        }

        $headers = array_map('trim', str_getcsv(array_shift($rows), $delimiter, $enclosure, $escape));
        $countHeaders = count($headers);
        $emptyRow = array_fill(0, $countHeaders, '');
        foreach ($rows as $row) {
            $data[] = array_combine(
                $headers,
                array_slice(array_map('trim', str_getcsv($row, $delimiter, $enclosure, $escape)) + $emptyRow, 0, $countHeaders)
            );
        }
        return $data;
    }

    /**
     * Strangely, the "timestamp" may have date time data.
     *
     * Furthermore, a check is done because mysql allows only 1000-9999, but
     * there may be bad dates.
     *
     * @link https://dev.mysql.com/doc/refman/8.0/en/datetime.html
     *
     * @param string $date
     * @return \DateTime
     */
    protected function getSqlDateTime($date): ?DateTime
    {
        if (empty($date)) {
            return null;
        }

        try {
            $date = (string) $date;
        } catch (\Exception $e) {
            return null;
        }

        if (in_array(substr($date, 0, 10), [
            '0000-00-00',
            '2038-01-01',
        ])) {
            return null;
        }

        if (substr($date, 0, 10) === '1970-01-01'
            && substr($date, 13, 6) === ':00:00'
        ) {
            return null;
        }

        try {
            $dateTime = strpos($date, ':', 1) || strpos($date, '-', 1)
                ? new \DateTime(substr(str_replace('T', ' ', $date), 0, 19))
                : new \DateTime(date('Y-m-d H:i:s', $date));
        } catch (\Exception $e) {
            return null;
        }

        $formatted = $dateTime->format('Y-m-d H:i:s');
        return $formatted < '1000-01-01 00:00:00' || $formatted > '9999-12-31 23:59:59'
            ? null
            : $dateTime;
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
