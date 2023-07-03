<?php declare(strict_types=1);

namespace BulkImport\Mvc\Controller\Plugin;

use Laminas\Log\Logger;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Api\Manager as ApiManager;
use Omeka\Mvc\Controller\Plugin\Messenger;

/**
 * TODO @deprecated Use MetaMapper or MetaMapperConfig.
 */
class AutomapFields extends AbstractPlugin
{
    /**
     * The pattern checks five components:
     * - a field (term or keyword): `dcterms:title`
     * - one or more datatypes: `^^datatype ^^literal`,
     * - a language: `@fra`,
     * - a visibility, private or public: `§private`,
     * - a transformation pattern: `~pattern`.
     * The field should be the first component and is required.
     * The pattern should be the last component and is allowed only when there
     * is a single target field.
     * Other components can be in any order.
     *
     * For custom vocabs, it is possible to use the name instead of the id. It
     * should be wrapped by quotes or double quotes: `^^customvocab:"Liste des établissements"`.
     * It will be converted internally. Warning: custom vocab labels can be
     * shared, but the labels can be updated by end user.
     *
     * The use of multiple data types separated with a ";" is no more supported.
     * Use a "^^" for each  data type. A warning is displayed when it is used.
     * Only real life old patterns are checked.
     */
    const PATTERN = '#'
        // Requires a term/keyword/label ("dcterms:title" or "Rights holder"
        // or "Resource class" or "Établissement") at beginning.
        . '^\s*+(?<field>[^@§^~|\n\r]+)'
        // Argumens in any order:
        . '(?<args>(?:'
        // Check a data type (^^resource:item or ^^customvocab:"Liste des établissements")
        // or multiple data types (^^customvocab:xxx ^^resource:item ^^literal).
        // To get each datatype separately is complex or slow, so explode them
        // later with the same pattern in a second time.
        // Another way is to set the list of all data types here.
        . '(?:\s*\^\^(?<datatype>(?:customvocab:(?:"[^\n\r"]+"|\'[^\n\r\']+\')|[a-zA-Z_][\w:-]*)))'
        // Check a language + country (@fra or @fr-Fr or @en-GB-oed, etc.).
        // See application/asset/js/admin.js for a complex check.
        // @link http://stackoverflow.com/questions/7035825/regular-expression-for-a-language-tag-as-defined-by-bcp47
        . '|(?:\s*@(?<language>(?:(?:[a-zA-Z0-9]+-)*[a-zA-Z]+|)))'
        // Check visibility (§private).
        . '|(?:\s*§(?<visibility>private|public|))'
        // No limit for datatypes number, but commonly until 3. So the number of
        // components is not limited.
        //  TODO Make language and visibility components not repeatable.
        . ')*)?'
        // A replacement pattern for optional transformation of the source:
        // ~ {{ value|trim }}. It can be a default value, enclosed or not by
        // quotes: ~ "Public domain" (internal quotes are not escaped).
        . '(?:\s*~\s*(?<pattern>.*))?'
        // Remove final spaces too.
        . '\s*$'
        . '#';

    /**
     * Allows to extract each datatype from the args previously extracted.
     * The pattern is a part of the main pattern above.
     */
    const PATTERN_DATATYPES = '#\^\^(?<datatype>(?:customvocab:(?:"[^\n\r"]+"|\'[^\n\r\']+\')|[a-zA-Z_][\w:-]*))#';

    /**
     * Check for old pattern, no more allowed:
     * - prefix with space,
     * - multiple datatypes separated with a ";",
     * - unwrapped custom vocab label.
     */
    const OLD_PATTERN_CHECK = '#'
        . '(?<prefix_with_space>(?:\^\^|@|§)\s)'
        . '|(?<datatypes_semicolon>\^\^\s*[a-zA-Z][^\^@§~\n\r;]*;)'
        . '|(?<unwrapped_customvocab_label>(?:\^\^|;)\s*customvocab:[^\d"\';\^\n]+)'
        // Unicode is used for custom vocab labels.
        . '#u';

    /**
     * @var array
     */
    protected $map;

    /**
     * @var ApiManager
     */
    protected $api;

    /**
     * @var Bulk
     */
    protected $bulk;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var Messenger
     */
    protected $messenger;

    /**
     * @todo Use messenger only in view and front-end.
     */
    public function __construct(
        array $map,
        Logger $logger,
        Messenger $messenger,
        ApiManager $api,
        Bulk $bulk
    ) {
        $this->map = $map;
        $this->logger = $logger;
        $this->messenger = $messenger;
        $this->api = $api;
        $this->bulk = $bulk;
    }

