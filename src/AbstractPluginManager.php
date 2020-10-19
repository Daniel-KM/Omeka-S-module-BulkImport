<?php declare(strict_types=1);
namespace BulkImport;

use BulkImport\Traits\ServiceLocatorAwareTrait;
use Laminas\ServiceManager\ServiceLocatorInterface;

abstract class AbstractPluginManager
{
    use ServiceLocatorAwareTrait;

    /**
     * @var array
     */
    protected $plugins;

    /**
     * @return string
     */
    abstract protected function getName();

    /**
     * @return string
     */
    abstract protected function getInterface();

    /**
     * AbstractPluginManager constructor.
     *
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function __construct(ServiceLocatorInterface $serviceLocator)
    {
        $this->setServiceLocator($serviceLocator);
    }

    public function getPlugins()
    {
        if ($this->plugins) {
            return $this->plugins;
        }

        $this->plugins = [];

        // @todo Use a standard factory? But without load at init or bootstrap, because it's rarely used.

        $services = $this->getServiceLocator();
        $name = $this->getName();
        $config = $services->get('Config');
        $interface = $this->getInterface();

        $items = $config['bulk_import'][$name];
        foreach ($items as $name => $class) {
            if (class_exists($class) && in_array($interface, class_implements($class))) {
                $this->plugins[$name] = new $class($services);
            }
        }
        return $this->plugins;
    }

    public function has($name)
    {
        $plugins = $this->getPlugins();
        return isset($plugins[$name]);
    }

    public function get($name)
    {
        $plugins = $this->getPlugins();
        return isset($plugins[$name]) ? $plugins[$name] : null;
    }
}
