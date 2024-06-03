<?php declare(strict_types=1);

namespace BulkImport\Mvc\Controller\Plugin;

use ArrayObject;
use Common\Stdlib\EasyMeta;
use Common\Stdlib\PsrMessage;
use Laminas\Log\Logger;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\AbstractEntityRepresentation;
use Omeka\Api\Representation\AssetRepresentation;

/**
 * Manage identifiers from import.
 */
class BulkIdentifiers extends AbstractPlugin
{
    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var \Common\Stdlib\EasyMeta
     */
    protected $easyMeta;

    /**
     * @var \BulkImport\Mvc\Controller\Plugin\FindResourcesFromIdentifiers
     */
    protected $findResourcesFromIdentifiers;

    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * Unit separator ("␟") as utf-8.
     *
     * Should be a class constant.
     *
     * @var string
     */
    protected $unitSeparator;

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
     * Store the source identifiers for each index, reverted and mapped.
     * Manage possible duplicate identifiers.
     *
     * The keys are filled during first loop and values when found or available.
     *
     * Identifiers are the id and the main resource name ("resources", "assets",
     * etc.) is appended to the numeric id, separated with a unit separator
     * (ascii 31).
     *
     * @todo Remove "mapx" and "revert" ("revert" is only used to get "mapx"). "mapx" is a short to map[source index]. But a source can have no identifier and only an index.
     *
     * @var array
     */
    protected $identifiers = [
        // Source index to identifiers + suffix (array).
        'source' => [],
        // Identifiers  + suffix to source indexes (array).
        'revert' => [],
        // Source indexes to resource id + suffix.
        'mapx' => [],
        // Source identifiers + suffix to resource id + suffix.
        'map' => [],
    ];

    /**
     * List of resources used to manage identifiers with unique ids.
     *
     * @var array
     */
    protected $mainResourceNames = [
        'assets' => 'assets',
        'resources' => 'resources',
        'items' => 'resources',
        'item_sets' => 'resources',
        'media' => 'resources',
        'annotations' => 'resources',
    ];

    /**
     * @deprecated To be removed.
     * @todo Remove "allowDuplicateIdentifiers" because it is used only for log.
     *
     * @var bool
     */
    protected $allowDuplicateIdentifiers = false;

    public function __construct(
        ApiManager $api,
        EasyMeta $easyMeta,
        FindResourcesFromIdentifiers $findResourcesFromIdentifiers,
        Logger $logger
    ) {
        $this->api = $api;
        $this->easyMeta = $easyMeta;
        $this->logger = $logger;
        $this->findResourcesFromIdentifiers = $findResourcesFromIdentifiers;

        // Prepare the unit separator one time.
        $this->unitSeparator = function_exists('mb_chr') ? mb_chr(31, 'UTF-8') : chr(31);
    }

    public function __invoke(): self
    {
        return $this;
    }

    /**
     * @deprecated To be removed.
     */
    public function setAllowDuplicateIdentifiers(bool $allowDuplicateIdentifiers): self
    {
        $this->allowDuplicateIdentifiers = $allowDuplicateIdentifiers;
        return $this;
    }

    public function setIdentifierNames(array $identifierNames): self
    {
        $this->identifierNames = $identifierNames;
        return $this;
    }

    /**
     * Get the internal resource id from an identifier.
     *
     * @todo Check the resource name for items, etc.
     *
     * @param string|int $identifier
     */
    public function getId($identifier, ?string $resourceName = null): ?int
    {
        // TODO Manage the case where there is the same identifier for an item set as title and as preferred label for an item. This is the normal case for a thesaurus.
        // If the resource name is precise, use it only, else use "resources"? It won't fix issue. Use only the precise name? Use an array or a boolean flag? What for the value stored as "resources": the last value or the first?
        $mainResourceName = $this->mainResourceNames[$resourceName] ?? $resourceName ?? 'resources';
        $fullIdentifier = $identifier . $this->unitSeparator . $mainResourceName;
        return empty($this->identifiers['map'][$fullIdentifier])
            ? null
            : (int) strtok((string) $this->identifiers['map'][$fullIdentifier], $this->unitSeparator);
    }

