<?php declare(strict_types=1);

namespace BulkImport\Reader;

use BulkImport\Entry\BaseEntry;
use BulkImport\Entry\Entry;
use BulkImport\Interfaces\Configurable;
use BulkImport\Interfaces\Parametrizable;
use BulkImport\Traits\ConfigurableTrait;
use BulkImport\Traits\ParametrizableTrait;
use BulkImport\Traits\ServiceLocatorAwareTrait;
use Laminas\Form\Form;
use Laminas\ServiceManager\ServiceLocatorInterface;

/**
 * @todo The reader itself may be an iterator or an array. Here too?
 */
abstract class AbstractReader implements Reader, Configurable, Parametrizable
{
    // TODO Remove these traits so sub reader won't be all configurable.
    use ConfigurableTrait, ParametrizableTrait, ServiceLocatorAwareTrait;

    /**
     * @var string
     */
    protected $entryClass = BaseEntry::class;

    /**
     * @var \BulkImport\Stdlib\MetaMapper|null
     */
    protected $metaMapper;

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
     * The main iterator to loop on.
     * May be \IteratorIterator, so the index may be a duplicate, so use it in
     * conjunction with the main iterator index when needed.
     *
     * @var \Iterator|\IteratorIterator
     */
    protected $iterator;

    /**
     * @var bool
     */
    protected $isReady = false;

    /**
     * For spreadsheets, the total entries should not include headers.
     *
     * @var int
     */
    protected $totalEntries;

    /**
     * @var mixed
     */
    protected $currentData;

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
        $this->metaMapper = $services->get('Bulk\MetaMapper');
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function isValid(): bool
    {
        return true;
    }

    public function getLastErrorMessage(): ?string
    {
        return (string) $this->lastErrorMessage;
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

    public function handleConfigForm(Form $form): self
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

    public function handleParamsForm(Form $form): self
    {
        $values = $form->getData();
        $params = array_intersect_key($values, array_flip($this->paramsKeys));
        $this->setParams($params);
        $this->appendInternalParams();
        $this->reset();
        return $this;
    }

    public function setObjectType($objectType): self
    {
        $this->objectType = $objectType;
        return $this;
    }

    public function setFilters(?array $filters): self
    {
        $this->filters = $filters ?? [];
        return $this;
    }

    public function setOrders($by, $dir = 'ASC'): self
    {
        $this->orders = [];
        if (!$by) {
            // Nothing.
        } elseif (is_string($by)) {
            $this->orders[] = [
                'by' => $by,
                'dir' => strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC',
            ];
        } elseif (is_array($by)) {
            foreach ($by as $byElement) {
                $this->orders[] = [
                    'by' => $byElement['by'],
                    'dir' => !empty($byElement['dir']) && strtoupper($byElement['dir']) === 'DESC' ? 'DESC' : 'ASC',
                ];
            }
        }
        return $this;
    }

    #[\ReturnTypeWillChange]
    public function current()
    {
        $this->isReady();
        $this->currentData = $this->iterator->current();
        return $this->currentData
            ? $this->currentEntry()
            : null;
    }

    #[\ReturnTypeWillChange]
    public function key()
    {
        $this->isReady();
        return $this->iterator->key();
    }

    public function next(): void
    {
        $this->isReady();
        $this->iterator->next();
    }

    public function rewind(): void
    {
        $this->isReady();
        $this->iterator->rewind();
    }

    public function valid(): bool
    {
        $this->isReady();
        return $this->iterator->valid();
    }

    public function count(): int
    {
        $this->isReady();
        if (is_null($this->totalEntries)) {
            $this->totalEntries = method_exists($this->iterator, 'count')
                ? $this->iterator->count()
                : iterator_count($this->iterator);
        }
        return (int) $this->totalEntries;
    }

    /**
     * Get the current data mapped as an Entry, by default for an array.
     */
    protected function currentEntry(): Entry
    {
        $class = $this->entryClass;
        return new $class(
            $this->currentData,
            $this->key(),
            $this->availableFields,
            $this->getParams() + ['metaMapper' => $this->metaMapper]
        );
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
    protected function reset(): self
    {
        $this->availableFields = [];
        $this->objectType = null;
        $this->lastErrorMessage = null;
        $this->isReady = false;
        $this->iterator = null;
        $this->totalEntries = null;
        $this->currentData = null;
        return $this;
    }

    /**
     * @throws \Omeka\Service\Exception\RuntimeException
     */
    protected function prepareIterator(): self
    {
        // The params should be checked and valid.
        $this->reset();
        if (!$this->isValid()) {
            throw new \Omeka\Service\Exception\RuntimeException((string) $this->getLastErrorMessage());
        }

        $this->initializeReader();
        $this->finalizePrepareIterator();
        $this->prepareAvailableFields();

        $this->isReady = true;
        return $this;
    }

    /**
     * Initialize the reader iterator.
     */
    abstract protected function initializeReader(): self;

    /**
     * Called only by prepareIterator() after opening reader.
     */
    protected function finalizePrepareIterator(): self
    {
        $this->totalEntries = iterator_count($this->iterator);
        $this->iterator->rewind();
        return $this;
    }

    /**
     * The list of available fields are an array.
     */
    protected function prepareAvailableFields(): self
    {
        return $this;
    }
    /**
     * Prepare other internal data.
     */
    protected function appendInternalParams(): self
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

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
