<?php declare(strict_types=1);

namespace BulkImport\Processor;

use ArrayObject;
use BulkImport\Entry\Entry;
use BulkImport\Form\Processor\ResourceProcessorConfigForm;
use BulkImport\Form\Processor\ResourceProcessorParamsForm;
use BulkImport\Stdlib\MessageStore;
use Log\Stdlib\PsrMessage;

/**
 * Can be used for all derivative of AbstractResourceEntityRepresentation.
 */
class ResourceProcessor extends AbstractResourceProcessor
{
    use ResourceUpdateTrait;

    protected $resourceName = 'resources';

    protected $resourceLabel = 'Mixed resources'; // @translate

    protected $configFormClass = ResourceProcessorConfigForm::class;

    protected $paramsFormClass = ResourceProcessorParamsForm::class;

    /**
     * @see \Omeka\Api\Representation\ItemRepresentation
     *
     * @var array
     */
    protected $metadataData = [
        // Assets metadata and file.
        'fields' => [
            'file',
            'url',
            'o:id',
            'o:owner',
            // TODO Incomplete, but not used currently
        ],
        'meta_mapper_config' => [
            'to_keys' => [
                'field' => null,
                'property_id' => null,
                'datatype' => null,
                'language' => null,
                'is_public' => null,
            ],
        ],
        'skip' => [],
        // Cf. baseSpecific(), fillItem(), fillItemSet() and fillMedia().
        'boolean' => [
            'o:is_public' => true,
            'o:is_open' => true,
        ],
        'single_data' => [
            // Generic.
            'o:id' => null,
            // Resource.
            'resource_name' => null,
            // Media.
            'o:lang' => null,
            'o:ingester' => null,
            'o:source' => null,
            'ingest_filename' => null,
            'ingest_directory' => null,
            'ingest_url' => null,
            'html' => null,
        ],
        'single_entity' => [
            // Generic.
            'o:resource_template' => null,
            'o:resource_class' => null,
            'o:thumbnail' => null,
            'o:owner' => null,
            // Media.
            'o:item' => null,
        ],
        'multiple_entities' => [
            'o:item_set' => null,
            'o:media' => null,
        ],
    ];

    /**
     * @see \Omeka\Api\Representation\AssetRepresentation
     * @var array
     */
    protected $fields = [
        // Assets metadata and file.
        'file',
        'url',
        'o:id',
        'o:name',
        'o:storage_id',
        'o:owner',
        'o:alt_text',
        // To attach resources.
        'o:resource',
    ];

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
     * @deprecated
     * @var bool
     */
    protected $hasProcessorMapping;

    /**
     * @deprecated
     * @var array
     */
    protected $mapping;

    protected function handleFormGeneric(ArrayObject $args, array $values): self
    {
        $defaults = [
            'processing' => 'stop_on_error',
            'skip_missing_files' => false,
            'entries_to_skip' => 0,
            'entries_max' => 0,

            'action' => null,
            'action_unidentified' => null,
            'identifier_name' => null,
            'value_datatype_literal' => false,
            'allow_duplicate_identifiers' => false,
            'action_identifier_update' => null,
            'action_media_update' => null,
            'action_item_set_update' => null,

            'o:resource_template' => null,
            'o:resource_class' => null,
            'o:thumbnail' => null,
            'o:owner' => null,
            'o:is_public' => null,
        ];

        $result = array_intersect_key($values, $defaults) + $args->getArrayCopy() + $defaults;
        $result['allow_duplicate_identifiers'] = (bool) $result['allow_duplicate_identifiers'];
        $args->exchangeArray($result);
        return $this;
    }

    protected function handleFormSpecific(ArrayObject $args, array $values): self
    {
        if (isset($values['resource_name'])) {
            $args['resource_name'] = $values['resource_name'];
        }
        $this->handleFormItem($args, $values);
        $this->handleFormItemSet($args, $values);
        $this->handleFormMedia($args, $values);
        return $this;
    }

    protected function handleFormItem(ArrayObject $args, array $values): self
    {
        if (isset($values['o:item_set'])) {
            $ids = $this->bulk->findResourcesFromIdentifiers($values['o:item_set'], 'o:id', 'item_sets');
            foreach ($ids as $id) {
                $args['o:item_set'][] = ['o:id' => $id];
            }
        }
        return $this;
    }

    protected function handleFormItemSet(ArrayObject $args, array $values): self
    {
        if (isset($values['o:is_open'])) {
            $args['o:is_open'] = $values['o:is_open'] !== 'false';
        }
        return $this;
    }

    protected function handleFormMedia(ArrayObject $args, array $values): self
    {
        if (!empty($values['o:item'])) {
            $id = $this->bulk->findResourceFromIdentifier($values['o:item'], 'o:id', 'items');
            if ($id) {
                $args['o:item'] = ['o:id' => $id];
            }
        }
        return $this;
    }

    protected function prepareSpecific(): self
    {
        $this
            ->prepareActionIdentifier()
            ->prepareActionMedia()
            ->prepareActionItemSet()

            ->appendInternalParams()

            ->prepareMapping();

        return $this;
    }

    protected function prepareActionIdentifier(): self
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

    protected function prepareActionMedia(): self
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

