<?php declare(strict_types=1);

namespace BulkImport\Processor;

use ArrayObject;
use BulkImport\Interfaces\Parametrizable;
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
     * - dest:  table
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
     * - source:  table or path. If null, not importable.
     * - key_id: the id of the key in the source output.
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
        $result = array_intersect_key($values, $this->configDefault) + $this->configDefault;
        $args = new ArrayObject($result);
        $this->setConfig($args);
    }

    public function handleParamsForm(Form $form): void
    {
        $values = $form->getData();
        $result = array_intersect_key($values, $this->paramsDefault) + $this->paramsDefault;
        $args = new ArrayObject($result);
        $this->setParams($args);
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
            $this->logger->err(
                'The source is unavailable. Check params, rights or connection.' // @translate
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
        $args['types'][] = 'vocabularies';
        $args['types'][] = 'resource_templates';
        $args['types'][] = 'custom_vocabs';
        $args['types'] = array_unique($args['types']);
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
        if ($this->hasError) {
            return true;
        }
        if ($this->job->shouldStop()) {
            $this->logger->warn(
                'The job was stopped.' // @translate
            );
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
            $this->logger->err(
                'Resource "media" cannot be imported without items.' // @translate
            );
        }

        // Check database integrity for assets.
        $this->logger->info(
            'Check integrity of assets.' // @translate
        );
        $this->checkAssets();

        // Check database integrity for resources.
        $this->logger->info(
            'Check integrity of resources.' // @translate
        );
        $this->checkResources();
    }

    protected function importMetadata(): void
    {
        $toImport = $this->getParam('types') ?: [];

        // FIXME  Check for missing modules for datatypes (value suggest, custom vocab, numeric datatype, rdf datatype, geometry datatype).

        // Users are prepared first to include the owner anywhere.
        if (in_array('users', $toImport)
            && $this->prepareImport('users')
        ) {
            $this->logger->info(
                'Import of users.' // @translate
            );
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
            $this->logger->info(
                'Check vocabularies.' // @translate
            );
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
                $this->logger->info(
                    'Preparation of properties.' // @translate
                );
                $this->prepareProperties();
                if ($this->isErrorOrStop()) {
                    return;
                }
            }

            if ($this->prepareImport('resource_classes')) {
                $this->logger->info(
                    'Preparation of resource classes.' // @translate
                );
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
            $this->logger->info(
                'Check custom vocabs.' // @translate
            );
            $this->prepareCustomVocabsInitialize();
            if ($this->isErrorOrStop()) {
                return;
            }
        }

        if (in_array('resource_templates', $toImport)
            && $this->prepareImport('resource_templates')
        ) {
            $this->logger->info(
                'Preparation of resource templates.' // @translate
            );
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
            $this->logger->info(
                'Initialization of all assets.' // @translate
            );
            $this->prepareAssets();
            if ($this->isErrorOrStop()) {
                return;
            }
        }

        if (array_intersect(['items', 'media', 'media_items', 'item_sets'], $toImport)) {
            $this->logger->info(
                'Initialization of all resources.' // @translate
            );
            if (in_array('items', $toImport)
                && $this->prepareImport('items')
            ) {
                $this->prepareItems();
                if ($this->isErrorOrStop()) {
                    return;
                }
            }
            if (in_array('media', $toImport)
                && $this->prepareImport('medias')
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
            $this->logger->info(
                'Finalization of assets.' // @translate
            );
            $this->fillAssets();
        }
        if (array_intersect(['items', 'media', 'media_items', 'item_sets'], $toImport)) {
            $this->logger->info(
                'Preparation of metadata of all resources.' // @translate
            );
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

    protected function checkVocabularies(): void
    {
        if (empty($this->mapping['vocabularies']['source'])) {
            return;
        }

        // The clone is needed because the properties use the reader inside
        // the loop.
        $reader = clone $this->reader;
        foreach ($reader->setObjectType($this->objectType) as $vocabulary) {
            $result = $this->checkVocabulary($vocabulary);
            if ($result['status'] !== 'success') {
                $this->hasError = true;
            }
        }
    }

    protected function checkVocabularyProperties(array $vocabulary, VocabularyRepresentation $vocabularyRepresentation)
    {
        // TODO Add a filter to the reader.
        $vocabularyProperties = [];
        $sourceName = $this->mapping['properties']['source'];
        foreach ($this->reader->setObjectType($sourceName) as $property) {
            if ($property['o:vocabulary']['o:id'] === $vocabulary['o:id']) {
                $vocabularyProperties[] = $property['o:local_name'];
            }
        }
        return $vocabularyProperties;
    }

    protected function prepareVocabularies(): void
    {
        // The clone is needed because the properties use the reader inside
        // the loop.
        // TODO Avoid the second check of properties.
        $reader = clone $this->reader;
        $this->prepareVocabulariesProcess($reader->setObjectType($this->objectType));
    }

    protected function prepareProperties(): void
    {
        $sourceName = $this->mapping['properties']['source'];
        $this->prepareVocabularyMembers($this->reader->setObjectType($sourceName), 'properties');
    }

    protected function prepareResourceClasses(): void
    {
        $sourceName = $this->mapping['resource_classes']['source'];
        $this->prepareVocabularyMembers($this->reader->setObjectType($sourceName), 'resource_classes');
    }

    protected function prepareUsers(): void
    {
        $this->prepareUsersProcess($this->reader->setObjectType($this->objectType));
    }

    protected function prepareCustomVocabsInitialize(): void
    {
        $this->prepareCustomVocabsProcess($this->reader->setObjectType($this->objectType));
    }

    protected function prepareResourceTemplates(): void
    {
        $this->prepareResourceTemplatesProcess($this->reader->setObjectType($this->objectType));
    }

    protected function prepareAssets(): void
    {
        $this->prepareAssetsProcess($this->reader->setObjectType($this->objectType));
    }

    protected function prepareItems(): void
    {
        $this->prepareResources($this->reader->setObjectType($this->objectType), 'items');
    }

    protected function prepareMedias(): void
    {
        $this->prepareResources($this->reader->setObjectType($this->objectType), 'media');
    }

    protected function prepareMediaItems(): void
    {
        $this->prepareResources($this->reader->setObjectType($this->objectType), 'media_items');
    }

    protected function prepareItemSets(): void
    {
        $this->prepareResources($this->reader->setObjectType($this->objectType), 'item_sets');
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
                $this->prepareConcepts($this->reader->setObjectType($this->objectType));
            }
        }
    }

    protected function fillAssets(): void
    {
        $this->fillAssetsProcess($this->reader->setObjectType($this->objectType));
    }

    protected function fillItems(): void
    {
        $this->fillResources($this->reader->setObjectType($this->objectType), 'items');
    }

    protected function fillMedias(): void
    {
        $this->fillResources($this->reader->setObjectType($this->objectType), 'media');
    }

    protected function fillMediaItems(): void
    {
        $this->fillResources($this->reader->setObjectType($this->objectType), 'media_items');
    }

    protected function fillItemSets(): void
    {
        $this->fillResources($this->reader->setObjectType($this->objectType), 'item_sets');
    }

    protected function fillOthers(): void
    {
        if (!empty($this->modules['Thesaurus'])
            && in_array('concepts', $this->getParam('types') ?: [])
        ) {
            if ($this->prepareImport('concepts')) {
                $this->fillConcepts();
            }
        }

        if (!empty($this->modules['Mapping'])
            && in_array('mappings', $this->getParam('types') ?: [])
        ) {
            $this->logger->info(
                'Preparation of metadata of module Mapping.' // @translate
            );
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

        $itemSet = new \Omeka\Entity\ItemSet;
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
        $this->logger->info(
            'Running jobs for reindexation and finalization. Check next jobs in admin interface.' // @translate
        );

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
            $this->logger->warning(
                'Derivative files should be recreated with module Bulk Check.' // @translate
            );
        }
    }

    /**
     * Check if managed modules are available.
     */
    protected function checkAvailableModules(): void
    {
        /** @var \Omeka\Module\Manager $moduleManager */
        $moduleManager = $this->getServiceLocator()->get('Omeka\ModuleManager');
        foreach ([$this->optionalModules, $this->requiredModules] as $moduleClasses) {
            foreach ($moduleClasses as $moduleClass) {
                $module = $moduleManager->getModule($moduleClass);
                $this->modules[$moduleClass] = $module
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
