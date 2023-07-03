<?php declare(strict_types=1);

/*
 * Copyright 2017-2023 Daniel Berthereau
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

namespace BulkImport\Stdlib;

use BulkImport\Mvc\Controller\Plugin\AutomapFields;
use BulkImport\Mvc\Controller\Plugin\Bulk;
use DOMDocument;
use DOMNode;
use DOMNodeList;
use DOMXPath;
use Flow\JSONPath\JSONPath;
use JmesPath\Env as JmesPathEnv;
use JmesPath\Parser as JmesPathParser;
use Laminas\Log\Logger;
use SimpleXMLElement;

/**
 * @todo Clarify settings and arguments.
 * @todo Separate preparation of the config (read and merge config) and processing transform.
 * @todo Separate xml and json process into two plugins and make this one an abstract one. But a complex config may mix various paths? In real world?
 * @todo Simplify process of init and allow multiple init with static cache.
 * @todo Add unit tests.
 */
class MetaMapper
{
    use TwigTrait;

    /**
     * @var \BulkImport\Mvc\Controller\Plugin\AutomapFields
     */
    protected $automapFields;

    /**
     * @var \BulkImport\Mvc\Controller\Plugin\Bulk
     */
    protected $bulk;

    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * @var \BulkImport\Stdlib\MetaMapperConfig
     */
    protected $metaMapperConfig;

    /**
     * @var \JmesPath\Env
     */
    protected $jmesPathEnv;

    /**
     * @var \JmesPath\Parser
     */
    protected $jmesPathParser;

    /**
     * @var \Flow\JSONPath\JSONPath
     */
    protected $jsonPathQuerier;

    /**
     * @var string
     */
    protected $mappingName;

    /**
     * @todo Improve management of variables with metaMapperConfig. Or separate clearly options and variables.
     *
     * @var array
     */
    protected $variables = [];

    public function __construct(
        MetaMapperConfig $metaMapperConfig,
        Logger $logger,
        Bulk $bulk,
        AutomapFields $automapFields
    ) {
        $this->metaMapperConfig = $metaMapperConfig;
        $this->logger = $logger;
        $this->bulk = $bulk;
        $this->automapFields = $automapFields;
        $this->jmesPathEnv = new JmesPathEnv;
        $this->jmesPathParser = new JmesPathParser;
        $this->jsonPathQuerier = new JSONPath;
    }

    /**
     * Get this meta mapper.
     */
    public function __invoke(?string $mappingName = null): self
    {
        if ($mappingName) {
            $this->mappingName = $mappingName;
            $this->metaMapperConfig->__invoke($mappingName);
        }
        return $this;
    }

    public function getMetaMapperConfig(): MetaMapperConfig
    {
        return $this->metaMapperConfig;
    }

    /**
     * Get meta mapper config name.
     */
    public function getMappingName(): ?string
    {
        return $this->mappingName;
    }

