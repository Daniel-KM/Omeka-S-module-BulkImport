<?php declare(strict_types=1);

namespace BulkImport\Processor;

use Omeka\Api\Representation\ResourceTemplateRepresentation;

trait ResourceTemplateTrait
{
    /**
     * Create resource templates from a source.
     */
    protected function prepareResourceTemplates(): void
    {
    }

    /**
     * @param iterable $sources Should be countable too.
     */
    protected function prepareResourceTemplatesProcess($sources): void
    {
        $this->map['resource_templates'] = [];

        if ((is_array($sources) && !count($sources))
            || (!is_array($sources) && !$sources->count())
        ) {
            $this->logger->notice(
                'No resource templates importable from source.' // @translate
            );
            return;
        }

        $resourceTemplates = $this->bulk->getResourceTemplateIds();

        $result = $this->bulk->api()
            ->search('resource_templates')->getContent();
        $rts = [];
        foreach ($result as $resourceTemplate) {
            $rts[$resourceTemplate->label()] = $resourceTemplate;
        }
        unset($result);

        $index = 0;
        $created = 0;
        $skipped = 0;
        foreach ($sources as $source) {
            ++$index;
            // Clean the metadata to simplify check and import.
            $sourceId = $source['o:id'];
            unset($source['@id'], $source['o:id']);
            $source['o:owner'] = $this->userOIdOrDefaultOwner($source['o:owner']);

            $source['o:resource_class'] = !empty($source['o:resource_class']['o:id'])
                && isset($this->map['by_id']['resource_classes'][$source['o:resource_class']['o:id']])
                ? ['o:id' => $this->map['by_id']['resource_classes'][$source['o:resource_class']['o:id']]]
                : null;
            $source['o:title_property'] = !empty($source['o:title_property']['o:id'])
                && !empty($this->map['by_id']['properties'][$source['o:title_property']['o:id']])
                ? ['o:id' => $this->map['by_id']['properties'][$source['o:title_property']['o:id']]]
                : null;
            $source['o:description_property'] = !empty($source['o:description_property']['o:id'])
                && !empty($this->map['by_id']['properties'][$source['o:description_property']['o:id']])
                ? ['o:id' => $this->map['by_id']['properties'][$source['o:description_property']['o:id']]]
                : null;
            foreach ($source['o:resource_template_property'] as &$rtProperty) {
                $rtProperty['o:property'] = !empty($rtProperty['o:property']['o:id'])
                    && !empty($this->map['by_id']['properties'][$rtProperty['o:property']['o:id']])
                    ? ['o:id' => $this->map['by_id']['properties'][$rtProperty['o:property']['o:id']]]
                    : null;
                // Convert unknown custom vocab into a literal.
                // There is only one datatype in version 2 but multiple in v3.
                if (empty($rtProperty['o:data_type'])) {
                    $rtProperty['o:data_type'] = [];
                } else {
                    if (!is_array($rtProperty['o:data_type'])) {
                        $rtProperty['o:data_type'] = [$rtProperty['o:data_type']];
                    }
                    foreach ($rtProperty['o:data_type'] as &$dataType) {
                        if (mb_substr($dataType, 0, 12) === 'customvocab:') {
                            if (empty($this->map['custom_vocabs'][$dataType]['datatype'])) {
                                $dataType = $this->getCustomVocabDataTypeName($dataType) ?? 'literal';
                            } else {
                                $dataType = $this->map['custom_vocabs'][$dataType]['datatype'];
                            }
                        }
                        // Convert datatype idref of deprecated module IdRef
                        // into valuesuggest.
                        if ($dataType === 'idref') {
                            $dataType = 'valuesuggest:idref:person';
                        }
                    }
                    unset($dataType);
                }
            }
            unset($rtProperty);

            // Loop all resource templates to know if label was renamed.
            foreach ($rts as $rt) {
                if ($this->equalResourceTemplates($rt, $source)) {
                    ++$skipped;
                    $this->map['resource_templates'][$sourceId] = $rt->id();
                    $this->logger->notice(
                        'Resource template "{label}" already exists.', // @translate
                        ['label' => $source['o:label']]
                    );
                    continue 2;
                }
            }

            // Rename the label if it already exists.
            if (isset($resourceTemplates[$source['o:label']])) {
                $sourceLabel = $source['o:label'];
                $source['o:label'] .= ' [' . $this->currentDateTime->format('Y-m-d H:i:s')
                    . ' ' . substr(bin2hex(\Laminas\Math\Rand::getBytes(20)), 0, 3) . ']';
                $this->logger->notice(
                    'Resource template "{old_label}" has been renamed to "{label}".', // @translate
                    ['old_label' => $sourceLabel, 'label' => $source['o:label']]
                );
            }

            // TODO Use orm.
            $response = $this->bulk->api()->create('resource_templates', $source);
            if (!$response) {
                $this->logger->err(
                    'Unable to create resource template "{label}".', // @translate
                    ['label' => $source['o:label']]
                );
                $this->hasError = true;
                return;
            }
            $this->logger->notice(
                'Resource template "{label}" has been created.', // @translate
                ['label' => $source['o:label']]
            );
            ++$created;

            $this->map['resource_templates'][$sourceId] = $response->getContent()->id();
        }

        $this->logger->notice(
            '{total} resource templates ready, {created} created, {skipped} skipped.', // @translate
            ['total' => $index, 'created' => $created, 'skipped' => $skipped]
        );
    }

