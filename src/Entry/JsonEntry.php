<?php declare(strict_types=1);

namespace BulkImport\Entry;

class JsonEntry extends BaseEntry
{
    protected function init(): void
    {
        // Convert the data according to the mapping here.
        if (!empty($this->options['is_formatted'])) {
            return;
        }

        /** @var \BulkImport\Mvc\Controller\Plugin\TransformSource $transformSource */
        $transformSource = $this->options['transformSource'];
        if (!$transformSource) {
            return;
        }

        // Create a multivalue spreadsheet-like resource, not an omeka resource.
        // The conversion itself is done in processor.
        // So just replace each value by the right part according to mapping.
        // TODO Currently, the conversion via pattern is done here.

        // The real resource type is set via config or via processor.
        $resource = [];
        $resource = $transformSource->convertMappingSection('default', $resource, $this->data, true);
        $resource = $transformSource->convertMappingSection('mapping', $resource, $this->data);

        // Filter duplicated and null values.
        foreach ($resource as &$datas) {
            $datas = array_values(array_unique(array_filter(array_map('strval', $datas), 'strlen')));
        }
        unset($datas);

        $this->data = $resource;
    }
}
