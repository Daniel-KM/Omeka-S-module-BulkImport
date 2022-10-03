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

use DOMDocument;
use DOMNode;
use DOMNodeList;
use DOMXPath;
use Laminas\Log\Logger;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use SimpleXMLElement;

/**
 * @todo Separate xml and json process into two plugins and make this one an abstract one.
 * @todo Merge with \AdvancedResourceTemplate\Mvc\Controller\Plugin\ArtMapper.
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

    /**
     * List of tables (associative arrays) indexed by their name.
     *
     * @array
     */
    protected $tables = [];

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
     * extract metadata from files json or xml files.
     *
     * It contains a list of mappings between source data and destination data.
     * For example:
     *
     * ```
     * /record/datafield[@tag='200'][@ind1='1']/subfield[@code='a'] = dcterms:title @fra ^^literal §private ~ pattern for the {{ value|trim }} with {{/source/record/data}}
     * ```
     *
     * or the same mapping as xml:
     *
     * ```xml
     * <mapping>
     *     <map>
     *         <from xpath="/record/datafield[@tag='200']/subfield[@code='a']"/>
     *         <to field="dcterms:title" language="fra" datatype="literal" visibility="private"/>
     *         <mod pattern="pattern for the {{ value|trim }} with {{/source/record/data}}"/>
     *     </map>
     * </mapping>
     * ```
     *
     * will be stored internally as:
     *
     * ```php
     * [
     *     [
     *          'from' => [
     *              'type' => 'xpath',
     *              'path' => '/record/datafield[@tag='200'][@ind1='1']/subfield[@code='a']',
     *          ],
     *          'to' => [
     *              'field' => 'dcterms:title',
     *              'property_id' => 1,
     *              'datatype' => 'literal',
     *              'language' => 'fra',
     *              'is_public' => false,
     *          ],
     *          'mod' => [
     *              'raw' => null,
     *              'prepend' => 'pattern for the ',
     *              'pattern' => '{{ value|trim }} with {{/source/record/data}}',
     *              'append' => null,
     *              'replace' => [
     *                  '{{/source/record/data}}',
     *              ],
     *              'twig' => [
     *                  '{{ value|trim }}',
     *              ],
     *          ],
     *      ],
     * ]
     * ```
     *
     * For more information and formats: see {@link https://gitlab.com/Daniel-KM/Omeka-S-module-BulkImport}
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

    public function getVariable(string $name, $default = null)
    {
        return $this->variables[$name] ?? $default;
    }

    public function getTables(): array
    {
        return $this->tables;
    }

    public function getTable(string $name): array
    {
        return $this->tables[$name] ?? [];
    }

    public function getTableItem(string $name, $key, $default = null): array
    {
        return $this->tables[$name][$key] ?? $default;
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
    public function setConfigs(?string ...$configs): self
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
     *
     * For sections "mapping", the name is the "from" path and all the setting
     * is output.
     */
    public function getSectionSetting(string $section, string $name, $default = null)
    {
        if (!empty($this->configSections[$section])
            && $this->configSections[$section] === 'mapping'
            && !empty($this->normConfig[$section])
        ) {
            foreach ($this->normConfig[$section] as $fromTo) {
                if ($name === ($fromTo['from']['path'] ?? null)) {
                    return $fromTo;
                }
            }
            return $default;
        }
        return $this->normConfig[$section][$name] ?? $default;
    }

    /**
     * Convert all mappings from a section with type "mapping".
     *
     * This method should be used when a mapping source ("from") is used
     * multiple times.
     * @param bool $isDefault When true, the target value "to" is added to the
     * resource without using data.
     */
    public function convertMappingSectionJson(string $section, array $resource, ?array $data, bool $isDefault = false): array
    {
        if (empty($this->configSections[$section]) || $this->configSections[$section] !== 'mapping') {
            return $resource;
        }

        if ($isDefault || empty($data)) {
            $flatData = [];
            $fields = [];
        } else {
            // Flat data and fields are used for jsdot and for replacements.
            $flatData = $this->flatArray($data);
            // Fields are used only for jsdot.
            $fields = $this->extractFields($data);
        }

        foreach ($this->getSection($section) as $fromTo) {
            $to = $fromTo['to'] ?? null;
            if (empty($to)) {
                continue;
            }

            $mod = $fromTo['mod'] ?? [];
            $raw = $mod['raw'] ?? '';
            if (strlen($raw)) {
                $resource[$to['dest']] = empty($resource[$to['dest']])
                    ? [$raw]
                    : array_merge($resource[$to['dest']], [$raw]);
                continue;
            }

            // @todo When default, "from" is useless: remove it from normalized config.
            $fromPath = $fromTo['from']['path'] ?? null;
            $prepend = $mod['prepend'] ?? '';
            $append = $mod['append'] ?? '';

            $result = [];
            if ($isDefault) {
                $converted = $this->convertTargetToStringJson($fromPath, $mod);
                if ($converted === [] || $converted === '' || $converted === null) {
                    continue;
                }
                $result[] = $prepend . $converted . $append;
            } else {
                // Check for associative value. "from" is a full path to data:
                // [key.to.data => "value"]
                if (array_key_exists($fromPath, $flatData)) {
                    $values = $flatData[$fromPath];
                }
                // Check for a repetitive value, starting with "fields[].".
                elseif (mb_substr($fromPath, 0, 9) === 'fields[].') {
                    $values = $fields[mb_substr($fromPath, 9)] ?? [];
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
                    $source[$fromPath] = $value;
                    $converted = $this->convertTargetToStringJson($fromPath, $mod, $source);
                    if ($converted === [] || $converted === '' || $converted === null) {
                        continue;
                    }
                    $result[] = $prepend . $converted . $append;
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
     * Convert all mappings from a section with type "mapping" for xml.
     *
     * This method should be used when a mapping source ("from") is used
     * multiple times.
     * @param bool $isDefault When true, the target value "to" is added to the
     * resource without using data.
     */
    public function convertMappingSectionXml(string $section, array $resource, \SimpleXMLElement $xml, bool $isDefault = false): array
    {
        if (empty($this->configSections[$section]) || $this->configSections[$section] !== 'mapping') {
            return $resource;
        }

        // There is no fields with xml: xpath is smart enough.

        // Use dom because it allows any xpath.
        /** @var \DOMElement $dom */
        $dom = dom_import_simplexml($xml);
        $doc = new DOMDocument();
        $doc->appendChild($doc->importNode($dom, true));

        foreach ($this->getSection($section) as $fromTo) {
            $to = $fromTo['to'] ?? null;
            if (empty($to)) {
                continue;
            }

            $mod = $fromTo['mod'] ?? [];
            $raw = $mod['raw'] ?? '';
            if (strlen($raw)) {
                $resource[$to['dest']] = empty($resource[$to['dest']])
                    ? [$raw]
                    : array_merge($resource[$to['dest']], [$raw]);
                continue;
            }

            $from = $fromTo['from']['path'] ?? null;
            $prepend = $mod['prepend'] ?? '';
            $append = $mod['append'] ?? '';

            $result = [];
            if ($isDefault) {
                $converted = $this->convertTargetToStringXml($from, $mod);
                if ($converted === [] || $converted === '' || $converted === null) {
                    continue;
                }
                $result[] = $prepend . $converted . $append;
            } else {
                if (!$from) {
                    continue;
                }
                $values = $this->xpathQuery($doc, $from);
                foreach ($values as $value) {
                    $converted = $this->convertTargetToStringXml($from, $mod, $doc, $value);
                    if ($converted === [] || $converted === '' || $converted === null) {
                        continue;
                    }
                    $result[] = $prepend . $converted . $append;
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
    public function convertToString(string $section, string $name, $data = null): ?string
    {
        // Note: for section type "mapping", the output is the whole setting
        // including "from", "to" and "mod".
        $toMod = $this->getSectionSetting($section, $name);
        if (!$toMod) {
            return null;
        }
        return $data instanceof \SimpleXMLElement
            ? $this->convertTargetToStringXml($name, $toMod, $data)
            : $this->convertTargetToStringJson($name, $toMod, $data);
    }

    /**
     * Convert a single field from the config into a string via a "jsdot".
     *
     * Example:
     * ```php
     * $this->variables = [
     *     'endpoint' => 'https://example.com',
     *     // Set for current value and default output when there is no pattern.
     *     'value' => 'xxx',
     * ];
     * $from = 'yyy';
     * $mod = [
     *     'pattern' => '{{ endpoint }}/api{{itemLink}}',
     *     // The following keys are automatically created from the pattern.
     *     'replace' => [ '{{itemLink}}' ]
     *     'twig' => [ '{{ endpoint }}' ],
     * ];
     * $data = [
     *     'itemLink' => '/id/150',
     * ];
     * $output = 'https://example.com/api/id/1850'
     * ```
     *
     * @todo Clarify arguments of function convertTargetToStringJson().
     * @internal For internal use only.
     *
     * @param string|array $from The key, or an array with key "path", where to
     * get the data.
     * @param array|string $mod If array, contains the pattern to use, else the
     * static value itself.
     * @param array $data The resource from which extract the data, if needed,
     * and any other value.
     * @return string The converted value. Without pattern, return the key
     * "value" from the variables.
     */
    protected function convertTargetToStringJson($from, $mod, ?array $data = null): ?string
    {
        if (is_null($mod) || is_string($mod)) {
            return $mod;
        }

        if (is_array($from)) {
            $from = $from['path'] ?? null;
        }

        $mod = $mod['mod'] ?? $mod;

        // Flat data is used for replacement too.
        $flatData = $this->flatArray($data);

        $fromValue = $flatData[$from] ?? null;
        $this->addVariable('value', $fromValue);

        if (!isset($mod['pattern']) || !strlen($mod['pattern'])) {
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

        if (isset($mod['raw']) && strlen($mod['raw'])) {
            return (string) $mod['raw'];
        }

        // When there are data, a query can be used for each variable.
        $replace = [];
        if (!empty($mod['replace'])) {
            if ($data) {
                foreach ($mod['replace'] as $wrappedQuery) {
                    // Manage the exceptions: there is no value here, neither label or list.
                    if (in_array($wrappedQuery, ['{{ value }}', '{{ label }}', '{{ list }}'])) {
                        $replace[$wrappedQuery] = '';
                        continue;
                    }
                    $query = mb_substr($wrappedQuery, 2, -2);
                    $replace[$wrappedQuery] = $flatData[$query] ?? '';
                }
            } else {
                $replace = array_fill_keys($mod['replace'], '');
            }
        }

        // Wrap vars to quick process for simple variables without twig filters.
        // Note that there are exceptions in variables (value, list, label), so
        // replacement is done in all cases.
        foreach ($this->variables as $name => $value) {
            if ($value instanceof \DOMNode) {
                $replace["{{ $name }}"] = (string) $value->nodeValue;
            } elseif (is_scalar($value)) {
                $replace["{{ $name }}"] = $value;
            } else {
                // TODO What else can be value. Array?
                continue;
            }
            // The variable can be set multiple times.
            if (!empty($mod['twig']) && ($poss = array_keys($mod['twig'], "{{ $name }}"))) {
                foreach ($poss as $pos) {
                    unset($mod['twig'][$pos]);
                }
            }
        }

        $value = $replace
            ? str_replace(array_keys($replace), array_values($replace), $mod['pattern'])
            : $mod['pattern'];

        if (!empty($mod['twig'])) {
            $value = $this->twig($value, $this->variables, $mod['twig'], $mod['twig_has_replace'] ?? [], $replace);
        }

        return $value;
    }

    /**
     * Convert a single field from the config into a string via a "xpath".
     *
     * Example:
     * ```php
     * $this->variables = [
     *     'endpoint' => 'https://example.com',
     *     // Set for current value and default output when there is no pattern.
     *     'value' => 'xxx',
     * ];
     * $from = 'yyy';
     * $mod = [
     *     'pattern' => '{{ endpoint }}/api{{itemLink}}',
     *     // The following keys are automatically created from the pattern.
     *     'replace' => [ '{{itemLink}}' ]
     *     'twig' => [ '{{ endpoint }}' ],
     * ];
     * $data = [
     *     'itemLink' => '/id/150',
     * ];
     * $output = 'https://example.com/api/id/1850'
     * ```
     *
     * @todo Clarify arguments of function convertTargetToStringXml().
     *
     * @param string|array $from The key, or an array with key "path", where to
     * get the data.
     * @param array|string mod If array, contains the pattern to use, else the
     * static value itself.
     * @param \DOMDocument|\SimpleXMLElement $data The resource from which
     * extract the data, if needed.
     * @param \DOMNode|string  $fromValue
     * @return string The converted value. Without pattern, return the key
     * "value" from the variables.
     */
    protected function convertTargetToStringXml($from, $mod, $data = null, $fromValue = null): ?string
    {
        if (is_null($mod) || is_string($mod)) {
            return $mod;
        }

        if (is_array($from)) {
            $from = $from['path'] ?? null;
        }

        $mod = $mod['mod'] ?? $mod;

        if (is_null($fromValue) && $from && $data) {
            $fromValue = $this->xpathQuery($data, $from);
        }

        if (is_null($fromValue)) {
            $first = null;
        } elseif (is_scalar($fromValue)) {
            $first = (string) $fromValue;
        } elseif ($fromValue instanceof \DOMNode) {
            $first = $fromValue;
        } elseif ($fromValue instanceof \SimpleXMLElement) {
            // Not used any more. SimpleXml doesn't support context or subquery.
            $first = (string) $fromValue[0];
        } else {
            $first = (string) reset($fromValue);
        }

        $fromValue = $first;
        $this->addVariable('value', $first);

        if (!isset($mod['pattern']) || !strlen($mod['pattern'])) {
            return $first instanceof \DOMNode ? (string) $first->nodeValue : (string) $first;
        }

        if (isset($mod['raw']) && strlen($mod['raw'])) {
            return (string) $mod['raw'];
        }

        // When there are data, a query can be used for each variable.
        $replace = [];
        if (!empty($mod['replace'])) {
            if ($data) {
                foreach ($mod['replace'] as $wrappedQuery) {
                    // Manage the exceptions: there is no value here, neither label or list.
                    if (in_array($wrappedQuery, ['{{ value }}', '{{ label }}', '{{ list }}'])) {
                        $replace[$wrappedQuery] = '';
                        continue;
                    }
                    $query = mb_substr($wrappedQuery, 2, -2);
                    $answer = $this->xpathQuery($data, $query, $first instanceof \DOMNode ? $first : null);
                    if (count($answer)) {
                        $firstAnswer = reset($answer);
                        $replace[$wrappedQuery] = $firstAnswer instanceof \DOMNode
                            ? (string) $firstAnswer->nodeValue
                            : (string) $firstAnswer;
                    } else {
                        $replace[$wrappedQuery] = '';
                    }
                }
            } else {
                $replace = array_fill_keys($mod['replace'], '');
            }
        }

        // Wrap vars to quick process for simple variables without twig filters.
        // Note that there are exceptions in variables (value, list, label), so
        // replacement is done in all cases.
        foreach ($this->variables as $name => $value) {
            if ($value instanceof \DOMNode) {
                $replace["{{ $name }}"] = (string) $value->nodeValue;
            } elseif (is_scalar($value)) {
                $replace["{{ $name }}"] = $value;
            } else {
                // TODO What else can be value. Array?
                continue;
            }
            // The variable can be set multiple times.
            if (!empty($mod['twig']) && ($poss = array_keys($mod['twig'], "{{ $name }}"))) {
                foreach ($poss as $pos) {
                    unset($mod['twig'][$pos]);
                }
            }
        }

        $value = $replace
            ? str_replace(array_keys($replace), array_values($replace), $mod['pattern'])
            : $mod['pattern'];

        if (!empty($mod['twig'])) {
            $value = $this->twig($value, $this->variables, $mod['twig'], $mod['twig_has_replace'] ?? [], $replace);
        }

        return $value;
    }

    /**
     * Convert a value into another value via twig filters.
     *
     * Only some common filters and some filter arguments are managed, and some
     * special functions for dates and index uris.
     */
    protected function twig(string $pattern, array $twigVars, array $twig, array $twigHasReplace, array $replace): string
    {
        // Store twig vars statically to avoid to extract them multiple times.
        static $patterns = [];

        // Prepare the static vars regex for twig.
        if (count($twigVars)) {
            // serialize() doesn't store DOMNode properties.
            $tw = $twigVars;
            foreach ($tw as &$v) {
                if ($v instanceof \DOMNode) {
                    $v = (string) $v->nodeValue;
                }
            }
            $serialized = serialize($tw);
            if (!isset($patterns[$serialized])) {
                $patterns[$serialized] = implode('|', array_map(function ($v) {
                    $v = $v instanceof \DOMNode ? (string) $v->nodeValue : (string) $v;
                    return mb_substr($v, 0, 3) === '{{ '
                        ? preg_quote(mb_substr($v, 3, -3), '~')
                        : preg_quote($v, '~');
                }, array_keys($twigVars))) . '|';
            }
            $patternVars = $patterns[$serialized];
        } else {
            $patternVars = '';
        }

        $extractList = function ($args, array $keys = []) use ($patternVars, $twigVars) {
            $matches = [];
            preg_match_all('~\s*(?<args>' . $patternVars . '"[^"]*?"|\'[^\']*?\')\s*,?\s*~', $args, $matches);
            $args = array_map(function ($arg) use ($twigVars) {
                // If this is a var, take it, else this is a string, so remove
                // the quotes.
                return $twigVars['{{ ' . $arg . ' }}'] ?? mb_substr($arg, 1, -1);
            }, $matches['args']);
            $countKeys = count($keys);
            return $countKeys
                ? array_combine($keys, count($args) >= $countKeys ? array_slice($args, 0, $countKeys) : array_pad($args, $countKeys, ''))
                : $args;
        };

        $extractAssociative = function ($args) use ($patternVars, $twigVars) {
            // TODO Improve the regex to extract keys and values directly.
            $matches = [];
            preg_match_all('~\s*(?<args>' . $patternVars . '"[^"]*?"|\'[^\']*?\')\s*,?\s*~', $args, $matches);
            $output = [];
            foreach (array_chunk($matches['args'], 2) as $keyValue) {
                if (count($keyValue) === 2) {
                    // The key cannot be a value.
                    $key = mb_substr($keyValue[0], 1, -1);
                    $value = $twigVars['{{ ' . $keyValue[1] . ' }}'] ?? mb_substr($keyValue[1], 1, -1);
                    $output[$key] = $value;
                }
            }
            return $output;
        };

        $twigProcess = function ($v, string $filter) use ($twigVars, $extractList, $extractAssociative) {
            $matches = [];
            if (preg_match('~\s*(?<function>[a-zA-Z0-9_]+)\s*\(\s*(?<args>.*?)\s*\)\s*~', $filter, $matches) > 0) {
                $function = $matches['function'];
                $args = $matches['args'];
            } else {
                $function = $filter;
                $args = '';
            }
            // Most of the time, a string is required, but a function can return
            // an array. Only some functions can manage an array.
            $w = is_array($v) ? reset($v) : $v;
            switch ($function) {
                case 'abs':
                    $v = is_numeric($w) ? (string) abs($w) : $w;
                    break;

                case 'capitalize':
                    $v = ucfirst((string) $w);
                    break;

                case 'date':
                    $format = $args;
                    try {
                        $v = $format === ''
                            ? @strtotime($w)
                            : @date($format, @strtotime($w));
                    } catch (\Exception $e) {
                        // Nothing: keep value.
                    }
                    break;

                case 'e':
                case 'escape':
                    $v = htmlspecialchars((string) $w, ENT_COMPAT | ENT_HTML5);
                    break;

                case 'first':
                    $v = is_array($v) ? $w : mb_substr((string) $v, 0, 1);
                    break;

                case 'format':
                    $args = $extractList($args);
                    if ($args) {
                        try {
                            $v = @vsprintf($w, $args);
                        } catch (\Exception $e) {
                            // Nothing: keep value.
                        }
                    }
                    break;

                case 'last':
                    $v = is_array($v) ? (string) end($v) : mb_substr((string) $v, -1);
                    break;

                case 'length':
                    $v = is_array($v) ? count($v) : mb_strlen((string) $v);
                    break;

                case 'lower':
                    $v = mb_strtolower((string) $w);
                    break;

                case 'replace':
                    $args = $extractAssociative($args);
                    if ($args) {
                        $v = str_replace(array_keys($args), array_values($args), $w);
                    }
                    break;

                case 'slice':
                    $args = $extractList($args);
                    $start = (int) ($args[0] ?? 0);
                    $length = (int) ($args[1] ?? 1);
                    $v = is_array($v)
                        ? array_slice($v, $start, $length, !empty($args[2]))
                        : mb_substr((string) $w, $start, $length);
                    break;

                case 'split':
                    $args = $extractList($args);
                    $delimiter = $args[0] ?? '';
                    $limit = (int) ($args[1] ?? 1);
                    $v = strlen($delimiter)
                        ? explode($delimiter, (string) $w, $limit)
                        : str_split((string) $w, $limit);
                    break;

                case 'striptags':
                    $v = strip_tags((string) $w);
                    break;

                case 'table':
                    // table() (included).
                    $first = mb_substr($args, 0, 1);
                    if ($first === '{') {
                        $table = $extractAssociative(trim(mb_substr($args, 1, -1)));
                        if ($table) {
                            $v = $table[$w] ?? $w;
                        }
                    }
                    // table() (named).
                    else {
                        $name = $first === '"' || $first === "'" ? mb_substr($args, 1, -1) : $args;
                        $v = $this->tables[$name][$w] ?? $w;
                    }
                    break;

                case 'title':
                    $v = ucwords((string) $w);
                    break;

                case 'trim':
                    $args = $extractList($args);
                    $characterMask = $args[0] ?? '';
                    if (!strlen($characterMask)) {
                        $characterMask = " \t\n\r\0\x0B";
                    }
                    $side = $args[1] ?? '';
                    // Side is "both" by default.
                    if ($side === 'left') {
                        $v = ltrim((string) $w, $characterMask);
                    } elseif ($side === 'right') {
                        $v = rtrim((string) $w, $characterMask);
                    } else {
                        $v = trim((string) $w, $characterMask);
                    }
                    break;

                case 'upper':
                    $v = mb_strtoupper((string) $w);
                    break;

                case 'url_encode':
                    $v = rawurlencode((string) $w);
                    break;

                // Special filters and functions to manage common values.
                case 'dateIso':
                    // "d1605110512" => "1605-11-05T12" (date iso).
                    // "[1984]-" => kept.
                    // Missing numbers may be set as "u", but this is not
                    // manageable as iso 8601.
                    $v = (string) $w;
                    if (mb_strlen($v) && mb_strpos($v, 'u') === false) {
                        $firstChar = mb_substr($v, 0, 1);
                        if (in_array($firstChar, ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '-', '+', 'c', 'd'])) {
                            if (in_array($firstChar, ['-', '+', 'c', 'd'])) {
                                $d = $firstChar === '-' || $firstChar === 'c' ? '-' : '';
                                $v = mb_substr($v, 1);
                            } else {
                                $d = '';
                            }
                            $v = $d
                                . mb_substr($v, 0, 4) . '-' . mb_substr($v, 4, 2) . '-' . mb_substr($v, 6, 2)
                                . 'T' . mb_substr($v, 8, 2) . ':' . mb_substr($v, 10, 2) . ':' . mb_substr($v, 12, 2);
                            $v = rtrim($v, '-:T |#');
                        }
                    }
                    break;

                case 'dateSql':
                    // Unimarc 005.
                    // "19850901141236.0" => "1985-09-01 14:12:36" (date sql).
                    $v = (string) $w;
                    $v = mb_substr($v, 0, 4) . '-' . mb_substr($v, 4, 2) . '-' . mb_substr($v, 6, 2)
                        . ' ' . mb_substr($v, 8, 2) . ':' . mb_substr($v, 10, 2) . ':' . mb_substr($v, 12, 2);
                    break;

                case 'isbdName':
                    // isbdName(a, b, c, d, f, g, k, o, p, 5) (function).
                    /* Unimarc 700 et suivants :
                    $a Élément d’entrée
                    $b Partie du nom autre que l’élément d’entrée
                    $c Eléments ajoutés aux noms autres que les dates
                    $d Chiffres romains
                    $f Dates
                    $g Développement des initiales du prénom
                    $k Qualificatif pour l’attribution
                    $o Identifiant international du nom
                    $p Affiliation / adresse
                    $5 Institution à laquelle s’applique la zone
                     */
                    $args = $extractList($args, ['a', 'b', 'c', 'd', 'f', 'g', 'k', 'o', 'p', '5']);
                    // @todo Improve isbd for names.
                    $v = $args['a']
                        . ($args['b'] ? ', ' . $args['b'] : '')
                        . ($args['g'] ? ' (' . $args['g'] . ')' : '')
                        . ($args['d'] ? ', ' . $args['d'] : '')
                        . (
                            $args['f']
                            ? ' (' . $args['f']
                                . ($args['c'] ? ' ; ' . $args['c'] : '')
                                . ($args['k'] ? ' ; ' . $args['k'] : '')
                                . ')'
                            : (
                                $args['c']
                                ? (' (' . $args['c'] . ($args['k'] ? ' ; ' . $args['k'] : '') . ')')
                                : ($args['k'] ? ' (' . $args['k'] . ')' : '')
                            )
                        )
                        . ($args['o'] ? ' {' . $args['o'] . '}' : '')
                        . ($args['p'] ? ', ' . $args['p'] : '')
                        . ($args['5'] ? ', ' . $args['5'] : '')
                    ;
                    break;

                case 'isbdNameColl':
                    // isbdNameColl(a, b, c, d, e, f, g, h, o, p, r, 5) (function).
                    /* Unimarc 710/720/740 et suivants :
                    $a Élément d’entrée
                    $b Subdivision
                    $c Élément ajouté au nom ou qualificatif
                    $d Numéro de congrès et/ou numéro de session de congrès
                    $e Lieu du congrès
                    $f Date du congrès
                    $g Élément rejeté
                    $h Partie du nom autre que l’élément d’entrée et autre que l’élément rejeté
                    $o Identifiant international du nom
                    $p Affiliation / adresse
                    $r Partie ou rôle joué
                    $5 Institution à laquelle s’applique la zone
                    // Pour mémoire.
                    $3 Identifiant de la notice d’autorité
                    $4 Code de fonction
                     */
                    $args = $extractList($args, ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'o', 'p', 'r', '5']);
                    // @todo Improve isbd for organizations.
                    $v = $args['a']
                        . ($args['b'] ? ', ' . $args['b'] : '')
                        . ($args['g']
                            ? ' (' . $args['g'] . ($args['h'] ? ' ; ' . $args['h'] . '' : '') . ')'
                            : ($args['h'] ? ' (' . $args['h'] . ')' : ''))
                        . ($args['d'] ? ', ' . $args['d'] : '')
                        . ($args['e'] ? ', ' . $args['e'] : '')
                        . (
                            $args['f']
                            ? ' (' . $args['f']
                                . ($args['c'] ? ' ; ' . $args['c'] : '')
                                . ')'
                            : ($args['c'] ? (' (' . $args['c'] . ')') : '')
                        )
                        . ($args['o'] ? ' {' . $args['o'] . '}' : '')
                        . ($args['p'] ? ', ' . $args['p'] : '')
                        . ($args['r'] ? ', ' . $args['r'] : '')
                        . ($args['5'] ? ', ' . $args['5'] : '')
                    ;
                    break;

                case 'isbdMark':
                    /* Unimarc 716 :
                    $a Élément d’entrée
                    $c Qualificatif
                    $f Dates
                     */
                    // isbdMark(a, b, c) (function).
                    $args = $extractList($args, ['a', 'b', 'c']);
                    // @todo Improve isbd for marks.
                    $v = $args['a']
                        . ($args['b'] ? ', ' . $args['b'] : '')
                        . ($args['c'] ? (' (' . $args['c'] . ')') : '')
                    ;
                    break;

                case 'unimarcIndex':
                    $args = $extractList($args);
                    $index = $args[0] ?? '';
                    if ($index) {
                        // Unimarc Index uri (filter or function).
                        $code = count($args) === 1 ? $w : ($args[1] ?? '');
                        // Unimarc Annexe G.
                        // @link https://www.transition-bibliographique.fr/wp-content/uploads/2018/07/AnnexeG-5-2007.pdf
                        switch ($index) {
                            case 'unimarc/a':
                                $v = 'Unimarc/A : ' . $code;
                                break;
                            case 'rameau':
                                $v = 'https://data.bnf.fr/ark:/12148/cb' . $code . $this->noidCheckBnf('cb' . $code);
                                break;
                            default:
                                $v = $index . ' : ' . $code;
                                break;
                        }
                    }
                    break;

                case 'unimarcCoordinates':
                    // "w0241207" => "W 24°12’7”".
                    // Hemisphere "+" / "-" too.
                    $v = (string) $w;
                    $firstChar = mb_strtoupper(mb_substr($v, 0, 1));
                    $mappingChars = ['+' => 'N', '-' => 'S', 'W' => 'W', 'E' => 'E', 'N' => 'N', 'S' => 'S'];
                    $v = ($mappingChars[$firstChar] ?? '?') . ' '
                        . intval(mb_substr($v, 1, 3)) . '°'
                        . intval(mb_substr($v, 4, 2)) . '’'
                        . intval(mb_substr($v, 6, 2)) . '”';
                    break;

                case 'unimarcCoordinatesHexa':
                    $v = (string) $w;
                    $v = mb_substr($v, 0, 2) . '°' . mb_substr($v, 2, 2) . '’' . mb_substr($v, 4, 2) . '”';
                    break;

                case 'unimarcTimeHexa':
                    // "150027" => "15h0m27s".
                    $v = (string) $w;
                    $h = (int) trim(mb_substr($v, 0, 2));
                    $m = (int) trim(mb_substr($v, 2, 2));
                    $s = (int) trim(mb_substr($v, 4, 2));
                    $v = ($h ? $h . 'h' : '')
                        . ($m ? $m . 'm' : ($h && $s ? '0m' : ''))
                        . ($s ? $s . 's' : '');
                    break;

                // This is not a reserved keyword, so check for a variable.
                case 'value':
                default:
                    $v = $twigVars['{{ ' . $filter . ' }}'] ?? $twigVars[$filter] ?? $v;
                    if ($v instanceof \DOMNode) {
                        $v = (string) $v->nodeValue;
                    }
                    break;
            }
            return $v;
        };

        $twigReplace = [];
        $twigPatterns = array_flip($twig);
        $hasReplace = !empty($replace);
        foreach ($twig as $query) {
            $hasReplaceQuery = $hasReplace && !empty($twigHasReplace[$twigPatterns[$query]]);
            $v = '';
            $filters = array_filter(array_map('trim', explode('|', mb_substr((string) $query, 3, -3))));
            // The first filter may not be a filter, but a variable. A variable
            // cannot be a reserved keyword.
            foreach ($filters as $filter) {
                $v = $hasReplaceQuery
                    ? $twigProcess($v, str_replace(array_keys($replace), array_values($replace), $filter))
                    : $twigProcess($v, $filter);
            }
            // Avoid an issue when the twig pattern returns an array.
            if (is_array($v)) {
                $v = reset($v);
            }
            if ($hasReplaceQuery) {
                $twigReplace[str_replace(array_keys($replace), array_values($replace), $query)] = $v;
            } else {
                $twigReplace[$query] = $v;
            }
        }
        return str_replace(array_keys($twigReplace), array_values($twigReplace), $pattern);
    }

    protected function normalizeConfig(): self
    {
        $this->normConfig = [];
        if (!$this->config) {
            return $this;
        }

        // When first character is "<", it's an xml because it cannot be ini.
        $this->config = trim($this->config);
        $isXml = mb_substr($this->config, 0, 1) === '<';
        return $isXml
            ? $this->normalizeConfigXml()
            : $this->normalizeConfigIni();
    }

    protected function normalizeConfigIni(): self
    {
        // parse_ini_string() cannot be used, because some characters are forbid
        // on the left and the right part is not quoted.
        // So process by line.

        if (!function_exists('array_key_last')) {
            function array_key_last(array $array)
            {
                return empty($array) ? null : key(array_slice($array, -1, 1, true));
            }
        }

        $toKeys = [
            'field' => null,
            'property_id' => null,
            'datatype' => null,
            'language' => null,
            'is_public' => null,
        ];

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
                if ($isRaw || mb_substr($to, 0, 1) !== '~') {
                    if ($isRaw) {
                        $normConfigRef[$from] = $to;
                    } else {
                        $mapRaw = ['true' => true, 'false' => false, 'null' => null];
                        $normConfigRef[$from] = $mapRaw[strtolower($to)] ?? $to;
                    }
                } else {
                    $normConfigRef[$from] = $this->preparePattern(trim(mb_substr($to, 1)));
                }
            } else {
                // Section type is "mapping".
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
                $result = [
                    'from' => [
                        'type' => $this->typePath($from),
                        'path' => $from,
                    ],
                    'to' => array_intersect_key($ton, $toKeys),
                    'mod' => array_diff_key($ton, $toKeys),
                ];
                $result['to']['dest'] = $toDest;
                $normConfigRef[] = $result;
            }
        }
        unset($normConfigRef);

        return $this;
    }

    protected function normalizeConfigXml(): self
    {
        // The config is always a small file (less than some megabytes), so it
        // can be managed directly with SimpleXml.
        $xmlConfig = new SimpleXMLElement($this->config);

        $xmlMapToArray = function (SimpleXMLElement $element, int $index, bool $isDefault = false): ?array {
            // Since anything is set inside attributes, use a json conversion.
            $xmlArray = json_decode(json_encode($element), true);

            if ($isDefault) {
                $result['from'] = null;
            } elseif (isset($xmlArray['from']['@attributes']['jsdot']) && strlen((string) $xmlArray['from']['@attributes']['jsdot'])) {
                $result['from'] = ['type' => 'jsdot', 'path' => (string) $xmlArray['from']['@attributes']['jsdot']];
            } elseif (isset($xmlArray['from']['@attributes']['xpath']) && strlen((string) $xmlArray['from']['@attributes']['xpath'])) {
                $result['from'] = ['type' => 'xmlpath', 'path' => (string) $xmlArray['from']['@attributes']['xmlpath']];
            } else {
                $this->logger->err(sprintf('The mapping "%s" has no source.', $index));
                return null;
            }

            if (!isset($xmlArray['to']['@attributes']['field'])
                || !strlen((string) $xmlArray['to']['@attributes']['field'])
            ) {
                $this->logger->err(sprintf('The mapping "%s" has no destination.', $index));
                return null;
            }

            // @todo The use of automapFields is simpler, so merge the values and output it?

            $result['to']['field'] = (string) $xmlArray['to']['@attributes']['field'];

            $termId = $this->bulk->getPropertyId($result['to']['field']);
            if ($termId) {
                $result['to']['property_id'] = $termId;
            }

            $result['to']['language'] = isset($xmlArray['to']['@attributes']['language'])
                ? (string) $xmlArray['to']['@attributes']['language']
                : null;
            $result['to']['datatype'] = isset($xmlArray['to']['@attributes']['datatype'])
                ? array_filter(array_map('trim', explode(';', (string) $xmlArray['to']['@attributes']['datatype'])))
                : [];
            $result['to']['is_public'] = isset($xmlArray['to']['@attributes']['visibility'])
                ? ((string) $xmlArray['to']['@attributes']['visibility']) !== 'private'
                : null;

            $result['mod']['raw'] = isset($xmlArray['mod']['@attributes']['raw']) && strlen($xmlArray['mod']['@attributes']['raw'])
                ? (string) $xmlArray['mod']['@attributes']['raw']
                : null;
            $hasNoRaw = is_null($result['mod']['raw']);
            $result['mod']['prepend'] = $hasNoRaw && isset($xmlArray['mod']['@attributes']['prepend'])
                ? (string) $xmlArray['mod']['@attributes']['prepend']
                : null;
            $result['mod']['append'] = $hasNoRaw && isset($xmlArray['mod']['@attributes']['append'])
                ? (string) $xmlArray['mod']['@attributes']['append']
                : null;
            $result['mod']['pattern'] = null;
            if ($hasNoRaw && isset($xmlArray['mod']['@attributes']['pattern'])) {
                $r = $this->preparePattern((string) $xmlArray['mod']['@attributes']['pattern']);
                if (isset($r['raw']) && strlen($r['raw'])) {
                    $result['mod']['raw'] = $r['raw'];
                    $hasNoRaw = false;
                } else {
                    if (isset($r['prepend']) && strlen($r['prepend'])) {
                        $result['mod']['prepend'] = $r['prepend'];
                    }
                    if (isset($r['append']) && strlen($r['append'])) {
                        $result['mod']['append'] = $r['append'];
                    }
                    $result['mod']['pattern'] = $r['pattern'] ?? null;
                    $result['mod']['replace'] = $r['replace'] ?? [];
                    $result['mod']['twig'] = $r['twig'] ?? [];
                    $result['mod']['twig_has_replace'] = $r['twig_has_replace'] ?? [];
                }
            }

            // @todo Remove the short destination (used in processor and when converting to avoid duplicates)?
            $fullPattern = $hasNoRaw
                ? ($result['mod']['prepend'] ?? '') . ($result['mod']['pattern'] ?? '') . ($result['mod']['append'] ?? '')
                : (string) $result['mod']['raw'];
            $result['to']['dest'] = $result['to']['field']
                . (count($result['to']['datatype']) ? ' ^^' . implode('; ', $result['to']['datatype']) : '')
                . (isset($result['to']['language']) ? ' @' . $result['to']['language'] : '')
                . (isset($result['to']['is_public']) ? ' §' . ($result['to']['is_public'] ? 'public' : 'private') : '')
                . (strlen($fullPattern) ? ' ~ ' . $fullPattern : '')
            ;

            return $result;
        };

        $this->normConfig = [
            'info' => [],
            'params' => [],
            'default' => [],
            'mapping' => [],
        ];

        // TODO Xml config for info and params.
        foreach ($xmlConfig->info as $element) {
            $this->normConfig['info'][] = [];
        }

        foreach ($xmlConfig->params as $element) {
            $this->normConfig['params'][] = [];
        }

        // This value allows to keep the original dest single.
        // @todo Remove [to][dest] and "k".
        $k = 0;
        // TODO Use an attribute or a sub-element ?
        $i = 0;
        foreach ($xmlConfig->map as $element) {
            if ($element->from['xpath']) {
                continue;
            }
            $fromTo = $xmlMapToArray($element, ++$i, true);
            if (is_array($fromTo)) {
                if (isset($fromTo['to']['dest'])) {
                    $fromTo['to']['dest'] = $fromTo['to']['dest'] . str_repeat(' ', $k++);
                }
                $this->normConfig['default'][] = $fromTo;
            }
        }

        $i = 0;
        foreach ($xmlConfig->map as $element) {
            if (!$element->from['xpath']) {
                continue;
            }
            $fromTo = $xmlMapToArray($element, ++$i);
            if (is_array($fromTo)) {
                if (isset($fromTo['to']['dest'])) {
                    $fromTo['to']['dest'] = $fromTo['to']['dest'] . str_repeat(' ', $k++);
                }
                $this->normConfig['mapping'][] = $fromTo;
            }
        }

        // Prepare tables.
        $this->normalizeTables();

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
     * Determine if an expression is a js dot object notation or an xpath.
     *
     * @return string|null "jsdot" (default) or "xpath".
     */
    protected function typePath(?string $path): ?string
    {
        if (is_null($path) || !strlen($path)) {
            return null;
        }

        // Keys with "/", "'", """, etc. are very rare in json, so not a jsdot.
        if (strpos($path, '/') !== false
            || strpos($path, '"') !== false
            || strpos($path, "'") !== false
            || strpos($path, '::') !== false
            || strpos($path, '=') !== false
        ) {
            return 'xpath';
        }

        // A sub-array is represented as "xxx.0.yyy", so a jsdot.
        if (preg_match('~\.\d+\.~', $path)) {
            return 'jsdot';
        }

        $xpath = new DOMXPath(new DOMDocument);
        return @$xpath->query($path)
            ? 'xpath'
            : 'jsdot';
    }

    /**
     * @todo Factorize with AutomapFields::appendPattern()
     * @see \BulkImport\Mvc\Controller\Plugin\AutomapFields::appendPattern()
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
            $result['pattern'] = null;
            return $result;
        }

        // Check for incomplete replacement or twig patterns.
        $prependPos = mb_strpos($pattern, '{{');
        $appendPos = mb_strrpos($pattern, '}}');

        // A quick check.
        if ($prependPos === false || $appendPos === false) {
            $result['raw'] = trim($pattern);
            $result['pattern'] = null;
            return $result;
        }

        // To simplify process and remove the empty values, check if the pattern
        // contains a prepend/append string.
        // Replace only complete patterns, so check append too.
        $isNormalPattern = $prependPos < $appendPos;
        if ($isNormalPattern && $prependPos && $appendPos) {
            $result['prepend'] = mb_substr($pattern, 0, $prependPos);
            $pattern = mb_substr($pattern, $prependPos);
            $result['pattern'] = $pattern;
            $appendPos = mb_strrpos($pattern, '}}');
        }
        if ($isNormalPattern && $appendPos !== mb_strlen($pattern) - 2) {
            $result['append'] = mb_substr($pattern, $appendPos + 2);
            $pattern = mb_substr($pattern, 0, $appendPos + 2);
            $result['pattern'] = $pattern;
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

    protected function normalizeTables(): self
    {
        // The config is always a small file (less than some megabytes), so it
        // can be managed directly with SimpleXml.
        $xmlConfig = new SimpleXMLElement($this->config);
        foreach ($xmlConfig->table as $table) {
            $code = (string) $table['code'];
            if (!$code) {
                continue;
            }
            foreach ($table->list[0]->term as $term) {
                $termCode = (string) $term['code'];
                if (strlen($termCode)) {
                    $this->tables[$code][$termCode] = (string) $term[0];
                }
            }
        }
        return $this;
    }

    /**
     * Extract sub value with an object path.
     *
     * When multiple extractions should be done, it's quicker to use flatArray.
     * @see self::flatArray()
     */
    public function extractSubValue($data, ?string $path, $default = null)
    {
        if (is_null($path) || !strlen($path)) {
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
     *         'data.format' => 'jpg',
     *         'creator' => ['alpha', 'beta'],
     *     ],
     * ]
     * // is converted into:
     * [
     *     'video.data\.format' => 'jpg',
     *     'creator.0' => 'alpha',
     *     'creator.1' => 'beta',
     * ]
     * ```
     *
     * @see \AdvancedResourceTemplate\Mvc\Controller\Plugin\ArtMapper::flatArray()
     * @see \ValueSuggestAny\Suggester\JsonLd\JsonLdSuggester::flatArray()
     * @todo Factorize flatArray() between modules.
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
     */
    private function _flatArray(array &$array, array &$flatArray, ?string $keys = null): void
    {
        foreach ($array as $key => $value) {
            $nKey = str_replace(['.', '\\'], ['\.', '\\\\'], $key);
            if (is_array($value)) {
                $this->_flatArray($value, $flatArray, $keys . '.' . $nKey);
            } else {
                $flatArray[trim($keys . '.' . $nKey, '.')] = $value;
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
                if (mb_substr((string) $flatDataKey, 0, $fieldsKeyDotLength) === $fieldsKeyDot) {
                    $flatDataKey = explode('.', mb_substr((string) $flatDataKey, $fieldsKeyDotLength), 2);
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
     * Get result from a xpath expression on a xml.
     *
     * If the xpath contains a function (like `substring(xpath, 2)`),
     * `evaluate()` is used and the output may be a simple string.
     * Else `query()` is used and the output is a node list, so it's possible to
     * do another query, included relative ones, on each node.
     *
     * Note: DOMXPath->query() and SimpleXML->xpath() don't work with xpath
     * functions like `substring(xpath, 2)`, that output a single scalar value,
     * not a list of nodes.
     *
     * @param \DOMDocument|\SimpleXMLElement $xml
     * @param string $query
     * @param \DOMNode $contextNode
     * @return \DOMNode[]|string[] Try to return DOMNode when possible.
     */
    protected function xpathQuery($xml, string $query, ?DOMNode $contextNode = null): array
    {
        if ($xml instanceof \SimpleXMLElement) {
            /** @var \DOMElement $xml */
            $xml = dom_import_simplexml($xml);
            $doc = new DOMDocument();
            $node = $doc->importNode($xml, true);
            $doc->appendChild($node);
            /** @var \DOMDocument $xml */
            $xml = $doc;
        }
        $xpath = new DOMXPath($xml);

        $nodeList = $xpath->evaluate($query, $contextNode);
        if ($nodeList === false
            || ($nodeList === '')
        ) {
            return [];
        }

        if (is_array($nodeList)) {
            return array_map('strval', $nodeList);
        }

        if (!is_object($nodeList)) {
            return [(string) $nodeList];
        }

        /** @var DOMNodeList $nodeList */
        $result = [];
        foreach ($nodeList as $item) {
            $result[] = $item;
        }
        return $result;
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

    /**
     * Compute the check character for BnF records.
     *
     * The records linked with BnF use only the code, without the check
     * character, so it should be computed in order to get the uri.
     *
     * @see https://metacpan.org/dist/Noid/view/noid#NOID-CHECK-DIGIT-ALGORITHM
     */
    protected function noidCheckBnf(string $value): string
    {
        // Unlike noid recommendation, the check for bnf doesn't use the naan ("12148").
        $table = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', 'b', 'c', 'd', 'f', 'g', 'h', 'j', 'k', 'm', 'n', 'p', 'q', 'r', 's', 't', 'v', 'w', 'x', 'z'];
        $tableKeys = array_flip($table);
        $vals = str_split($value, 1);
        $sum = array_sum(array_map(function ($k, $v) use ($tableKeys) {
            return ($tableKeys[$v] ?? 0) * ($k + 1);
        }, array_keys($vals), array_values($vals)));
        $mod = $sum % count($table);
        return $table[$mod];
    }
}
