<?php
namespace BulkImport\Processor;

use ArrayObject;
use BulkImport\Form\Processor\OmekaSProcessorConfigForm;
use BulkImport\Form\Processor\OmekaSProcessorParamsForm;
use BulkImport\Interfaces\Parametrizable;
use BulkImport\Traits\ParametrizableTrait;
use finfo;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Api\Representation\VocabularyRepresentation;
use Zend\Form\Form;
use Log\Stdlib\PsrMessage;

/**
 * @todo The processor is only parametrizable currently.
 */
class OmekaSProcessor extends AbstractProcessor implements Parametrizable
{
    use ParametrizableTrait;

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
     * @var int|string
     */
    protected $ownerIdOrNull;

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

    public function handleConfigForm(Form $form)
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

    public function handleParamsForm(Form $form)
    {
        $values = $form->getData();
        $params = new ArrayObject;
        $this->handleFormGeneric($params, $values);
        $defaults = [
            'o:owner' => null,
        ];
        $params = array_intersect_key($params->getArrayCopy(), $defaults);
        $this->setParams($params);
    }

    protected function handleFormGeneric(ArrayObject $args, array $values)
    {
        $defaults = [
            'endpoint' => null,
            'key_identity' => null,
            'key_credential' => null,
            'o:owner' => null,
        ];
        $result = array_intersect_key($values, $defaults) + $args->getArrayCopy() + $defaults;
        // TODO Manage check of duplicate identifiers during dry-run.
        // $result['allow_duplicate_identifiers'] = (bool) $result['allow_duplicate_identifiers'];
        $args->exchangeArray($result);
    }

    public function process()
    {
        // TODO Add a dry-run.
        // TODO Add an option to use api or not.
        // TODO Add an option to stop/continue on error.

        $services = $this->getServiceLocator();
        $this->entityManager = $services->get('Omeka\EntityManager');
        $this->connection = $services->get('Omeka\Connection');

        $this->adapterManager = $services->get('Omeka\ApiAdapterManager');
        $this->tempFileFactory = $services->get('Omeka\File\TempFileFactory');
        $this->allowedDataTypes = $services->get('Omeka\DataTypeManager')->getRegisteredNames();

        // The owner should be reloaded each time the entity manager is flushed.
        $ownerId = $this->getParam('o:owner', 'current') ?: 'current';
        if ($ownerId === 'current') {
            $identity = $services->get('ControllerPluginManager')->get('identity');
            $this->owner = $identity();
        } elseif ($ownerId) {
            $this->owner = $this->entityManager->find(\Omeka\Entity\User::class, $ownerId);
        }
        $this->ownerIdOrNull = $this->owner ? $this->owner->getId() : 'NULL';

        $config = $services->get('Config');
        $this->basePath = $config['file_store']['local']['base_path'] ?: OMEKA_PATH . '/files';
        $this->tempPath = $config['temp_dir'] ?: sys_get_temp_dir();
        $this->defaultDate = (new \DateTime())->format('Y-m-d H:i:s');

        $settings = $services->get('Omeka\Settings');
        $this->disableFileValidation = (bool) $settings->get('disable_file_validation');
        $this->allowedMediaTypes = $settings->get('media_type_whitelist', []);
        $this->allowedExtensions = $settings->get('extension_whitelist', []);

        $this->checkAvailableModules();

        // Check database integrity for assets.
        $this->logger->info(
            'Check integrity of assets.' // @translate
        );
        $this->checkResources();
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

        // TODO Refresh the bulk lists for properties, resource classes and templates.

        $this->logger->info(
            'Check custom vocabs.' // @translate
        );
        $this->prepareCustomVocabs();
        if ($this->hasError) {
            return;
        }

        $this->logger->info(
            'Preparation of resource templates.' // @translate
        );
        $this->prepareResourceTemplates();
        if ($this->hasError) {
            return;
        }

        // The process uses two steps: creation of all resources empty, then
        // fill all resources. This process is required to manage relations
        // between resources, while any other user can create new resources at
        // the same time. This process is required for assets too in order to
        // get the mapping of ids for thumbnails.

        // First loop: create one resource by resource.
        $this->logger->info(
            'Initialization of all resources and assets.' // @translate
        );
        $this->initializeEntities();
        if ($this->hasError) {
            return;
        }

        // Second loop.
        $this->logger->info(
            'Finalization of assets.' // @translate
        );
        $this->fillAssets();

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

        $this->logger->notice(
            'End of process: {total_resources} resources to process, {total_skipped} skipped or blank, {total_processed} processed, {total_errors} errors inside data. Note: errors can occur separately for each file.', // @translate
            [
                'total_resources' => $this->totalIndexResources,
                'total_skipped' => $this->totalSkipped,
                'total_processed' => $this->totalProcessed,
                'total_errors' => $this->totalErrors,
            ]
        );
    }

