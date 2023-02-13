<?php declare(strict_types=1);

namespace BulkImport\Mvc\Controller\Plugin;

use BulkImport\Processor\AbstractProcessor;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;

class DiffResources extends AbstractPlugin
{
    // TODO Manage other metadata.
    protected $skip = [
        // Internal keys.
        'source_index',
        'checked_id',
        'has_error',
        'messageStore',
        'resource_name',
        // Omeka data;
        '@context',
        '@type',
        // TODO @id may be kept when importing external data.
        '@id',
        'o:site',
        'o:thumbnail',
        'o:title',
        'thumbnail_display_urls',
        'o:created',
        'o:modified',
        'o:media',
        'o:item_set',
        'o:item',
    ];

    /**
     * @var \BulkImport\Mvc\Controller\Plugin\Bulk
     */
    protected $bulk;

    /**
     * @var \BulkImport\Mvc\Controller\Plugin\UpdateResourceProperties
     */
    protected $updateResourceProperties;

    /**
     * @var array
     */
    protected $resource1;

    /**
     * @var array
     */
    protected $resource2;

    /**
     * @var string
     */
    protected $updateMode;

    /**
     * @param array
     */
    protected $result;

    public function __construct(
        Bulk $bulk,
        UpdateResourceProperties $updateResourceProperties
    ) {
        $this->bulk = $bulk;
        $this->updateResourceProperties = $updateResourceProperties;
    }

    /**
     * Make a diff between two resources.
     *
     * Only main values are comparated.
     *
     * The two resources can be the same, before and after an update.
     * The optional mode allows to diff simulating an update of the first
     * resource with the data of the second resource.
     */
    public function __invoke($resource1 = null, $resource2 = null, ?string $updateMode = null): self
    {
        if ($resource1 === null && $resource2 === null && $updateMode === null) {
            return $this;
        }

        $this->resource1 = is_null($resource1) || is_array($resource1)
            ? $resource1
            : $this->bulk->resourceJson($resource1);

        $this->resource2 = is_null($resource2) || is_array($resource2)
            ? $resource2
            : $this->bulk->resourceJson($resource2);

        // Some of this actions are useless for properties (create), but
        // normally, this helper is not called in that case.
        // Here, Create is an alias of Append.
        $actions = [
            // AbstractProcessor::ACTION_CREATE,
            AbstractProcessor::ACTION_APPEND,
            AbstractProcessor::ACTION_REVISE,
            AbstractProcessor::ACTION_UPDATE,
            AbstractProcessor::ACTION_REPLACE,
            // AbstractProcessor::ACTION_DELETE,
            // AbstractProcessor::ACTION_SKIP,
        ];
        $this->updateMode = in_array($updateMode, $actions)
            ? $updateMode
            : null;

        $this->result = null;

        return $this;
    }

    /**
     * Get the diff for each meta, that can be scalar or array (properties).
     */
    public function asArray(): array
    {
        if ($this->result === null) {
            $this->prepareDiff();
        }
        return $this->result;
    }

    public function asFlatArray(): array
    {
        if ($this->result === null) {
            $this->prepareDiff();
        }
        $flat = [];
        foreach ($this->result as $value) {
            if (isset($value['meta'])) {
                $flat[] = $value;
            } else {
                $flat = array_merge($flat, array_values($value));
            }
        }
        return $flat;
    }

    protected function prepareDiff(): self
    {
        if (empty($this->resource1)
            && empty($this->resource2)
        ) {
            $this->result['resource'] = [
                'meta' => 'resource',
                'data1' => null,
                'data2' => null,
                'diff' => '',
            ];
            return $this;
        }

        if (empty($this->resource1)) {
            $this->result['resource'] = [
                'meta' => 'resource',
                'data1' => null,
                'data2' => $this->resource2['o:id'] ?? null,
                'diff' => '+',
            ];
            return $this;
        }

        if (empty($this->resource2)) {
            $this->result['resource'] = [
                'meta' => 'resource',
                'data1' => $this->resource1['o:id'] ?? null,
                'data2' => null,
                'diff' => '-',
            ];
            return $this;
        }

        if (!empty($this->resource1['has_error'])
            || !empty($this->resource2['has_error'])
        ) {
            $this->result['has_error'] = [
                'meta' => 'has_error',
                'data1' => $this->resource1['o:id'] ?? null,
                'data2' => $this->resource2['o:id'] ?? null,
                'diff' => '×',
            ];
            return $this;
        }

        // Loop metadata for first resource.
        foreach ($this->resource1 as $meta => $data1) {
            $data2 = isset($this->resource2[$meta])
                ? $this->resource2[$meta]
                : null;
            unset($this->resource1[$meta]);
            unset($this->resource2[$meta]);
            if (in_array($meta, $this->skip)) {
                continue;
            }
            $this->result[$meta] = $this->diffMetadata($meta, $data1, $data2);
        }

        // Append remaining metadata, missing in resource1.
        foreach ($this->resource2 as $meta => $data2) {
            unset($this->resource2[$meta]);
            if (in_array($meta, $this->skip)) {
                continue;
            }
            $this->result[$meta] = $this->diffMetadata($meta, null, $data2);
        }

        return $this;
    }

