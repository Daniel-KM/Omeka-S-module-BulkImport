<?php declare(strict_types=1);

namespace BulkImport\Processor;

use ArrayObject;
use BulkImport\Form\Processor\ResourceProcessorConfigForm;
use BulkImport\Form\Processor\ResourceProcessorParamsForm;
use BulkImport\Stdlib\MessageStore;
use Log\Stdlib\PsrMessage;

/**
 * Can be used for all derivative of AbstractResourceEntityRepresentation.
 */
class ResourceProcessor extends AbstractResourceProcessor
{
    protected $resourceName = 'resources';

    protected $resourceLabel = 'Mixed resources'; // @translate

    protected $configFormClass = ResourceProcessorConfigForm::class;

    protected $paramsFormClass = ResourceProcessorParamsForm::class;

    /**
     * @see \Omeka\Api\Representation\ResourceReference
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
        // Alias of "o:owner" here.
        'o:email' => 'entity',
        // A common, but special and complex key, so managed in meta config too.
        'property' => 'arrays',
        // Generic.
        'o:resource_template' => 'entity',
        'o:resource_class' => 'entity',
        'o:thumbnail' => 'entity',
        // Item.
        'o:item_set' => 'entities',
        'o:media' => 'entities',
        // Media.
        'o:item' => 'entity',
        // Media, but there may be multiple urls, files, etc. for an item.
        'o:lang' => 'strings',
        'o:ingester' => 'strings',
        'o:source' => 'strings',
        'ingest_filename' => 'strings',
        'ingest_directory' => 'strings',
        'ingest_url' => 'strings',
        'html' => 'strings',
        // Item set.
        'o:is_open' => 'boolean',
        'o:items' => 'entities',
        // Modules.
        // Module Mapping.
        // There can be only one mapping zone.
        'o-module-mapping:bounds' => 'string',
        'o-module-mapping:marker' => 'strings',
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

    protected function handleFormGeneric(ArrayObject $args, array $values): self
    {
        $defaults = [
            'processing' => 'stop_on_error',
            'skip_missing_files' => false,
            'entries_to_skip' => 0,
            'entries_max' => 0,
            'info_diffs' => false,

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
        $result['skip_missing_files'] = (bool) $result['skip_missing_files'];
        $result['entries_to_skip'] = (int) $result['entries_to_skip'];
        $result['entries_max'] = (int) $result['entries_max'];
        $result['info_diffs'] = (bool) $result['info_diffs'];
        $result['value_datatype_literal'] = (bool) $result['value_datatype_literal'];
        $result['allow_duplicate_identifiers'] = (bool) $result['allow_duplicate_identifiers'];
        $result['o:resource_template'] = empty($result['o:resource_template']) ? null : (int) $result['o:resource_template'];
        $result['o:resource_class'] = empty($result['o:resource_class']) ? null : (string) $result['o:resource_class'];
        $result['o:owner'] = empty($result['o:owner']) ? null : (is_numeric($result['o:owner']) ? (int) $result['o:owner'] : (string) $result['o:owner']);
        $result['o:is_public'] = (bool) $result['o:is_public'];
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
            $ids = $this->bulkIdentifiers->findResourcesFromIdentifiers($values['o:item_set'], 'o:id', 'item_sets');
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
            $id = $this->bulkIdentifiers->findResourceFromIdentifier($values['o:item'], 'o:id', 'items');
            if ($id) {
                $args['o:item'] = ['o:id' => $id];
            }
        }
        return $this;
    }

    protected function prepareSpecific(): self
    {
        return $this
            ->prepareActionIdentifier()
            ->prepareActionMedia()
            ->prepareActionItemSet()
            ->appendInternalParams()
        ;
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
        if (empty($this->identifierNames)
            || (count($this->identifierNames) === 1 && reset($this->identifierNames) === 'o:id')
        ) {
            $this->actionIdentifier = self::ACTION_SKIP;
            return $this;
        }

        $this->actionIdentifier = $this->getParam('action_identifier_update') ?: self::ACTION_APPEND;
        if (!in_array($this->actionIdentifier, [
            self::ACTION_APPEND,
            self::ACTION_UPDATE,
        ])) {
            $this->logger->warn(
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
            $this->logger->warn(
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
            $this->logger->warn(
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
        $internalParams = [];
        $internalParams['iiifserver_media_api_url'] = $this->settings->get('iiifserver_media_api_url', '');
        if ($internalParams['iiifserver_media_api_url']
            && mb_substr($internalParams['iiifserver_media_api_url'], -1) !== '/'
        ) {
            $internalParams['iiifserver_media_api_url'] .= '/';
        }
        $this->setParams(array_merge($this->getParams() + $internalParams));
        return $this;
    }

    protected function prepareBaseEntitySpecific(ArrayObject $resource): self
    {
        $this
            ->prepareBaseResourceCommon($resource)
            // May be determined by the mapping or the entry.
            ->prepareBaseItem($resource)
            ->prepareBaseItemSet($resource)
            ->prepareBaseMedia($resource);
        $resource['resource_name'] = $this->getParam('resource_name');
        return $this;
    }

    protected function prepareBaseResourceCommon(ArrayObject $resource): self
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

    protected function prepareBaseItem(ArrayObject $resource): self
    {
        $resource['resource_name'] = 'items';
        $resource['o:item_set'] = $this->getParam('o:item_set', []);
        $resource['o:media'] = [];
        return $this;
    }

    protected function prepareBaseItemSet(ArrayObject $resource): self
    {
        $resource['resource_name'] = 'item_sets';
        $isOpen = $this->getParam('o:is_open', null);
        $resource['o:is_open'] = $isOpen;
        return $this;
    }

    protected function prepareBaseMedia(ArrayObject $resource): self
    {
        $resource['resource_name'] = 'media';
        $resource['o:item'] = $this->getParam('o:item') ?: ['o:id' => null];
        return $this;
    }

    /**
     * Convert a prepared entry into a resource.
     *
     * So fill owner id, resource template id, resource class id, property ids.
     */
    protected function fillResourceFields(ArrayObject $resource, array $data): self
    {
        $this
            // Fill the resource name first when possible.
            ->fillResourceName($resource, $data)
            ->fillResourceId($resource, $data)
            ->fillResourceSingleEntities($resource, $data);

        // Manage properties.
        $properties = $this->bulk->propertyIds();
        foreach (array_intersect_key($data, $properties) as $term => $values) {
            $this->fillProperty($resource, $term, $values);
        }

        // Get the resource id early when the identifier is set in a property,
        // so not yet filled. It simplifies updating resources created in the
        // same source.
        if ($this->actionRequiresId() && empty($resource['checked_id'])) {
            $identifierId = $this->bulkIdentifiers->getIdFromResourceValues($resource->getArrayCopy());
            if ($identifierId) {
                $resource['o:id'] = reset($identifierId);
                $resource['checked_id'] = true;
                $resource['source_identifier'] = key($identifierId);
            }
        }

        $this
            ->fillResourceSpecific($resource, $data);

        $mainResourceName = $resource['resource_name'];

        // This fonction is used for sub-resources, so don't mix with main one.
        // TODO Factorize with fillResource().
        $fillResourceDataAndProperties = function (array $dataArray, ?string $resourceName) use ($properties, $mainResourceName): array {
            // Fill other metadata (for media and item set).
            $resource = new ArrayObject($dataArray);
            if ($resourceName && empty($resource['resource_name'])) {
                $resource['resource_name'] = $resourceName;
            }
            $this
                ->fillResourceName($resource, $dataArray)
                ->fillResourceData($resource, $dataArray)
                ->fillResourceId($resource, $dataArray)
                ->fillResourceSingleEntities($resource, $dataArray);
            foreach (array_intersect_key($dataArray, $properties) as $term => $values) {
                $this->fillProperty($resource, $term, $values);
            }
            $resourceName = $resource['resource_name'] ?? 'resources';
            if ($resourceName === 'items') {
                $this->fillItem($resource, $dataArray, );
            } elseif ($resourceName === 'media') {
                $this->fillMedia($resource, $dataArray, );
            } elseif ($resourceName === 'item_sets') {
                $this->fillItemSet($resource, $dataArray, );
            } else {
                $this
                    ->fillResourceSpecific($resource, $dataArray, $mainResourceName);
            }
            return $resource->getArrayCopy();
        };

        // Do the same recursively for sub-resources (multiple entity keys:
        // "o:media" and "o:item_set" for items, assets for resources).
        // Only one level is managed for now, so use the function above instead
        // of the parent one.
        foreach (array_intersect_key($data, array_flip(array_keys($this->fieldTypes, 'entities'))) as $field => $subResources) {
            $resourceName = $this->bulk->resourceName($field);
            foreach (array_values($subResources) as $key => $dataArray) {
                // If the source is only a string, it is an identifier that is
                // already filled, mainly item sets for item.
                if (is_array($dataArray)) {
                    $resource[$field][$key] = $fillResourceDataAndProperties($dataArray, $resourceName);
                }
            }
        }

        return $this;
    }