    protected function checkAssets()
    {
        // Check if there are empty data, for example from an incomplete import.
        $sql = <<<SQL
SELECT id
FROM asset
WHERE asset.name = ""
    AND asset.media_type = ""
    AND asset.extension = ""
    AND asset.owner_id IS NULL;
SQL;
        $result = $this->connection->query($sql)->fetchAll(\PDO::FETCH_COLUMN);
        if (count($result)) {
            $this->hasError = true;
            $this->logger->err(
                '{total} empty {type} are present in the database. Here are the first ones: {first}. Use module BulkCheck to check and fix them.', // @translate
                ['total' => count($result), 'type' => 'assets', 'first' => implode(', ', array_slice($result, 0, 10))]
            );
        }
    }

    protected function checkResources()
    {
        $resourceTables = [
            'item' => \Omeka\Entity\Item::class,
            'item_set' => \Omeka\Entity\ItemSet::class,
            'media' => \Omeka\Entity\Media::class,
        ];

        // Check if there are missing specific id.
        foreach ($resourceTables as $resourceTable => $class) {
            $resourceClass = $this->connection->quote($class);
            $sql = <<<SQL
SELECT resource.id
FROM resource AS resource
LEFT JOIN $resourceTable AS spec ON spec.id = resource.id
WHERE resource.resource_type = $resourceClass
    AND spec.id IS NULL;
SQL;
            $result = $this->connection->query($sql)->fetchAll(\PDO::FETCH_COLUMN);
            if (count($result)) {
                $this->hasError = true;
                $this->logger->err(
                    '{total} resources are present in the table "resource", but missing in the table "{type}" of the database. Here are the first ones: {first}. Use module BulkCheck to check and fix them.', // @translate
                    ['total' => count($result), 'type' => $resourceTable, 'first' => implode(', ', array_slice($result, 0, 10))]
                );
            }
        }
    }

    protected function checkVocabularies()
    {
        foreach ($this->reader->setObjectType('vocabularies') as $vocabulary) {
            $result = $this->checkVocabulary($vocabulary);
            if ($result['status'] !== 'success') {
                $this->hasError = true;
            }
        }
    }

    /**
     * Check if a vocabulary exists and check if different.
     *
     * @todo Remove arg $skipLog.
     *
     * @param array $vocabulary
     * @param bool $skipLog
     * @return array The status and the cleaned vocabulary.
     */
    protected function checkVocabulary(array $vocabulary, $skipLog = false)
    {
        try {
            /** @var \Omeka\Api\Representation\VocabularyRepresentation $vocabularyRepresentation */
            $vocabularyRepresentation = $this->api()
                // Api "search" uses "namespace_uri", but "read" uses "namespaceUri".
                ->read('vocabularies', ['namespaceUri' => $vocabulary['o:namespace_uri']])->getContent();
            if ($vocabularyRepresentation->prefix() !== $vocabulary['o:prefix']) {
                $vocabulary['o:prefix'] = $vocabularyRepresentation->prefix();
                if (!$skipLog) {
                    $this->logger->notice(
                        'Vocabulary {prefix} exists as vocabulary #{vocabulary_id}, but the prefix is not the same.', // @translate
                        ['prefix' => $vocabulary['o:prefix'], 'vocabulary_id' => $vocabularyRepresentation->id()]
                    );
                }
            }
            return [
                'status' => 'success',
                'data' => [
                    'source' => $vocabulary,
                    'destination' => $vocabularyRepresentation,
                ],
            ];
        } catch (NotFoundException $e) {
        }

        try {
            /** @var \Omeka\Api\Representation\VocabularyRepresentation $vocabularyRepresentation */
            $vocabularyRepresentation = $this->api()
                ->read('vocabularies', ['prefix' => $vocabulary['o:prefix']])->getContent();
            if (rtrim($vocabularyRepresentation->namespaceUri(), '#/') !== rtrim($vocabulary['o:namespace_uri'], '#/')) {
                $vocabulary['o:prefix'] .= '_' . (new \DateTime())->format('YmdHis');
                if (!$skipLog) {
                    $this->logger->notice(
                        'Vocabulary prefix {prefix} is used so the imported one is renamed.', // @translate
                        ['prefix' => $vocabulary['o:prefix']]
                    );
                }
            }
        } catch (NotFoundException $e) {
        }

        return [
            'status' => 'success',
            'data' => [
                'source' => $vocabulary,
                'destination' => null,
            ],
        ];
    }

    protected function checkProperties(array $vocabulary, VocabularyRepresentation $vocabularyRepresentation)
    {
        $vocabularyProperties = [];

        $this->reader->setObjectType('properties');
        // TODO Add a filter to the reader.
        foreach ($this->reader as $property) {
            if ($property['o:vocabulary']['o:id'] === $vocabulary['o:id']) {
                $vocabularyProperties[] = $property['o:local_name'];
            }
        }
        sort($vocabularyProperties);

        $vocabularyRepresentationProperties = [];
        foreach ($vocabularyRepresentation->properties() as $property) {
            $vocabularyRepresentationProperties[] = $property->localName();
        }
        sort($vocabularyRepresentationProperties);

        if ($vocabularyProperties !== $vocabularyRepresentationProperties) {
            $this->logger->notice(
                'The properties are different for the {prefix}.', // @translate
                ['prefix' => $vocabulary['o:prefix']]
            );
        }

        // Whatever the result, the result is always true.
        return true;
    }

