<?php declare(strict_types=1);

namespace BulkImport\Processor;

trait MappingTrait
{
    protected function fillMapping(): void
    {
        $this->fillMappingProcess([
            'mappings' => $this->prepareReader('mappings'),
            'mapping_markers' => $this->prepareReader('mapping_markers'),
        ]);
    }

    protected function fillMappingProcess(array $mappingsAndMarkers): void
    {
        $this->map['mappings'] = [];
        $this->map['mapping_markers'] = [];

        foreach ($mappingsAndMarkers as $resourceType => $iterable) {
            $this->prepareImport($resourceType);
            $class = $this->importables[$resourceType]['class'];

            $this->map[$resourceType] = [];
            $this->totals[$resourceType] = $iterable->count();

            /** @var \Omeka\Api\Adapter\AbstractResourceEntityAdapter $adapter */
            $adapter = $this->adapterManager->get($resourceType);
            $index = 0;
            $created = 0;
            $skipped = 0;
            $method = $this->importables[$resourceType]['fill'];
            foreach ($iterable as $source) {
                ++$index;

                $this->entity = new $class;

                $this->$method($source);

                $errorStore = new \Omeka\Stdlib\ErrorStore;
                $adapter->validateEntity($this->entity, $errorStore);
                if ($errorStore->hasErrors()) {
                    ++$skipped;
                    $this->logErrors($this->entity, $errorStore);
                    continue;
                }

                $this->entityManager->persist($this->entity);
                ++$created;

                if ($created % self::CHUNK_ENTITIES === 0) {
                    $this->entityManager->flush();
                    $this->entityManager->clear();
                    $this->logger->notice(
                        '{count}/{total} resource "{type}" imported, {skipped} skipped.', // @translate
                        ['count' => $created, 'total' => $this->totals[$resourceType], 'type' => $resourceType, 'skipped' => $skipped]
                    );
                }
            }

            // Remaining entities.
            $this->entityManager->flush();
            $this->entityManager->clear();
            $this->logger->notice(
                '{count}/{total} resource "{type}" imported, {skipped} skipped.', // @translate
                ['count' => $created, 'total' => count($this->map[$resourceType]), 'type' => $resourceType, 'skipped' => $skipped]
            );
        }
    }

    protected function fillMappingMapping(array $source): void
    {
        $item = $this->entityManager->find(\Omeka\Entity\Item::class, $this->map['items'][$source['o:item']['o:id']]);
        if (!$item) {
            $this->logger->warn(
                'The source item #{source_id} is not found for its mapping zone.', // @translate
                ['source_id' => $source['o:item']['o:id']]
            );
        } else {
            $this->entity->setItem($item);
        }
        $this->entity->setBounds($source['o-module-mapping:bounds']);
    }

    protected function fillMappingMarker(array $source): void
    {
        $item = $this->entityManager->find(\Omeka\Entity\Item::class, $this->map['items'][$source['o:item']['o:id']]);
        if (!$item) {
            $this->logger->warn(
                'The source item #{source_id} is not found for its mapping marker.', // @translate
                ['source_id' => $source['o:item']['o:id']]
            );
        } else {
            $this->entity->setItem($item);
        }

        if (!empty($source['o:item']['o:id'])) {
            $media = $this->entityManager->find(\Omeka\Entity\Media::class, $this->map['media'][$source['o:media']['o:id']] ?? '0');
            if (!$media) {
                $this->logger->warn(
                    'The source media #{source_id} is not found for its mapping marker.', // @translate
                    ['source_id' => $source['o:media']['o:id']]
                );
            } else {
                $this->entity->setMedia($media);
            }
        }

        $this->entity->setLat($source['o-module-mapping:lat']);
        $this->entity->setLng($source['o-module-mapping:lng']);
        if (array_key_exists('o-module-mapping:label', $source)) {
            $this->entity->setLabel($source['o-module-mapping:label']);
        }
    }
}
