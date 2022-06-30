<?php declare(strict_types=1);

namespace BulkImport\Processor;

use ArrayObject;
use BulkImport\Form\Processor\ResourceProcessorConfigForm;
use BulkImport\Form\Processor\ResourceProcessorParamsForm;
use Log\Stdlib\PsrMessage;
use Omeka\Api\Request;

class ResourceProcessor extends AbstractResourceProcessor
{
    protected $resourceName = 'resources';

    protected $resourceLabel = 'Mixed resources'; // @translate

    protected $configFormClass = ResourceProcessorConfigForm::class;

    protected $paramsFormClass = ResourceProcessorParamsForm::class;

    protected function handleFormSpecific(ArrayObject $args, array $values): \BulkImport\Processor\Processor
    {
        if (isset($values['resource_name'])) {
            $args['resource_name'] = $values['resource_name'];
        }
        $this->handleFormItem($args, $values);
        $this->handleFormItemSet($args, $values);
        $this->handleFormMedia($args, $values);
        return $this;
    }

    protected function handleFormItem(ArrayObject $args, array $values): \BulkImport\Processor\Processor
    {
        if (isset($values['o:item_set'])) {
            $ids = $this->bulk->findResourcesFromIdentifiers($values['o:item_set'], 'o:id', 'item_sets');
            foreach ($ids as $id) {
                $args['o:item_set'][] = ['o:id' => $id];
            }
        }
        return $this;
    }

    protected function handleFormItemSet(ArrayObject $args, array $values): \BulkImport\Processor\Processor
    {
        if (isset($values['o:is_open'])) {
            $args['o:is_open'] = $values['o:is_open'] !== 'false';
        }
        return $this;
    }

    protected function handleFormMedia(ArrayObject $args, array $values): \BulkImport\Processor\Processor
    {
        if (!empty($values['o:item'])) {
            $id = $this->bulk->findResourceFromIdentifier($values['o:item'], 'o:id', 'items');
            if ($id) {
                $args['o:item'] = ['o:id' => $id];
            }
        }
        return $this;
    }

    protected function baseSpecific(ArrayObject $resource): \BulkImport\Processor\Processor
    {
        // Determined by the entry, but prepare all possible types in the case
        // there is a mapping.
        $this->baseItem($resource);
        $this->baseItemSet($resource);
        $this->baseMedia($resource);
        $resource['resource_name'] = $this->getParam('resource_name');
        return $this;
    }

    protected function baseItem(ArrayObject $resource): \BulkImport\Processor\Processor
    {
        $resource['resource_name'] = 'items';
        $resource['o:item_set'] = $this->getParam('o:item_set', []);
        $resource['o:media'] = [];
        return $this;
    }

    protected function baseItemSet(ArrayObject $resource): \BulkImport\Processor\Processor
    {
        $resource['resource_name'] = 'item_sets';
        $isOpen = $this->getParam('o:is_open', null);
        $resource['o:is_open'] = $isOpen;
        return $this;
    }