    protected function fillResourceSingleEntities(ArrayObject $resource, array $data): self
    {
        // Entities are not processed in the meta mapper, neither by the
        // processor above, so it is always an array of strings or arrays
        // to copy from data into resource.

        parent::fillResourceSingleEntities($resource, $data);

        // Don't fill item set for item or item for media here, only common data.

        $currentFieldTypes = empty($resource['resource_name']) || $resource['resource_name'] === $this->resourceName
            ? $this->fieldTypes
            : $this->getFieldTypesForResource($resource['resource_name']);
        $fields = array_keys($currentFieldTypes, 'entity');
        $fields = array_combine($fields, $fields);

        // Remove processed fields.
        unset($fields['o:owner'], $fields['o:email']);

        // Keep only the last value because this is a single entity.
        // end() cannot be used with array_map.
        $lastData = [];
        foreach (array_intersect_key($data, $fields) as $field => $values) {
            $lastData[$field] = is_array($values) ? end($values) : $values;
        }

        // Get the entity id for all entities that have a value.
        foreach (array_intersect_key($lastData, $fields) as $field => $value) switch ($field) {
            case 'o:resource_template':
                if (!$value) {
                    $resource[$field] = null;
                    continue 2;
                }
                if (is_array($value)) {
                    $id = empty($value['o:id']) ? null : $value['o:id'];
                    $label = empty($value['o:label']) ? null : $value['o:label'];
                    $value = $id ?? $label ?? reset($value);
                }
                $id = $this->bulk->resourceTemplateId($value);
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
                continue 2;

            case 'o:resource_class':
                if (!$value) {
                    $resource[$field] = null;
                    continue 2;
                }
                if (is_array($value)) {
                    $id = empty($value['o:id']) ? null : $value['o:id'];
                    $term = empty($value['o:term']) ? null : $value['o:term'];
                    $value = $id ?? $term ?? reset($value);
                }
                $id = $this->bulk->resourceClassId($value);
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
                continue 2;

            case 'o:thumbnail':
                if (!$value) {
                    $resource[$field] = null;
                    continue 2;
                }
                if (is_array($value)) {
                    $id = empty($value['o:id']) ? null : $value['o:id'];
                    $url = empty($value['ingest_url']) ? null : $value['ingest_url'];
                    $altText = empty($value['o:alt_text']) ? null : $value['o:alt_text'];
                    $value = $id ?? $url ?? reset($value);
                }
                if (is_numeric($value)) {
                    $id = $this->getAssetId($value);
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
                    $resource['o:thumbnail'] = null;
                    $resource['messageStore']->addError('values', new PsrMessage(
                        'The thumbnail "{source}" does not exist or cannot be created.', // @translate
                        ['source' => $value]
                    ));
                }
                continue 2;

            default:
                continue 2;
        }

        return $this;
    }