    /**
     * Get current meta mapper mapping if any.
     */
    public function getMapping(): ?array
    {
        // Don't return via invoke: it may be the plugin itself when there is no
        // mapping name.
        return $this->metaMapperConfig->__invoke()->getMapping($this->mappingName);
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

    public function setVariable(string $name, $value): self
    {
        $this->variables[$name] = $value;
        return $this;
    }

    public function getVariable(string $name, $default = null)
    {
        return $this->variables[$name] ?? $default;
    }

    /**
     * Directly convert a string with a map without a meta mapping.
     *
     * The string is generally extracted from a source via a mapping.
     * Warning: Here, the conversion cannot use another data from the source.
     * Indeed, the full entry is not provided.
     *
     * Currently only used with Spreadsheet.
     * @see \BulkImport\Entry/SpreadsheetEntry
     *
     * @todo Make Spreadsheet uses MetaMapperConfig.
     */
    public function convertString(?string $value, array $map = []): string
    {
        if (is_null($value)) {
            return '';
        }

        if (empty($map['mod'])) {
            return $value;
        }

        // The map should be well configured: an empty string must be null.
        if (isset($map['mod']['raw'])) {
            return $map['mod']['raw'];
        }

        if (isset($map['mod']['val'])) {
            return $map['mod']['val'];
        }

        $result = $this->convertTargetToStringJson($value, $map, null, 'value', true);
        return is_null($result) || !strlen($result)
            ? ''
            : (($map['prepend'] ?? '') . $result . ($map['append'] ?? ''));
    }

    /**
     * Convert array or xml into new data (array) applying current mapping.
     *
     * @param array|SimpleXMLElement $data
     */
    public function convert($data): array
    {
        $mapping = $this->metaMapperConfig->__invoke($this->mappingName);
        if (!$mapping) {
            return [];
        }

        $result = [];

        if (is_array($data)) {
            $result = $this->convertMappingSectionJson('default', $result, $data, true);
            $result = $this->convertMappingSectionJson('maps', $result, $data);
        } elseif ($data instanceof SimpleXMLElement) {
            $result = $this->convertMappingSectionXml('default', $result, $data, true);
            $result = $this->convertMappingSectionXml('maps', $result, $data);
        }

        return $result;
    }

    /**
     * Convert all mappings from a section.
     *
     * This method should be used when a mapping source ("from") is used
     * multiple times.
     *
     * @param bool $isDefaultSection When true, the target value "to" is added to the
     * resource without using data.
     */
    protected function convertMappingSectionJson(string $section, array $resource, ?array $data, bool $isDefaultSection = false): array
    {
        // Only sections "default" and "mapping" are a mapping.
        $isMapping = $this->metaMapperConfig->getSectionSettingSub('options', 'section_types', $section) === 'mapping';
        if (!$isMapping) {
            return $resource;
        }

        if ($isDefaultSection || empty($data)) {
            $flatData = [];
            $fields = [];
        } else {
            // Prepare in all cases, because the querier can be set dynamically.
            // Flat data and fields are used by jsdot.
            $flatData = $this->flatArray($data);
            $fields = $this->extractFields($data);
            // Prepare JsonPath querier.
            $this->jsonPathQuerier = new JSONPath($data);
        }

        foreach ($this->metaMapperConfig->getSection($section) as $fromTo) {
            $to = $fromTo['to'] ?? null;
            if (empty($to)) {
                continue;
            }

            // TODO For default section, remove useless "from path".

            $mod = $fromTo['mod'] ?? [];
            $raw = $mod['raw'] ?? '';
            $val = $mod['val'] ?? '';
            if (strlen($raw)) {
                $resource[$to['dest']] = empty($resource[$to['dest']])
                    ? [$raw]
                    : array_merge($resource[$to['dest']], [$raw]);
                continue;
            }

            // @todo When default, "from" is useless: remove it from normalized config.
            $querier = $fromTo['from']['querier'] ?? 'jsdot';
            $fromPath = $fromTo['from']['path'] ?? null;
            $prepend = $mod['prepend'] ?? '';
            $append = $mod['append'] ?? '';

            // Val is returned only when there is a value from.
            $result = [];
            if ($isDefaultSection) {
                $converted = $this->convertTargetToStringJson($fromTo['from'], $mod, null, $querier, true);
                if ($converted === null || $converted === '') {
                    continue;
                }
                $result[] = strlen($val)
                    ? $val
                    : $prepend . $converted . $append;
            } elseif (is_null($fromPath)) {
                continue;
            } else {
                if ($querier === 'jmespath' || $querier === 'jsonpath') {
                    if ($querier === 'jmespath') {
                        $values = $this->jmesPathEnv->search($fromPath, $data);
                    } else {
                        $values = $this->jsonPathQuerier->find($fromPath)->getData();
                    }
                    if ($values === [] || $values === '' || $values === null) {
                        continue;
                    }
                    $values = is_array($values) ? array_values($values) : [$values];
                    foreach ($values as $value) {
                        // Values should not be an array of array.
                        if (!is_scalar($value)) {
                            continue;
                        }
                        $converted = $this->convertTargetToStringJson($fromTo['from'], $mod, $data, $querier, true);
                        if ($converted === null || $converted === '') {
                            continue;
                        }
                        $result[] = strlen($val)
                            ? $val
                            : $prepend . $converted . $append;
                    }
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
                        if (!is_scalar($value)) {
                            continue;
                        }
                        // Allows to use multiple mappings in one pattern, managing fields.
                        $source = $flatData;
                        $source[$fromPath] = $value;
                        $converted = $this->convertTargetToStringJson($fromTo['from'], $mod, $source, $querier, true);
                        if ($converted === null || $converted === '') {
                            continue;
                        }
                        $result[] = strlen($val)
                            ? $val
                            : $prepend . $converted . $append;
                    }
                }
            }

            if ($result === []) {
                continue;
            }

            $result = array_unique($result);

            $resource[$to['dest']] = empty($resource[$to['dest']])
                ? $result
                : array_merge($resource[$to['dest']], $result);
        }

        return $resource;
    }

