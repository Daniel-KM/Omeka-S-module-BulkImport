<?php declare(strict_types=1);
namespace BulkImport\Traits;

trait ConfigurableTrait
{
    /**
     * @var array|\ArrayObject
     */
    protected $config = [];

    /**
     * @param array|\ArrayObject $config
     * @return self
     */
    public function setConfig($config)
    {
        $this->config = $config;
        return $this;
    }

    /**
     * @return array|\ArrayObject
     */
    public function getConfig()
    {
        return $this->config;
    }
}
