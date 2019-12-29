<?php
namespace BulkImport\Processor;

use ArrayObject;
use BulkImport\Interfaces\Configurable;
use BulkImport\Interfaces\Entry;
use BulkImport\Interfaces\Parametrizable;
use BulkImport\Traits\ConfigurableTrait;
use BulkImport\Traits\ParametrizableTrait;
use Omeka\Api\Exception\ValidationException;
use Zend\Form\Form;

abstract class AbstractResourceProcessor extends AbstractProcessor implements Configurable, Parametrizable
{
    use ConfigurableTrait, ParametrizableTrait;
    use ResourceUpdateTrait;

    /**
     * @var string
     */
    protected $resourceType;

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
     * @var string|int|array
     */
    protected $identifierNames;

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
    protected $hasMapping = false;

    /**
     * @var array
     */
    protected $mapping;

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

    public function getResourceType()
    {
        return $this->resourceType;
    }

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
        $this->handleFormSpecific($config, $values);
        $this->setConfig($config);
    }

    public function handleParamsForm(Form $form)
    {
        $values = $form->getData();
        $params = new ArrayObject;
        $this->handleFormGeneric($params, $values);
        $this->handleFormSpecific($params, $values);
        $params['mapping'] = isset($values['mapping']) ? $values['mapping'] : [];
        $this->setParams($params);
    }

    protected function handleFormGeneric(ArrayObject $args, array $values)
    {
        $defaults = [
            'o:resource_template' => null,
            'o:resource_class' => null,
            'o:owner' => null,
            'o:is_public' => null,
            'action' => null,
            'action_unidentified' => null,
            'identifier_name' => null,
            'allow_duplicate_identifiers' => false,
            'entries_by_batch' => null,
        ];
        $result = array_intersect_key($values, $defaults) + $args->getArrayCopy() + $defaults;
        $result['allow_duplicate_identifiers'] = (bool) $result['allow_duplicate_identifiers'];
        $args->exchangeArray($result);
    }

    protected function handleFormSpecific(ArrayObject $args, array $values)
    {
    }

    public function process()
    {
        $this->prepareAction();
        if (empty($this->action)) {
            return;
        }

        $this->prepareActionUnidentified();
        if (empty($this->actionUnidentified)) {
            return;
        }

        $this->prepareIdentifierNames();

        $this->prepareMapping();

        $this->setAllowDuplicateIdentifiers($this->getParam('allow_duplicate_identifiers'));

        $batch = (int) $this->getParam('entries_by_batch') ?: self::ENTRIES_BY_BATCH;

        $this->base = $this->baseEntity();

        $dataToProcess = [];
        foreach ($this->reader as $index => $entry) {
            if ($this->job->shouldStop()) {
                $this->logger->warn(
                    'Index #{index}: The job "Import" was stopped.', // @translate
                    ['index' => $this->indexResource]
                );
                break;
            }

            ++$this->totalIndexResources;
            // The first entry is #1, but the iterator (array) numbered it 0.
            $this->indexResource = $index + 1;
            $this->logger->info(
                'Index #{index}: Process started', // @translate
                ['index' => $this->indexResource]
            );

            $resource = $this->processEntry($entry);
            if (!$resource) {
                continue;
            }

            if ($this->checkEntity($resource)) {
                ++$this->processing;
                ++$this->totalProcessed;
                $dataToProcess[] = $resource->getArrayCopy();
            } else {
                ++$this->totalErrors;
            }

            // Only add every X for batch import.
            if ($this->processing >= $batch) {
                $this->processEntities($dataToProcess);
                $dataToProcess = [];
                $this->processing = 0;
            }
        }
        // Take care of remainder from the modulo check.
        $this->processEntities($dataToProcess);

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

    /**
     * Process one entry to create one resource (and eventually attached ones).
     *
     * @param Entry $entry
     * @return ArrayObject|null
     */
    protected function processEntry(Entry $entry)
    {
        if ($entry->isEmpty()) {
            $this->logger->warn(
                'Index #{index}: the entry is empty and is skipped.', // @translate
                ['index' => $this->indexResource]
            );
            ++$this->totalSkipped;
            return null;
        }

        return $this->hasMapping
            ? $this->processEntryWithMapping($entry)
            :  $this->processEntryDirectly($entry);
    }

    protected function processEntryDirectly(Entry $entry)
    {
        /** @var \ArrayObject $resource */
        $resource = clone $this->base;

        // Added for security.
        $skipKeys = [
            'checked_id' => null,
            'has_error' => null,
        ];

        // List of keys that can have only one value.
        // Cf. baseSpecific(), fillItem(), fillItemSet() and fillMedia().
        $singleKeys = [
            // Generic.
            'o:id' => null,
            'o:resource_template' => null,
            'o:resource_class' => null,
            'o:owner' => null,
            'o:is_public' => null,
            // Resource.
            'resource_type' => null,
            // Item.
            // 'o:item_set' => null,
            // 'o:media' => null,
            // Item set.
            'o:is_open' => null,
            // Media.
            'o:item' => null,
            'o:ingester' => null,
            'o:source' => null,
            'ingest_filename' => null,
            'html' => null,
        ];

        foreach ($entry as $key => $values) {
            if (array_key_exists($key, $skipKeys)) {
                continue;
            } elseif (array_key_exists($key, $singleKeys)) {
                $resource[$key] = $values;
            } elseif (array_key_exists($key, $resource) && is_array($resource[$key])) {
                $resource[$key] = array_merge($resource[$key], $values);
            } else {
                $resource[$key] = $values;
            }
        }

        return $resource;
    }

    protected function processEntryWithMapping(Entry $entry)
    {
        /** @var \ArrayObject $resource */
        $resource = clone $this->base;

        $this->skippedSourceFields = [];
        foreach ($this->mapping as $sourceField => $targets) {
            // Check if the entry has a value for this source field.
            if (!isset($entry[$sourceField])) {
                // Probably an issue in the config.
                if (!$entry->offsetExists($sourceField)) {
                    $this->logger->warn(
                        'Index #{index}: The source field "{field}" is set in the mapping, but not in the entry. The params may have an issue.', // @translate
                        ['index' => $this->indexResource, 'field' => $sourceField]
                    );
                }
                $this->skippedSourceFields[] = $sourceField;
                continue;
            }

            $values = $entry[$sourceField];
            if (!count($values)) {
                $this->skippedSourceFields[] = $sourceField;
                continue;
            }

            $this->fillResource($resource, $targets, $values);
        }

        return $resource;
    }

    protected function baseEntity()
    {
        // TODO Use a specific class that extends ArrayObject to manage process metadata (check and errors).
        $resource = new ArrayObject;
        $resource['o:id'] = null;
        $resource['checked_id'] = false;
        $resource['has_error'] = false;
        $this->baseGeneric($resource);
        $this->baseSpecific($resource);
        return $resource;
    }

    protected function baseGeneric(ArrayObject $resource)
    {
        $resourceTemplateId = $this->getParam('o:resource_template');
        if ($resourceTemplateId) {
            $resource['o:resource_template'] = ['o:id' => $resourceTemplateId];
        }
        $resourceClassId = $this->getParam('o:resource_class');
        if ($resourceClassId) {
            $resource['o:resource_class'] = ['o:id' => $resourceClassId];
        }
        $ownerId = $this->getParam('o:owner', 'current') ?: 'current';
        if ($ownerId === 'current') {
            $identity = $this->getServiceLocator()->get('ControllerPluginManager')
                ->get('identity');
            $ownerId = $identity()->getId();
        }
        $resource['o:owner'] = ['o:id' => $ownerId];
        $resource['o:is_public'] = $this->getParam('o:is_public') !== 'false';
    }

    protected function baseSpecific(ArrayObject $resource)
    {
    }

    protected function fillResource(ArrayObject $resource, array $targets, array $values)
    {
        foreach ($targets as $target) {
            switch ($target['target']) {
                case $this->fillProperty($resource, $target, $values):
                    break;
                case $this->fillGeneric($resource, $target, $values):
                    break;
                case $this->fillSpecific($resource, $target, $values):
                    break;
                default:
                    $resource[$target['target']] = array_pop($values);
                    break;
            }
        }
    }

    protected function fillProperty(ArrayObject $resource, $target, array $values)
    {
        if (!isset($target['value']['property_id'])) {
            return false;
        }

        foreach ($values as $value) {
            $resourceValue = $target['value'];
            switch ($resourceValue['type']) {
                // Currently, most of the datatypes are literal.
                case 'literal':
                // case strpos($resourceValue['type'], 'customvocab:') === 0:
                default:
                    $resourceValue['@value'] = $value;
                    break;
                case 'uri':
                case strpos($resourceValue['type'], 'valuesuggest:') === 0:
                    if (!empty($target['extra'])) {
                        switch ($target['extra']) {
                            case 'uri-label':
                                if (strpos($value, ' ')) {
                                    list($uri, $label) = explode(' ', $value, 2);
                                    $label = trim($label);
                                    if (!strlen($label)) {
                                        $label = null;
                                    }
                                } else {
                                    $uri = $value;
                                    $label = null;
                                }
                                $resourceValue['@id'] = $uri;
                                $resourceValue['o:label'] = $label;
                                break;
                        }
                    } else {
                        $resourceValue['@id'] = $value;
                        // $resourceValue['o:label'] = null;
                    }
                    break;
                case 'resource':
                case 'resource:item':
                case 'resource:itemset':
                case 'resource:media':
                    $resourceType = isset($resource['resource_type']) ? $resource['resource_type'] : null;
                    $id = $this->findResourceFromIdentifier($value, null, $resourceType);
                    if ($id) {
                        $resourceValue['value_resource_id'] = $id;
                        $resourceValue['@language'] = null;
                    } else {
                        $resource['has_error'] = true;
                        $this->logger->err(
                            'Index #{index}: Resource id for value "{value}" cannot be found: the entry is skipped.', // @translate
                            ['index' => $this->indexResource, 'value' => $value]
                        );
                    }
                    break;
            }
            $resource[$target['target']][] = $resourceValue;
        }
        return true;
    }

    protected function fillGeneric(ArrayObject $resource, $target, array $values)
    {
        switch ($target['target']) {
            case 'o:id':
                $value = (int) array_pop($values);
                if (!$value) {
                    return true;
                }
                $resourceType = isset($resource['resource_type']) ? $resource['resource_type'] : null;
                $id = $this->findResourceFromIdentifier($value, 'o:id', $resourceType);
                if ($id) {
                    $resource['o:id'] = $id;
                    $resource['checked_id'] = !empty($resourceType) && $resourceType !== 'resources';
                } else {
                    $resource['has_error'] = true;
                    $this->logger->err(
                        'Index #{index}: Internal id #{id} cannot be found: the entry is skipped.', // @translate
                        ['index' => $this->indexResource, 'id' => $id]
                    );
                }
                return true;
            case 'o:resource_template':
                $value = array_pop($values);
                $id = $this->getResourceTemplateId($value);
                if ($id) {
                    $resource['o:resource_template'] = ['o:id' => $id];
                }
                return true;
            case 'o:resource_class':
                $value = array_pop($values);
                $id = $this->getResourceClassId($value);
                if ($id) {
                    $resource['o:resource_class'] = ['o:id' => $id];
                }
                return true;
            case 'o:owner':
            case 'o:email':
                $value = array_pop($values);
                $id = $this->getUserId($value);
                if ($id) {
                    $resource['o:owner'] = ['o:id' => $id];
                }
                return true;
            case 'o:is_public':
                $value = array_pop($values);
                $resource['o:is_public'] = in_array(strtolower($value), ['false', 'no', 'off', 'private'])
                    ? false
                    : (bool) $value;
                return true;
        }
        return false;
    }

    protected function fillSpecific(ArrayObject $resource, $target, array $values)
    {
        return false;
    }

    /**
     * Check if a resource is well-formed.
     *
     * @param ArrayObject $resource
     * @return bool
     */
    protected function checkEntity(ArrayObject $resource)
    {
        if (!$this->checkId($resource)) {
            $this->fillId($resource);
        }

        if ($resource['o:id']) {
            if (!$this->actionRequiresId()) {
                if ($this->getAllowDuplicateIdentifiers()) {
                    // Action is create or skip.
                    if ($this->action === self::ACTION_CREATE) {
                        unset($resource['o:id']);
                    }
                } else {
                    $this->logger->err(
                        'Index #{index}: The action "{action}" requires a unique identifier.', // @translate
                        ['index' => $this->indexResource, 'action' => $this->action]
                    );
                    $resource['has_error'] = true;
                }
            }
        }
        // No resource id, so it is an error, so add message if choice is skip.
        elseif ($this->actionUnidentified === self::ACTION_SKIP) {
            if ($this->getAllowDuplicateIdentifiers()) {
                $this->logger->err(
                    'Index #{index}: The action "{action}" requires an identifier.', // @translate
                    ['index' => $this->indexResource, 'action' => $this->action]
                );
            } else {
                $this->logger->err(
                    'Index #{index}: The action "{action}" requires a unique identifier.', // @translate
                    ['index' => $this->indexResource, 'action' => $this->action]
                );
            }
            $resource['has_error'] = true;
        }

        return !$resource['has_error'];
    }

    /**
     * Process entities.
     *
     * @param array $data
     */
    protected function processEntities(array $data)
    {
        switch ($this->action) {
            case self::ACTION_CREATE:
                $this->createEntities($data);
                break;
            case self::ACTION_APPEND:
            case self::ACTION_REVISE:
            case self::ACTION_UPDATE:
            case self::ACTION_REPLACE:
                $this->updateEntities($data);
                break;
            case self::ACTION_SKIP:
                $this->skipEntities($data);
                break;
            case self::ACTION_DELETE:
                $this->deleteEntities($data);
                break;
        }
    }

    /**
     * Process creation of entities.
     *
     * @param array $data
     */
    protected function createEntities(array $data)
    {
        $resourceType = $this->getResourceType();
        $this->createResources($resourceType, $data);
    }

    /**
     * Process creation of resources.
     *
     * @param array $data
     */
    protected function createResources($resourceType, array $data)
    {
        if (!count($data)) {
            return;
        }

        try {
            if (count($data) === 1) {
                $response = $this->api(null, true)
                    ->create($resourceType, reset($data));
                $resource = $response->getContent();
                $resources = [$resource];
            } else {
                // TODO Clarify continuation on exception for batch.
                $resources = $this->api(null, true)
                    ->batchCreate($resourceType, $data, [], ['continueOnError' => true])->getContent();
            }
        } catch (ValidationException $e) {
            $messages = $this->listValidationMessages($e);
            $this->logger->err(
                "Index #{index}: Error during validation of the data before creation:\n{messages}", // @translate
                ['index' => $this->indexResource, 'messages' => implode("\n", $messages)]
            );
            ++$this->totalErrors;
            return;
        } catch (\Exception $e) {
            $this->logger->err(
                'Index #{index}: Core error during creation: {exception}', // @translate
                ['index' => $this->indexResource, 'exception' => $e]
            );
            ++$this->totalErrors;
            return;
        }

        foreach ($resources as $resource) {
            $this->logger->notice(
                'Index #{index}: Created {resource_type} #{resource_id}', // @translate
                ['index' => $this->indexResource, 'resource_type' => $this->label($resourceType), 'resource_id' => $resource->id()]
            );
        }
    }

    /**
     * Process update of entities.
     *
     * @param array $data
     */
    protected function updateEntities(array $data)
    {
        $resourceType = $this->getResourceType();

        $dataToCreateOrSkip = [];
        foreach ($data as $key => $value) {
            if (empty($value['o:id'])) {
                $dataToCreateOrSkip[] = $value;
                unset($data[$key]);
            }
        }
        if ($this->actionUnidentified === self::ACTION_CREATE) {
            $this->createResources($resourceType, $dataToCreateOrSkip);
        }

        $this->updateResources($resourceType, $data);
    }

    /**
     * Process update of resources.
     *
     * @param array $data
     */
    protected function updateResources($resourceType, array $data)
    {
        if (!count($data)) {
            return;
        }

        // In the api manager, batchUpdate() allows to update a set of resources
        // with the same data. Here, data are specific to each entry, so each
        // resource is updated separately.
        $options = [];
        $fileData = [];
        $api = $this->api(null, true);
        foreach ($data as $dataResource) {
            switch ($this->action) {
                case self::ACTION_APPEND:
                    $dataResource = $this->updateData($resourceType, $dataResource, $this->action);
                    $options['isPartial'] = false;
                    break;
                case self::ACTION_REVISE:
                case self::ACTION_UPDATE:
                    $dataResource = $this->updateData($resourceType, $dataResource, $this->action);
                    $options['isPartial'] = true;
                    $options['collectionAction'] = 'replace';
                    break;
                case self::ACTION_REPLACE:
                    $options['isPartial'] = false;
                    break;
            }

            try {
                $api->update($resourceType, $dataResource['o:id'], $dataResource, $fileData, $options);
                $this->logger->notice(
                    'Index #{index}: Updated {resource_type} #{resource_id}', // @translate
                    ['index' => $this->indexResource, 'resource_type' => $this->label($resourceType), 'resource_id' => $dataResource['o:id']]
                );
            } catch (ValidationException $e) {
                $messages = $this->listValidationMessages($e);
                $this->logger->err(
                    "Index #{index}: Error during validation of the data before update:\n{messages}", // @translate
                    ['index' => $this->indexResource, 'messages' => implode("\n", $messages)]
                );
                ++$this->totalErrors;
                return;
            } catch (\Exception $e) {
                $this->logger->err(
                    'Index #{index}: Core error during update: {exception}', // @translate
                    ['index' => $this->indexResource, 'exception' => $e]
                );
                ++$this->totalErrors;
                return;
            }
        }
    }

    /**
     * Process deletion of entities.
     *
     * @param array $data
     */
    protected function deleteEntities(array $data)
    {
        $resourceType = $this->getResourceType();
        $this->deleteResources($resourceType, $data);
    }

    /**
     * Process deletion of resources.
     *
     * @param array $data
     */
    protected function deleteResources($resourceType, array $data)
    {
        if (!count($data)) {
            return;
        }

        // Get ids (already checked normally).
        $ids = [];
        foreach ($data as $values) {
            if (isset($values['o:id'])) {
                $ids[] = $values['o:id'];
            }
        }

        try {
            if (count($ids) === 1) {
                $this->api(null, true)
                    ->delete($resourceType, reset($ids))->getContent();
            } else {
                $this->api(null, true)
                    ->batchDelete($resourceType, $ids, [], ['continueOnError' => true])->getContent();
            }
        } catch (ValidationException $e) {
            $messages = $this->listValidationMessages($e);
            $this->logger->err(
                "Index #{index}: Error during validation of the data before deletion:\n{messages}", // @translate
                ['index' => $this->indexResource, 'messages' => implode("\n", $messages)]
            );
            ++$this->totalErrors;
            return;
        } catch (\Exception $e) {
            // There is no error, only ids already deleted, so continue.
            $this->logger->err(
                'Index #{index}: Core error during deletion: {exception}', // @translate
                ['index' => $this->indexResource, 'exception' => $e]
            );
            ++$this->totalErrors;
            return;
        }

        foreach ($ids as $id) {
            $this->logger->notice(
                'Index #{index}: Deleted {resource_type} #{resource_id}', // @translate
                ['index' => $this->indexResource, 'resource_type' => $this->label($resourceType), 'resource_id' => $id]
            );
        }
    }

    /**
     * Process skipping of entities.
     *
     * @param array $data
     */
    protected function skipEntities(array $data)
    {
        $resourceType = $this->getResourceType();
        $this->skipResources($resourceType, $data);
    }

    /**
     * Process skipping of resources.
     *
     * @param array $data
     */
    protected function skipResources($resourceType, array $data)
    {
    }

    protected function actionRequiresId($action = null)
    {
        $actionsRequireId = [
            \BulkImport\Processor\AbstractProcessor::ACTION_APPEND,
            \BulkImport\Processor\AbstractProcessor::ACTION_REVISE,
            \BulkImport\Processor\AbstractProcessor::ACTION_UPDATE,
            \BulkImport\Processor\AbstractProcessor::ACTION_REPLACE,
            \BulkImport\Processor\AbstractProcessor::ACTION_DELETE,
        ];
        if (empty($action)) {
            $action = $this->action;
        }
        return in_array($action, $actionsRequireId);
    }

    protected function actionIsUpdate($action = null)
    {
        $actionsUpdate = [
            \BulkImport\Processor\AbstractProcessor::ACTION_APPEND,
            \BulkImport\Processor\AbstractProcessor::ACTION_REVISE,
            \BulkImport\Processor\AbstractProcessor::ACTION_UPDATE,
            \BulkImport\Processor\AbstractProcessor::ACTION_REPLACE,
        ];
        if (empty($action)) {
            $action = $this->action;
        }
        return in_array($action, $actionsUpdate);
    }

    protected function prepareAction()
    {
        $this->action = $this->getParam('action') ?: self::ACTION_CREATE;
        if (!in_array($this->action, [
            \BulkImport\Processor\AbstractProcessor::ACTION_CREATE,
            \BulkImport\Processor\AbstractProcessor::ACTION_APPEND,
            \BulkImport\Processor\AbstractProcessor::ACTION_REVISE,
            \BulkImport\Processor\AbstractProcessor::ACTION_UPDATE,
            \BulkImport\Processor\AbstractProcessor::ACTION_REPLACE,
            \BulkImport\Processor\AbstractProcessor::ACTION_DELETE,
            \BulkImport\Processor\AbstractProcessor::ACTION_SKIP,
        ])) {
            $this->logger->err(
                'Action "{action}" is not managed.', // @translate
                ['action' => $this->action]
            );
        }
    }

    protected function prepareActionUnidentified()
    {
        $this->actionUnidentified = $this->getParam('action_unidentified') ?: self::ACTION_SKIP;
        if (!in_array($this->actionUnidentified, [
            \BulkImport\Processor\AbstractProcessor::ACTION_CREATE,
            \BulkImport\Processor\AbstractProcessor::ACTION_SKIP,
        ])) {
            $this->logger->err(
                'Action "{action}" for unidentified resource is not managed.', // @translate
                ['action' => $this->actionUnidentified]
            );
        }
    }

    protected function prepareIdentifierNames()
    {
        $this->identifierNames = [];
        $identifierNames = $this->getParam('identifier_name', ['o:id', 'dcterms:identifier']);
        if (empty($identifierNames)) {
            $this->logger->warn(
                'No identifier name was selected.' // @translate
            );
            return;
        }

        if (!is_array($identifierNames)) {
            $identifierNames = [$identifierNames];
        }

        // For quicker search, prepare the ids of the properties.
        foreach ($identifierNames as $identifierName) {
            $id = $this->getPropertyId($identifierName);
            if ($id) {
                $this->identifierNames[$this->getPropertyTerm($id)] = $id;
            } else {
                $this->identifierNames[$identifierName] = $identifierName;
            }
        }
        $this->identifierNames = array_filter($this->identifierNames);
        if (empty($this->identifierNames)) {
            $this->logger->err(
                'Invalid identifier names: check your params.' // @translate
            );
        }
    }

    /**
     * Prepare full mapping to simplify process.
     *
     * Add automapped metadata for properties (language and datatype).
     */
    protected function prepareMapping()
    {
        $mapping = $this->getParam('mapping', []);

        $automapFields = $this->getServiceLocator()->get('ViewHelperManager')->get('automapFields');
        $sourceFields = $automapFields(array_keys($mapping), ['output_full_matches' => true]);
        $index = -1;
        foreach ($mapping as $sourceField => $targets) {
            ++$index;
            if (empty($targets)) {
                continue;
            }

            // Default metadata (type and language). For consistency, only the
            // first metadata is used.
            $metadatas = $sourceFields[$index];
            $metadata = reset($metadatas);

            $fullTargets = [];
            foreach ($targets as $target) {
                $result = [];
                $result['field'] = $metadata['field'];

                // Manage the property of a target when it is a resource type,
                // like "o:item_set [dcterms:title]".
                // It is used to set a metadata for derived resource (media for
                // item) or to find another resource (item set for item, as an
                // identifier name).
                $pos = strpos($target, '[');
                if ($pos) {
                    $targetData = trim(substr($target, $pos + 1), '[] ');
                    $target = trim(substr($target, $pos));
                    $result['target'] = $target;
                    $result['target_data'] = $targetData;
                    $propertyId = $this->getPropertyId($targetData);
                    if ($propertyId) {
                        $subValue = [];
                        $subValue['property_id'] = $propertyId;
                        // TODO Allow different types for subvalues (inside "[]").
                        $subValue['type'] = 'literal';
                        $subValue['is_public'] = true;
                        $result['target_data_value'] = $subValue;
                    }
                } else {
                    $result['target'] = $target;
                }

                $propertyId = $this->getPropertyId($target);
                if ($propertyId) {
                    $result['value']['property_id'] = $propertyId;
                    // Manage data type exceptions.
                    switch ($metadata['type']) {
                        case 'uri-label':
                            $result['value']['type'] = 'uri';
                            $result['extra'] = 'uri-label';
                            break;
                        default:
                            $result['value']['type'] = $this->getDataType($metadata['type']) ?: 'literal';
                            break;
                    }
                    $result['value']['@language'] = $metadata['@language'];
                    $result['value']['is_public'] = true;
                } else {
                    $result['@language'] = $metadata['@language'];
                    $result['type'] = $metadata['type'];
                }

                $fullTargets[] = $result;
            }
            $mapping[$sourceField] = $fullTargets;
        }

        // Filter the mapping to avoid to loop entries without target.
        $this->mapping = array_filter($mapping);
        // Some readers don't need a mapping (xml reader do the process itself).
        $this->hasMapping = (bool) $this->mapping;
    }
}
