<?php declare(strict_types=1);
namespace BulkImport\View\Helper;

use Laminas\I18n\View\Helper\Translate;
use Laminas\View\Helper\AbstractHelper;
use Omeka\View\Helper\Api;

class AutomapFields extends AbstractHelper
{
    /**
     * @var array
     */
    protected $map;

    /**
     * @var Api
     */
    protected $api;

    /**
     * @var Translate
     */
    protected $translate;

    /**
     * @param array $map
     * @param Api $api
     * @param Translate $translate
     */
    public function __construct(array $map, Api $api, Translate $translate)
    {
        $this->map = $map;
        $this->api = $api;
        $this->translate = $translate;
    }

    /**
     * Automap a list of field names with a standard Omeka metadata names.
     *
     * This is a simplified but improved version of the full automap of an old
     * version of the automap of the module CSVImport. it returns all the input
     * fields, with field name, identified metadata, language and datatype.
     * It manages language and datatype, as in "dcterms:title @fr-fr ^^xsd:string §private",
     * following some convention, without the double quote.
     * Furthermore, user mappings are replaced by the file /data/mappings/fields_to_metadata.php.
     *
     * Default supported datatypes are the ones managed by omeka (cf. config[data_types]:
     * literal, resource, uri, resource:item, resource:itemset, resource:media.
     * Supported datatypes if modules are present:
     * numeric:timestamp, numeric:integer, numeric:duration, geometry:geography,
     * geometry:geometry.
     * The prefixes can be omitted, so item, itemset, media, timestamp, integer,
     * duration, geography, geometry.
     * Datatype for modules CustomVocab and ValueSuggest are supported too:
     * customvocab:xxx and valuesuggest:xxx, as well as rdf datatypes managed by
     * module DataTypeRdf: xsd:string (literal), rdf:XMLLiteral, xsd:boolean,
     * xsd:date, xsd:dateTime, xsd:decimal, xsd:gDay, xsd:gMonth, xsd:gMonthDay,
     * xsd:gYear, xsd:gYearMonth, xsd:integer, xsd:time, rdf:HTML, with or
     * without prefix. The datatypes are checked by the processor.
     * Multiple targets can be mapped with the separator "|". Note that if there
     * may be multiple properties, only the first language and type will be
     * used.
     * The visibility of each data can be public or private, prefixed by "§".
     *
     * @see \CSVImport\Mvc\Controller\Plugin\AutomapHeadersToMetadata
     *
     * @param array $fields
     * @param array $options Associative array of options:
     * - map (array)
     * - check_names_alone (boolean)
     * - output_full_matches (boolean) Returns the language and datatype too.
     * - resource_type (string) Useless, except for quicker process.
     * @return array Associative array of all fields with the normalized name,
     * or with their normalized name, language and datatype when option
     * "output_full_matches" is set, or null.
     */
    public function __invoke($fields, array $options = [])
    {
        // Return all values, even without matching normalized name, with the
        // same keys in the same order.
        $automaps = array_fill_keys(array_keys($fields), null);

        $defaultOptions = [
            'map' => [],
            'check_names_alone' => true,
            'output_full_matches' => false,
            'resource_type' => null,
        ];
        $options += $defaultOptions;
        $this->map = array_merge($this->map, $options['map']);
        unset($options['map']);

        $checkNamesAlone = (bool) $options['check_names_alone'];
        $outputFullMatches = (bool) $options['output_full_matches'];

        $fields = $this->cleanStrings($fields);

        // Prepare the standard lists to check against.
        $lists = [];
        $automapLists = [];

        // Prepare the list of names and labels one time to speed up process.
        $propertyLists = $this->listTerms();

        // The automap list is the file mapping combined with itself, with a
        // lower case version.
        $automapList = [];
        if ($this->map) {
            $automapList = $this->map;
            // Add all the defined values as key too.
            $automapList += array_combine($automapList, $automapList);
            $automapLists['base'] = array_combine(
                array_keys($automapList),
                array_keys($automapList)
            );
            $automapLists['lower_base'] = array_map('mb_strtolower', $automapLists['base']);
            if ($automapLists['base'] === $automapLists['lower_base']) {
                unset($automapLists['base']);
            }
        }

        // Because some terms and labels are not standardized (foaf:givenName is
        // not foaf:givenname), the process must be done case sensitive first.
        $lists['names'] = array_combine(
            array_keys($propertyLists['names']),
            array_keys($propertyLists['names'])
        );
        $lists['lower_names'] = array_map('mb_strtolower', $lists['names']);
        $lists['labels'] = array_combine(
            array_keys($propertyLists['names']),
            array_keys($propertyLists['labels'])
        );
        $lists['lower_labels'] = array_map('mb_strtolower', $lists['labels']);

        // Check names alone, like "Title", for "dcterms:title".
        if ($checkNamesAlone) {
            $lists['local_names'] = array_map(function ($v) {
                $w = explode(':', $v);
                return end($w);
            }, $lists['names']);
            $lists['lower_local_names'] = array_map('mb_strtolower', $lists['local_names']);
            $lists['local_labels'] = array_map(function ($v) {
                $w = explode(':', $v);
                return end($w);
            }, $lists['labels']);
            $lists['lower_local_labels'] = array_map('mb_strtolower', $lists['local_labels']);
        }

        // The pattern checks a term or keyword, then an optional @language, then
        // an optional ^^data type, then an optional §visibility.
        $pattern = '~'
            // Check a term/keyword (dcterms:title).
            . '^([a-zA-Z][^@§^]*)'
            // Check a language + country (@fr-Fr).
            . '\s*(?:@\s*([a-zA-Z]+-[a-zA-Z]+|[a-zA-Z]+|))?'
            // Check a data type (^^resource:item).
            . '\s*(?:\^\^\s*([a-zA-Z][a-zA-Z0-9]*:[a-zA-Z][\w-]*|[a-zA-Z][\w-]*|))?'
            // Check visibility (§private).
            . '\s*(?:§\s*(public|private|))?'
            . '$'
            . '~';
        $matches = [];

        foreach ($fields as $index => $fieldsMulti) {
            $fieldsMulti = array_filter(array_map('trim', explode('|', $fieldsMulti)));
            foreach ($fieldsMulti as $field) {
                $meta = preg_match($pattern, $field, $matches);
                if (!$meta) {
                    continue;
                }

                // TODO Add a check of the type with the list of data types.

                $field = trim($matches[1]);
                $lowerField = strtolower($field);

                // Check first with the specific auto-mapping list.
                foreach ($automapLists as $listName => $list) {
                    $toSearch = strpos($listName, 'lower_') === 0 ? $lowerField : $field;
                    $found = array_search($toSearch, $list, true);
                    if ($found) {
                        // The automap list is used to keep the sensitive value.
                        if ($outputFullMatches) {
                            $result = [];
                            $result['field'] = $automapList[$found];
                            $result['@language'] = empty($matches[2]) ? null : trim($matches[2]);
                            $result['type'] = empty($matches[3]) ? null : trim($matches[3]);
                            $result['is_public'] = empty($matches[4]) ? null : trim($matches[4]);
                            $automaps[$index][] = $result;
                        } else {
                            $automaps[$index][] = $automapList[$found];
                        }
                        continue 2;
                    }
                }

                // Check strict term name, like "dcterms:title", sensitively then
                // insensitively, then term label like "Dublin Core : Title"
                // sensitively then insensitively too. Because all the lists contain
                // the same keys in the same order, the process can be done in one
                // step.
                foreach ($lists as $listName => $list) {
                    $toSearch = strpos($listName, 'lower_') === 0 ? $lowerField : $field;
                    $found = array_search($toSearch, $list, true);
                    if ($found) {
                        if ($outputFullMatches) {
                            $result = [];
                            $result['field'] = $propertyLists['names'][$found];
                            $result['@language'] = empty($matches[2]) ? null : trim($matches[2]);
                            $result['type'] = empty($matches[3]) ? null : trim($matches[3]);
                            $result['is_public'] = empty($matches[4]) ? null : trim($matches[4]);
                            $automaps[$index][] = $result;
                        } else {
                            $property = $propertyLists['names'][$found];
                            $automaps[$index][] = $property;
                        }
                        continue 2;
                    }
                }
            }
        }

        return $automaps;
    }