    /**
     * Get the internal resource id from an index.
     *
     * The index is the order from the reader.
     *
     * @param int $index
     */
    public function getIdFromIndex(int $index): ?int
    {
        return empty($this->identifiers['mapx'][$index])
            ? null
            : (int) strtok((string) $this->identifiers['mapx'][$index], $this->unitSeparator);
    }

    /**
     * Get the first internal resource id matching resource properties values.
     */
    public function getIdFromResourceValues(array $resource): array
    {
        $properties = $this->easyMeta->propertyIds();
        foreach (array_intersect_key($this->identifierNames, $properties, $resource) as $term => $values) {
            if (!is_array($values)) {
                continue;
            }
            $identifiers = [];
            foreach ($values as $value) {
                if (is_array($value) && !empty($value['@value'])) {
                    $identifiers[] = $value['@value'];
                }
            }
            $foundIds = $this->findResourcesFromIdentifiers($identifiers, $term, $resource['resource_name']);
            $foundIds = array_filter($foundIds);
            if ($foundIds) {
                return [
                    key($foundIds) => (int) reset($foundIds),
                ];
            }
        }
        return [];
    }

    /**
     * Check if an identifier is present in the list, even if not mapped yet.
     *
     * This method allows to manage a multi-step process.
     *
     * @param string|int $identifier
     */
    public function isPreparedIdentifier($identifier, ?string $resourceName = null): bool
    {
        $mainResourceName = $this->mainResourceNames[$resourceName] ?? $resourceName ?? 'resources';
        $fullIdentifier = $identifier . $this->unitSeparator . $mainResourceName;
        return array_key_exists($fullIdentifier, $this->identifiers['map']);
    }

    public function countIdentifiers(): int
    {
        return count($this->identifiers['map']);
    }

    public function countMappedIdentifiers(): int
    {
        return count(array_filter($this->identifiers['map']));
    }