    /**
     * Automap a list of field names with a standard Omeka metadata names.
     *
     * This is a simplified but improved version of the full automap of an old
     * version of the automap of the module CSVImport. It returns all the input
     * fields, with field name, identified metadata, language and datatype.
     * It manages language, datatype and visibility, as in "dcterms:title ^^resource:item @fra §private",
     * following some conventions.
     * Furthermore, user mappings are replaced by the file /data/mappings/fields_to_metadata.php.
     * Finally, a pattern (prefixed with "~") can be appended to transform the
     * value.
     *
     * Default supported datatypes are the ones managed by omeka (cf. config[data_types]:
     * literal, resource, uri, resource:item, resource:itemset, resource:media.
     * Supported datatypes if modules are present:
     * numeric:timestamp, numeric:integer, numeric:duration, geometry,
     * geography, geography:coordinates, geometry:coordinates, geometry:position.
     * The prefixes can be omitted, so item, itemset, media, timestamp, integer,
     * duration, geography, geometry.
     * Datatypes of other modules are supported too (Custom Vocab,
     * Value Suggest, DataTypeRdf, Numeric Data Types):
     * - customvocab:xxx (where xxx is the id, or the label wrapped with quotes
     *   or double quotes),
     * - valuesuggest:yyy,
     * - html,
     * - xml,
     * - boolean,
     * - numeric:timestamp,
     * - numeric:integer,
     * - etc.
     * with or without prefix, etc.
     * There may be multiple data types. In that case, they are checked in the
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
     * @todo Make the map and list of allowed fields an option, so it will be usable with assets, users, etc.? In fact, resources is the exception, so create a simple generic automap fields? A two-steps process?
     *
     * @param array $fields
     * @param array $options Associative array of options:
     * - map (array) Complement for the default mapping.
     * - check_field (boolean): Allows to check a map without field, for example
     *   when defining default options for the meta mapper.
     * - check_names_alone (boolean): Check property local name without prefix.
     * - single_target (boolean): Allows to output multiple targets from one string.
     * - output_full_matches (boolean): Returns the language and data types too.
     * - output_property_id (boolean): Returns the property id when the field is
     *   a property. Requires output_full_matches.
     * @return array Associative array of all fields with the normalized name,
     * or with their normalized name, data types and language when option
     * "output_full_matches" is set, and property id when "ouput_property_id" is
     * set, or null.
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
            'output_property_id' => false,
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

        // Warning: patterns ae modified too.
        $fields = $this->cleanStrings($fields);

        $checkNamesAlone = (bool) $options['check_names_alone'];
        $singleTarget = (bool) $options['single_target'];
        $outputFullMatches = (bool) $options['output_full_matches'];
        $outputProperty = $outputFullMatches && $options['output_property_id'];

        $this->map = array_merge($this->map, $options['map']);
        unset($options['map']);

        // Prepare the standard lists to check against.
        $lists = [];
        $automapLists = [];

        // Prepare the list of names and labels one time to speed up process.
        $propertyLists = $this->listTerms();

        // The automap list is the file mapping combined with itself, with a
        // lower case version. It does not contains properties.
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
        $lists['labels'] = array_combine($labelNames, array_map('strval', $labelLabels->toArray()));
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
                ? [trim($fieldsMulti)]
                : array_filter(array_map('trim', explode('|', $fieldsMulti)));
            foreach ($fieldsMulti as $field) {
                $this->isOldPattern($field);
                $meta = preg_match(self::PATTERN, $field, $matches);
                if (!$meta) {
                    continue;
                }

                if ($outputFullMatches) {
                    $matches['datatype'] = $this->explodeDataTypesInMatches($matches);
                }

                $field = trim($matches['field']);
                $lowerField = mb_strtolower($field);

                // Check first with the specific auto-mapping list.
                foreach ($automapLists as $listName => $list) {
                    $toSearch = strpos($listName, 'lower_') === 0 ? $lowerField : $field;
                    $found = array_search($toSearch, $list, true);
                    if ($found) {
                        // The automap list allows to keep case sensitivity.
                        if ($outputFullMatches) {
                            $result = [];
                            $result['field'] = $automapList[$found];
                            $result['datatype'] = $this->normalizeDataTypes($matches['datatype']);
                            $result['language'] = empty($matches['language']) ? null : trim($matches['language']);
                            $result['is_public'] = empty($matches['visibility']) ? null : trim($matches['visibility']);
                            $result['pattern'] = empty($matches['pattern']) ? null : trim($matches['pattern']);
                            if ($outputProperty) {
                                $result['property_id'] = $result['field'] ? $this->bulk->getPropertyId($result['field']) : null;
                            }
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
                            $result['datatype'] = $this->normalizeDataTypes($matches['datatype']);
                            $result['language'] = empty($matches['language']) ? null : trim($matches['language']);
                            $result['is_public'] = empty($matches['visibility']) ? null : trim($matches['visibility']);
                            $result['pattern'] = empty($matches['pattern']) ? null : trim($matches['pattern']);
                            if ($outputProperty) {
                                $result['property_id'] = $result['field'] ? $this->bulk->getPropertyId($result['field']) : null;
                            }
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

    /**
     * Automap entry without check of field, that may be empty.
     */
    protected function automapNoCheckField(array $fields, array $options): array
    {
        // Return all values, even without matching normalized name, with the
        // same keys in the same order.
        $automaps = array_fill_keys(array_keys($fields), null);

        $fields = $this->cleanStrings($fields);

        $singleTarget = (bool) $options['single_target'];
        $outputFullMatches = (bool) $options['output_full_matches'];
        $outputProperty = $outputFullMatches && $options['output_property_id'];
        unset($options['map']);

        $matches = [];

        // The field is not required, so make it optional.
        $pattern = substr_replace(self::PATTERN, '#^\s*+(?<field>[^@§^~|\n\r]+)?', 0, 30);

        foreach ($fields as $index => $fieldsMulti) {
            $fieldsMulti = $singleTarget || strpos($fieldsMulti, '~') !== false
                ? [$fieldsMulti]
                : array_filter(array_map('trim', explode('|', $fieldsMulti)));
            foreach ($fieldsMulti as $field) {
                $this->isOldPattern($field);
                $meta = preg_match($pattern, $field, $matches);
                if (!$meta) {
                    continue;
                }

                $field = trim($matches['field']);

                if ($outputFullMatches) {
                    $result = [];
                    $result['field'] = $field;
                    $result['datatype'] = $this->normalizeDataTypes($this->explodeDataTypesInMatches($matches));
                    $result['language'] = empty($matches['language']) ? null : trim($matches['language']);
                    $result['is_public'] = empty($matches['visibility']) ? null : trim($matches['visibility']);
                    $result['pattern'] = empty($matches['pattern']) ? null : trim($matches['pattern']);
                    if ($outputProperty) {
                        $result['property_id'] = $result['field'] ? $this->bulk->getPropertyId($result['field']) : null;
                    }
                    $result = $this->appendPattern($result);
                    $automaps[$index][] = $result;
                } else {
                    $automaps[$index][] = $field;
                }
            }
        }

        return $automaps;
    }

