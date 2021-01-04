<?php declare(strict_types=1);

namespace BulkImport\Processor;

trait MappingTrait
{
    protected function fillMapping(): void
    {
    }

    protected function fillMappingProcess(array $mappingsAndMarkers): void
    {
        $this->map['mappings'] = [];
        $this->map['mapping_markers'] = [];

        $classes = [
            'mappings' => \Mapping\Entity\Mapping::class,
            'mapping_markers' => \Mapping\Entity\MappingMarker::class,
        ];
        $methods = [
            'mappings' => 'fillMappingMapping',
            'mapping_markers' => 'fillMappingMarker',
        ];

        foreach ($mappingsAndMarkers as $resourceType => $iterable) {
            $class = $classes[$resourceType];

            $this->map[$resourceType] = [];
            $this->totals[$resourceType] = $iterable->count();

            /** @var \Omeka\Api\Adapter\AbstractResourceEntityAdapter $adapter */
            $adapter = $this->adapterManager->get($resourceType);
            $index = 0;
            $created = 0;
            $skipped = 0;
            $method = $methods[$resourceType];
            foreach ($iterable as $resource) {
                ++$index;

                $this->entity = new $class;

                $this->$method($resource);

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

    protected function fillMappingMapping(array $resource): void
    {
        $item = $this->entityManager->find(\Omeka\Entity\Item::class, $this->map['items'][$resource['o:item']['o:id']]);
        if (!$item) {
            $this->logger->warn(
                'The source item #{source_id} is not found for its mapping zone.', // @translate
                ['source_id' => $resource['o:item']['o:id']]
            );
        } else {
            $this->entity->setItem($item);
        }
        $this->entity->setBounds($resource['o-module-mapping:bounds']);
    }

    protected function fillMappingMarker(array $resource): void
    {
        $item = $this->entityManager->find(\Omeka\Entity\Item::class, $this->map['items'][$resource['o:item']['o:id']]);
        if (!$item) {
            $this->logger->warn(
                'The source item #{source_id} is not found for its mapping marker.', // @translate
                ['source_id' => $resource['o:item']['o:id']]
            );
        } else {
            $this->entity->setItem($item);
        }

        if (!empty($resource['o:item']['o:id'])) {
            $media = $this->entityManager->find(\Omeka\Entity\Media::class, $this->map['media'][$resource['o:media']['o:id']] ?? '0');
            if (!$media) {
                $this->logger->warn(
                    'The source media #{source_id} is not found for its mapping marker.', // @translate
                    ['source_id' => $resource['o:media']['o:id']]
                );
            } else {
                $this->entity->setMedia($media);
            }
        }

        $this->entity->setLat($resource['o-module-mapping:lat']);
        $this->entity->setLng($resource['o-module-mapping:lng']);
        if (array_key_exists('o-module-mapping:label', $resource)) {
            $this->entity->setLabel($resource['o-module-mapping:label']);
        }
    }
}