    /**
     * Extract main and linked resource identifiers from a resource.
     *
     * @todo Check for duplicates.
     * @todo Take care of actions (update/create).
     * @todo Take care of specific identifiers (uri, filename, etc.).
     * @todo Make a process and an output with all identifiers only.
     * @todo Store the resolved id existing in database one time here (after deduplication), and remove the search of identifiers in second loop.
     */
    public function extractSourceIdentifiers(?array $resource): self
    {
        if (!$resource) {
            return $this;
        }

        $storeMain = function ($idOrIdentifier, $mainResourceName) use ($resource): void {
            if ($idOrIdentifier) {
                // No check for duplicates here: it depends on action.
                $this->identifiers['source'][$resource['source_index']][] = $idOrIdentifier . $this->unitSeparator . $mainResourceName;
                $this->identifiers['revert'][$idOrIdentifier . $this->unitSeparator . $mainResourceName][$resource['source_index']] = $resource['source_index'];
            }
            // Source indexes to resource id.
            $this->identifiers['mapx'][$resource['source_index']] = empty($resource['o:id'])
                ? null
                : $resource['o:id'] . $this->unitSeparator . $mainResourceName;
            if ($idOrIdentifier) {
                // Source identifiers to resource id.
                // No check for duplicate here: last map is the right one.
                $this->identifiers['map'][$idOrIdentifier . $this->unitSeparator . $mainResourceName] = empty($resource['o:id'])
                    ? null
                    : $resource['o:id'] . $this->unitSeparator . $mainResourceName;
            }
        };

        $storeLinkedIdentifier = function ($idOrIdentifier, $vrId, $mainResourceName): void {
            // As soon as an array exists, a check can be done on identifier,
            // even if the id is defined later. The same for map.
            if (!isset($this->identifiers['revert'][$idOrIdentifier . $this->unitSeparator . $mainResourceName])) {
                $this->identifiers['revert'][$idOrIdentifier . $this->unitSeparator . $mainResourceName] = [];
            }
            $this->identifiers['map'][$idOrIdentifier . $this->unitSeparator . $mainResourceName] = $vrId;
        };

        $mainResourceName = $resource['resource_name'] ?? 'resources';

        // Main identifiers.
        foreach ($this->identifierNames as $identifierName) {
            if ($identifierName === 'o:id') {
                $storeMain($resource['o:id'] ?? null, $mainResourceName);
            } elseif ($identifierName === 'o:storage_id') {
                $storeMain($resource['o:storage_id'] ?? null, $mainResourceName);
            } elseif ($identifierName === 'o:name') {
                $storeMain($resource['o:name'] ?? null, $mainResourceName);
            } else {
                // TODO Normally already initialized.
                $term = $this->easyMeta->propertyTerm($identifierName);
                foreach ($resource[$term] ?? [] as $value) {
                    if (!empty($value['@value'])) {
                        $storeMain($value['@value'], 'resources');
                    }
                }
            }
        }

        // TODO Move these checks in resource and asset processors.

        // Specific identifiers for items (item sets and media).
        if ($resource['resource_name'] === 'items') {
            foreach ($resource['o:item_set'] ?? [] as $itemSet) {
                if (!empty($itemSet['source_identifier'])) {
                    $storeLinkedIdentifier($itemSet['source_identifier'], $itemSet['o:id'] ?? null, 'resources');
                }
            }
            foreach ($resource['o:media'] ?? [] as $media) {
                if (!empty($media['source_identifier'])) {
                    $storeLinkedIdentifier($media['source_identifier'], $media['o:id'] ?? null, 'resources');
                }
            }
        }

        // Specific identifiers for media (item).
        elseif ($resource['resource_name'] === 'media') {
            if (!empty($resource['o:item']['source_identifier'])) {
                $storeLinkedIdentifier($resource['o:item']['source_identifier'], $resource['o:item']['o:id'] ?? null, 'resources');
            }
        }

        // Specific identifiers for resources attached to assets.
        elseif ($resource['resource_name'] === 'assets') {
            foreach ($resource['o:resource'] ?? [] as $thumbnailForResource) {
                if (!empty($thumbnailForResource['source_identifier'])) {
                    $storeLinkedIdentifier($thumbnailForResource['source_identifier'], $thumbnailForResource['o:id'] ?? null, 'resources');
                }
            }
        }

        // TODO It's now possible to store an identifier for the asset from the resource.

        // Store identifiers for linked resources.
        $properties = $this->easyMeta->propertyIds();
        foreach (array_intersect_key($resource, $properties) as $term => $values) {
            if (!is_array($values)) {
                continue;
            }
            foreach ($values as $value) {
                if (is_array($value)
                    && isset($value['property_id'])
                    && !empty($value['source_identifier'])
                ) {
                    $storeLinkedIdentifier($value['source_identifier'], $value['value_resource_id'] ?? null, 'resources');
                }
            }
        }

        return $this;
    }

    /**
     * Store new id when source contains identifiers not yet imported.
     */
    public function storeSourceIdentifiersIds(array $dataResource, AbstractEntityRepresentation $resource): self
    {
        $resourceId = $resource->id();
        if (empty($resourceId) || empty($dataResource['source_index'])) {
            return $this;
        }

        $resourceName = $resource instanceof AssetRepresentation ? 'assets' : $resource->resourceName();
        $mainResourceName = $this->mainResourceNames[$resourceName] ?? $resourceName;

        // Source indexes to resource id (filled when found or created).
        $this->identifiers['mapx'][$dataResource['source_index']] = $resourceId . $this->unitSeparator . $mainResourceName;

        // Source identifiers to resource id (filled when found or created).
        // No check for duplicate here: last map is the right one.
        foreach ($this->identifiers['source'][$dataResource['source_index']] ?? [] as $idOrIdentifierWithResourceName) {
            $this->identifiers['map'][$idOrIdentifierWithResourceName] = $resourceId . $this->unitSeparator . $mainResourceName;
        }

        return $this;
    }