    protected function isOldPattern(?string $field): bool
    {
        if (!$field || !preg_match(self::OLD_PATTERN_CHECK, $field)) {
            return false;
        }
        // @todo Use logger only in back-end and messenger only in front-end. Possibly detailled messages.
        $message = new \Omeka\Stdlib\Message(
            'The destination pattern "%s" follows the old format. To avoid issue, you should update it replacing the data type separator";" by "^^", removing spaces after "^^", "@" and "§", and wrapping custom vocab labels with quote or double quotes.', // @translate
            $field
        );
        $this->logger->warn($message);
        $this->messenger->addWarning($message);
        return true;
    }

    /**
     * Extract each data types inside regex matches.
     */
    protected function explodeDataTypesInMatches(array $matches): array
    {
        // Extract each data types separately only when needed.
        if (isset($matches['args']) && $matches['args'] !== ''
            && isset($matches['datatype']) && $matches['datatype'] !== ''
        ) {
            $matchesDataTypes = [];
            preg_match_all(self::PATTERN_DATATYPES, $matches['args'], $matchesDataTypes, PREG_SET_ORDER, 0);
            $matches['datatype'] = array_column($matchesDataTypes, 'datatype');
        } else {
            $matches['datatype'] = [];
        }
        return $matches['datatype'];
    }

