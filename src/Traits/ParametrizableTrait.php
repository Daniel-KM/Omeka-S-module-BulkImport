<?php declare(strict_types=1);

namespace BulkImport\Traits;

trait ParametrizableTrait
{
    /**
     * @var array
     */
    protected $params = [];

    /**
     * @return self
     */
    public function setParams(array $params): self
    {
        $this->params = $params;
        return $this;
    }

    /**
     * @return array
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public function getParam($name, $default = null)
    {
        return array_key_exists($name, $this->params)
            ? $this->params[$name]
            : $default;
    }
}
