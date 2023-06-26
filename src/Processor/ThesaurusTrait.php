<?php declare(strict_types=1);

namespace BulkImport\Processor;

/**
 * @see Thesaurus
 *
 * @todo Better management of multiple thesaurus (temporary storage for now).
 */
trait ThesaurusTrait
{
    /**
     * Name of the current config of the thesaurus.
     *
     * In case of multiple thesaurus, it should be updated just before and just
     * after each step.
     */
    protected $configThesaurus = 'concepts';

    /**
     * @var array
     */
    protected $thesaurusConfigs = [
        'concepts' => [
            // If resources are already created.
            'resources_ready' => [
                'scheme' => null,
                'item_set' => null,
                'custom_vocab' => null,
            ],
            // New thesaurus.
            'label' => 'Thesaurus',
            // TODO Fix singular/plural for internal thesaurus data.
            // The mapping source, main name and the source are the same, but in
            // some cases, one is plural and the other one is singular.
            'mapping_source' => 'concepts',
            'main_name' => 'concept',
            // Data from the source.
            'source' => 'concept',
            'key_id' => 'id',
            'key_parent_id' => null,
            'key_label' => null,
            'key_definition' => null,
            'key_scope_note' => null,
            'key_created' => null,
            'key_modified' => null,
            'narrowers_sort' => null,
        ],
    ];

    /**
     * Storage (tops, broaders, narrowers) of each thesaurus.
     *
     * The scheme, item set, and custom vocab are stored in main for refreshing.
     *
     * @var array
     */
    protected $thesaurus = [];

