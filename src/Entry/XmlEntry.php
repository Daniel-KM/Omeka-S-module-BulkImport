<?php declare(strict_types=1);

namespace BulkImport\Entry;

use SimpleXMLElement;

class XmlEntry extends BaseEntry
{
    protected $xmlOptions = LIBXML_BIGLINES
        | LIBXML_COMPACT
        | LIBXML_NOBLANKS
        // | LIBXML_NOCDATA
        | LIBXML_NOENT
        | LIBXML_PARSEHUGE
        | LIBXML_HTML_NOIMPLIED
        | LIBXML_HTML_NODEFDTD
        | LIBXML_NOERROR
        | LIBXML_NOWARNING
        // This option is not working on old versions of php.
        | LIBXML_NOXMLDECL
        | LIBXML_NSCLEAN
    ;

    protected function init(): void
    {
        // To check xml:
        // echo $this->data->getSimpleXMLElement()->asXML();
        // $this->logger->debug($this->data->getSimpleXMLElement()->asXML());

        // Convert the data according to the mapping here.
        if (!empty($this->options['is_formatted'])
            || empty($this->options['metaMapper'])
        ) {
            return;
        }

        if (is_null($this->data)) {
            $this->data = [];
            return;
        }

        /** @var \BulkImport\Stdlib\MetaMapper $metaMapper */
        $metaMapper = $this->options['metaMapper'];

        /** @var \XMLReaderNode $data */
        $simpleData = $this->data->getSimpleXMLElement();
        $namespaces = [null] + $simpleData->getNamespaces(true);

        // Fix issue with cdata (no: it will escape html tags).
        $simpleData = new SimpleXMLElement($simpleData->asXML(), $this->xmlOptions);

        if (!$metaMapper->getMapping()) {
            $this->extractWithoutMapping($simpleData, $namespaces);
            return;
        }

        // TODO Manage multiple resources inside one file.
        // Remove wrapper to keep mapping simple with xpath adapted to source.
        $unwrappedData = null;
        foreach ($simpleData->xpath('/resource/child::*[1]') as $node) {
            // Avoid warning on missing namespaces. But here, it doesn't matter.
            // TODO Log warning on missing namespaces instead of output.
            $unwrappedData = @new SimpleXMLElement($node->asXML());
            break;
        }
        if (!$unwrappedData) {
            $this->data = [];
            return;
        }

        // The real resource type is set via config or via processor.
        $resource = $metaMapper->convert($unwrappedData);

        // Filter duplicated and null values.
        foreach ($resource as &$datas) {
            $datas = array_values(array_unique(array_filter(array_map('strval', $datas), 'strlen')));
        }
        unset($datas);

        $this->data = $resource;
    }

    /**
     * @todo Important: For xml entry, convert this simple internal mapping into a hidden mapping to use with metaMapper.
     */
    protected function extractWithoutMapping($simpleData, $namespaces)
    {
        $array = $this->attributes($simpleData);

        foreach ($namespaces as $prefix => $namespace) {
            $nsElements = $namespace
                ? $simpleData->children($namespace)
                : $simpleData->children();
            foreach ($nsElements as $element) {
                $term = ($prefix ? $prefix . ':' : '') . $element->getName();
                $singleValue = false;
                switch ($term) {
                    case 'o:item_set':
                        $value = $this->initItemSet($element);
                        break;
                    case 'o:media':
                        $value = $this->initMedia($element);
                        break;
                    case 'o:class':
                        $term = 'o:resource_class';
                        // no break.
                    case 'o:resource_class':
                        $singleValue = true;
                        $value = $this->innerXml($element);
                        break;
                    case 'o:template':
                        $term = 'o:resource_template';
                        // no break.
                    case 'o:resource_template':
                        $singleValue = true;
                        $value = $this->innerXml($element);
                        break;
                    // Properties.
                    default:
                        $value = $this->initPropertyValue($element, $prefix);
                        break;
                }
                if ($value) {
                    if ($singleValue) {
                        $array[$term] = $value;
                    } else {
                        $array[$term][] = $value;
                    }
                }
            }
        }

        $this->data = $array;
    }

    protected function initItemSet(SimpleXMLElement $element): ?array
    {
        $resource = $this->attributes($element);

        $resource['o:is_public'] = !array_key_exists('o:is_public', $resource) || !in_array($resource['o:is_public'], $this->false, true);
        $resource['o:is_open'] = !array_key_exists('o:is_open', $resource) || !in_array($resource['o:is_open'], $this->false, true);

        $mainElement = $element;
        unset($element);
        /** @var \XMLReaderNode $data */
        $namespaces = [null] + $mainElement->getNamespaces(true);

        foreach ($namespaces as $prefix => $namespace) {
            $nsElements = $namespace
                ? $mainElement->children($namespace)
                : $mainElement->children();
            foreach ($nsElements as $element) {
                $term = ($prefix ? $prefix . ':' : '') . $element->getName();
                $value = $this->initPropertyValue($element, $prefix);
                if ($value) {
                    $resource[$term][] = $value;
                }
            }
        }

        return $resource;
    }