    /**
     * Fill specific for any resource when mixed resource is selected in import.
     * It can be used for linked resources too.
     *
     * This method is used for mixed resources only.
     *
     * @todo Add the possibility to mix resources and assets.
     */
    protected function fillResourceSpecific(ArrayObject $resource, array $data, ?string $mainResourceName = null): self
    {
        // TODO It may be an item set too. Ideally, it should be checked with the presence of specific fields.
        if (empty($resource['resource_name']) && $mainResourceName === 'items') {
            $resource['resource_name'] = 'media';
        }

        // Check first if the resource name is set in the metadata.
        $defaultResourceName = $this->getParam('resource_name');
        $resourceName = empty($resource['resource_name'])
            ? $defaultResourceName
            : $resource['resource_name'];

        // The resource name may be filled by another processor.

        // Normalize the resource name.
        if ($resourceName) {
            $resourceName = $this->bulk->resourceName($resourceName)
                ?? $this->bulk->resourceName(mb_strtolower($resourceName))
                ?? $this->resourceNamesMore[mb_strtolower($resourceName)]
                ?? null;
            if (!$resourceName) {
                return $this;
            }
        }

        if ($resourceName === 'items') {
            $this->fillItem($resource, $data);
        } elseif ($resourceName === 'media') {
            $this->fillMedia($resource, $data);
        } elseif ($resourceName === 'item_sets') {
            $this->fillItemSet($resource, $data);
        }

        return $this;
    }

