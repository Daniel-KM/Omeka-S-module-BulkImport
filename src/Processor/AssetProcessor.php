<?php declare(strict_types=1);

namespace BulkImport\Processor;

use ArrayObject;
use BulkImport\Form\Processor\AssetProcessorConfigForm;
use BulkImport\Form\Processor\AssetProcessorParamsForm;
use BulkImport\Interfaces\Configurable;
use BulkImport\Interfaces\Parametrizable;
use Log\Stdlib\PsrMessage;
use Omeka\Api\Exception\ValidationException;
use Omeka\Api\Representation\AssetRepresentation;
use Omeka\Stdlib\ErrorStore;

class AssetProcessor extends AbstractResourceProcessor implements Configurable, Parametrizable
{
    protected $resourceName = 'assets';

    protected $resourceLabel = 'Assets'; // @translate

    protected $configFormClass = AssetProcessorConfigForm::class;

    protected $paramsFormClass = AssetProcessorParamsForm::class;

    /**
     * @see \Omeka\Api\Representation\AssetRepresentation
     *
     * @var array
     */
    protected $metadataData = [
        // Assets metadata and file.
        'fields' => [
            'file',
            'url',
            'o:id',
            'o:name',
            'o:storage_id',
            'o:owner',
            'o:alt_text',
            // To attach resources.
            'o:resource',
        ],
        'skip' => [],
        'boolean' => [],
        'single_data' => [
            'resource_name' => null,
            'file' => null,
            'url' => null,
            // Generic.
            'o:id' => null,
            // TODO Use all data names and storage ids from data to find existing assets before update. Keep source data as a key of resource to simplify process.
            // Asset.
            'o:name' => null,
            'o:media_type' => null,
            'o:storage_id' => null,
            'o:alt_text' => null,
        ],
        'single_entity' => [
            // Generic.
            'o:owner' => null,
        ],
        'multiple_entities' => [
            // Attached resources for thumbnails.
            // 'o:resource' => null,
        ],
        'misc' => [
            'o:id' => null,
            'o:storage_id' => null,
            'o:media_type' => null,
            'file' => null,
            'url' => null,
            'o:resource' => null,
        ],
    ];

    protected function handleFormGeneric(ArrayObject $args, array $values): self
    {
        $defaults = [
            'processing' => 'stop_on_error',
            'entries_to_skip' => 0,
            'entries_max' => 0,

            'action' => null,
            'action_unidentified' => null,
            'identifier_name' => null,

            'o:owner' => null,
        ];
        $result = array_intersect_key($values, $defaults) + $args->getArrayCopy() + $defaults;
        $result['entries_to_skip'] = (int) $result['entries_to_skip'];
        $result['entries_max'] = (int) $result['entries_max'];
        // There is no identifier for assets, only unique data (ids and storage
        // ids), so no missing or duplicates, so allow them.
        $result['allow_duplicate_identifiers'] = true;
        $result['resource_name'] = 'assets';
        $args->exchangeArray($result);
        return $this;
    }

