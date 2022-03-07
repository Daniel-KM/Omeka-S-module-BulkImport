<?php declare(strict_types=1);

namespace BulkImport\Processor;

use Omeka\Api\Exception\NotFoundException;
use Omeka\Api\Representation\VocabularyRepresentation;

trait VocabularyTrait
{
    protected function checkVocabularies(): void
    {
        // Get and check each vocabulary.
    }

    /**
     * Check if a vocabulary exists and check if different.
     *
     * @todo Remove arg $skipLog.
     *
     * @param array $vocabulary
     * @param bool $skipLog
     * @return array The status and the cleaned vocabulary.
     */
    protected function checkVocabulary(array $vocabulary, $skipLog = false)
    {
        // Check existing namespace, but avoid some issues with uri, that may
        // have a trailing "#" or "/".
        $vocabularies = $this->bulk->getVocabularyUris(true);
        $prefix = array_search(rtrim($vocabulary['o:namespace_uri'], '#/'), $vocabularies);
        if ($prefix) {
            /** @var \Omeka\Api\Representation\VocabularyRepresentation $vocabularyRepresentation */
            $vocabularyRepresentation = $this->bulk->api()
                // Api "search" uses "namespace_uri", but "read" uses "namespaceUri".
                ->read('vocabularies', ['prefix' => $prefix])->getContent();
            if ($vocabularyRepresentation->prefix() !== $vocabulary['o:prefix']) {
                if (!$skipLog) {
                    $this->logger->notice(
                        'Vocabulary "{prefix}" exists as vocabulary #{vocabulary_id}, but the prefix is not the same ("{prefix_2}").', // @translate
                        ['prefix' => $vocabularyRepresentation->prefix(), 'vocabulary_id' => $vocabularyRepresentation->id(), 'prefix_2' => $vocabulary['o:prefix']]
                    );
                }
                $vocabulary['o:prefix'] = $vocabularyRepresentation->prefix();
            }

            $this->logger->info(
                'Check properties for prefix "{prefix}".', // @translate
                 ['prefix' => $prefix]
            );
            $this->checkVocabularyProperties($vocabulary, $vocabularyRepresentation);
            if ($this->hasError) {
                return [
                     'status' => 'error',
                     'data' => [
                         'source' => $vocabulary,
                         'destination' => $vocabularyRepresentation,
                     ],
                 ];
            }

            return [
                'status' => 'success',
                'data' => [
                    'source' => $vocabulary,
                    'destination' => $vocabularyRepresentation,
                ],
            ];
        }

        try {
            /** @var \Omeka\Api\Representation\VocabularyRepresentation $vocabularyRepresentation */
            $vocabularyRepresentation = $this->bulk->api()
                ->read('vocabularies', ['prefix' => $vocabulary['o:prefix']])->getContent();
            if (rtrim($vocabularyRepresentation->namespaceUri(), '#/') !== rtrim($vocabulary['o:namespace_uri'], '#/')) {
                $vocabulary['o:prefix'] .= '_' . $this->currentDateTime->format('Ymd-His');
                if (!$skipLog) {
                    $this->logger->notice(
                        'Vocabulary prefix {prefix} is used so the imported one is renamed.', // @translate
                        ['prefix' => $vocabulary['o:prefix']]
                    );
                }
            }
        } catch (NotFoundException $e) {
            // Nothing to do.
        }

        return [
            'status' => 'success',
            'data' => [
                'source' => $vocabulary,
                'destination' => null,
            ],
        ];
    }

    protected function fetchVocabularyProperties(array $vocabulary): array
    {
        return [];
    }

    protected function checkVocabularyProperties(array $vocabulary, VocabularyRepresentation $vocabularyRepresentation): bool
    {
        $vocabularyProperties = $this->fetchVocabularyProperties($vocabulary);
        sort($vocabularyProperties);

        $vocabularyRepresentationProperties = [];
        foreach ($vocabularyRepresentation->properties() as $property) {
            $vocabularyRepresentationProperties[] = $property->localName();
        }
        sort($vocabularyRepresentationProperties);

        if ($vocabularyProperties !== $vocabularyRepresentationProperties) {
            $this->logger->notice(
                'The properties are different for the {prefix}.', // @translate
                ['prefix' => $vocabulary['o:prefix']]
            );
        }

        // Whatever the result, the result is always true.
        return true;
    }

    /**
     * The vocabularies should be checked before.
     */
    protected function prepareVocabularies(): void
    {
        // Prepare list of vocabularies.
    }

    protected function prepareVocabulariesProcess(iterable $vocabularies): void
    {
        $index = 0;
        $created = 0;
        foreach ($vocabularies as $vocabulary) {
            ++$index;
            $result = $this->checkVocabulary($vocabulary, false);
            if ($result['status'] !== 'success') {
                $this->hasError = true;
                return;
            }

            if (!$result['data']['destination']) {
                $vocab = $result['data']['source'];
                unset($vocab['@id'], $vocab['o:id']);
                $vocab['o:owner'] = $this->userOIdOrDefaultOwner($vocabulary['o:owner']);
                $vocab['o:prefix'] = trim($vocab['o:prefix']);
                // TODO Use orm.
                $response = $this->bulk->api()->create('vocabularies', $vocab);
                $result['data']['destination'] = $response->getContent();
                $this->logger->notice(
                    'Vocabulary {prefix} has been created.', // @translate
                    ['prefix' => $vocab['o:prefix']]
                );
                ++$created;
            }

            // The prefix may have been changed. Keep only needed data.
            $this->map['vocabularies'][$vocabulary['o:prefix']] = [
                'source' => [
                    'id' => $vocabulary['o:id'],
                    'prefix' => $vocabulary['o:prefix'],
                ],
                'destination' => [
                    'id' => $result['data']['destination']->id(),
                    'prefix' => $result['data']['destination']->prefix(),
                ],
            ];
        }

        $this->logger->notice(
            '{total} vocabularies ready, {created} created.', // @translate
            ['total' => $index, 'created' => $created]
        );
    }