    protected function fillItem(ArrayObject $resource, array $data): self
    {
        // Remove keys that are not present in item for resources.
        // Remove notice when a key is not present.
        $errorReporting = error_reporting();
        error_reporting(0);
        unset(
            $resource['o:email'],
            $resource['o:item'],
            $resource['o:lang'],
            $resource['o:ingester'],
            $resource['o:source'],
            $resource['ingest_filename'],
            $resource['ingest_directory'],
            $resource['ingest_url'],
            $resource['html'],
            $resource['o:items'],
            $resource['o:is_open']
        );
        error_reporting($errorReporting);

        foreach ($resource as $field => $values) switch ($field) {
            default:
                continue 2;

            case 'o:item_set':
                // TODO Allow to use specific identifier names like "o:item_set/dcterms:title".
                $identifierNames = $this->identifierNames;
                // Check values one by one to manage source identifiers.
                foreach ($values as $key => $value) {
                    // May be already filled.
                    if (is_array($value) && !empty($value['o:id'])) {
                        if (!empty($value['checked_id'])) {
                            continue;
                        }
                        if ($this->bulkIdentifiers->findResourcesFromIdentifiers($value['o:id'], 'o:id', 'item_sets', $resource['messageStore'])) {
                            $resource['o:item']['checked_id'] = true;
                        } else {
                            $resource['messageStore']->addError('values', new PsrMessage(
                                'The item set #{item_set_id} does not exist.', // @translate
                                ['item_set_id' => $value['o:id']]
                            ));
                        }
                        continue;
                    }
                    $identifier = is_array($value)
                        ? (array_key_exists('o:id', $value) ? $value['o:id'] : end($value))
                        : $value;
                    $storedId = $this->bulkIdentifiers->getId($value, 'item_sets');
                    if ($storedId) {
                        $resource['o:item_set'][$key] = [
                            'o:id' => $storedId,
                            'checked_id' => true,
                            'source_identifier' => $identifier,
                            'resource_name' => 'item_sets',
                        ];
                    } elseif ($this->bulkIdentifiers->isPreparedIdentifier($identifier, 'item_sets')) {
                        $resource['o:item_set'][$key] = [
                            // To be filled during real import if empty.
                            'o:id' => null,
                            'checked_id' => true,
                            'source_identifier' => $identifier,
                            'resource_name' => 'item_sets',
                        ];
                    } else {
                        $itemSetId = $this->bulkIdentifiers->findResourcesFromIdentifiers($identifier, $identifierNames, 'item_sets', $resource['messageStore']);
                        if ($itemSetId) {
                            $resource['o:item_set'][$key] = [
                                'o:id' => $itemSetId,
                                'checked_id' => true,
                                'source_identifier' => $value,
                                'resource_name' => 'item_sets',
                            ];
                        } else {
                            // Only for first loop. Normally not possible after:
                            // all identifiers are stored in the list "map"
                            // during first loop.
                            $valueForMsg = mb_strlen($value) > 120 ? mb_substr($value, 0, 120) . '…' : $value;
                            $resource['messageStore']->addError('values', new PsrMessage(
                                'The value "{value}" is not an item set.', // @translate
                                ['value' => $valueForMsg]
                            ));
                        }
                    }
                }
                continue 2;

            case 'directory':
            case 'file':
            case 'html':
            case 'iiif':
            case 'tile':
            case 'url':
                // These values are already filled in the resource.
                foreach ($values as $value) {
                    $mediaData = $this->prepareMediaData($resource, $field, $value);
                    if ($mediaData) {
                        $resource['o:media'][] = $mediaData;
                    }
                }
                continue 2;

            case 'o:media':
                // Unlike item sets, the media cannot be created before and
                // attached to another item.
                // The media may be fully filled early (json or xml).
                foreach ($values as $key => $value) {
                    $media = $value;
                    $media['resource_name'] = 'media';
                    $resource['o:media'][$key] = $media;
                }
                continue 2;

            case 'o-module-mapping:bounds':
                if (!$values) {
                    $resource[$field] = null;
                    continue 2;
                }
                $bounds = $values;
                /** @see \Mapping\Api\Adapter\MappingAdapter::validateEntity() */
                if (null !== $bounds
                    && 4 !== count(array_filter(explode(',', $bounds), 'is_numeric'))
                ) {
                    $resource['messageStore']->addError('values', new PsrMessage(
                        'The mapping bounds requires four numeric values separated by a comma. Incorrect value: {value}',  // @translate
                        ['value' => $value]
                    ));
                } else {
                    // TODO Manage the update of a mapping.
                    $resource['o-module-mapping:mapping'] = [
                        'o-id' => null,
                        'o-module-mapping:bounds' => $bounds,
                    ];
                }
                continue 2;

            case 'o-module-mapping:marker':
                /** @see \Mapping\Api\Adapter\MappingMarkerAdapter::validateEntity() */
                foreach ($values as $key => $value) {
                    [$lat, $lng] = array_filter(array_map('trim', explode('/', $value, 2)), 'is_numeric');
                    if (!strlen($lat) || !strlen($lng)) {
                        unset($resource['o-module-mapping:marker'][$key]);
                        $resource['messageStore']->addError('values', new PsrMessage(
                            'The mapping marker requires a latitude and a longitude separated by a "/". Incorrect value: {value}',  // @translate
                            ['value' => $value]
                        ));
                    } else {
                        $resource['o-module-mapping:marker'][$key] = [
                            'o:id' => null,
                            'o-module-mapping:lat' => $lat,
                            'o-module-mapping:lng' => $lng,
                            'o-module-mapping:label' => null,
                        ];
                    }
                }
                continue 2;
        }

        return $this;
    }

