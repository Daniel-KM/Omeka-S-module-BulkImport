<?php declare(strict_types=1);

namespace BulkImport\Traits;

trait ParametrizableTrait
{
    protected $params = [];

    public function setParams(array $params): self
    {
        $this->params = $params;
        return $this;
    }

    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * @todo Remove default arg.
     */
    public function getParam($name, $default = null)
    {
        return array_key_exists($name, $this->params)
            ? $this->params[$name]
            : $default;
    }
}
