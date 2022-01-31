<?php declare(strict_types=1);

/*
 * Copyright 2017-2022 Daniel Berthereau
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software. You can use, modify and/or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software’s author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user’s attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software’s suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

namespace BulkImport\Mvc\Controller\Plugin;

use Laminas\Log\Logger;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;

/**
 * @todo Separate xml and json process into two plugins and make this one an abstract one.
 * @todo Merge with AdvancedResourceTemplate Mapper.
 */
class TransformSource extends AbstractPlugin
{
    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * @var \BulkImport\Mvc\Controller\Plugin\AutomapFields
     */
    protected $automapFields;

    /**
     * @var \BulkImport\Mvc\Controller\Plugin\Bulk
     */
    protected $bulk;

    /**
     * @var array
     */
    protected $variables = [];

    /**
     * @var array
     */
    protected $configSections = [];

    /**
     * @var ?string
     */
    protected $config;

    /**
     * @var array
     */
    protected $normConfig = [];

    public function __construct(
        Logger $logger,
        AutomapFields $automapFields,
        Bulk $bulk
    ) {
        $this->logger = $logger;
        $this->automapFields = $automapFields;
        $this->bulk = $bulk;
    }

    /**
     * Prepare a config to simplify any import into Omeka and transform a source.
     *
     * It can be used as headers of a spreadsheet, or in an import config, or to
     * extract metadata from files.
     *
     * It contains a list of mappings between source data and destination data.
     * For example:
     * ```
     * source or xpath = dcterms:title @fr-fr ^^literal §private ~ pattern for the {{ value|trim }} with {{/source/record/data}}
     * ```
     * will be converted into:
     * ```php
     * [
     *      'from' => 'source or xpath',
     *      'to' => [
     *          'field' => 'dcterms:title',
     *          'property_id' => 1,
     *          'type' => 'literal',
     *          '@language' => 'fr-fr',
     *          'is_public' => false,
     *          'pattern' => 'pattern for the {{ value|trim }} with {{/source/record/data}}',
     *          'replace' => [
     *              '{{/source/record/data}}',
     *          ],
     *          'twig' => [
     *              '{{ value|trim }}',
     *          ],
     *      ],
     * ]
     * ```
     *
     * A config is composed of multiple lines. The sections like "[info]" are
     * managed: the next lines will be a sub-array.
     *
     * Each line is formatted with a source and a destination separated with the
     * sign "=". The format of each part (left and right of the "=") of each
     * line is checked, but not if it has a meaning.
     *
     * The source part may be the key in an array, or in a sub-array (`dcterms:title.0.@value`),
     * or a xpath (used when the input is xml).
     *
     * The destination part is an automap field. It has till five components and
     * only the first is required.
     *
     * The first must be the destination field. The field is one of the key used
     * in the json representation of a resource, generally a property, but other
     * metadata too ("o:resource_template", etc.). It can be a sub-field too, in
     * particular to specify related resources when importing an item:
     * `o:media[o:original_url]`, `o:media[o:ingester]`, or `o:item_set[dcterms:identifier]`.
     *
     * The next three components are specific to properties and can occur in any
     * order and are prefixed with a code, similar to some rdf representations.
     * The language is prefixed with a `@`: `@fr-FR` or `@fra`.
     * The data type is prefixed with a `^^`: `^^resource:item` or `^^customvocab:Liste des établissements`.
     * The visibility is prefixed with a `§`: `§public` or `§private`.
     *
     * The last component is a pattern used to transform the source value when
     * needed. It is prefixed with a `~`. It can be a simple replacement string,
     * or a complex pattern with some twig commands.
     *
     * A simple replacement string is a pattern with some replacement values:
     * ```
     * geonameId = dcterms:identifier ^^uri ~ https://www.geonames.org/{{ value }}
     * ```
     * The available replacement patterns are: the current source value `{{ value }}`
     * and any source query `{{xxx}}`, for example a xpath `{{/doc/str[@name="ppn_z"]}}`.
     *
     * `{{ value }}` and `{{value}}` are not the same: the first is the current
     * value extracted from the source part (stored as variable) and the second
     * is the key used to extract the value with the key `value` from a source
     * data array.
     *
     * For default values, the right part may be a simple string starting and
     * ending with a simple or double quotes, in which case the left part is the
     * destination.
     * ```
     * dcterms:license = "Public domain"
     * dcterms:license = ^^literal ~ "Public domain"
     * dcterms:license = dcterms:license ^^literal ~ "Public domain"
     * ```
     *
     * For complex transformation, the pattern may be build as a simplified twig
     * one: this is a string where the values between `{{ ` and ` }}` are
     * converted with some basic filters. For example, `{{ value|trim }}` takes
     * the value from the source and trims it. The space after `{{` and before
     * `}}` is required.
     * Only some common twig filters are supported: `abs`, `capitalize`, `date`,
     * `e`, `escape`, `first`, `format`, `last`, `length`, `lower`, `slice`,
     * `split`, `striptags`, `title`, `trim`, `upper`, `url_encode`. Only some
     * common arguments of these filters are supported. Twig filters can be
     * combined, but not nested.
     *
     * An example of the use of the filters is the renormalization of a date,
     * here from a xml unimarc source `17890804` into a standard ISO 8601
     * numeric date time `1789-08-04`:
     * ```
     * /record/datafield[@tag="103"]/subfield[@code="b"] = dcterms:valid ^^numeric:timestamp ~ {{ value|trim|slice(1,4) }}-{{ value|trim|slice(5,2) }}-{{ value|trim|slice(7,2) }}
     * ```
     *
     * The twig filters can be used in conjunction with simple replacements. In
     * that case, they are processed after the replacements.
     *
     * The source can be `~` too, in which case the destination is a composite
     * of multiple sources:
     * ```
     * ~ = dcterms:spatial ~ Coordinates: {{lat}}/{{lng}}
     * ```
     * Here, `{{lat}}` and `{{lng}}` are values extracted from the source.
     *
     * The prefix `~` must not be used in other components or in the left part
     * for now.
     *
     * For the autofiller of the module Advanced resource template, a special
     * line can be used to determine the autofiller: `[service:subservice #variant] = label`.
     * The label is optional, but the "=" is required when there is no label in
     * order to make a distinction with standard sections.
     * Two special sources can be used: `service_url = https://xxx`, to set the
     * endpoint of the web service, and `service_query = ?username=johnsmith&lang={language}&q={query}`
     * to specify the query to use to fetch data.
     * Two special destinations are available too: `{{ label }}` is used to get
     * the main title of a resource; `{{ list }}` is used to specify the base path
     * of the resources when it is not the root and allows to loop them.
     *
     * If the source or the destination is not determined, is is returned as
     * a raw pattern.
     */
    public function __invoke(): self
    {
        return $this;
    }

