<?php declare(strict_types=1);

namespace BulkImport\Mvc\Controller\Plugin;

use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Mvc\Controller\Plugin\Api;

class AutomapFields extends AbstractPlugin
{
    /**
     * The pattern checks a field (term or keyword), then, in any order, a
     * a @language, a ^^datatype or multiple data types (^^datatype1 ; datatype2),
     * and a §visibility, and finally a ~pattern.
     * A pattern is allowed only when there is a single target field.
     */
    const PATTERN = '#'
        // Check a term/keyword ("dcterms:title" or "Rights holder" or "Resource class"), required.
        . '^\s*(?<field>[a-zA-Z][^@§^~|]*?)'
        // In any order:
        . '(?:'
        // Check a language + country (@fra or @fr-Fr or @en-GB-oed, etc.).
        // See application/asset/js/admin.js for a complex check.
        // @link http://stackoverflow.com/questions/7035825/regular-expression-for-a-language-tag-as-defined-by-bcp47
        . '(?:\s*@\s*(?<language>(?:(?:[a-zA-Z0-9]+-)*[a-zA-Z]+|)))'
        // Check a data type (^^resource:item or ^^customvocab:Liste des établissements)
        // or multiple data types (^^customvocab:xxx ; resource:item ; literal).
        // The sub data type must not contain a ";".
        . '|(?:\s*\^\^\s*(?<datatypes>(?:[a-zA-Z][\w;]*:[\w\p{L}][\w\p{L}:;\s-]*?|[a-zA-Z][\w;\s-]*)+))'
        // Check visibility (§private).
        . '|(?:\s*§\s*(?<visibility>public|private|))'
        // Max three options, but no check for duplicates for now.
        . '){0,3}'
        // A replacement pattern for optional transformation of the source:
        // ~ {{ value|trim }}. It can be a default value, enclosed or not by
        // quotes: ~ "Public domain" (internal quotes are not escaped).
        . '(?:\s*~\s*(?<pattern>.*))?'
        // Remove final spaces too.
        . '\s*$'
        // Unicode is used for custom vocab labels.
        . '#u';

    /**
     * @var array
     */
    protected $map;

    /**
     * @var Api
     */
    protected $api;

    /**
     * @var Bulk
     */
    protected $bulk;

    public function __construct(array $map, Api $api, Bulk $bulk)
    {
        $this->map = $map;
        $this->api = $api;
        $this->bulk = $bulk;
    }

