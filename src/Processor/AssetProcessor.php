<?php declare(strict_types=1);

namespace BulkImport\Processor;

use ArrayObject;
use BulkImport\Form\Processor\AssetProcessorConfigForm;
use BulkImport\Form\Processor\AssetProcessorParamsForm;
use BulkImport\Interfaces\Configurable;
use BulkImport\Interfaces\Parametrizable;
use BulkImport\Stdlib\MessageStore;
use Common\Stdlib\PsrMessage;
use Omeka\Api\Exception\ValidationException;
use Omeka\Api\Representation\AssetRepresentation;

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
    protected $fieldTypes = [
        // Common metadata.
        'resource_name' => 'string',
        // "o:id" may be an identifier.
        'o:id' => 'string',
        'o:created' => 'datetime',
        'o:modified' => 'datetime',
        'o:owner' => 'entity',
        // Alias of "o:owner" here.
        'o:email' => 'entity',
        // TODO Use all data names and storage ids from data to find existing assets before update. Keep source data as a key of resource to simplify process.
        'file' => 'string',
        'url' => 'string',
        // The name should be set after file/url to set it according to param.
        'o:name' => 'string',
        'o:storage_id' => 'string',
        'o:media_type' => 'string',
        'o:alt_text' => 'string',
        // To attach as thumbnail for resources.
        'o:resource' => 'entities',
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

            'asset_name' => 'filename',

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

    protected function prepareBaseEntitySpecific(ArrayObject $resource): self
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

    protected function fillResourceSpecific(ArrayObject $resource, array $data): self
    {
        foreach ($resource->getArrayCopy() as $field => $values) switch ($field) {
            default:
                continue 2;

            case 'o:storage_id':
                $value = $values;
                if (!$value) {
                    $resource['o:storage_id'] = null;
                    continue 2;
                }
                // Use the storage id to get the id, since it is unique.
                try {
                    $id = $this->bulkIdentifiers->getIdFromIndex($resource['source_index'])
                        // Read does not allow to return scalar.
                        ?: $this->api->read('assets', ['storage_id' => $value])->getContent();
                } catch (\Exception $e) {
                    $id = null;
                }
                if ($id) {
                    $resource['o:id'] = is_object($id) ? $id->id() : $id;
                    $resource['checked_id'] = true;
                } else {
                    $resource['o:id'] = $resource['o:id'] ?? null;
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
                // This is an entity: data are not yet filled inside resource.
                $resource['o:resource'] = [];
                $values = $data['o:resource'] ?? [];
                // Check values one by one to manage source identifiers.
                foreach (array_filter($values) as $key => $value) {
                    // May be ["o:id" => "identifier"].
                    if (is_array($value)) {
                        $value = reset($value);
                        if (!$value) {
                            continue;
                        }
                    }
                    $storedId = $this->bulkIdentifiers->getId($value, 'resources');
                    if ($storedId) {
                        $resource['o:resource'][$key] = [
                            'o:id' => $storedId,
                            'checked_id' => true,
                            'source_identifier' => $value,
                        ];
                    } elseif ($thumbnailForResourceId = $this->bulkIdentifiers->findResourcesFromIdentifiers($value, $this->identifierNames, 'resources', $resource['messageStore'])) {
                        $resource['o:resource'][$key] = [
                            'o:id' => $thumbnailForResourceId,
                            'checked_id' => true,
                            'source_identifier' => $value,
                        ];
                    } else {
                        // Only for first loop. Normally not possible after: all
                        // identifiers are stored in the list "map" during first loop.
                        $valueForMsg = mb_strlen($value) > 50 ? mb_substr($value, 0, 50) . '…' : $value;
                        $resource['messageStore']->addError('values', new PsrMessage(
                            'The value "{value}" is not a resource (field "{field}").', // @translate
                            ['value' => $valueForMsg, 'field' => $field]
                        ));
                    }
                }
                continue 2;

            case 'o:name':
                if ($resource['o:name'] === null || $resource['o:name'] === '') {
                    // According to list of fields, the url/file is already filled.
                    $setAssetName = $this->getParam('asset_name', 'filename');
                    switch ($setAssetName) {
                        default:
                        case 'filename':
                            $resource['o:name'] = $resource['ingest_url'] ?? $resource['ingest_filename'] ?? null;
                            $resource['o:name'] = $resource['o:name'] ? basename($resource['o:name']) : null;
                            break;
                        case 'filename_url':
                            if (!empty($resource['ingest_url'])) {
                                $resource['o:name'] = $resource['ingest_url'];
                            } elseif (!empty($resource['ingest_filename'])) {
                                $resource['o:name'] = basename($resource['ingest_filename']);
                            }
                            break;
                        case 'full':
                            $resource['o:name'] = $resource['ingest_url'] ?? $resource['ingest_filename'] ?? null;
                            break;
                        case 'none':
                            break;
                    }
                    if ($resource['o:name'] === '') {
                        $resource['o:name'] = null;
                    }
                }
                break;
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
    protected function createEntity(array $resource): ?AssetRepresentation
    {
        $asset = $this->createAsset($resource);

        if (!$asset) {
            $this->bulkCheckLog->logCheckedResource($this->indexResource, $resource);
            ++$this->totalErrors;
            return null;
        }

        $this->bulkIdentifiers->storeSourceIdentifiersIds($resource, $asset);
        $this->logger->notice(
            'Index #{index}: Created {resource_name} #{resource_id}', // @translate
            ['index' => $this->indexResource, 'resource_name' => $this->easyMeta->resourceLabel('assets'), 'resource_id' => $asset->id()]
        );

        $resource['o:id'] = $asset->id();
        $this->updateThumbnailForResources($resource);

        return $asset;
    }

    /**
     * Create a new asset.
     *
     *AssetAdapter requires an uploaded file, but it's common to use urls in
     *bulk import.
     *
     * @todo Factorize with \BulkImport\Processor\ResourceProcessor::createAssetFromUrl()
     */
    protected function createAsset(array $resource): ?AssetRepresentation
    {
        $resource['messageStore'] = $resource['messageStore'] ?? new MessageStore();

        $resource = $this->bulkIdentifiers->completeResourceIdentifierIds($resource);

        // TODO Clarify use of ingester and allows any ingester for assets.
        $pathOrUrl = $resource['url'] ?? $resource['file']
            ?? $resource['ingest_url'] ?? $resource['ingest_filename']
            ?? null;

        $this->bulkFile->setIsAsset(true);
        $result = $this->bulkFile->checkFileOrUrl($pathOrUrl, $resource['messageStore']);
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
            $resource['messageStore']->addError('file', $result['message']);
            return null;
        }

        $fullPath = $result['data']['fullpath'];

        $mediaType = $this->bulkFile->getMediaType($fullPath);

        // TODO Get the extension from the media type or use standard asset uploaded.

        // This doctrine resource should be reloaded each time the entity
        // manager is cleared, else a error may occur on big import.
        $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
        $owner = $entityManager->find(\Omeka\Entity\User::class, $resource['o:owner']['o:id'] ?? $this->userId);

        $isUrl = $this->bulk->isUrl($pathOrUrl);
        $name = strlen(trim((string) ($resource['o:name'] ?? '')))
            ? trim($resource['o:name'])
            : ($isUrl ? $pathOrUrl : $filename);

        $asset = new \Omeka\Entity\Asset;
        $asset->setName($name);
        // TODO Use the user specified in the config (owner).
        $asset->setOwner($owner);
        $asset->setStorageId($storageId);
        $asset->setExtension($extension);
        $asset->setMediaType($mediaType);
        $asset->setAltText($resource['o:alt_text'] ?? null);

        // TODO Remove this flush (required because there is a clear() after checks).
        $entityManager->persist($asset);
        $entityManager->flush();

        return $this->adapterManager->get('assets')->getRepresentation($asset);
    }

    /**
     * Only name and alt text are update for now (see api AssetAdapter).
     * Thumbnails of resources are updatable too.
     *
     * @see \BulkImport\Mvc\Controller\Plugin\UpdateResource
     */
    protected function updateDataAsset(array $resource): array
    {
        // Unlike resource, the only fields updatable via standard methods are
        // name, alternative text and attached resources.
        $resource['messageStore'] = $resource['messageStore'] ?? new MessageStore();

        // Always reload the resource that is currently managed to manage
        // multiple update of the same resource.
        try {
            $this->api->read('assets', $resource['o:id'], [], ['responseContent' => 'resource'])->getContent();
        } catch (\Exception $e) {
            // Normally already checked.
            $resource['messageStore']->addError('resource', new PsrMessage(
                'Index #{index}: The resource {resource} #{id} is not available and cannot be updated.', // @translate
                ['index' => $this->indexResource, 'resource' => 'asset', 'id', $resource['o:id']]
            ));
            $this->bulkCheckLog->logCheckedResource($this->indexResource, $resource);
            ++$this->totalErrors;
            return null;
        }

        // A name is required.

        return $resource;
    }

    protected function updateThumbnailForResources(array $resource): self
    {
        // Here, the resource is an asset.

        if (empty($resource['o:resource'])) {
            return $this;
        }

        // The id is required to attach the asset to a resource.
        if (empty($resource['o:id'])) {
            return $this;
        }

        // Attach asset to the resources.
        $resourcesToUpdateThumbnail = [];
        foreach ($resource['o:resource'] as $resourceForThumbnail) {
            // Normally checked early.
            if (empty($resourceForThumbnail['resource_name']) || $resourceForThumbnail['resource_name'] === 'resources') {
                try {
                    $resourceForThumbnail['resource_name'] = $this->api->read('resources', $resourceForThumbnail['o:id'], [], ['responseContent' => 'resource'])->getContent()
                        ->getResourceName();
                } catch (\Exception $e) {
                    $resource['messageStore']->addError('resource', new PsrMessage(
                        'The resource #{resource_id} for asset #{asset_id} does not exist and cannot be updated.', // @translate
                        ['resource_id' => $resourceForThumbnail['o:id'], 'asset_id' => $resource['o:id']]
                    ));
                    $messages = $this->listValidationMessages(new ValidationException($e->getMessage()));
                    $resource['messageStore']->addError('resource', $messages);
                    $this->bulkCheckLog->logCheckedResource($this->indexResource, $resource);
                    ++$this->totalErrors;
                    return $this;
                }
            }
            $resourceForThumbnail['o:thumbnail'] = ['o:id' => $resource['o:id']];
            $resourcesToUpdateThumbnail[] = $resourceForThumbnail;
        }

        // TODO Isolate the processes of updating resource thumbnails from assets.
        $assetAction = $this->action;
        $this->action = self::ACTION_SUB_UPDATE;
        foreach ($resourcesToUpdateThumbnail as $key => $resourceForThumbnail) {
            $resourceForThumbnail['messageStore'] = $resourceForThumbnail['messageStore'] ?? $resource['messageStore'] ?? new MessageStore();
            // These resources are logged with a negative index to avoid to
            // override assets.
            // This is normally useless anyway.
            $resourceForThumbnail['source_index'] = -(++$key);
            $this->updateEntity($resourceForThumbnail);
        }
        $this->action = $assetAction;

        return $this;
    }
}
