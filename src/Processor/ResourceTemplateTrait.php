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
     * @param iterable $sourceResourceTemplates Should be countable too.
     */
    protected function prepareResourceTemplatesProcess($sourceResourceTemplates): void
    {
        $this->map['resource_templates'] = [];

        if ((is_array($sourceResourceTemplates) && !count($sourceResourceTemplates))
            || (!is_array($sourceResourceTemplates) && !$sourceResourceTemplates->count())
        ) {
            $this->logger->notice(
                'No resource templates importable from source.' // @translate
            );
            return;
        }

        $resourceTemplates = $this->getResourceTemplateIds();

        $result = $this->api()
            ->search('resource_templates')->getContent();
        $rts = [];
        foreach ($result as $resourceTemplate) {
            $rts[$resourceTemplate->label()] = $resourceTemplate;
        }
        unset($result);

        $index = 0;
        $created = 0;
        $skipped = 0;
        foreach ($sourceResourceTemplates as $sourceResourceTemplate) {
            ++$index;
            // Clean the metadata to simplify check and import.
            $sourceResourceTemplateId = $sourceResourceTemplate['o:id'];
            unset($sourceResourceTemplate['@id'], $sourceResourceTemplate['o:id']);
            $sourceResourceTemplate['o:owner'] = $this->userOIdOrDefaultOwner($sourceResourceTemplate['o:owner']);

            $sourceResourceTemplate['o:resource_class'] = !empty($sourceResourceTemplate['o:resource_class']['o:id'])
                && isset($this->map['by_id']['resource_classes'][$sourceResourceTemplate['o:resource_class']['o:id']])
                ? ['o:id' => $this->map['by_id']['resource_classes'][$sourceResourceTemplate['o:resource_class']['o:id']]]
                : null;
            $sourceResourceTemplate['o:title_property'] = !empty($sourceResourceTemplate['o:title_property']['o:id'])
                && !empty($this->map['by_id']['properties'][$sourceResourceTemplate['o:title_property']['o:id']])
                ? ['o:id' => $this->map['by_id']['properties'][$sourceResourceTemplate['o:title_property']['o:id']]]
                : null;
            $sourceResourceTemplate['o:description_property'] = !empty($sourceResourceTemplate['o:description_property']['o:id'])
                && !empty($this->map['by_id']['properties'][$sourceResourceTemplate['o:description_property']['o:id']])
                ? ['o:id' => $this->map['by_id']['properties'][$sourceResourceTemplate['o:description_property']['o:id']]]
                : null;
            foreach ($sourceResourceTemplate['o:resource_template_property'] as &$rtProperty) {
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
                        if (strtok($dataType, ':') === 'customvocab') {
                            $dataType = !empty($this->map['custom_vocabs'][$dataType]['datatype'])
                                ? $this->map['custom_vocabs'][$dataType]['datatype']
                                : 'literal';
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
                if ($this->equalResourceTemplates($rt, $sourceResourceTemplate)) {
                    ++$skipped;
                    $this->map['resource_templates'][$sourceResourceTemplateId] = $rt->id();
                    $this->logger->notice(
                        'Resource template "{label}" already exists.', // @translate
                        ['label' => $sourceResourceTemplate['o:label']]
                    );
                    continue 2;
                }
            }

            // Rename the label if it already exists.
            if (isset($resourceTemplates[$sourceResourceTemplate['o:label']])) {
                $sourceLabel = $sourceResourceTemplate['o:label'];
                $sourceResourceTemplate['o:label'] .= ' ' . (new \DateTime())->format('Ymd-His')
                    . ' ' . substr(bin2hex(\Laminas\Math\Rand::getBytes(20)), 0, 5);
                $this->logger->notice(
                    'Resource template "{old_label}" has been renamed to "{label}".', // @translate
                    ['old_label' => $sourceLabel, 'label' => $sourceResourceTemplate['o:label']]
                );
            }

            // TODO Use orm.
            $response = $this->api()->create('resource_templates', $sourceResourceTemplate);
            if (!$response) {
                $this->logger->err(
                    'Unable to create resource template "{label}".', // @translate
                    ['label' => $sourceResourceTemplate['o:label']]
                );
                $this->hasError = true;
                return;
            }
            $this->logger->notice(
                'Resource template "{label}" has been created.', // @translate
                ['label' => $sourceResourceTemplate['o:label']]
            );
            ++$created;

            $this->map['resource_templates'][$sourceResourceTemplateId] = $response->getContent()->id();
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