    /**
     * The vocabularies should be checked before.
     */
    protected function prepareVocabularies()
    {
        $index = 0;
        $created = 0;
        foreach ($this->reader->setObjectType('vocabularies') as $vocabulary) {
            ++$index;
            $result = $this->checkVocabulary($vocabulary, false);
            if ($result['status'] !== 'success') {
                $this->hasError = true;
                return;
            }

            if (!$result['data']['destination']) {
                $vocab = $result['data']['source'];
                unset($vocab['@id'], $vocab['o:id']);
                $vocab['o:owner'] = $this->owner ? ['o:id' => $this->owner->getId()] : null;
                // TODO Use orm.
                $response = $this->api()->create('vocabularies', $vocab);
                $result['data']['destination'] = $response->getContent();
                $this->logger->notice(
                    'Vocabulary {prefix} has been created.', // @translate
                    ['prefix' => $vocab['o:prefix']]
                );
                ++$created;
            }

            // The prefix may have been changed. Keep only needed data.
            $this->map['vocabularies'][$vocabulary['o:prefix']] = [
                'source' => [
                    'id' => $vocabulary['o:id'],
                    'prefix' => $vocabulary['o:prefix'],
                ],
                'destination' => [
                    'id' => $result['data']['destination']->id(),
                    'prefix' => $result['data']['destination']->prefix(),
                ],
            ];
        }

        $this->logger->notice(
            '{total} vocabulary ready, {created} created.', // @translate
            ['total' => $index, 'created' => $created]
        );
    }

    protected function prepareProperties()
    {
        $properties = $this->getPropertyIds();

        $index = 0;
        $created = 0;
        $skipped = 0;
        foreach ($this->reader->setObjectType('properties') as $property) {
            ++$index;
            $sourceId = $property['o:id'];
            $sourceTerm = $property['o:term'];
            $sourcePrefix = strtok($sourceTerm, ':');
            if (!isset($this->map['vocabularies'][$sourcePrefix])) {
                ++$skipped;
                $this->logger->warn(
                    'The vocabulary of the property {term} does not exist.', // @translate
                    ['term' => $sourceTerm]
                );
                continue;
            }

            $destTerm = $this->map['vocabularies'][$sourcePrefix]['destination']['prefix'] . ':' . $property['o:local_name'];
            if (isset($properties[$destTerm])) {
                $destTermId = $properties[$destTerm];
            } else {
                $property['o:vocabulary'] = $this->map['vocabularies'][$sourcePrefix]['destination'];
                $property['o:term'] = $destTerm;
                unset($property['@id'], $property['o:id']);
                $property['o:owner'] = $this->owner ? ['o:id' => $this->owner->getId()] : null;
                // TODO Use orm.
                $response = $this->api()->create('properties', $property);
                $this->logger->notice(
                    'Property {term} has been created.', // @translate
                    ['term' => $property['o:term']]
                );
                $destTermId = $response->getContent()->id();
                ++$created;
            }

            $this->map['properties'][$sourceTerm] = [
                'source' => $sourceId,
                'id' => $destTermId,
                'term' => $destTerm,
            ];
        }

        $this->logger->notice(
            '{total} properties ready, {created} created, {skipped} skipped.', // @translate
            ['total' => $index, 'created' => $created, 'skipped' => $skipped]
        );
    }

    protected function prepareResourceClasses()
    {
        $resourceClasses = $this->getResourceClassIds();

        $index = 0;
        $created = 0;
        $skipped = 0;
        foreach ($this->reader->setObjectType('resource_classes') as $resourceClass) {
            ++$index;
            $sourceId = $resourceClass['o:id'];
            $sourceTerm = $resourceClass['o:term'];
            $sourcePrefix = strtok($sourceTerm, ':');
            if (!isset($this->map['vocabularies'][$sourcePrefix])) {
                ++$skipped;
                $this->logger->warn(
                    'The vocabulary of the resource class {term} does not exist.', // @translate
                    ['term' => $sourceTerm]
                );
                continue;
            }

            $destTerm = $this->map['vocabularies'][$sourcePrefix]['destination']['prefix'] . ':' . $resourceClass['o:local_name'];
            if (isset($resourceClasses[$destTerm])) {
                $destTermId = $resourceClasses[$destTerm];
            } else {
                $resourceClass['o:vocabulary'] = $this->map['vocabularies'][$sourcePrefix]['destination'];
                $resourceClass['o:term'] = $destTerm;
                unset($resourceClass['@id'], $resourceClass['o:id']);
                $resourceClass['o:owner'] = $this->owner ? ['o:id' => $this->owner->getId()] : null;
                // TODO Use orm.
                $response = $this->api()->create('resource_classes', $resourceClass);
                $this->logger->notice(
                    'Resource class {term} has been created.', // @translate
                    ['term' => $resourceClass['o:term']]
                );
                $destTermId = $response->getContent()->id();
                ++$created;
            }

            $this->map['resource_classes'][$sourceTerm] = [
                'source' => $sourceId,
                'id' => $destTermId,
                'term' => $destTerm,
            ];
        }

        $this->logger->notice(
            '{total} resource classes ready, {created} created, {skipped} skipped.', // @translate
            ['total' => $index, 'created' => $created, 'skipped' => $skipped]
        );
    }