    protected function diffMetadata(string $meta, $data1, $data2): array
    {
        $resultMeta = [];

        // All meta have a single value except properties.
        // Nevertheless, only owner, class, template and some simple metadata
        // are checked for now.

        if ($meta === 'o:owner'
            || $meta === 'o:resource_class'
            || $meta === 'o:resource_template'
            || $meta === 'o:primary_media'
        ) {
            $data1 = empty($data1) || empty($data1['o:id']) ? null : (int) $data1['o:id'];
            $data2 = empty($data2) || empty($data2['o:id']) ? null : (int) $data2['o:id'];
        }

        // Manage metadata in a generic way.

        $isProperty = is_integer($this->bulk->getPropertyId($meta));

        if (!$isProperty) {
            $resultMeta = [
                'meta' => $meta,
                'data1' => $data1,
                'data2' => $data2,
                'diff' => null,
            ];

            // Don't manage unknown data with sub-arrays for now.
            if ((!is_null($data1) && !is_scalar($data1))
                || (!is_null($data2) && !is_scalar($data2))
            ) {
                $resultMeta['diff'] = '?';
            } elseif ($data1 === $data2) {
                $resultMeta['diff'] = '=';
            } elseif (!$data2) {
                $resultMeta['diff'] = '-';
            } elseif (!$data1) {
                $resultMeta['diff'] = '+';
            } else {
                $resultMeta['diff'] = '≠';
            }

            return $resultMeta;
        }

        // Properties.

        // To diff values requires to have complete, normalized and ordered
        // representation.
        $dataNorm1 = $data1 ? $this->bulk->normalizePropertyValues($meta, $data1) : [];
        $dataNorm2 = $data2 ? $this->bulk->normalizePropertyValues($meta, $data2) : [];
        $data2 = $dataNorm2;

        // For properties, the diff depends on the action, so the new data
        // should update old data before doing the diff.
        if ($this->updateMode) {
            if ($data2) {
                $dataNorm2 = $this->updateResourceProperties
                    ->__invoke(
                        [$meta => $dataNorm1],
                        [$meta => $dataNorm2],
                        $this->updateMode
                    )->asArray();
                $dataNorm2 = reset($dataNorm2) ?: [];
            }  else {
                $dataNorm2 = [];
            }
        }

        // Processing the diff.

        if (!$dataNorm1 && !$dataNorm2) {
            // But the meta exists, so output it.
            $resultMeta[] = [
                'meta' => $meta,
                'data1' => null,
                'data2' => null,
                'diff' => '=',
            ];
            return $resultMeta;
        }

        if (!$dataNorm2) {
            foreach ($dataNorm1 as $value1) {
                $resultMeta[] = [
                    'meta' => $meta,
                    'data1' => $value1,
                    'data2' => null,
                    'diff' => '-',
                ];
            }
            return $resultMeta;
        }

        if (!$dataNorm1) {
            foreach ($dataNorm2 as $value2) {
                $resultMeta[] = [
                    'meta' => $meta,
                    'data1' => null,
                    'data2' => $value2,
                    'diff' => '+',
                ];
            }
            return $resultMeta;
        }

        // TODO Compare order.

        foreach ($dataNorm1 as $key1 => $value1) {
            $has = false;
            foreach ($dataNorm2 as $key2 => $value2) {
                if ($value1 === $value2) {
                    $resultMeta[] = [
                        'meta' => $meta,
                        'data1' => $value1,
                        'data2' => $value2,
                        'diff' => '=',
                    ];
                    $has = true;
                    unset($dataNorm2[$key2]);
                    break;
                }
            }
            if (!$has) {
                $resultMeta[] = [
                    'meta' => $meta,
                    'data1' => $value1,
                    'data2' => null,
                    'diff' => '-',
                ];
            }
            unset($dataNorm1[$key1]);
        }

        foreach ($dataNorm2 as $key2 => $value2) {
            $resultMeta[] = [
                'meta' => $meta,
                'data1' => null,
                'data2' => $value2,
                'diff' => '+',
            ];
        }

        return $resultMeta;
    }
}