    protected function initMedia(SimpleXMLElement $element): ?array
    {
        // A media can be a single media or a full resource.
        // In all cases, it is returned as a full resource.
        if (!$element->count()) {
            $resource = $this->initMediaBase($element);
            // Only visibility is checked to get a public value by default.
            $resource['o:is_public'] = !array_key_exists('o:is_public', $resource) || !in_array($resource['o:is_public'], $this->false, true);
            return $resource;
        }

        // TODO Factorize with init(), but not recursive.

        $resource = $this->attributes($element);

        $mainElement = $element;
        unset($element);
        /** @var \XMLReaderNode $data */
        $namespaces = [null] + $mainElement->getNamespaces(true);

        foreach ($namespaces as $prefix => $namespace) {
            $nsElements = $namespace
                ? $mainElement->children($namespace)
                : $mainElement->children();
            foreach ($nsElements as $element) {
                $term = ($prefix ? $prefix . ':' : '') . $element->getName();
                switch ($term) {
                    case 'o:media':
                        // No recursive here: this is already a media, so this
                        // is the base media.
                        $value = $this->initMediaBase($element);
                        if ($value) {
                            unset(
                                $resource['ingest_url'],
                                $resource['ingest_filename'],
                                $resource['ingest_directory'],
                                $resource['html']
                            );
                            $resource = array_replace($resource, $value);
                            $value = null;
                        }
                        break;
                    // Properties.
                    default:
                        $value = $this->initPropertyValue($element, $prefix);
                        break;
                }
                if ($value) {
                    $resource[$term][] = $value;
                }
            }
        }

        $resource['o:is_public'] = !array_key_exists('o:is_public', $resource) || !in_array($resource['o:is_public'], $this->false, true);

        return empty($resource['o:ingester'])
            ? null
            : $resource;
    }

    protected function initMediaBase(SimpleXMLElement $element): ?array
    {
        $resource = $this->attributes($element);

        $value = $this->innerXml($element);
        if (!strlen($value)) {
            return empty($resource['o:ingester'])
                ? null
                : $resource;
        }

        $ingester = $resource['o:ingester'] ?? 'file ';
        switch ($ingester) {
            default:
            case 'tile':
                // Deprecated: "tile" is only a renderer, no more an ingester
                // since ImageServer version 3.6.13. All images are
                // automatically tiled, so "tile" is a format similar to large/medium/square,
                // but different.
            case 'file':
                if ($this->isUrl($value)) {
                    $resource['o:ingester'] = 'url';
                    $resource += [
                        'resource_name' => 'media',
                        'o:ingester' => 'url',
                        'ingest_url' => $value,
                        'o:source' => $value,
                    ];
                } else {
                    $resource['o:ingester'] = 'sideload';
                    $resource += [
                        'resource_name' => 'media',
                        'o:ingester' => 'sideload',
                        'ingest_filename' => $value,
                        'o:source' => $value,
                    ];
                }
                break;

            case 'url':
                $resource += [
                    'resource_name' => 'media',
                    'o:ingester' => 'url',
                    'ingest_url' => $value,
                    'o:source' => $value,
                ];
                break;

            case 'sideload':
                $resource += [
                    'resource_name' => 'media',
                    'o:ingester' => 'sideload',
                    'ingest_filename' => $value,
                    'o:source' => $value,
                ];
                break;

            case 'directory':
                $resource['o:ingester'] = 'sideload_dir';
                // no break.
            case 'sideload_dir':
                $resource += [
                    'resource_name' => 'media',
                    'o:ingester' => 'sideload_dir',
                    'ingest_directory' => $value,
                    'o:source' => $value,
                ];
                break;

            case 'html':
                $resource += [
                    'resource_name' => 'media',
                    'o:ingester' => 'html',
                    'html' => $value,
                    'o:source' => null,
                ];
                break;

            case 'iiif':
                if (!$this->isUrl($value) && !empty($this->options['iiifserver_media_api_url'])) {
                    $value = $this->options['iiifserver_media_api_url'] . $value;
                }
                $resource += [
                    'resource_name' => 'media',
                    'o:ingester' => 'iiif',
                    'ingest_url' => $value,
                    'o:source' => $value,
                ];
                return true;

            /*
            case 'tile':
                $resource += [
                    'resource_name' => 'media',
                    'o:ingester' => 'tile',
                    'ingest_url' => $value,
                    'o:source' => $value,
                ];
                break;
            */
        }

        return $resource;
    }