    protected function fillItemSet(ArrayObject $resource, array $data): self
    {
        // Remove keys that are not present in item set for resources.
        // Remove notice when a key is not present.
        $errorReporting = error_reporting();
        error_reporting(0);
        unset(
            $resource['o:email'],
            $resource['o:item'],
            $resource['o:media'],
            $resource['o:item_set'],
            $resource['o:lang'],
            $resource['o:ingester'],
            $resource['o:source'],
            $resource['ingest_filename'],
            $resource['ingest_directory'],
            $resource['ingest_url'],
            $resource['html'],
            $resource['o-module-mapping:bounds'],
            $resource['o-module-mapping:marker']
        );
        error_reporting($errorReporting);

        // Only "o:is_open" is specific to item sets, but already processed via
        // fillResourceData().

        return $this;
    }

    protected function fillMedia(ArrayObject $resource, array $data): self
    {
        // Remove keys that are not present in media for resources.
        // Remove notice when a key is not present.
        $errorReporting = error_reporting();
        error_reporting(0);
        unset(
            $resource['o:email'],
            $resource['o:media'],
            $resource['o:item_set'],
            $resource['o:item_sets'],
            $resource['o:items'],
            $resource['o:is_open']
        );
        error_reporting($errorReporting);

        foreach ($resource as $field => $values) switch ($field) {
            default:
                continue 2;

            case 'o:ingester':
            case 'ingest_filename':
            case 'ingest_directory':
            case 'ingest_uri':
                $resource[$field] = (string) (is_array($values) ? end($values) : $values) ?: null;
                break;

            case 'o:filename':
            case 'o:basename':
            case 'o:storage_id':
            case 'o:source':
            case 'o:sha256':
                // These values may be overridden by an ingester.
                $value = is_array($values) ? end($values) : $values;
                if (!$value) {
                    $resource[$field] = null;
                    continue 2;
                }
                if (!in_array($field, $this->identifierNames)) {
                    $resource[$field] = $value;
                    continue 2;
                }
                // Get the identifier from a specific source.
                $id = $this->bulkIdentifiers->getIdFromIndex($resource['source_index'])
                    ?: $this->bulkIdentifiers->findResourceFromIdentifier($value, $field, 'media', $resource['messageStore']);
                if ($id) {
                    $resource['o:id'] = $id;
                    $resource['checked_id'] = true;
                } else {
                    $resource['messageStore']->addError('identifier', new PsrMessage(
                        'Media with metadata "{field}" "{identifier}" cannot be found. The entry is skipped.', // @translate
                        ['field' => $field, 'identifier' => $value]
                    ));
                }
                continue 2;

            case 'o:item':
                // May be already filled.
                $value = $values;
                if (is_array($value) && !empty($value['o:id'])) {
                    if (!empty($value['checked_id'])) {
                        continue 2;
                    }
                    if ($this->bulkIdentifiers->findResourcesFromIdentifiers($value['o:id'], 'o:id', 'items', $resource['messageStore'])) {
                        $resource['o:item']['checked_id'] = true;
                    } else {
                        $resource['messageStore']->addError('values', new PsrMessage(
                            'The item #{item_id} does not exist.', // @translate
                            ['item_id' => $value['o:id']]
                        ));
                    }
                    continue 2;
                }
                $identifier = is_array($value)
                    ? (array_key_exists('o:id', $value) ? $value['o:id'] : end($value))
                    : $value;
                if (!$identifier) {
                    // The item is required, so skip during first loop.
                } elseif ($storedId = $this->bulkIdentifiers->getId($identifier, 'items')) {
                    $resource['o:item'] = [
                        'o:id' => $storedId,
                        'checked_id' => true,
                        'source_identifier' => $identifier,
                        'resource_name' => 'items',
                    ];
                } elseif ($this->bulkIdentifiers->isPreparedIdentifier($identifier, 'items')) {
                    $resource['o:item'] = [
                        // To be filled during real import.
                        'o:id' => null,
                        'checked_id' => true,
                        'source_identifier' => $identifier,
                        'resource_name' => 'items',
                    ];
                } else {
                    $itemIds = $this->bulkIdentifiers->findResourcesFromIdentifiers($values, $this->identifierNames, 'items', $resource['messageStore']);
                    if ($itemIds) {
                        if (count($values) === 1) {
                            $resource['o:item'] = [
                                'o:id' => end($itemIds),
                                'checked_id' => true,
                                'source_identifier' => $identifier,
                                'resource_name' => 'items',
                            ];
                        } else {
                            // TODO Set the source identifier anywhere (rare anyway).
                            $resource['o:item'] = [
                                'o:id' => end($itemIds),
                                'checked_id' => true,
                                'resource_name' => 'items',
                            ];
                        }
                    } else {
                        // Only for first loop. Normally not possible after: all
                        // identifiers are stored in the list "map" during first loop.
                        $identifier = (string) $identifier;
                        $valueForMsg = mb_strlen($identifier) > 120 ? mb_substr($identifier, 0, 120) . '…' : $identifier;
                        $resource['messageStore']->addError('values', new PsrMessage(
                            'The value "{value}" is not an item.', // @translate
                            ['value' => $valueForMsg]
                        ));
                    }
                }
                continue 2;

            case 'directory':
            case 'file':
            case 'html':
            case 'iiif':
            case 'tile':
            case 'url':
                $value = is_array($values) ? end($values) : $values;
                $mediaData = $this->prepareMediaData($resource, $field, $value);
                // Resource is an ArrayObject, so array_replace cannot be used.
                foreach ($mediaData as $field => $value) {
                    $resource[$field] = $value;
                }
                continue 2;
        }

        return $this;
    }