    protected function prepareCustomVocabs()
    {
        $this->map['custom_vocabs'] = [];

        if (empty($this->modules['CustomVocab'])) {
            return;
        }

        $result = $this->api()
            ->search('custom_vocabs', [], ['responseContent' => 'resource'])->getContent();

        $customVocabs = [];
        foreach ($result as $customVocab) {
            $customVocabs[$customVocab->getLabel()] = $customVocab;
        }
        unset($result);

        $index = 0;
        $created = 0;
        $skipped = 0;
        foreach ($this->reader->setObjectType('custom_vocabs') as $customVocab) {
            ++$index;
            if (isset($customVocabs[$customVocab['o:label']])) {
                /*
                // Item sets are not yet imported, so no mapping for item sets for now…
                // TODO Currently, item sets are created after, so vocab is updated later, but resource can be created empty first?
                if (!empty($customVocab['o:item_set']) && $customVocab['o:item_set'] === $customVocabs[$customVocab['o:label']]->getItemSet()) {
                    // ++$skipped;
                    // $this->map['custom_vocabs']['custom:' . $customVocab['o:id']]['datatype'] = 'custom:' . $customVocabs[$customVocab['o:label']]->getId();
                    // continue;
                */
                if (empty($customVocab['o:item_set']) && !empty($customVocab['o:terms']) && $customVocab['o:terms'] === $customVocabs[$customVocab['o:label']]->getTerms()) {
                    ++$skipped;
                    $this->map['custom_vocabs']['custom:' . $customVocab['o:id']]['datatype'] = 'custom:' . $customVocabs[$customVocab['o:label']]->getId();
                    continue;
                } else {
                    $label = $customVocab['o:label'];
                    $customVocab['o:label'] .= ' ' . (new \DateTime())->format('Ymd-His')
                        . ' ' . substr(bin2hex(\Zend\Math\Rand::getBytes(20)), 0, 5);
                    $this->logger->notice(
                        'Custom vocab "{old_label}" has been renamed to "{label}".', // @translate
                        ['old_label' => $label, 'label' => $customVocab['o:label']]
                    );
                }
            }

            $sourceId = $customVocab['o:id'];
            $sourceItemSet = empty($customVocab['o:item_set']) ? null : $customVocab['o:item_set'];
            $customVocab['o:item_set'] = null;
            $customVocab['o:terms'] = !strlen(trim($customVocab['o:terms'])) ? null : $customVocab['o:terms'];

            // Some custom vocabs from old versions can be empty.
            // They are created with a false term and updated later.
            $isEmpty = is_null($customVocab['o:item_set']) && is_null($customVocab['o:terms']);
            if ($isEmpty) {
                $customVocab['o:terms'] = 'Added by Bulk Import. To be removed.';
            }

            unset($customVocab['@id'], $customVocab['o:id']);
            $customVocab['o:owner'] = $this->owner ? ['o:id' => $this->owner->getId()] : null;
            // TODO Use orm.
            $response = $this->api()->create('custom_vocabs', $customVocab);
            if (!$response) {
                $this->hasError = true;
                $this->logger->err(
                    'Unable to create custom vocab "{label}".', // @translate
                    ['label' => $customVocab['o:label']]
                );
                return;
            }
            $this->logger->notice(
                'Custom vocab {label} has been created.', // @translate
                ['label' => $customVocab['o:label']]
            );
            ++$created;

            $this->map['custom_vocabs']['custom:' . $sourceId] = [
                'datatype' => 'custom:' . $response->getContent()->id(),
                'source_item_set' => $sourceItemSet,
                'is_empty' => $isEmpty,
            ];
        }

        $this->allowedDataTypes = $this->getServiceLocator()->get('Omeka\DataTypeManager')->getRegisteredNames();

        $this->logger->notice(
            '{total} custom vocabs ready, {created} created, {skipped} skipped.', // @translate
            ['total' => $index, 'created' => $created, 'skipped' => $skipped]
        );
    }

