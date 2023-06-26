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

    public function getControllerName()
    {
        return 'importer';
    }

    public function getJsonLd()
    {
        $owner = $this->owner();

        return [
            'o:id' => $this->id(),
            'o:label' => $this->label(),
            'o:config' => $this->config(),
            'o-bulk:reader_class' => $this->readerClass(),
            'o-bulk:reader_config' => $this->readerConfig(),
            'o-bulk:processor_class' => $this->processorClass(),
            'o-bulk:processor_config' => $this->processorConfig(),
            'o:owner' => $owner ? $owner->getReference() : null,
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

    public function label(): ?string
    {
        return $this->resource->getLabel();
    }

    public function config(): array
    {
        return $this->resource->getConfig() ?: [];
    }

    public function readerClass(): string
    {
        return $this->resource->getReaderClass();
    }

    public function readerConfig(): array
    {
        return $this->resource->getReaderConfig() ?: [];
    }

    public function processorClass(): string
    {
        return $this->resource->getProcessorClass();
    }

    public function processorConfig(): array
    {
        return $this->resource->getProcessorConfig() ?: [];
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

        return $this->reader;
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

        return $this->processor;
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
}
