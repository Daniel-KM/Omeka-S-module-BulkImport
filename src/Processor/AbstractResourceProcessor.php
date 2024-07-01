<?php declare(strict_types=1);

namespace BulkImport\Processor;

use ArrayObject;
use BulkImport\Interfaces\Configurable;
use BulkImport\Interfaces\Parametrizable;
use BulkImport\Stdlib\MessageStore;
use BulkImport\Traits\ConfigurableTrait;
use BulkImport\Traits\ParametrizableTrait;
use Laminas\Form\Form;
use Common\Stdlib\PsrMessage;
use Omeka\Api\Exception\ValidationException;
use Omeka\Api\Representation\AbstractEntityRepresentation;
use Omeka\Api\Request;

/**
 * Can be used for all derivative of AbstractResourceRepresentation.
 */
abstract class AbstractResourceProcessor extends AbstractProcessor implements Configurable, Parametrizable
{
    use ConfigurableTrait, ParametrizableTrait;

    /**
     * Specific action used for unidentified identifiers..
     *
     * @var string
     */
    const ACTION_ERROR = 'error';

    /**
     * Specific action used for sub update, for example the resources for a new
     * thumbnail.
     *
     * @var string
     */
    const ACTION_SUB_UPDATE = 'sub_update';

    /**
     * The resource name to process with this processor.
     *
     * @var string
     */
    protected $resourceName;

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
     * List of types for keys of the resources, used to simplify mapping.
     *
     * Keys are the fields of the resources and values are the variable types.
     * Managed types are: "skip", "boolean", "integer", "string", "datetime",
     * "entity", "single_data".
     *
     * In default process, the last value is kept.
     *
     * @var array
     */
    protected $fieldTypes = [
        // Common metadata.
        'resource_name' => 'string',
        // "o:id" may be an identifier.
        'o:id' => 'string',
        'o:created' => 'datetime',
        'o:modified' => 'datetime',
        'o:is_public' => 'boolean',
        'o:owner' => 'entity',
        // Alias of 'o:owner'.
        'o:email' => 'entity',
    ];

    /**
     * @var \Omeka\File\TempFileFactory
     */
    protected $tempFileFactory;

    /**
     * @var \BulkImport\Mvc\Controller\Plugin\UpdateResource
     */
    protected $updateResource;

    /**
     * @var ArrayObject
     */
    protected $base;

    /**
     * @var string
     */
    protected $action;

    /**
     * @var string
     */
    protected $actionUnidentified;

    /**
     * @var bool
     */
    protected $allowDuplicateIdentifiers = false;

    /**
     * @var bool
     */
    protected $skipMissingFiles = false;

    /**
     * @var bool
     */
    protected $fakeFiles = false;

    /**
     * @var array
     */
    protected $resourceNamesMore;

    /**
     * @var bool
     */
    protected $useDatatypeLiteral = false;

    /**
     * @see \BulkImport\Job\ImportTrait
     *
     * @var array
     */
    protected $identifierNames = [
        'o:id' => 'o:id',
        // 'dcterms:identifier' => 10,
    ];