    protected function prepareCustomVocabsFinalize()
    {
        if (empty($this->modules['CustomVocab'])) {
            return;
        }

        $api = $this->api();
        foreach ($this->map['custom_vocabs'] as &$customVocab) {
            if (empty($customVocab['source_item_set'])) {
                unset($customVocab['is_empty']);
                continue;
            }
            if (empty($this->map['item_sets'][$customVocab['source_item_set']])) {
                unset($customVocab['is_empty']);
                continue;
            }
            $id = (int) substr($customVocab['datatype'], 12);
            /** @var \CustomVocab\Api\Representation\CustomVocabRepresentation $customVocab */
            $customVocabRepr = $api->searchOne('custom_vocabs', $id)->getContent();
            if (!$customVocabRepr) {
                unset($customVocab['is_empty']);
                continue;
            }
            $data = json_decode(json_encode($customVocabRepr), true);
            $data['o:item_set'] = $this->map['item_sets'][$customVocab['source_item_set']];
            if (!empty($customVocab['is_empty'])) {
                $data['o:terms'] = null;
            }
            unset($customVocab['is_empty']);
            $api->update('custom_vocabs', $id, $data);
        }
    }

    protected function prepareResourceTemplates()
    {
        $resourceTemplates = $this->getResourceTemplateIds();

        $result = $this->api()
            ->search('resource_templates')->getContent();
        $rts = [];
        foreach ($result as $resourceTemplate) {
            $rts[$resourceTemplate->label()] = $resourceTemplate;
        }
        unset($result);

        $index = 0;
        $created = 0;
        $skipped = 0;
        foreach ($this->reader->setObjectType('resource_templates') as $resourceTemplate) {
            ++$index;
            if (isset($resourceTemplates[$resourceTemplate['o:label']])) {
                if ($this->equalResourceTemplates($rts[$resourceTemplate['o:label']], $resourceTemplate)) {
                    ++$skipped;
                    $this->map['resource_templates'][$resourceTemplate['o:id']] = $rts[$resourceTemplate['o:label']]->id();
                    continue;
                }

                $sourceLabel = $resourceTemplate['o:label'];
                $resourceTemplate['o:label'] .= ' ' . (new \DateTime())->format('Ymd-His')
                    . ' ' . substr(bin2hex(\Zend\Math\Rand::getBytes(20)), 0, 5);
                $this->logger->notice(
                    'Resource template "{old_label}" has been renamed to "{label}".', // @translate
                    ['old_label' => $sourceLabel, 'label' => $resourceTemplate['o:label']]
                );
            }

            $resourceTemplateId = $resourceTemplate['o:id'];
            unset($resourceTemplate['@id'], $resourceTemplate['o:id']);
            $resourceTemplate['o:owner'] = $this->owner ? ['o:id' => $this->owner->getId()] : null;
            $resourceTemplate['o:resource_class'] = !empty($resourceTemplate['o:resource_class'])
                && !empty($this->map['resource_classes'][$resourceTemplate['o:resource_class']['o:id']]['id'])
                ? ['o:id' => $this->map['resource_classes'][$resourceTemplate['o:resource_class']['o:id']]['id']]
                : null;
            $resourceTemplate['o:title_property'] = !empty($resourceTemplate['o:title_property'])
                && !empty($this->map['properties'][$resourceTemplate['o:title_property']['o:id']]['id'])
                ? ['o:id' => $this->map['properties'][$resourceTemplate['o:title_property']['o:id']]['id']]
                : null;
            $resourceTemplate['o:description_property'] = !empty($resourceTemplate['o:description_property'])
                && !empty($this->map['properties'][$resourceTemplate['o:description_property']['o:id']]['id'])
                ? ['o:id' => $this->map['properties'][$resourceTemplate['o:description_property']['o:id']]['id']]
                : null;
            foreach ($resourceTemplate['o:resource_template_property'] as &$rtProperty) {
                $rtProperty['o:property'] = !empty($rtProperty['o:property'])
                    && !empty($this->map['properties'][$rtProperty['o:property']['o:id']]['id'])
                    ? ['o:id' => $this->map['properties'][$rtProperty['o:property']['o:id']]['id']]
                    : null;
                // Convert unknown custom vocab into a literal.
                if (strtok($rtProperty['o:data_type'], ':' === 'customvocab')) {
                    $rtProperty['o:data_type'] = !empty($this->map['custom_vocabs'][$rtProperty['o:data_type']]['datatype'])
                        ? $this->map['custom_vocabs'][$rtProperty['o:data_type']]['datatype']
                        : 'literal';
                }
            }
            unset($rtProperty);

            // TODO Use orm.
            $response = $this->api()->create('resource_templates', $resourceTemplate);
            if (!$response) {
                $this->logger->notice(
                    'Unable to create resource template "{label}".', // @translate
                    ['label' => $resourceTemplate['o:label']]
                );
                $this->hasError = true;
                return;
            }
            $this->logger->notice(
                'Resource template "{label}" has been created.', // @translate
                ['label' => $resourceTemplate['o:label']]
            );
            ++$created;

            $this->map['resource_templates'][$resourceTemplateId] = $response->getContent()->id();
        }

        $this->logger->notice(
            '{total} resource templates ready, {created} created, {skipped} skipped.', // @translate
            ['total' => $index, 'created' => $created, 'skipped' => $skipped]
        );
    }

    protected function initializeEntities()
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

