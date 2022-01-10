<?php declare(strict_types=1);

namespace BulkImport\Reader;

use BulkImport\Interfaces\Configurable;
use BulkImport\Interfaces\Parametrizable;
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
     * This is the base path of the files, not the base path of the url.
     *
     * @var string
     */
    protected $basePath;

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
     * @var array
     */
    protected $filters = [];

    /**
     * @var array
     */
    protected $order = [
        'by' => null,
        'dir' => 'ASC',
    ];

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
        $config = $services->get('Config');
        $this->basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
    }

    public function getLabel(): string
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

    public function getAvailableFields(): array
    {
        $this->isReady();
        return $this->availableFields;
    }

    public function getConfigFormClass(): string
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

    public function getParamsFormClass(): string
    {
        return $this->paramsFormClass;
    }

    public function handleParamsForm(Form $form)
    {
        $values = $form->getData();
        $params = array_intersect_key($values, array_flip($this->paramsKeys));
        $this->setParams($params);
        $this->appendInternalParams();
        $this->reset();
        return $this;
    }

    public function setObjectType($objectType): \BulkImport\Reader\Reader
    {
        $this->objectType = $objectType;
        return $this;
    }

    public function setFilters(?array $filters): \BulkImport\Reader\Reader
    {
        $this->filters = $filters ?? [];
        return $this;
    }

    public function setOrder(?string $by, $dir = 'ASC'): \BulkImport\Reader\Reader
    {
        $this->order = [
            'by' => $by,
            'dir' => strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC',
        ];
        return $this;
    }

    /**
     * Check if the reader is ready, or prepare it.
     */
    protected function isReady(): bool
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
    protected function reset(): \BulkImport\Reader\Reader
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
    protected function prepareIterator(): \BulkImport\Reader\Reader
    {
        $this->reset();
        if (!$this->isValid()) {
            throw new \Omeka\Service\Exception\RuntimeException((string) $this->getLastErrorMessage());
        }

        $this->isReady = true;
        return $this;
    }

    /**
     * Prepare other internal data.
     */
    protected function appendInternalParams(): \BulkImport\Reader\Reader
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $internalParams = [];
        $internalParams['iiifserver_media_api_url'] = $settings->get('iiifserver_media_api_url', '');
        if ($internalParams['iiifserver_media_api_url']
            && mb_substr($internalParams['iiifserver_media_api_url'], -1) !== '/'
        ) {
            $internalParams['iiifserver_media_api_url'] .= '/';
        }
        $this->setParams(array_merge($this->getParams() + $internalParams));
        return $this;
    }
}