    protected function prepareActionItemSet(): self
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
     * Prepare other internal data.
     */
    protected function appendInternalParams(): self
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $internalParams = [];
        $internalParams['iiifserver_media_api_url'] = $settings->get('iiifserver_media_api_url', '');
        if ($internalParams['iiifserver_media_api_url']
            && mb_substr($internalParams['iiifserver_media_api_url'], -1) !== '/'
        ) {
            $internalParams['iiifserver_media_api_url'] .= '/';
        }
        $this->setParams(array_merge($this->getParams() + $internalParams));
        return $this;
    }

    /**
     * Prepare full mapping one time to simplify and speed process.
     *
     * Add automapped metadata for properties (language and datatypes).
     *
     * @see \BulkImport\Processor\AssetProcessor::prepareMapping()
     * Note: The metaconfig is already prepared in prepareMetaConfig().
     * @deprecated Use MetaMapperConfig directly.
     */
    protected function prepareMapping(): self
    {
        $isPrepared = false;
        if (method_exists($this->reader, 'getConfigParam')) {
            $mappingConfig = $this->reader->getParam('mapping_config')
                ?: ($this->reader->getConfigParam('mapping_config') ?: null);
            if ($mappingConfig) {
                $isPrepared = true;
                $mapping = [];
                $this->metaMapper->getMetaMapperConfig(
                    'resources',
                    $mappingConfig,
                    $this->metadataData['meta_mapper_config']
                );

                $this->metaMapper->__invoke('resources');

                if ($this->metaMapper->getMetaMapperConfig()->hasError()) {
                    return $this;
                }

                $mappingSource = array_merge(
                    $this->metaMapper->getMetaMapperConfig()->getSection('default'),
                    $this->metaMapper->getMetaMapperConfig()->getSection('maps')
                );
                foreach ($mappingSource as $fromTo) {
                    // The from is useless here, the entry takes care of it.
                    if (isset($fromTo['to']['dest'])) {
                        // Manage multimapping: there may be multiple target fields.
                        // TODO Improve multimapping management (for spreadsheet in fact).
                        $mapping[$fromTo['to']['dest']][] = $fromTo['to']['field'] ?? null;
                    }
                }
                // Filter duplicated and null values.
                foreach ($mapping as &$datas) {
                    $datas = array_values(array_unique(array_filter(array_map('strval', $datas), 'strlen')));
                }
                unset($datas);

                // These mappings are added automatically with JsonEntry.
                // TODO Find a way to add automatic mapping in metaMapper or default mapping.
                $mapping['url'] = $mapping['url'] ?? ['url'];
                $mapping['iiif'] = $mapping['iiif'] ?? ['iiif'];
            }
        }

        if (!$isPrepared) {
            $mapping = $this->getParam('mapping', []);
        }

        if (!count($mapping)) {
            $this->hasProcessorMapping = false;
            $this->mapping = [];
            return $this;
        }

        // The automap is only used for language, datatypes and visibility:
        // the properties are the one that are set by the user.
        // TODO Avoid remapping or factorize when done in metaMapper.
        /** @var \BulkImport\Mvc\Controller\Plugin\AutomapFields $automapFields */
        $automapFields = $this->getServiceLocator()->get('ControllerPluginManager')->get('automapFields');
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
                        'language' => null,
                        'type' => null,
                        'is_public' => null,
                    ];
                }
            }

            // Default metadata (datatypes, language and visibility).
            // For consistency, only the first metadata is used.
            $metadatas = $sourceFields[$index];
            $metadata = reset($metadatas);

            $fullTargets = [];
            foreach ($targets as $target) {
                $result = [];
                // Field is the property found by automap, or any other metadata.
                // This value is not used, but may be useful for messages.
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
                    $propertyId = $this->bulk->getPropertyId($targetData);
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

                $propertyId = $this->bulk->getPropertyId($target);
                if ($propertyId) {
                    $datatypes = [];
                    // Normally already checked.
                    foreach ($metadata['datatype'] ?? [] as $datatype) {
                        $datatypes[] = $this->bulk->getDataTypeName($datatype);
                    }
                    $datatypes = array_filter(array_unique($datatypes));
                    if (empty($datatypes)) {
                        $datatype = 'literal';
                    } elseif (count($datatypes) === 1) {
                        $datatype = reset($datatypes);
                    } else {
                        $datatype = null;
                    }
                    $result['value']['property_id'] = $propertyId;
                    $result['value']['type'] = $datatype;
                    $result['value']['@language'] = $metadata['language'];
                    $result['value']['is_public'] = $metadata['is_public'] !== 'private';
                    if (is_null($datatype)) {
                        $result['datatype'] = $datatypes;
                    }
                }
                // A specific or module field. These fields may be useless.
                // TODO Check where this exception is used.
                else {
                    $result['full_field'] = $sourceField;
                    $result['@language'] = $metadata['language'];
                    $result['type'] = empty($metadata['datatype'])
                        ? null
                        : (is_array($metadata['datatype']) ? reset($metadata['datatype']) : (string) $metadata['datatype']);
                    $result['is_public'] = $metadata['is_public'] !== 'private';
                }

                $fullTargets[] = $result;
            }
            $mapping[$sourceField] = $fullTargets;
        }

        // Filter the mapping to avoid to loop entries without target.
        $this->mapping = array_filter($mapping);
        // Some readers don't need a mapping (xml reader do the process itself).
        $this->hasProcessorMapping = (bool) $this->mapping;

        return $this;
    }

    /**
     * Process one entry to create one resource (and eventually attached ones).
     */
    protected function processEntry(Entry $entry): ?ArrayObject
    {
        if ($entry->isEmpty()) {
            ++$this->totalSkipped;
            return null;
        }

        // TODO Use MetaMapper.
        return $this->hasProcessorMapping
            ? $this->processEntryFromProcessor($entry)
            : $this->processEntryFromReader($entry);
    }

    /**
     * Convert a prepared entry into a resource, setting ids for each key.
     *
     * Reader-driven extraction of data.
     *
     * So fill owner id, resource template id, resource class id, property ids.
     * Check boolean values too for is public and is open.
     */
    protected function processEntryFromReader(Entry $entry): ArrayObject
    {
        $resource = parent::processEntryFromReader($entry);

        // Clean the property id in all cases.
        $properties = $this->bulk->getPropertyIds();
        foreach (array_intersect_key($resource->getArrayCopy(), $properties) as $term => $values) {
            foreach (array_keys($values) as $key) {
                $resource[$term][$key]['property_id'] = $properties[$term];
            }
        }

        // TODO Fill the source identifiers of the main resource.

        $fillPropertiesAndResourceData = function (array $resourceArray) use ($properties): array {
            // Fill the properties.
            foreach (array_intersect_key($resourceArray, $properties) as $term => $values) {
                foreach (array_keys($values) as $key) {
                    $resourceArray[$term][$key]['property_id'] = $properties[$term];
                }
            }
            // Fill other metadata (for media and item set).
            $resourceObject = new ArrayObject($resourceArray);
            foreach (array_keys($this->metadataData['boolean']) as $key) {
                if (array_key_exists($key, $resourceArray)) {
                    $this->fillBoolean($resourceObject, $key, $resourceObject[$key]);
                }
            }
            foreach (array_keys($this->metadataData['single_entity']) as $key) {
                if (array_key_exists($key, $resourceArray)) {
                    $this->fillSingleEntity($resourceObject, $key, $resourceObject[$key]);
                }
            }
            // TODO Fill the source identifiers of related resources and other single data.
            return $resourceObject->getArrayCopy();
        };

        // Do the same for sub-resources (multiple entity keys: ''o:media" and
        // "o:item_set" for items).
        foreach (array_keys($this->metadataData['multiple_entities']) as $key) {
            if (!empty($resource[$key])) {
                foreach ($resource[$key] as &$resourceData) {
                    $resourceData = $fillPropertiesAndResourceData($resourceData);
                }
            }
        }

        return $resource;
    }

    /**
     * Processor-driven extraction of data.
     *
     * @todo Upgrade to use the metamapper.
     */
    protected function processEntryFromProcessor(Entry $entry): ArrayObject
    {
        /** @var \ArrayObject $resource */
        $resource = clone $this->base;
        $resource['source_index'] = $this->indexResource;
        $resource['messageStore']->clearMessages();

        $this->skippedSourceFields = [];
        foreach ($this->mapping as $sourceField => $targets) {
            // Check if the entry has a value for this source field.
            if (!isset($entry[$sourceField])) {
                // Probably an issue in the config.
                /*
                // TODO Warn when it is not a multisheet. Check updates with a multisheet.
                if (!$entry->offsetExists($sourceField)) {
                    $resource['messageStore']->addWarning('values', new PsrMessage(
                        'The source field "{field}" is set in the mapping, but not in the entry. The params may have an issue.', // @translate
                        ['field' => $sourceField]
                    ));
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

    protected function baseSpecific(ArrayObject $resource): self
    {
        $this->baseResourceCommon($resource);
        // Determined by the entry, but prepare all possible types in the case
        // there is a mapping.
        $this->baseItem($resource);
        $this->baseItemSet($resource);
        $this->baseMedia($resource);
        $resource['resource_name'] = $this->getParam('resource_name');
        return $this;
    }

    protected function baseResourceCommon(ArrayObject $resource): self
    {
        $ownerId = $this->getParam('o:owner', 'current') ?: 'current';
        if ($ownerId === 'current') {
            $identity = $this->getServiceLocator()->get('ControllerPluginManager')
                ->get('identity');
            $ownerId = $identity()->getId();
        }
        $resource['o:owner'] = ['o:id' => $ownerId];

        $resourceTemplateId = $this->getParam('o:resource_template');
        if ($resourceTemplateId) {
            $resource['o:resource_template'] = ['o:id' => $resourceTemplateId];
        }

        $resourceClassId = $this->getParam('o:resource_class');
        if ($resourceClassId) {
            $resource['o:resource_class'] = ['o:id' => $resourceClassId];
        }

        $thumbnailId = $this->getParam('o:thumbnail');
        if ($thumbnailId) {
            $resource['o:thumbnail'] = ['o:id' => $thumbnailId];
        }

        $resource['o:is_public'] = $this->getParam('o:is_public') !== 'false';
        return $this;
    }

    protected function baseItem(ArrayObject $resource): self
    {
        $resource['resource_name'] = 'items';
        $resource['o:item_set'] = $this->getParam('o:item_set', []);
        $resource['o:media'] = [];
        return $this;
    }

    protected function baseItemSet(ArrayObject $resource): self
    {
        $resource['resource_name'] = 'item_sets';
        $isOpen = $this->getParam('o:is_open', null);
        $resource['o:is_open'] = $isOpen;
        return $this;
    }

    protected function baseMedia(ArrayObject $resource): self
    {
        $resource['resource_name'] = 'media';
        $resource['o:item'] = $this->getParam('o:item') ?: ['o:id' => null];
        return $this;
    }

    /**
     * @todo Factorize with fillGeneric().
     */
    protected function fillSingleEntity(ArrayObject $resource, $key, $value): self
    {
        if (empty($value)) {
            $resource[$key] = null;
            return $this;
        }

        // Get the entity id.
        switch ($key) {
            case 'o:resource_template':
                if (is_array($value)) {
                    $id = empty($value['o:id']) ? null : $value['o:id'];
                    $label = empty($value['o:label']) ? null : $value['o:label'];
                    $value = $id ?? $label ?? reset($value);
                }
                $id = $this->bulk->getResourceTemplateId($value);
                if ($id) {
                    $resource['o:resource_template'] = empty($label)
                        ? ['o:id' => $id]
                        : ['o:id' => $id, 'o:label' => $label];
                } else {
                    $resource['o:resource_template'] = null;
                    $resource['messageStore']->addError('values', new PsrMessage(
                        'The resource template "{source}" does not exist.', // @translate
                        ['source' => $value]
                    ));
                }
                return $this;

            case 'o:resource_class':
                if (is_array($value)) {
                    $id = empty($value['o:id']) ? null : $value['o:id'];
                    $term = empty($value['o:term']) ? null : $value['o:term'];
                    $value = $id ?? $term ?? reset($value);
                }
                $id = $this->bulk->getResourceClassId($value);
                if ($id) {
                    $resource['o:resource_class'] = empty($term)
                        ? ['o:id' => $id]
                        : ['o:id' => $id, 'o:term' => $term];
                } else {
                    $resource['o:resource_class'] = null;
                    $resource['messageStore']->addError('values', new PsrMessage(
                        'The resource class "{source}" does not exist.', // @translate
                        ['source' => $value]
                    ));
                }
                return $this;

            case 'o:thumbnail':
                if (is_array($value)) {
                    $id = empty($value['o:id']) ? null : $value['o:id'];
                    $url = empty($value['ingest_url']) ? null : $value['ingest_url'];
                    $altText = empty($value['o:alt_text']) ? null : $value['o:alt_text'];
                    $value = $id ?? $url ?? null;
                }
                if (is_numeric($value)) {
                    $id = $this->bulk->getAssetId($value);
                } elseif (is_string($value)) {
                    // TODO Temporary creation of the asset.
                    $asset = $this->createAssetFromUrl($value, $resource['messageStore']);
                    $id = $asset ? $asset->getId() : null;
                }
                if ($id) {
                    $resource['o:thumbnail'] = empty($altText)
                        ? ['o:id' => $id]
                        // TODO Check if the alt text is updated.
                        : ['o:id' => $id, 'o:alt_text' => $altText];
                } else {
                    $resource['messageStore']->addError('values', new PsrMessage(
                        'The thumbnail "{source}" does not exist or cannot be created.', // @translate
                        ['source' => $value]
                    ));
                }
                return $this;

            case 'o:owner':
                if (is_array($value)) {
                    $id = empty($value['o:id']) ? null : $value['o:id'];
                    $email = empty($value['o:email']) ? null : $value['o:email'];
                    $value = $id ?? $email
                        // Check standard value too, that may be created by a
                        // xml flat mapping.
                        // TODO Remove this fix: it should be checked earlier.
                        ?? $value['@value'] ?? $value['value_resource_id']
                        ?? reset($value);
                }
                $id = $this->bulk->getUserId($value);
                if ($id) {
                    $resource['o:owner'] = empty($email)
                        ? ['o:id' => $id]
                        : ['o:id' => $id, 'o:email' => $email];
                } else {
                    $resource['o:owner'] = null;
                    $resource['messageStore']->addError('resource', new PsrMessage(
                        'The user "{source}" does not exist.', // @translate
                        ['source' => $value]
                    ));
                }
                return $this;

            case 'o:item':
                // For media.
                if (is_array($value)) {
                    $id = empty($value['o:id']) ? null : $value['o:id'];
                    $value = $id ?? reset($value);
                }
                $id = empty($this->identifiers['mapx'][$resource['source_index']])
                    ? $this->bulk->findResourceFromIdentifier($value, null, null, $resource['messageStore'])
                    : (int) strtok((string) $this->identifiers['mapx'][$resource['source_index']], '§');
                if ($id) {
                    $resource['o:item'] = ['o:id' => $id];
                } else {
                    $resource['o:item'] = null;
                    $resource['messageStore']->addError('resource', new PsrMessage(
                        'The item "{source}" for media does not exist.', // @translate
                        ['source' => $value]
                    ));
                }
                return $this;

            default:
                return $this;
        }
    }

    /**
     * @todo Use the MetaMapper.
     */
    protected function fillResource(ArrayObject $resource, array $targets, array $values): self
    {
        foreach ($targets as $target) {
            switch ($target['target']) {
                // Check properties first for performance.
                case $this->fillProperty($resource, $target, $values):
                    break;
                case $this->fillGeneric($resource, $target, $values):
                    break;
                case $this->fillSpecific($resource, $target, $values):
                    break;
                default:
                    // The resource name should be set only in fillSpecific.
                    if ($target['target'] !== 'resource_name') {
                        $resource[$target['target']] = end($values);
                    }
                    break;
            }
        }
        return $this;
    }

    protected function fillGeneric(ArrayObject $resource, $target, array $values): bool
    {
        switch ($target['target']) {
            case 'o:id':
                $value = (int) end($values);
                if (!$value) {
                    return true;
                }
                $resourceName = $resource['resource_name'] ?? null;
                $id = empty($this->identifiers['mapx'][$resource['source_index']])
                    ? $this->bulk->findResourceFromIdentifier($value, 'o:id', $resourceName, $resource['messageStore'])
                    : (int) strtok((string) $this->identifiers['mapx'][$resource['source_index']], '§');
                if ($id) {
                    $resource['o:id'] = $id;
                    $resource['checked_id'] = !empty($resourceName) && $resourceName !== 'resources';
                } else {
                    $resource['messageStore']->addError('resource', new PsrMessage(
                        'source index #{index}: Internal id cannot be found. The entry is skipped.', // @translate
                        ['index' => $resource['source_index']]
                    ));
                }
                return true;

            case 'o:resource_template':
                $value = end($values);
                if (!$value) {
                    return true;
                }
                if (is_array($value)) {
                    $id = empty($value['o:id']) ? null : $value['o:id'];
                    $label = empty($value['o:label']) ? null : $value['o:label'];
                    $value = $id ?? $label ?? reset($value);
                }
                $id = $this->bulk->getResourceTemplateId($value);
                if ($id) {
                    $resource['o:resource_template'] = empty($label)
                        ? ['o:id' => $id]
                        : ['o:id' => $id, 'o:label' => $label];
                } else {
                    $resource['messageStore']->addError('template', new PsrMessage(
                        'The resource template "{source}" does not exist.', // @translate
                        ['source' => $value]
                    ));
                }
                return true;

            case 'o:resource_class':
                $value = end($values);
                if (!$value) {
                    return true;
                }
                if (is_array($value)) {
                    $id = empty($value['o:id']) ? null : $value['o:id'];
                    $term = empty($value['o:term']) ? null : $value['o:term'];
                    $value = $id ?? $term ?? reset($value);
                }
                $id = $this->bulk->getResourceClassId($value);
                if ($id) {
                    $resource['o:resource_class'] = empty($term)
                        ? ['o:id' => $id]
                        : ['o:id' => $id, 'o:term' => $term];
                } else {
                    $resource['messageStore']->addError('values', new PsrMessage(
                        'The resource class "{source}" does not exist.', // @translate
                        ['source' => $value]
                    ));
                }
                return true;

            case 'o:thumbnail':
                $value = end($values);
                if (!$value) {
                    return true;
                }
                if (is_array($value)) {
                    $id = empty($value['o:id']) ? null : $value['o:id'];
                    $url = empty($value['ingest_url']) ? null : $value['ingest_url'];
                    $altText = empty($value['o:alt_text']) ? null : $value['o:alt_text'];
                    $value = $id ?? $url ?? null;
                }
                if (is_numeric($value)) {
                    $id = $this->bulk->getAssetId($value);
                } elseif (is_string($value)) {
                    // TODO Temporary creation of the asset.
                    $asset = $this->createAssetFromUrl($value, $resource['messageStore']);
                    $id = $asset ? $asset->getId() : null;
                }
                if ($id) {
                    $resource['o:thumbnail'] = empty($altText)
                        ? ['o:id' => $id]
                        // TODO Check if the alt text is updated.
                        : ['o:id' => $id, 'o:alt_text' => $altText];
                } else {
                    $resource['messageStore']->addError('values', new PsrMessage(
                        'The thumbnail "{source}" does not exist or cannot be created.', // @translate
                        ['source' => $value]
                    ));
                }
                return true;

            case 'o:owner':
                $value = end($values);
                if (!$value) {
                    return true;
                }
                if (is_array($value)) {
                    $id = empty($value['o:id']) ? null : $value['o:id'];
                    $email = empty($value['o:email']) ? null : $value['o:email'];
                    $value = $id ?? $email ?? reset($value);
                }
                $id = $this->bulk->getUserId($value);
                if ($id) {
                    $resource['o:owner'] = empty($email)
                        ? ['o:id' => $id]
                        : ['o:id' => $id, 'o:email' => $email];
                } else {
                    $resource['messageStore']->addError('values', new PsrMessage(
                        'The user "{source}" does not exist.', // @translate
                        ['source' => $value]
                    ));
                }
                return true;

            case 'o:email':
                $value = end($values);
                if (!$value) {
                    return true;
                }
                $id = $this->bulk->getUserId($value);
                if ($id) {
                    $resource['o:owner'] = ['o:id' => $id, 'o:email' => $value];
                } else {
                    $resource['messageStore']->addError('values', new PsrMessage(
                        'The user "{source}" does not exist.', // @translate
                        ['source' => $value]
                    ));
                }
                return true;

            case 'o:is_public':
                $value = (string) end($values);
                $resource['o:is_public'] = in_array(strtolower($value), ['0', 'false', 'no', 'off', 'private'], true)
                    ? false
                    : (bool) $value;
                return true;

            case 'o:created':
            case 'o:modified':
                $value = end($values);
                $resource[$target['target']] = is_array($value)
                    ? $value
                    : ['@value' => substr_replace('0000-00-00 00:00:00', $value, 0, strlen($value))];
                return true;

            default:
                return false;
        }
    }

    protected function fillProperty(ArrayObject $resource, $target, array $values): bool
    {
        // Return true in all other cases, when this is a property process, with
        // or without issue.
        if (!isset($target['value']['property_id'])) {
            return false;
        }

        if (!empty($target['value']['type'])) {
            $datatypeNames = [$target['value']['type']];
        } elseif (!empty($target['datatype'])) {
            $datatypeNames = $target['datatype'];
        } else {
            // Normally not possible, so use "literal", whatever the option is.
            $datatypeNames = ['literal'];
        }

        // The datatype should be checked for each value. The value is checked
        // against each datatype and get the first valid one.
        // TODO Factorize instead of doing check twice.
        foreach ($values as $value) {
            $hasDatatype = false;
            // The data type name is normally already checked, but may be empty.
            foreach ($datatypeNames as $datatypeName) {
                /** @var \Omeka\DataType\DataTypeInterface $datatype */
                $datatype = $this->bulk->getDataType($datatypeName);
                if (!$datatype) {
                    continue;
                }
                $datatypeName = $datatype->getName();
                $target['value']['type'] = $datatypeName;
                $mainDataType = $this->bulk->getMainDataType($datatypeName);
                if ($datatypeName === 'literal') {
                    $this->fillPropertyForValue($resource, $target, $value);
                    $hasDatatype = true;
                    break;
                } elseif ($mainDataType === 'resource') {
                    $vrId = empty($this->identifiers['map'][$value . '§resources'])
                        ? $this->bulk->findResourceFromIdentifier($value, null, substr($datatypeName, 0, 11) === 'customvocab' ? 'item' : $datatypeName, $resource['messageStore'])
                        : (int) strtok((string) $this->identifiers['map'][$value . '§resources'], '§');
                    // Normally always true after first loop: all identifiers
                    // are stored first.
                    if ($vrId || array_key_exists($value . '§resources', $this->identifiers['map'])) {
                        $this->fillPropertyForValue($resource, $target, $value, $vrId ? (int) $vrId : null);
                        $hasDatatype = true;
                        break;
                    }
                } elseif (substr($datatypeName, 0, 11) === 'customvocab') {
                    if ($this->bulk->isCustomVocabMember($datatypeName, $value)) {
                        $this->fillPropertyForValue($resource, $target, $value);
                        $hasDatatype = true;
                        break;
                    }
                } elseif ($mainDataType === 'uri'
                    // Deprecated.
                    || $datatypeName === 'uri-label'
                ) {
                    if ($this->bulk->isUrl($value)) {
                        $this->fillPropertyForValue($resource, $target, $value);
                        $hasDatatype = true;
                        break;
                    }
                } else {
                    // Some data types may be more complex than "@value", but it
                    // manages most of the common other modules.
                    $valueArray = [
                        '@value' => $value,
                    ];
                    if ($datatype->isValid($valueArray)) {
                        $this->fillPropertyForValue($resource, $target, $value);
                        $hasDatatype = true;
                        break;
                    }
                }
            }
            if (!$hasDatatype) {
                if ($this->getParam('value_datatype_literal')) {
                    $targetLiteral = $target;
                    $targetLiteral['value']['type'] = 'literal';
                    $this->fillPropertyForValue($resource, $targetLiteral, $value);
                    if ($this->bulk->getMainDataType(reset($datatypeNames)) === 'resource') {
                        $resource['messageStore']->addNotice('values', new PsrMessage(
                            'The value "{value}" is not compatible with datatypes "{datatypes}". Try to create the resource first. Data type "literal" is used.', // @translate
                            ['value' => mb_substr((string) $value, 0, 50), 'datatypes' => implode('", "', $datatypeNames)]
                        ));
                    } else {
                        $resource['messageStore']->addNotice('values', new PsrMessage(
                            'The value "{value}" is not compatible with datatypes "{datatypes}". Data type "literal" is used.', // @translate
                            ['value' => mb_substr((string) $value, 0, 50), 'datatypes' => implode('", "', $datatypeNames)]
                        ));
                    }
                } else {
                    if ($this->bulk->getMainDataType(reset($datatypeNames)) === 'resource') {
                        $resource['messageStore']->addError('values', new PsrMessage(
                            'The value "{value}" is not compatible with datatypes "{datatypes}". Try to create resource first. Or try to add "literal" to datatypes or default to it.', // @translate
                            ['value' => mb_substr((string) $value, 0, 50), 'datatypes' => implode('", "', $datatypeNames)]
                        ));
                    } else {
                        $resource['messageStore']->addError('values', new PsrMessage(
                            'The value "{value}" is not compatible with datatypes "{datatypes}". Try to add "literal" to datatypes or default to it.', // @translate
                            ['value' => mb_substr((string) $value, 0, 50), 'datatypes' => implode('", "', $datatypeNames)]
                        ));
                    }
                }
            }
        }

        return true;
    }

    protected function fillPropertyForValue(ArrayObject $resource, $target, $value, ?int $vrId = null): bool
    {
        // Prepare the new resource value from the target.
        $resourceValue = $target['value'];
        $datatype = $resourceValue['type'];
        $mainDataType = $this->bulk->getMainDataType($datatype);
        switch ($datatype) {
            default:
            case 'literal':
                $resourceValue['@value'] = $value;
                break;

                // "uri-label" is deprecated: use simply "uri".
            case 'uri-label':
            case $mainDataType === 'uri':
            // case 'uri':
            // case substr($datatype, 0, 12) === 'valuesuggest':
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

            // case 'resource':
            // case 'resource:item':
            // case 'resource:itemset':
            // case 'resource:media':
            case $mainDataType === 'resource':
                // TODO Check identifier as member of custom vocab later (anyway item sets of an item can change).
                $resourceValue['value_resource_id'] = $vrId;
                $resourceValue['@language'] = null;
                $resourceValue['source_identifier'] = $value;
                $resourceValue['checked_id'] = true;
                break;

            case substr($datatype, 0, 11) === 'customvocab':
                $customVocabBaseType = $this->bulk->getCustomVocabBaseType($datatype);
                $result = $this->bulk->isCustomVocabMember($datatype, $vrId ?? $value);
                if ($result) {
                    if ($customVocabBaseType === 'uri') {
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
                    } else {
                        // Literal.
                        $resourceValue['@value'] = $value;
                    }
                } else {
                    if ($this->getParam('value_datatype_literal')) {
                        $resourceValue['@value'] = $value;
                        $resourceValue['type'] = 'literal';
                        $resource['messageStore']->addNotice('values', new PsrMessage(
                            'The value "{value}" is not member of custom vocab "{customvocab}". A literal value is used instead.', // @translate
                            ['value' => mb_substr((string) $value, 0, 50), 'customvocab' => $datatype]
                        ));
                    } else {
                        $resource['messageStore']->addError('values', new PsrMessage(
                            'The value "{value}" is not member of custom vocab "{customvocab}".', // @translate
                            ['value' => mb_substr((string) $value, 0, 50), 'customvocab' => $datatype]
                        ));
                    }
                }
                break;

            // TODO Support other special data t$this->fillItem($resource, $target, $values)ypes for geometry, numeric, etc.
        }
        $resource[$target['target']][] = $resourceValue;

        return true;
    }

    protected function fillSpecific(ArrayObject $resource, $target, array $values): bool
    {
        static $resourceNames;

        if (is_null($resourceNames)) {
            $translate = $this->getServiceLocator()->get('ViewHelperManager')->get('translate');
            $resourceNames = [
                'oitem' => 'items',
                'oitemset' => 'item_sets',
                'omedia' => 'media',
                'item' => 'items',
                'itemset' => 'item_sets',
                'media' => 'media',
                'items' => 'items',
                'itemsets' => 'item_sets',
                'medias' => 'media',
                'media' => 'media',
                'collection' => 'item_sets',
                'collections' => 'item_sets',
                'file' => 'media',
                'files' => 'media',
                $translate('item') => 'items',
                $translate('itemset') => 'item_sets',
                $translate('media') => 'media',
                $translate('items') => 'items',
                $translate('itemsets') => 'item_sets',
                $translate('medias') => 'media',
                $translate('media') => 'media',
                $translate('collection') => 'item_sets',
                $translate('collections') => 'item_sets',
                $translate('file') => 'media',
                $translate('files') => 'media',
            ];
            $resourceNames = array_change_key_case($resourceNames, CASE_LOWER);
        }

        // Normally already set?
        if (empty($resource['resource_name']) && $target['target'] === 'o:item' && !empty($values)) {
            $resource['resource_name'] = 'media';
        }

        // When the resource name is known, don't fill other resources. But if
        // is not known yet, fill the item first. It fixes the issues with the
        // target that are the same for media of item and media (that is a
        // special case where two or more resources are created from one
        // entry).
        // Of course, use the option set in mixed resource importer if any and
        // not already set.
        $defaultResourceName = $this->getParam('resource_name', false);
        $resourceName = empty($resource['resource_name'])
            ? ($defaultResourceName ?: true)
            : $resource['resource_name'];

        // TODO Replace by a if/else, but take care of return when no output.
        switch ($target['target']) {
            case 'resource_name':
                $value = trim((string) end($values));
                $resourceName = preg_replace('~[^a-z]~', '', strtolower($value));
                if (isset($resourceNames[$resourceName])) {
                    $resource['resource_name'] = $resourceNames[$resourceName];
                }
                return true;
            case $resourceName === 'items' && $this->fillItem($resource, $target, $values):
                return true;
            case $resourceName === 'item_sets' && $this->fillItemSet($resource, $target, $values):
                return true;
            case $resourceName === 'media' && $this->fillMedia($resource, $target, $values):
                return true;
            default:
                return false;
        }
    }

    protected function fillItem(ArrayObject $resource, $target, array $values): bool
    {
        switch ($target['target']) {
            case 'o:item_set':
                $identifierNames = $target['target_data'] ?? $this->bulk->getIdentifierNames();
                // Check values one by one to manage source identifiers.
                foreach ($values as $value) {
                    if (!empty($this->identifiers['map'][$value . '§resources'])) {
                        $resource['o:item_set'][] = [
                            'o:id' => (int) strtok((string) $this->identifiers['map'][$value . '§resources'], '§'),
                            'checked_id' => true,
                            'source_identifier' => $value,
                        ];
                    } else {
                        $itemSetId = $this->bulk->findResourcesFromIdentifiers($value, $identifierNames, 'item_sets', $resource['messageStore']);
                        if ($itemSetId) {
                            $resource['o:item_set'][] = [
                                'o:id' => $itemSetId,
                                'checked_id' => true,
                                'source_identifier' => $value,
                            ];
                        } elseif (array_key_exists($value . '§resources', $this->identifiers['map'])) {
                            $resource['o:item_set'][] = [
                                // To be filled during real import if empty.
                                'o:id' => null,
                                'checked_id' => true,
                                'source_identifier' => $value,
                            ];
                        } else {
                            // Only for first loop. Normally not possible after: all
                            // identifiers are stored in the list "map" during first loop.
                            $resource['messageStore']->addError('values', new PsrMessage(
                                'The value "{value}" is not an item set.', // @translate
                                ['value' => mb_substr((string) $value, 0, 50)]
                            ));
                        }
                    }
                }
                return true;
            case 'url':
                foreach ($values as $value) {
                    $media = [];
                    $media['o:ingester'] = 'url';
                    $media['ingest_url'] = $value;
                    $media['o:source'] = $value;
                    $this->appendRelated($resource, $media);
                }
                return true;
            case 'tile':
                // Deprecated: tiles are now only a rendering, not an ingester
                // since ImageServer version 3.6.13. All images are
                // automatically tiled, so "tile" is a format similar to large/medium/square,
                // but different.
            case 'file':
                // A file may be a url for end-user simplicity.
                foreach ($values as $value) {
                    $media = [];
                    if ($this->bulk->isUrl($value)) {
                        $media['o:ingester'] = 'url';
                        $media['ingest_url'] = $value;
                    } else {
                        $media['o:ingester'] = 'sideload';
                        $media['ingest_filename'] = $value;
                    }
                    $media['o:source'] = $value;
                    $this->appendRelated($resource, $media);
                }
                return true;
            case 'directory':
                foreach ($values as $value) {
                    $media = [];
                    $media['o:ingester'] = 'sideload_dir';
                    $media['ingest_directory'] = $value;
                    $media['o:source'] = $value;
                    $this->appendRelated($resource, $media);
                }
                return true;
            case 'html':
                foreach ($values as $value) {
                    $media = [];
                    $media['o:ingester'] = 'html';
                    $media['html'] = $value;
                    $this->appendRelated($resource, $media);
                }
                return true;
            case 'iiif':
                foreach ($values as $value) {
                    $media = [];
                    $media['o:ingester'] = 'iiif';
                    $media['ingest_url'] = null;
                    if (!$this->bulk->isUrl($value)) {
                        $value = $this->getParam('iiifserver_media_api_url', '') . $value;
                    }
                    $media['o:source'] = $value;
                    $this->appendRelated($resource, $media);
                }
                return true;
            /*
            case 'tile':
                foreach ($values as $value) {
                    $media = [];
                    $media['o:ingester'] = 'tile';
                    $media['ingest_url'] = $value;
                    $media['o:source'] = $value;
                    $this->appendRelated($resource, $media);
                }
                return true;
            */
            case 'o:media':
                if (isset($target['target_data'])) {
                    if (isset($target['target_data_value'])) {
                        foreach ($values as $value) {
                            $resourceProperty = $target['target_data_value'];
                            $resourceProperty['@value'] = $value;
                            $media = [];
                            $media[$target['target_data']][] = $resourceProperty;
                            $this->appendRelated($resource, $media, 'o:media', 'dcterms:title');
                        }
                        return true;
                    } else {
                        $value = end($values);
                        $media = [];
                        $media[$target['target_data']] = $value;
                        $this->appendRelated($resource, $media, 'o:media', $target['target_data']);
                        return true;
                    }
                }
                break;
            case 'o-module-mapping:bounds':
                // There can be only one mapping zone.
                $bounds = reset($values);
                if (!$bounds) {
                    return true;
                }
                // @see \Mapping\Api\Adapter\MappingAdapter::validateEntity().
                if (null !== $bounds
                    && 4 !== count(array_filter(explode(',', $bounds), 'is_numeric'))
                ) {
                    $resource['messageStore']->addError('values', new PsrMessage(
                        'The mapping bounds requires four numeric values separated by a comma.'  // @translate
                    ));
                    return true;
                }
                // TODO Manage the update of a mapping.
                $resource['o-module-mapping:mapping'] = [
                    'o-id' => null,
                    'o-module-mapping:bounds' => $bounds,
                ];
                break;
            case 'o-module-mapping:marker':
                $resource['o-module-mapping:marker'] = [];
                foreach ($values as $value) {
                    list($lat, $lng) = array_filter(array_map('trim', explode('/', $value, 2)), 'is_numeric');
                    if (!strlen($lat) || !strlen($lng)) {
                        $resource['messageStore']->addError('values', new PsrMessage(
                            'The mapping marker requires a latitude and a longitude separated by a "/".'  // @translate
                        ));
                        return true;
                    }
                    $resource['o-module-mapping:marker'][] = [
                        'o:id' => null,
                        'o-module-mapping:lat' => $lat,
                        'o-module-mapping:lng' => $lng,
                        'o-module-mapping:label' => null,
                    ];
                }
                return true;
            default:
                return false;
        }
    }

    protected function fillItemSet(ArrayObject $resource, $target, array $values): bool
    {
        switch ($target['target']) {
            case 'o:is_open':
                $value = end($values);
                $resource['o:is_open'] = in_array(strtolower((string) $value), ['0', 'false', 'no', 'off', 'closed'], true)
                    ? false
                    : (bool) $value;
                return true;
            default:
                return false;
        }
    }

    protected function fillMedia(ArrayObject $resource, $target, array $values): bool
    {
        switch ($target['target']) {
            case 'o:filename':
            case 'o:basename':
            case 'o:storage_id':
            case 'o:source':
            case 'o:sha256':
                $value = trim((string) end($values));
                if (!$value) {
                    return true;
                }
                $id = empty($this->identifiers['mapx'][$resource['source_index']])
                    ? $this->bulk->findResourceFromIdentifier($value, $target['target'], 'media', $resource['messageStore'])
                    : (int) strtok((string) $this->identifiers['mapx'][$resource['source_index']], '§');
                if ($id) {
                    $resource['o:id'] = $id;
                    $resource['checked_id'] = true;
                } else {
                    $resource['messageStore']->addError('identifier', new PsrMessage(
                        'Media with metadata "{target}" "{identifier}" cannot be found. The entry is skipped.', // @translate
                        ['target' => $target['target'], 'identifier' => $value]
                    ));
                }
                return true;
            case 'o:item':
                $identifier = (string) end($values);
                if (!empty($this->identifiers['map'][$identifier . '§resources'])) {
                    $resource['o:item'] = [
                        // To be filled during real import.
                        'o:id' => (int) strtok((string) $this->identifiers['map'][$identifier . '§resources'], '§') ?: null,
                        'checked_id' => true,
                        'source_identifier' => $identifier,
                    ];
                } else {
                    $identifierName = $target['target_data'] ?? $this->bulk->getIdentifierNames();
                    $itemIds = $this->bulk->findResourcesFromIdentifiers($values, $identifierName, 'items', $resource['messageStore']);
                    if ($itemIds) {
                        if (count($values) === 1) {
                            $resource['o:item'] = [
                                'o:id' => end($itemIds),
                                'checked_id' => true,
                                'source_identifier' => $identifier,
                            ];
                        } else {
                            // TODO Set the source identifier anywhere (rare anyway).
                            $resource['o:item'] = [
                                'o:id' => end($itemIds),
                                'checked_id' => true,
                            ];
                        }
                    } elseif (array_key_exists($identifier . '§resources', $this->identifiers['map'])) {
                        $resource['o:item'] = [
                            // To be filled during real import.
                            'o:id' => null,
                            'checked_id' => true,
                            'source_identifier' => $identifier,
                        ];
                    } else {
                        // Only for first loop. Normally not possible after: all
                        // identifiers are stored in the list "map" during first loop.
                        $resource['messageStore']->addError('values', new PsrMessage(
                            'The value "{value}" is not an item.', // @translate
                            ['value' => mb_substr($identifier, 0, 50)]
                        ));
                    }
                }
                return true;
            case 'url':
                // TODO Check value first here?
                $value = end($values);
                $resource['o:ingester'] = 'url';
                $resource['ingest_url'] = $value;
                $resource['o:source'] = $value;
                return true;
            case 'tile':
                // Deprecated: "tile" is only a renderer, no more an ingester
                // since ImageServer version 3.6.13. All images are
                // automatically tiled, so "tile" is a format similar to large/medium/square,
                // but different.
            case 'file':
                // TODO Check value first here?
                $value = end($values);
                if ($this->bulk->isUrl($value)) {
                    $resource['o:ingester'] = 'url';
                    $resource['ingest_url'] = $value;
                } else {
                    $resource['o:ingester'] = 'sideload';
                    $resource['ingest_filename'] = $value;
                }
                $resource['o:source'] = $value;
                return true;
            case 'directory':
                $value = end($values);
                $resource['o:ingester'] = 'sideload_dir';
                $resource['ingest_directory'] = $value;
                $resource['o:source'] = $value;
                return true;
            case 'html':
                $value = end($values);
                $resource['o:ingester'] = 'html';
                $resource['html'] = $value;
                return true;
            case 'iiif':
                $value = end($values);
                $resource['o:ingester'] = 'iiif';
                $resource['ingest_url'] = null;
                if (!$this->bulk->isUrl($value)) {
                    $value = $this->getParam('iiifserver_media_api_url', '') . $value;
                }
                $resource['o:source'] = $value;
                return true;
            /*
            case 'tile':
                $value = end($values);
                $resource['o:ingester'] = 'tile';
                $resource['ingest_url'] = $value;
                $resource['o:source'] = $value;
                return true;
            */
            default:
                return false;
        }
    }

    /**
     * Append an attached resource to a resource, checking if it exists already.
     *
     * It allows to fill multiple media of an item, or any other related
     * resource, in multiple steps, for example the url, then the title.
     * Note: it requires that all elements to be set, in the same order, when
     * they are multiple.
     *
     * @param ArrayObject $resource
     * @param array $related
     * @param string $term
     * @param string $check
     */
    protected function appendRelated(
        ArrayObject $resource,
        array $related,
        $metadata = 'o:media',
        $check = 'o:ingester'
    ): self {
        if (!empty($resource[$metadata])) {
            foreach ($resource[$metadata] as $key => $values) {
                if (!array_key_exists($check, $values)) {
                    // Use the last data set.
                    $resource[$metadata][$key] = $related + $resource[$metadata][$key];
                    return $this;
                }
            }
        }
        $resource[$metadata][] = $related;
        return $this;
    }

    /**
     * @todo Use only core validations. Just remove flush in fact, but it may occurs anywhere or in modules.
     */
    protected function checkEntity(ArrayObject $resource): bool
    {
        if (empty($resource['resource_name'])) {
            $resource['messageStore']->addError('resource_name', new PsrMessage(
                'No resource type set.'  // @translate
            ));
            return false;
        }

        if (!in_array($resource['resource_name'], ['items', 'item_sets', 'media'])) {
            $resource['messageStore']->addError('resource_name', new PsrMessage(
                'The resource type "{resource_name}" is not managed.', // @translate
                ['resource_name' => $resource['resource_name']]
            ));
            return false;
        }

        return parent::checkEntity($resource);
    }

    protected function checkEntitySpecific(ArrayObject $resource): bool
    {
        if ($resource['resource_name'] === 'items') {
            return $this->checkItem($resource);
        } elseif ($resource['resource_name'] === 'item_sets') {
            return $this->checkItemSet($resource);
        } elseif ($resource['resource_name'] === 'media') {
            return $this->checkMedia($resource);
        } else {
            return !$resource['messageStore']->hasErrors();
        }
    }

    protected function checkItem(ArrayObject $resource): bool
    {
        // Media of an item are public by default.
        foreach ($resource['o:media'] as $key => $media) {
            if (is_string($media)) {
                $resource['o:media'][$key] = [
                    'o:ingester' => 'url',
                    'o:source' => $media,
                    'o:is_public' => true,
                ];
                $media = $resource['o:media'][$key];
            }
            if (!array_key_exists('o:is_public', $media) || is_null($media['o:is_public'])) {
                $resource['o:media'][$key]['o:is_public'] = true;
            }
        }

        // Manage the special case where an item is updated and a media is
        // provided: it should be identified too in order to update the one that
        // belongs to this specified item.
        // It cannot be done during mapping, because the id of the item is not
        // known from the media source. In particular, it avoids false positives
        // in case of multiple files with the same name for different items.
        if (!empty($resource['o:id']) && !empty($resource['o:media']) && $this->actionIsUpdate()) {
            foreach ($resource['o:media'] as $key => $media) {
                if (!empty($media['o:id'])) {
                    continue;
                }
                if (empty($media['o:source']) || empty($media['o:ingester'])) {
                    continue;
                }
                $identifierProperties = [];
                $identifierProperties['o:ingester'] = $media['o:ingester'];
                $identifierProperties['o:item']['o:id'] = $resource['o:id'];
                $resource['o:media'][$key]['o:id'] = $this->bulk->findResourceFromIdentifier($media['o:source'], $identifierProperties, 'media', $resource['messageStore']);
            }
        }

        // The check is needed to avoid a notice because it's an ArrayObject.
        if (property_exists($resource, 'o:item')) {
            unset($resource['o:item']);
        }

        return !$resource['messageStore']->hasErrors();
    }

    protected function checkItemSet(ArrayObject $resource): bool
    {
        // The check is needed to avoid a notice because it's an ArrayObject.
        if (property_exists($resource, 'o:item')) {
            unset($resource['o:item']);
        }
        if (property_exists($resource, 'o:item_set')) {
            unset($resource['o:item_set']);
        }
        if (property_exists($resource, 'o:media')) {
            unset($resource['o:media']);
        }
        return !$resource['messageStore']->hasErrors();
    }

    protected function checkMedia(ArrayObject $resource): bool
    {
        // When a resource type is unknown before the end of the filling of an
        // entry, fillItem() is called for item first, and there are some common
        // fields with media (the file related ones), so they should be moved
        // here.
        if (!empty($resource['o:media'])) {
            foreach ($resource['o:media'] as $media) {
                $resource += $media;
            }
        }

        // This check is useless now, because action when unidentified is "skip"
        // or "create" anyway. Furthermore, the id can be set later.
        /*
        if (empty($resource['o:id'])
            && ($this->actionRequiresId() || empty($resource['source_index']))
        ) {
            $resource['messageStore']->addError('resource_id', new PsrMessage(
                'No internal id can be found for the media' // @translate
            ));
            return false;
        }
        */

        if (empty($resource['o:id'])
            && empty($resource['o:item']['o:id'])
            && empty($resource['checked_id'])
        ) {
            if ($this->action !== self::ACTION_DELETE) {
                $resource['messageStore']->addError('resource_id', new PsrMessage(
                    'No item is set for the media.' // @translate
                ));
                return false;
            }
        }

        // The check is needed to avoid a notice because it's an ArrayObject.
        if (property_exists($resource, 'o:item_set')) {
            unset($resource['o:item_set']);
        }
        if (property_exists($resource, 'o:media')) {
            unset($resource['o:media']);
        }
        return !$resource['messageStore']->hasErrors();
    }

    /**
     * Create a new asset from a url.
     *
     * @see \BulkImport\Processor\AssetProcessor::createAsset()
     */
    protected function createAssetFromUrl(string $pathOrUrl, ?MessageStore $messageStore = null): ?\Omeka\Entity\Asset
    {
        $this->checkAssetMediaType = true;

        // AssetAdapter requires an uploaded file, but it's common to use urls
        // in bulk import.
        $result = $this->checkFileOrUrl($pathOrUrl, $messageStore);
        if (!$result) {
            return null;
        }

        $storageId = bin2hex(\Laminas\Math\Rand::getBytes(20));
        $filename = mb_substr(basename($pathOrUrl), 0, 255);
        // TODO Set the real extension via tempFile().
        $extension = pathinfo($pathOrUrl, PATHINFO_EXTENSION);

        $isUrl = $this->bulk->isUrl($pathOrUrl);
        if ($isUrl) {
            $result = $this->fetchUrl(
                'asset',
                $filename,
                $filename,
                $storageId,
                $extension,
                $pathOrUrl
            );
            if ($result['status'] !== 'success') {
                $messageStore->addError('file', $result['message']);
                return null;
            }
            $fullPath = $result['data']['fullpath'];
        } else {
            $isAbsolutePathInsideDir = strpos($pathOrUrl, $this->sideloadPath) === 0;
            $fileinfo = $isAbsolutePathInsideDir
                ? new \SplFileInfo($pathOrUrl)
                : new \SplFileInfo($this->sideloadPath . DIRECTORY_SEPARATOR . $pathOrUrl);
            $realPath = $fileinfo->getRealPath();
            $this->store->put($realPath, 'asset/' . $storageId . '.' . $extension);
            $fullPath = $this->basePath . '/asset/' . $storageId . '.' . $extension;
        }

        // A check to get the real media-type and extension.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mediaType = $finfo->file($fullPath);
        $mediaType = \Omeka\File\TempFile::MEDIA_TYPE_ALIASES[$mediaType] ?? $mediaType;
        // TODO Get the extension from the media type or use standard asset uploaded.

        // This doctrine resource should be reloaded each time the entity
        // manager is cleared, else a error may occur on big import.
        $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
        $this->user = $entityManager->find(\Omeka\Entity\User::class, $this->userId);

        $asset = new \Omeka\Entity\Asset;
        $asset->setName($filename);
        // TODO Use the user specified in the config (owner).
        $asset->setOwner($this->user);
        $asset->setStorageId($storageId);
        $asset->setExtension($extension);
        $asset->setMediaType($mediaType);
        $asset->setAltText(null);

        // TODO Remove this flush (required because there is a clear() after checks).
        $entityManager->persist($asset);
        $entityManager->flush();

        return $asset;
    }
}