    /**
     * Create the scheme, the item set and the custom vocab for a thesaurus.
     */
    protected function prepareThesaurus(): void
    {
        $config = $this->thesaurusConfigs[$this->configThesaurus];
        $mappingName = $config['mapping_source'];
        $mainName = $config['main_name'];

        $name = $config['label'] ?: sprintf('Thesaurus %s (%s)', $this->resourceLabel, $this->currentDateTimeFormatted); // @translate;
        $randomName = substr(str_replace(['+', '/', '='], ['', '', ''], base64_encode(random_bytes(128))), 0, 8);

        if (!empty($config['resources_ready']['scheme'])) {
            $schemeReal = $this->entityManager->find(\Omeka\Entity\Item::class, $config['resources_ready']['scheme']);
            if (!$schemeReal) {
                $this->hasError = true;
                $this->logger->notice(
                    'Item #{id} for scheme for thesaurus {name} is missing.', // @translate
                    ['id' => $config['resources_ready']['scheme'], 'name' => $name]
                );
                return;
            }
        }

        if (!empty($config['resources_ready']['item_set'])) {
            $itemSetReal = $this->entityManager->find(\Omeka\Entity\ItemSet::class, $config['resources_ready']['item_set']);
            if (!$itemSetReal) {
                $this->hasError = true;
                $this->logger->notice(
                    'Item set #{id} for thesaurus {name} is missing.', // @translate
                    ['id' => $config['resources_ready']['item_set'], 'name' => $name]
                );
                return;
            }
        }

        if (!empty($config['resources_ready']['custom_vocab'])) {
            $customVocabReal = $this->entityManager->find(\CustomVocab\Entity\CustomVocab::class, $config['resources_ready']['custom_vocab']);
            if (!$customVocabReal) {
                $this->hasError = true;
                $this->logger->notice(
                    'Custom vocab #{id} for thesaurus {name} is missing.', // @translate
                    ['id' => $config['resources_ready']['custom_vocab'], 'name' => $name]
                );
                return;
            }
        }

        // Custom vocab requires a single name.
        $customVocab = $this->entityManager->getRepository(\CustomVocab\Entity\CustomVocab::class)->findOneBy(['label' => $name]);
        $customVocabName = $customVocab
            ? $name . ' ' . $this->currentDateTimeFormatted . ' ' . $randomName
            : $name;

        /**
         * @var \Omeka\Entity\Vocabulary $vocabulary
         * @var \Omeka\Entity\ResourceClass $classSkosConceptScheme
         * @var \Omeka\Entity\ResourceClass $classSkosCollection
         * @var \Omeka\Entity\ResourceTemplate $templateThesaurusScheme
         */
        $vocabulary = $this->entityManager->getRepository(\Omeka\Entity\Vocabulary::class)->findOneBy(['prefix' => 'skos']);
        $classSkosConceptScheme = $this->entityManager->getRepository(\Omeka\Entity\ResourceClass::class)->findOneBy(['vocabulary' => $vocabulary, 'localName' => 'ConceptScheme']);
        $classSkosCollection = $this->entityManager->getRepository(\Omeka\Entity\ResourceClass::class)->findOneBy(['vocabulary' => $vocabulary, 'localName' => 'Collection']);
        $templateThesaurusScheme = $this->entityManager->getRepository(\Omeka\Entity\ResourceTemplate::class)->findOneBy(['label' => 'Thesaurus Scheme']);

        $item = $schemeReal ?? new \Omeka\Entity\Item;
        $item->setOwner($this->owner);
        $item->setTitle($randomName);
        $item->setCreated($this->currentDateTime);
        $item->setIsPublic(true);
        $item->setResourceClass($classSkosConceptScheme);
        $item->setResourceTemplate($templateThesaurusScheme);
        $this->appendValue([
            'term' => 'skos:prefLabel',
            'value' => $name,
        ], $item);

        $itemSet = $itemSetReal ?? new \Omeka\Entity\ItemSet;
        $itemSet->setOwner($this->owner);
        $itemSet->setTitle($randomName);
        $itemSet->setCreated($this->currentDateTime);
        $itemSet->setIsPublic(true);
        $itemSet->setIsOpen(true);
        $itemSet->setResourceClass($classSkosCollection);
        $this->appendValue([
            'term' => 'dcterms:title',
            'value' => $name,
        ], $itemSet);

        $item->getItemSets()->add($itemSet);

        $customVocab = $customVocabReal ?? new \CustomVocab\Entity\CustomVocab();
        $customVocab->setOwner($this->owner);
        $customVocab->setLabel($randomName);
        $customVocab->setItemSet($itemSet);

        $this->entityManager->persist($item);
        $this->entityManager->persist($itemSet);
        $this->entityManager->persist($customVocab);
        $this->entityManager->flush();

        // Get the ids and set real name.

        /**
         * @var \Omeka\Entity\Item $item
         * @var \Omeka\Entity\ItemSet $itemSet
         * @var \CustomVocab\Entity\CustomVocab $customVocab
         */
        $item = $this->entityManager->getRepository(\Omeka\Entity\Item::class)->findOneBy(['title' => $randomName]);
        $itemSet = $this->entityManager->getRepository(\Omeka\Entity\ItemSet::class)->findOneBy(['title' => $randomName]);
        $customVocab = $this->entityManager->getRepository(\CustomVocab\Entity\CustomVocab::class)->findOneBy(['label' => $randomName]);

        $item->setTitle($name);
        $itemSet->setTitle($name);
        $customVocab->setLabel($customVocabName);

        $this->entityManager->persist($item);
        $this->entityManager->persist($itemSet);
        $this->entityManager->persist($customVocab);
        $this->entityManager->flush();

        $this->main[$mainName]['item'] = $item;
        $this->main[$mainName]['item_id'] = $item->getId();
        $this->main[$mainName]['item_set'] = $itemSet;
        $this->main[$mainName]['item_set_id'] = $itemSet->getId();
        $this->main[$mainName]['custom_vocab'] = $customVocab;
        $this->main[$mainName]['custom_vocab_id'] = $customVocab->getId();
        $this->refreshMainResources();

        // Prepare the structure of the thesaurus during this first loop.
        $this->map[$mappingName] = [];
        $this->thesaurus[$mappingName]['tops'] = [];
        $this->thesaurus[$mappingName]['parents'] = [];
        $this->thesaurus[$mappingName]['narrowers'] = [];
    }

