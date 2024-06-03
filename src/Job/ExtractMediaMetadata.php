<?php declare(strict_types=1);

namespace BulkImport\Job;

use Common\Stdlib\PsrMessage;
use Omeka\Job\AbstractJob;

/**
 * FIXME Warning: don't use shouldStop(), since it may be a fake job (see Module).
 */
class ExtractMediaMetadata extends AbstractJob
{
    /**
     * @var \BulkImport\Mvc\Controller\Plugin\Bulk
     */
    protected $bulk;

    /**
     * @var \Common\Stdlib\EasyMeta
     */
    protected $easyMeta;

    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * @todo Integrate param "value_datatype_literal" in ExtractMediaMetadata
     *
     * @var bool
     */
    protected $useDatatypeLiteral = true;

    public function perform(): void
    {
        $services = $this->getServiceLocator();

        // Log is a dependency of the module, so add some data.
        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('bulk/extract/job_' . $this->job->getId());

        $logger = $services->get('Omeka\Logger');
        $logger->addProcessor($referenceIdProcessor);

        $settings = $services->get('Omeka\Settings');
        if (!$settings->get('bulkimport_extract_metadata', false)) {
            $logger->warn(new PsrMessage(
                'The setting to extract metadata is not set.' // @translate
            ));
            return;
        }

        $itemId = (int) $this->getArg('item_id');
        if (!$itemId) {
            $logger->warn(new PsrMessage(
                'No item is set.' // @translate
            ));
            return;
        }

        /** @var \Omeka\Api\Manager $api */
        $api = $services->get('Omeka\ApiManager');
        try {
            $item = $api->read('items', ['id' => $itemId], [], ['responseContent' => 'resource'])->getContent();
        } catch (\Exception $e) {
            $logger->err(new PsrMessage(
                'The item #{item_id} is not available.', // @translate
                ['item_id' => $itemId]
            ));
            return;
        }

        // Process only new medias if existing medias are set.
        $skipMediaIds = $this->getArg('skip_media_ids', []);

        /**
         * @var \Common\Stdlib\EasyMeta $easyMeta
         * @var \BulkImport\Mvc\Controller\Plugin\ExtractMediaMetadata $extractMediaMetadata
         */
        $plugins = $services->get('ControllerPluginManager');
        $easyMeta = $services->get('EasyMeta');
        $extractMediaMetadata = $plugins->get('extractMediaMetadata');

        $this->bulk = $plugins->get('bulk');
        $this->easyMeta = $easyMeta;
        $this->logger = $logger;

        $propertyIds = $easyMeta->propertyIds();

        /** TODO Remove for Omeka v4. */
        if (!function_exists('array_key_last')) {
            function array_key_last(array $array)
            {
                return empty($array) ? null : key(array_slice($array, -1, 1, true));
            }
        }

        $totalExtracted = 0;
        /** @var \Omeka\Entity\Media $media */
        foreach ($item->getMedia() as $media) {
            $mediaId = $media->getId();
            if ($skipMediaIds && in_array($mediaId, $skipMediaIds)) {
                continue;
            }
            $extractedData = $extractMediaMetadata->__invoke($media);
            if ($extractedData === null) {
                continue;
            }
            ++$totalExtracted;
            if (!$extractedData) {
                continue;
            }

            // Convert the extracted metadata into properties and resource.
            // TODO Move ResourceProcessor process into a separated Filler to be able to use it anywhere.
            // For now, just manage resource class, template and properties without check neither linked resource.
            // TODO Keep original media data (but it is a creation, so generally empty in manual creation of item).
            $data = [];
            foreach ($extractedData as $dest => $values) {
                if (!is_array($values)) {
                    $values = (array) $values;
                }
                // TODO Reconvert dest.
                $field = strtok($dest, ' ');
                if ($field === 'o:resource_class') {
                    $value = array_key_last($values);
                    $id = $easyMeta->resourceClassId($value);
                    $data['o:resource_class'] = $id ? ['o:id' => $id] : null;
                } elseif ($field === 'o:resource_template') {
                    $value = array_key_last($values);
                    $id = $easyMeta->resourceTemplateId($value);
                    $data['o:resource_template'] = $id ? ['o:id' => $id] : null;
                } elseif (isset($propertyIds[$field])) {
                    $data[$field] = [];
                    // array_unique() cannot be used with sub-arrays, for
                    // example multiple data types or special mapping.
                    // $values = array_unique($values);
                    $values = array_map('unserialize', array_unique(array_map('serialize', $values)));
                    foreach ($values as $value) {
                        if (is_array($value)) {
                            $val = $this->fillPropertyForValue($field, 'literal', $value);
                            if ($val) {
                                $data[$field][] = $val;
                            }
                        } else {
                            $data[$field][] = [
                                'type' => 'literal',
                                'property_id' => $propertyIds[$field],
                                'is_public' => true,
                                '@value' => $value,
                            ];
                        }
                    }
                }
            }

            if (!$data) {
                continue;
            }

            // FIXME There may be a doctrine issue with AccessStatus (status of item not found).
            try {
                $api->update('media', ['id' => $mediaId], $data, [], ['isPartial' => true]);
            } catch (\Exception $e) {
                $logger->err(
                    'Media #{media_id}: an issue occurred during update: {exception}.', // @translate
                    ['media_id' => $mediaId, 'exception' => $e]
                );
            }
        }

        $logger->notice(
            'Item #{item_id}: data extracted for {total} media.', // @translate
            ['item_id' => $itemId, 'total' => $totalExtracted]
        );
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
     *
     * Copy / adapted in:
     * @see \BulkImport\Processor\ResourceProcessor::fillPropertyForValue()
     * @see \BulkImport\Job\ExtractMediaMetadata::fillPropertyForValue()
     * @todo Factorize or normalize fillPropertyForValue(). May be simplified here because there is no linked resource.
     */
    protected function fillPropertyForValue(
        string $term,
        string $dataType,
        array $value,
        ?int $vrId = null
    ): ?array {
        // Common data for all data types.
        $resourceValue = [
            'type' => $dataType,
            'property_id' => $value['property_id'] ?? $this->easyMeta->propertyId($term),
            'is_public' => $value['is_public'] ?? true,
        ];

        $mainDataType = $this->easyMeta->dataTypeMain($dataType);

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
                    $this->logger->err(
                        'The value "{value}" for property "{term}" is not member of custom vocab "{customvocab}".', // @translate
                        ['value' => $valueForMsg, 'term' => $term, 'customvocab' => $dataType]
                    );
                    return null;
                }
                $dataType = 'literal';
                $this->logger->notice(
                    'The value "{value}" for property "{term}" is not member of custom vocab "{customvocab}". A literal value is used instead.', // @translate
                    ['value' => $valueForMsg, 'term' => $term, 'customvocab' => $dataType]
                );
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

        return $resourceValue;
    }
}
