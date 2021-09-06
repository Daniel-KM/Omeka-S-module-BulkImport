<?php declare(strict_types=1);

namespace BulkImport\Processor;

/**
 * @see Thesaurus
 */
trait ThesaurusTrait
{
    /**
     * @var array
     */
    protected $mainThesaurus = [
        'concept' => [
            'template' => 'Thesaurus Concept',
            'class' => 'skos:Concept',
            'item_set' => null,
        ],
        'scheme' => [
            'template' => 'Thesaurus Scheme',
            'class' => 'skos:ConceptScheme',
            'item' => null,
            // Useless?
            'custom_vocab' => null,
        ],
    ];

    // See Spip for an example.
    /**
     * @var array
     */
    /*
    protected $mapping = [
        'concepts' => [
            'source' => 'concept',
            'key_id' => 'id',
            'key_parent_id' => 'parent_id',
            'key_label' => 'label',
            'narrowers_sort' => '',
        ],
    ];
    */

    /**
     * @var array
     */
    protected $thesaurus = [
        'tops' => [],
        'broaders' => [],
        'narrowers' => [],
    ];

    protected function prepareConcepts(iterable $sources): void
    {
        // See prepareAssets() or prepareResources().

        // The concepts are not mixed with items.
        $this->map['concepts'] = [];

        $keyId = $this->mapping['concepts']['key_id'];
        $keyParentId = $this->mapping['concepts']['key_parent_id'];
        $keyLabel = $this->mapping['concepts']['key_label'];
        $sourceResourceType = 'concepts';

        // Check the size of the import.
        $this->countEntities($sources, 'concepts');
        if ($this->hasError) {
            return;
        }

        $this->logger->notice(
            'Preparation of a thesaurus scheme with {total} concepts.', // @translate
            ['total' => $this->totals['concepts']]
        );

        $this->prepareItemSetAndCustomVocabForThesaurus();

        // Prepare the list of all ids.
        // Add the item for the scheme first.
        $this->map['concepts'][0] = null;

        // Prepare the structure of the thesaurus during this first loop.
        $this->thesaurus['tops'] = [];
        $this->thesaurus['parents'] = [];
        $this->thesaurus['narrowers'] = [];
        foreach ($sources as $source) {
            $id = (int) $source[$keyId];
            $this->map[$sourceResourceType][$id] = null;
            $parentId = (int) $source[$keyParentId];
            $label = sprintf('%s <%s>', $source[$keyLabel], $id);
            if ($parentId) {
                $this->thesaurus['parents'][$id] = $parentId;
                $this->thesaurus['narrowers'][$parentId][$label] = $id;
            } else {
                // Warning: all concepts without a parent are not top concepts,
                // but unstructured ones.
                $this->thesaurus['tops'][] = $id;
            }
        }

        if (!count($this->map[$sourceResourceType])) {
            $this->logger->notice(
                'No resource "{type}" available on the source.', // @translate
                ['type' => $sourceResourceType]
            );
            return;
        }

        // Remove duplicate narrowers.
        foreach ($this->thesaurus['narrowers'] as &$narrowers) {
            $narrowers = array_flip(array_flip($narrowers));
        }
        // Sort narrowers by id or label.
        if ($this->mapping['concepts']['narrowers_sort'] === 'id') {
            foreach ($this->thesaurus['narrowers'] as &$narrowers) {
                sort($narrowers);
            }
        } elseif ($this->mapping['concepts']['narrowers_sort'] === 'alpha') {
            foreach ($this->thesaurus['narrowers'] as &$narrowers) {
                $narrowers = array_flip($narrowers);
                natcasesort($narrowers);
                $narrowers = array_flip($narrowers);
            }
        }
        // Clean narrowers.
        foreach ($this->thesaurus['narrowers'] as &$narrowers) {
            $narrowers = array_values($narrowers);
        }

        $templateId = $this->main['concept']['template_id'];
        $classId = $this->main['concept']['class_id'];

        $this->createEmptyResources($sourceResourceType, $classId, $templateId);
        $this->createEmptyResourcesSpecific($sourceResourceType);

        // Update the class and template id of the scheme.
        $id = (int) $this->map[$sourceResourceType][0];
        if (!$id) {
            $this->hasError = true;
            $this->logger->err(
                 'Unable to set the class and template of the concept scheme.' // @translate
             );
            return;
        }

        // Unset the scheme from the list of concepts.
        unset($this->map[$sourceResourceType][0]);

        $sql = <<<SQL
UPDATE `resource`
SET `resource_class_id` = {$this->main['scheme']['class_id']},
    `resource_template_id` = {$this->main['scheme']['template_id']}
WHERE `id` = $id;
SQL;
        $this->connection->executeQuery($sql);

        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->refreshMainResources();

        $this->main['scheme']['item'] = $this->entityManager->find(\Omeka\Entity\Item::class, $id);
        $this->main['scheme']['item_id'] = $id;

        $name = sprintf('Thesaurus %s (%s)', $this->resourceLabel, $this->currentDateTimeFormatted); // @translate
        $this->main['scheme']['item']->setTitle($name);
        $this->main['scheme']['item']->isPublic(true);
        $this->appendValue([
            'term' => 'skos:prefLabel',
            'value' => $name,
        ], $this->main['scheme']['item']);

        // Set top themes.
        foreach ($this->thesaurus['tops'] as $value) {
            $linked = $this->entityManager->find(\Omeka\Entity\Item::class, $this->map['concepts'][$value]);
            $this->appendValue([
                'term' => 'skos:hasTopConcept',
                'type' => 'resource:item',
                'value_resource' => $linked,
            ], $this->main['scheme']['item']);
        }

        $this->logger->notice(
             '{total} resources "{type}" have been created inside concept scheme #{item_id}.', // @translate
            ['total' => count($this->map[$sourceResourceType]), 'type' => $sourceResourceType, 'item_id' => $this->main['scheme']['item_id']]
         );
    }