    protected function baseMedia(ArrayObject $resource): \BulkImport\Processor\Processor
    {
        $resource['resource_name'] = 'media';
        $resource['o:item'] = $this->getParam('o:item') ?: ['o:id' => null];
        return $this;
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

        // When the resource name is known, don't fill other resources. But if
        // is not known yet, fill the item first. It fixes the issues with the
        // target that are the same for media of item and media (that is a
        // special case where two or more resources are created from one
        // entry).
        $resourceName = empty($resource['resource_name']) ? true : $resource['resource_name'];

        switch ($target['target']) {
            case 'resource_name':
                $value = array_pop($values);
                $resourceName = preg_replace('~[^a-z]~', '', strtolower((string) $value));
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
        return false;
    }

    protected function fillItem(ArrayObject $resource, $target, array $values): bool
    {
        switch ($target['target']) {
            case 'o:item_set':
                $identifierName = $target['target_data'] ?? $this->bulk->getIdentifierNames();
                $ids = $this->bulk->findResourcesFromIdentifiers($values, $identifierName, 'item_sets', $resource['messageStore']);
                foreach ($ids as $id) {
                    $resource['o:item_set'][] = [
                        'o:id' => $id,
                        'checked_id' => true,
                    ];
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
            case 'tile':
                foreach ($values as $value) {
                    $media = [];
                    $media['o:ingester'] = 'tile';
                    $media['ingest_url'] = $value;
                    $media['o:source'] = $value;
                    $this->appendRelated($resource, $media);
                }
                return true;
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
                        $value = array_pop($values);
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
        }
        return false;
    }

    protected function fillItemSet(ArrayObject $resource, $target, array $values): bool
    {
        switch ($target['target']) {
            case 'o:is_open':
                $value = array_pop($values);
                $resource['o:is_open'] = in_array(strtolower((string) $value), ['0', 'false', 'no', 'off', 'closed'], true)
                    ? false
                    : (bool) $value;
                return true;
        }
        return false;
    }

    protected function fillMedia(ArrayObject $resource, $target, array $values): bool
    {
        switch ($target['target']) {
            case 'o:filename':
            case 'o:basename':
            case 'o:storage_id':
            case 'o:source':
            case 'o:sha256':
                $value = trim((string) array_pop($values));
                if (!$value) {
                    return true;
                }
                $id = $this->bulk->findResourceFromIdentifier($value, $target['target'], 'media', $resource['messageStore']);
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
                // $value = array_pop($values);
                $identifierName = $target['target_data'] ?? $this->bulk->getIdentifierNames();
                $ids = $this->bulk->findResourcesFromIdentifiers($values, $identifierName, 'items', $resource['messageStore']);
                $id = $ids ? array_pop($ids) : null;
                $resource['o:item'] = [
                    'o:id' => $id,
                    'checked_id' => true,
                ];
                return true;
            case 'url':
                $value = array_pop($values);
                $resource['o:ingester'] = 'url';
                $resource['ingest_url'] = $value;
                $resource['o:source'] = $value;
                return true;
            case 'file':
                $value = array_pop($values);
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
                $value = array_pop($values);
                $resource['o:ingester'] = 'sideload_dir';
                $resource['ingest_directory'] = $value;
                $resource['o:source'] = $value;
                return true;
            case 'html':
                $value = array_pop($values);
                $resource['o:ingester'] = 'html';
                $resource['html'] = $value;
                return true;
            case 'iiif':
                $value = array_pop($values);
                $resource['o:ingester'] = 'iiif';
                $resource['ingest_url'] = null;
                if (!$this->bulk->isUrl($value)) {
                    $value = $this->getParam('iiifserver_media_api_url', '') . $value;
                }
                $resource['o:source'] = $value;
                return true;
            case 'tile':
                $value = array_pop($values);
                $resource['o:ingester'] = 'tile';
                $resource['ingest_url'] = $value;
                $resource['o:source'] = $value;
                return true;
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
    ): \BulkImport\Processor\Processor {
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

        // The parent is checked first, because id may be needed in next checks.
        if (!parent::checkEntity($resource)) {
            return false;
        }

        switch ($resource['resource_name']) {
            case 'items':
                if (!$this->checkItem($resource)) {
                    return false;
                }
                break;
            case 'item_sets':
                if (!$this->checkItemSet($resource)) {
                    return false;
                }
                break;
            case 'media':
                if (!$this->checkMedia($resource)) {
                    return false;
                }
                break;
            default:
                // Never.
                return !$resource['messageStore']->hasErrors();
        }

        // Don't do more check for deletion, check only for update or create.
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

        if ($operation === Request::CREATE) {
            $entityClass = $adapter->getEntityClass();
            $entity = new $entityClass;
            if ($resource['resource_name'] === 'media') {
                // Normally already checked.
                $entityItem = $adapter->getAdapter('items')->findEntity($resource['o:item']['o:id'] ?? 0);
                if (!$entityItem) {
                    $resource['messageStore']->addError('media', new PsrMessage(
                        'Media must belong to an item.' // @translate
                    ));
                    return false;
                }
                $entity->setItem($entityItem);
            }
        } else {
            // The id is already checked.
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
            $this->apiManager->initialize($adapter, $request);
        } catch (\Exception $e) {
            $resource['messageStore']->addError('modules', new PsrMessage(
                'Initialization exception: {exception}', // @translate
                ['exception' => $e]
            ));
            return false;
        }

        // Check new files for items and media before hydration to speed process
        // because files are checked during hydration too, but with a full
        // download.
        $this->checkNewFiles($resource);

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
                $resource['o:media'][$key]['o:id'] = $this->bulk
                    ->findResourceFromIdentifier($media['o:source'], $identifierProperties, 'media', $resource['messageStore']);
            }
        }

        // The check is needed to avoid a notice because it's an ArrayObject.
        if (property_exists($resource, 'o:item')) {
            unset($resource['o:item']);
        }

        return true;
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
        return true;
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

        if (empty($resource['o:id']) && $this->actionRequiresId()) {
            $resource['messageStore']->addError('resource_id', new PsrMessage(
                'No internal id can be found for the media' // @translate
            ));
            return false;
        }

        if (empty($resource['o:id']) && empty($resource['o:item']['o:id'])) {
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
        return true;
    }

    protected function processEntities(array $data): \BulkImport\Processor\Processor
    {
        $resourceName = $this->getResourceName();
        if ($resourceName !== 'resources') {
            parent::processEntities($data);
            return $this;
        }

        if (!count($data)) {
            return $this;
        }

        // Process all resources, but keep order, so process them by type.
        // Useless when the batch is 1.
        // TODO Create an option for full order by id for items, then media.
        $datas = [];
        $previousResourceName = $data[0]['resource_name'];
        foreach ($data as $dataResource) {
            if ($previousResourceName !== $dataResource['resource_name']) {
                $this->resourceName = $previousResourceName;
                parent::processEntities($datas);
                $this->resourceName = 'resources';
                $previousResourceName = $dataResource['resource_name'];
                $datas = [];
            }
            $datas[] = $dataResource;
        }
        if ($datas) {
            $this->resourceName = $previousResourceName;
            parent::processEntities($datas);
            $this->resourceName = 'resources';
        }
        return $this;
    }
}
