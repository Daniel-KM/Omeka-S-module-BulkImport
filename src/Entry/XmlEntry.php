<?php declare(strict_types=1);

namespace BulkImport\Entry;

class XmlEntry extends Entry
{
    protected function init($data, array $fields, array $options): void
    {
        /** @var \XMLReaderNode $data */
        $simpleData = $data->getSimpleXMLElement();
        $namespaces = [null] + $simpleData->getNamespaces(true);

        $array = [];
        foreach ($namespaces as $prefix => $namespace) {
            $nsElements = $namespace
                ? $simpleData->children($namespace)
                : $simpleData->children();
            foreach ($nsElements as $element) {
                $term = $prefix . ':' . $element->getName();

                // Read and merge attributes of the current element, only for
                // some specific namespaces.
                $string = $element->__toString();
                if (!strlen($string)) {
                    continue;
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
                            'o:language' => null,
                        ];
                        break;

                    // Module Custom Vocab.

                    case substr($type, 0, 12) === 'customvocab:':
                        // TODO Manage items by type.
                        $value = [
                            'type' => $type,
                            '@value' => $string,
                            '@language' => null,
                        ];
                        break;

                    // Module Value Suggest (may be valuesuggest or valuesuggestall).

                    case substr($type, 0, 12) === 'valuesuggest':
                        $value = [
                            'type' => $type,
                            '@value' => $string,
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
                            '@type' => 'boolean',
                            '@value' => !(empty($string) || $string === 'false'),
                            '@language' => null,
                        ];
                        break;

                    // Module DataTypeGeometry.

                    case 'geometry:geography':
                    case 'geometry:geometry':
                    case 'http://www.opengis.net/ont/geosparql#wktLiteral':
                        $value = [
                            'type' => $type,
                            '@value' => $string,
                            '@language' => null,
                        ];
                        break;

                    // Module Numeric data types.

                    case 'numeric:integer':
                    case 'xsd:integer':
                    case 'http://www.w3.org/2001/XMLSchema#integer':
                        $value = [
                            '@type' => 'numeric:integer',
                            '@value' => (int) $string,
                            '@language' => null,
                        ];
                        break;

                    case 'numeric:timestamp':
                        $value = [
                            '@type' => 'numeric:timestamp',
                            '@value' => $string,
                            '@language' => null,
                        ];
                        break;

                    case 'numeric:interval':
                        $value = [
                            '@type' => 'numeric:interval',
                            '@value' => $string,
                            '@language' => null,
                        ];
                        break;

                    case 'numeric:duration':
                    case 'http://www.w3.org/2001/XMLSchema#duration':
                        $value = [
                            '@type' => 'numeric:duration',
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

                $value['is_public'] = !array_key_exists('o:is_public', $attributes)
                    || (!empty($attributes['o:is_public']) && $attributes['o:is_public'] !== 'false');
                if (!array_key_exists('@language', $value)) {
                    $value['@language'] = empty($attributes['xml:lang']) ? null : $attributes['xml:lang'];
                }

                $array[$term][] = $value;
            }
        }

        $this->data = $array;
    }

    protected function postInit($data, array $fields, array $options): void
    {
    }

    public function isEmpty(): bool
    {
        // Unlike Entry, data are filtered during array conversion.
        return !count($this->data);
    }

    /**
     * Read attributes of the current element for some namespaces.
     */
    protected function attributes(\SimpleXMLElement $element): array
    {
        $namespaces = [
            'o' => 'http://omeka.org/s/vocabs/o#',
            'xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
            'xml' => 'http://www.w3.org/XML/1998/namespace',
        ];

        $attributes = [];
        foreach ($namespaces as $prefix => $namespace) {
            $attrs = (array) $element->attributes($namespace);
            foreach ($attrs['@attributes'] ?? [] as $name => $value) {
                $attributes[$prefix . ':' . $name] = $value;
            }
        }
        return $attributes;
    }
}
