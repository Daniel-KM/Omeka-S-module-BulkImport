<?php declare(strict_types=1);

namespace BulkImport\Processor;

use ArrayObject;
use BulkImport\Interfaces\Configurable;
use BulkImport\Interfaces\Entry;
use BulkImport\Interfaces\Parametrizable;
use BulkImport\Traits\ConfigurableTrait;
use BulkImport\Traits\ParametrizableTrait;
use Laminas\Form\Form;
use Omeka\Api\Exception\ValidationException;

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
     * @var string
     */
    protected $actionIdentifier;

    /**
     * @var string
     */
    protected $actionMedia;

    /**
     * @var string
     */
    protected $actionItemSet;

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

    public function getResourceType(): ?string
    {
        return $this->resourceType;
    }

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
        $config = new ArrayObject;
        $this->handleFormGeneric($config, $values);
        $this->handleFormSpecific($config, $values);
        $this->setConfig($config->getArrayCopy());
        return $this;
    }

    public function handleParamsForm(Form $form)
    {
        $values = $form->getData();
        $params = new ArrayObject;
        $this->handleFormGeneric($params, $values);
        $this->handleFormSpecific($params, $values);
        $params['mapping'] = $values['mapping'] ?? [];
        $this->setParams($params->getArrayCopy());
        return $this;
    }

    protected function handleFormGeneric(ArrayObject $args, array $values): \BulkImport\Interfaces\Processor
    {
        $defaults = [
            'o:resource_template' => null,
            'o:resource_class' => null,
            'o:owner' => null,
            'o:is_public' => null,
            'action' => null,
            'action_unidentified' => null,
            'identifier_name' => null,
            'action_identifier_update' => null,
            'action_media_update' => null,
            'action_item_set_update' => null,
            'allow_duplicate_identifiers' => false,
            'entries_to_skip' => 0,
            'entries_by_batch' => null,
        ];

        $result = array_intersect_key($values, $defaults) + $args->getArrayCopy() + $defaults;
        $result['allow_duplicate_identifiers'] = (bool) $result['allow_duplicate_identifiers'];
        $args->exchangeArray($result);
        return $this;
    }

    protected function handleFormSpecific(ArrayObject $args, array $values): \BulkImport\Interfaces\Processor
    {
        return $this;
    }

    public function process(): void
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

        $this->prepareActionIdentifier();
        $this->prepareActionMedia();
        $this->prepareActionItemSet();

        $this->appendInternalParams();

        $this->prepareMapping();

        $this->setAllowDuplicateIdentifiers($this->getParam('allow_duplicate_identifiers', false));

        $toSkip = $this->getParam('entries_to_skip', 0);
        if ($toSkip) {
            $this->logger->notice(
                'The first {skip} entries are skipped by user.', // @translate
                ['skip' => $toSkip]
            );
        }

        $batch = (int) $this->getParam('entries_by_batch', self::ENTRIES_BY_BATCH);

        $this->base = $this->baseEntity();

        /** @var \Doctrine\ORM\EntityManager $entityManager */
        $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');

        $shouldStop = false;
        $dataToProcess = [];
        foreach ($this->reader as $index => $entry) {
            if ($shouldStop = $this->job->shouldStop()) {
                $this->logger->warn(
                    'Index #{index}: The job "Import" was stopped.', // @translate
                    ['index' => $this->indexResource]
                );
                break;
            }

            if ($toSkip) {
                --$toSkip;
                continue;
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
                // Avoid memory issue.
                unset($dataToProcess);
                $entityManager->flush();
                $entityManager->clear();
                // Reset for next batch.
                $dataToProcess = [];
                $this->processing = 0;
            }
        }

        // Take care of remainder from the modulo check.
        if (!$shouldStop && $dataToProcess) {
            $this->processEntities($dataToProcess);
            // Avoid memory issue.
            unset($dataToProcess);
            $entityManager->flush();
            $entityManager->clear();
        }

        $this->logger->notice(
            'End of process: {total_resources} resources to process, {total_skipped} skipped or blank, {total_processed} processed, {total_errors} errors inside data. Note: errors can occur separately for each imported file.', // @translate
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
     */
    protected function processEntry(Entry $entry): ?ArrayObject
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
            : $this->processEntryDirectly($entry);
    }

    /**
     * Convert a prepared entry into a resource, setting ids for each key.
     *
     * So fill owner id, resource template id, resource class id, property ids.
     * Check boolean values too for is public and is open.
     */
    protected function processEntryDirectly(Entry $entry): ArrayObject
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
        $booleanKeys = [
            'o:is_public' => true,
            'o:is_open' => true,
        ];

        $singleDataKeys = [
            // Generic.
            'o:id' => null,
            // Resource.
            'resource_type' => null,
            // Media.
            'o:ingester' => null,
            'o:source' => null,
            'ingest_filename' => null,
            'html' => null,
        ];

        // Keys that can have only one value that is an entity with an id.
        $singleEntityKeys = [
            // Generic.
            'o:resource_template' => null,
            'o:resource_class' => null,
            'o:owner' => null,
            // Media.
            'o:item' => null,
        ];

        foreach ($entry as $key => $values) {
            if (array_key_exists($key, $skipKeys)) {
                // Nothing to do.
            } elseif (array_key_exists($key, $booleanKeys)) {
                $this->fillBoolean($resource, $key, $values);
            } elseif (array_key_exists($key, $singleDataKeys)) {
                $resource[$key] = $values;
            } elseif (array_key_exists($key, $singleEntityKeys)) {
                $this->fillSingleEntity($resource, $key, $values);
            } elseif ($resource->offsetExists($key) && is_array($resource[$key])) {
                $resource[$key] = array_merge($resource[$key], $values);
            } else {
                // Keep multiple entity keys (below) and extra data for modules.
                $resource[$key] = $values;
            }
        }

        // Clean the property id in all cases.
        $properties = $this->getPropertyIds();
        foreach (array_intersect_key($resource->getArrayCopy(), $properties) as $term => $values) {
            foreach (array_keys($values) as $key) {
                $resource[$term][$key]['property_id'] = $properties[$term];
            }
        }

        $fillPropertiesAndResourceData = function (array $resourceArray) use ($properties, $booleanKeys, $singleEntityKeys): array {
            // Fill the properties.
            foreach (array_intersect_key($resourceArray, $properties) as $term => $values) {
                foreach (array_keys($values) as $key) {
                    $resourceArray[$term][$key]['property_id'] = $properties[$term];
                }
            }
            // Fill other metadata (for media and item set).
            $resourceObject = new ArrayObject($resourceArray);
            foreach (array_keys($booleanKeys) as $key) {
                if (array_key_exists($key, $resourceArray)) {
                    $this->fillBoolean($resourceObject, $key, $resourceObject[$key]);
                }
            }
            foreach (array_keys($singleEntityKeys) as $key) {
                if (array_key_exists($key, $resourceArray)) {
                    $this->fillSingleEntity($resourceObject, $key, $resourceObject[$key]);
                }
            }
            return $resourceObject->getArrayCopy();
        };

        // Do the same for sub-resources (multiple entity keys: ''o:media" and
        // "o:item_set" for items).
        foreach (['o:item_set', 'o:media'] as $key) {
            if (!empty($resource[$key])) {
                foreach ($resource[$key] as &$resourceData) {
                    $resourceData = $fillPropertiesAndResourceData($resourceData);
                }
            }
        }

        return $resource;
    }

    protected function processEntryWithMapping(Entry $entry): ArrayObject
    {
        /** @var \ArrayObject $resource */
        $resource = clone $this->base;

        $this->skippedSourceFields = [];
        foreach ($this->mapping as $sourceField => $targets) {
            // Check if the entry has a value for this source field.
            if (!isset($entry[$sourceField])) {
                // Probably an issue in the config.
                /*
                // TODO Warn when it is not a multisheet. Check updates with a multisheet.
                if (!$entry->offsetExists($sourceField)) {
                    $this->logger->warn(
                        'Index #{index}: The source field "{field}" is set in the mapping, but not in the entry. The params may have an issue.', // @translate
                        ['index' => $this->indexResource, 'field' => $sourceField]
                    );
                }
                */
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

    protected function baseEntity(): ArrayObject
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

    protected function baseGeneric(ArrayObject $resource): \BulkImport\Interfaces\Processor
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
        return $this;
    }

    protected function baseSpecific(ArrayObject $resource): \BulkImport\Interfaces\Processor
    {
        return $this;
    }

    protected function fillResource(ArrayObject $resource, array $targets, array $values): \BulkImport\Interfaces\Processor
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
        return $this;
    }

    protected function fillProperty(ArrayObject $resource, $target, array $values): bool
    {
        if (!isset($target['value']['property_id'])) {
            return false;
        }

        foreach ($values as $value) {
            $resourceValue = $target['value'];
            switch ($resourceValue['type']) {
                default:
                // Currently, most of the datatypes are literal.
                case 'literal':
                // case strpos($resourceValue['type'], 'customvocab:') === 0:
                    $resourceValue['@value'] = $value;
                    break;
                case 'uri-label':
                    // Deprecated.
                case 'uri':
                case substr($resourceValue['type'], 0, 12) === 'valuesuggest':
                    if (strpos($value, ' ')) {
                        list($uri, $label) = explode(' ', $value, 2);
                        $label = trim($label);
                        if (!strlen($label)) {
                            $label = null;
                        }
                        $resourceValue['@id'] = $uri;
                        $resourceValue['o:label'] = $label;
                    } else {
                        $resourceValue['@id'] = $value;
                        // $resourceValue['o:label'] = null;
                    }
                    break;
                case 'resource':
                case 'resource:item':
                case 'resource:itemset':
                case 'resource:media':
                    $id = $this->findResourceFromIdentifier($value, null, $resourceValue['type']);
                    if ($id) {
                        $resourceValue['value_resource_id'] = $id;
                        $resourceValue['@language'] = null;
                    } else {
                        $resource['has_error'] = true;
                        $this->logger->err(
                            'Index #{index}: Resource id for value "{value}" cannot be found. The entry is skipped.', // @translate
                            ['index' => $this->indexResource, 'value' => $value]
                        );
                    }
                    break;
            }
            $resource[$target['target']][] = $resourceValue;
        }
        return true;
    }

    protected function fillGeneric(ArrayObject $resource, $target, array $values): bool
    {
        switch ($target['target']) {
            case 'o:id':
                $value = (int) array_pop($values);
                if (!$value) {
                    return true;
                }
                $resourceType = $resource['resource_type'] ?? null;
                $id = $this->findResourceFromIdentifier($value, 'o:id', $resourceType);
                if ($id) {
                    $resource['o:id'] = $id;
                    $resource['checked_id'] = !empty($resourceType) && $resourceType !== 'resources';
                } else {
                    $resource['has_error'] = true;
                    $this->logger->err(
                        'Index #{index}: Internal id #{id} cannot be found. The entry is skipped.', // @translate
                        ['index' => $this->indexResource, 'id' => $id]
                    );
                }
                return true;

            case 'o:resource_template':
                $value = array_pop($values);
                if (!$value) {
                    return true;
                }
                if (is_array($value)) {
                    $id = empty($value['o:id']) ? null : $value['o:id'];
                    $label = empty($value['o:label']) ? null : $value['o:label'];
                    $value = $id ?? $label ?? reset($value);
                }
                $id = $this->getResourceTemplateId($value);
                if ($id) {
                    $resource['o:resource_template'] = empty($label)
                        ? ['o:id' => $id]
                        : ['o:id' => $id, 'o:label' => $label];
                } else {
                    $this->logger->warn(
                        'Index #{index}: The resource template "{source}" does not exist.', // @translate
                        ['index' => $this->indexResource, 'source' => $value]
                    );
                }
                return true;

            case 'o:resource_class':
                $value = array_pop($values);
                if (!$value) {
                    return true;
                }
                if (is_array($value)) {
                    $id = empty($value['o:id']) ? null : $value['o:id'];
                    $term = empty($value['o:term']) ? null : $value['o:term'];
                    $value = $id ?? $term ?? reset($value);
                }
                $id = $this->getResourceClassId($value);
                if ($id) {
                    $resource['o:resource_class'] = empty($term)
                        ? ['o:id' => $id]
                        : ['o:id' => $id, 'o:term' => $term];
                } else {
                    $this->logger->warn(
                        'Index #{index}: The resource class "{source}" does not exist.', // @translate
                        ['index' => $this->indexResource, 'source' => $value]
                    );
                }
                return true;

            case 'o:owner':
                $value = array_pop($values);
                if (!$value) {
                    return true;
                }
                if (is_array($value)) {
                    $id = empty($value['o:id']) ? null : $value['o:id'];
                    $email = empty($value['o:email']) ? null : $value['o:email'];
                    $value = $id ?? $email ?? reset($value);
                }
                $id = $this->getUserId($value);
                if ($id) {
                    $resource['o:owner'] = empty($email)
                        ? ['o:id' => $id]
                        : ['o:id' => $id, 'o:email' => $email];
                } else {
                    $this->logger->warn(
                        'Index #{index}: The user "{source}" does not exist.', // @translate
                        ['index' => $this->indexResource, 'source' => $value]
                    );
                }
                return true;

            case 'o:email':
                $value = array_pop($values);
                if (!$value) {
                    return true;
                }
                $id = $this->getUserId($value);
                if ($id) {
                    $resource['o:owner'] = ['o:id' => $id, 'o:email' => $value];
                } else {
                    $this->logger->warn(
                        'Index #{index}: The user "{source}" does not exist.', // @translate
                        ['index' => $this->indexResource, 'source' => $value]
                    );
                }
                return true;

            case 'o:is_public':
                $value = (string) array_pop($values);
                $resource['o:is_public'] = in_array(strtolower((string) $value), ['0', 'false', 'no', 'off', 'private'], true)
                    ? false
                    : (bool) $value;
                return true;

            case 'o:created':
            case 'o:modified':
                $value = array_pop($values);
                $resource[$target['target']] = is_array($value)
                    ? $value
                    : ['@value' => substr_replace('0000-00-00 00:00:00', $value, 0, strlen($value))];
                return true;

            default:
                return false;
        }
        return false;
    }

    protected function fillSpecific(ArrayObject $resource, $target, array $values): bool
    {
        return false;
    }

    protected function fillBoolean(ArrayObject $resource, $key, $value): void
    {
        $resource[$key] = in_array(strtolower((string) $value), ['0', 'false', 'no', 'off', 'private', 'closed'], true)
            ? false
            : (bool) $value;
    }

    protected function fillSingleEntity(ArrayObject $resource, $key, $value): void
    {
        if (empty($value)) {
            $resource[$key] = null;
            return;
        }

        // Get the entity id.
        switch ($key) {
            case 'o:resource_template':
                if (is_array($value)) {
                    $id = empty($value['o:id']) ? null : $value['o:id'];
                    $label = empty($value['o:label']) ? null : $value['o:label'];
                    $value = $id ?? $label ?? reset($value);
                }
                $id = $this->getResourceTemplateId($value);
                if ($id) {
                    $resource['o:resource_template'] = empty($label)
                        ? ['o:id' => $id]
                        : ['o:id' => $id, 'o:label' => $label];
                } else {
                    $resource['o:resource_template'] = null;
                    $this->logger->warn(
                        'Index #{index}: The resource template "{source}" does not exist.', // @translate
                        ['index' => $this->indexResource, 'source' => $value]
                    );
                }
                return;

            case 'o:resource_class':
                if (is_array($value)) {
                    $id = empty($value['o:id']) ? null : $value['o:id'];
                    $term = empty($value['o:term']) ? null : $value['o:term'];
                    $value = $id ?? $term ?? reset($value);
                }
                $id = $this->getResourceClassId($value);
                if ($id) {
                    $resource['o:resource_class'] = empty($term)
                        ? ['o:id' => $id]
                        : ['o:id' => $id, 'o:term' => $term];
                } else {
                    $resource['o:resource_class'] = null;
                    $this->logger->warn(
                        'Index #{index}: The resource class "{source}" does not exist.', // @translate
                        ['index' => $this->indexResource, 'source' => $value]
                    );
                }
                return;

            case 'o:owner':
                if (is_array($value)) {
                    $id = empty($value['o:id']) ? null : $value['o:id'];
                    $email = empty($value['o:email']) ? null : $value['o:email'];
                    $value = $id ?? $email ?? reset($value);
                }
                $id = $this->getUserId($value);
                if ($id) {
                    $resource['o:owner'] = empty($email)
                        ? ['o:id' => $id]
                        : ['o:id' => $id, 'o:email' => $email];
                } else {
                    $resource['o:owner'] = null;
                    $this->logger->warn(
                        'Index #{index}: The user "{source}" does not exist.', // @translate
                        ['index' => $this->indexResource, 'source' => $value]
                    );
                }
                return;

            case 'o:item':
                // For media.
                if (is_array($value)) {
                    $id = empty($value['o:id']) ? null : $value['o:id'];
                    $value = $id ?? reset($value);
                }
                $id = $this->findResourceFromIdentifier($value);
                if ($id) {
                    $resource['o:item'] = ['o:id' => $id];
                } else {
                    $resource['o:item'] = null;
                    $this->logger->warn(
                        'Index #{index}: The item "{source}" for media does not exist.', // @translate
                        ['index' => $this->indexResource, 'source' => $value]
                    );
                }
                return;

            default:
                return;
        }
    }

    /**
     * Check if a resource is well-formed.
     */
    protected function checkEntity(ArrayObject $resource): bool
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
                    $identifier = $this->extractIdentifierOrTitle($resource);
                    $this->logger->err(
                        'Index #{index}: The action "{action}" requires a unique identifier ({identifier}, #{resource_id}).', // @translate
                        ['index' => $this->indexResource, 'action' => $this->action, 'identifier' => $identifier, 'resource_id' => $resource['o:id']]
                    );
                    $resource['has_error'] = true;
                }
            }
        }
        // No resource id, so it is an error, so add message if choice is skip.
        elseif ($this->actionRequiresId() && $this->actionUnidentified === self::ACTION_SKIP) {
            $identifier = $this->extractIdentifierOrTitle($resource);
            if ($this->getAllowDuplicateIdentifiers()) {
                $this->logger->err(
                    'Index #{index}: The action "{action}" requires an identifier ({identifier}).', // @translate
                    ['index' => $this->indexResource, 'action' => $this->action, 'identifier' => $identifier]
                );
            } else {
                $this->logger->err(
                    'Index #{index}: The action "{action}" requires a unique identifier ({identifier}).', // @translate
                    ['index' => $this->indexResource, 'action' => $this->action, 'identifier' => $identifier]
                );
            }
            $resource['has_error'] = true;
        }

        return !$resource['has_error'];
    }

    /**
     * Process entities.
     */
    protected function processEntities(array $data): \BulkImport\Interfaces\Processor
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
        return $this;
    }

    /**
     * Process creation of entities.
     */
    protected function createEntities(array $data): \BulkImport\Interfaces\Processor
    {
        $resourceType = $this->getResourceType();
        $this->createResources($resourceType, $data);
        return $this;
    }

    /**
     * Process creation of resources.
     */
    protected function createResources($resourceType, array $data): \BulkImport\Interfaces\Processor
    {
        if (!count($data)) {
            return $this;
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
            return $this;
        } catch (\Exception $e) {
            $this->logger->err(
                'Index #{index}: Core error during creation: {exception}', // @translate
                ['index' => $this->indexResource, 'exception' => $e]
            );
            ++$this->totalErrors;
            return $this;
        }

        /** @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation[] $resources */
        foreach ($resources as $resource) {
            if ($resource->resourceName() === 'media') {
                $this->logger->info(
                    'Index #{index}: Created media #{media_id} (item #{item_id})', // @translate
                    ['index' => $this->indexResource, 'media_id' => $resource->id(), 'item_id' => $resource->item()->id()]
                );
            } else {
                $this->logger->info(
                    'Index #{index}: Created {resource_type} #{resource_id}', // @translate
                    ['index' => $this->indexResource, 'resource_type' => $this->label($resourceType), 'resource_id' => $resource->id()]
                );
            }
        }

        $this->recordCreatedResources($resources);

        return $this;
    }

    /**
     * Process update of entities.
     */
    protected function updateEntities(array $data): \BulkImport\Interfaces\Processor
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
        return $this;
    }

    /**
     * Process update of resources.
     */
    protected function updateResources($resourceType, array $data): \BulkImport\Interfaces\Processor
    {
        if (!count($data)) {
            return $this;
        }

        // In the api manager, batchUpdate() allows to update a set of resources
        // with the same data. Here, data are specific to each entry, so each
        // resource is updated separately.

        // Clone is required to keep option to throw issue. The api plugin may
        // be used by other methods.
        $api = clone $this->api(null, true);
        foreach ($data as $dataResource) {
            $options = [];
            $fileData = [];

            switch ($this->action) {
                case self::ACTION_APPEND:
                case self::ACTION_REPLACE:
                    $options['isPartial'] = false;
                    break;
                case self::ACTION_REVISE:
                case self::ACTION_UPDATE:
                    $options['isPartial'] = true;
                    $options['collectionAction'] = 'replace';
                    break;
                default:
                    return $this;
            }
            $dataResource = $this->updateData($resourceType, $dataResource);

            try {
                $response = $api->update($resourceType, $dataResource['o:id'], $dataResource, $fileData, $options);
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
                return $this;
            } catch (\Exception $e) {
                $this->logger->err(
                    'Index #{index}: Core error during update: {exception}', // @translate
                    ['index' => $this->indexResource, 'exception' => $e]
                );
                ++$this->totalErrors;
                return $this;
            }
            if (!$response) {
                $this->logger->err(
                    'Index #{index}: Unknown error occured during update.', // @translate
                    ['index' => $this->indexResource]
                );
                ++$this->totalErrors;
                return $this;
            }
        }

        return $this;
    }

    /**
     * Process deletion of entities.
     */
    protected function deleteEntities(array $data): \BulkImport\Interfaces\Processor
    {
        $resourceType = $this->getResourceType();
        $this->deleteResources($resourceType, $data);
        return $this;
    }

    /**
     * Process deletion of resources.
     */
    protected function deleteResources($resourceType, array $data): \BulkImport\Interfaces\Processor
    {
        if (!count($data)) {
            return $this;
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
            return $this;
        } catch (\Exception $e) {
            // There is no error, only ids already deleted, so continue.
            $this->logger->err(
                'Index #{index}: Core error during deletion: {exception}', // @translate
                ['index' => $this->indexResource, 'exception' => $e]
            );
            ++$this->totalErrors;
            return $this;
        }

        foreach ($ids as $id) {
            $this->logger->notice(
                'Index #{index}: Deleted {resource_type} #{resource_id}', // @translate
                ['index' => $this->indexResource, 'resource_type' => $this->label($resourceType), 'resource_id' => $id]
            );
        }
        return $this;
    }

    /**
     * Process skipping of entities.
     */
    protected function skipEntities(array $data): \BulkImport\Interfaces\Processor
    {
        $resourceType = $this->getResourceType();
        $this->skipResources($resourceType, $data);
        return $this;
    }

    /**
     * Process skipping of resources.
     */
    protected function skipResources($resourceType, array $data): \BulkImport\Interfaces\Processor
    {
        return $this;
    }

    protected function extractIdentifierOrTitle(ArrayObject $resource): ?string
    {
        if (!empty($resource['dcterms:identifier'][0]['@value'])) {
            return (string) $resource['dcterms:identifier'][0]['@value'];
        }
        if (!empty($resource['o:display_title'])) {
            return (string) $resource['o:display_title'];
        }
        if (!empty($resource['dcterms:title'][0]['@value'])) {
            return (string) $resource['dcterms:title'][0]['@value'];
        }
        return null;
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

    protected function prepareAction(): \BulkImport\Interfaces\Processor
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
            $this->logger->err(
                'Action "{action}" is not managed.', // @translate
                ['action' => $this->action]
            );
        }
        return $this;
    }

    protected function prepareActionUnidentified(): \BulkImport\Interfaces\Processor
    {
        $this->actionUnidentified = $this->getParam('action_unidentified') ?: self::ACTION_SKIP;
        if (!in_array($this->actionUnidentified, [
            self::ACTION_CREATE,
            self::ACTION_SKIP,
        ])) {
            $this->logger->err(
                'Action "{action}" for unidentified resource is not managed.', // @translate
                ['action' => $this->actionUnidentified]
            );
        }
        return $this;
    }

    protected function prepareIdentifierNames(): \BulkImport\Interfaces\Processor
    {
        $identifierNames = $this->getParam('identifier_name', ['dcterms:identifier']);
        if (empty($identifierNames)) {
            $this->bulk->setIdentifierNames([]);
            $this->logger->warn(
                'No identifier name was selected.' // @translate
            );
            return $this;
        }

        if (!is_array($identifierNames)) {
            $identifierNames = [$identifierNames];
        }

        // For quicker search, prepare the ids of the properties.
        $result = [];
        foreach ($identifierNames as $identifierName) {
            $id = $this->getPropertyId($identifierName);
            if ($id) {
                $result[$this->getPropertyTerm($id)] = $id;
            } else {
                $result[$identifierName] = $identifierName;
            }
        }
        $result = array_filter($result);
        if (empty($result)) {
            $this->logger->err(
                'Invalid identifier names: check your params.' // @translate
            );
        }
        $this->bulk->setIdentifierNames($result);
        return $this;
    }

    protected function prepareActionIdentifier(): \BulkImport\Interfaces\Processor
    {
        if (!in_array($this->action, [
            self::ACTION_REVISE,
            self::ACTION_UPDATE,
        ])) {
            $this->actionIdentifier = self::ACTION_SKIP;
            return $this;
        }

        // This option doesn't apply when "o:id" is the only one identifier.
        $identifierNames = $this->bulk->getIdentifierNames();
        if (empty($identifierNames)
            || (count($identifierNames) === 1 && reset($identifierNames) === 'o:id')
        ) {
            $this->actionIdentifier = self::ACTION_SKIP;
            return $this;
        }

        $this->actionIdentifier = $this->getParam('action_identifier_update') ?: self::ACTION_APPEND;
        if (!in_array($this->actionIdentifier, [
            self::ACTION_APPEND,
            self::ACTION_UPDATE,
        ])) {
            $this->logger->err(
                'Action "{action}" for identifier is not managed.', // @translate
                ['action' => $this->actionIdentifier]
            );
            $this->actionIdentifier = self::ACTION_APPEND;
        }

        // TODO Prepare the list of identifiers one time (only properties) (see extractIdentifiers())?
        return $this;
    }

    protected function prepareActionMedia(): \BulkImport\Interfaces\Processor
    {
        $this->actionMedia = $this->getParam('action_media_update') ?: self::ACTION_APPEND;
        if (!in_array($this->actionMedia, [
            self::ACTION_APPEND,
            self::ACTION_UPDATE,
        ])) {
            $this->logger->err(
                'Action "{action}" for media (update of item) is not managed.', // @translate
                ['action' => $this->actionMedia]
            );
            $this->actionMedia = self::ACTION_APPEND;
        }
        return $this;
    }

    protected function prepareActionItemSet(): \BulkImport\Interfaces\Processor
    {
        $this->actionItemSet = $this->getParam('action_item_set_update') ?: self::ACTION_APPEND;
        if (!in_array($this->actionItemSet, [
            self::ACTION_APPEND,
            self::ACTION_UPDATE,
        ])) {
            $this->logger->err(
                'Action "{action}" for item set (update of item) is not managed.', // @translate
                ['action' => $this->actionItemSet]
            );
            $this->actionItemSet = self::ACTION_APPEND;
        }
        return $this;
    }

    /**
     * Prepare full mapping to simplify process.
     *
     * Add automapped metadata for properties (language and datatype).
     */
    protected function prepareMapping(): \BulkImport\Interfaces\Processor
    {
        $mapping = $this->getParam('mapping', []);

        // The automap is only used for language, type and visibility:
        // the properties are the one that are set by the user.
        $automapFields = $this->getServiceLocator()->get('ViewHelperManager')->get('automapFields');
        $sourceFields = $automapFields(array_keys($mapping), ['output_full_matches' => true]);

        $index = -1;
        foreach ($mapping as $sourceField => $targets) {
            ++$index;
            if (empty($targets)) {
                continue;
            }

            // The automap didn't find any matching.
            if (empty($sourceFields[$index])) {
                foreach ($targets as $target) {
                    $sourceFields[$index][] = [
                        'field' => $target,
                        '@language' => null,
                        'type' => null,
                        'is_public' => null,
                    ];
                }
            }

            // Default metadata (type, language and visibility).
            // For consistency, only the first metadata is used.
            $metadatas = $sourceFields[$index];
            $metadata = reset($metadatas);

            $fullTargets = [];
            foreach ($targets as $target) {
                $result = [];
                // Field is the property found by automap. Not used, but possible for messages.
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
                    $result['value']['type'] = $this->getDataType($metadata['type']) ?: 'literal';
                    $result['value']['@language'] = $metadata['@language'];
                    $result['value']['is_public'] = $metadata['is_public'] !== 'private';
                }
                // A specific or module field. These fields may be useless.
                else {
                    $result['full_field'] = $sourceField;
                    $result['@language'] = $metadata['@language'];
                    $result['type'] = $metadata['type'];
                    $result['is_public'] = $metadata['is_public'] !== 'private';
                }

                $fullTargets[] = $result;
            }
            $mapping[$sourceField] = $fullTargets;
        }

        // Filter the mapping to avoid to loop entries without target.
        $this->mapping = array_filter($mapping);
        // Some readers don't need a mapping (xml reader do the process itself).
        $this->hasMapping = (bool) $this->mapping;
        return $this;
    }

    /**
     * Prepare other internal data.
     */
    protected function appendInternalParams(): \BulkImport\Interfaces\Processor
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $internalParams = [];
        $internalParams['iiifserver_image_server'] = $settings->get('iiifserver_image_server', '');
        if ($internalParams['iiifserver_image_server']
            && mb_substr($internalParams['iiifserver_image_server'], -1) !== '/'
        ) {
            $internalParams['iiifserver_image_server'] .= '/';
        }
        $this->setParams(array_merge($this->getParams() + $internalParams));
        return $this;
    }
}