        // Check the size of the import.
        foreach (array_keys($resourceTypes) as $resourceType) {
            $total = $this->reader->setObjectType($resourceType)->count();
            if ($total > 10000000) {
                $this->hasError = true;
                $this->logger->err(
                    'Resource "{type}" has too much records ({total}).', // @translate
                    ['type' => $resourceType, 'total' => $total]
                );
                return;
            }
            $this->logger->notice(
                'Preparation of {total} resource "{type}".', // @translate
                ['total' => $total, 'type' => $resourceType]
            );
        }

        // Use direct query to speed process and to reserve a whole list of ids.
        // The indexation, api events, etc. will be done when the resources will
        // be really filled via update.

        // Prepare the list of all ids.
        $mediaItems = [];

        foreach (array_keys($resourceTypes) as $resourceType) {
            // Only the ids are needed here, except for media, that require the
            // item id (mapped below).
            if ($resourceType === 'media') {
                foreach ($this->reader->setObjectType($resourceType) as $resource) {
                    $this->map[$resourceType][(int) $resource['o:id']] = null;
                    $mediaItems[(int) $resource['o:id']] = (int) $resource['o:item']['o:id'];
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
                $sql = '';
                // Save the ids as storage, it should be unique anyway.
                foreach (array_chunk(array_keys($this->map[$resourceType]), self::CHUNK_RECORD_IDS) as $chunk) {
                    $sql .= 'INSERT INTO asset (name,media_type,storage_id) VALUES("","",' . implode('),("","",', $chunk) . ');' ."\n";
                }
                $this->connection->query($sql);

                // Get the mapping of source and destination ids.
                $sql = <<<SQL
SELECT asset.storage_id AS s, asset.id AS d
FROM asset AS asset
WHERE asset.name = ""
    AND asset.media_type = ""
    AND (asset.extension IS NULL OR asset.extension = "")
    AND asset.owner_id IS NULL;
SQL;
                // Fetch by key pair is not supported by doctrine 2.0.
                $this->map[$resourceType] = array_column($this->connection->query($sql)->fetchAll(\PDO::FETCH_ASSOC), 'd', 's');
                continue;
            }

            // For compatibility with old database, a temporary table is used in
            // order to create a generator of enough consecutive rows.
            $sql = <<<SQL
DROP TABLE IF EXISTS temporary_source_resource;
CREATE TEMPORARY TABLE temporary_source_resource (id INT unsigned NOT NULL, PRIMARY KEY (id));

SQL;
            foreach (array_chunk(array_keys($this->map[$resourceType]), self::CHUNK_RECORD_IDS) as $chunk) {
                $sql .= 'INSERT INTO temporary_source_resource (id) VALUES(' . implode('),(', $chunk) . ");\n";
            }
            $sql .= <<<SQL
INSERT INTO resource
    (owner_id, resource_class_id, resource_template_id, is_public, created, modified, resource_type, thumbnail_id, title)
SELECT
    $this->ownerIdOrNull, NULL, NULL, 0, "$this->defaultDate", NULL, $resourceClass, NULL, id
FROM temporary_source_resource;

DROP TABLE IF EXISTS temporary_source_resource;
SQL;
            $this->connection->query($sql);

            // Get the mapping of source and destination ids.
            $sql = <<<SQL
SELECT resource.title AS s, resource.id AS d
FROM resource AS resource
LEFT JOIN {$resourceTables[$resourceType]} AS spec ON spec.id = resource.id
WHERE spec.id IS NULL
    AND resource.resource_type = $resourceClass;
SQL;
            // Fetch by key pair is not supported by doctrine 2.0.
            $this->map[$resourceType] = array_column($this->connection->query($sql)->fetchAll(\PDO::FETCH_ASSOC), 'd', 's');

            // Create the resource in the specific resource table.
            switch ($resourceType) {
                case 'items':
                    $sql = <<<SQL
INSERT INTO item
SELECT resource.id
SQL;
                    break;

                case 'media':
                    // Attach all media to first item id for now, updated below.
                    $itemId = (int) reset($this->map['items']);
                    $sql = <<<SQL
INSERT INTO media
    (id, item_id, ingester, renderer, data, source, media_type, storage_id, extension, sha256, has_original, has_thumbnails, position, lang, size)
SELECT
    resource.id, $itemId, '', '', NULL, NULL, NULL, NULL, NULL, NULL, 0, 0, 0, NULL, NULL
SQL;
                    break;

                case 'item_sets':
                    // Finalize custom vocabs early: item sets map is available.
                    $this->prepareCustomVocabsFinalize();
                    $sql = <<<SQL
INSERT INTO item_set
SELECT resource.id, 0
SQL;
                    break;
                default:
                    return;
            }
            $sql .= PHP_EOL . <<<SQL
FROM resource AS resource
LEFT JOIN {$resourceTables[$resourceType]} AS spec ON spec.id = resource.id
WHERE spec.id IS NULL
    AND resource.resource_type = $resourceClass;
SQL;
            $this->connection->query($sql);

            // Manage the exception for media, that require the good item id.
            if ($resourceType === 'media') {
                foreach (array_chunk($mediaItems, self::CHUNK_RECORD_IDS,  true) as $chunk) {
                    $sql = str_repeat("UPDATE media SET item_id=? WHERE id=?;\n", count($chunk));
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

    protected function fillAssets()
    {
        // The owner should be reloaded each time the entity manager is cleared,
        // so it is saved and reloaded.
        $ownerId = $this->owner ? $this->owner->getId() : false;
        if ($ownerId) {
            $this->owner = $this->entityManager->find(\Omeka\Entity\User::class, $ownerId);
        }

        /** @var \Omeka\Api\Adapter\AssetAdapter $adapter */
        $adapter = $this->adapterManager->get('assets');
        $index = 0;
        $created = 0;
        $skipped = 0;
        foreach ($this->reader->setObjectType('assets') as $resource) {
            ++$index;

            // Some new resources created between first loop.
            if (!isset($this->map['assets'][$resource['o:id']])) {
                ++$skipped;
                $this->logger->notice(
                    'Skipped resource "{type}" #{source_id} added in source.', // @translate
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
            $storageId = bin2hex(\Zend\Math\Rand::getBytes(20));
            $extension = substr($resource['o:filename'], $pos + 1);

            $result = $this->fetchUrl('asset', $resource['o:name'], $resource['o:filename'], $storageId, $extension, $resource['o:asset_url']);
            if ($result['status'] !== 'success') {
                ++$skipped;
                $this->logger->err($result['message']);
                continue;
            }

            $this->entity = $this->entityManager->find(\Omeka\Entity\Asset::class, $this->map['assets'][$resource['o:id']]);

            // Omeka entities are not fluid.
            $this->entity->setOwner($this->owner);
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
                if ($ownerId) {
                    $this->owner = $this->entityManager->find(\Omeka\Entity\User::class, $ownerId);
                }
                $this->logger->notice(
                    '{count}/{total} resource "{type}" imported, {skipped} skipped.', // @translate
                    ['count' => $created, 'total' => count($this->map['assets']), 'type' => 'asset', 'skipped' => $skipped]
                );
            }
        }

        // Remaining entities.
        $this->entityManager->flush();
        $this->entityManager->clear();
        if ($ownerId) {
            $this->owner = $this->entityManager->find(\Omeka\Entity\User::class, $ownerId);
        }

        $this->logger->notice(
            '{count}/{total} resource "{type}" imported, {skipped} skipped.', // @translate
            ['count' => $created, 'total' => $index, 'type' => 'asset', 'skipped' => $skipped]
        );
    }

    protected function fillResources()
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

        // The owner should be reloaded each time the entity manager is cleared,
        // so it is saved and reloaded.
        $ownerId = $this->owner ? $this->owner->getId() : false;
        if ($ownerId) {
            $this->owner = $this->entityManager->find(\Omeka\Entity\User::class, $ownerId);
        }

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
                    if ($ownerId) {
                        $this->owner = $this->entityManager->find(\Omeka\Entity\User::class, $ownerId);
                    }
                    $this->logger->notice(
                        '{count}/{total} resource "{type}" imported, {skipped} skipped.', // @translate
                        ['count' => $created, 'total' => count($this->map[$resourceType]), 'type' => $resourceType, 'skipped' => $skipped]
                    );
                }
            }

            // Remaining entities.
            $this->entityManager->flush();
            $this->entityManager->clear();
            if ($ownerId) {
                $this->owner = $this->entityManager->find(\Omeka\Entity\User::class, $ownerId);
            }

            $this->logger->notice(
                '{count}/{total} resource "{type}" imported, {skipped} skipped.', // @translate
                ['count' => $created, 'total' => count($this->map[$resourceType]), 'type' => $resourceType, 'skipped' => $skipped]
            );
        }
    }

    protected function fillResource(array $resource)
    {
        // Omeka entities are not fluid.
        $this->entity->setOwner($this->owner);

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

        if (array_key_exists('o:title', $resource) && strlen($resource['o:title'])) {
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

    protected function fillValues(array $resource)
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
                if (strtok($datatype, ':') === 'customvocab') {
                    // Convert unknown custom vocab into a literal.
                    // TODO Add a log.
                    if (!empty($this->map['custom_vocabs'][$datatype]['datatype'])) {
                        $datatype = $value['type'] = $this->map['custom_vocabs'][$datatype]['datatype'];
                    } else {
                        $datatype = $value['type'] = 'literal';
                        $this->logger->warn(
                            'Value with datatype {type} for resource #{id} is changed to literal.', // @translate
                            ['type' => $value['type'], 'id' => $this->entity->getId()]
                        );
                    }
                }

                if (!in_array($value['type'], $this->allowedDataTypes)) {
                    $this->logger->warn(
                        'Value of resource {type} #{id} with data type {datatype} is not managed and skipped.', // @translate
                        ['type' => $resourceType, 'id' => $resource['o:id'], 'datatype' => $value['type']]
                    );
                    continue;
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
                }

                if (!empty($value['@id'])) {
                    $valueUri = $value['@id'];
                    $valueValue = isset($value['o:label']) && strlen($value['o:label']) ? $value['o:label'] : null;
                }

                $entity = new \Omeka\Entity\Value;
                $entity->setResource($this->entity);
                $entity->setProperty($property);
                $entity->setType($datatype);
                $entity->setValue($valueValue);
                $entity->setUri($valueUri);
                $entity->setValueResource($valueResource);
                $entity->setLang(empty($value['lang']) ? null : $value['lang']);
                $entity->setIsPublic(!empty($value['is_public']));

                // TODO Manage hydrating of some datatypes (numeric, geometry).
                $entityValues->add($entity);
            }
        }
    }