    public function addVariable(string $name, $value): self
    {
        $this->variables[$name] = $value;
        return $this;
    }

    public function setVariables(array $variables): self
    {
        $this->variables = $variables;
        return $this;
    }

    public function getVariables(): array
    {
        return $this->variables;
    }

    /**
     * Set the sections that contains raw data, pattern data, or full mapping.
     */
    public function setConfigSections(array $configSections): self
    {
        $this->configSections = $configSections;
        return $this;
    }

    public function setConfig(?string $config): self
    {
        $this->config = $config;
        $this->normalizeConfig();
        return $this;
    }

    /**
     * Allow to use a generic config completed by a specific one.
     */
    public function setConfigs(string ...$configs): self
    {
        $mergedMappings = [];
        foreach (array_filter($configs) as $config) {
            $this->config = $config;
            $this->normalizeConfig();
            // Merge the sections, but don't replace them as a whole, so no
            // array_merge_recursive() neither array_replace_recursive().
            foreach ($this->normConfig as $key => $value) {
                $mergedMappings[$key] = empty($mergedMappings[$key])
                    ? $value
                    : array_merge($mergedMappings[$key], $value);
            }
        }
        // Avoid to duplicate data.
        // Don't do array_unique on key values.
        // The function array_unique() doesn't work with multilevel arrays.
        foreach ($mergedMappings as $key => $mapping) {
            if (is_numeric(key($mapping))) {
                $mergedMappings[$key] = array_values(array_map('unserialize', array_unique(array_map('serialize', $mapping))));
            }
        }
        $this->normConfig = $mergedMappings;
        return $this;
    }

