<?php declare(strict_types=1);
namespace BulkImport\Reader;

use BulkImport\Interfaces\Configurable;
use BulkImport\Interfaces\Parametrizable;
use BulkImport\Interfaces\Reader;
use BulkImport\Traits\ConfigurableTrait;
use BulkImport\Traits\ParametrizableTrait;
use BulkImport\Traits\ServiceLocatorAwareTrait;
use Laminas\Form\Form;
use Laminas\ServiceManager\ServiceLocatorInterface;

abstract class AbstractReader implements Reader, Configurable, Parametrizable
{
    // TODO Remove these traits so sub reader won't be all configurable.
    use ConfigurableTrait, ParametrizableTrait, ServiceLocatorAwareTrait;

    /**
     * @var string
     */
    protected $label;

    /**
     * @var array
     */
    protected $availableFields = [];

    /**
     * @var string
     */
    protected $objectType;

    /**
     * @var string|null
     */
    protected $lastErrorMessage;

    /**
     * @var string
     */
    protected $configFormClass;

    /**
     * @var string
     */
    protected $paramsFormClass;

    /**
     * var array
     */
    protected $configKeys = [];

    /**
     * var array
     */
    protected $paramsKeys = [];

    /**
     * @var bool
     */
    protected $isReady;

    /**
     * Reader constructor.
     *
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function __construct(ServiceLocatorInterface $services)
    {
        $this->setServiceLocator($services);
    }

    public function getLabel()
    {
        return $this->label;
    }

    public function isValid(): bool
    {
        return true;
    }

    public function getLastErrorMessage()
    {
        return $this->lastErrorMessage;
    }

    public function getAvailableFields()
    {
        $this->isReady();
        return $this->availableFields;
    }

    public function getConfigFormClass()
    {
        return $this->configFormClass;
    }

    public function handleConfigForm(Form $form)
    {
        $values = $form->getData();
        $config = array_intersect_key($values, array_flip($this->configKeys));
        $this->setConfig($config);
        $this->reset();
        return $this;
    }

    public function getParamsFormClass()
    {
        return $this->paramsFormClass;
    }

    public function handleParamsForm(Form $form)
    {
        $values = $form->getData();
        $params = array_intersect_key($values, array_flip($this->paramsKeys));
        $this->setParams($params);
        $this->reset();
        return $this;
    }

    public function setObjectType($objectType)
    {
        $this->objectType = $objectType;
        return $this;
    }

    /**
     * Check if the reader is ready, or prepare it.
     *
     * @return bool
     */
    protected function isReady()
    {
        if ($this->isReady) {
            return true;
        }

        $this->prepareIterator();
        return $this->isReady;
    }

    /**
     * Reset the iterator to allow to use it with different params.
     */
    protected function reset()
    {
        $this->availableFields = [];
        $this->objectType = null;
        $this->lastErrorMessage = null;
        $this->isReady = false;
        return $this;
    }
    /**
     * @throws \Omeka\Service\Exception\RuntimeException
     */
    protected function prepareIterator()
    {
        $this->reset();
        if (!$this->isValid()) {
            throw new \Omeka\Service\Exception\RuntimeException($this->getLastErrorMessage());
        }

        $this->isReady = true;
        return $this;
    }
}