    /**
     * Store new id when source contains identifiers not yet imported.
     *
     * @todo Clean this method.
     */
    public function storeSourceIdentifiersIdsMore(array $identifiers, string $identifierName, string $resourceName, ArrayObject $resource): array
    {
        // Use source index first, because resource may have no identifier.
        $ids = [];
        if (empty($this->identifiers['mapx'][$resource['source_index']])) {
            $mainResourceName = $this->mainResourceNames[$resourceName] ?? $resourceName ?? 'resources';
            if ($mainResourceName === 'assets') {
                $ids = $this->findAssetsFromIdentifiers($identifiers, [$identifierName]);
            } elseif ($mainResourceName === 'resources') {
                $ids = $this->findResourcesFromIdentifiers($identifiers, [$identifierName], $resourceName, $resource['messageStore'] ?? null);
            }
            $ids = array_filter($ids);
            // Store the id one time.
            // TODO Merge with storeSourceIdentifiersIds().
            if ($ids) {
                foreach ($ids as $identifier => $id) {
                    $idEntity = $id . $this->unitSeparator . $mainResourceName;
                    $this->identifiers['mapx'][$resource['source_index']] = $idEntity;
                    $this->identifiers['map'][$identifier . $this->unitSeparator . $mainResourceName] = $idEntity;
                }
                if (isset($resource['messageStore'])) {
                    $resource['messageStore']->addInfo('identifier', new PsrMessage(
                        'Identifier "{identifier}" ({metadata}) matches {resource_name} #{resource_id}.', // @translate
                        [
                            'identifier' => key($ids),
                            'metadata' => $identifierName,
                            'resource_name' => $this->easyMeta->resourceLabel($resourceName),
                            'resource_id' => $resource['o:id'],
                        ]
                    ));
                } else {
                    $this->logger->info(
                        'Index #{index}: Identifier "{identifier}" ({metadata}) matches {resource_name} #{resource_id}.', // @translate
                        [
                            'index' => $resource['source_index'],
                            'identifier' => key($ids),
                            'metadata' => $identifierName,
                            'resource_name' => $this->easyMeta->resourceLabel($resourceName),
                            'resource_id' => $resource['o:id'],
                        ]
                    );
                }
            }
        } elseif (!empty($this->identifiers['mapx'][$resource['source_index']])) {
            $ids = array_fill_keys($identifiers, (int) strtok((string) $this->identifiers['mapx'][$resource['source_index']], $this->unitSeparator));
        }

        return $ids;
    }