    public function getNormalizedConfig(): array
    {
        return $this->normConfig;
    }

    public function getSection(string $section): array
    {
        return $this->normConfig[$section] ?? [];
    }

    /**
     * Only the settings for the namable sections can be get, of course.
     */
    public function getSectionSetting(string $section, string $name, $default = null)    {
        if (!empty($this->configSections[$section])
            && $this->configSections[$section] === 'mapping'
            && !empty($this->normConfig[$section])
        ) {
            foreach ($this->normConfig[$section] as $fromTo) {
                if ($name === $fromTo['from']) {
                    return $fromTo['to'];
                }
            }
            return $default;
        }
        return $this->normConfig[$section][$name] ?? $default;
    }

    /**
     * Convert all mappings from a section "mapping".
     *
     * This method should be used when a mapping source ("from") is used
     * multiple times.
     * @param bool $isDefault When true, the target value "to" is added to the
     * resource without using data.
     */
    public function convertMappingSection(string $section, array $resource, ?array $data, bool $isDefault = false): array
    {
        if (empty($this->configSections[$section]) || $this->configSections[$section] !== 'mapping') {
            return $resource;
        }
        if ($isDefault || empty($data)) {
            $flatData = [];
            $fields = [];
        } else {
            $flatData = $this->flatArray($data);
            $fields = $this->extractFields($data);
        }
        foreach ($this->getSection($section) as $fromTo) {
            $from = $fromTo['from'];
            $to = $fromTo['to'] ?? null;
            if (empty($from) || empty($to)) {
                continue;
            }
            $result = [];
            if ($isDefault) {
                $converted = $this->convertTargetToString($from, $to);
                if ($converted === [] || $converted === '' || $converted === null) {
                    continue;
                }
                $result[] = $converted;
            } else {
                // Check for associative value. "from" is a full path to data:
                // [key.to.data => "value"]
                if (array_key_exists($from, $flatData)) {
                    $values = $flatData[$from];
                }
                // Check for a repetitive value, starting with "fields[].".
                elseif (mb_substr($from, 0, 9) === 'fields[].') {
                    $values = $fields[mb_substr($from, 9)] ?? [];
                } else {
                    continue;
                }
                if ($values === [] || $values === '' || $values === null) {
                    continue;
                }
                $values = is_array($values) ? array_values($values) : [$values];
                foreach ($values as $value) {
                    // Allows to use multiple mappings in one pattern, managing fields.
                    $source = $flatData;
                    $source[$from] = $value;
                    $converted = $this->convertTargetToString($from, $to, $source);
                    if ($converted === [] || $converted === '' || $converted === null) {
                        continue;
                    }
                    $result[] = $converted;
                }
            }
            if ($result === []) {
                continue;
            }
            $resource[$to['dest']] = empty($resource[$to['dest']])
                ? $result
                : array_merge($resource[$to['dest']], $result);
        }
        return $resource;
    }

    /**
     * Convert a single field into a string.
     */
    public function convertToString(string $section, string $name, ?array $data = null): ?string
    {
        $to = $this->getSectionSetting($section, $name);
        return $to ? $this->convertTargetToString($name, $to, $data) : null;
    }

