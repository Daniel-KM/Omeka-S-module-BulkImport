<?php declare(strict_types=1);

namespace BulkImport\Processor;

trait CountEntitiesTrait
{
    /**
     * @param iterable $entities
     * @param string $resourceType
     */
    protected function countEntities(iterable $entities, string $resourceType): void
    {
        $this->totals[$resourceType] = is_array($entities) ? count($entities) : $entities->count();
        if ($this->totals[$resourceType] > 10000000) {
            $this->hasError = true;
            $this->logger->err(
                'Resource "{type}" has too much records ({total}).', // @translate
                ['type' => $resourceType, 'total' => $this->totals[$resourceType]]
            );
        }
    }
}