    /**
     * Prepare media data and get result array.
     *
     * The resource may be an item or a media.
     */
    protected function prepareMediaData(ArrayObject $resource, string $field, $value): array
    {
        if (!$value) {
            return [];
        }

        switch ($field) {
            case 'url':
                $media = [];
                $media['resource_name'] = 'media';
                $media['o:ingester'] = 'url';
                $media['ingest_url'] = $value;
                $media['o:source'] = $value;
                return $media;

            case 'tile':
                // Deprecated: tiles are now only a rendering, not an ingester
                // since ImageServer version 3.6.13. All images are
                // automatically tiled, so "tile" is a format similar to large/medium/square,
                // but different.
                /*
                $media = [];
                $media['resource_name'] = 'media';
                $media['o:ingester'] = 'tile';
                $media['ingest_url'] = $value;
                $media['o:source'] = $value;
                return $media;
                */
                // no break.
            case 'file':
                // A file may be a url for end-user simplicity.
                $media = [];
                $media['resource_name'] = 'media';
                $media['o:source'] = $value;
                if ($this->bulk->isUrl($value)) {
                    $media['o:ingester'] = 'url';
                    $media['ingest_url'] = $value;
                } elseif ($filepath = $this->bulkFileUploaded->getFileUploaded($value)) {
                    /** @see \BulkImport\Media\Ingester\Bulk */
                    // Not fluid.
                    $tempFile = $this->tempFileFactory->build();
                    $tempFile->setTempPath($filepath);
                    $tempFile->setSourceName($value);
                    $media['o:ingester'] = 'bulk';
                    $media['ingest_ingester'] = 'upload';
                    $media['ingest_tempfile'] = $tempFile;
                    // Don't delete file before validation (that uses
                    // hydration and removes file);
                    // $media['ingest_delete_file'] = true;
                    $media['ingest_filename'] = $value;
                } else {
                    $media['o:ingester'] = 'sideload';
                    $media['ingest_filename'] = $value;
                }
                return $media;

            case 'directory':
                $media = [];
                $media['resource_name'] = 'media';
                $media['o:ingester'] = 'sideload_dir';
                $media['ingest_directory'] = $value;
                $media['o:source'] = $value;
                return $media;

            case 'html':
                $media = [];
                $media['resource_name'] = 'media';
                $media['o:ingester'] = 'html';
                $media['html'] = $value;
                return $media;

            case 'iiif':
                $media = [];
                $media['resource_name'] = 'media';
                $media['o:ingester'] = 'iiif';
                $media['ingest_url'] = null;
                if (!$this->bulk->isUrl($value)) {
                    $value = $this->getParam('iiifserver_media_api_url', '') . $value;
                }
                $media['o:source'] = $value;
                return $media;

            default:
                return [];
        }
    }