    protected function fillItemSet(array $resource)
    {
        $this->fillResource($resource);

        $this->entity->setIsOpen(!empty($resource['o:is_open']));
    }

    protected function fillItem(array $resource)
    {
        $this->fillResource($resource);

        $itemSets = $this->entity->getItemSets();
        foreach ($resource['o:item_set'] as $itemSet) {
            if (isset($this->map['item_sets'][$itemSet['o:id']])) {
                $itemSets->add($this->entityManager->find(\Omeka\Entity\ItemSet::class, $this->map['item_sets'][$itemSet['o:id']]));
            }
        }

        // Media are updated separately in order to manage files.
    }

    protected function fillMedia(array $resource)
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
            $storageId = bin2hex(\Zend\Math\Rand::getBytes(20));
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

        $this->entity->setData(isset($resource['o:data']) ? $resource['o:data'] : null);
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

    protected function fillMapping()
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
            $total = $this->reader->setObjectType($resourceType)->count();
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
                        ['count' => $created, 'total' => $total, 'type' => $resourceType, 'skipped' => $skipped]
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

    protected function fillMappingMappings(array $resource)
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

    protected function fillMappingMarkers(array $resource)
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
    protected function checkAvailableModules()
    {
        // Modules managed by the module.
        $modules = [
            'CustomVocab',
            'Mapping',
        ];

        $services = $this->getServiceLocator();
        /** @var \Omeka\Module\Manager $moduleManager */
        $moduleManager = $services->get('Omeka\ModuleManager');
        foreach ($modules as $moduleClass) {
            $module = $moduleManager->getModule($moduleClass);
            $this->modules[$module] = $module
                && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE;
        }
    }

