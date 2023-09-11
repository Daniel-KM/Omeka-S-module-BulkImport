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
            /*
            // But there can be multiple urls, files, etc. for an item.
            // Media.
            'o:lang' => null,
            'o:ingester' => null,
            'o:source' => null,
            'ingest_filename' => null,
            'ingest_directory' => null,
            'ingest_url' => null,
            'html' => null,
            */
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
        'misc' => [
            'o:id' => null,
            'o:email' => null,
            'o:created' => null,
            'o:modified' => null,
        ],
    ];

    /**
     * @see \Omeka\Api\Representation\AssetRepresentation
     *
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
            $ids = $this->findResourcesFromIdentifiers($values['o:item_set'], 'o:id', 'item_sets');
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
            $id = $this->findResourceFromIdentifier($values['o:item'], 'o:id', 'items');
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
     * Convert a prepared entry into a resource, setting ids for each key.
     *
     * So fill owner id, resource template id, resource class id, property ids.
     * Check boolean values too for is public and is open.
     */
    protected function fillResourceData(ArrayObject $resource, array $data): self
    {
        $this
            ->fillGeneric($resource, $data)
            ->fillSpecific($resource, $data);

        $properties = $this->bulk->propertyIds();
        foreach (array_intersect_key($data, $properties) as $term => $values) {
            $this->fillProperty($resource, $term, $values);
        }

        // TODO Fill the source identifiers of the main resource here.
        $mainResourceName = $resource['resource_name'];

        // This fonction is used for sub-resources, so don't mix with main one.
        // TODO Factorize with fillResource().
        $fillPropertiesAndResourceData = function (array $resourceArray) use ($properties, $mainResourceName): array {
            // Fill other metadata (for media and item set).
            $resource = new ArrayObject($resourceArray);
            foreach (array_intersect_key($resourceArray, $this->metadataData['boolean']) as $field => $values) {
                $this->fillBoolean($resource, $field, $resource[$field]);
            }
            foreach (array_intersect_key($resourceArray, $this->metadataData['single_data']) as $field => $values) {
                $this->fillSingleData($resource, $field, $resource[$field]);
            }
            foreach (array_intersect_key($resourceArray, $this->metadataData['single_entity']) as $field => $values) {
                $this->fillSingleEntity($resource, $field, $resource[$field]);
            }
            $this
                ->fillGeneric($resource, $resourceArray)
                ->fillSpecific($resource, $resourceArray, $mainResourceName);
            foreach (array_intersect_key($resourceArray, $properties) as $term => $values) {
                $this->fillProperty($resource, $term, $values);
            }
            // TODO Fill the source identifiers of related resources and other single data.
            return $this;
        };

        // Do the same recursively for sub-resources (multiple entity keys:
        // "o:media" and "o:item_set" for items, assets for resources).
        // Only one level is managed for now, so use the function above instead
        // of the parent one.
        foreach (array_intersect_key($data, $this->metadataData['multiple_entities']) as $key => $subResources) {
            foreach ($subResources as $subKey => $subResourceData) {
                $resource[$key][$subKey] = $fillPropertiesAndResourceData($subResourceData);
            }
        }

        return $this;
    }

    protected function fillSingleEntity(ArrayObject $resource, string $field, array $values): self
    {
        if (empty($values)) {
            $resource[$field] = null;
            return $this;
        }

        $value = end($values);

        // Get the entity id.
        switch ($field) {
            // TODO Factorize with AbstractResourceProcessor and AssetProcessor.
            case 'o:resource_template':
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
                return $this;

            case 'o:resource_class':
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
                return $this;

            case 'o:thumbnail':
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
                return $this;

            // TODO Factorize with AbstractResourceProcessor and AssetProcessor.
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
                    ? $this->findResourceFromIdentifier($value, null, null, $resource['messageStore'])
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
     * Fill other data that are not managed in a common way.
     *
     * Specific values set in metadataData have been processed.
     * Other values are already copied as an array of values.
     */
    protected function fillGeneric(ArrayObject $resource, array $data): self
    {
        // TODO Factorize with ResourceProcessor and AssetProcessor.
        foreach ($resource as $field => $values) switch ($field) {
            default:
                continue 2;

            case 'o:id':
                $value = (int) $values;
                if (!$value) {
                    $resource[$field] = null;
                    continue 2;
                }
                $resourceName = $resource['resource_name'] ?? null;
                $id = empty($this->identifiers['mapx'][$resource['source_index']])
                    ? $this->findResourceFromIdentifier($value, 'o:id', $resourceName, $resource['messageStore'])
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
                continue 2;

            case 'o:email':
                $value = $values;
                if (!$value) {
                    // TODO Clarify use of email to set owner (that may be the current one).
                    // $resource['o:owner'] = null;
                    continue 2;
                }
                $id = $this->getUserId($value);
                if ($id) {
                    $resource['o:owner'] = ['o:id' => $id, 'o:email' => $value];
                } else {
                    $resource['messageStore']->addError('values', new PsrMessage(
                        'The user "{source}" does not exist.', // @translate
                        ['source' => $value]
                    ));
                }
                continue 2;

            case 'o:created':
            case 'o:modified':
                $value = $values;
                if (!$value) {
                    $resource[$field] = null;
                    continue 2;
                }
                $resource[$field] = is_array($value)
                    ? $value
                    : ['@value' => substr_replace('0000-00-00 00:00:00', $value, 0, strlen($value))];
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
    protected function fillSpecific(ArrayObject $resource, array $data, ?string $mainResourceName = null): self
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
            $resourceNameClean = preg_replace('~[^a-z]~', '', mb_strtolower($resourceName));
            if (!isset($resourceNames[$resourceNameClean])) {
                return $this;
            }
            $resourceName = $resourceNames[$resourceNameClean];
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
        foreach ($resource as $field => $values) switch ($field) {
            default:
                continue 2;

            case 'o:item_set':
                // Normally useless: already checked and filled via "multiple_entities".
                /*
                // TODO Allow to use specific identifier names like "o:item_set [dcterms:title]".
                $identifierNames = $this->identifierNames;
                // Check values one by one to manage source identifiers.
                foreach ($values as $key => $value) {
                    if (!empty($this->identifiers['map'][$value . '§resources'])) {
                        $resource['o:item_set'][$key] = [
                            'o:id' => (int) strtok((string) $this->identifiers['map'][$value . '§resources'], '§'),
                            'checked_id' => true,
                            'source_identifier' => $value,
                            'resource_name' => 'item_sets',
                        ];
                    } else {
                        $itemSetId = $this->findResourcesFromIdentifiers($value, $identifierNames, 'item_sets', $resource['messageStore']);
                        if ($itemSetId) {
                            $resource['o:item_set'][$key] = [
                                'o:id' => $itemSetId,
                                'checked_id' => true,
                                'source_identifier' => $value,
                                'resource_name' => 'item_sets',
                            ];
                        } elseif (array_key_exists($value . '§resources', $this->identifiers['map'])) {
                            $resource['o:item_set'][$key] = [
                                // To be filled during real import if empty.
                                'o:id' => null,
                                'checked_id' => true,
                                'source_identifier' => $value,
                                'resource_name' => 'item_sets',
                            ];
                        } else {
                            // Only for first loop. Normally not possible after:
                            // all identifiers are stored in the list "map"
                            // during first loop.
                            $valueForMsg = mb_strlen($value) > 50 ? mb_substr($value, 0, 50) . '…' : $value;
                            $resource['messageStore']->addError('values', new PsrMessage(
                                'The value "{value}" is not an item set.', // @translate
                                ['value' => $valueForMsg]
                            ));
                        }
                    }
                }
                */
                continue 2;

            case 'url':
            case 'tile':
            case 'file':
            case 'directory':
            case 'html':
            case 'iiif':
                foreach ($values as $value) {
                    $value = $value['__value'];
                    $mediaData = $this->prepareMediaData($resource, $field, $value);
                    if ($mediaData) {
                        $resource['o:media'][] = $mediaData;
                    }
                }
                continue 2;

            case 'o:media':
                // Normally useless: already checked and filled via "multiple_entities".
                /*
                // Unlike item sets, the media cannot be created before and
                // attached to another item.
                // The media may be fully filled early (json or xml).
                foreach ($values as $key => $value) {
                    $media = $value;
                    $media['resource_name'] = 'media';
                    $resource['o:media'][$key] = $media;
                }
                */
                continue 2;

            case 'o-module-mapping:bounds':
                // There can be only one mapping zone.
                $bounds = reset($values);
                if (!$bounds) {
                    continue 2;
                }
                $bounds = $value['__value'];
                // @see \Mapping\Api\Adapter\MappingAdapter::validateEntity().
                if (null !== $bounds
                    && 4 !== count(array_filter(explode(',', $bounds), 'is_numeric'))
                ) {
                    $resource['messageStore']->addError('values', new PsrMessage(
                        'The mapping bounds requires four numeric values separated by a comma.'  // @translate
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
                $resource['o-module-mapping:marker'] = [];
                foreach ($values as $value) {
                    $value = $value['__value'];
                    list($lat, $lng) = array_filter(array_map('trim', explode('/', $value, 2)), 'is_numeric');
                    if (!strlen($lat) || !strlen($lng)) {
                        $resource['messageStore']->addError('values', new PsrMessage(
                            'The mapping marker requires a latitude and a longitude separated by a "/".'  // @translate
                        ));
                    } else {
                        $resource['o-module-mapping:marker'][] = [
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
        // Only "o:is_open" is specific to item sets, but already processed.
        return $this;
    }

    protected function fillMedia(ArrayObject $resource, array $data): self
    {
        foreach ($resource as $field => $values) switch ($field) {
            default:
                continue 2;

            case 'o:filename':
            case 'o:basename':
            case 'o:storage_id':
            case 'o:source':
            case 'o:sha256':
                $value = $values;
                if (!$value) {
                    $resource[$field] = null;
                    continue 2;
                }
                $id = empty($this->identifiers['mapx'][$resource['source_index']])
                    ? $this->findResourceFromIdentifier($value, $field, 'media', $resource['messageStore'])
                    : (int) strtok((string) $this->identifiers['mapx'][$resource['source_index']], '§');
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
                $identifier = $values;
                if (!empty($this->identifiers['map'][$identifier . '§resources'])) {
                    $resource['o:item'] = [
                        // To be filled during real import.
                        'o:id' => (int) strtok((string) $this->identifiers['map'][$identifier . '§resources'], '§') ?: null,
                        'checked_id' => true,
                        'source_identifier' => $identifier,
                    ];
                } else {
                    $identifierName = $this->identifierNames;
                    $itemIds = $this->findResourcesFromIdentifiers($values, $identifierName, 'items', $resource['messageStore']);
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
                        $valueForMsg = mb_strlen($identifier) > 50 ? mb_substr($identifier, 0, 50) . '…' : $identifier;
                        $resource['messageStore']->addError('values', new PsrMessage(
                            'The value "{value}" is not an item.', // @translate
                            ['value' => $valueForMsg]
                        ));
                    }
                }
                continue 2;

            case 'file':
            case 'url':
            case 'directory':
            case 'html':
            case 'iiif':
            case 'tile':
                $mediaData = $this->prepareMediaData($resource, $field, $value);
                foreach ($mediaData as $subfield => $value) {
                    $resource[$subfield] = $value;
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
        foreach ($resource[$term] as $key => $value) {
            $value['property_id'] = $propertyId;

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
                    $this->fillPropertyForValue($resource, $key, $term, $dataTypeName, $value);
                    $hasDatatype = true;
                    break;
                } elseif ($mainDataType === 'resource') {
                    $vrId = empty($this->identifiers['map'][$value . '§resources'])
                        ? $this->findResourceFromIdentifier($value, null, substr($dataTypeName, 0, 11) === 'customvocab' ? 'item' : $dataTypeName, $resource['messageStore'])
                        : (int) strtok((string) $this->identifiers['map'][$value . '§resources'], '§');
                    // Normally always true after first loop: all identifiers
                    // are stored first.
                    if ($vrId || array_key_exists($value . '§resources', $this->identifiers['map'])) {
                        $this->fillPropertyForValue($resource, $key, $term, $dataTypeName, $value, $vrId ? (int) $vrId : null);
                        $hasDatatype = true;
                        break;
                    }
                } elseif (substr($dataTypeName, 0, 11) === 'customvocab') {
                    if ($this->bulk->isCustomVocabMember($dataTypeName, $value)) {
                        $this->fillPropertyForValue($resource, $key, $term, $dataTypeName, $value);
                        $hasDatatype = true;
                        break;
                    }
                } elseif ($mainDataType === 'uri'
                    // Deprecated.
                    || $dataTypeName === 'uri-label'
                ) {
                    if ($this->bulk->isUrl($value)) {
                        $this->fillPropertyForValue($resource, $key, $term, $dataTypeName, $value);
                        $hasDatatype = true;
                        break;
                    }
                } else {
                    // Some data types may be more complex than "@value", but it
                    // manages most of the common other modules.
                    $valueArray = [
                        '@value' => $value['__value'],
                    ];
                    if ($dataType->isValid($valueArray)) {
                        $this->fillPropertyForValue($resource, $key, $term, $dataTypeName, $value);
                        $hasDatatype = true;
                        break;
                    }
                }
            }

            if (!$hasDatatype) {
                if ($this->useDatatypeLiteral) {
                    $this->fillPropertyForValue($resource, $key, $term, 'literal', $value);
                    $val = (string) $value['__value'];
                    $valueForMsg = mb_strlen($val) > 50 ? mb_substr($val, 0, 50) . '…' : $val;
                    if ($this->bulk->dataTypeMain(reset($dataTypeNames)) === 'resource') {
                        $resource['messageStore']->addNotice('values', new PsrMessage(
                            'The value "{value}" is not compatible with datatypes "{datatypes}". Try to create the resource first. Data type "literal" is used.', // @translate
                            ['value' => $valueForMsg, 'datatypes' => implode('", "', $dataTypeNames)]
                        ));
                    } else {
                        $resource['messageStore']->addNotice('values', new PsrMessage(
                            'The value "{value}" is not compatible with datatypes "{datatypes}". Data type "literal" is used.', // @translate
                            ['value' => $valueForMsg, 'datatypes' => implode('", "', $dataTypeNames)]
                        ));
                    }
                } else {
                    if ($this->bulk->dataTypeMain(reset($dataTypeNames)) === 'resource') {
                        $resource['messageStore']->addError('values', new PsrMessage(
                            'The value "{value}" is not compatible with datatypes "{datatypes}". Try to create resource first. Or try to add "literal" to datatypes or default to it.', // @translate
                            ['value' => $valueForMsg, 'datatypes' => implode('", "', $dataTypeNames)]
                        ));
                    } else {
                        $resource['messageStore']->addError('values', new PsrMessage(
                            'The value "{value}" is not compatible with datatypes "{datatypes}". Try to add "literal" to datatypes or default to it.', // @translate
                            ['value' => $valueForMsg, 'datatypes' => implode('", "', $dataTypeNames)]
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
     * The extracted value is set as "__value" by the meta mapper.
     */
    protected function fillPropertyForValue(
        ArrayObject $resource,
        int $indexValue,
        string $term,
        string $dataType,
        array $value,
        ?int $vrId = null
    ): self {
        $resourceValue = [
            'type' => $dataType,
            'property_id' => $value['property_id'],
            'is_public' => null,
        ];

        $mainDataType = $this->bulk->dataTypeMain($dataType);
        $val = $value['__value'];

        // Manage special datatypes first.
        $isCustomVocab = substr($dataType, 0, 11) === 'customvocab';

        if ($isCustomVocab) {
            $result = $this->bulk->isCustomVocabMember($dataType, $mainDataType === 'resource' ? $vrId ?? $val : $val);
            if (!$result) {
                $valueForMsg = mb_strlen($val) > 50 ? mb_substr($val, 0, 50) . '…' : $val;
                if (!$this->useDatatypeLiteral) {
                    $resource['messageStore']->addError('values', new PsrMessage(
                        'The value "{value}" is not member of custom vocab "{customvocab}".', // @translate
                        ['value' => $valueForMsg, 'customvocab' => $dataType]
                    ));
                    return $this;
                }
                $dataType = 'literal';
                $resource['messageStore']->addNotice('values', new PsrMessage(
                    'The value "{value}" is not member of custom vocab "{customvocab}". A literal value is used instead.', // @translate
                    ['value' => $valueForMsg, 'customvocab' => $dataType]
                ));
            }
        }

        switch ($dataType) {
            default:
            case 'literal':
                $resourceValue['@value'] = $value['__value'];
                $resourceValue['@language'] = $value['language'] ?: null;
                break;

            case 'uri-label':
                // "uri-label" is deprecated: use simply "uri".
            case $mainDataType === 'uri':
                // case 'uri':
                // case substr($dataType, 0, 12) === 'valuesuggest':
                if (strpos($value['__value'], ' ')) {
                    list($uri, $label) = explode(' ', $value['__value'], 2);
                    $label = trim($label);
                    if (!strlen($label)) {
                        $label = null;
                    }
                    $resourceValue['@id'] = $uri;
                    $resourceValue['o:label'] = $label;
                } else {
                    $resourceValue['@id'] = $value['__value'];
                    // The label may be set early?
                    // $resourceValue['o:label'] = null;
                }
                $resourceValue['o:lang'] = $value['o:lang'] ?? ($value['language'] ?: null);
                break;

            // case 'resource':
            // case 'resource:item':
            // case 'resource:itemset':
            // case 'resource:media':
            // case 'resource:annotation':
            case $mainDataType === 'resource':
                $resourceValue['value_resource_id'] = $vrId;
                $resourceValue['@language'] = null;
                $resourceValue['source_identifier'] = $value['__value'];
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
                $resource['o:media'][$key]['o:id'] = $this->findResourceFromIdentifier($media['o:source'], $identifierProperties, 'media', $resource['messageStore']);
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
        if (!$id) {
            return null;
        }
        $result = $this->bulk->api()->searchOne('assets', ['id' => $id])->getContent();
        return $result ? $id : null;
    }
}