    /**
     * Convert a single field from the config into a string.
     *
     * Example:
     * ```php
     * $this->variables = [
     *     'endpoint' => 'https://example.com',
     *     // Set for current value and default output when there is no pattern.
     *     'value' => 'xxx',
     * ];
     * $from = 'xxx';
     * $to = [
     *     'pattern' => '{{ endpoint }}/api{{itemLink}}',
     *     // The following keys are automatically created from the pattern.
     *     'replace' => ['{{itemLink}}']
     *     'twig' => ['{{ endpoint }}'],
     * ];
     * $data = [
     *     'itemLink' => '/id/150',
     * ];
     * $output = 'https://example.com/api/id/1850'
     * ```
     *
     * @param string $from The key where to get the data.
     * @param array|string $to If array, contains the pattern to use, else the
     * static value itself.
     * @param array $data The resource from which extract the data, if needed,
     * and any other value.
     * @return string The converted value. Without pattern, return the key
     * "value" from the variables.
     */
    protected function convertTargetToString($from, $to, ?array $data = null): ?string
    {
        if (is_null($to) || is_string($to)) {
            return $to;
        }

        $flatData = $this->flatArray($data);
        $fromValue = $flatData[$from] ?? null;
        $this->addVariable('value', $fromValue);

        if (!isset($to['pattern']) || !strlen($to['pattern'])) {
            if (is_null($fromValue)) {
                return null;
            }
            if (is_scalar($fromValue)) {
                return (string) $fromValue;
            }
            // The from should be a key to a string, but manages the case of an
            // array.
            return (string) reset($fromValue);
        }

        if (isset($to['raw']) && strlen($to['raw'])) {
            return (string) $to['raw'];
        }

        // When there are data, a query can be used for each variable.
        $replace = [];
        if (!empty($to['replace'])) {
            if ($data) {
                foreach ($to['replace'] as $wrappedQuery) {
                    // Manage the exceptions: there is no value here, neither label or list.
                    if (in_array($wrappedQuery, ['{{ value }}', '{{ label }}', '{{ list }}'])) {
                        $replace[$wrappedQuery] = '';
                        continue;
                    }
                    $query = mb_substr($wrappedQuery, 2, -2);
                    $replace[$wrappedQuery] = $flatData[$query] ?? '';
                }
            } else {
                $replace = array_fill_keys($to['replace'], '');
            }
        }

        // Wrap vars to quick process for simple variables without twig filters.
        if (!empty($to['twig'])) {
            foreach ($this->variables as $name => $value) {
                if (!is_scalar($value)) {
                    continue;
                }
                $replace["{{ $name }}"] = $value;
                if (($pos = array_search("{{ $name }}", $to['twig'])) !== false) {
                    unset($to['twig'][$pos]);
                }
            }
        }

        $value = $replace
            ? str_replace(array_keys($replace), array_values($replace), $to['pattern'])
            : $to['pattern'];

        if (!empty($to['twig'])) {
            $value = $this->twig($value, $this->variables, $to['twig']);
        }

        return $value;
    }