    /**
     * @deprecated Manage finalization of the storage automatically.
     */
    public function finalizeStorageIdentifiers(?string $resourceName): self
    {
        // Clean identifiers for duplicates.
        $this->identifiers['source'] = array_map('array_unique', $this->identifiers['source']);

        // Simplify identifiers revert.
        $this->identifiers['revert'] = array_map('array_unique', $this->identifiers['revert']);
        foreach ($this->identifiers['revert'] as &$values) {
            $values = array_combine($values, $values);
        }
        unset($values);

        // Empty identifiers should be null to use isset().
        // A foreach is quicker than array_map.
        foreach ($this->identifiers['mapx'] as &$val) {
            if (!$val) {
                $val = null;
            }
        }
        unset($val);

        foreach ($this->identifiers['map'] as &$val) {
            if (!$val) {
                $val = null;
            }
        }
        unset($val);

        if (empty($this->identifiers['map'])) {
            return $this;
        }

        $mainResourceName = $this->mainResourceNames[$resourceName] ?? $resourceName ?? 'resources';

        // Process only identifiers without ids (normally all of them).
        $emptyIdentifiers = [];
        foreach ($this->identifiers['map'] as $identifier => $id) {
            if (empty($id)) {
                $emptyIdentifiers[] = strtok((string) $identifier, $this->unitSeparator);
            }
        }

        // TODO Manage assets.
        if ($mainResourceName === 'assets') {
            $ids = $this->findAssetsFromIdentifiers($emptyIdentifiers, $this->identifierNames);
        } elseif ($mainResourceName === 'resources') {
            $ids = $this->findResourcesFromIdentifiers($emptyIdentifiers, $this->identifierNames);
        }

        foreach ($ids as $identifier => $id) {
            $this->identifiers['map'][$identifier . $this->unitSeparator . $mainResourceName] = $id
                ? $id . $this->unitSeparator . $mainResourceName
                : null;
        }

        // Fill mapx when possible.
        foreach ($ids as $identifier => $id) {
            if (!empty($this->identifiers['revert'][$identifier . $this->unitSeparator . $mainResourceName])) {
                $this->identifiers['mapx'][reset($this->identifiers['revert'][$identifier . $this->unitSeparator . $mainResourceName])]
                    = $id . $this->unitSeparator . $mainResourceName;
            }
        }

        $this->identifiers['mapx'] = array_map(function ($v) {
            return $v ?: null;
        }, $this->identifiers['mapx']);
        $this->identifiers['map'] = array_map(function ($v) {
            return $v ?: null;
        }, $this->identifiers['map']);

        return $this;
    }

    /**
     * Set missing ids when source contains identifiers not yet imported during
     * resource building.
     */
    public function completeResourceIdentifierIds(array $resource): array
    {
        if (empty($resource['o:id'])
            && !empty($resource['source_index'])
            && !empty($this->identifiers['mapx'][$resource['source_index']])
        ) {
            $resource['o:id'] = (int) strtok((string) $this->identifiers['mapx'][$resource['source_index']], $this->unitSeparator);
        }

        // TODO Move these checks into the right processor.
        // TODO Add checked_id?

        if ($resource['resource_name'] === 'items') {
            foreach ($resource['o:item_set'] ?? [] as $key => $itemSet) {
                if (empty($itemSet['o:id'])
                    && !empty($itemSet['source_identifier'])
                    && !empty($this->identifiers['map'][$itemSet['source_identifier'] . $this->unitSeparator . 'resources'])
                    // TODO Add a check for item set identifier.
                ) {
                    $resource['o:item_set'][$key]['o:id'] = (int) strtok((string) $this->identifiers['map'][$itemSet['source_identifier'] . $this->unitSeparator . 'resources'], $this->unitSeparator);
                }
            }
            // TODO Fill media identifiers for update here?
        }

        if ($resource['resource_name'] === 'media'
            && empty($resource['o:item']['o:id'])
            && !empty($resource['o:item']['source_identifier'])
            && !empty($this->identifiers['map'][$resource['o:item']['source_identifier'] . $this->unitSeparator . 'resources'])
            // TODO Add a check for item identifier.
        ) {
            $resource['o:item']['o:id'] = (int) strtok((string) $this->identifiers['map'][$resource['o:item']['source_identifier'] . $this->unitSeparator . 'resources'], $this->unitSeparator);
        }

        // TODO Useless for now with assets: don't create resource on unknown resources. Maybe separate options create/skip for main resources and related resources.
        if ($resource['resource_name'] === 'assets') {
            foreach ($resource['o:resource'] ?? [] as $key => $thumbnailForResource) {
                if (empty($thumbnailForResource['o:id'])
                    && !empty($thumbnailForResource['source_identifier'])
                    && !empty($this->identifiers['map'][$thumbnailForResource['source_identifier'] . $this->unitSeparator . 'resources'])
                    // TODO Add a check for resource identifier.
                ) {
                    $resource['o:resource'][$key]['o:id'] = (int) strtok((string) $this->identifiers['map'][$thumbnailForResource['source_identifier'] . $this->unitSeparator . 'resources'], $this->unitSeparator);
                }
            }
        }

        foreach ($resource as $term => $values) {
            if (!is_array($values)) {
                continue;
            }
            foreach ($values as $key => $value) {
                if (is_array($value)
                    && isset($value['property_id'])
                    // Avoid to test the type (resources and some custom vocabs).
                    && array_key_exists('value_resource_id', $value)
                    && empty($value['value_resource_id'])
                    && !empty($value['source_identifier'])
                    && !empty($this->identifiers['map'][$value['source_identifier'] . $this->unitSeparator . 'resources'])
                ) {
                    $resource[$term][$key]['value_resource_id'] = (int) strtok((string) $this->identifiers['map'][$value['source_identifier'] . $this->unitSeparator . 'resources'], $this->unitSeparator);
                }
            }
        }

        return $resource;
    }