    /**
     * Return the list of properties by names and labels.
     *
     * @return array Associative array of term names and term labels as key
     * (ex: "dcterms:title" and "Dublin Core : Title") in two subarrays ("names"
     * "labels", and properties as value.
     * Note: Some terms are badly standardized (in foaf, the label "Given name"
     * matches "foaf:givenName" and "foaf:givenname"), so, in that case, the
     * index is added to the label, except the first property.
     */
    protected function listTerms()
    {
        $result = [];
        $vocabularies = $this->api()->search('vocabularies')->getContent();
        foreach ($vocabularies as $vocabulary) {
            $properties = $vocabulary->properties();
            if (empty($properties)) {
                continue;
            }
            foreach ($properties as $property) {
                $result['names'][$property->term()] = $property->term();
                $name = $vocabulary->label() . ':' . $property->label();
                if (isset($result['labels'][$name])) {
                    $result['labels'][$vocabulary->label() . ':' . $property->label() . ' (#' . $property->id() . ')'] = $property->term();
                } else {
                    $result['labels'][$vocabulary->label() . ':' . $property->label()] = $property->term();
                }
            }
        }
        return $result;
    }

    /**
     * Clean and trim all whitespace, and remove spaces around colon.
     *
     * It fixes whitespaces added by some spreadsheets before or after a colon.
     *
     * @param array $strings
     * @return array
     */
    protected function cleanStrings(array $strings)
    {
        return array_map(function ($string) {
            return preg_replace('~\s*:\s*~', ':', $this->cleanUnicode($string));
        }, $strings);
    }

    /**
     * Clean and trim all whitespaces, included the unicode ones.
     *
     * @param string $string
     * @return string
     */
    protected function cleanUnicode($string)
    {
        return trim(preg_replace('/[\s\h\v[:blank:][:space:]]+/u', ' ', $string));
    }

    protected function api()
    {
        return $this->api;
    }
}