    /**
     * Fill the values of properties of a resource.
     *
     * Values are prefilled via the meta mapping, so original values are usually
     * useless.
     */
    protected function fillProperty(ArrayObject $resource, string $term, array $values): self
    {
        // Normally, the property id is already set.
        $propertyId = $this->bulk->propertyId($term);

        // The datatype should be checked for each value. The value is checked
        // against each datatype and get the first valid one.
        foreach ($resource[$term] as $indexValue => $value) {
            // Some mappers fully format the value.
            $val = $value['value_resource_id'] ?? $value['@id'] ?? $value['@value'] ?? $value['o:label'] ?? $value['value'] ?? $value['__value'] ?? null;

            // There should be a value.
            if ($val === null || $val === [] || $val === '') {
                unset($resource[$term][$indexValue]);
                continue;
            }

            // Refill property id even if is already filled.
            $value['property_id'] = $propertyId;
            $resource[$term][$indexValue] = $value;

            // The data type may be set early.
            $hasDatatype = false;
            $dataType = empty($value['type']) ? null : $value['type'];
            $dataTypeNames = $dataType ? [$dataType] : ($value['datatype'] ?: ['literal']);

            // The data type name is normally already checked, but may be empty.
            // So find the right data type and update the resource with it.
            foreach ($dataTypeNames as $dataTypeName) {
                /** @var \Omeka\DataType\DataTypeInterface $dataType */
                $dataType = $this->bulk->dataType($dataTypeName);
                if (!$dataType) {
                    // The message is set below.
                    continue;
                }
                // Use the real data type name, mainly for custom vocab.
                $dataTypeName = $dataType->getName();
                $mainDataType = $this->bulk->dataTypeMain($dataTypeName);
                if ($dataTypeName === 'literal') {
                    $this->fillPropertyForValue($resource, $indexValue, $term, $dataTypeName, $value);
                    $hasDatatype = true;
                    break;
                } elseif ($mainDataType === 'resource') {
                    $vrId = $this->bulkIdentifiers->getId($val, 'resources')
                        ?: $this->bulkIdentifiers->findResourceFromIdentifier($val, null, substr($dataTypeName, 0, 11) === 'customvocab' ? 'item' : $dataTypeName, $resource['messageStore']);
                    // Normally always true after first loop: all identifiers
                    // are stored first.
                    if ($vrId || $this->bulkIdentifiers->isPreparedIdentifier($val, 'resources')) {
                        $this->fillPropertyForValue($resource, $indexValue, $term, $dataTypeName, $value, $vrId ? (int) $vrId : null);
                        $hasDatatype = true;
                        break;
                    }
                } elseif (substr($dataTypeName, 0, 11) === 'customvocab') {
                    if ($this->bulk->isCustomVocabMember($dataTypeName, $val)) {
                        $this->fillPropertyForValue($resource, $indexValue, $term, $dataTypeName, $value);
                        $hasDatatype = true;
                        break;
                    }
                } elseif ($mainDataType === 'uri'
                    // Deprecated.
                    || $dataTypeName === 'uri-label'
                ) {
                    if ($this->bulk->isUrl($val)) {
                        $this->fillPropertyForValue($resource, $indexValue, $term, $dataTypeName, $value);
                        $hasDatatype = true;
                        break;
                    }
                } else {
                    // Some data types may be more complex than "@value", but it
                    // manages most of the common other modules.
                    $value = ['@value' => $val];
                    if ($dataType->isValid($value)) {
                        $this->fillPropertyForValue($resource, $indexValue, $term, $dataTypeName, $value);
                        $hasDatatype = true;
                        break;
                    }
                }
            }

            if (!$hasDatatype) {
                if ($this->useDatatypeLiteral) {
                    $this->fillPropertyForValue($resource, $indexValue, $term, 'literal', $value);
                    $val = (string) $val;
                    $valueForMsg = mb_strlen($val) > 120 ? mb_substr($val, 0, 120) . '…' : $val;
                    if ($this->bulk->dataTypeMain(reset($dataTypeNames)) === 'resource') {
                        $resource['messageStore']->addNotice('values', new PsrMessage(
                            'The value "{value}" for property "{term}" is not compatible with datatypes "{datatypes}". Try to create the resource first. Data type "literal" is used.', // @translate
                            ['value' => $valueForMsg, 'term' => $term, 'datatypes' => implode('", "', $dataTypeNames)]
                        ));
                    } else {
                        $resource['messageStore']->addNotice('values', new PsrMessage(
                            'The value "{value}" for property "{term}" is not compatible with datatypes "{datatypes}". Data type "literal" is used.', // @translate
                            ['value' => $valueForMsg, 'term' => $term, 'datatypes' => implode('", "', $dataTypeNames)]
                        ));
                    }
                } else {
                    if ($this->bulk->dataTypeMain(reset($dataTypeNames)) === 'resource') {
                        $resource['messageStore']->addError('values', new PsrMessage(
                            'The value "{value}" for property "{term}" is not compatible with datatypes "{datatypes}". Try to create resource first. Or try to add "literal" to datatypes or default to it.', // @translate
                            ['value' => $valueForMsg, 'term' => $term, 'datatypes' => implode('", "', $dataTypeNames)]
                        ));
                    } else {
                        $resource['messageStore']->addError('values', new PsrMessage(
                            'The value "{value}" for property "{term}" is not compatible with datatypes "{datatypes}". Try to add "literal" to datatypes or default to it.', // @translate
                            ['value' => $valueForMsg, 'term' => $term, 'datatypes' => implode('", "', $dataTypeNames)]
                        ));
                    }
                }
            }
        }

        return $this;
    }

