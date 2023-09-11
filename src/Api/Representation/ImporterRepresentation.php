<?php declare(strict_types=1);

namespace BulkImport\Api\Representation;

use BulkImport\Interfaces\Configurable;
use BulkImport\Processor\Manager as ProcessorManager;
use BulkImport\Reader\Manager as ReaderManager;
use Omeka\Api\Representation\AbstractEntityRepresentation;

class ImporterRepresentation extends AbstractEntityRepresentation
{
    /**
     * @var ReaderManager
     */
    protected $readerManager;

    /**
     * @var ProcessorManager
     */
    protected $processorManager;

    /**
     * @var \BulkImport\Reader\Reader
     */
    protected $reader;

    /**
     * @var \BulkImport\Processor\Processor
     */
    protected $processor;

    /**
     * @var array|null|false
     */
    protected $metaMapperMapping = false;

    public function getControllerName()
    {
        return 'importer';
    }

    public function getJsonLd()
    {
        $owner = $this->owner();

        return [
            'o:id' => $this->id(),
            'o:owner' => $owner ? $owner->getReference() : null,
            'o:label' => $this->label(),
            'o-bulk:reader' => $this->readerClass(),
            'o-bulk:mapper' => $this->mapper(),
            'o-bulk:processor' => $this->processorClass(),
            'o:config' => $this->config(),
        ];
    }

    public function getJsonLdType()
    {
        return 'o-bulk:Importer';
    }

    public function getResource(): \BulkImport\Entity\Importer
    {
        return $this->resource;
    }

    /**
     * Get the owner of this importer.
     */
    public function owner(): ?\Omeka\Api\Representation\UserRepresentation
    {
        $owner = $this->resource->getOwner();
        return $owner
            ? $this->getAdapter('users')->getRepresentation($owner)
            : null;
    }

    public function label(): ?string
    {
        return $this->resource->getLabel();
    }

    public function config(): array
    {
        return $this->resource->getConfig();
    }

    public function readerClass(): string
    {
        return $this->resource->getReader();
    }

    public function mapper(): ?string
    {
        return $this->resource->getMapper();
    }

    public function processorClass(): string
    {
        return $this->resource->getProcessor();
    }

    public function readerConfig(): array
    {
        $conf = $this->config();
        return $conf['reader'] ?? [];
    }

    public function mapperConfig(): array
    {
        $conf = $this->config();
        return $conf['mapper'] ?? [];
    }

    public function processorConfig(): array
    {
        $conf = $this->config();
        return $conf['processor'] ?? [];
    }

    public function reader(): ?\BulkImport\Reader\Reader
    {
        if ($this->reader) {
            return $this->reader;
        }

        $readerClass = $this->readerClass();
        $readerManager = $this->getReaderManager();
        if ($readerManager->has($readerClass)) {
            $this->reader = $readerManager->get($readerClass);
            if ($this->reader instanceof Configurable) {
                $config = $this->readerConfig();
                $this->reader->setConfig($config);
            }
        }

        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $this->reader->setLogger($logger);

        return $this->reader;
    }

    public function mapping(): ?array
    {
        if ($this->metaMapperMapping !== false) {
            return $this->metaMapperMapping;
        }

        $mapper = $this->mapper();
        if (in_array((string) $mapper, ['', 'automatic', 'manual'])) {
            $this->metaMapperMapping = null;
        } else {
            /** @var \BulkImport\Stdlib\MetaMapperConfig $metaMapperConfig */
            $metaMapperConfig = $this->getServiceLocator()->get('Bulk\MetaMapperConfig');
            $this->metaMapperMapping = $metaMapperConfig($mapper, $mapper);
        }

        return $this->metaMapperMapping;
    }

    public function processor(): ?\BulkImport\Processor\Processor
    {
        if ($this->processor) {
            return $this->processor;
        }

        $processorClass = $this->processorClass();
        $processorManager = $this->getProcessorManager();
        if ($processorManager->has($processorClass)) {
            $this->processor = $processorManager->get($processorClass);
            if ($this->processor instanceof Configurable) {
                $config = $this->processorConfig();
                $this->processor->setConfig($config);
            }
        }

        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $this->processor->setLogger($logger);

        return $this->processor;
    }

    public function adminUrl($action = null, $canonical = false)
    {
        $url = $this->getViewHelper('Url');
        return $url(
            'admin/bulk/id',
            [
                'controller' => $this->getControllerName(),
                'action' => $action,
                'id' => $this->id(),
            ],
            ['force_canonical' => $canonical]
        );
    }

    protected function getReaderManager(): ?ReaderManager
    {
        if (!$this->readerManager) {
            $this->readerManager = $this->getServiceLocator()->get(ReaderManager::class);
        }
        return $this->readerManager;
    }

    protected function getProcessorManager(): ?ProcessorManager
    {
        if (!$this->processorManager) {
            $this->processorManager = $this->getServiceLocator()->get(ProcessorManager::class);
        }
        return $this->processorManager;
    }
}
