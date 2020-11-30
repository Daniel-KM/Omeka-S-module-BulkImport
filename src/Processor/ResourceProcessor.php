<?php declare(strict_types=1);
namespace BulkImport\Processor;

use ArrayObject;
use BulkImport\Form\Processor\ResourceProcessorConfigForm;
use BulkImport\Form\Processor\ResourceProcessorParamsForm;

class ResourceProcessor extends AbstractResourceProcessor
{
    protected $resourceType = 'resources';

    protected $resourceLabel = 'Mixed resources'; // @translate

    protected $configFormClass = ResourceProcessorConfigForm::class;

    protected $paramsFormClass = ResourceProcessorParamsForm::class;

    protected function handleFormSpecific(ArrayObject $args, array $values): void
    {
        if (isset($values['resource_type'])) {
            $args['resource_type'] = $values['resource_type'];
        }
        $this->handleFormItem($args, $values);
        $this->handleFormItemSet($args, $values);
        $this->handleFormMedia($args, $values);
    }

    protected function handleFormItem(ArrayObject $args, array $values): void
    {
        if (isset($values['o:item_set'])) {
            $ids = $this->findResourcesFromIdentifiers($values['o:item_set'], 'o:id', 'item_sets');
            foreach ($ids as $id) {
                $args['o:item_set'][] = ['o:id' => $id];
            }
        }
    }

    protected function handleFormItemSet(ArrayObject $args, array $values): void
    {
        if (isset($values['o:is_open'])) {
            $args['o:is_open'] = $values['o:is_open'] !== 'false';
        }
    }

    protected function handleFormMedia(ArrayObject $args, array $values): void
    {
        if (!empty($values['o:item'])) {
            $id = $this->findResourceFromIdentifier($values['o:item'], 'o:id', 'items');
            if ($id) {
                $args['o:item'] = ['o:id' => $id];
            }
        }
    }

    protected function baseSpecific(ArrayObject $resource): void
    {
        // Determined by the entry, but prepare all possible types in the case
        // there is a mapping.
        $this->baseItem($resource);
        $this->baseItemSet($resource);
        $this->baseMedia($resource);
        $resource['resource_type'] = $this->getParam('resource_type');
    }

    protected function baseItem(ArrayObject $resource): void
    {
        $resource['resource_type'] = 'items';
        $resource['o:item_set'] = $this->getParam('o:item_set', []);
        $resource['o:media'] = [];
    }

    protected function baseItemSet(ArrayObject $resource): void
    {
        $resource['resource_type'] = 'item_sets';
        $isOpen = $this->getParam('o:is_open', null);
        $resource['o:is_open'] = $isOpen;
    }

    protected function baseMedia(ArrayObject $resource): void
    {
        $resource['resource_type'] = 'media';
        $resource['o:item'] = $this->getParam('o:item') ?: ['o:id' => null];
    }

    protected function fillSpecific(ArrayObject $resource, $target, array $values)
    {
        static $resourceTypes;

        if (is_null($resourceTypes)) {
            $translate = $this->getServiceLocator()->get('ViewHelperManager')->get('translate');
            $resourceTypes = [
                'o:Item' => 'items',
                'o:ItemSet' => 'item_sets',
                'o:Media' => 'media',
                'o:item' => 'items',
                'o:item_set' => 'item_sets',
                'o:media' => 'media',
                'item' => 'items',
                'itemset' => 'item_sets',
                'media' => 'media',
                'items' => 'items',
                'itemsets' => 'item_sets',
                'medias' => 'media',
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
                $translate('collection') => 'item_sets',
                $translate('collections') => 'item_sets',
                $translate('file') => 'media',
                $translate('files') => 'media',
            ];
        }

        // When the resource type is known, don't fill other resources. But if
        // is not known yet, fill the item first. It fixes the issues with the
        // target that are the same for media of item and media (that is a
        // special case where two or more resources are created from one
        // entry).
        $resourceType = empty($resource['resource_type']) ? true : $resource['resource_type'];

        switch ($target['target']) {
            case 'resource_type':
                $value = array_pop($values);
                $resourceType = preg_replace('~[^a-z]~', '', strtolower((string) $value));
                if (isset($resourceTypes[$resourceType])) {
                    $resource['resource_type'] = $resourceTypes[$resourceType];
                }
                return true;
            case $resourceType == 'items' && $this->fillItem($resource, $target, $values):
                return true;
            case $resourceType == 'item_sets' && $this->fillItemSet($resource, $target, $values):
                return true;
            case $resourceType == 'media' && $this->fillMedia($resource, $target, $values):
                return true;
            default:
                return false;
        }
    }