    protected function initPropertyValue(SimpleXMLElement $element): ?array
    {
        $string = $this->innerXml($element);
        if (!strlen($string)) {
            return null;
        }

        $attributes = $this->attributes($element);
        $type = $attributes['o:type'] ?? $attributes['xsi:type'] ?? null;
        $type = $type ?: 'literal';
        switch ($type) {
            default:
                // TODO Log unmanaged type.
            case 'literal':
                $value = [
                    'type' => 'literal',
                    '@value' => $string,
                ];
                break;

            case 'uri':
            case 'dcterms:URI':
                $value = [
                    'type' => 'uri',
                    '@id' => $string,
                    'o:label' => isset($attributes['o:label']) && strlen($attributes['o:label']) ? $attributes['o:label'] : null,
                    '@language' => null,
                ];
                break;

            case substr($type, 0, 8) === 'resource':
                // FIXME Manage source for xml entry.
                throw new \Exception('Unable to import a linked resource via xml currently.'); // @translate
                break;

                // Module Custom Vocab.

            case substr($type, 0, 12) === 'customvocab:':
                // TODO Manage items and uri by type for customvocab.
                $value = [
                    'type' => $type,
                    '@value' => $string,
                ];
                break;

                // Module Value Suggest (may be valuesuggest or valuesuggestall).

            case substr($type, 0, 12) === 'valuesuggest':
                $value = [
                    'type' => $type,
                    '@id' => $string,
                    'o:label' => isset($attributes['o:label']) && strlen($attributes['o:label']) ? $attributes['o:label'] : null,
                ];
                break;

                // Module Data type Rdf.

            case 'html':
            case 'rdf:HTML':
            case 'http://www.w3.org/1999/02/22-rdf-syntax-ns#HTML':
                $value = [
                    'type' => 'html',
                    '@value' => $string,
                ];
                break;

            case 'xml':
            case 'rdf:XMLLiteral':
            case 'http://www.w3.org/1999/02/22-rdf-syntax-ns#XMLLiteral':
                $value = [
                    'type' => 'xml',
                    '@value' => $string,
                ];
                break;

            case 'boolean':
            case 'xsd:boolean':
            case 'http://www.w3.org/2001/XMLSchema#boolean':
                $value = [
                    'type' => 'boolean',
                    '@value' => !(empty($string) || $string === 'false'),
                    '@language' => null,
                ];
                break;

                // Module DataTypeGeometry.

            case 'geography':
            case 'geometry':
            case 'geography:coordinates':
            case 'geometry:coordinates':
            case 'geometry:position':
            case 'http://www.opengis.net/ont/geosparql#wktLiteral':
            case 'geometry:geography:coordinates':
            case 'geometry:geometry:coordinates':
            case 'geometry:geometry:position':
            case 'geometry:geography':
            case 'geometry:geometry':
                $geotypes = [
                    'geometry:geometry' => 'geometry',
                    'geometry:geography' => 'geography',
                    'geometry:geometry:position' => 'geometry:position',
                    'geometry:geometry:coordinates' => 'geometry:coordinates',
                    'geometry:geography:coordinates' => 'geography:coordinates',
                    'http://www.opengis.net/ont/geosparql#wktLiteral' => 'geography',
                ];
                $value = [
                    'type' => $geotypes[$type] ?? $type,
                    '@value' => $string,
                    '@language' => null,
                ];
                break;

                // Module Numeric data types.

            case 'numeric:integer':
            case 'xsd:integer':
            case 'http://www.w3.org/2001/XMLSchema#integer':
                $value = [
                    'type' => 'numeric:integer',
                    '@value' => (int) $string,
                    '@language' => null,
                ];
                break;

            case 'numeric:timestamp':
                $value = [
                    'type' => 'numeric:timestamp',
                    '@value' => $string,
                    '@language' => null,
                ];
                break;

            case 'numeric:interval':
                $value = [
                    'type' => 'numeric:interval',
                    '@value' => $string,
                    '@language' => null,
                ];
                break;

            case 'numeric:duration':
            case 'http://www.w3.org/2001/XMLSchema#duration':
                $value = [
                    'type' => 'numeric:duration',
                    '@value' => $string,
                    '@language' => null,
                ];
                break;

                // Module RdfDataType (deprecated).

            case 'xsd:date':
            case 'xsd:dateTime':
            case 'xsd:gYear':
            case 'xsd:gYearMonth':
            case 'http://www.w3.org/2001/XMLSchema#dateTime':
            case 'http://www.w3.org/2001/XMLSchema#date':
            case 'http://www.w3.org/2001/XMLSchema#gYearMonth':
            case 'http://www.w3.org/2001/XMLSchema#gYear':
                if (class_exists('NumericDataTypes\DataType\Timestamp')) {
                    try {
                        $value = [
                            'type' => 'numeric:timestamp',
                            '@value' => \NumericDataTypes\DataType\Timestamp::getDateTimeFromValue($string),
                            '@language' => null,
                        ];
                    } catch (\Exception $e) {
                        $value = [
                            'type' => 'literal',
                            '@value' => $string,
                            '@language' => null,
                        ];
                    }
                }
                break;

                // TODO Need conversion to numeric timestamp. See this module.
            case 'xsd:decimal':
            case 'xsd:gDay':
            case 'xsd:gMonth':
            case 'xsd:gMonthDay':
            case 'xsd:time':
                $value = [
                    'type' => 'literal',
                    '@value' => $string,
                    '@language' => null,
                ];
                break;

                // Module IdRef (deprecated).
            case 'idref':
                $value = [
                    'type' => 'valuesuggest:idref:person',
                    '@value' => $string,
                    '@language' => null,
                ];
                break;
        }

        $value['is_public'] = !array_key_exists('o:is_public', $attributes) || !in_array($attributes['o:is_public'], $this->false, true);

        if (!array_key_exists('@language', $value)) {
            $value['@language'] = empty($attributes['xml:lang']) ? null : $attributes['xml:lang'];
        }

        return $value;
    }