    /**
     * Find a list of resource ids from a list of identifiers (or one id).
     *
     * When there are true duplicates and case insensitive duplicates, the first
     * case sensitive is returned, else the first case insensitive resource.
     *
     * @todo Manage Media source html.
     *
     * @uses\BulkImport\Mvc\Controller\Plugin\FindResourcesFromIdentifiers
     *
     * @param array|string $identifiers Identifiers should be unique. If a
     * string is sent, the result will be the resource.
     * @param string|int|array $identifierName Property as integer or term,
     * "o:id", a media ingester (url or file), or an associative array with
     * multiple conditions (for media source). May be a list of identifier
     * metadata names, in which case the identifiers are searched in a list of
     * properties and/or in internal ids.
     * @param string $resourceName The resource type, name or class, if any.
     * @param \BulkImport\Stdlib\MessageStore $messageStore
     * @return array|int|null|Object Associative array with the identifiers as key
     * and the ids or null as value. Order is kept, but duplicate identifiers
     * are removed. If $identifiers is a string, return directly the resource
     * id, or null. Returns standard object when there is at least one duplicated
     * identifiers in resource and the option "$uniqueOnly" is set.
     *
     * Note: The option uniqueOnly is not taken in account. The object or the
     * boolean are not returned, but logged.
     * Furthermore, the identifiers without id are not returned.
     */
    public function findResourcesFromIdentifiers(
        $identifiers,
        $identifierName = null,
        $resourceName = null,
        // TODO Remove message store.
        ?\BulkImport\Stdlib\MessageStore $messageStore = null
    ) {
        // TODO Manage non-resources here? Or a different helper for assets?

        $identifierName = $identifierName ?: $this->identifierNames;
        $result = $this->findResourcesFromIdentifiers->__invoke($identifiers, $identifierName, $resourceName, true);

        $isSingle = !is_array($identifiers);

        // Log duplicate identifiers.
        if (is_object($result)) {
            $result = (array) $result;
            if ($isSingle) {
                $result['result'] = [$identifiers => $result['result']];
                $result['count'] = [$identifiers => $result['count']];
            }

            // Remove empty identifiers.
            $result['result'] = array_filter($result['result']);

            // TODO Remove the logs from here.
            foreach (array_keys($result['result']) as $identifier) {
                if ($result['count'][$identifier] > 1) {
                    if ($messageStore) {
                        $messageStore->addWarning('identifier', new PsrMessage(
                            'Identifier "{identifier}" is not unique ({count} values). First is #{id}.', // @translate
                            ['identifier' => $identifier, 'count' => $result['count'][$identifier], 'id' => $result['result'][$identifier]]
                        ));
                    } else {
                        $this->logger->warn(
                            'Identifier "{identifier}" is not unique ({count} values). First is #{id}.', // @translate
                            ['identifier' => $identifier, 'count' => $result['count'][$identifier], 'id' => $result['result'][$identifier]]
                        );
                    }
                    // if (!$this->allowDuplicateIdentifiers) {
                    //     unset($result['result'][$identifier]);
                    // }
                }
            }

            if (!$this->allowDuplicateIdentifiers) {
                if ($messageStore) {
                    $messageStore->addError('identifier', new PsrMessage(
                        'Duplicate identifiers are not allowed.' // @translate
                    ));
                } else {
                    $this->logger->err(
                        'Duplicate identifiers are not allowed.' // @translate
                    );
                }
                return $isSingle ? null : [];
            }

            $result = $isSingle ? reset($result['result']) : $result['result'];
        } else {
            // Remove empty identifiers.
            if (!$isSingle) {
                $result = array_filter($result);
            }
        }

        return $result;
    }

