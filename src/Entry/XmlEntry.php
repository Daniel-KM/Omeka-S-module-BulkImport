<?php declare(strict_types=1);

namespace BulkImport\Entry;

use SimpleXMLElement;
use XMLReaderNode;

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
        if ($this->data instanceof XMLReaderNode) {
            $simpleXml = $this->data->getSimpleXMLElement();
            // Fix issue with cdata (no: it will escape html tags).
            // $simpleXml = new SimpleXMLElement($simpleXml->asXML(), $this->xmlOptions);
            $simpleXml = new \BulkImport\Entry\SimpleXMLElementNamespaced($simpleXml->asXML(), $this->xmlOptions);
        } elseif (!$this->data instanceof SimpleXMLElement) {
            $this->data = [];
            return;
        }

        // To check xml in entry:
        // echo $simpleXml->asXML();
        // $this->logger->debug($simpleXml->asXML());
        /*
        $dom = new DOMDocument('1.0');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($simpleXml->asXML());
        echo $dom->saveXML();
        $this->logger->debug($dom->saveXML());
        */

        // Remove wrapper to keep mapping simple with xpath adapted to source.

        // There should be only one resource by entry, else use a xsl to
        // separate them earlier.

        // The resource may be a resource itself or may be wrapped, for example
        // with child `ead` or `unimarc`. When wrapped, the attribute `wrapper="1"`
        // should be set.

        // For example, here, the real resource is `record` and `resource` is
        // the wrapper:
        // `<resources><resource wrapper="1"><record>...</record></resource></resources>`.
        // So the source xml (transformed by xsl) is: `/resources/resource/record`
        // In the iterated entry, the xml is: `/resource/record`
        // And the wrapper `resource` is removed to get only `record` (or any
        // other first child element, like `unimarc` or `ead`). This mechanism
        // is similar to oai-pmh wrapping.

        // To simplify process, the wrapper can be the record itself.
        // It is useful when the source is already like an omeka json-ld
        // resource, or when the source is transformed by an xsl to be like an
        // omeka json-ld element, because it avoids a possible double `/resource/resource`
        // or any other useless element.

        // And when the content matches the json-ld representation, a mapping is
        // useless.

        // Remove the wrapper if any.
        $isWrapper = $simpleXml->xpath('/resource/@wrapper');
        $isWrapper = $isWrapper ? (bool) $isWrapper[0] : false;
        if ($isWrapper) {
            $unwrappedData = null;
            foreach ($simpleXml->xpath('/resource/child::*[1]') as $node) {
                // Avoid warning on missing namespaces. But here, it doesn't matter.
                // TODO Log warning on missing namespaces instead of output.
                $unwrappedData = @new SimpleXMLElement($node->asXML()) ?: null;
                break;
            }
            $this->xml = $unwrappedData;
        } else {
            $this->xml = $simpleXml;
        }

        $this->data = [];
    }

    public function isEmpty(): bool
    {
        return $this->xml === null;
    }

    public function getArrayCopy(): array
    {
        if ($this->xml === null || $this->data !== []) {
            return $this->data;
        }
        if (!$this->xml instanceof \BulkImport\Entry\SimpleXMLElementNamespaced) {
            $this->xml = new \BulkImport\Entry\SimpleXMLElementNamespaced($this->xml->asXML());
        }
        $this->data = $this->xml
            ->setWithPrefix(true)
            ->toArray(true);
        return $this->data;
    }

    /**
     * When the xml is an Omeka resource with integrated vocabularies, there is
     * no need to convert it.
     *
     * @todo Important: For xml entry, convert this simple internal mapping into a hidden mapping to use with metaMapper.
     */
    public function extractWithoutMapping(): array
    {
        if ($this->xml === null) {
            return [];
        }

        $namespaces = [null] + $this->xml->getNamespaces(true);

        $array = $this->attributes($this->xml);

        foreach ($namespaces as $prefix => $namespace) {
            $nsElements = $namespace
                ? $this->xml->children($namespace)
                : $this->xml->children();
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
        return $this->data;
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
        $media = $this->attributes($element);

        $inner = $this->innerXml($element);
        if (!strlen($inner)) {
            return empty($media['o:ingester'])
                ? null
                : $media;
        }

        $media['resource_name'] = 'media';

        /**
         * @see \BulkImport\Processor\ResourceProcessor::prepareMediaData()
         * @see \BulkImport\Processor\ResourceProcessor::prepareMediaDataFinalize()
         */
        $ingester = $media['o:ingester'] ?? 'file';

        // Except html that is rare, it is a path or url, so without <.
        if (mb_strpos($inner, '<')) {
            $value = $inner;
            switch ($ingester) {
                default:
                    return null;
                case 'tile':
                    // Deprecated: "tile" is only a renderer, no more an ingester
                    // since ImageServer version 3.6.13. All images are
                    // automatically tiled, so "tile" is a format similar to large/medium/square,
                    // but different.
                case 'file':
                case 'url':
                case 'sideload':
                    // A file may be a url for end-user simplicity.
                    $media['o:source'] = empty($media['o:source']) ? $value : $media['o:source'];
                    if ($this->isUrl($value)) {
                        $media['o:ingester'] = 'url';
                        $media['ingest_url'] = $value;
                        unset($media['ingest_filename']);
                        unset($media['ingest_directory']);
                    }  else {
                        // TODO Manage local file bulk upload (but normally checked later)
                        $media['o:ingester'] = 'sideload';
                        $media['ingest_filename'] = $value;
                        unset($media['ingest_url']);
                        unset($media['ingest_directory']);
                    }
                    break;

                case 'directory':
                case 'sideload_dir':
                    $media['o:ingester'] = 'sideload_dir';
                    $media['ingest_directory'] = $value;
                    $media['o:source'] = empty($media['o:source']) ? $value : $media['o:source'];
                    unset($media['ingest_filename']);
                    unset($media['ingest_url']);
                    break;

                // TODO Manager html value data in media.
                case 'html':
                    $media['o:ingester'] = 'html';
                    $media['html'] = $value;
                    $media['o:source'] ??= null;
                    unset($media['ingest_url']);
                    unset($media['ingest_filename']);
                    unset($media['ingest_directory']);
                    break;

                case 'iiif':
                case 'iiif_presentation':
                    if (!$this->isUrl($value) && !empty($this->options['iiifserver_media_api_url'])) {
                        $value = $this->options['iiifserver_media_api_url'] . $value;
                    }
                    $media['o:ingester'] = $ingester;
                    $media['ingest_url'] = $value;
                    unset($media['ingest_filename']);
                    unset($media['ingest_directory']);
                    break;

                case 'oembed':
                case 'youtube':
                    $media['o:ingester'] = $ingester;
                    $media['o:source'] = empty($media['o:source']) ? $value : $media['o:source'];
                    unset($media['ingest_filename']);
                    unset($media['ingest_directory']);
                    unset($media['ingest_url']);
                    break;
            }
        }

        // Get properties if any.
        $mainElement = $element;
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
                    $media[$term][] = $value;
                }
            }
        }

        return $media;
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
    protected function isUrl($string): bool
    {
        return strpos($string, 'https:') === 0
            || strpos($string, 'http:') === 0
            || strpos($string, 'ftp:') === 0
            || strpos($string, 'sftp:') === 0;
    }
}
