<?php declare(strict_types=1);

namespace BulkImport\Entry;

class JsonEntry extends BaseEntry
{
    protected function init(): void
    {
        // Convert the data according to the mapping here.
        if (!empty($this->options['is_formatted'])
            || empty($this->options['metaMapper'])
        ) {
            return;
        }

        /** @var \BulkImport\Stdlib\MetaMapper $metaMapper */
        $metaMapper = $this->options['metaMapper'];

        // Avoid an issue when the config is incorrect or incomplete or when the
        // source is not available.
        if (!is_array($this->data) && !is_null($this->data)) {
            return;
        }

        // Create a multivalue spreadsheet-like resource, not an omeka resource.
        // The conversion itself is done in processor.
        // So just replace each value by the right part according to mapping.
        // TODO Currently, the conversion via pattern is done here: move it to the processor (?).

        // The real resource type is set via config or via processor.
        $resource = $metaMapper->convert($this->data);

        $importMedia = $metaMapper->getMetaMapperConfig()->getSectionSetting('params', 'import_media');
        if (in_array($importMedia, ['1', true, 'true'])) {
            $resource = $this->appendMedias($resource);
        }

        // Filter duplicated and null values.
        foreach ($resource as &$datas) {
            $datas = array_values(array_unique(array_filter(array_map('strval', $datas), 'strlen')));
        }
        unset($datas);

        $this->data = $resource;
    }

    protected function appendMedias(array $resource): array
    {
        /** @var \BulkImport\Stdlib\MetaMapper $metaMapper */
        $metaMapper = $this->options['metaMapper'];
        $mediaUrlMode = $metaMapper->getMetaMapperConfig()->getSectionSetting('params', 'media_url_mode');
        if (!$mediaUrlMode) {
            return $resource;
        }

        // IIIF.
        if (in_array($mediaUrlMode, ['iiif_service_or_id', 'iiif_id_or_service', 'iiif_service', 'iiif_id'])) {
            if (empty($this->data['@context'])) {
                return $resource;
            }
            // IIIF v2.
            if ($this->data['@context'] === 'http://iiif.io/api/presentation/2/context.json') {
                // Get all resources in all canvases in all sequences, but one time only.
                // Omeka manages only iiif images.
                // TODO Import other files as standard files.
                foreach ($this->data['sequences'] ?? [] as $sequence) {
                    foreach ($sequence['canvases'] ?? [] as $canvas) {
                        foreach ($canvas['images'] ?? [] as $image) {
                            if ($mediaUrlMode === 'iiif_service_or_id') {
                                if (isset($image['resource']['service']['@id'])) {
                                    $resource['iiif'][] = $image['resource']['service']['@id'];
                                } elseif (isset($image['resource']['@id'])) {
                                    $resource['url'][] = $image['resource']['@id'];
                                }
                            } elseif ($mediaUrlMode === 'iiif_id_or_service') {
                                if (isset($image['resource']['@id'])) {
                                    $resource['url'][] = $image['resource']['@id'];
                                } elseif (isset($image['resource']['service']['@id'])) {
                                    $resource['iiif'][] = $image['resource']['service']['@id'];
                                }
                            } elseif ($mediaUrlMode === 'iiif_service') {
                                if (isset($image['resource']['service']['@id'])) {
                                    $resource['iiif'][] = $image['resource']['service']['@id'];
                                }
                            } elseif ($mediaUrlMode === 'iiif_id') {
                                if (isset($image['resource']['@id'])) {
                                    $resource['url'][] = $image['resource']['@id'];
                                }
                            }
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
        }
        // Content-DM.
        elseif ($mediaUrlMode === 'contentdm') {
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
        }

        return $resource;
    }
}
