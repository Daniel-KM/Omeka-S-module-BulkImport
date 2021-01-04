<?php declare(strict_types=1);

namespace BulkImport\Processor;

use ArrayObject;
use BulkImport\Form\Processor\OmekaSProcessorConfigForm;
use BulkImport\Form\Processor\OmekaSProcessorParamsForm;
use BulkImport\Interfaces\Parametrizable;
use BulkImport\Traits\ConfigurableTrait;
use BulkImport\Traits\ParametrizableTrait;
use Laminas\Form\Form;
use Omeka\Api\Representation\VocabularyRepresentation;

/**
 * @todo The processor is only parametrizable currently.
 */
class OmekaSProcessor extends AbstractProcessor implements Parametrizable
{
    use ConfigurableTrait;
    use CustomVocabTrait;
    use InternalIntegrityTrait;
    use MappingTrait;
    use ParametrizableTrait;
    use ResourceTrait;
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
        if (in_array('media', $toImport) && !in_array('items', $toImport)) {
            $this->hasError = true;
            $this->logger->err(
                'Resource "media" cannot be imported without items.' // @translate
            );
            return;
        }

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
            'Initialization of all assets.' // @translate
        );
        if (in_array('assets', $toImport)) {
            $this->prepareAssets();
            if ($this->hasError) {
                return;
            }
        }

        $this->logger->info(
            'Initialization of all resources.' // @translate
        );
        if (in_array('items', $toImport)) {
            $this->prepareItems();
            if ($this->hasError) {
                return;
            }
        }
        if (in_array('media', $toImport)) {
            $this->prepareMedias();
            if ($this->hasError) {
                return;
            }
        }
        if (in_array('item_sets', $toImport)) {
            $this->prepareItemSets();
            if ($this->hasError) {
                return;
            }
        }

        // Second loop.
        if (in_array('assets', $toImport)) {
            $this->logger->info(
                'Finalization of assets.' // @translate
            );
            $this->fillAssets();
        }
        $this->logger->info(
            'Preparation of metadata of all resources.' // @translate
        );
        if (in_array('items', $toImport)) {
            $this->fillItems();
            if ($this->hasError) {
                return;
            }
        }
        if (in_array('media', $toImport)) {
            $this->fillMedias();
            if ($this->hasError) {
                return;
            }
        }
        if (in_array('item_sets', $toImport)) {
            $this->fillItemSets();
            if ($this->hasError) {
                return;
            }
        }

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

    protected function prepareAssets(): void
    {
        $this->prepareAssetsProcess($this->reader->setObjectType('assets'));
    }

    protected function prepareItems(): void
    {
        $this->prepareResources($this->reader->setObjectType('items'), 'items');
    }

    protected function prepareMedias(): void
    {
        $this->prepareResources($this->reader->setObjectType('media'), 'media');
    }

    protected function prepareItemSets(): void
    {
        $this->prepareResources($this->reader->setObjectType('item_sets'), 'item_sets');
    }

    protected function fillAssets(): void
    {
        $this->fillAssetsProcess($this->reader->setObjectType('assets'));
    }

    protected function fillItems(): void
    {
        $this->fillResources($this->reader->setObjectType('items'), 'items');
    }

    protected function fillMedia(): void
    {
        $this->fillResources($this->reader->setObjectType('media'), 'media');
    }

    protected function fillItemSets(): void
    {
        $this->fillResources($this->reader->setObjectType('item_sets'), 'item_sets');
    }

    protected function fillMapping(): void
    {
        if (empty($this->modules['Mapping'])) {
            return;
        }

        $this->fillMappingProcess([
            'mappings' => $this->reader->setObjectType('mappings'),
            'mapping_markers' => $this->reader->setObjectType('mapping_markers'),
        ]);
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
