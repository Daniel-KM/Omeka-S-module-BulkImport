<?php declare(strict_types=1);

namespace BulkImport\Traits;

trait ConfigurableTrait
{
    protected $config = [];

    public function setConfig(array $config): self
    {
        $this->config = $config;
        return $this;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getConfigParam($name, $default = null)
    {
        return array_key_exists($name, $this->config)
            ? $this->config[$name]
            : $default;
    }
}