    protected function equalResourceTemplates(
        \Omeka\Api\Representation\ResourceTemplateRepresentation $rta,
        array $rtb
    ) {
        $resourceClassById = [];
        foreach ($this->map['resource_classes'] as $resourceClass) {
            $resourceClassById[$resourceClass['source']] = $resourceClass['id'];
        }
        $propertiesById = [];
        foreach ($this->map['properties'] as $property) {
            $propertiesById[$property['source']] = $property['id'];
        }

        // Local uris are incorrect since server base url may be not set in job.
        $rta = json_decode(json_encode($rta), true);
        unset($rta['@context'], $rta['@id'], $rta['o:id'], $rta['o:owner'], $rta['o:resource_class']['@id'],
            $rta['o:title_property']['@id'], $rta['o:description_property']['@id']);
        foreach ($rta['o:resource_template_property'] as &$rtProperty) {
            unset($rtProperty['o:property']['@id']);
        }
        unset($rtProperty);

        unset($rtb['@context'], $rtb['@id'], $rtb['o:id'], $rtb['o:owner'], $rtb['o:resource_class']['@id'],
            $rtb['o:title_property']['@id'], $rtb['o:description_property']['@id']);
        if ($rtb['o:resource_class']) {
            $rtb['o:resource_class']['o:id'] = isset($resourceClassById[$rtb['o:resource_class']['o:id']])
                ? $resourceClassById[$rtb['o:resource_class']['o:id']]
                : null;
        }
        if ($rtb['o:title_property']) {
            $rtb['o:title_property']['o:id'] = isset($propertiesById[$rtb['o:title_property']['o:id']])
                ? $propertiesById[$rtb['o:title_property']['o:id']]
                : null;
        }
        if ($rtb['o:description_property']) {
            $rtb['o:description_property']['o:id'] = isset($propertiesById[$rtb['o:description_property']['o:id']])
                ? $propertiesById[$rtb['o:description_property']['o:id']]
                : null;
        }
        foreach ($rtb['o:resource_template_property'] as &$rtProperty) {
            unset($rtProperty['o:property']['@id']);
            $rtProperty['o:property']['o:id'] = isset($propertiesById[$rtProperty['o:property']['o:id']])
                ? $propertiesById[$rtProperty['o:property']['o:id']]
                : null;
        }
        unset($rtProperty);

        return $rta === $rtb;
    }

    /**
     * Ferch, check and save a file.
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

    protected function logErrors($entity, $errorStore)
    {
        foreach ($errorStore->getErrors() as $messages) {
            if (!i_sarray($messages)) {
                $messages = [$messages];
            }
            foreach ($messages as $message) {
                $this->logger->err($message);
            }
        }
    }
}