    /**
     * @param \Omeka\Api\Representation\ResourceTemplateRepresentation $rta
     * @param array $rtb
     * @return bool
     */
    protected function equalResourceTemplates(ResourceTemplateRepresentation $rta, array $rtb): bool
    {
        // TODO Check if jsonSerialize() can be used.
        // $rta = $rta->jsonSerialize();
        $rta = json_decode(json_encode($rta), true);

        // Don't take the label into account.
        $rta['o:label'] = $rtb['o:label'];
        // Local uris are incorrect since server base url may be not set in job.
        unset($rta['@context'], $rta['@type'], $rta['@id'], $rta['o:id'], $rta['o:owner'], $rta['o:resource_class']['@id'],
            $rta['o:title_property']['@id'], $rta['o:description_property']['@id']);
        foreach ($rta['o:resource_template_property'] as &$rtProperty) {
            unset($rtProperty['o:property']['@id']);
            // To simplify comparaison, all empty values are removed.
            $rtProperty = array_filter($rtProperty);
            asort($rtProperty);
        }
        unset($rtProperty);
        $rta['o:resource_template_property'] = array_values($rta['o:resource_template_property']);

        // Update the same for the remote resource template.
        unset($rtb['@context'], $rtb['@type'], $rtb['@id'], $rtb['o:id'], $rtb['o:owner'], $rtb['o:resource_class']['@id'],
            $rtb['o:title_property']['@id'], $rtb['o:description_property']['@id']);
        if (!empty($rtb['o:resource_class']['o:id'])) {
            $rtb['o:resource_class']['o:id'] = (int) $rtb['o:resource_class']['o:id'];
        }
        if (!empty($rtb['o:title_property']['o:id'])) {
            $rtb['o:title_property']['o:id'] = (int) $rtb['o:title_property']['o:id'];
        }
        if (!empty($rtb['o:description_property']['o:id'])) {
            $rtb['o:description_property']['o:id'] = (int) $rtb['o:description_property']['o:id'];
        }
        foreach ($rtb['o:resource_template_property'] as &$rtProperty) {
            unset($rtProperty['o:property']['@id']);
            $rtProperty['o:property']['o:id'] = (int) $rtProperty['o:property']['o:id'];
            $rtProperty = array_filter($rtProperty);
            asort($rtProperty);
        }
        unset($rtProperty);
        $rtb['o:resource_template_property'] = array_values($rtb['o:resource_template_property']);
        $rta = array_filter($rta);
        $rtb = array_filter($rtb);
        asort($rta);
        asort($rtb);

        return $rta == $rtb;
    }
}
