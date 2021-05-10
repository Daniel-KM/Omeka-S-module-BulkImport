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
    public function setConfig(array $config)
    {
        $this->config = $config;
        return $this;
    }

    public function getConfig(): array
    {
        return $this->config;
    }
}