    /**
     * Convert all mappings from a section with section type "mapping" for xml.
     *
     * This method should be used when a mapping source ("from") is used
     * multiple times.
     *
     * @param bool $isDefaultSection When true, the target value "to" is added
     * to the resource without using data.
     */
    protected function convertMappingSectionXml(string $section, array $resource, SimpleXMLElement $xml, bool $isDefaultSection = false): array
    {
        // Only sections "default" and "mapping" are a mapping.
        $isMapping = $this->metaMapperConfig->getSectionSettingSub('options', 'section_types', $section) === 'mapping';
        if (!$isMapping) {
            return $resource;
        }

        // TODO Important: see c14n(), that allows to filter document directly with a list of xpath.

        // There is no fields with xml: xpath is smart enough.

        // Use dom because it allows any xpath.
        /** @var \DOMElement $dom */
        $dom = dom_import_simplexml($xml);
        $doc = new DOMDocument();
        $doc->appendChild($doc->importNode($dom, true));

        foreach ($this->metaMapperConfig->getSection($section) as $fromTo) {
            $to = $fromTo['to'] ?? null;
            if (empty($to)) {
                continue;
            }

            $mod = $fromTo['mod'] ?? [];
            $raw = $mod['raw'] ?? '';
            $val = $mod['val'] ?? '';
            if (strlen($raw)) {
                $resource[$to['dest']] = empty($resource[$to['dest']])
                    ? [$raw]
                    : array_merge($resource[$to['dest']], [$raw]);
                continue;
            }

            // Manage an exception: when first datatype is "xml" and there is no
            // pattern, output as xml in order to get full xml when possible.
            $outputAsXml = !empty($to['datatype']) && reset($to['datatype']) === 'xml';

            $from = $fromTo['from']['path'] ?? null;
            $prepend = $mod['prepend'] ?? '';
            $append = $mod['append'] ?? '';

            // Val is returned only when there is a value from.
            $result = [];
            if ($isDefaultSection) {
                $converted = $this->convertTargetToStringXml($from, $mod, null, null, true, $outputAsXml);
                if ($converted === null || $converted === '') {
                    continue;
                }
                $result[] = strlen($val)
                    ? $val
                    : $prepend . $converted . $append;
            } else {
                if (!$from) {
                    continue;
                }
                $values = $this->xpathQuery($doc, $from);
                foreach ($values as $value) {
                    $converted = $this->convertTargetToStringXml($from, $mod, $doc, $value, true, $outputAsXml);
                    if ($converted === null || $converted === '') {
                        continue;
                    }
                    $result[] = strlen($val)
                        ? $val
                        : $prepend . $converted . $append;
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
     * Convert a single field into a string with the configured mapping.
     *
     * @todo Check if "raw" and "val" should be used.
     *
     * @param array|SimpleXMLElement|null $data
     */
    public function convertToString(string $section, string $name, $data): ?string
    {
        // Note: for section type "mapping", the output is the whole setting
        // including "from", "to" and "mod".
        $fromToMod = $this->metaMapperConfig->getSectionSetting($section, $name);
        if (!$fromToMod) {
            return null;
        } elseif ($data instanceof SimpleXMLElement) {
            return $this->convertTargetToStringXml($name, $fromToMod, $data, null, true);
        }

        $querier = is_array($fromToMod) && isset($fromToMod['from']['querier'])
            ? $fromToMod['from']['querier']
            : 'value';
        switch ($querier) {
            default:
                $querier = 'value';
                // no break
            case 'value':
                return $this->convertTargetToStringJson($name, $fromToMod, $data, $querier, true);
            case 'jsdot':
            case 'jmespath':
            case 'jsonpath':
                return $this->convertTargetToStringJson($name, $fromToMod, $data, $querier, true);
            case 'xpath':
                return $this->convertTargetToStringXml($name, $fromToMod, $data, null, true);
        }
    }

    /**
     * Convert a single field from the config into a string via a json path.
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
     * @param string $querier "jsdot" (default), "jmespath" or "jsonpath".
     * @param bool $atLeastOneReplacement When set, don't return a value when a
     * pattern has no replacement,
     * @return string The converted value. Without pattern, return the key
     * "value" from the variables.
     */
    protected function convertTargetToStringJson(
        $from,
        $mod,
        ?array $data = null,
        ?string $querier = null,
        bool $atLeastOneReplacement = false
    ): ?string {
        if (is_null($mod) || is_string($mod)) {
            return $mod;
        }

        if (is_array($from)) {
            $from = $from['path'] ?? null;
        }

        $mod = $mod['mod'] ?? $mod;

        // Querier is jsdot by default.
        if ($querier === 'jmespath') {
            // TODO Check if data for jmespath are cacheable or automatically cached.
            $fromValue = $data && !is_null($from) ? $this->jmesPathEnv->search($from, $data) : null;
        } elseif ($querier === 'jsonpath') {
            // TODO Check if data for jsonpath are cacheable or automatically cached.
            $this->jsonPathQuerier = new JSONPath($data);
            if ($data && !is_null($from)) {
                $fromValue = $this->jsonPathQuerier->find($from)->getData();
            } else {
                $fromValue = null;
            }
        } elseif ($querier === 'jsdot') {
            $flatData = $this->flatArray($data);
            $fromValue = $flatData[$from] ?? null;
        } else {
            $fromValue = $from;
        }

        $this->setVariable('value', $fromValue);

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

        // TODO Remove or reorganize "raw"/"val" in convertTargetToStringJson().
        if (isset($mod['raw']) && strlen($mod['raw'])) {
            return (string) $mod['raw'];
        }

        if (isset($mod['val']) && strlen($mod['val'])) {
            return (string) $mod['val'];
        }

        // When there are data, a query can be used for each variable.
        $replace = [];
        if (!empty($mod['replace'])) {
            if ($data) {
                // TODO Remove exceptions for replace/twig.
                // Manage the exceptions: there is no value here, neither label or list.
                $wrappedQueryExceptions = ['{{ value }}', '{{ label }}', '{{ list }}'];
                foreach ($mod['replace'] as $wrappedQuery) {
                    if (in_array($wrappedQuery, $wrappedQueryExceptions)) {
                        $replace[$wrappedQuery] = '';
                        continue;
                    }
                    $query = mb_substr($wrappedQuery, 2, -2);
                    if ($querier === 'jmespath') {
                        $replace[$wrappedQuery] = $this->jmesPathEnv->search($query, $data);
                    } elseif ($querier === 'jsonpath') {
                        $replace[$wrappedQuery] = $this->jsonPathQuerier->find($query)->getData();
                    } elseif ($querier === 'jsdot') {
                        $replace[$wrappedQuery] = $flatData[$query] ?? '';
                    } else {
                        // TODO A value requires an entry as data.
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
            // Normally never a xml.
            if ($value instanceof DOMNode) {
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

        // Fix issues with quotes: when there is a quote inside a quoted value,
        // or a double quote inside a double quoted value, it causes an issue to
        // twig functions with arguments.
        // So escape them before and after twig process.
        // TODO Fix more accurately the presence of quote string to replace for twig. Only rare cases have issues anyway.
        $hasTwig = !empty($mod['twig']);
        if ($hasTwig) {
            $replaceQuotes = [
                '"' => '__DQUOTE__',
                "'" => '__SQUOTE__',
            ];
            $baseReplace = $replace;
            foreach ($replace as &$replaceValue) {
                $replaceValue = str_replace(array_keys($replaceQuotes), array_values($replaceQuotes), $replaceValue);
            }
            unset($replaceValue);
            $hasQuote = $baseReplace !== $replace;
        }

        $value = $replace
            ? str_replace(array_keys($replace), array_values($replace), $mod['pattern'])
            : $mod['pattern'];

        if ($hasTwig) {
            $value = $this->twig($value, $this->variables, $mod['twig'], $mod['twig_has_replace'] ?? [], $replace);
        }

        if ($hasTwig && $hasQuote) {
            foreach ($replace as &$replaceValue) {
                $replaceValue = str_replace(array_values($replaceQuotes), array_keys($replaceQuotes), $replaceValue);
            }
            unset($replaceValue);
        }

        if ($atLeastOneReplacement
            && !$this->checkAtLeastOneReplacement($fromValue, $value, ['mod' => $mod])
        ) {
            return null;
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
     * $output = 'https://example.com/api/id/150'
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
     * @param \DOMNode|string $fromValue
     * @param bool $atLeastOneReplacement When set, don't return a value when a
     * pattern has no replacement,
     * @param bool $keepXmlContent When set, get the xml content, not the xml
     * node value.
     * @return string The converted value. Without pattern, return the key
     * "value" from the variables.
     */
    protected function convertTargetToStringXml(
        $from,
        $mod,
        $data = null,
        $fromValue = null,
        bool $atLeastOneReplacement = false,
        bool $keepXmlContent = false
    ): ?string {
        if (is_null($mod) || is_string($mod)) {
            return $mod;
        }

        if (is_array($from)) {
            $from = $from['path'] ?? null;
        }

        // TODO c14n() allows to filter nodes with xpath.

        $mod = $mod['mod'] ?? $mod;

        if (is_null($fromValue) && $from && $data) {
            $fromValue = $this->xpathQuery($data, $from);
        }

        if (is_null($fromValue)) {
            $first = null;
        } elseif (is_scalar($fromValue)) {
            $first = (string) $fromValue;
        } elseif ($fromValue instanceof DOMNode) {
            $first = $fromValue;
        } elseif ($fromValue instanceof SimpleXMLElement) {
            // Not used any more. SimpleXml doesn't support context or subquery.
            $first = (string) $fromValue[0];
        } else {
            $first = (string) reset($fromValue);
        }

        $fromValue = $first;
        $this->setVariable('value', $first);

        $keepXmlData = function ($content): string {
            if ($content instanceof DOMNode) {
                return (string) $content->C14N();
            } elseif ($content instanceof SimpleXMLElement) {
                return (string) $content->saveXML();
            }
            return (string) $content;
        };

        if (!isset($mod['pattern']) || !strlen($mod['pattern'])) {
            if ($keepXmlContent) {
                return $keepXmlData($fromValue);
            }
            return $first instanceof DOMNode ? (string) $first->nodeValue : (string) $first;
        }

        if ($mod['pattern'] === '{{ xml }}') {
            if ($keepXmlContent) {
                return $keepXmlData($fromValue);
            }
        }

        // TODO Remove or reorganize "raw"/"val" in convertTargetToStringXml().
        if (isset($mod['raw']) && strlen($mod['raw'])) {
            return (string) $mod['raw'];
        }

        if (isset($mod['val']) && strlen($mod['val'])) {
            return (string) $mod['val'];
        }

        // When there are data, a query can be used for each variable.
        $replace = [];
        if (!empty($mod['replace'])) {
            if ($data) {
                // TODO Remove exceptions for replace/twig.
                // Manage the exceptions: there is no value here, neither label or list.
                $wrappedQueryExceptions = ['{{ value }}', '{{ label }}', '{{ list }}'];
                foreach ($mod['replace'] as $wrappedQuery) {
                    if (in_array($wrappedQuery, $wrappedQueryExceptions)) {
                        $replace[$wrappedQuery] = '';
                        continue;
                    }
                    $query = mb_substr($wrappedQuery, 2, -2);
                    $answer = $this->xpathQuery($data, $query, $first instanceof DOMNode ? $first : null);
                    if (count($answer)) {
                        $firstAnswer = reset($answer);
                        $replace[$wrappedQuery] = $firstAnswer instanceof DOMNode
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
            if ($value instanceof DOMNode) {
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

        // Fix issues with quotes: when there is a quote inside a quoted value,
        // or a double quote inside a double quoted value, it causes an issue to
        // twig functions with arguments.
        // So escape them before and after twig process.
        // TODO Fix more accurately the presence of quote string to replace for twig. Only rare cases have issues anyway.
        $hasTwig = !empty($mod['twig']);
        if ($hasTwig) {
            $replaceQuotes = [
                '"' => '__DQUOTE__',
                "'" => '__SQUOTE__',
            ];
            $baseReplace = $replace;
            foreach ($replace as &$replaceValue) {
                $replaceValue = str_replace(array_keys($replaceQuotes), array_values($replaceQuotes), $replaceValue);
            }
            unset($replaceValue);
            $hasQuote = $baseReplace !== $replace;
        }

        $value = $replace
            ? str_replace(array_keys($replace), array_values($replace), $mod['pattern'])
            : $mod['pattern'];

        if ($hasTwig) {
            $value = $this->twig($value, $this->variables, $mod['twig'], $mod['twig_has_replace'] ?? [], $replace);
        }

        if ($hasTwig && $hasQuote) {
            foreach ($replace as &$replaceValue) {
                $replaceValue = str_replace(array_values($replaceQuotes), array_keys($replaceQuotes), $replaceValue);
            }
            unset($replaceValue);
        }

        if ($atLeastOneReplacement
            && !$this->checkAtLeastOneReplacement((string) ($fromValue instanceof DOMNode ? $fromValue->nodeValue : $fromValue), $value, ['mod' => $mod])
        ) {
            return null;
        }

        return $value;
    }

    /**
     * Check if the result of a transformation with string and twig replacements
     * has at least one replacement, so at least one value.
     * Nevertheless, if the pattern does not contain any static string, the
     * check is skipped.
     *
     * It avoids to return something when there is no transformation or no
     * value. For example for pattern "pattern for {{ value|trim }} with {{/source/record/data}}",
     * if there is no value and no source record data, the transformation
     * returns something, the raw text of the pattern, but this is useless.
     *
     * This method does not check for transformation with "raw" or "val".
     */
    protected function checkAtLeastOneReplacement(?string $value, ?string $result, array $map): bool
    {
        if (is_null($value)
            || is_null($result)
            || !strlen($result)
            || empty($map['mod'])
            || empty($map['mod']['pattern'])
        ) {
            return false;
        }

        $allReplacements = array_merge(
            // TODO Remove exceptions {{ value }}, {{ label }}, {{ list }}.
            ['{{ value }}', '{{ label }}', '{{ list }}'],
            $map['mod']['replace'] ?? [],
            $map['mod']['twig'] ?? []
        );

        // First, check if the pattern contains static string.
        $check = trim(str_replace($allReplacements, '', $map['mod']['pattern']));
        if (!strlen($check)) {
            return true;
        }

        // Second, check if all replacements in value is different from result.
        return str_replace($allReplacements, '', $value) !== $result;
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
     * @see \ValueSuggestAny\Suggester\JsonLd\JsonLdSuggester::flatArray()
     * @todo Factorize flatArray() between modules.
     * @todo Cache flat array (at least the last ones, checked via a hash).
     *
     * @todo Move to common Bulk?
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
     * @todo Find a way to keep the last level of array (list of subjects…), currently managed with fields. Useless: use jsonpath or jmespath.
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
        $fieldsKey = $this->metaMapperConfig->getSectionSetting('params', 'fields');
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
        $fieldKey = $this->metaMapperConfig->getSectionSetting('params', 'fields.key');
        $fieldValue = $this->metaMapperConfig->getSectionSetting('params', 'fields.value');

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
        if ($xml instanceof SimpleXMLElement) {
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
}
