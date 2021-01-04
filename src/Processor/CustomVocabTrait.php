<?php declare(strict_types=1);

namespace BulkImport\Processor;

trait CustomVocabTrait
{
    /**
     * Create custom vocabs from a source.
     */
    protected function prepareCustomVocabsInitialize(): void
    {
    }

    /**
     * @param iterable $sourceCustomVocabs Should be countable too.
     */
    protected function prepareCustomVocabsProcess(iterable $sourceCustomVocabs): void
    {
        $this->map['custom_vocabs'] = [];

        if ((is_array($sourceCustomVocabs) && !count($sourceCustomVocabs))
            || (!is_array($sourceCustomVocabs) && !$sourceCustomVocabs->count())
        ) {
            $this->logger->notice(
                'No custom vocabs importable from source.' // @translate
            );
            return;
        }

        $result = $this->api()
            ->search('custom_vocabs', [], ['responseContent' => 'resource'])->getContent();

        $customVocabs = [];
        foreach ($result as $customVocab) {
            $customVocabs[$customVocab->getLabel()] = $customVocab;
        }
        unset($result);

        $index = 0;
        $created = 0;
        $skipped = 0;
        foreach ($sourceCustomVocabs as $sourceCustomVocab) {
            ++$index;
            if (isset($customVocabs[$sourceCustomVocab['o:label']])) {
                /*
                 // Item sets are not yet imported, so no mapping for item sets for now…
                 // TODO Currently, item sets are created after, so vocab is updated later, but resource can be created empty first?
                 if (!empty($sourceCustomVocab['o:item_set']) && $sourceCustomVocab['o:item_set'] === $customVocabs[$sourceCustomVocab['o:label']]->getItemSet()) {
                 // ++$skipped;
                 // $this->map['custom_vocabs']['customvocab:' . $sourceCustomVocab['o:id']]['datatype'] = 'customvocab:' . $customVocabs[$sourceCustomVocab['o:label']]->getId();
                 // continue;
                 */
                if (empty($sourceCustomVocab['o:item_set'])
                    && !empty($sourceCustomVocab['o:terms'])
                    && $this->equalCustomVocabsTerms($sourceCustomVocab['o:terms'], $customVocabs[$sourceCustomVocab['o:label']]->getTerms())
                ) {
                    ++$skipped;
                    $this->map['custom_vocabs']['customvocab:' . $sourceCustomVocab['o:id']]['datatype'] = 'customvocab:' . $customVocabs[$sourceCustomVocab['o:label']]->getId();
                    continue;
                } else {
                    $label = $sourceCustomVocab['o:label'];
                    $sourceCustomVocab['o:label'] .= ' [' . $this->currentDateTime->format('Ymd-His')
                        . ' ' . substr(bin2hex(\Laminas\Math\Rand::getBytes(20)), 0, 3) . ']';
                    $this->logger->notice(
                        'Custom vocab "{old_label}" has been renamed to "{label}".', // @translate
                        ['old_label' => $label, 'label' => $sourceCustomVocab['o:label']]
                    );
                }
            }

            $sourceId = $sourceCustomVocab['o:id'];
            $sourceItemSet = empty($sourceCustomVocab['o:item_set']) ? null : $sourceCustomVocab['o:item_set'];
            $sourceCustomVocab['o:item_set'] = null;
            $sourceCustomVocab['o:terms'] = !strlen(trim((string) $sourceCustomVocab['o:terms'])) ? null : $sourceCustomVocab['o:terms'];

            // Some custom vocabs from old versions can be empty.
            // They are created with a false term and updated later.
            $isEmpty = is_null($sourceCustomVocab['o:item_set']) && is_null($sourceCustomVocab['o:terms']);
            if ($isEmpty) {
                $sourceCustomVocab['o:terms'] = 'Added by Bulk Import. To be removed.';
            }

            unset($sourceCustomVocab['@id'], $sourceCustomVocab['o:id']);
            $sourceCustomVocab['o:owner'] = $this->userOIdOrDefaultOwner($sourceCustomVocab['o:owner']);
            // TODO Use orm.
            $response = $this->api()->create('custom_vocabs', $sourceCustomVocab);
            if (!$response) {
                $this->hasError = true;
                $this->logger->err(
                    'Unable to create custom vocab "{label}".', // @translate
                    ['label' => $sourceCustomVocab['o:label']]
                );
                return;
            }
            $this->logger->notice(
                'Custom vocab {label} has been created.', // @translate
                ['label' => $sourceCustomVocab['o:label']]
            );
            ++$created;

            $this->map['custom_vocabs']['customvocab:' . $sourceId] = [
                'datatype' => 'customvocab:' . $response->getContent()->id(),
                'source_item_set' => $sourceItemSet,
                'is_empty' => $isEmpty,
            ];
        }

        $this->allowedDataTypes = $this->getServiceLocator()->get('Omeka\DataTypeManager')->getRegisteredNames();

        $this->logger->notice(
            '{total} custom vocabs ready, {created} created, {skipped} skipped.', // @translate
            ['total' => $index, 'created' => $created, 'skipped' => $skipped]
        );
    }

    /**
     * Finalize custom vocabs that are based on item sets.
     */
    protected function prepareCustomVocabsFinalize(): void
    {
        $api = $this->api();
        foreach ($this->map['custom_vocabs'] as &$customVocab) {
            if (empty($customVocab['source_item_set'])) {
                unset($customVocab['is_empty']);
                continue;
            }
            if (empty($this->map['item_sets'][$customVocab['source_item_set']])) {
                unset($customVocab['is_empty']);
                continue;
            }
            $id = (int) substr($customVocab['datatype'], 12);
            /** @var \CustomVocab\Api\Representation\CustomVocabRepresentation $customVocab */
            $customVocabRepr = $api->searchOne('custom_vocabs', $id)->getContent();
            if (!$customVocabRepr) {
                unset($customVocab['is_empty']);
                continue;
            }
            $data = json_decode(json_encode($customVocabRepr), true);
            $data['o:item_set'] = $this->map['item_sets'][$customVocab['source_item_set']];
            if (!empty($customVocab['is_empty'])) {
                $data['o:terms'] = null;
            }
            unset($customVocab['is_empty']);
            $api->update('custom_vocabs', $id, $data);
        }
    }

    /**
     * @param array|mixed $termsA
     * @param array|mixed $termsB
     * @return bool
     */
    protected function equalCustomVocabsTerms($termsA, $termsB): bool
    {
        $terms = [
            'a' => $termsA,
            'b' => $termsB,
        ];
        foreach ($terms  as &$termsList) {
            if (!is_array($termsList)) {
                $termsList = explode("\n", $termsList);
            }
            $termsList = array_unique(array_filter(array_map('trim', array_map('strval', $termsList)), 'strlen'));
            sort($termsList);
        }
        return $terms['a'] === $terms['b'];
    }
}
