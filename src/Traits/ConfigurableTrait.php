<?php declare(strict_types=1);

namespace BulkImport\Traits;

trait ConfigurableTrait
{
    /**
     * @var array
     */
    protected $config = [];

    /**
     * @return self
     */
    public function setConfig(array $config): self
    {
        $this->config = $config;
        return $this;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public function getConfigParam($name, $default = null)
    {
        return array_key_exists($name, $this->config)
            ? $this->config[$name]
            : $default;
    }
}