    /**
     * Convert a value into another value via twig filters.
     *
     * Only some common filters and some filter arguments are managed.
     */
    protected function twig(string $pattern, array $twigVars, array $twig): string
    {
        static $patterns = [];

        $matches = [];

        // Prepare the static vars regex for twig.
        if (count($twigVars)) {
            $serialized = serialize($twigVars);
            if (!isset($patterns[$serialized])) {
                $patterns[$serialized] = implode('|', array_map(function($v) {
                    $v = (string) $v;
                    return mb_substr($v, 0, 3) === '{{ '
                        ? preg_quote(mb_substr($v, 3, -3), '~')
                        : preg_quote($v, '~');
                }, array_keys($twigVars))) . '|';
            }
            $patternVars = $patterns[$serialized];
        } else {
            $patternVars = '';
        }

        $extractList = function ($args) use ($patternVars, $twigVars) {
            $matches = [];
            preg_match_all('~\s*(?<args>' . $patternVars . '"[^"]*?"|\'[^\']*?\')\s*,?\s*~', $args, $matches);
            return array_map(function ($arg) use ($twigVars) {
                // If this is a var, take it, else this is a string, so remove
                // the quotes.
                return $twigVars['{{ ' . $arg . ' }}'] ?? mb_substr($arg, 1, -1);
            }, $matches['args']);
        };

        $twig = array_fill_keys($twig, '');
        foreach ($twig as $query => &$output) {
            $v = '';
            $filters = array_filter(array_map('trim', explode('|', mb_substr((string) $query, 3, -3))));
            // The first filter may not be a filter, but a variable. A variable
            // cannot be a reserved keyword.
            foreach ($filters as $filter) switch ($filter) {
                case 'abs':
                    $v = is_numeric($v) ? abs($v) : $v;
                    break;
                case 'capitalize':
                    $v = ucfirst($v);
                    break;
                case 'e':
                case 'escape':
                    $v = htmlspecialchars($v, ENT_COMPAT | ENT_HTML5);
                    break;
                case 'first':
                    $v = is_array($v) ? reset($v) : mb_substr((string) $v, 0, 1);
                    break;
                case 'last':
                    $v = is_array($v) ? array_pop($v) : mb_substr((string) $v, -1);
                    break;
                case 'length':
                    $v = is_array($v) ? count($v) : mb_strlen($v);
                    break;
                case 'lower':
                    $v = mb_strtolower($v);
                    break;
                case 'striptags':
                    $v = strip_tags($v);
                    break;
                case 'title':
                    $v = ucwords($v);
                    break;
                case 'trim':
                    $v = trim($v);
                    break;
                case 'upper':
                    $v = mb_strtoupper($v);
                    break;
                case 'url_encode':
                    $v = rawurlencode($v);
                    break;
                // date().
                case preg_match('~date\s*\(\s*["|\'](?<format>[^"\']+?)["|\']\s*\)~', $filter, $matches) > 0:
                    try {
                        $v = @date($matches['format'], @strtotime($v));
                    } catch (\Exception $e) {
                        // Nothing.
                    }
                    break;
                // format().
                case preg_match('~format\s*\(\s*(?<args>.*?)\s*\)~', $filter, $matches) > 0:
                    $args = $extractList($matches['args']);
                    if ($args) {
                        try {
                            $v = @vsprintf($v, $args);
                        } catch (\Exception $e) {
                            // Nothing.
                        }
                    }
                    break;
                // slice().
                case preg_match('~slice\s*\(\s*(?<start>-?\d+)\s*,\s*(?<length>-?\d+\s*)\s*\)~', $filter, $matches) > 0:
                    $v = mb_substr($v, $matches['start'], (int) $matches['length']);
                    break;
                // split().
                case preg_match('~split\s*\(\s*["|\'](?<delimiter>[^"\']*?)["|\']\s*(?:,\s*(?<limit>-?\d+\s*)\s*)?\)~', $filter, $matches) > 0:
                    $delimiter = $matches['delimiter'] ?? '';
                    $limit = (int) ($matches['limit'] ?? 1);
                    $v = strlen($delimiter) ? explode($delimiter, $v, $limit) : str_split($v, $limit);
                    break;
                // trim().
                case preg_match('~trim\s*\(\s*["|\'](?<character_mask>[^"\']*?)["|\']\s*(?:,\s*["|\'](?<side>left|right|both|)["|\']\s*)?\s*\)~', $filter, $matches) > 0:
                    $characterMask = isset($matches['character_mask']) && strlen($matches['character_mask']) ? $matches['character_mask'] : " \t\n\r\0\x0B";
                    if (empty($matches['side']) || $matches['side'] === 'both') {
                        $v = trim($v, $characterMask);
                    } elseif ($matches['side'] === 'left') {
                        $v = ltrim($v, $characterMask);
                    } elseif ($matches['side'] === 'right') {
                        $v = rtrim($v, $characterMask);
                    }
                    break;
                // This is not a reserved keyword, so check for a variable.
                default:
                    $v = $twigVars['{{ ' . $filter . ' }}'] ?? $twigVars[$filter] ?? $v;
                    break;
            }
            $output = $v;
        }
        unset($output);
        return str_replace(array_keys($twig), array_values($twig), $pattern);
    }