    /**
     * Automap a list of field names with a standard Omeka metadata names.
     *
     * This is a simplified but improved version of the full automap of an old
     * version of the automap of the module CSVImport. It returns all the input
     * fields, with field name, identified metadata, language and datatype.
     * It manages language, datatype and visibility, as in "dcterms:title @fra ^^resource:item §private",
     * following some conventions, without the double quote.
     * Furthermore, user mappings are replaced by the file /data/mappings/fields_to_metadata.php.
     * Finally, a pattern (prefixed with "~") can be appended to transform the
     * value.
     *
     * Default supported datatypes are the ones managed by omeka (cf. config[data_types]:
     * literal, resource, uri, resource:item, resource:itemset, resource:media.
     * Supported datatypes if modules are present:
     * numeric:timestamp, numeric:integer, numeric:duration, geometry:geography,
     * geometry:geometry.
     * The prefixes can be omitted, so item, itemset, media, timestamp, integer,
     * duration, geography, geometry.
     * Datatypes of other modules are supported too (Custom Vocab,
     * Value Suggest, DataTypeRdf, Numeric Data Types):
     * - customvocab:xxx (where xxx is the id, or the label (the punctuation,
     *   included quote, should be removed, but space is allowed)
     *   spaces,
     * - valuesuggest:xxx,
     * - html,
     * - xml,
     * - boolean,
     * - numeric:timestamp,
     * - numeric:integer,
     * - etc.
     * with or without prefix, etc.
     * There may be multiple data types, separated with a ";". So the data type
     * itself must not contain a ";", or any special character anyway (no
     * punctuation). When there are multiple data types, they are checked in the
     * order they are. So take care of custom vocab with item sets.
     * The datatypes are checked by the processor currently.
     *
     * Multiple targets can be mapped with the separator `|`. Note that if there
     * are multiple properties, only the first language and type will be used.
     * There cannot be multiple targets when there is a pattern.
     *
     * The visibility of each data can be public or private, prefixed by "§".
     *
     * See readme for more information.
     *
     * @see \CSVImport\Mvc\Controller\Plugin\AutomapHeadersToMetadata
     *
     * @param array $fields
     * @param array $options Associative array of options:
     * - map (array) Complement for the default mapping.
     * - check_field (boolean) Recommended, else it will be done later.
     * - check_names_alone (boolean) Check property local name without prefix.
     * - single_target (boolean) Allows to output multiple targets from one string.
     * - output_full_matches (boolean) Returns the language and data types too.
     * - resource_type (string) Useless, except for quicker process.
     * @return array Associative array of all fields with the normalized name,
     * or with their normalized name, language and data types when option
     * "output_full_matches" is set, or null.
     */
    public function __invoke(array $fields, array $options = []): array
    {
        $defaultOptions = [
            'map' => [],
            // TODO Use only "false" default options.
            'check_field' => true,
            'check_names_alone' => true,
            'single_target' => false,
            'output_full_matches' => false,
            'resource_name' => null,
        ];
        $options += $defaultOptions;

        // TODO Check if this option not to check fields is still really used.
        $checkField = (bool) $options['check_field'];
        if (!$checkField) {
            return $this->automapNoCheckField($fields, $options);
        }

        // Return all values, even without matching normalized name, with the
        // same keys in the same order.
        $automaps = array_fill_keys(array_keys($fields), null);

        $fields = $this->cleanStrings($fields);

        $checkNamesAlone = (bool) $options['check_names_alone'];
        $singleTarget = (bool) $options['single_target'];
        $outputFullMatches = (bool) $options['output_full_matches'];

        $this->map = array_merge($this->map, $options['map']);
        unset($options['map']);

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
        // With "dc:", there are more names than labels, so add and filter them.
        $labelNames = array_keys($propertyLists['names']);
        $labelLabels = \SplFixedArray::fromArray(array_keys($propertyLists['labels']));
        $labelLabels->setSize(count($labelNames));
        $lists['labels'] = array_combine($labelNames, $labelLabels->toArray());
        $lists['lower_labels'] = array_filter(array_map('mb_strtolower', $lists['labels']));

        // Check names alone, like "Title" for "dcterms:title".
        if ($checkNamesAlone) {
            $lists['local_names'] = array_map(function ($v) {
                $w = explode(':', (string) $v);
                return end($w);
            }, $lists['names']);
            $lists['lower_local_names'] = array_map('mb_strtolower', $lists['local_names']);
            $lists['local_labels'] = array_map(function ($v) {
                $w = explode(':', (string) $v);
                return end($w);
            }, $lists['labels']);
            $lists['lower_local_labels'] = array_map('mb_strtolower', $lists['local_labels']);
        }

        $matches = [];

        foreach ($fields as $index => $fieldsMulti) {
            $fieldsMulti = $singleTarget || strpos($fieldsMulti, '~') !== false
                ? [$fieldsMulti]
                : array_filter(array_map('trim', explode('|', $fieldsMulti)));
            foreach ($fieldsMulti as $field) {
                $meta = preg_match(self::PATTERN, $field, $matches);
                if (!$meta) {
                    continue;
                }

                // TODO Add a check of the data types with the list of data types.

                $field = trim($matches['field']);
                $lowerField = mb_strtolower($field);

                // Check first with the specific auto-mapping list.
                foreach ($automapLists as $listName => $list) {
                    $toSearch = strpos($listName, 'lower_') === 0 ? $lowerField : $field;
                    $found = array_search($toSearch, $list, true);
                    if ($found) {
                        // The automap list is used to keep the sensitive value.
                        if ($outputFullMatches) {
                            $result = [];
                            $result['field'] = $automapList[$found];
                            $result['@language'] = empty($matches['language']) ? null : trim($matches['language']);
                            $result['datatypes'] = empty($matches['datatypes'])
                                ? []
                                : $this->normalizeDatatypes(array_filter(array_map('trim', explode(';', $matches['datatypes']))));
                            $result['is_public'] = empty($matches['visibility']) ? null : trim($matches['visibility']);
                            $result['pattern'] = empty($matches['pattern']) ? null : trim($matches['pattern']);
                            $result = $this->appendPattern($result);
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
                // the same keys in the same order, the process can be done in
                // one step.
                foreach ($lists as $listName => $list) {
                    $toSearch = strpos($listName, 'lower_') === 0 ? $lowerField : $field;
                    $found = array_search($toSearch, $list, true);
                    if ($found) {
                        if ($outputFullMatches) {
                            $result = [];
                            $result['field'] = $propertyLists['names'][$found];
                            $result['@language'] = empty($matches['language']) ? null : trim($matches['language']);
                            $result['datatypes'] = empty($matches['datatypes'])
                                ? []
                                : $this->normalizeDatatypes(array_filter(array_map('trim', explode(';', $matches['datatypes']))));
                            $result['is_public'] = empty($matches['visibility']) ? null : trim($matches['visibility']);
                            $result['pattern'] = empty($matches['pattern']) ? null : trim($matches['pattern']);
                            $result = $this->appendPattern($result);
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

    protected function automapNoCheckField(array $fields, array $options): array
    {
        // Return all values, even without matching normalized name, with the
        // same keys in the same order.
        $automaps = array_fill_keys(array_keys($fields), null);

        $fields = $this->cleanStrings($fields);

        $singleTarget = (bool) $options['single_target'];
        $outputFullMatches = (bool) $options['output_full_matches'];
        unset($options['map']);

        $matches = [];

        foreach ($fields as $index => $fieldsMulti) {
            $fieldsMulti = $singleTarget || strpos($fieldsMulti, '~') !== false
                ? [$fieldsMulti]
                : array_filter(array_map('trim', explode('|', $fieldsMulti)));
            foreach ($fieldsMulti as $field) {
                $meta = preg_match(self::PATTERN, $field, $matches);
                if (!$meta) {
                    continue;
                }

                $field = trim($matches['field']);

                if ($outputFullMatches) {
                    $result = [];
                    $result['field'] = $field;
                    $result['@language'] = empty($matches['language']) ? null : trim($matches['language']);
                    $result['datatypes'] = empty($matches['datatypes']) ? [] : array_filter(array_map('trim', explode(';', $matches['datatypes'])));
                    $result['is_public'] = empty($matches['visibility']) ? null : trim($matches['visibility']);
                    $result['pattern'] = empty($matches['pattern']) ? null : trim($matches['pattern']);
                    $result = $this->appendPattern($result);
                    $automaps[$index][] = $result;
                } else {
                    $automaps[$index][] = $field;
                }
            }
        }

        return $automaps;
    }

    /**
     * @todo Factorize with TransformSource::preparePattern()
     */
    protected function appendPattern(array $result): array
    {
        if (empty($result['pattern'])) {
            return $result;
        }

        $pattern = &$result['pattern'];

        // There is no escape for simple/double quotes.
        $isQuoted = (mb_substr($pattern, 0, 1) === '"' && mb_substr($pattern, -1) === '"')
            || (mb_substr($pattern, 0, 1) === "'" && mb_substr($pattern, -1) === "'");
        if ($isQuoted) {
            $result['raw'] = trim(mb_substr($pattern, 1, -1));
            return $result;
        }

        // Manage exceptions.
        $exceptions = ['{{ value }}', '{{ label }}', '{{ list }}'];

        if (in_array($pattern, $exceptions)) {
            $result['replace'][] = $pattern;
            return $result;
        }

        // Separate simple replacement strings (`{{/xpath/from/source}}` and the
        // twig filters (`{{ value|trim }}`).
        // The difference is the presence of spaces surrounding sub-patterns.
        // Sub-patterns cannot be nested, but combined.
        $matches = [];
        if (preg_match_all('~\{\{( value | label | list |\S+?|\S.*?\S)\}\}~', $pattern, $matches) !== false) {
            $result['replace'] = empty($matches[0]) ? [] : array_values(array_unique($matches[0]));
        }
        if (preg_match_all('~\{\{ ([^{}]+) \}\}~', $pattern, $matches) !== false) {
            $result['twig'] = empty($matches[0]) ? [] : array_unique($matches[0]);
            // Avoid to use twig when a replacement is enough.
            $result['twig'] = array_values(array_diff($result['twig'], $exceptions));
        }

        return $result;
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
     * Append the vocabulary Dublin Core with prefix "dc" too, for simplicity.
     */
    protected function listTerms(): array
    {
        $result = [];

        $vocabularies = $this->api->search('vocabularies')->getContent();
        /** @var \Omeka\Api\Representation\VocabularyRepresentation $vocabulary */
        foreach ($vocabularies as $vocabulary) {
            $properties = $vocabulary->properties();
            if (empty($properties)) {
                continue;
            }
            foreach ($properties as $property) {
                $term = $property->term();
                $result['names'][$term] = $term;
                $name = $vocabulary->label() . ':' . $property->label();
                if (isset($result['labels'][$name])) {
                    $result['labels'][$vocabulary->label() . ':' . $property->label() . ' (#' . $property->id() . ')'] = $term;
                } else {
                    $result['labels'][$vocabulary->label() . ':' . $property->label()] = $term;
                }
            }
        }

        // Add the special prefix "dc:" for "dcterms:", that is not so uncommon.
        $vocabulary = $vocabularies[0];
        $properties = $vocabulary->properties();
        foreach ($properties as $property) {
            $term = $property->term();
            $termDc = 'dc:' . substr($term, 8);
            $result['names'][$termDc] = $term;
        }

        return $result;
    }

    /**
     * Normalize datatypes (full standard name).
     *
     * It converts custom vocab labels into id.
     */
    protected function normalizeDatatypes(array $datatypes): array
    {
        if (!count($datatypes)) {
            return [];
        }

        foreach ($datatypes as &$datatype) {
            $datatype = $this->bulk->getDataTypeName($datatype);
        }
        unset($datatype);

        return array_filter(array_unique($datatypes));
    }

    /**
     * Clean and trim all whitespace, and remove spaces around colon.
     *
     * It fixes whitespaces added by some spreadsheets before or after a colon.
     */
    protected function cleanStrings(array $strings): array
    {
        return array_map(function ($string) {
            return preg_replace('~\s*:\s*~', ':', $this->cleanUnicode($string));
        }, $strings);
    }

    /**
     * Clean and trim all whitespaces, included the unicode ones inside string.
     */
    protected function cleanUnicode($string): string
    {
        return trim(preg_replace('/[\s\h\v[:blank:][:space:]]+/u', ' ', (string) $string));
    }
}
