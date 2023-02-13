<?php declare(strict_types=1);

namespace BulkImport\Mvc\Controller\Plugin;

use BulkImport\Processor\AbstractProcessor;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;

class UpdateResourceProperties extends AbstractPlugin
{
    /**
     * @var \BulkImport\Mvc\Controller\Plugin\Bulk
     */
    protected $bulk;

    /**
     * @var array
     */
    protected $resource;

    /**
     * @var array
     */
    protected $values;

    /**
     * @var string
     */
    protected $updateMode;

    /**
     * @param array
     */
    protected $result;

    public function __construct(Bulk $bulk)
    {
        $this->bulk = $bulk;
    }

    /**
     * Update property values of a resources according to a mode.
     */
    public function __invoke($resource = null, array $values = null, ?string $mode = null): self
    {
        if ($resource === null && $values === null && $mode === null) {
            return $this;
        }

        $this->resource = is_null($resource) || is_array($resource)
            ? $resource
            : $this->bulk->resourceJson($resource);

        $this->values = $values;

        // Some of this actions are useless for properties (create), but
        // normally, this helper is not called in that case.
        // Here, Create is an alias of Append.
        $actions = [
            AbstractProcessor::ACTION_CREATE,
            AbstractProcessor::ACTION_APPEND,
            AbstractProcessor::ACTION_REVISE,
            AbstractProcessor::ACTION_UPDATE,
            AbstractProcessor::ACTION_REPLACE,
            AbstractProcessor::ACTION_DELETE,
            AbstractProcessor::ACTION_SKIP,
        ];
        $this->updateMode = in_array($mode, $actions)
            ? $mode
            : AbstractProcessor::ACTION_SKIP;

        $this->result = null;

        return $this;
    }

    /**
     * Get the resulting resource as array.
     */
    public function asArray(): array
    {
        if ($this->result === null) {
            $this->prepareUpdate();
        }
        return $this->result;
    }

    protected function prepareUpdate(): self
    {
        $this->result = $this->resource ?: [];

        if (empty($this->resource)
            || !$this->updateMode
            || $this->updateMode === AbstractProcessor::ACTION_SKIP
        ) {
            return $this;
        }

        $properties = $this->bulk->getPropertyIds();

        $resourceProperties = array_intersect_key($this->resource, $properties);

        // A quick check.
        if (!$resourceProperties && !$this->values) {
            return $this;
        }

        // Update properties.
        if ($this->updateMode === AbstractProcessor::ACTION_CREATE
             || $this->updateMode === AbstractProcessor::ACTION_APPEND
        ) {
            foreach ($this->values as $term => $vals) {
                $this->result[$term] = isset($this->result[$term])
                    ? array_merge(array_values($this->result[$term]), array_values($vals))
                    : $vals;
            }
        } elseif ($this->updateMode === AbstractProcessor::ACTION_REVISE) {
            $this->result = array_replace($this->resource, array_filter($this->values));
        } elseif ($this->updateMode === AbstractProcessor::ACTION_UPDATE) {
            $this->result = array_replace($this->resource, $this->values);
        } elseif ($this->updateMode === AbstractProcessor::ACTION_REPLACE) {
            $this->result = array_diff_key($this->resource, $properties) + array_filter($this->values);
        } elseif ($this->updateMode === AbstractProcessor::ACTION_DELETE) {
            $this->result = array_diff_key($this->resource, $this->values);
        }

        // Deduplicate properties.
        $newProperties = array_intersect_key($this->result, $properties);
        foreach ($newProperties as $term => &$vals) {
            $newVals = $this->bulk->normalizePropertyValues($term, $vals);
            // array_unique() does not work on array, so serialize them first.
            $vals = count($newVals) <= 1
                ? $newVals
                : array_map('unserialize', array_unique(array_map('serialize', $newVals)));
        }
        unset($vals);

        $this->result = array_diff_key($this->result, $properties) + array_filter($newProperties);

        return $this;
    }
}
