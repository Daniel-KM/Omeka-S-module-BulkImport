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

use Flow\JSONPath\JSONPath;
use JmesPath\Env as JmesPathEnv;
use JmesPath\Parser as JmesPathParser;
use Laminas\Log\Logger;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Log\Stdlib\PsrMessage;
use SimpleXMLElement;

class MetaMapperConfig extends AbstractPlugin
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
     * Cache for configs.
     *
     * @var array
     */
    protected $configs = [
        'ec58905619984f5c3dbb308c5556df58' => [
            'info' => [
                // Label is the only required data.
                'label' => 'Empty config', // @translate
                'querier' => null,
                'mapper' => null,
                'example' => null,
            ],
            'params' => [],
            'default' => [],
            'mapping' => [],
            // List of tables (associative arrays) indexed by their name.
            'tables' => [],
            'has_error' => false,
        ],
    ];

    /**
     * @var string
     */
    protected $name;

    /**
     * Current options.
     */
    public function __construct(
        Logger $logger,
        AutomapFields $automapFields,
        Bulk $bulk
    ) {
        $this->logger = $logger;
        $this->automapFields = $automapFields;
        $this->bulk = $bulk;
        $this->jmesPathEnv = new JmesPathEnv;
        $this->jmesPathParser = new JmesPathParser;
        $this->jsonPathQuerier = new JSONPath;
    }

    /**
     * @todo Finalize separation with MetaMapper for all readers and processors.
     *
     * Prepare a config to simplify any import into Omeka and transform a source.
     *
     * It can be used as headers of a spreadsheet, or in an import config, or to
     * extract metadata from files json or xml files. It contains a list of
     * mappings between source data and destination data.
     *
     * A config contains four sections:
     * - info: label, base mapper if any, querier to use, example of source;
     * - params: what to import (metadata or files) and constants and tables;
     * - default: default maps when creating resources, for example the owner;
     * - mapping: the maps to use for the import.
     *
     * Each map is based on a source and a destination, eventually modified.
     * So the internal representation of the maps is:
     *
     * ```php
     * [
     *     [
     *          'from' => [
     *              'querier' => 'xpath',
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
     *              'val' => null,
     *              'prepend' => 'Title is: ',
     *              'pattern' => 'pattern for {{ value|trim }} with {{/source/record/data}}',
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
     * Such a config can be created from an array, a list of fields (for example
     * headers of a spreadsheet), an ini-like or xml file, or stored in database
     * as ini-like or xml. It can be based on another config.
     *
     * For example, the ini map for the map above is:
     *
     * ```
     * /record/datafield[@tag='200'][@ind1='1']/subfield[@code='a'] = dcterms:title @fra ^^literal §public ~ pattern for {{ value|trim }} with {{/source/record/data}}
     * ```
     *
     * The same mapping for the xml is:
     *
     * ```xml
     * <mapping>
     *     <map>
     *         <from xpath="/record/datafield[@tag='200']/subfield[@code='a']"/>
     *         <to field="dcterms:title" language="fra" datatype="literal" visibility="public"/>
     *         <mod append="Title is: " pattern="pattern for {{ value|trim }} with {{/source/record/data}}"/>
     *     </map>
     * </mapping>
     * ```
     *
     * The default querier is to take the value provided by the reader.
     *
     * Note that a ini config has a static querier (the same for all maps), but
     * a xml config has a dynamic querier (set as attribute of element "from").
     *
     * For more information and formats: see {@link https://gitlab.com/Daniel-KM/Omeka-S-module-BulkImport}.
     *
     * @param string $name The name of the config to get or to set. It is not
     *   overridable once built.
     * @param mixed $config
     * @param array $options
     * @return self|array The normalized config.
     */
    public function __invoke(?string $name = null, $config = null, array $options = [])
    {
        if (is_null($name) && is_null($config)) {
            return $this;
        }

        if (is_null($name) && !is_null($config)) {
            $name = md5(serialize($config));
        }

        $this->setName($name);

        if (is_null($config)) {
            return $this->getMergedConfig();
        }

        // The config is not overridable.
        if (!isset($this->configs[$name]) && !is_null($config)) {
            $this->prepareConfig($config, $options);
        }

        return $this->getMergedConfig($name);
    }

    public function getMergedConfig(?string $name = null): ?array
    {
        $config = $this->getSimpleConfig($name);
        if (is_null($config)) {
            return null;
        }

        // TODO Recusively merge configs.

        return $config;
    }

    public function getSimpleConfig(?string $name = null): ?array
    {
        return $this->configs[$name ?? $this->name] ?? null;
    }

    public function isValidConfig(?string $name = null): ?array
    {
        $config = $this->getConfig($name);
        return is_null($config)
            ? false
            : $config['has_error'];
    }

    protected function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    protected function prepareConfig($config, array $options): self
    {
        // Check for a normalized config.
        $normalizedConfig = null;
        if (empty($config)) {
            // Nothing to do.
        } elseif (is_array($config) && isset($config['info'])) {
            $normalizedConfig = $this->prepareConfigNormalized($config, $options);
        } elseif (is_array($config)) {
            $normalizedConfig = $this->prepareConfigList($config, $options);
        } else {
            $content = $this->prepareConfigContent($config);
            if ($content) {
                $normalizedConfig = $this->prepareConfigFull($content, $options);
            }
        }

        // Validate config as a whole for default and maping.
        if ($normalizedConfig) {
            foreach (['default', 'mapping'] as $section) {
                if (!$this->isValidMapping($normalizedConfig[$section], $options)) {
                    $this->logger->warn(
                        'Config "{config_name}": invalid map in section "{section}".',
                        ['config_name' => $this->name, 'section' => $section]
                    );
                    $normalizedConfig['has_error'] = true;
                }
            }
        }

        $this->configs[$this->name] = $normalizedConfig ?: $this->configs['40cd750bba9870f18aada2478b24840a'];
        return $this;
    }

    /**
     * Normalize a list of map, like spreadsheet headers.
     */
    protected function prepareConfigList(array $config, array $options): array
    {
        $normalizedConfig = [
            'info' => [
                'label' => $options['label'] ?? $this->name,
                'querier' => null,
                'mapper' => $options['mapper'] ?? null,
                'example' => $options['example'] ?? null,
            ],
            'params' => $options['params'] ?? [],
            'default' => $options['default'] ?? [],
            'mapping' => $config,
            // TODO Options or config to store tables in config list (for spreadsheet)?
            'tables' => $options['tables'] ?? [],
            'has_error' => false,
        ];

        foreach (['default', 'mapping'] as $section) {
            $options['section'] = $section;
            $normalizedConfig[$section] = $this->normalizeMapping($normalizedConfig[$section], $options);
        }

        // TODO Merge with upper configs.

        return $normalizedConfig;
    }

    /**
     * Check and store a ready-normalized config.
     */
    protected function prepareConfigNormalized(array $config, array $options): array
    {
        $normalizedConfig = array_intersect_key($config, array_flip(['info', 'params', 'default', 'mapping']));
        $normalizedConfig['has_error'] = false;
        if (!isset($config['info']) || !is_array($config['info'])
            || (array_key_exists('params', $config) && !is_array($config['params']))
            || (array_key_exists('default', $config) && !is_array($config['default']))
            || (array_key_exists('mapping', $config) && !is_array($config['mapping']))
        ) {
            $this->logger->warn(
                'Config "{config_name}": invalid provided config.',
                ['config_name' => $this->name]
            );
            $normalizedConfig['has_error'] = true;
            return $normalizedConfig;
        }

        $normalizedConfig['info']['label'] = !empty($normalizedConfig['info']['label']) && is_string($normalizedConfig['info']['label'])
            ? $normalizedConfig['info']['label']
            : $this->name;
        $normalizedConfig['info']['querier'] = !empty($normalizedConfig['info']['querier']) && is_string($normalizedConfig['info']['querier'])
            ? $normalizedConfig['info']['querier']
            : null;
        $normalizedConfig['info']['mapper'] = !empty($normalizedConfig['info']['mapper']) && is_string($normalizedConfig['info']['mapper'])
            ? $normalizedConfig['info']['mapper']
            : null;
        $normalizedConfig['info']['example'] = !empty($normalizedConfig['info']['example']) && is_string($normalizedConfig['info']['example'])
            ? $normalizedConfig['info']['example']
            : null;

        foreach (['default', 'mapping'] as $section) {
            $options['section'] = $section;
            $normalizedConfig[$section] = $this->normalizeMapping($normalizedConfig[$section], $options);
        }

        return $normalizedConfig;
    }

    /**
     * Get the content of a file or a mapping from the reference.
     */
    protected function prepareConfigContent(?string $mappingConfig, ?string $defaultPrefix = null, int $loop = 0): ?string
    {
        if (empty($mappingConfig)) {
            return null;
        }

        $prefixes = [
            'user' => $this->bulk->getBasePath() . '/mapping/',
            'module' => dirname(__DIR__, 4) . '/data/mapping/',
            'base' => dirname(__DIR__, 4) . '/data/mapping/base/',
        ];

        $content = null;
        if (mb_substr($mappingConfig, 0, 8) === 'mapping:') {
            $mappingId = (int) mb_substr($mappingConfig, 8);
            /** @var \BulkImport\Api\Representation\MappingRepresentation $mapping */
            $mapping = $this->bulk->api()->searchOne('bulk_mappings', ['id' => $mappingId])->getContent();
            if (!$mapping) {
                return null;
            }
            $content = $mapping->mapping();
        } else {
            $filename = basename($mappingConfig);
            if (empty($filename)) {
                return null;
            }

            if (strpos($mappingConfig, ':')) {
                $prefix = strtok($mappingConfig, ':');
            } else {
                $prefix = $defaultPrefix;
                $mappingConfig = $prefix . ':xml/' . $mappingConfig;
            }
            if (!isset($prefixes[$prefix])) {
                return null;
            }

            $file = mb_substr($mappingConfig, strlen($prefix) + 1);
            $filepath = $prefixes[$prefix] . $file;
            if (file_exists($filepath) && is_file($filepath) && is_readable($filepath) && filesize($filepath)) {
                $content = trim((string) file_get_contents($filepath));
            }
        }

        if (!$content) {
            return null;
        }

        // Xml config may include sub mappings, so merge them one time early.
        if (!mb_substr($content, 0, 1) === '<'
            || !mb_strpos($content, '<include')
        ) {
            return $content;
        }

        if ($loop > 20) {
            $this->logger->err('Too many included mappings or recursive mapping.'); // @translate
            return null;
        }

        $doc = simplexml_load_string($content);
        $includes = $doc->xpath('//include[@mapping][@mapping != ""]');
        $defaultPrefix = strtok($mappingConfig, ':') ?: $defaultPrefix;
        foreach ($includes as $include) {
            $subContent = $this->prepareConfigContent((string) $include['mapping'], $defaultPrefix, ++$loop);
            // TODO Use standard simple xml way to replace a node,
            if ($subContent) {
                /** @see self::prepareConfigFullXml() */
                // Currently, the process uses an array conversion to get xml
                // maps. so sub maps are not managed, so remove mapping here.
                // TODO Allow to manage sub map or use simplexml way to remove submapping.
                $subContent = str_replace([
                    '<?xml version="1.0"?>',
                    '<?xml version="1.0" encoding="UTF-8"?>',
                    '<mapping>',
                    '</mapping>',
                ], '', $subContent);
                $content = str_replace((string) $include->asXml(), $subContent, $content);
            }
        }

        return $content;
    }

    /**
     * Normalize a full mapping from a string.
     */
    protected function prepareConfigFull(string $config, array $options): array
    {
        // The config should be trimmed to check the first character.
        $config = trim($config);
        if (!strlen($config)) {
            return [
                'info' => [
                    'label' => $options['label'] ?? $this->name,
                    'querier' => null,
                    'mapper' => null,
                    'example' => null,
                ],
                'params' => [],
                'default' => [],
                'mapping' => $config,
                'tables' => [],
                'has_error' => false,
            ];
        }

        $isXml = mb_substr($config, 0, 1) === '<';
        return $isXml
            ? $this->prepareConfigFullXml($config, $options)
            : $this->prepareConfigFullIni($config, $options);
    }

    protected function prepareConfigFullIni(string $config, array $options): array
    {
        // parse_ini_string() cannot be used, because some characters are
        // forbidden on the left and the right part may be not quoted.
        // So process by line.

        $normalizedConfig = [];

        $sectionsConfigTypes = $options['section_types'] ?? [
            'info' => 'raw',
            'params' => 'raw_or_pattern',
            'default' => 'mapping',
            'mapping' => 'mapping',
        ];
        unset($sectionsConfigTypes['has_error']);

        // Lines are trimmed. Empty lines are removed.
        $lines = $this->bulk->stringToList($config);

        $section = null;
        $sectionType = null;
        $options['section'] = null;
        $options['section_type'] = null;
        foreach ($lines as $line) {
            // Skip comments.
            if (mb_substr($line, 0, 1) === ';') {
                continue;
            }

            $first = mb_substr($line, 0, 1);
            $last = mb_substr($line, -1);

            // Check for a section.
            if ($first === '[' && $last === ']') {
                $section = trim(mb_substr($line, 1, -1));
                $sectionType = null;
                $options['section'] = null;
                $options['section_type'] = null;
                // A section should have a name.
                if ($section === '') {
                    $section = null;
                    $normalizedConfig['has_error'] = new PsrMessage('A section should have a name.'); // @translate
                    continue;
                } elseif (!isset($sectionsConfigTypes[$section])) {
                    $section = null;
                    $normalizedConfig['has_error'] = new PsrMessage(
                        'The section "{name}" is not managed.', // @translate
                        ['name' => $section]
                    );
                    continue;
                } else {
                    $normalizedConfig[$section] = [];
                }
                $sectionType = $sectionsConfigTypes[$section];
                $options['section'] = $section;
                $options['section_type'] = $sectionType;
                continue;
            } elseif (!$section) {
                $options['section'] = null;
                $options['section_type'] = null;
                continue;
            }

            // Add a key/value pair to the current section.

            $map = $this->normalizeMapFromStringIni($line, $options);
            if (in_array($sectionType, ['raw', 'pattern', 'raw_or_pattern'])) {
                $normalizedConfig[$section][$map['from']] = $map['to'];
            } else {
                $normalizedConfig[$section][] = $map;
            }
        }

        // TODO Add tables for config ini.

        return $normalizedConfig;
    }

    protected function prepareConfigFullXml(string $config, array $options): array
    {
        $normalizedConfig = [
            'info' => [
                'label' => $options['label'] ?? $this->name,
                'querier' => null,
                'mapper' => null,
                'example' => null,
            ],
            'params' => [],
            'default' => [],
            'mapping' => [],
            'tables' => [],
            'has_error' => false,
        ];

        // The config is always a small file (less than some megabytes), so it
        // can be managed directly with SimpleXml.
        try {
            $xmlConfig = new SimpleXMLElement($config);
            if (!$xmlConfig) {
                throw new \Exception;
            }
        } catch (\Exception $e) {
            $normalizedConfig['has_error'] = new PsrMessage('The xml string is not a valid xml.'); // @translate
            return $normalizedConfig;
        }

        // TODO Xml config for info and params.
        foreach ($xmlConfig->info as $element) {
            $normalizedConfig['info'][] = [];
        }

        foreach ($xmlConfig->params as $element) {
            $normalizedConfig['params'][] = [];
        }

        // This value allows to keep the original dest single.
        // @todo Remove [to][dest] and "k".
        $k = 0;
        foreach (['default', 'mapping'] as $section) {
            $isDefault = $section === 'default';
            // TODO Use an attribute or a sub-element ?
            $i = 0;
            $options['section'] = $section;
            // TODO Include xml includes here.
            foreach ($xmlConfig->map as $element) {
                $hasXpath = (bool) $element->from['xpath'];
                if (($isDefault && $hasXpath)
                    || (!$isDefault && !$hasXpath)
                ) {
                    continue;
                }
                $options['index'] = ++$i;
                $fromTo = $this->normalizeMapFromXml($element, $options);
                if (isset($fromTo['to']['dest'])) {
                    $fromTo['to']['dest'] = $fromTo['to']['dest'] . str_repeat(' ', $k++);
                }
                $normalizedConfig[$section][] = $fromTo;
            }
        }

        // Prepare tables.
        foreach ($xmlConfig->table as $table) {
            $code = (string) $table['code'];
            if (!$code) {
                continue;
            }
            foreach ($table->list[0]->term as $term) {
                $termCode = (string) $term['code'];
                if (strlen($termCode)) {
                    $normalizedConfig['tables'][$code][$termCode] = (string) $term[0];
                }
            }
        }

        return $normalizedConfig;
    }

    /**
     * Normalize a mapping (the default one or the main mapping).
     *
     * @return array The mapping is returned. Each map can contain an error.
     */
    public function normalizeMapping($mapping, array $options = []): array
    {
        if (empty($mapping) || !is_array($mapping)) {
            return [];
        }

        $normalizedMapping = [];
        $normalizedMapDefault = [
            'from' => [],
            'to' => [],
            'mod' => [],
        ];

        foreach ($mapping as $indexMap => $map) {
            $options['index'] = $indexMap;
            if (is_null($map) || empty($map)) {
                $normalizedMapping[] = $normalizedMapDefault;
                continue;
            }
            $normalizedMap = $this->normalizeMap($map, $options);
            if (!empty($normalizedMap['has_error'])) {
                $this->logger->warn(
                    'Config "{config_name}": contains an invalid map.', // @translate
                    ['config_name' => $this->name]
                );
            }
            // An input map can create multiple maps, for example a name mapped
            // to foaf:name and foaf:familyName.
            if (!empty($normalizedMap) && is_numeric(key($normalizedMap))) {
                foreach ($normalizedMap as $normalizedMapp) {
                    $normalizedMapping[] = $normalizedMapp;
                }
            } else {
                $normalizedMapping[] = $normalizedMap;
            }
        }

        return $normalizedMapping;
    }

    public function isValidMapping($mapping, array $options = []): bool
    {
        if (!is_array($mapping)) {
            return false;
        }
        foreach ($mapping as $map) {
            if (!empty($map['has_error'])) {
                return false;
            }
        }
        return true;
    }

    public function isValidMap($map, array $options = []): bool
    {
        // TODO Add more check to check a valid map.
        return is_array($map)
            && empty($map['has_error']);
    }

    /**
     * Warning: a map can be normalized to multiple maps, for example a name
     * mapped to foaf:name and foaf:familyName.
     */
    public function normalizeMap($map, array $options = []): array
    {
        if (empty($map)) {
            return $this->normalizeMapFromEmpty($map, $options);
        }
        if (is_string($map)) {
            return $this->normalizeMapFromString($map, $options);
        }
        if (is_array($map)) {
            // When the map is an array of maps, each map can be a string or an
            // array, so do a recursive loop in such a case.
            // Only one level is allowed.
            if (is_numeric(key($map))) {
                $result = [];
                foreach ($map as $mapp) {
                    $result[] = $this->normalizeMap($mapp, $options);
                }
                return $result;
            }
            return $this->normalizeMapFromArray($map, $options);
        }
        return [
            'from' => [],
            'to' => [],
            'mod' => [],
            'has_error' => true,
        ];
    }

    protected function normalizeMapFromEmpty($map, array $options): array
    {
        return [
            'from' => [],
            'to' => [],
            'mod' => [],
        ];
    }

    protected function normalizeMapFromString($map, array $options): array
    {
        // When first character is "<", it's an xml because it cannot be ini.
        $isXml = mb_substr($map, 0, 1) === '<';
        return $isXml
            ? $this->normalizeMapFromStringXml($map, $options)
            : $this->normalizeMapFromStringIni($map, $options);
    }

    protected function normalizeMapFromStringIni(string $map, array $options): array
    {
        $map = trim($map);

        $normalizedMap = [
            'from' => [],
            'to' => [],
            'mod' => [],
        ];

        if (isset($options['index'])) {
            $normalizedMap['from']['index'] = $options['index'];
        }

        // Skip comments.
        if (!mb_strlen($map) || mb_substr($map, 0, 1) === ';') {
            return $normalizedMap;
        }

        // The map may be only a destination (like spreadsheet headers), so
        // without left and right part, so prepend a generic left part.
        $p = mb_strpos($map, '~');
        $hasOnlyDestination = mb_strpos($p === false ? $map : strtok($map, '~'), '=') === false;
        if ($hasOnlyDestination) {
            $map = '~ = ' . $map;
        }

        $first = mb_substr($map, 0, 1);
        // $last = mb_substr($map, -1);

        // The left part can be a xpath, a jmespath, etc. with a "=". On the
        // right part, only a pattern can contain a "=". So split the line
        // according to the presence of a pattern prefixed with a `~`.
        // The left part may be a destination field too when the right part
        // is a raw content (starting with « " » or « ' »).
        // When the left part is "~", it means a value from the reader.
        // TODO The left part cannot contain a "~" for now.
        $pos = $first === '~'
            ? mb_strpos($map, '=')
            : mb_strrpos(strtok($map, '~'), '=');
        if ($pos === false) {
            $normalizedMap['has_error'] = new PsrMessage('The map "{map}" has no source or destination.', // @translate
                ['map' => $map]
            );
            return $normalizedMap;
        }

        $from = trim(mb_substr($map, 0, $pos));
        if (!strlen($from)) {
            $normalizedMap['has_error'] = new PsrMessage('The map "{map}" has no source.', // @translate
                ['map' => $map]
            );
            return $normalizedMap;
        }

        // Trim leading and trailing quote/double quote only when paired.
        $to = trim(mb_substr($map, $pos + 1));
        $originalTo = $to;
        $isRaw = (mb_substr($to, 0, 1) === '"' && mb_substr($to, -1) === '"')
            || (mb_substr($to, 0, 1) === "'" && mb_substr($to, -1) === "'");
        if ($isRaw) {
            $to = trim(mb_substr($to, 1, -1));
        }

        $sectionType = $options['sectionType'] ?? null;

        if ($sectionType === 'raw') {
            return [
                'from' => $from,
                'to' => strlen($to) ? $to : null,
            ];
        } elseif ($sectionType === 'pattern') {
            $normalizedTo = $this->preparePattern($originalTo);
            return [
                'from' => $from,
                'to' => $normalizedTo,
            ];
        } elseif ($sectionType === 'raw_or_pattern') {
            if ($isRaw || mb_substr($to, 0, 1) !== '~') {
                if ($isRaw) {
                    return [
                        'from' => $from,
                        'to' => $to,
                    ];
                } else {
                    $mapRaw = ['true' => true, 'false' => false, 'null' => null];
                    return [
                        'from' => $from,
                        'to' => $mapRaw[strtolower($to)] ?? $to,
                    ];
                }
            } else {
                $normalizedTo = $this->preparePattern(trim(mb_substr($to, 1)));
                return [
                    'from' => $from,
                    'to' => $normalizedTo,
                ];
            }
        } else {
            // Type of section is "mapping".
            if (!strlen($to)) {
                if (!strlen($from)) {
                    $normalizedMap['has_error'] = new PsrMessage('The map "{map}" has no destination.', // @translate
                        ['map' => trim($map, "= \t\n\r\0\x0B")]
                    );
                    return $normalizedMap;
                }
            }
            // Manage default values: dcterms:license = "Public domain"
            // and default mapping: dcterms:license = dcterms:license ^^literal ~ "Public domain"
            // and full source mapping: license = dcterms:license ^^literal ~ "Public domain"
            $toDest = $isRaw
                ? $from . ' ~ ' . $originalTo
                : $to;
            $ton = $this->normalizeToFromString($toDest, $options);
            if (!$ton) {
                $normalizedMap['has_error'] = new PsrMessage('The map "{map}" has an invalid destination "{destination}".', // @translate
                    ['map' => $map, 'destination' => $to]
                );
                return $normalizedMap;
            }
        }

        // For resource properties by default.
        // Most of other metadata have no data anyway.
        $toKeys = $options['to_keys'] ?? [
            'field' => null,
            'property_id' => null,
            'datatype' => null,
            'language' => null,
            'is_public' => null,
        ];

        $modKeys = $options['mod_keys'] ?? [
            'raw' => null,
            'val' => null,
            'prepend' => null,
            'pattern' => null,
            'append' => null,
            'replace' => [],
            'twig' => [],
        ];

        if (!$hasOnlyDestination) {
            $normalizedMap['from']['path'] = $from;
        }
        $normalizedMap['to'] = array_intersect_key($ton, $toKeys);
        $normalizedMap['mod'] = array_filter(array_intersect_key($ton, $modKeys), function ($v) {
            return !is_null($v) && $v !== '' && $v !== [];
        });
        return $normalizedMap;
    }

    protected function normalizeMapFromStringXml($map, array $options): array
    {
        try {
            $mapXml = new SimpleXMLElement($map);
            if (!$mapXml) {
                throw new \Exception();
            }
        } catch (\Exception $e) {
            return [
                'from' => [],
                'to' => [],
                'mod' => [],
                'has_error' => new PsrMessage('The xml string is not a valid xml.'), // @translate
            ];
        }
        return $this->normalizeMapFromXml($mapXml, $options);
    }

    protected function normalizeMapFromXml(SimpleXMLElement $map, array $options): array
    {
        // Since anything is set inside attributes, convert it into an array
        // via a json conversion.
        $xmlArray = json_decode(json_encode($map), true);

        $index = $options['index'] ?? 0;
        $isDefaultMapping = ($options['section'] ?? null) === 'default';

        $result = [
            'from' => [],
            'to' => [],
            'mod' => [],
            'has_error' => false,
        ];

        if ($isDefaultMapping) {
            $result['from'] = null;
        } elseif (isset($xmlArray['from']['@attributes']['jsdot']) && strlen((string) $xmlArray['from']['@attributes']['jsdot'])) {
            $result['from'] = ['querier' => 'jsdot', 'path' => (string) $xmlArray['from']['@attributes']['jsdot']];
        } elseif (isset($xmlArray['from']['@attributes']['jmespath']) && strlen((string) $xmlArray['from']['@attributes']['jmespath'])) {
            $result['from'] = ['querier' => 'jmespath', 'path' => (string) $xmlArray['from']['@attributes']['jmespath']];
        } elseif (isset($xmlArray['from']['@attributes']['jsonpath']) && strlen((string) $xmlArray['from']['@attributes']['jsonpath'])) {
            $result['from'] = ['querier' => 'jsonpath', 'path' => (string) $xmlArray['from']['@attributes']['jsonpath']];
        } elseif (isset($xmlArray['from']['@attributes']['xpath']) && strlen((string) $xmlArray['from']['@attributes']['xpath'])) {
            $result['from'] = ['querier' => 'xpath', 'path' => (string) $xmlArray['from']['@attributes']['xpath']];
        } else {
            $result['has_error'] = new PsrMessage('The map has no path.'); // @translate
            return $result;
        }

        if (!isset($xmlArray['to']['@attributes']['field'])
            || !strlen((string) $xmlArray['to']['@attributes']['field'])
        ) {
            $result['has_error'] = new PsrMessage(
                'The mapping "{index}" has no destination.', // @translate
                ['index' => $index]
            );
            return $result;
        }

        // @todo The use of automapFields is simpler, so merge the values and output it?

        $result['to']['field'] = (string) $xmlArray['to']['@attributes']['field'];

        $termId = $this->bulk->getPropertyId($result['to']['field']);
        if ($termId) {
            $result['to']['property_id'] = $termId;
        }

        $result['to']['datatype'] = [];
        if (isset($xmlArray['to']['@attributes']['datatype']) && $xmlArray['to']['@attributes']['datatype'] !== '') {
            // Support short data types and custom vocab labels.
            // @see \BulkImport\Mvc\Controller\Plugin::PATTERN_DATATYPES
            $matchesDataTypes = [];
            $patternDataTypes = '#(?<datatype>(?:customvocab:(?:"[^\n\r"]+"|\'[^\n\r\']+\')|[a-zA-Z_][\w:-]*))#';
            if (preg_match_all($patternDataTypes, (string) $xmlArray['to']['@attributes']['datatype'], $matchesDataTypes, PREG_SET_ORDER, 0)) {
                foreach (array_column($matchesDataTypes, 'datatype') as $datatype) {
                    $result['to']['datatype'][] = $this->bulk->getDataTypeName($datatype);
                }
                $result['to']['datatype'] = array_filter(array_unique($result['to']['datatype']));
            }
        }
        $result['to']['language'] = isset($xmlArray['to']['@attributes']['language'])
            ? (string) $xmlArray['to']['@attributes']['language']
            : null;
        $result['to']['is_public'] = isset($xmlArray['to']['@attributes']['visibility'])
            ? ((string) $xmlArray['to']['@attributes']['visibility']) !== 'private'
            : null;

        $result['mod']['raw'] = isset($xmlArray['mod']['@attributes']['raw']) && strlen((string) $xmlArray['mod']['@attributes']['raw'])
            ? (string) $xmlArray['mod']['@attributes']['raw']
            : null;
        $hasNoRaw = is_null($result['mod']['raw']);
        $result['mod']['val'] = $hasNoRaw && isset($xmlArray['mod']['@attributes']['val']) && strlen((string) $xmlArray['mod']['@attributes']['val'])
            ? (string) $xmlArray['mod']['@attributes']['val']
            : null;
        $hasNoVal = is_null($result['mod']['val']);
        $hasNoRawVal = $hasNoRaw && $hasNoVal;
        $result['mod']['prepend'] = $hasNoRawVal && isset($xmlArray['mod']['@attributes']['prepend'])
            ? (string) $xmlArray['mod']['@attributes']['prepend']
            : null;
        $result['mod']['append'] = $hasNoRawVal && isset($xmlArray['mod']['@attributes']['append'])
            ? (string) $xmlArray['mod']['@attributes']['append']
            : null;
        $result['mod']['pattern'] = null;
        if ($hasNoRawVal && isset($xmlArray['mod']['@attributes']['pattern'])) {
            $r = $this->preparePattern((string) $xmlArray['mod']['@attributes']['pattern']);
            if (isset($r['raw']) && strlen($r['raw'])) {
                $result['mod']['raw'] = $r['raw'];
                $result['mod']['val'] = null;
                $hasNoRaw = false;
                $hasNoVal = true;
                $hasNoRawVal = false;
            }
            // Not possible anyway.
            elseif (isset($r['val']) && strlen($r['val'])) {
                $result['mod']['raw'] = null;
                $result['mod']['val'] = $r['val'];
                $hasNoRaw = true;
                $hasNoVal = false;
                $hasNoRawVal = false;
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

        // @todo Remove the short destination (used in processor and when converting to avoid duplicates).
        $fullPattern = $hasNoRawVal
            ? ($result['mod']['prepend'] ?? '') . ($result['mod']['pattern'] ?? '') . ($result['mod']['append'] ?? '')
            : (isset($result['mod']['raw']) ? (string) $result['mod']['raw'] : (string) $result['mod']['val']);
        $result['to']['dest'] = $result['to']['field']
            // Here, the short datatypes and custom vocab labels are already cleaned.
            . (count($result['to']['datatype']) ? ' ^^' . implode(' ^^', $result['to']['datatype']) : '')
            . (isset($result['to']['language']) ? ' @' . $result['to']['language'] : '')
            . (isset($result['to']['is_public']) ? ' §' . ($result['to']['is_public'] ? 'public' : 'private') : '')
            . (strlen($fullPattern) ? ' ~ ' . $fullPattern : '')
        ;

        return $result;
    }

    /**
     * Here, the array should be already tested and no a nested array.
     */
    protected function normalizeMapFromArray(array $map, array $options): array
    {
        $map = array_intersect_key($map, array_flip(['from', 'to', 'mod']));
        // Only "to" is required.
        if (!is_array($map)
            || (array_key_exists('from', $map) && !is_array($map['from']))
            || !isset($map['to']) || !is_array($map['to'])
            || (array_key_exists('mod', $map) && !is_array($map['mod']))
        ) {
            $map['has_error'] = true;
            return $map;
        }

        // TODO Add more check for map by array.

        return $map;
    }

    protected function normalizeToFromString(string $string, array $options): array
    {
        $defaultOptions = [
            'check_field' => false,
            'output_full_matches' => true,
            'output_property_id' => true,
        ];

        $result = $this->automapFields->__invoke([$string], $defaultOptions + $options);
        if (empty($result)) {
            return null;
        }

        // With output_full_matches, there is one more level, so reset twice.
        $result = reset($result);
        return $result
            ? reset($result)
            : null;
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
}
