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
     * @param iterable $sources Should be countable too.
     */
    protected function prepareCustomVocabsProcess(iterable $sources): void
    {
        $this->map['custom_vocabs'] = [];

        if ((is_array($sources) && !count($sources))
            || (!is_array($sources) && !$sources->count())
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
        foreach ($sources as $source) {
            ++$index;
            if (isset($customVocabs[$source['o:label']])) {
                /*
                 // Item sets are not yet imported, so no mapping for item sets for now…
                 // TODO Currently, item sets are created after, so vocab is updated later, but resource can be created empty first?
                 if (!empty($source['o:item_set']) && $source['o:item_set'] === $customVocabs[$source['o:label']]->getItemSet()) {
                 // ++$skipped;
                 // $this->map['custom_vocabs']['customvocab:' . $source['o:id']]['datatype'] = 'customvocab:' . $customVocabs[$source['o:label']]->getId();
                 // continue;
                 */
                if (empty($source['o:item_set'])
                    && !empty($source['o:terms'])
                    && $this->equalCustomVocabsTerms($source['o:terms'], $customVocabs[$source['o:label']]->getTerms())
                ) {
                    ++$skipped;
                    $this->map['custom_vocabs']['customvocab:' . $source['o:id']]['datatype'] = 'customvocab:' . $customVocabs[$source['o:label']]->getId();
                    continue;
                } else {
                    $label = $source['o:label'];
                    $source['o:label'] .= ' [' . $this->currentDateTime->format('Ymd-His')
                        . ' ' . substr(bin2hex(\Laminas\Math\Rand::getBytes(20)), 0, 3) . ']';
                    $this->logger->notice(
                        'Custom vocab "{old_label}" has been renamed to "{label}".', // @translate
                        ['old_label' => $label, 'label' => $source['o:label']]
                    );
                }
            }

            $sourceId = $source['o:id'];
            $sourceItemSet = empty($source['o:item_set']) ? null : $source['o:item_set'];
            $source['o:item_set'] = null;
            $source['o:terms'] = !strlen(trim((string) $source['o:terms'])) ? null : $source['o:terms'];

            // Some custom vocabs from old versions can be empty.
            // They are created with a false term and updated later.
            $isEmpty = is_null($source['o:item_set']) && is_null($source['o:terms']);
            if ($isEmpty) {
                $source['o:terms'] = 'Added by Bulk Import. To be removed.';
            }

            unset($source['@id'], $source['o:id']);
            $source['o:owner'] = $this->userOIdOrDefaultOwner($source['o:owner']);
            // TODO Use orm.
            $response = $this->api()->create('custom_vocabs', $source);
            if (!$response) {
                $this->hasError = true;
                $this->logger->err(
                    'Unable to create custom vocab "{label}".', // @translate
                    ['label' => $source['o:label']]
                );
                return;
            }
            $this->logger->notice(
                'Custom vocab {label} has been created.', // @translate
                ['label' => $source['o:label']]
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