    /**
     * @var int
     */
    protected $indexResource = 0;

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
        $config = new ArrayObject;
        $this->handleFormGeneric($config, $values);
        $this->handleFormSpecific($config, $values);
        $this->setConfig($config->getArrayCopy());
        return $this;
    }

    public function handleParamsForm(Form $form, ?string $mappingSerialized = null): self
    {
        $values = $form->getData();
        $params = new ArrayObject;
        $this->handleFormGeneric($params, $values);
        $this->handleFormSpecific($params, $values);
        $params['mapping'] = isset($mappingSerialized) ? unserialize($mappingSerialized) : ($params['values'] ?? []);
        $files = $this->bulkFileUploaded->prepareFilesUploaded($values['files']['files'] ?? []);
        if ($files) {
            $params['files'] = $files;
        } elseif ($params->offsetExists('files')) {
            $params->offsetUnset('files');
        }
        $this->setParams($params->getArrayCopy());
        return $this;
    }

    protected function handleFormGeneric(ArrayObject $args, array $values): self
    {
        return $this;
    }

    protected function handleFormSpecific(ArrayObject $args, array $values): self
    {
        return $this;
    }

    public function isValid(): bool
    {
        $result = $this->init();
        if (!$result) {
            $this->lastErrorMessage = true;
        }
        return parent::isValid();
    }

    protected function init(): bool
    {
        // Used for uploaded files.
        $services = $this->getServiceLocator();
        $this->tempFileFactory = $services->get('Omeka\File\TempFileFactory');
        $this->updateResource = $services->get('ControllerPluginManager')->get('updateResource');

        $this->prepareAction();
        if ($this->totalErrors) {
            return false;
        }

        $this->prepareActionUnidentified();
        if ($this->totalErrors) {
            return false;
        }

        $errorStore = new MessageStore();
        $files = $this->params['files'] ?? [];
        $this->bulkFileUploaded
            ->setErrorStore($errorStore)
            ->setFilesUploaded($files)
            ->prepareFilesZip();
        if ($errorStore->hasErrors()) {
            ++$this->totalErrors;
            return false;
        }

        $this->logger->notice(
            'The process will run action "{action}" with option "{mode}" for unindentified resources.', // @translate
            ['action' => $this->action, 'mode' => $this->actionUnidentified]
        );

        $this
            ->prepareSpecific();
        if ($this->totalErrors) {
            return false;
        }

        $this->allowDuplicateIdentifiers = (bool) $this->getParam('allow_duplicate_identifiers', false);
        $this->fakeFiles = (bool) $this->getParam('fake_files', false);
        $this->identifierNames = $this->getParam('identifier_name', $this->identifierNames);
        $this->skipMissingFiles = (bool) $this->getParam('skip_missing_files', false);

        $this->bulkIdentifiers->setAllowDuplicateIdentifiers($this->allowDuplicateIdentifiers);

        // Parameter specific to resources.
        $this->useDatatypeLiteral = (bool) $this->getParam('value_datatype_literal');

        // Check for FileSideload: remove files after import is not possible
        // because of the multi-step process and the early check of files.
        // TODO Allow to use FileSideload option "file_sideload_delete_file".
        if ($this->settings->get('file_sideload_delete_file') === 'yes') {
            // This is not an error: the input data may not use sideload files.
            $this->logger->warn(
                'The option to delete files (module File Sideload) is not fully supported currently. Check config of the module or use urls.' // @translate
            );
        }

        $translate = $services->get('ViewHelperManager')->get('translate');
        $this->resourceNamesMore = [
            // For compatibility with spreadsheet used in Omeka classic.
            'collection' => 'item_sets',
            'collections' => 'item_sets',
            'file' => 'media',
            'files' => 'media',
            $translate('asset') => 'assets',
            $translate('item') => 'items',
            $translate('item set') => 'item_sets',
            $translate('media') => 'media',
            $translate('assets') => 'assets',
            $translate('items') => 'items',
            $translate('item sets') => 'item_sets',
            $translate('medias') => 'media',
            $translate('media') => 'media',
            $translate('collection') => 'item_sets',
            $translate('collections') => 'item_sets',
            $translate('file') => 'media',
            $translate('files') => 'media',
        ];

        // The base entity depends on the resource type, so it should be init.
        // Prepare it one time for performance, but can be completed with
        // getPreparedBase().
        $this->prepareBaseEntity();

        return true;
    }

    protected function prepareAction(): self
    {
        $this->action = $this->getParam('action') ?: self::ACTION_CREATE;
        if (!in_array($this->action, [
            self::ACTION_CREATE,
            self::ACTION_APPEND,
            self::ACTION_REVISE,
            self::ACTION_UPDATE,
            self::ACTION_REPLACE,
            self::ACTION_DELETE,
            self::ACTION_SKIP,
        ])) {
            ++$this->totalErrors;
            $this->logger->err(
                'Action "{action}" is not managed.', // @translate
                ['action' => $this->action]
            );
        }
        return $this;
    }

    protected function prepareActionUnidentified(): self
    {
        $this->actionUnidentified = $this->getParam('action_unidentified') ?: self::ACTION_SKIP;
        if (!in_array($this->actionUnidentified, [
            'error',
            self::ACTION_CREATE,
            self::ACTION_SKIP,
        ])) {
            ++$this->totalErrors;
            $this->logger->err(
                'Action "{action}" for unidentified resource is not managed.', // @translate
                ['action' => $this->actionUnidentified]
            );
        }
        return $this;
    }

    protected function prepareSpecific(): self
    {
        return $this;
    }

    protected function prepareBaseEntity(): self
    {
        // ArrayObject is used internally to simplify calling functions.
        // TODO Use a specific class that extends ArrayObject to manage process metadata (check and errors).
        $resource = new ArrayObject;
        $resource['resource_name'] = $this->getResourceName();
        $resource['o:id'] = null;
        // The human source index is one-based, so "0" means undetermined.
        $resource['source_index'] = 0;
        $resource['checked_id'] = false;
        $resource['messageStore'] = new MessageStore();
        $this->prepareBaseEntitySpecific($resource);
        $this->base = $resource;
        return $this;
    }

    protected function prepareBaseEntitySpecific(ArrayObject $resource): self
    {
        return $this;
    }

    protected function getPreparedBase(): ArrayObject
    {
        $result = clone $this->base;
        // Clone does not clone sub-object, so init it here.
        $result['messageStore'] = new MessageStore();
        return $result;
    }

    public function fillResource(array $data, ?int $index = null): ?array
    {
        $resource = $this->getPreparedBase();

        $this->indexResource = $index;
        $resource['source_index'] = $this->indexResource;

        $this
            // Fill the resource with data.
            ->fillResourceData($resource, $data)
            // Specific values may have been processed.
            // Other values are copied as array of values in the resource.
            // So generally data are useless here, except for entities.
            ->fillResourceFields($resource, $data)
        ;

        return $resource->getArrayCopy();
    }

    /**
     * Fill resource with fields according to base field types.
     *
     * In this abstract processor, the first step is to simplify, not to fill or
     * to check.
     */
    protected function fillResourceData(ArrayObject $resource, array $data): self
    {
        // Internal keys of the base entities to skip.
        $baseMetadataTypes = [
            'source_index' => 'skip',
            'checked_id' => 'skip',
            'messageStore' => 'skip',
        ];

        // The generic resource contains all field types of specific resources.

        $currentFieldTypes = empty($resource['resource_name']) || $resource['resource_name'] === $this->resourceName
            ? $this->fieldTypes
            : $this->getFieldTypesForResource($resource['resource_name']);
        $metadataTypes = $baseMetadataTypes + $currentFieldTypes;

        // The values are always a list get from the meta mapper mapping.
        // TODO Why are the values always a list get from the meta mapper mapping? (manage the case of multiple identifiers).
        foreach ($data as $field => $values) switch ($metadataTypes[$field] ?? null) {
            case 'skip':
                // Nothing to do.
                break;
            case 'boolean':
            case 'integer':
            case 'string':
            case 'array':
                $resource[$field] = is_array($values) ? end($values) : $values;
                break;
            case 'datetime':
                $value = is_array($values) ? end($values) : $values;
                $resource[$field] = ['@value' => $value];
                break;
            case 'datetimes':
                if (!is_array($values)) {
                    $values = [$values];
                }
                foreach ($values as $value) {
                    $resource[$field][] = ['@value' => $value];
                }
                break;
            /* // Don't fill entities here: they require other data to check.
            case 'entity':
                $value = end($values);
                $resource[$field] = $this->fillEntity($resource, $field, $value);
                break;
            case 'entities':
                foreach ($values as $value) {
                    $this->fillEntity($resource, $field, $value, true);
                }
                break;
            */
            case 'booleans':
            case 'integers':
            case 'strings':
            case 'arrays':
            default:
                $resource[$field] = is_array($values) ? $values : [$values];
                break;
        }

        return $this;
    }

    /**
     * Fill other data that are not managed in a common way.
     *
     * Specific values set in metadataTypes have been processed.
     * Other values are already copied as an array of values.
     */
    protected function fillResourceFields(ArrayObject $resource, array $data): self
    {
        return $this
            // Fill the resource name first when possible then id, because they
            // are the base to fill other data.
            ->fillResourceName($resource, $data)
            ->fillResourceId($resource, $data)
            ->fillResourceSingleEntities($resource, $data)
            ->fillResourceSpecific($resource, $data)
        ;
    }

    /**
     * Normalize the resource name.
     */
    protected function fillResourceName(ArrayObject $resource, array $data): self
    {
        if (isset($resource['resource_name'])
            // Don't revalidate data from the resource base entity.
            && $resource['resource_name'] !== $this->resourceName
        ) {
            $resource['resource_name'] = $this->easyMeta->resourceName($resource['resource_name'])
                ?? $this->easyMeta->resourceName(mb_strtolower($resource['resource_name']))
                ?? $this->resourceNamesMore[mb_strtolower($resource['resource_name'])]
                ?? $resource['resource_name'];
        }
        return $this;
    }

    protected function fillResourceId(ArrayObject $resource, array $data): self
    {
        // Warning: "o:id" may be an identifier here, so it is converted.
        // Furthermore, the id may be set via another key (dcterms:identifier,
        // storage id, etc.).
        if (empty($resource['o:id'])) {
            $resource['o:id'] = null;
            return $this;
        }

        if (is_numeric($resource['o:id'])) {
            $resource['o:id'] = ((int) $resource['o:id']) ?: null;
        }

        if (empty($resource['o:id'])) {
            $resource['o:id'] = null;
            return $this;
        }

        // Validate the main id/identifier early.
        $resourceName = $resource['resource_name'] ?? null;

        $id = $this->bulkIdentifiers->getIdFromIndex($resource['source_index']);
        if ($id) {
            $resource['o:id'] = $id;
        } else {
            // TODO Use a generic method.
            $resource['o:id'] = in_array($resourceName, [null, 'items', 'media', 'item_sets', 'value_annotations', 'annotations'])
                ? $this->bulkIdentifiers->findResourceFromIdentifier($resource['o:id'], 'o:id', $resourceName, $resource['messageStore'])
                : $this->api->searchOne($resourceName, ['id' => $resource['o:id']], ['returnScalar' => 'id'])->getContent();
        }

        if ($resource['o:id']) {
            $resource['checked_id'] = true;
        } else {
            $resource['o:id'] = null;
            $resource['messageStore']->addError('resource', new PsrMessage(
                'source index #{index}: Internal id cannot be found. The entry is skipped.', // @translate
                ['index' => $resource['source_index']]
            ));
        }

        return $this;
    }

    protected function fillResourceSingleEntities(ArrayObject $resource, array $data): self
    {
        // Entities are not processed in the meta mapper, neither by the
        // processor above, so it is always an array of strings or arrays
        // to copy from data into resource.

        $currentFieldTypes = empty($resource['resource_name']) || $resource['resource_name'] === $this->resourceName
            ? $this->fieldTypes
            : $this->getFieldTypesForResource($resource['resource_name']);

        // Owner is prefilled in base entity. This is a single entity.
        // TODO Use $this->getFieldTypesForResource().
        if ((isset($currentFieldTypes['o:owner']) || isset($currentFieldTypes['o:email']))
            && (array_key_exists('o:owner', $data) || array_key_exists('o:email', $data))
        ) {
            $values = array_merge(array_values($data['o:owner'] ?? []), array_values($data['o:email'] ?? []));
            foreach (array_filter($values) as $value) {
                // Get the entity id.
                if (is_array($value)) {
                    $id = empty($value['o:id']) ? null : $value['o:id'];
                    $email = empty($value['o:email']) ? null : $value['o:email'];
                    $value = $id ?? $email ?? reset($value);
                }
                $id = $this->bulkIdentifiers->getUserId($value);
                if ($id) {
                    $resource['o:owner'] = empty($email)
                        ? ['o:id' => $id]
                        : ['o:id' => $id, 'o:email' => $email];
                } else {
                    $resource['o:owner'] = $resource['o:owner'] ?? null;
                    $resource['messageStore']->addError('resource', new PsrMessage(
                        'The user "{source}" does not exist.', // @translate
                        ['source' => $value]
                    ));
                }
            }
        }

        return $this;
    }

    protected function fillResourceSpecific(ArrayObject $resource, array $data): self
    {
        return $this;
    }

    public function checkResource(array $resource): array
    {
        $this->indexResource = $resource['source_index'];
        $resourceObject = new ArrayObject($resource);
        $resourceObject['messageStore'] = $resource['messageStore'] ?? new MessageStore();
        $this->checkEntity($resourceObject);
        return $resourceObject->getArrayCopy();
    }

    /**
     * Check if a resource is well-formed.
     *
     * @todo Replace by checkResource() directly, but manage ResourceProcessor.
     */
    protected function checkEntity(ArrayObject $resource): bool
    {
        if (!$this->checkId($resource)) {
            $this->fillId($resource);
        }

        $listIdentifiers = function (ArrayObject $resource): array {
            // To simplify check of source, log all identifiers when
            // possible and append common identifiers and titles.
            $identifiers = $this->extractIdentifiersAll($resource);
            $identifierCommon = $this->extractIdentifierCommon($resource);
            if ($identifierCommon) {
                $identifiers['identifier'][] = $identifierCommon;
            }
            $identifierTitle = $this->extractIdentifierTitle($resource);
            if ($identifierTitle) {
                $identifiers['title'][] = $identifierTitle;
            }
            return $identifiers;
        };

        if ($resource['o:id']) {
            if (!$this->actionRequiresId()) {
                if ($this->allowDuplicateIdentifiers) {
                    // Action is create or skip.
                    // TODO This feature is not clear: disallow the action create when o:id is set in the source (allow only a found identifier).
                    if ($this->action === self::ACTION_CREATE) {
                        unset($resource['o:id']);
                    }
                } else {
                    $identifiers = $listIdentifiers($resource);
                    $resourceNameLabel = $this->easyMeta->resourceLabel($resource['resource_name']);
                    if (!$identifiers) {
                        $resource['messageStore']->addError('resource', new PsrMessage(
                            'The action "{action}" requires an identifier ({resource_name} #{resource_id}).', // @translate
                            ['action' => $this->action, 'resource_name' => $resourceNameLabel, 'resource_id' => $resource['o:id']]
                        ));
                    } elseif ($this->action === self::ACTION_CREATE) {
                        $resource['messageStore']->addError('resource', new PsrMessage(
                            'The action "{action}" cannot have an id or a duplicate identifier ({resource_name} #{resource_id}): {json}', // @translate
                            ['action' => $this->action, 'resource_name' => $resourceNameLabel, 'resource_id' => $resource['o:id'], 'json' => $identifiers]
                        ));
                    } else {
                        $resource['messageStore']->addError('resource', new PsrMessage(
                            'The action "{action}" requires a unique identifier ({resource_name} #{resource_id}): {json}', // @translate
                            ['action' => $this->action, 'resource_name' => $resourceNameLabel, 'resource_id' => $resource['o:id'], 'json' => $identifiers]
                        ));
                    }
                }
            }
        }
        // No resource id but the action requires one. If the option for
        // undefined id is to create new resources, it is normal. If the option
        // is to skip the current line, add a warning, else an error.
        elseif ($this->actionRequiresId()
            && in_array($this->actionUnidentified, [self::ACTION_ERROR, self::ACTION_SKIP])
        ) {
            $identifiers = $listIdentifiers($resource);
            $logLevel = $this->actionUnidentified === self::ACTION_SKIP ? \Laminas\Log\Logger::WARN: \Laminas\Log\Logger::ERR;
            if (!$identifiers) {
                $resource['messageStore']->addMessage('identifier', new PsrMessage(
                    'The action "{action}" requires an identifier.', // @translate
                    ['action' => $this->action]
                ), $logLevel);
            } elseif ($this->allowDuplicateIdentifiers) {
                $resource['messageStore']->addMessage('identifier', new PsrMessage(
                    'The action "{action}" requires an identifier: {json}', // @translate
                    ['action' => $this->action, 'json' => $identifiers]
                ), $logLevel);
            } else {
                $resource['messageStore']->addMessage('identifier', new PsrMessage(
                    'The action "{action}" requires a unique identifier: {json}', // @translate
                    ['action' => $this->action, 'json' => $identifiers]
                ), $logLevel);
            }
        }

        $this->checkEntitySpecific($resource);

        // Hydration check may be heavy, so check it only wen there are not
        // issue.
        if ($resource['messageStore']->hasErrors()) {
            return false;
        }

        $this->checkEntityViaHydration($resource);

        return !$resource['messageStore']->hasErrors();
    }

    protected function checkEntitySpecific(ArrayObject $resource): bool
    {
        return true;
    }

    protected function checkEntityViaHydration(ArrayObject $resource): bool
    {
        // Don't do more checks for skip.
        $operation = $this->standardOperation($this->action);
        if (!$operation) {
            return !$resource['messageStore']->hasErrors();
        }

        /** @see \Omeka\Api\Manager::execute() */
        if (!$this->checkAdapter($resource['resource_name'], $operation)) {
            $resource['messageStore']->addError('rights', new PsrMessage(
                'User has no rights to "{action}" {resource_name}.', // @translate
                ['action' => $operation, 'resource_name' => $resource['resource_name']]
            ));
            return false;
        }

        // Check through hydration and standard api.
        /** @var \Omeka\Api\Adapter\AbstractResourceEntityAdapter $adapter */
        $adapter = $this->adapterManager->get($resource['resource_name']);

        // Some options are useless here, but added anyway.
        /** @see \Omeka\Api\Request::setOption() */
        $requestOptions = [
            'continueOnError' => true,
            'flushEntityManager' => false,
            'responseContent' => 'resource',
        ];

        $request = new Request($operation, $resource['resource_name']);
        $request
            ->setContent($resource->getArrayCopy())
            ->setOption($requestOptions);

        if (empty($resource['o:id'])
            && $operation !== Request::CREATE
            && $this->actionUnidentified === self::ACTION_SKIP
        ) {
            return true;
        } elseif ($operation === Request::CREATE
            || (empty($resource['o:id']) && $this->actionUnidentified === self::ACTION_CREATE)
        ) {
            $entityClass = $adapter->getEntityClass();
            $entity = new $entityClass;
            // TODO This exception should be managed in resource or item processor.
            if ($resource['resource_name'] === 'media') {
                $entityItem = null;
                // Already checked, except when a source identifier is used.
                if (empty($resource['o:item']['o:id'])) {
                    if (!empty($resource['o:item']['source_identifier']) && !empty($resource['o:item']['checked_id'])) {
                        $entityItem = new \Omeka\Entity\Item;
                    }
                } else {
                    try {
                        $entityItem = $adapter->getAdapter('items')->findEntity($resource['o:item']['o:id']);
                    } catch (\Exception $e) {
                        // Managed below.
                    }
                }
                if (!$entityItem) {
                    $resource['messageStore']->addError('media', new PsrMessage(
                        'Media must belong to an item.' // @translate
                    ));
                    return false;
                }
                $entity->setItem($entityItem);
                $entity->setIngester($resource['o:ingester'] ?? null);
            } elseif ($resource['resource_name'] === 'assets') {
                $entity->setName($resource['o:name'] ?? '');
            }
        } else {
            $request
                ->setId($resource['o:id']);

            $entity = $adapter->findEntity($resource['o:id']);
            // \Omeka\Api\Adapter\AbstractEntityAdapter::authorize() is protected.
            if (!$this->acl->userIsAllowed($entity, $operation)) {
                $resource['messageStore']->addError('rights', new PsrMessage(
                    'User has no rights to "{action}" {resource_name} {resource_id}.', // @translate
                    ['action' => $operation, 'resource_name' => $resource['resource_name'], 'resource_id' => $resource['o:id']]
                ));
                return false;
            }

            // For deletion, just check rights.
            if ($operation === Request::DELETE) {
                return !$resource['messageStore']->hasErrors();
            }
        }

        // Complete from api modules (api.execute/create/update.pre).
        /** @see \Omeka\Api\Manager::initialize() */
        try {
            $this->api->initialize($adapter, $request);
        } catch (\Exception $e) {
            $resource['messageStore']->addError('modules', new PsrMessage(
                'Initialization exception: {exception}', // @translate
                ['exception' => $e]
            ));
            return false;
        }

        // Check new files for assets, items and media before hydration to speed
        // process, because files are checked during hydration too, but with a
        // full download.
        $this->checkNewFiles($resource);

        // Some files may have been removed.
        if ($this->skipMissingFiles) {
            $request
                ->setContent($resource->getArrayCopy());
        }

        // The entity is checked here to store error when there is a file issue.
        $errorStore = new \Omeka\Stdlib\ErrorStore;
        $adapter->validateRequest($request, $errorStore);
        $adapter->validateEntity($entity, $errorStore);

        // TODO Process hydration checks except files or use an event to check files differently during hydration or store loaded url in order to get all results one time.
        // TODO In that case, check iiif image or other media that may have a file or url too.

        if ($resource['messageStore']->hasErrors() || $errorStore->hasErrors()) {
            $resource['messageStore']->mergeErrors($errorStore);
            return false;
        }

        // TODO Check the file for asset.
        if ($resource['resource_name'] === 'assets') {
            return !$resource['messageStore']->hasErrors();
        }

        // Don't check new files twice. Furthermore, the media are pre-hydrated
        // and a flush somewhere may duplicate the item.
        // TODO Use a second entity manager.
        /*
        $isItem = $resource['resource_name'] === 'items';
        if ($isItem) {
            $res = $request->getContent();
            unset($res['o:media']);
            $request->setContent($res);
        }
         */

        // Process hydration checks for remaining checks, in particular media.
        // This is the same operation than api create/update, but without
        // persisting entity.
        // Normally, all data are already checked, except actual medias.
        $errorStore = new \Omeka\Stdlib\ErrorStore;
        try {
            $adapter->hydrateEntity($request, $entity, $errorStore);
        } catch (\Exception $e) {
            $resource['messageStore']->addError('validation', new PsrMessage(
                'Validation exception: {exception}', // @translate
                ['exception' => $e]
            ));
            return false;
        }

        // Remove pre-hydrated entities from entity manager: it was only checks.
        // TODO Ideally, checks should be done on a different entity manager, so modify service before and after.
        /** @var \Doctrine\ORM\EntityManager $entityManager */
        $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
        $entityManager->clear();

        // Merge error store with resource message store.
        $resource['messageStore']->mergeErrors($errorStore, 'validation');
        if ($resource['messageStore']->hasErrors()) {
            return false;
        }

        // TODO Check finalize (post) api process. Note: some modules flush results.

        return !$resource['messageStore']->hasErrors();
    }

    /**
     * Check if new files (local system and urls) are available and allowed.
     *
     * Just warn when a file is missing with mode "skip missing files" (only for
     * items with medias, not media alone or assets).
     *
     * By construction, it's not possible to check or modify existing files.
     */
    protected function checkNewFiles(ArrayObject $resource): bool
    {
        if (!in_array($resource['resource_name'], ['items', 'media', 'assets'])) {
            return true;
        }

        $isItem = $resource['resource_name'] === 'items';
        if ($isItem) {
            $resourceFiles = $resource['o:media'] ?? [];
            if (!$resourceFiles) {
                return true;
            }
        } else {
            $resourceFiles = [$resource];
        }

        if ($isItem && $this->skipMissingFiles) {
            return $this->checkItemFilesWarn($resource, $resourceFiles);
        }

        $isAsset = $resource['resource_name'] === 'assets';

        foreach ($resourceFiles as $resourceFile) {
            // A file cannot be updated.
            if (!empty($resourceFile['o:id'])) {
                continue;
            }
            $this->bulkFile->setIsAsset($isAsset);
            if (!empty($resourceFile['ingest_url'])) {
                $this->bulkFile->checkUrl($resourceFile['ingest_url'], $resource['messageStore']);
            } elseif (!empty($resourceFile['ingest_filename'])) {
                $this->bulkFile->checkFile($resourceFile['ingest_filename'], $resource['messageStore']);
            } elseif (!empty($resourceFile['ingest_directory'])) {
                $this->bulkFile->checkDirectory($resourceFile['ingest_directory'], $resource['messageStore']);
            } else {
                // Add a warning: cannot be checked for other media ingester? Or is it checked somewhere else?
            }
        }

         return !$resource['messageStore']->hasErrors();
    }

    /**
     * Warn if new files (local system and urls) are available and allowed.
     *
     * By construction, it's not possible to check or modify existing files.
     * So this method is only for items.
     */
    protected function checkItemFilesWarn(ArrayObject $resource): bool
    {
        // This array allows to skip check when the same file is imported
        // multiple times, in particular during tests of an import.
        static $missingFiles = [];

        $resource['messageStore']->setStoreNewErrorAsWarning(true);

        $ingestData = [
            'ingest_url' => [
                'method' => 'checkUrl',
                'message_msg' => 'Cannot fetch url "{url}". The url is skipped.', // @translate
                'message_key' => 'url',
            ],
            'ingest_filename' => [
                'method' => 'checkFile',
                'message_msg' => 'Cannot fetch file "{file}". The file is skipped.', // @translate
                'message_key' => 'file',
            ],
            'ingest_directory' => [
                'method' => 'checkDirectory',
                'message_msg' => 'Cannot fetch directory "{file}". The file is skipped.', // @translate
                'message_key' => 'file',
            ],
        ];

        $resourceFiles = $resource['o:media'];
        foreach ($resourceFiles as $key => $resourceFile) {
            // A file cannot be updated.
            if (!empty($resourceFile['o:id'])) {
                continue;
            }

            $ingestSourceKey = !empty($resourceFile['ingest_url'])
                ? 'ingest_url'
                : (!empty($resourceFile['ingest_filename'])
                    ? 'ingest_filename'
                    : (!empty($resourceFile['ingest_directory'])
                        ? 'ingest_directory'
                        : null));
            if (!$ingestSourceKey) {
                // Add a warning: cannot be checked for other media ingester? Or is it checked somewhere else?
                continue;
            }

            // Method is "checkFile", "checkUrl" or "checkDirectory".
            $ingestSource = $resourceFile[$ingestSourceKey];
            $method = $ingestData[$ingestSourceKey]['method'];
            $messageMsg = $ingestData[$ingestSourceKey]['message_msg'];
            $messageKey = $ingestData[$ingestSourceKey]['message_key'];

            if (isset($missingFiles[$ingestSourceKey][$ingestSource])) {
                $resource['messageStore']->addWarning($messageKey, new PsrMessage($messageMsg, [$messageKey => $ingestSource]));
            } else {
                $result = $this->bulkFile->$method($ingestSource, $resource['messageStore']);
                if (!$result) {
                    $missingFiles[$ingestSourceKey][$ingestSource] = true;
                }
            }
            if (isset($missingFiles[$ingestSourceKey][$ingestSource])) {
                unset($resource['o:media'][$key]);
            }
        }

        $resource['messageStore']->setStoreNewErrorAsWarning(false);

        return !$resource['messageStore']->hasErrors();
    }

    /**
     * Check if a resource has a id and mark "checked_id" as true in all cases.
     *
     * The action should be checked separately, else the result may have no
     * meaning.
     *
     * @todo Clarify the key "checked_id" when there is no id.
     */
    protected function checkId(ArrayObject $resource): bool
    {
        if (!empty($resource['checked_id'])) {
            return !empty($resource['o:id']);
        }

        // The id is set, but not checked. So check it.
        // TODO Check if the resource name is the good one.
        if ($resource['o:id']) {
            $resourceName = $resource['resource_name'] ?: $this->getResourceName();
            $resource['messageStore'] = $resource['messageStore'] ?? new MessageStore();
            if (empty($resourceName) || $resourceName === 'resources') {
                $resource['messageStore']->addError('resource_name', new PsrMessage(
                    'The resource id #{id} cannot be checked: the resource type is undefined.', // @translate
                    ['id' => $resource['o:id']]
                ));
            } else {
                $resourceId = $resourceName === 'assets'
                    ? $this->bulkIdentifiers->findAssetsFromIdentifiers($resource['o:id'], 'o:id')
                    : $this->bulkIdentifiers->findResourceFromIdentifier($resource['o:id'], 'o:id', $resourceName, $resource['messageStore'] ?? null);
                if (!$resourceId) {
                    $resource['messageStore']->addError('resource_id', new PsrMessage(
                        'The id #{id} of this resource doesn’t exist.', // @translate
                        ['id' => $resource['o:id']]
                    ));
                }
            }
        }

        $resource['checked_id'] = true;
        return !empty($resource['o:id']);
    }

    /**
     * Fill id of resource if not set. No check if set, so use checkId() first.
     *
     * The resource type is required, so this method should be used in the end
     * of the process.
     *
     * @return bool True if id is set.
     */
    protected function fillId(ArrayObject $resource): bool
    {
        if (is_numeric($resource['o:id'])) {
            return true;
        }

        $resource['messageStore'] = $resource['messageStore'] ?? new MessageStore();

        // TODO getResourceName() is only in child AbstractResourceProcessor.
        $resourceName = empty($resource['resource_name'])
            ? $this->getResourceName()
            : $resource['resource_name'];
        if (empty($resourceName) || $resourceName === 'resources') {
            $resource['messageStore']->addError('resource_name', new PsrMessage(
                'The resource id cannot be filled: the resource type is undefined.' // @translate
            ));
        }

        $idNames = $this->identifierNames;
        $key = array_search('o:id', $idNames);
        if ($key !== false) {
            unset($idNames[$key]);
        }
        if (empty($idNames) && !$this->actionRequiresId()) {
            return false;
        }

        if (empty($idNames)) {
            if ($this->allowDuplicateIdentifiers) {
                $resource['messageStore']->addWarning('identifier', new PsrMessage(
                    'The resource has no identifier.' // @translate
                ));
            } else {
                $resource['messageStore']->addError('identifier', new PsrMessage(
                    'The resource id cannot be filled: no metadata defined as identifier and duplicate identifiers are not allowed.' // @translate
                ));
            }
            return false;
        }

        // Don't try to fill id when resource has an error, but allow warnings.
        if ($resource['messageStore']->hasErrors()) {
            return false;
        }

        foreach (array_keys($idNames) as $identifierName) {
            // Get the list of identifiers from the resource metadata.
            $identifiers = [];
            if (!empty($resource[$identifierName])) {
                // Check if it is a property value.
                if (is_array($resource[$identifierName])) {
                    foreach ($resource[$identifierName] as $value) {
                        if (is_array($value)) {
                            // Check the different type of value. Only value is
                            // managed currently.
                            // TODO Check identifier that is not a property value.
                            if (isset($value['@value']) && strlen($value['@value'])) {
                                $identifiers[] = $value['@value'];
                            }
                        }
                    }
                } else {
                    // TODO Check identifier that is not a property.
                    $identifiers[] = $resource[$identifierName];
                }
            }

            if (!$identifiers) {
                continue;
            }

            $ids = $this->bulkIdentifiers->storeSourceIdentifiersIdsMore($identifiers, $identifierName, $resourceName, $resource);

            if (!$ids) {
                continue;
            }

            $flipped = array_flip($ids);
            if (count($flipped) > 1) {
                if ($this->allowDuplicateIdentifiers) {
                    $resource['messageStore']->addWarning('identifier', new PsrMessage(
                        'Resource doesn’t have a unique identifier. You may check options for resource identifiers.' // @translate
                    ));
                } else {
                    $resource['messageStore']->addError('identifier', new PsrMessage(
                        'Duplicate identifiers are not allowed. You may check options for resource identifiers.' // @translate
                    ));
                    break;
                }
            }

            $resource['o:id'] = reset($ids);
            $resource['checked_id'] = true;
            return true;
        }

        return false;
    }

    public function processResource(array $resource): ?AbstractEntityRepresentation
    {
        // TODO Complete output with representation.

        $this->indexResource = $resource['source_index'];
        $resource['messageStore'] = $resource['messageStore'] ?? new MessageStore();

        switch ($this->action) {
            case self::ACTION_SKIP:
                $this->skipEntity($resource);
                return null;
            case self::ACTION_CREATE:
                return $this->createEntity($resource);
            case self::ACTION_APPEND:
            case self::ACTION_REVISE:
            case self::ACTION_UPDATE:
            case self::ACTION_REPLACE:
                return $this->updateEntity($resource);
            case self::ACTION_DELETE:
                $this->deleteEntity($resource);
                return null;
            default:
                return null;
        }
    }

    /**
     * Do not process an entity.
     */
    protected function skipEntity(array $resource): ?array
    {
        return null;
    }

    protected function createEntity(array $resource): ?AbstractEntityRepresentation
    {
        // Linked ids from identifiers may be missing in data. So two solutions
        // to add missing ids: create resources one by one and add ids here, or
        // batch create and use an event to fill add ids.
        // In all cases, the ids should be stored for next resources.
        // The batch create in api adapter is more a loop than a bulk process.
        // The main difference is the automatic detachment of new entities,
        // instead of a clear. In doctrine 3, detachment will be removed.

        // So act as a loop.
        // Anyway, in most of the cases, the loop contains only one resource.

        // And in fact, there is no more loop, but a single resource.

        $defaultResourceName = $this->getResourceName();

        // Manage mixed resources.
        $resourceName = $resource['resource_name'] ?? $defaultResourceName;
        $resource = $this->bulkIdentifiers->completeResourceIdentifierIds($resource);

        // Remove uploaded files for items.
        foreach ($resource['o:media'] ?? [] as &$media) {
            if (($media['o:ingester'] ?? null )=== 'bulk' && ($media['ingest_ingester'] ?? null) === 'upload') {
                $media['ingest_delete_file'] = true;
            }
        }
        unset($media);

        try {
            $response = $this->bulk->api(null, true)
                ->create($resourceName, $resource);
        } catch (ValidationException $e) {
            $resource['messageStore']->addError('resource', new PsrMessage(
                'Error during validation of the data before creation.' // @translate
            ));
            $messages = $this->listValidationMessages($e);
            $resource['messageStore']->addError('resource', $messages);
            $this->bulkCheckLog->logCheckedResource($this->indexResource, $resource);
            ++$this->totalErrors;
            return null;
        } catch (\Exception $e) {
            $resource['messageStore']->addError('resource', new PsrMessage(
                'Core error during creation: {exception}', // @translate
                ['exception' => $e]
            ));
            $this->bulkCheckLog->logCheckedResource($this->indexResource, $resource);
            ++$this->totalErrors;
            return null;
        }

        $representation = $response->getContent();
        $this->bulkIdentifiers->storeSourceIdentifiersIds($resource, $representation);
        if ($representation->resourceName() === 'media') {
            $this->logger->notice(
                'Index #{index}: Created media #{media_id} (item #{item_id})', // @translate
                ['index' => $this->indexResource, 'media_id' => $representation->id(), 'item_id' => $representation->item()->id()]
            );
        } else {
            $this->logger->notice(
                'Index #{index}: Created {resource_name} #{resource_id}', // @translate
                ['index' => $this->indexResource, 'resource_name' => $this->easyMeta->resourceLabel($resourceName), 'resource_id' => $representation->id()]
            );
        }

        return $representation;
    }

    /**
     * Process update of entity.
     */
    protected function updateEntity(array $resource): ?AbstractEntityRepresentation
    {
        $defaultResourceName = $this->getResourceName();
        $resourceName = $resource['resource_name'] ?? $defaultResourceName;

        // Create resource if needed.
        if (empty($resource['o:id'])) {
            if ($this->actionUnidentified === self::ACTION_CREATE) {
                return $this->createEntity($resource);
            }
            // Normally already checked.
            $this->logger->warn(
                'Index #{index}: The {resource_name} has no id and cannot be updated.', // @translate
                ['index' => $this->indexResource, 'resource_name' => $this->easyMeta->resourceLabel($resourceName)]
            );
            return null;
        }

        // In the api manager, batchUpdate() allows to update a set of resources
        // with the same data. Here, data are specific to each entry, so each
        // resource is updated separately.

        // Clone is required to keep option to throw issue. The api plugin may
        // be used by other methods.
        $api = clone $this->bulk->api(null, true);

        $options = [];
        $fileData = [];

        // The "resources" cannot be updated directly.
        // Normally already checked.
        if (!$resourceName || $resourceName === 'resources') {
            $resource['messageStore']->addError('resource', new PsrMessage(
                'The resource id #"{id}" has no resource name.', // @translate
                ['id' => $resource['o:id']]
            ));
            $this->bulkCheckLog->logCheckedResource($this->indexResource, $resource);
            ++$this->totalErrors;
            return $this;
        }

        switch ($this->action) {
            case self::ACTION_APPEND:
            case self::ACTION_REPLACE:
                $options['isPartial'] = false;
                break;
            case self::ACTION_REVISE:
            case self::ACTION_UPDATE:
            case self::ACTION_SUB_UPDATE:
                $options['isPartial'] = true;
                $options['collectionAction'] = 'replace';
                break;
            default:
                return null;
        }

        if ($this->action !== self::ACTION_SUB_UPDATE) {
            $updatedResource = $resourceName === 'assets'
                ? $this->updateDataAsset($resource)
                : $this->updateResource->__invoke($resource, [
                    'action' => $this->action,
                    // In ResourceProcessor.
                    'actionItemSet' => $this->actionItemSet,
                    'actionMedia' => $this->actionMedia,
                    'actionIdentifier' => $this->actionIdentifier,
                    'identifierNames' => $this->identifierNames,
                    'metaMapping' => $this->metaMapper->getMetaMapping(),
                ]);
            if (!$updatedResource
                || (isset($updatedResource['messageStore']) && $updatedResource['messageStore']->hasErrors())
            ) {
                ++$this->totalErrors;
                return null;
            }
            $resource = $updatedResource;
        }

        // Remove uploaded files.
        foreach ($resource['o:media'] ?? [] as &$media) {
            if (($media['o:ingester'] ?? null )=== 'bulk' && ($media['ingest_ingester'] ?? null) === 'upload') {
                $media['ingest_delete_file'] = true;
            }
        }
        unset($media);

        try {
            $response = $api->update($resourceName, $resource['o:id'], $resource, $fileData, $options);
        } catch (ValidationException $e) {
            $resource['messageStore']->addError('resource', new PsrMessage(
                'Error during validation of the data before update.' // @translate
            ));
            $messages = $this->listValidationMessages($e);
            $resource['messageStore']->addError('resource', $messages);
            $this->bulkCheckLog->logCheckedResource($this->indexResource, $resource);
            ++$this->totalErrors;
            return null;
        } catch (\Exception $e) {
            $resource['messageStore']->addError('resource', new PsrMessage(
                'Core error during update: {exception}', // @translate
                ['exception' => $e]
            ));
            $this->bulkCheckLog->logCheckedResource($this->indexResource, $resource);
            ++$this->totalErrors;
            return null;
        }

        if (!$response) {
            $resource['messageStore']->addError('resource', new PsrMessage(
                'Unknown error occured during update.' // @translate
            ));
            ++$this->totalErrors;
            return null;
        }

        $representation = $response->getContent();

        if ($resourceName === 'assets') {
            $this->updateThumbnailForResources($resource);
            return $representation;
        }

        $this->logger->notice(
            'Index #{index}: Updated {resource_name} #{resource_id}', // @translate
            ['index' => $this->indexResource, 'resource_name' => $this->easyMeta->resourceLabel($resourceName), 'resource_id' => $resource['o:id']]
        );

        return $representation;
    }

    /**
     * Process deletion of an entity.
     */
    protected function deleteEntity(array $resource): ?AbstractEntityRepresentation
    {
        $resourceName = $resource['resource_name'] ?? 'resources';
        $id = $resource['o:id'];

        if (!$id) {
            // Normally already checked.
            $this->logger->warn(
                'Index #{index}: The {resource_name} has no id and cannot be deleted.', // @translate
                ['index' => $this->indexResource, 'resource_name' => $this->easyMeta->resourceLabel($resourceName)]
            );
            return null;
        }

        try {
            $this->bulk->api(null, true)
                ->delete($resourceName, $id)->getContent();
        } catch (ValidationException $e) {
            $resource['messageStore']->addError('resource', new PsrMessage(
                'Error during validation of the data before deletion.' // @translate
            ));
            $messages = $this->listValidationMessages($e);
            $resource['messageStore']->addError('resource', $messages);
            $this->bulkCheckLog->logCheckedResource($this->indexResource, $resource);
            ++$this->totalErrors;
            return null;
        } catch (\Exception $e) {
            // There is no error, only id already deleted, so continue.
            $resource['messageStore']->addWarning('resource', new PsrMessage(
                'Core error during deletion: {exception}', // @translate
                ['exception' => $e]
            ));
            $this->bulkCheckLog->logCheckedResource($this->indexResource, $resource);
            ++$this->totalErrors;
            return null;
        }

        $this->logger->notice(
            'Index #{index}: Deleted {resource_name} #{resource_id}', // @translate
            ['index' => $this->indexResource, 'resource_name' => $this->easyMeta->resourceLabel($resourceName), 'resource_id' => $id]
        );
        return null;
    }

    protected function extractIdentifiersAll(ArrayObject $resource): array
    {
        $result = [];
        foreach ($this->identifierNames as $identifierName) {
            if (empty($resource[$identifierName])) {
                continue;
            }
            if ($identifierName === 'o:display_title') {
                if (!empty($resource['o:display_title'])) {
                    $result[$identifierName][] = (string) $resource['o:display_title'];
                }
            } elseif ($this->easyMeta->propertyId($identifierName)) {
                foreach ($resource[$identifierName] as $value) {
                    if (!empty($value['@value'])) {
                        $result[$identifierName][] = (string) $value['@value'];
                    }
                }
            }
        }
        return $result;
    }

    protected function extractIdentifierCommon(ArrayObject $resource): ?string
    {
        if (!empty($resource['dcterms:identifier'][0]['@value'])) {
            return (string) $resource['dcterms:identifier'][0]['@value'];
        } elseif (!empty($resource['bibo:identifier'][0]['@value'])) {
            return (string) $resource['bibo:identifier'][0]['@value'];
        }
        return null;
    }

    protected function extractIdentifierTitle(ArrayObject $resource): ?string
    {
        if (!empty($resource['o:display_title'])) {
            return (string) $resource['o:display_title'];
        } elseif (!empty($resource['o:resource_template']['o:id'])) {
            $templateTitleId = $this->bulk->resourceTemplateTitleIds()[($resource['o:resource_template']['o:id'])] ?? null;
            if ($templateTitleId && !empty($resource[$templateTitleId][0]['@value'])) {
                return (string) $resource[$templateTitleId][0]['@value'];
            }
        }
        if (!empty($resource['dcterms:title'][0]['@value'])) {
            return (string) $resource['dcterms:title'][0]['@value'];
        } elseif (!empty($resource['foaf:name'][0]['@value'])) {
            return (string) $resource['foaf:name'][0]['@value'];
        } elseif (!empty($resource['skos:preferredLabel'][0]['@value'])) {
            return (string) $resource['skos:preferredLabel'][0]['@value'];
        }
        return null;
    }

    protected function standardOperation(string $action): ?string
    {
        $actionsToOperations = [
            self::ACTION_CREATE => \Omeka\Api\Request::CREATE,
            self::ACTION_APPEND => \Omeka\Api\Request::UPDATE,
            self::ACTION_REVISE => \Omeka\Api\Request::UPDATE,
            self::ACTION_UPDATE => \Omeka\Api\Request::UPDATE,
            self::ACTION_REPLACE => \Omeka\Api\Request::UPDATE,
            self::ACTION_DELETE => \Omeka\Api\Request::DELETE,
            self::ACTION_SKIP => null,
        ];
        return $actionsToOperations[$action] ?? null;
    }

    protected function actionRequiresId($action = null): bool
    {
        $actionsRequireId = [
            self::ACTION_APPEND,
            self::ACTION_REVISE,
            self::ACTION_UPDATE,
            self::ACTION_REPLACE,
            self::ACTION_DELETE,
        ];
        if (empty($action)) {
            $action = $this->action;
        }
        return in_array($action, $actionsRequireId);
    }

    protected function actionIsUpdate($action = null): bool
    {
        $actionsUpdate = [
            self::ACTION_APPEND,
            self::ACTION_REVISE,
            self::ACTION_UPDATE,
            self::ACTION_REPLACE,
        ];
        if (empty($action)) {
            $action = $this->action;
        }
        return in_array($action, $actionsUpdate);
    }

    protected function checkAdapter(string $resourceName, string $operation): bool
    {
        static $checks = [];
        if (!isset($checks[$resourceName][$operation])) {
            $adapter = $this->adapterManager->get($resourceName);
            $checks[$resourceName][$operation] = $this->acl->userIsAllowed($adapter, $operation);
        }
        return $checks[$resourceName][$operation];
    }

    protected function listValidationMessages(ValidationException $e): array
    {
        $messages = [];
        foreach ($e->getErrorStore()->getErrors() as $error) {
            foreach ($error as $message) {
                // Some messages can be nested.
                if (is_array($message)) {
                    $result = [];
                    array_walk_recursive($message, function ($v) use (&$result): void {
                        $result[] = $v;
                    });
                    $message = $result;
                    unset($result);
                } else {
                    $message = [$message];
                }
                $messages = array_merge($messages, array_values($message));
            }
        }
        return $messages;
    }

    /**
     * @todo Find a less quick and dirty way to get the field types.
     */
    protected function getFieldTypesForResource(?string $resourceName): array
    {
        static $fieldTypesForResource = [];

        $resourceName = $this->easyMeta->resourceName($resourceName);
        if (!$resourceName) {
            return [];
        }

        $resourceNamesToProcessor = [
            'assets' => AssetProcessor::class,
            'items' => ItemProcessor::class,
            'media' => MediaProcessor::class,
            'item_sets' => ItemSetProcessor::class,
            'resources' => ResourceProcessor::class,
        ];

        if (!isset($fieldTypesForResource[$resourceName])) {
            $specificProcessor = $resourceNamesToProcessor[$resourceName];
            $specificProcessor = new $specificProcessor($this->getServiceLocator());
            $fieldTypesForResource[$resourceName] = $specificProcessor->getFieldTypes();
        }

        return $fieldTypesForResource[$resourceName];
    }
}