    protected function prepareAction(): self
    {
        $this->action = $this->getParam('action') ?: self::ACTION_CREATE;
        if (!in_array($this->action, [
            self::ACTION_CREATE,
            self::ACTION_UPDATE,
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

    protected function baseSpecific(ArrayObject $resource): self
    {
        $resource['resource_name'] = 'assets';

        /** @see \Omeka\Api\Representation\AssetRepresentation */
        $ownerId = $this->getParam('o:owner', 'current') ?: 'current';
        if ($ownerId === 'current') {
            $ownerId = $this->userId;
        }
        $resource['o:owner'] = ['o:id' => $ownerId];

        $resource['o:name'] = null;
        $resource['o:filename'] = null;
        $resource['o:media_type'] = null;
        $resource['o:alt_text'] = null;

        // Storage id and extension are managed automatically.

        $resource['o:resource'] = [];

        return $this;
    }

    protected function fillResourceData(ArrayObject $resource, array $data): self
    {
        // TODO Factorize with ResourceProcessor and AssetProcessor.
        foreach (array_intersect_key($resource->getArrayCopy(), $this->metadataData['misc']) as $field => $values) switch ($field) {
            default:
                continue 2;

            case 'o:id':
                $value = (int) $values;
                if (!$value) {
                    $resource['o:id'] = null;
                    continue 2;
                }
                $id = empty($this->identifiers['mapx'][$resource['source_index']])
                    ? $this->bulk->api()->searchOne('assets', ['id' => $value])->getContent()
                    : (int) strtok((string) $this->identifiers['mapx'][$resource['source_index']], '§');
                if ($id) {
                    $resource['o:id'] = is_object($id) ? $id->id() : $id;
                    $resource['checked_id'] = true;
                } else {
                    $resource['messageStore']->addError('resource', new PsrMessage(
                        'Source index #{index}: Internal id cannot be found. The entry is skipped.', // @translate
                        ['index' => $resource['source_index']]
                    ));
                }
                continue 2;

            case 'o:storage_id':
                $value = $values;
                if (!$value) {
                    $resource['o:storage_id'] = null;
                    continue 2;
                }
                try {
                    $id = empty($this->identifiers['mapx'][$resource['source_index']])
                        ? $this->bulk->api()->read('assets', ['storage_id' => $value])->getContent()
                        : (int) strtok((string) $this->identifiers['mapx'][$resource['source_index']], '§');
                } catch (\Exception $e) {
                    $id = null;
                }
                if ($id) {
                    $resource['o:id'] = is_object($id) ? $id->id() : $id;
                    $resource['checked_id'] = true;
                } else {
                    $resource['messageStore']->addError('resource', new PsrMessage(
                        'Source index #{index}: Storage id cannot be found. The entry is skipped.', // @translate
                        ['index' => $resource['source_index']]
                    ));
                }
                continue 2;

            case 'o:media_type':
                $value = $values;
                if (!$value) {
                    $resource[$field] = null;
                } else {
                    if (preg_match('~(?:application|image|audio|video|model|text)/[\w.+-]+~', $value)) {
                        $resource[$field] = $value;
                    } else {
                        $resource['messageStore']->addError('values', new PsrMessage(
                            'The media type "{media_type}" is not valid.', // @translate
                            ['media_type' => $value]
                        ));
                    }
                }
                continue 2;

            case 'url':
            case 'file':
                $value = $values;
                if (!$value) {
                    $resource['o:ingester'] = null;
                    $resource['ingest_url'] = null;
                    $resource['ingest_filename'] = null;
                } elseif ($this->bulk->isUrl($value)) {
                    $resource['o:ingester'] = 'url';
                    $resource['ingest_url'] = $value;
                } else {
                    $resource['o:ingester'] = 'sideload';
                    $resource['ingest_filename'] = $value;
                }
                continue 2;

            case 'o:resource':
                // Check values one by one to manage source identifiers.
                foreach ($values as $key => $value) {
                    if (!$value) {
                        unset($resource[$field][$key]);
                        continue;
                    }
                    $thumbnailForResourceId = $this->findResourcesFromIdentifiers($value, $this->identifierNames, 'resources', $resource['messageStore']);
                    if ($thumbnailForResourceId) {
                        $resource['o:resource'][$key] = [
                            'o:id' => $thumbnailForResourceId,
                            'checked_id' => true,
                            // TODO Set the source identifier anywhere.
                        ];
                    } elseif (!empty($this->identifiers['map'][$value . '§resources'])) {
                        $resource['o:resource'][$key] = [
                            'o:id' => (int) strtok((string) $this->identifiers['map'][$value . '§resources'], '§'),
                            'checked_id' => true,
                            'source_identifier' => $value,
                        ];
                    } else {
                        // Only for first loop. Normally not possible after: all
                        // identifiers are stored in the list "map" during first loop.
                        $valueForMsg = mb_strlen($value) > 50 ? mb_substr($value, 0, 50) . '…' : $value;
                        $resource['messageStore']->addError('values', new PsrMessage(
                            'The value "{value}" is not a resource.', // @translate
                            ['value' => $valueForMsg]
                        ));
                    }
                }
                continue 2;
        }

        return $this;
    }

    protected function checkEntitySpecific(ArrayObject $resource): bool
    {
        // TODO Remove all properties for a spreadsheet imported with mixed content.

        // A resource must be a string and must have a name. Else, the filename
        // is used by default.
        // @see \Omeka\Api\Adapter\AssetAdapter::validateEntity()
        // Warning: during update, it should not be modifid if not set.

        if ($this->action === self::ACTION_CREATE) {
            if (!isset($resource['o:name']) || trim((string) $resource['o:name']) === '') {
                $resource['o:name'] = $resource['url'] ?? $resource['file']
                    ?? $resource['ingest_url'] ?? $resource['ingest_filename']
                    ?? null;
            } else {
                $resource['o:name'] = trim((string) $resource['o:name']);
            }
        } elseif (array_key_exists('o:name', $resource->getArrayCopy())) {
            $resource['o:name'] = trim((string) $resource['o:name']);
        }

        return true;
    }

    /**
     * Process creation of resources.
     *
     * Assets require an uploaded file, so bypass api and parent method.
     *
     * @return array Created resources.
     */
    protected function createResources($resourceName, array $dataResources): array
    {
        if (!count($dataResources)) {
            return [];
        }

        $baseResource = $this->baseEntity();
        $messageStore = $baseResource['messageStore'];

        $resources = [];

        foreach ($dataResources as $dataResource) {
            $resource = $this->createAsset($dataResource, $messageStore);
            if (!$resource) {
                $this->bulkCheckLog->logCheckedResource($this->indexResource, $baseResource->getArrayCopy());
                ++$this->totalErrors;
                return $resources;
            }

            $resources[$resource->id()] = $resource;
            $this->storeSourceIdentifiersIds($dataResource, $resource);
            $this->logger->notice(
                'Index #{index}: Created {resource_name} #{resource_id}', // @translate
                ['index' => $this->indexResource, 'resource_name' => $this->bulk->resourceLabel($resourceName), 'resource_id' => $resource->id()]
            );

            $dataResource['o:id'] = $resource->id();
            if (!$this->updateThumbnailForResources($dataResource)) {
                return $resources;
            }
        }

        return $resources;
    }

    /**
     * Create a new asset.
     *
     *AssetAdapter requires an uploaded file, but it's common to use urls in
     *bulk import.
     *
     * @todo Factorize with \BulkImport\Processor\ResourceProcessor::createAssetFromUrl()
     */
    protected function createAsset(array $dataResource, ErrorStore $messageStore): ?AssetRepresentation
    {
        $dataResource = $this->completeResourceIdentifierIds($dataResource);
        // TODO Clarify use of ingester and allows any ingester for assets.
        $pathOrUrl = $dataResource['url'] ?? $dataResource['file']
            ?? $dataResource['ingest_url'] ?? $dataResource['ingest_filename']
            ?? null;

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
        $owner = $entityManager->find(\Omeka\Entity\User::class, $dataResource['o:owner']['o:id'] ?? $this->userId);

        $isUrl = $this->bulk->isUrl($pathOrUrl);
        $name = strlen(trim((string) ($dataResource['o:name'] ?? '')))
            ? trim($dataResource['o:name'])
            : ($isUrl ? $pathOrUrl : $filename);

        $asset = new \Omeka\Entity\Asset;
        $asset->setName($name);
        // TODO Use the user specified in the config (owner).
        $asset->setOwner($owner);
        $asset->setStorageId($storageId);
        $asset->setExtension($extension);
        $asset->setMediaType($mediaType);
        $asset->setAltText($dataResource['o:alt_text'] ?? null);

        // TODO Remove this flush (required because there is a clear() after checks).
        $entityManager->persist($asset);
        $entityManager->flush();

        return $this->adapterManager->get('assets')->getRepresentation($asset);
    }

    /**
     * Only name and alt text are update for now (see api AssetAdapter).
     * Thumbnails of resources are updatable too.
     *
     * @see \BulkImport\Processor\ResourceUpdateTrait
     */
    protected function updateDataAsset($resourceName, array $dataResource): array
    {
        // Unlike resource, the only fields updatable via standard methods are
        // name, alternative text and attached resources.

        // Always reload the resource that is currently managed to manage
        // multiple update of the same resource.
        try {
            $this->bulk->api()->read('assets', $dataResource['o:id'], [], ['responseContent' => 'resource'])->getContent();
        } catch (\Exception $e) {
            // Normally already checked.
            $r = $this->baseEntity();
            $r['messageStore']->addError('resource', new PsrMessage(
                'Index #{index}: The resource {resource} #{id} is not available and cannot be updated.', // @translate
                ['index' => $this->indexResource, 'resource' => 'asset', 'id', $dataResource['o:id']]
            ));
            $this->bulkCheckLog->logCheckedResource($this->indexResource, $r->getArrayCopy());
            ++$this->totalErrors;
            return null;
        }

        // A name is required.

        return $dataResource;
    }

    protected function updateThumbnailForResources(array $dataResource)
    {
        // The id is required to attach the asset to a resource.
        if (empty($dataResource['o:id'])) {
            return $this;
        }

        $api = clone $this->bulk->api(null, true);

        // Attach asset to the resources.
        $thumbnailResources = [];
        foreach ($dataResource['o:resource'] ?? [] as $thumbnailResource) {
            // Normally checked early.
            if (empty($thumbnailResource['resource_name'])) {
                try {
                    $thumbnailResource['resource_name'] = $api->read('resources', $thumbnailResource['o:id'], [], ['responseContent' => 'resource'])->getContent()
                        ->getResourceName();
                } catch (\Exception $e) {
                    $r = $this->baseEntity();
                    $r['messageStore']->addError('resource', new PsrMessage(
                        'The resource #{resource_id} for asset #{asset_id} does not exist.', // @translate
                        ['resource_id' => $thumbnailResource['o:id'], 'asset_id' => $dataResource['o:id']]
                    ));
                    $messages = $this->listValidationMessages(new ValidationException($e->getMessage()));
                    $r['messageStore']->addError('resource', $messages);
                    $this->bulkCheckLog->logCheckedResource($this->indexResource, $r->getArrayCopy());
                    ++$this->totalErrors;
                    return null;
                }
            }
            $thumbnailResource['o:thumbnail'] = ['o:id' => $dataResource['o:id']];
            $thumbnailResources[] = $thumbnailResource;
        }

        // TODO Isolate the processes.
        $assetAction = $this->action;
        $this->action = self::ACTION_SUB_UPDATE;
        $this->updateResources('resources', $thumbnailResources);
        $this->action = $assetAction;
        return $this;
    }
}