    protected function fillItem(ArrayObject $resource, $target, array $values)
    {
        switch ($target['target']) {
            case 'o:item_set':
                $identifierName = isset($target['target_data']) ? $target['target_data'] : $this->getIdentifierNames();
                $ids = $this->findResourcesFromIdentifiers($values, $identifierName, 'item_sets');
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
                foreach ($values as $value) {
                    $media = [];
                    if ($this->isUrl($value)) {
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
            case 'tile':
                foreach ($values as $value) {
                    $media = [];
                    $media['o:ingester'] = 'tile';
                    $media['ingest_url'] = $value;
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
            case 'o:media':
                if (isset($target["target_data"])) {
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
                    $this->logger->err(
                        'Index #{index} skipped: the mapping bounds requires four numeric values separated by a comma.',  // @translate
                        ['index' => $this->indexResource]
                    );
                    $resource['has_error'] = true;
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
                        $this->logger->err(
                            'Index #{index} skipped: the mapping marker requires a latitude and a longitude separated by a "/".',  // @translate
                            ['index' => $this->indexResource]
                        );
                        $resource['has_error'] = true;
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

    protected function fillItemSet(ArrayObject $resource, $target, array $values)
    {
        switch ($target['target']) {
            case 'o:is_open':
                $value = array_pop($values);
                $resource['o:is_open'] = in_array(strtolower((string) $value), ['false', 'no', 'off', 'closed'])
                    ? false
                    : (bool) $value;
                return true;
        }
        return false;
    }

    protected function fillMedia(ArrayObject $resource, $target, array $values)
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
                $id = $this->findResourceFromIdentifier($value, $target['target'], 'media');
                if ($id) {
                    $resource['o:id'] = $id;
                    $resource['checked_id'] = true;
                } else {
                    $resource['has_error'] = true;
                    $this->logger->err(
                        'Index #{index}: Media with metadata "{target}" "{identifier}" cannot be found. The entry is skipped.', // @translate
                        ['index' => $this->indexResource, 'target' => $target['target'], 'identifier' => $value]
                    );
                }
                return true;
            case 'o:item':
                // $value = array_pop($values);
                $identifierName = isset($target["target_data"]) ? $target["target_data"] : $this->getIdentifierNames();
                $ids = $this->findResourcesFromIdentifiers($values, $identifierName, 'items');
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
                if ($this->isUrl($value)) {
                    $resource['o:ingester'] = 'url';
                    $resource['ingest_url'] = $value;
                } else {
                    $resource['o:ingester'] = 'sideload';
                    $resource['ingest_filename'] = $value;
                }
                $resource['o:source'] = $value;
                return true;
            case 'tile':
                $value = array_pop($values);
                $resource['o:ingester'] = 'tile';
                $resource['ingest_url'] = $value;
                $resource['o:source'] = $value;
                return true;
            case 'html':
                $value = array_pop($values);
                $resource['o:ingester'] = 'html';
                $resource['html'] = $value;
                return true;
        }
    }

    /**
     * Append an attached resource to a resource, checking if it exists already.
     *
     * It allows to fill multiple media of an items, or any other related
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
    ): void {
        if (!empty($resource[$metadata])) {
            foreach ($resource[$metadata] as $key => $values) {
                if (!array_key_exists($check, $values)) {
                    // Use the last data set.
                    $resource[$metadata][$key] = $related + $resource[$metadata][$key];
                    return;
                }
            }
        }
        $resource[$metadata][] = $related;
    }

    protected function checkEntity(ArrayObject $resource)
    {
        if (empty($resource['resource_type'])) {
            $this->logger->err(
                'Index #{index} skipped: no resource type set',  // @translate
                ['index' => $this->indexResource]
            );
            return false;
        }

        if (!in_array($resource['resource_type'], ['items', 'item_sets', 'media'])) {
            $this->logger->err(
                'Index #{index} skipped: resource type "{resource_type}" not managed', // @translate
                [
                    'index' => $this->indexResource,
                    'resource_type' => $resource['resource_type'],
                ]
            );
            return false;
        }

        // The parent is checked first, because id may be needed in next checks.
        if (!parent::checkEntity($resource)) {
            return false;
        }

        switch ($resource['resource_type']) {
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
        }

        return !$resource['has_error'];
    }

    protected function checkItem(ArrayObject $resource)
    {
        // Media of an item are public by default.
        foreach ($resource['o:media'] as $key => $media) {
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
        if ($resource['o:id'] && $resource['o:media'] && $this->actionIsUpdate()) {
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
                $resource['o:media'][$key]['o:id'] = $this->findResourceFromIdentifier(
                    $media['o:source'],
                    $identifierProperties,
                    'media'
                );
            }
        }

        // The check is needed to avoid a notice because it's an ArrayObject.
        if (array_key_exists('o:item', $resource)) {
            unset($resource['o:item']);
        }

        return true;
    }

    protected function checkItemSet(ArrayObject $resource)
    {
        // The check is needed to avoid a notice because it's an ArrayObject.
        if (array_key_exists('o:item', $resource)) {
            unset($resource['o:item']);
        }
        if (array_key_exists('o:item_set', $resource)) {
            unset($resource['o:item_set']);
        }
        if (array_key_exists('o:media', $resource)) {
            unset($resource['o:media']);
        }
        return true;
    }

    protected function checkMedia(ArrayObject $resource)
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
            $this->logger->err(
                'Index #{index} skipped: no internal id can be found for the media', // @translate
                ['index' => $this->indexResource]
            );
            return false;
        }

        if (empty($resource['o:id']) && empty($resource['o:item']['o:id'])) {
            if ($this->action !== self::ACTION_DELETE) {
                $this->logger->err(
                    'Index #{index} skipped: no item is set for the media', // @translate
                    ['index' => $this->indexResource]
                );
                return false;
            }
        }

        // The check is needed to avoid a notice because it's an ArrayObject.
        if (array_key_exists('o:item_set', $resource)) {
            unset($resource['o:item_set']);
        }
        if (array_key_exists('o:media', $resource)) {
            unset($resource['o:media']);
        }
        return true;
    }

    protected function processEntities(array $data): void
    {
        $resourceType = $this->getResourceType();
        if ($resourceType !== 'resources') {
            parent::processEntities($data);
            return;
        }

        if (!count($data)) {
            return;
        }

        // Process all resources, but keep order, so process them by type.
        // Useless when the batch is 1.
        // TODO Create an option for full order by id for items, then media.
        $datas = [];
        $previousResourceType = $data[0]['resource_type'];
        foreach ($data as $dataResource) {
            if ($previousResourceType !== $dataResource['resource_type']) {
                $this->resourceType = $previousResourceType;
                parent::processEntities($datas);
                $this->resourceType = 'resources';
                $previousResourceType = $dataResource['resource_type'];
                $datas = [];
            }
            $datas[] = $dataResource;
        }
        if ($datas) {
            $this->resourceType = $previousResourceType;
            parent::processEntities($datas);
            $this->resourceType = 'resources';
        }
    }
}
