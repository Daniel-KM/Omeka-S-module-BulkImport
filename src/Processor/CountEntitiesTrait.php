<?php declare(strict_types=1);

namespace BulkImport\Processor;

trait CountEntitiesTrait
{
    public $maxEntityToProcess = 10000000;

    protected function countEntities(iterable $sources, string $resourceName): void
    {
        $this->totals[$resourceName] = is_array($sources) ? count($sources) : $sources->count();
        if ($this->totals[$resourceName] > $this->maxEntityToProcess) {
            $this->hasError = true;
            $this->logger->err(
                'Resource "{type}" has too much records ({total}).', // @translate
                ['type' => $resourceName, 'total' => $this->totals[$resourceName]]
            );
        }
    }
}