    protected function prepareConcepts(iterable $sources): void
    {
        // See prepareAssets() or prepareResources().

        // The concepts are not mixed with items, but stored separately.

        $config = $this->thesaurusConfigs[$this->configThesaurus];

        $label = $config['label'];
        $mappingName = $config['mapping_source'];
        $mainName = $config['main_name'];

        if (!isset($this->map[$mappingName])) {
            $this->logger->err(
                'The thesaurus "{label}" should be prepared first.', // @translate
                ['label' => $label]
            );
        }

        $keyId = $config['key_id'];
        $keyLabel = $config['key_label'] ?? null;
        $keyParentId = $config['key_parent_id'] ?? null;

        // Check the size of the import.
        $this->countEntities($sources, $mappingName);
        if ($this->hasError) {
            return;
        }

        $this->logger->notice(
            'Preparation of thesaurus scheme "{label}" with {total} concepts.', // @translate
            ['label' => $label, 'total' => $this->totals[$mappingName]]
        );

        // Prepare the list of all ids.

        // Prepare the structure of the thesaurus during this first loop.
        foreach ($sources as $source) {
            $id = (int) $source[$keyId];
            $this->map[$mappingName][$id] = null;
            $parentId = empty($source[$keyParentId]) ? null : (int) $source[$keyParentId];
            if ($parentId) {
                // The label allows to sort alphabetically, but some sources are
                // more complex (cf. spip).
                $labelKey = $this->labelKeyForSort($source[$keyLabel] ?? '', $id);
                $this->thesaurus[$mappingName]['parents'][$id] = $parentId;
                $this->thesaurus[$mappingName]['narrowers'][$parentId][$labelKey] = $id;
            } else {
                // Warning: all concepts without a parent are not top concepts,
                // but unstructured ones.
                $this->thesaurus[$mappingName]['tops'][] = $id;
            }
        }

        if (!count($this->map[$mappingName])) {
            $this->logger->notice(
                'No resource "{type}" available on the source.', // @translate
                ['type' => $mappingName]
            );
            return;
        }

        // Remove duplicate narrowers.
        foreach ($this->thesaurus[$mappingName]['narrowers'] as &$narrowers) {
            $narrowers = array_flip(array_flip($narrowers));
        }
        unset($narrowers);
        // Sort narrowers by id or label.
        if (($config['narrowers_sort'] ?? 'id') === 'id') {
            foreach ($this->thesaurus[$mappingName]['narrowers'] as &$narrowers) {
                sort($narrowers);
            }
            unset($narrowers);
        } elseif ($config['narrowers_sort'] === 'alpha') {
            foreach ($this->thesaurus[$mappingName]['narrowers'] as &$narrowers) {
                $narrowers = array_flip($narrowers);
                natcasesort($narrowers);
                $narrowers = array_flip($narrowers);
            }
            unset($narrowers);
        } else {
            $narrowers = $this->sortNarrowers($narrowers);
        }
        // Clean narrowers.
        foreach ($this->thesaurus[$mappingName]['narrowers'] as &$narrowers) {
            $narrowers = array_values($narrowers);
        }
        unset($narrowers);

        $templateId = $this->main[$mainName]['template_id'];
        $classId = $this->main[$mainName]['class_id'];

        $resourceColumns = [
            'id' => 'id',
            'owner_id' => $this->owner ? $this->ownerId : 'NULL',
            'resource_class_id' => $classId ?: 'NULL',
            'resource_template_id' => $templateId ?: 'NULL',
            'is_public' => '0',
            'created' => '"' . $this->currentDateTimeFormatted . '"',
            'modified' => 'NULL',
            'resource_type' => $this->connection->quote($this->importables[$mappingName]['class']),
            'thumbnail_id' => 'NULL',
            'title' => 'id',
        ];
        $this->createEmptyEntities($mappingName, $resourceColumns, false, true);
        $this->createEmptyResourcesSpecific($mappingName);

        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->refreshMainResources();

        // Update scheme with top concepts.
        foreach ($this->thesaurus[$mappingName]['tops'] as $value) {
            $this->appendValue([
                'term' => 'skos:hasTopConcept',
                'type' => 'resource:item',
                'value_resource' => $this->map[$mappingName][$value],
            ], $this->main[$mainName]['item']);
        }

        $this->logger->notice(
            '{total} resources "{type}" have been created inside concept scheme #{item_id}.', // @translate
            ['total' => count($this->map[$mappingName]), 'type' => $mappingName, 'item_id' => $this->main[$mainName]['item_id']]
        );
    }

    protected function labelKeyForSort($labelKey, $id): string
    {
        return sprintf('%s#%s', $labelKey, $id);
    }

    protected function sortNarrowers(array $narrowers): array
    {
        return $narrowers;
    }

    protected function fillConcepts(): void
    {
        $this->fillResourcesProcess($this->prepareReader($this->configThesaurus), $this->configThesaurus);
    }

    protected function fillConcept(array $source): void
    {
        $this->fillConceptProcess($source);
    }