    protected function normalizeConfig(): self
    {
        $this->normConfig = [];
        if (!$this->config) {
            return $this;
        }

        // parse_ini_string() cannot be used, because some characters are forbid
        // on the left and the right part is not quoted.
        // So process by line.

        if (!function_exists('array_key_last')) {
            function array_key_last(array $array) {
                return empty($array) ? null : key(array_slice($array, -1, 1, true));
            }
        }

        // Lines are trimmed. Empty lines are removed.
        $lines = $this->stringToList($this->config);

        $matches = [];
        $section = null;
        $sectionType = null;
        $autofillerKey = null;
        // The references simplify section management.
        $normConfigRef = &$this->normConfig;
        foreach ($lines as $line) {
            // Skip comments.
            if (mb_substr($line, 0, 1) === ';') {
                continue;
            }

            $first = mb_substr($line, 0, 1);
            $last = mb_substr($line, -1);

            // Check start of a new section.
            if ($first === '[') {
                // Check of a new autofiller.
                if (preg_match('~^\[[a-zA-Z][^\]]*\]\s*\S.*$~', $line)) {
                    preg_match('~^\[\s*(?<service>[a-zA-Z][a-zA-Z0-9]*)\s*(?:\:\s*(?<sub>[a-zA-Z][a-zA-Z0-9:]*))?\s*(?:#\s*(?<variant>[^\]]+))?\s*\]\s*(?:=?\s*(?<label>.*))$~', $line, $matches);
                    if (empty($matches['service'])) {
                        $this->logger->err(sprintf('The autofillers "%s" has no service.', $line));
                        continue;
                    }
                    $autofillerKey = $matches['service']
                        . (empty($matches['sub']) ? '' : ':' . $matches['sub'])
                        . (empty($matches['variant']) ? '' : ' #' . $matches['variant']);
                    $this->normConfig[$autofillerKey] = [
                        'service' => $matches['service'],
                        'sub' => $matches['sub'],
                        'label' => empty($matches['label']) ? null : $matches['label'],
                        'mapping' => [],
                    ];
                    unset($normConfigRef);
                    $normConfigRef = &$this->normConfig[$autofillerKey]['mapping'];
                    continue;
                }
                // Add a new section.
                elseif ($last === ']') {
                    $section = trim(mb_substr($line, 1, -1));
                    if ($section === '') {
                        $this->normConfig[] = [];
                        $section = array_key_last($this->normConfig);
                    } else {
                        $this->normConfig[$section] = [];
                    }
                    unset($normConfigRef);
                    $normConfigRef = &$this->normConfig[$section];
                    $sectionType = $this->configSections[$section] ?? null;
                    continue;
                }
            }

            // Add a key/value pair to the current section.

            // The left part can be a xpath with a "=". On the right part, only
            // a pattern can contain a "=". So split the line according to the
            // presence of a pattern prefixed with a `~`.
            // The left part may be a destination field too when the right part
            // is a raw content (starting with « " » or « ' »).
            // TODO The left part cannot contain a "~" for now.
            $pos = $first === '~'
                ? mb_strpos($line, '=')
                : mb_strrpos(strtok($line, '~'), '=');
            if ($pos === false) {
                $this->logger->err(sprintf('The mapping "%s" has no source or destination.', $line));
                continue;
            }
            $from = trim(mb_substr($line, 0, $pos));
            if (!strlen($from)) {
                $this->logger->err(sprintf('The mapping "%s" has no source.', $line));
                continue;
            }

            // Trim leading and trailing quote/double quote only when paired.
            $to = trim(mb_substr($line, $pos + 1));
            $originalTo = $to;
            $isRaw = (mb_substr($to, 0, 1) === '"' && mb_substr($to, -1) === '"')
                || (mb_substr($to, 0, 1) === "'" && mb_substr($to, -1) === "'");
            if ($isRaw) {
                $to = trim(mb_substr($to, 1, -1));
            }

            if ($sectionType === 'raw') {
                $normConfigRef[$from] = strlen($to) ? $to : null;
            } elseif ($sectionType === 'pattern') {
                $normConfigRef[$from] = $this->preparePattern($originalTo);
            } elseif ($sectionType === 'raw_or_pattern') {
                $normConfigRef[$from] = $isRaw || mb_substr($to, 0, 1) !== '~'
                    ? $to
                    : $this->preparePattern(trim(mb_substr($to, 1)));
            } else {
                if (!strlen($to)) {
                    $this->logger->warn(sprintf('The mapping "%s" has no destination.', trim($line, "= \t\n\r\0\x0B")));
                    continue;
                }
                // Manage default values: dcterms:license = "Public domain"
                // and default mapping: dcterms:license = dcterms:license ^^literal ~ "Public domain"
                // and full source mapping: license = dcterms:license ^^literal ~ "Public domain"
                $toDest = $isRaw ? $from . ' ~ ' . $originalTo : $to;
                $ton = $this->normalizeDestination($toDest);
                if (!$ton) {
                    $this->logger->err(sprintf('The destination "%s" is invalid.', $to));
                    continue;
                }
                // Remove useless values for the mapping.
                $result = [
                    'from' => $from,
                    'to' => array_filter($ton, function ($v) {
                        return !is_null($v);
                    }),
                ];
                $result['to']['dest'] = $toDest;
                $normConfigRef[] = $result;
            }

        }
        unset($normConfigRef);

        return $this;
    }

