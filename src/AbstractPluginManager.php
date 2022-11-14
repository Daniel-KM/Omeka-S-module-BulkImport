<?php declare(strict_types=1);

namespace BulkImport;

use BulkImport\Traits\ServiceLocatorAwareTrait;
use Laminas\ServiceManager\ServiceLocatorInterface;

/**
 * @todo Convert into a standard factory. But without load at init or bootstrap, because it's rarely used.
 */
abstract class AbstractPluginManager
{
    use ServiceLocatorAwareTrait;

    /**
     * @var array
     */
    protected $registeredNames;

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
        if (is_array($this->plugins)) {
            return $this->plugins;
        }

        $this->plugins = [];

        $this->getRegisteredNames();
        $services = $this->getServiceLocator();
        foreach ($this->registeredNames as $name => $class) {
            $this->plugins[$name] = new $class($services);
        }

        return $this->plugins;
    }

    public function has($name)
    {
        $this->getRegisteredNames();
        return isset($this->registeredNames[$name]);
    }

    public function get($name)
    {
        $this->getRegisteredNames();
        if (isset($this->registeredNames[$name])) {
            $class = $this->registeredNames[$name];
            return new $class($this->getServiceLocator());
        }
        return null;
    }

    public function getRegisteredNames(): array
    {
        if (is_array($this->registeredNames)) {
            return array_keys($this->registeredNames);
        }

        $this->registeredNames = [];

        $services = $this->getServiceLocator();
        $items = $services->get('Config')['bulk_import'][$this->getName()];
        $interface = $this->getInterface();
        foreach ($items as $name => $class) {
            if (class_exists($class) && in_array($interface, class_implements($class))) {
                $this->registeredNames[$name] = $class;
            }
        }

        return array_keys($this->registeredNames);
    }

    public function getRegisteredLabels(): array
    {
        $labels = [];
        foreach ($this->getPlugins() as $key => $reader) {
            $labels[$key] = $reader->getLabel();
        }
        return $labels;
    }
}
