<?php declare(strict_types=1);

namespace BulkImport\Processor;

use Statistics\Entity\Hit;

/**
 * @todo Manage a direct sql query because hits are often very numerous.
 */
trait StatisticsTrait
{
    protected function prepareHits(): void
    {
        $this->prepareHitsProcess($this->prepareReader('hits'));
    }

    protected function prepareHitsProcess(iterable $sources): void
    {
        // Normally already checked in AbstractFullProcessor.
        if (empty($this->mapping['hits']['source'])) {
            return;
        }

        $keySourceId = $this->mapping['hits']['key_id'] ?? 'id';

        // The maps is done early in order to keep original ids when possible.
        $this->map['hits'] = [];

        // TODO Add a way to fetch source ids only (in createEmptyEntity() in fact).
        foreach ($sources as $source) {
            $this->map['hits'][$source[$keySourceId]] = null;
        }

        $hitColumns = [
            'id' => 'id',
            'url' => 'id',
            'entity_id' => '0',
            'entity_name' => '""',
            'user_id' => '0',
            'ip' => '""',
            'query' => '""',
            'referrer' => '""',
            'user_agent' => '""',
            'accept_language' => '""',
            'created' => '"' . $this->currentDateTimeFormatted . '"',
        ];
        $this->createEmptyEntities('hits', $hitColumns);
        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->refreshMainResources();
    }

    protected function fillHits(): void
    {
        if ($this->mapping['hits']['mode'] === 'sql') {
            $this->logger->err(
                'To import "{source}" with mode "sql", the sql requests or the mapping should be defined.',  // @translate
                ['source' => 'statistics']
            );
            return;
        }

        $this->fillHitsProcess($this->prepareReader('hits'));
    }

    protected function fillHitsProcess(iterable $sources): void
    {
        $keySourceId = $this->mapping['hits']['key_id'] ?? 'id';

        $index = 0;
        $created = 0;
        $skipped = 0;
        foreach ($sources as $source) {
            ++$index;

            $source = array_map('trim', array_map('strval', $source));
            $sourceId = $source[$keySourceId];

            // Normally, it should be a useless check, unless the source base
            // was modified during process.
            if (empty($this->map['hits'][$sourceId])) {
                $this->hasError = true;
                $this->logger->err(
                    'The hit #{index} has an error: missing data.', // @translate
                    ['index' => $source[$keySourceId]]
                );
                return;
            }

            $hit = [
                // Keep the source id to simplify next steps and find mapped id.
                // The source id may be updated if duplicate.
                '_source_id' => $sourceId,
                'o:id' => $this->map['hits'][$sourceId],
                'o:url' => '',
                'o:entity_id' => 0,
                'o:entity_name' => '',
                'o:user_id' => 0,
                'o:ip' => '',
                'o:referrer' => '',
                'o:query' => '',
                'o:user_agent' => '',
                'o:accept_language' => '',
                'o:created' => ['@value' => $this->currentDateTimeFormatted],
            ];

            $hit = $this->fillHit($source, $hit);

            $result = $this->updateOrCreateHit($hit);

            // Prepare the fix for the dates.
            $result
                ? ++$created
                : ++$skipped;

            if ($created % self::CHUNK_ENTITIES === 0) {
                if ($this->isErrorOrStop()) {
                    break;
                }
                // Flush created or updated entities and fix dates.
                $this->entityManager->flush();
                $this->entityManager->clear();
                $this->refreshMainResources();
                $this->logger->notice(
                    '{count}/{total} resource "{type}" imported, {skipped} skipped.', // @translate
                    ['count' => $created, 'total' => count($this->map['hits']), 'type' => 'hits', 'skipped' => $skipped]
                );
            }
        }

        // Remaining entities and fix for dates.
        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->refreshMainResources();
    }

    /**
     * Complete a hit with specific data.
     *
     * Normally, "o:id" should not be overridden.
     *
     * @todo Use metaMapper().
     */
    protected function fillHit(array $source, array $hit): array
    {
        return $hit;
    }

    /**
     * Fill a fully checked hit with a json-ld array.
     */
    protected function updateOrCreateHit(array $hit): bool
    {
        if (empty($hit['o:id'])) {
            unset($hit['@id'], $hit['o:id']);
            $this->entity = new Hit();
        } else {
            $this->entity = $this->entityManager->find(Hit::class, $hit['o:id']);
            // It should not occur: empty entity should have been created during
            // preparation of the hit entities.
            if (!$this->entity) {
                $this->hasError = true;
                $this->logger->err(
                    'The id #{id} was provided for entity"{name}", but the entity was not created early.', // @translate
                    ['id' => $hit['o:id'], 'name' => 'hits']
                );
                return false;
            }
        }

        $hitCreated = empty($hit['o:created']['@value'])
            ? $this->currentDateTime
            : (\DateTime::createFromFormat('Y-m-d H:i:s', $hit['o:created']['@value']) ?: $this->currentDateTime);

        $this->entity
            ->setUrl($hit['url'])
            ->setEntityId((int) $hit['o:entity_id'])
            ->setEntityName($hit['o:entityName'])
            ->setUserId((int) $hit['o:user_id'])
            ->setIp($hit['o:ip'])
            ->setQuery($hit['o:query'])
            ->setReferrer($hit['o:referrer'])
            ->setUserAgent($hit['o:user_agent'])
            ->setAcceptLanguage($hit['o:accept_language'])
            ->setCreated($hitCreated);

        $this->entityManager->persist($this->entity);

        return true;
    }
}
