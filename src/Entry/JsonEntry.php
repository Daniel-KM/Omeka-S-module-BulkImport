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

        if (!empty($this->data['@context'])) {
            if ($this->data['@context'] === 'http://iiif.io/api/presentation/2/context.json') {
                $importMedia = $transformSource->getSectionSetting('params', 'import_media');
                if (in_array($importMedia, ['1', true, 'true'])) {
                    $resource = $this->appendMedias($resource);
                }
            }
        }

        // Filter duplicated and null values.
        foreach ($resource as &$datas) {
            $datas = array_values(array_unique(array_filter(array_map('strval', $datas), 'strlen')));
        }
        unset($datas);

        $smartDownload = $transformSource->getSectionSetting('params', 'smart_download_contentdm');
        if (in_array($smartDownload, ['1', true, 'true'])) {
            $resource = $this->smartDownloadContentDm($resource);
        }

        $this->data = $resource;
    }

    protected function appendMedias(array $resource): array
    {
        // Get all resources in all canvases in all sequences, but one time only.
        // Omeka manages only iiif images.
        // TODO Import other files as standard files.
        foreach ($this->data['sequences'] ?? [] as $sequence) {
            foreach ($sequence['canvases'] ?? [] as $canvas) {
                foreach ($canvas['images'] ?? [] as $image) {
                    if (isset($image['resource']['service']['@id'])) {
                        $resource['iiif'][] = $image['resource']['service']['@id'];
                    } elseif (isset($image['resource']['@id'])) {
                        $resource['url'][] = $image['resource']['@id'];
                    }
                }
            }
        }
        if (!empty($resource['iiif'])) {
            $resource['iiif'] = array_values(array_unique(array_filter($resource['iiif'])));
        }
        if (!empty($resource['url'])) {
            $resource['url'] = array_values(array_unique(array_filter($resource['url'])));
        }

        return $resource;
    }

    protected function smartDownloadContentDm(array $resource): array
    {
        if (empty($resource['url ~ {{ endpoint }}{{ value }}']) || empty($resource['iiif ~ {{ endpoint }}{{ value }}'])) {
            return $resource;
        }
        $urls = [];
        $iiif = [];
        foreach ($resource['url ~ {{ endpoint }}{{ value }}'] as $index => $url) {
            $code = preg_replace('~.*collection/([^/]+)/id/([^/]+)/.*~m', '$1:$2', $url);
            if ($code && $code !== $url) {
                $urls[$code] = $index;
            }
        }
        foreach ($resource['iiif ~ {{ endpoint }}{{ value }}'] as $index => $url) {
            $code = preg_replace('~^.*/([^/]+:[^/]+)/info.json$~m', '$1', $url);
            if ($code && $code !== $url) {
                $iiif[$code] = $index;
            }
        }
        $duplicates = array_intersect_key($urls, $iiif);
        if ($duplicates) {
            $resource['url ~ {{ endpoint }}{{ value }}'] = array_values(array_diff_key($resource['url ~ {{ endpoint }}{{ value }}'], array_flip($duplicates)));
        }
        return $resource;
    }
}