    /**
     * @todo Factorize with MetaMapper::preparePattern()
     * @see \BulkImport\Stdlib\MetaMapper::preparePattern()
     */
    protected function appendPattern(array $result): array
    {
        if (empty($result['pattern'])) {
            return $result;
        }

        $pattern = $result['pattern'];

        // Next code is the same in the two methods.

        // There is no escape for simple/double quotes.
        $isQuoted = (mb_substr($pattern, 0, 1) === '"' && mb_substr($pattern, -1) === '"')
            || (mb_substr($pattern, 0, 1) === "'" && mb_substr($pattern, -1) === "'");
        if ($isQuoted) {
            $result['raw'] = trim(mb_substr($pattern, 1, -1));
            $result['pattern'] = null;
            return $result;
        }

        // Manage exceptions.
        // TODO Remove twig / replacement exception (value, label, and list).
        $exceptions = ['{{ value }}', '{{ label }}', '{{ list }}'];

        if (in_array($pattern, $exceptions)) {
            $result['replace'][] = $pattern;
            return $result;
        }

        // Separate simple replacement strings (`{{/xpath/from/source}}` and the
        // twig filters (`{{ value|trim }}`).
        // The difference is the presence of spaces surrounding sub-patterns.
        // Sub-patterns cannot be nested for now, but combined.
        $matches = [];
        if (preg_match_all('~\{\{( value | label | list |\S+?|\S.*?\S)\}\}~', $pattern, $matches) !== false) {
            $result['replace'] = empty($matches[0]) ? [] : array_values(array_unique($matches[0]));
        }

        // In order to allow replacements inside twig patterns, the replacements
        // are replaced (since replacements are done before twig transforms).
        if (empty($result['replace'])) {
            $replacements = [];
            $cleanPattern = $pattern;
        } else {
            foreach ($result['replace'] as $i => $replacement) {
                $replacements[$replacement] = '__To_Be_Replaced__' . $i . '__';
            }
            $cleanPattern = str_replace(array_keys($replacements), array_values($replacements), $pattern);
        }

        // Instead of complex regex to check pattern not nested, use a
        // replacement before and after.
        // If not possible, use the basic check, not nested.

        // Try nested pattern.

        // Replace strings "{{ " and " }}" to exclude first, with a single
        // character not present in the string.
        $missings = ['§', '¤', '°', '¸',  '░', '▓', '▒', '█', '▄','▀'];
        $cleanerPattern = $cleanPattern;
        $skips = [];
        foreach (['{{ ', ' }}'] as $skip) {
            foreach ($missings as $key => $character) {
                if (mb_strpos($cleanerPattern, $character) === false) {
                    $skips[$skip] = $character;
                    unset($missings[$key]);
                    break;
                }
            }
        }

        if (count($skips) === 2) {
            // Explode everything.
            $cleanerPattern = str_replace(array_keys($skips), array_values($skips), $cleanerPattern);
            $regex = '~' . $skips['{{ '] . '([^' . $skips['{{ '] . $skips[' }}'] . ']+)' . $skips[' }}'] . '~';
            if (preg_match_all($regex, $cleanerPattern, $matches) !== false) {
                $result['twig'] = empty($matches[0]) ? [] : array_unique($matches[0]);
                foreach ($result['twig'] as &$twig) {
                    $twig = str_replace(array_values($skips), array_keys($skips), $twig);
                }
                unset($twig);
                // Avoid to use twig when a replacement is enough.
                $result['twig'] = array_values(array_diff($result['twig'], $exceptions));
                // Keep original replacements values.
                if (!empty($replacements)) {
                    foreach ($result['twig'] as $key => $twigPattern) {
                        $originalPattern = str_replace(array_values($replacements), array_keys($replacements), $twigPattern);
                        $result['twig'][$key] = $originalPattern;
                        // When there are replacements, the twig transformation
                        // should be done on real value or on a transformed filter.
                        $result['twig_has_replace'][$key] = $twigPattern !== $originalPattern;
                    }
                }
            }
            return $result;
        }

        // Try not-nested pattern.

        // Explode everything except single "{" or "}".
        // Issue: does not manage "{{ replace('{a': 'b'}) }}".
        if (preg_match_all('~\{\{ ([^{}]+) \}\}~', $cleanPattern, $matches) !== false) {
            $result['twig'] = empty($matches[0]) ? [] : array_unique($matches[0]);
            // Avoid to use twig when a replacement is enough.
            $result['twig'] = array_values(array_diff($result['twig'], $exceptions));
            // Keep original replacements values.
            if (!empty($replacements)) {
                foreach ($result['twig'] as $key => $twigPattern) {
                    $originalPattern = str_replace(array_values($replacements), array_keys($replacements), $twigPattern);
                    $result['twig'][$key] = $originalPattern;
                    // When there are replacements, the twig transformation
                    // should be done on real value or on a transformed filter.
                    $result['twig_has_replace'][$key] = $twigPattern !== $originalPattern;
                }
            }
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
     * Check and normalize datatypes.
     *
     * - Remove inexistant data types;
     * - Replace short data types with the full standard name;
     * - Replace custom vocab labels with id.
     */
    protected function normalizeDataTypes(array $datatypes): array
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