    protected function fillConceptProcess(array $source): void
    {
        $config = $this->thesaurusConfigs[$this->configThesaurus];
        $mappingName = $config['mapping_source'];
        $mainName = $config['main_name'];

        $keyId = $config['key_id'];
        $keyLabel = $config['key_label'] ?? null;
        $keyParentId = $config['key_parent_id'] ?? null;
        $keyDefinition = $config['key_definition'] ?? null;
        $keyScopeNote = $config['key_scope_note'] ?? null;
        $keyCreated = $config['key_created'] ?? null;
        $keyModified = $config['key_modified'] ?? null;

        // The title is needed, but sometime empty.
        if (!$keyLabel || !mb_strlen($source[$keyLabel])) {
            $source[$keyLabel] = sprintf($this->translator->translate('[Untitled %s #%s]'), $mainName, $source[$keyId]); // @translate
        }

        $createdDate = $keyCreated
            ? $this->getSqlDateTime($source[$keyCreated] ?? null) ?? $this->currentDateTime
            : $this->currentDateTime;
        $modifiedDate = $keyModified
            ? $this->getSqlDateTime($source[$keyModified] ?? null)
            : null;

        // Omeka entities are not fluid.
        /* @var \Omeka\Entity\Item */
        $this->entity->setOwner($this->owner);
        $this->entity->setResourceClass($this->main[$mainName]['class']);
        $this->entity->setResourceTemplate($this->main[$mainName]['template']);
        $this->entity->setTitle($source[$keyLabel]);
        $this->entity->setCreated($createdDate);
        if ($modifiedDate) {
            $this->entity->setModified($modifiedDate);
        }

        $itemSets = $this->entity->getItemSets();
        $itemSets->add($this->main[$mainName]['item_set']);

        $values = [];

        $fromTo = [
            $keyLabel => 'skos:prefLabel',
        ];
        if ($keyDefinition) {
            $fromTo[$keyDefinition] = 'skos:definition';
        }
        if ($keyScopeNote) {
            $fromTo[$keyScopeNote] = 'skos:scopeNote';
        }
        foreach ($fromTo as $sourceName => $term) {
            $value = $source[$sourceName] ?? '';
            if (strlen($value)) {
                // The texts may be stored in multiple languages in one string.
                foreach ($this->toArrayValue($value) as $lang => $value) {
                    $values[] = [
                        'term' => $term,
                        'lang' => empty($lang) ? $this->params['language'] ?? null : $lang,
                        'value' => $value,
                    ];
                }
            }
        }

        $values[] = [
            'term' => 'skos:inScheme',
            'type' => 'resource:item',
            'value_resource' => $this->main[$mainName]['item'],
        ];

        // $values is passed by reference.
        $this->fillConceptProcessParent($values, $source, $mappingName, $mainName, $keyId, $keyParentId);
        $this->fillConceptProcessNarrowers($values, $source, $mappingName, $mainName, $keyId, $keyParentId);

        if ($keyCreated && $createdDate) {
            $values[] = [
                'term' => 'dcterms:created',
                'value' => $createdDate->format('Y-m-d H:i:s'),
            ];
        }

        if ($keyModified && $modifiedDate) {
            $values[] = [
                'term' => 'dcterms:modified',
                'value' => $modifiedDate->format('Y-m-d H:i:s'),
            ];
        }

        $this->orderAndAppendValues($values);
    }

    protected function fillConceptProcessParent(array &$values, array $source, string $mappingName, string $mainName, $keyId, $keyParentId): void
    {
        if ($keyParentId && $source[$keyParentId]) {
            $linked = $this->entityManager->find(\Omeka\Entity\Item::class, $this->map[$mappingName][$source[$keyParentId]]);
            if ($linked) {
                $values[] = [
                    'term' => 'skos:broader',
                    'type' => 'resource:item',
                    'value_resource' => $linked,
                ];
            } else {
                $this->logger->warn(
                    'The broader concept #{identifier} of items #{item_id} (source {main} "{source}") was not found.', // @translate
                    ['identifier' => $source[$keyParentId], 'item_id' => $this->entity->getId(), 'main' => $mainName, 'source' => $source[$keyId]]
                );
            }
        } else {
            $values[] = [
                'term' => 'skos:topConceptOf',
                'type' => 'resource:item',
                'value_resource' => $this->main[$mainName]['item'],
            ];
        }
    }

    protected function fillConceptProcessNarrowers(array &$values, array $source, string $mappingName, string $mainName, $keyId, $keyParentId): void
    {
        if (empty($this->thesaurus[$mappingName]['narrowers'][$source[$keyId]])) {
            return;
        }
        foreach ($this->thesaurus[$mappingName]['narrowers'][$source[$keyId]] as $value) {
            // A literal value when the narrower item does not exist.
            if (empty($this->map[$mappingName][$value])) {
                $values[] = [
                    'term' => 'skos:narrower',
                    'value' => $value,
                ];
            } else {
                $linked = $this->entityManager->find(\Omeka\Entity\Item::class, $this->map[$mappingName][$value]);
                $values[] = [
                    'term' => 'skos:narrower',
                    'type' => 'resource:item',
                    'value_resource' => $linked,
                ];
            }
        }
    }

    protected function toArrayValue($value): array
    {
        return [$value];
    }
}