    protected function prepareProperties(): void
    {
    }

    protected function prepareResourceClasses(): void
    {
    }

    protected function prepareVocabularyMembers(iterable $sourceMembers, string $resourceName): void
    {
        $this->refreshMainResources();

        switch ($resourceName) {
            case 'properties':
                $memberIdsByTerm = $this->bulk->getPropertyIds();
                $class = \Omeka\Entity\Property::class;
                break;
            case 'resource_classes':
                $memberIdsByTerm = $this->bulk->getResourceClassIds();
                $class = \Omeka\Entity\ResourceClass::class;
                break;
            default:
                return;
        }

        /** @var \Omeka\Api\Adapter\AssetAdapter $adapter */
        $adapter = $this->adapterManager->get($resourceName);

        $this->map[$resourceName] = [];
        $this->totals[$resourceName] = is_array($sourceMembers) ? count($sourceMembers) : $sourceMembers->count();

        $index = 0;
        $existing = 0;
        $created = 0;
        $skipped = 0;
        foreach ($sourceMembers as $member) {
            ++$index;

            $sourceId = $member['o:id'];
            $sourceTerm = $member['o:term'];
            $sourcePrefix = strtok($sourceTerm, ':');
            if (!isset($this->map['vocabularies'][$sourcePrefix])) {
                ++$skipped;
                $this->logger->warn(
                    'The vocabulary of the {member} {term} does not exist.', // @translate
                    ['member' => $this->bulk->label($resourceName), 'term' => $sourceTerm]
                );
                continue;
            }

            $destTerm = $this->map['vocabularies'][$sourcePrefix]['destination']['prefix'] . ':' . $member['o:local_name'];

            $this->map[$resourceName][$sourceTerm] = [
                'term' => $destTerm,
                'source' => $sourceId,
                'id' => null,
            ];

            if (isset($memberIdsByTerm[$destTerm])) {
                ++$existing;
                $this->map[$resourceName][$sourceTerm]['id'] = $memberIdsByTerm[$destTerm];
                continue;
            }

            // The entity manager is used, because the api doesn't allow to
            // create individual vocabulary member (only as a whole with
            // vocabulary).
            /** @var \Omeka\Entity\Vocabulary $vocabulary */
            $vocabulary = $this->entityManager->find(\Omeka\Entity\Vocabulary::class, $this->map['vocabularies'][$sourcePrefix]['destination']['id']);
            if (!$vocabulary) {
                $this->logger->err(
                    'Unable to find vocabulary for {member} {term}.', // @translate
                    ['member' => $this->bulk->label($resourceName), 'term' => $member['o:term']]
                );
                $this->hasError = true;
                return;
            }

            $this->entity = new $class;
            $this->entity->setOwner($vocabulary->getOwner());
            $this->entity->setVocabulary($vocabulary);
            $this->entity->setLocalName($member['o:local_name']);
            $this->entity->setLabel($member['o:label']);
            $this->entity->setComment($member['o:comment']);

            $errorStore = new \Omeka\Stdlib\ErrorStore;
            $adapter->validateEntity($this->entity, $errorStore);
            if ($errorStore->hasErrors()) {
                $this->hasError = true;
                ++$skipped;
                $this->logger->err(
                    'Unable to create {member} {term}.', // @translate
                    ['member' => $this->bulk->label($resourceName), 'term' => $member['o:term']]
                );
                $this->logErrors($this->entity, $errorStore);
                continue;
            }

            $this->entityManager->persist($this->entity);
            ++$created;

            if ($created % self::CHUNK_ENTITIES === 0) {
                $this->entityManager->flush();
                $this->entityManager->clear();
                $this->refreshMainResources();
                $this->logger->notice(
                    '{count}/{total} vocabulary {member} imported, {existing} existing, {skipped} skipped.', // @translate
                    ['count' => $created, 'total' => $this->totals[$resourceName], 'existing' => $existing, 'member' => $this->bulk->label($resourceName), 'skipped' => $skipped]
                );
            }

            $this->logger->notice(
                'Vocabulary {member} {term} has been created.', // @translate
                ['member' => $this->bulk->label($resourceName), 'term' => $member['o:term']]
            );
            ++$created;
        }

        // Remaining entities.
        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->refreshMainResources();

        // Fill the missing new member ids.
        $api = $this->bulk->api();
        foreach ($this->map[$resourceName] as $sourceTerm => $data) {
            if ($data['id']) {
                continue;
            }
            $member = $api->searchOne($resourceName, ['term' => $data['term']])->getContent();
            if (!$member) {
                $this->hasError = true;
                $this->logger->err(
                    'Unable to find {member} {term}.', // @translate
                    ['member' => $this->bulk->label($resourceName), 'term' => $data['term']]
                );
                continue;
            }
            $this->map[$resourceName][$sourceTerm]['id'] = $member->id();
        }

        // Prepare simple maps of source id and destination id.
        $this->map['by_id'][$resourceName] = array_map('intval', array_column($this->map[$resourceName], 'id', 'source'));
    }
}