    protected function prepareItemSetAndCustomVocabForThesaurus(string $name = ''): void
    {
        if (!$name) {
            $name = sprintf('Thesaurus %s (%s)', $this->resourceLabel, $this->currentDateTimeFormatted); // @translate
        }

        $itemSet = new \Omeka\Entity\ItemSet;
        $itemSet->setOwner($this->owner);
        $itemSet->setTitle($name); // @translate
        $itemSet->setCreated($this->currentDateTime);
        $itemSet->setIsOpen(true);
        $this->appendValue([
            'term' => 'dcterms:title',
            'value' => $name,
        ], $itemSet);

        $customVocab = new \CustomVocab\Entity\CustomVocab();
        $customVocab->setOwner($this->owner);
        $customVocab->setItemSet($itemSet);
        $customVocab->setLabel($name);

        $this->entityManager->persist($itemSet);
        $this->entityManager->persist($customVocab);
        $this->entityManager->flush();

        $itemSet = $this->entityManager->getRepository(\Omeka\Entity\ItemSet::class)->findOneBy(['title' => $name]);
        $customVocab = $this->entityManager->getRepository(\CustomVocab\Entity\CustomVocab::class)->findOneBy(['label' => $name]);

        $this->main['concept']['item_set'] = $itemSet;
        $this->main['concept']['item_set_id'] = $itemSet->getId();
        $this->main['scheme']['custom_vocab'] = $customVocab;
        $this->main['scheme']['custom_vocab_id'] = $customVocab->getId();
    }

    protected function fillConcepts(): void
    {
        $this->fillResources($this->prepareReader('concepts'), 'concepts');
    }

    protected function fillConcept(array $source): void
    {
        $this->fillConceptProcess($source);
    }

    protected function fillConceptProcess(array $source): void
    {
        $keyId = $this->mapping['concepts']['key_id'];
        $keyParentId = $this->mapping['concepts']['key_parent_id'];
        $keyLabel = $this->mapping['concepts']['key_label'];
        $keyDefinition = $this->mapping['concepts']['key_definition'];
        $keyScopeNote = $this->mapping['concepts']['key_scope_note'];
        $keyCreated = $this->mapping['concepts']['key_created'];
        $keyModified = $this->mapping['concepts']['key_modified'];

        // Le titre est nécessaire, mais parfois vide dans Spip.
        if (!mb_strlen($source[$keyLabel])) {
            $source[$keyLabel] = sprintf($this->translator->translate('[Untitled concept #%s]'), $source[$keyId]); // @translate
        }

        $createdDate = $this->getSqlDateTime($source[$keyCreated] ?? null) ?? $this->currentDateTime;
        $modifiedDate = $this->getSqlDateTime($source[$keyModified] ?? null);

        // Omeka entities are not fluid.
        /* @var \Omeka\Entity\Item */
        $this->entity->setOwner($this->owner);
        $this->entity->setResourceClass($this->main['concept']['class']);
        $this->entity->setResourceTemplate($this->main['concept']['template']);
        $this->entity->setTitle($source[$keyLabel]);
        $this->entity->setCreated($createdDate);
        if ($modifiedDate) {
            $this->entity->setModified($modifiedDate);
        }

        $itemSets = $this->entity->getItemSets();
        $itemSets->add($this->main['concept']['item_set']);

        $values = [];

        $fromTo = [
            $keyLabel => 'skos:prefLabel',
            $keyDefinition => 'skos:definition',
            $keyScopeNote => 'skos:scopeNote',
        ];
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
            'value_resource' => $this->main['scheme']['item'],
        ];

        if ($keyParentId && $source['id_parent']) {
            $linked = $this->entityManager->find(\Omeka\Entity\Item::class, $this->map['concepts'][$source[$keyParentId]]);
            if ($linked) {
                $values[] = [
                    'term' => 'skos:broader',
                    'type' => 'resource:item',
                    'value_resource' => $linked,
                ];
            } else {
                $this->logger->warn(
                    'The broader concept #{identifier} of items #{item_id} (source {source}) was not found.', // @translate
                    ['identifier' => $source[$keyParentId], 'item_id' => $this->entity->getId(), 'source' => $source[$keyId]]
                );
            }
        } else {
            $values[] = [
                'term' => 'skos:topConceptOf',
                'type' => 'resource:item',
                'value_resource' => $this->main['scheme']['item'],
            ];
        }

        if (!empty($this->thesaurus['narrowers'][$source[$keyId]])) {
            foreach ($this->thesaurus['narrowers'][$source[$keyId]] as $value) {
                if (empty($this->map['concepts'][$value])) {
                    $values[] = [
                        'term' => 'skos:narrower',
                        'value' => $value,
                    ];
                } else {
                    $linked = $this->entityManager->find(\Omeka\Entity\Item::class, $this->map['concepts'][$value]);
                    $values[] = [
                        'term' => 'skos:narrower',
                        'type' => 'resource:item',
                        'value_resource' => $linked,
                    ];
                }
            }
        }

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

    protected function toArrayValue($value): array
    {
        return [$value];
    }
}