    /**
     * Find a resource id from a an identifier.
     *
     * @uses self::findResourcesFromIdentifiers()
     * @param string $identifier
     * @param string|int|array $identifierName Property as integer or term,
     * media ingester or "o:id", or an array with multiple conditions.
     * @param string $resourceName The resource type, name or class, if any.
     * @param \BulkImport\Stdlib\MessageStore $messageStore
     * @return int|null|false
     */
    public function findResourceFromIdentifier(
        $identifier,
        $identifierName = null,
        $resourceName = null,
        // TODO Remove message store.
        ?\BulkImport\Stdlib\MessageStore $messageStore = null
    ) {
        return $this->findResourcesFromIdentifiers($identifier, $identifierName, $resourceName, $messageStore);
    }

    public function findAssetsFromIdentifiers(array $identifiers, $identifierNames): array
    {
        // Extract all ids and identifiers: there are only two unique columns in
        // assets (id and storage id) and the table is generally small and the
        // api doesn't allow to search them.
        // The name is allowed too, even if not unique.

        if (!$identifiers || !$identifierNames) {
            return [];
        }

        if (!is_array($identifierNames)) {
            $identifierNames = [$identifierNames];
        }

        // TODO Allow to store statically ids and add new identifiers and ids in the map, or check in the map first.

        $idIds = [];
        if (in_array('o:id', $identifierNames)) {
            $idIds = $this->api->search('assets', [], ['returnScalar' => 'id'])->getContent();
        }
        $idNames = [];
        if (in_array('o:name', $identifierNames)) {
            $idNames = $this->api->search('assets', [], ['returnScalar' => 'name'])->getContent();
        }
        $idStorages = [];
        if (in_array('o:storage_id', $identifierNames)) {
            $idStorages = $this->api->search('assets', [], ['returnScalar' => 'storageId'])->getContent();
        }

        if (!$idIds && !$idNames && !$idStorages) {
            return [];
        }

        $result = [];
        $identifierKeys = array_fill_keys($identifiers, null);

        // Start by name to override it because it is not unique.
        if (in_array('o:name', $identifierNames)) {
            $result += array_intersect_key(array_flip($idNames), $identifierKeys);
        }

        if (in_array('o:storage_id', $identifierNames)) {
            $result += array_intersect_key(array_flip($idStorages), $identifierKeys);
        }

        if (in_array('o:id', $identifierNames)) {
            $result += array_intersect_key($idIds, $identifierKeys);
        }

        return array_filter($result);
    }

    /**
     * Get a user id by email or id or name.
     *
     * @var string|int $emailOrIdOrName
     */
    public function getUserId($emailOrIdOrName): ?int
    {
        if (empty($emailOrIdOrName) || !is_scalar($emailOrIdOrName)) {
            return null;
        }

        if (is_numeric($emailOrIdOrName)) {
            $data = ['id' => $emailOrIdOrName];
        } elseif (filter_var($emailOrIdOrName, FILTER_VALIDATE_EMAIL)) {
            $data = ['email' => $emailOrIdOrName];
        } else {
            $data = ['name' => $emailOrIdOrName];
        }
        $data['limit'] = 1;

        $users = $this->api->search('users', $data, ['responseContent' => 'resource'])->getContent();
        return $users ? (reset($users))->getId() : null;
    }
}