    /**
     * Fill a value of a property.
     *
     * The main checks should be already done, in particular the data type and
     * the value resource id (vrId).
     *
     * The value is prefilled via the meta mapping.
     * The value may be a valid value array (with filled key @value or
     * value_resource_id or @id) or an array with a key "__value" for the
     * extracted value by the meta mapper.
     */
    protected function fillPropertyForValue(
        ArrayObject $resource,
        int $indexValue,
        string $term,
        string $dataType,
        array $value,
        ?int $vrId = null
    ): self {
        // Common data for all data types.
        $resourceValue = [
            'type' => $dataType,
            'property_id' => $value['property_id'] ?? $this->bulk->propertyId($term),
            'is_public' => $value['is_public'] ?? true,
        ];

        $mainDataType = $this->bulk->dataTypeMain($dataType);

        // Some mappers fully format the value.
        $val = $value['value_resource_id'] ?? $value['@id'] ?? $value['@value'] ?? $value['o:label'] ?? $value['value'] ?? $value['__value'] ?? null;

        // Manage special datatypes first.
        $isCustomVocab = substr($dataType, 0, 11) === 'customvocab';
        if ($isCustomVocab) {
            $vridOrVal = (string) ($mainDataType === 'resource' ? $vrId ?? $val : $val);
            $result = $this->bulk->isCustomVocabMember($dataType, $vridOrVal);
            if (!$result) {
                $valueForMsg = mb_strlen($vridOrVal) > 120 ? mb_substr($vridOrVal, 0, 120) . '…' : $vridOrVal;
                if (!$this->useDatatypeLiteral) {
                    $resource['messageStore']->addError('values', new PsrMessage(
                        'The value "{value}" for property "{term}" is not member of custom vocab "{customvocab}".', // @translate
                        ['value' => $valueForMsg, 'term' => $term, 'customvocab' => $dataType]
                    ));
                    return $this;
                }
                $dataType = 'literal';
                $resource['messageStore']->addNotice('values', new PsrMessage(
                    'The value "{value}" for property "{term}" is not member of custom vocab "{customvocab}". A literal value is used instead.', // @translate
                    ['value' => $valueForMsg, 'term' => $term, 'customvocab' => $dataType]
                ));
            }
        }

        // The value will be checked later.

        switch ($dataType) {
            default:
            case 'literal':
                $resourceValue['@value'] = $val ?? '';
                $resourceValue['@language'] = ($value['@language'] ?? $value['o:lang'] ?? $value['language'] ?? null) ?: null;
                break;

            case 'uri-label':
                // "uri-label" is deprecated: use simply "uri".
            case $mainDataType === 'uri':
                // case 'uri':
                // case substr($dataType, 0, 12) === 'valuesuggest':
                $uriOrVal = trim((string) $val);
                if (strpos($uriOrVal, ' ')) {
                    [$uri, $label] = explode(' ', $uriOrVal, 2);
                    $label = trim($label);
                    if (!strlen($label)) {
                        $label = null;
                    }
                    $resourceValue['@id'] = $uri;
                    $resourceValue['o:label'] = $label;
                } else {
                    $resourceValue['@id'] = $val;
                    // The label may be set early too.
                    $resourceValue['o:label'] = $resourceValue['o:label'] ?? null;
                }
                $resourceValue['o:lang'] = ($value['o:lang'] ?? $value['@language'] ?? $value['language'] ?? null) ?: null;
                break;

            // case 'resource':
            // case 'resource:item':
            // case 'resource:itemset':
            // case 'resource:media':
            // case 'resource:annotation':
            case $mainDataType === 'resource':
                $resourceValue['value_resource_id'] = $vrId;
                $resourceValue['@language'] = ($value['@language'] ?? $value['o:lang'] ?? $value['language'] ?? null) ?: null;
                $resourceValue['source_identifier'] = $val;
                $resourceValue['checked_id'] = true;
                break;

            // TODO Support other special data types here for geometry, numeric, etc.
        }

        unset(
            $value['__value'],
            $value['language'],
            $value['datatype'],
        );

        $resource[$term][$indexValue] = $resourceValue;

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
            if (!array_key_exists('o:is_public', $media) || $media['o:is_public'] === null) {
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
                $resource['o:media'][$key]['o:id'] = $this->bulkIdentifiers->findResourceFromIdentifier($media['o:source'], $identifierProperties, 'media', $resource['messageStore']);
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
        // AssetAdapter requires an uploaded file, but it's common to use urls
        // in bulk import.
        $this->bulkFile->setIsAsset(true);
        $result = $this->bulkFile->checkFileOrUrl($pathOrUrl, $messageStore);
        if (!$result) {
            return null;
        }

        $storageId = bin2hex(\Laminas\Math\Rand::getBytes(20));
        $filename = mb_substr(basename($pathOrUrl), 0, 255);
        // TODO Set the real extension via tempFile().
        $extension = pathinfo($pathOrUrl, PATHINFO_EXTENSION);

        // TODO Check why the asset for thumbnail of the resource is not prepared when it is a url. See ResourceProcessor.
        $result = $this->bulkFile->fetchAndStore(
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

        $mediaType = $this->bulkFile->getMediaType($fullPath);

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

    /**
     * Check if an asset exists from id.
     */
    protected function getAssetId($id): ?int
    {
        $id = (int) $id;
        return $id
            ? $this->api->searchOne('assets', ['id' => $id])->getContent()
            : null;
    }
}