    public function isEmpty(): bool
    {
        // Unlike Entry, data are filtered during array conversion.
        return !count($this->data);
    }

    /**
     * Get the inner string from an xml, in particular for cdata.
     *
     * @link https://stackoverflow.com/questions/1937056/php-simplexml-get-innerxml.
     */
    protected function innerXml(SimpleXMLElement $element): ?string
    {
        // This is the simplest way to get the good content.
        $string = trim((string) $element);
        if (strlen($string)) {
            return $string;
        }

        /*
        $value = '';
        $doc = dom_import_simplexml($element);
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;
        foreach ($doc->childNodes as $child) {
            $value .= $child->ownerDocument->saveXML($child, $this->xmlOptions) . PHP_EOL;
        }
        $value = trim($value);
        */

        $value = trim((string) $element->asXml());
        $pos = mb_strpos($value, '>');
        $value = trim(mb_substr($value, $pos + 1, mb_strrpos($value, '</') - $pos - 1));

        if (mb_substr($value, 0, 9) === '<![CDATA[' && mb_substr($value, -3) === ']]>') {
            $value = trim(mb_substr($value, 9, -3));
        }

        // If string is an xml output, indent it because simpleXml removes it.
        if (mb_substr($value, 0, 1) !== '<' || mb_substr($value, -1) !== '>') {
            return $value;
        }

        $doc = new \DomDocument();
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;
        $result = $doc->loadXML($value, $this->xmlOptions);
        if (!$result) {
            $result = $doc->loadHTML($value, $this->xmlOptions);
            return $result
                ? $doc->saveHTML()
                : $value;
        }
        $output = $doc->saveXML(null, $this->xmlOptions);
        return mb_substr($output, 0, 2) === '<?'
            ? trim(mb_substr($output, mb_strpos($output, '?>') + 2))
            : $output;
    }

    /**
     * Read attributes of the current element for some namespaces.
     */
    protected function attributes(SimpleXMLElement $element): array
    {
        $namespaces = [
            '' => null,
            'o:' => 'http://omeka.org/s/vocabs/o#',
            'xsi:' => 'http://www.w3.org/2001/XMLSchema-instance',
            'xml:' => 'http://www.w3.org/XML/1998/namespace',
        ];

        $attributes = [];
        foreach ($namespaces as $prefix => $namespace) {
            $attrs = (array) $element->attributes($namespace);
            foreach ($attrs['@attributes'] ?? [] as $name => $value) {
                $attributes[$prefix . $name] = $value;
            }
        }
        return $attributes;
    }

    /**
     * Check if a string seems to be an url.
     *
     * @todo Use \BulkImport\Mvc\Controller\Plugin\Bulk::isUrl().
     *
     * Doesn't use FILTER_VALIDATE_URL, so allow non-encoded urls.
     *
     * @param string $string
     * @return bool
     */
    protected function isUrl($string)
    {
        return strpos($string, 'https:') === 0
            || strpos($string, 'http:') === 0
            || strpos($string, 'ftp:') === 0
            || strpos($string, 'sftp:') === 0;
    }
}