    protected function normalizeDestination(string $string): ?array
    {
        // TODO Add an option to fill the property id directly in automapFields().
        $result = $this->automapFields->__invoke([$string], ['check_field' => false, 'output_full_matches' => true]);
        if (empty($result)) {
            return null;
        }

        // With output_full_matches, there is one more level, so reset twice.
        $result = reset($result);
        if (!$result) {
            return null;
        }
        $result = reset($result);

        // Append the property id when the field is a property term.
        if (isset($result['field'])) {
            $termId = $this->bulk->getPropertyId($result['field']);
            if ($termId) {
                $result['property_id'] = $termId;
            }
        }

        return $result;
    }

    /**
     * @todo Factorize with TransformSource::appendPattern()
     */
    protected function preparePattern(string $pattern): array
    {
        $result = [
            'pattern' => $pattern,
        ];
        if (empty($pattern)) {
            return $result;
        }

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
     * Extract sub value with an object path.
     *
     * When multiple extractions should be done, it's quicker to use flatArray.
     * @see self::flatArray()
     */
    public function extractSubValue($data, string $path, $default = null)
    {
        if (!strlen($path)) {
            return $data;
        }

        if (is_array($data)) {
            foreach (explode('.', $path) as $part) {
                if (!array_key_exists($part, $data)) {
                    return $default;
                }
                $data = $data[$part];
            }
            return $data;
        }

        return $default;
    }

    /**
     * Create a flat array from a recursive array.
     *
     * @example
     * ```
     * // The following recursive array:
     * [
     *     'video' => [
     *         'dataformat' => 'jpg',
     *         'creator' => ['alpha', 'beta'],
     *     ],
     * ]
     * // is converted into:
     * [
     *     'video.dataformat' => 'jpg',
     *     'creator.0' => 'alpha',
     *     'creator.1' => 'beta',
     * ]
     * ```
     */
    public function flatArray(?array $array): array
    {
        // Quick check.
        if (empty($array)) {
            return [];
        }
        if (array_filter($array, 'is_scalar') === $array) {
            return $array;
        }
        $flatArray = [];
        $this->_flatArray($array, $flatArray);
        return $flatArray;
    }

    /**
     * Recursive helper to flat an array with separator ".".
     *
     * @todo Find a way to keep the last level of array (list of subjects…), currently managed with fields.
     * @todo Manage the case where the separator is included in the key (very rare in real world).
     */
    private function _flatArray(array &$array, array &$flatArray, ?string $keys = null): void
    {
        foreach ($array as $key => $value) {
            // $nKey = strpos($key, '.') === false ? $key : "'$key'";
            if (is_array($value)) {
                $this->_flatArray($value, $flatArray, $keys . '.' . $key);
            } else {
                $flatArray[trim($keys . '.' . $key, '.')] = $value;
            }
        }
    }

    protected function extractFields(?array $data): array
    {
        if (empty($data)) {
            return [];
        }

        $flatData = $this->flatArray($data);

        // Check if metadata are in a sub-array.
        // The list of fields simplifies left parts and manage multiple values.
        $fieldsKey = $this->getSectionSetting('params', 'fields');
        $fields = [];
        if ($fieldsKey) {
            $fieldsKeyDot = $fieldsKey . '.';
            $fieldsKeyDotLength = mb_strlen($fieldsKeyDot);
            foreach ($flatData as $flatDataKey => $flatDataValue) {
                if (mb_substr($flatDataKey, 0, $fieldsKeyDotLength) === $fieldsKeyDot) {
                    $flatDataKey = explode('.', mb_substr($flatDataKey, $fieldsKeyDotLength), 2);
                    if (isset($flatDataKey[1])) {
                        $fields[$flatDataKey[0]][$flatDataKey[1]] = $flatDataValue;
                    }
                }
            }
        }

        if (!$fields) {
            return [];
        }

        // Data can be a key-value pair, or an array where the key is a value,
        // and there may be multiple values.
        $fieldKey = $this->getSectionSetting('params', 'fields.key');
        $fieldValue = $this->getSectionSetting('params', 'fields.value');

        // Prepare the fields one time when there are fields and field key/value.

        // Field key is in a subkey of a list of fields. Example content-dm:
        // [key => "title", label => "Title", value => "value"]
        if ($fieldKey) {
            $fieldsResult = array_fill_keys(array_column($fields, $fieldKey), []);
            foreach ($fields as $dataFieldValue) {
                if (isset($dataFieldValue[$fieldKey]) && isset($dataFieldValue[$fieldValue])) {
                    $key = $dataFieldValue[$fieldKey];
                    $value = $dataFieldValue[$fieldValue];
                    if (is_array($value)) {
                        $fieldsResult[$key] = array_merge($fieldsResult[$key], array_values($value));
                    } else {
                        $fieldsResult[$key][] = $value;
                    }
                }
            }
            return $fieldsResult;
        }

        // Key for value is an associative key.
        // [fieldValue => "value"]
        if ($fieldValue) {
            $fieldsResult = array_fill_keys(array_column($fields, $fieldValue), []);
            foreach ($fields as $dataFieldValue) {
                if (isset($dataFieldValue[$fieldValue])) {
                    $value = $dataFieldValue[$fieldValue];
                    if (is_array($value)) {
                        $fieldsResult[$fieldValue] = array_merge($fieldsResult[$fieldValue], array_values($value));
                    } else {
                        $fieldsResult[$fieldValue][] = $value;
                    }
                }
            }
            return $fieldsResult;
        }

        return $fields;
    }

    /**
     * Get each line of a multi-line string separately.
     *
     * Empty lines are removed.
     */
    public function stringToList($string): array
    {
        return array_filter(array_map('trim', explode("\n", $this->fixEndOfLine($string))), 'strlen');
    }

    /**
     * Clean the text area from end of lines.
     *
     * This method fixes Windows and Apple copy/paste from a textarea input.
     */
    protected function fixEndOfLine($string): string
    {
        return str_replace(["\r\n", "\n\r", "\r"], ["\n", "\n", "\n"], (string) $string);
    }
}
