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
use Flow\JSONPath\JSONPath;
use JmesPath\Env as JmesPathEnv;
use JmesPath\Parser as JmesPathParser;
use Laminas\Log\Logger;
use Log\Stdlib\PsrMessage;
use SimpleXMLElement;

class MetaMapperConfig
{
    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * @var \BulkImport\Mvc\Controller\Plugin\Bulk
     */
    protected $bulk;

    /**
     * @var \BulkImport\Mvc\Controller\Plugin\AutomapFields
     */
    protected $automapFields;

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
     * Cache for mappings.
     *
     * @var array
     */
    protected $mappings = [
        '_empty' => [
            'options' => [],
            'info' => [
                // Label is the only required data.
                'label' => 'Default empty mapping', // @translate
                'from' => null,
                'to' => null,
                // xpath, jsonpath, jsdot, jmespath.
                'querier' => null,
                // Used by ini for json. In xml, include can be used.
                'mapper' => null,
                'example' => null,
            ],
            'params' => [],
            // TODO Merge default and maps by a setting in the maps.
            'default' => [],
            'maps' => [],
            // List of tables (associative arrays) indexed by their name.
            'tables' => [],
            // May be a boolean or a message.
            'has_error' => false,
        ],
    ];

    /**
     * Name of the current mapping.
     *
     * @var string
     */
    protected $mappingName;

    /**
     * Current options.
     */
    public function __construct(
        Logger $logger,
        Bulk $bulk,
        AutomapFields $automapFields
    ) {
        $this->logger = $logger;
        $this->bulk = $bulk;
        $this->automapFields = $automapFields;
        $this->jmesPathEnv = new JmesPathEnv;
        $this->jmesPathParser = new JmesPathParser;
        $this->jsonPathQuerier = new JSONPath;
    }

    /**
     * Prepare a mapping to simplify any import into Omeka and transform source.
     *
     * It can be used as headers of a spreadsheet, or in an import mapping, or
     * to extract metadata from files json, or xml files, or for any file.
     * It contains a list of mappings between source data and destination data.
     *
     * A mapping contains four sections:
     * - info: label, base mapper if any, querier to use, example of source;
     * - params: what to import (metadata or files) and constants;
     * - default: default maps when creating resources, for example the owner;
     * - maps: the maps to use for the import.
     * Some other sections are available (passed options, tables, has_error).
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
     *              'datatype' => [
     *                  'literal',
     *              ],
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
     * Such a mapping can be created from an array, a list of fields (for
     * example headers of a spreadsheet), an ini-like or xml file, or stored in
     * database as ini-like or xml. It can be based on another mapping.
     *
     * For example, the ini map for the map above is (except prepend/append,
     * that should be included in pattern):
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
     *         <mod prepend="Title is: " pattern="pattern for {{ value|trim }} with {{/source/record/data}}"/>
     *     </map>
     * </mapping>
     * ```
     *
     * The default querier is to take the value provided by the reader.
     *
     * "mod/raw" is the raw value set in all cases, even without source value.
     * "mod/val" is the raw value set only when "from" is a value, that may be
     * extracted with a path.
     * "mod/prepend" and "mod/append" are used only when the pattern returns a
     * value with at least one replacement. So a pattern without replacements
     * (simple or twig) should be a "val".
     *
     * Note that a ini mapping has a static querier (the same for all maps), but
     * a xml mapping has a dynamic querier (set as attribute of element "from").
     *
     * For more information and formats: see {@link https://gitlab.com/Daniel-KM/Omeka-S-module-BulkImport}.
     *
     * The mapping should contain some infos about the mapping itself in section
     * "info", some params if needed, and tables or reference to tables.
     *
     * @todo Merge default and maps by a setting in the maps.
     *
     * The mapping is not overridable once built, even if options are different.
     * If needed, another name should be used.
     *
     * @todo Remove options and use mapping only, that may contain info, params and tables.
     *
     * @param string $mappingName The name of the mapping to get or to set.
     * @param array|string|null $mappingOrMappingReference Full mapping or
     *   reference file or name.
     * @param array $options
     * // To be removed: part of the main mapping.
     * - section_types (array): way to manage the sections of the mapping.
     * - tables (array): tables to use for some maps with conversion.
     * // To be removed: Only used by spreadsheet.
     * - infos
     * - label
     * - params
     * - default
     * // To be removed: For automap a single string.
     * - check_field (bool)
     * - output_full_matches (bool)
     * - output_property_id (bool)
     * @return self|array The normalized mapping.
     */
    public function __invoke(?string $mappingName = null, $mappingOrReference = null, array $options = [])
    {
        if ($mappingName === null && $mappingOrReference === null) {
            return $this;
        }

        if ($mappingName === null && $mappingOrReference !== null) {
            $mappingName = md5(serialize($mappingOrReference));
        }

        $this->setCurrentMappingName($mappingName);

        if ($mappingOrReference === null) {
            return $this->getMapping();
        }

        // The mappings are not overridable.
        if (!isset($this->mappings[$mappingName]) && $mappingOrReference!== null) {
            $this->prepareMapping($mappingOrReference, $options);
        }

        return $this->getMapping($mappingName);
    }

    /**
     * @todo Recusively merge mappings.
     */
    public function getMapping(?string $mappingName = null, bool $base = false): ?array
    {
        $mapping = $this->mappings[$mappingName ?? $this->mappingName] ?? null;
        if ($mapping === null || $base) {
            return $mapping;
        }

        // TODO Recursively merge mappings.
        return $mapping;
    }

    /**
     * @return bool|PsrMessage
     */
    public function hasError(?string $mappingName = null)
    {
        $mapping = $this->getMapping($mappingName);
        return $mapping === null
            ? true
            : $mapping['has_error'];
    }

    /**
     * Get settings from a section.
     */
    public function getSection(string $section): array
    {
        $metaMapping = $this->getMapping() ?? [];
        return $metaMapping[$section] ?? [];
    }

    /**
     * Get a setting from a section.
     *
     * Only the settings for the namable sections can be get, of course.
     *
     * For sections with type "mapping", the name is the "from" path and all the
     * setting is output.
     *
     * @todo Remove the exception for mapping.
     */
    public function getSectionSetting(string $section, string $name, $default = null)
    {
        $metaMapping = $this->getMapping() ?? [];

        if (!isset($metaMapping[$section])) {
            return $default;
        }

        // Manage an exception.
        if (($metaMapping['options']['section_types'][$section] ?? null) === 'mapping') {
            foreach ($metaMapping[$section] as $fromTo) {
                if ($name === ($fromTo['from']['path'] ?? null)) {
                    return $fromTo;
                }
            }
            return $default;
        }

        return $metaMapping[$section][$name] ?? $default;
    }

    /**
     * Get a sub setting from a section.
     */
    public function getSectionSettingSub(string $section, string $name, string $subName, $default = null)
    {
        $metaMapping = $this->getMapping() ?? [];
        return $metaMapping[$section][$name][$subName] ?? $default;
    }

    public function getMappingName(): ?string
    {
        return $this->mappingName;
    }

    protected function setCurrentMappingName(string $mappingName): self
    {
        $this->mappingName = $mappingName;
        return $this;
    }

    /**
     * Prepare a mapping.
     *
     * Only this method should be used to prepare mappings. In particular, this
     * is the only one that store options.
     *
     * @param array|string|null $mappingOrMappingReference Full mapping or
     *   reference file or name.
     */
    protected function prepareMapping($mappingOrReference, array $options): self
    {
        // This is not really useful, since this is not used anywhere, but it is
        // required to manage the exception of getSectionSetting().
        // TODO Clarify and hard code sections types.
        $options['section_types'] ??= [
            'info' => 'raw',
            'params' => 'raw_or_pattern',
            'default' => 'mapping',
            'maps' => 'mapping',
        ];

        // Check for a normalized mapping.
        $normalizedMapping = null;
        if (empty($mappingOrReference)) {
            // Nothing to do.
        } elseif (is_array($mappingOrReference) && isset($mappingOrReference['info'])) {
            $normalizedMapping = $this->prepareMappingNormalized($mappingOrReference, $options);
        } elseif (is_array($mappingOrReference)) {
            $normalizedMapping = $this->prepareMappingList($mappingOrReference, $options);
        } else {
            $content = $this->prepareMappingContent($mappingOrReference);
            if ($content) {
                $normalizedMapping = $this->prepareMappingFull($content, $options);
            }
        }

        // Validate mapping as a whole for default and maping.
        if ($normalizedMapping) {
            foreach (['default', 'maps'] as $section) {
                if (!$this->areValidMaps($normalizedMapping[$section])) {
                    $this->logger->warn(
                        'Mapping "{mapping_name}": invalid map in section "{section}".',
                        ['mapping_name' => $this->mappingName, 'section' => $section]
                    );
                    $normalizedMapping['has_error'] = true;
                }
            }
        }

        $this->mappings[$this->mappingName] = $normalizedMapping ?: $this->mappings['_empty'];
        $this->mappings[$this->mappingName]['options'] = $options;
        return $this;
    }

    /**
     * Normalize a list of map, like spreadsheet headers.
     */
    protected function prepareMappingList(array $maps, array $options): array
    {
        $normalizedMapping = [
            'options' => $options,
            'info' => $options['infos'] ?? [
                'label' => $options['label'] ?? $this->mappingName,
                'from' => null,
                'to' => null,
                'querier' => null,
                'mapper' => null,
                'example' => null,
            ],
            'params' => $options['params'] ?? [],
            'default' => $options['default'] ?? [],
            'maps' => $maps,
            // TODO Options or mapping to store tables in mapping list (for spreadsheet)? Or module Table.
            'tables' => $options['tables'] ?? [],
            'has_error' => false,
        ];

        foreach (['default', 'maps'] as $section) {
            $options['section'] = $section;
            $normalizedMapping[$section] = $this->normalizeMaps($normalizedMapping[$section], $options);
        }

        // TODO Merge with upper mappings.

        return $normalizedMapping;
    }

    /**
     * Check and store a ready-normalized mapping.
     */
    protected function prepareMappingNormalized(array $mapping, array $options): array
    {
        $normalizedMapping = array_intersect_key($mapping, ['info' => [], 'params' => [], 'default' => [], 'maps' => []]);
        $normalizedMapping['has_error'] = false;
        if (!isset($mapping['info']) || !is_array($mapping['info'])
            || (array_key_exists('params', $mapping) && !is_array($mapping['params']))
            || (array_key_exists('default', $mapping) && !is_array($mapping['default']))
            || (array_key_exists('maps', $mapping) && !is_array($mapping['maps']))
        ) {
            $this->logger->warn(
                'Mapping "{mapping_name}": invalid provided mapping.',
                ['mapping_name' => $this->mappingName]
            );
            $normalizedMapping['has_error'] = true;
            return $normalizedMapping;
        }

        $normalizedMapping['info']['label'] = !empty($normalizedMapping['info']['label']) && is_string($normalizedMapping['info']['label'])
            ? $normalizedMapping['info']['label']
            : $this->mappingName;
        $normalizedMapping['info']['from'] = !empty($normalizedMapping['info']['from']) && is_string($normalizedMapping['info']['from'])
            ? $normalizedMapping['info']['from']
            : null;
        $normalizedMapping['info']['to'] = !empty($normalizedMapping['info']['to']) && is_string($normalizedMapping['info']['to'])
            ? $normalizedMapping['info']['to']
            : null;
        $normalizedMapping['info']['querier'] = !empty($normalizedMapping['info']['querier']) && is_string($normalizedMapping['info']['querier'])
            ? $normalizedMapping['info']['querier']
            : null;
        $normalizedMapping['info']['mapper'] = !empty($normalizedMapping['info']['mapper']) && is_string($normalizedMapping['info']['mapper'])
            ? $normalizedMapping['info']['mapper']
            : null;
        $normalizedMapping['info']['example'] = !empty($normalizedMapping['info']['example']) && is_string($normalizedMapping['info']['example'])
            ? $normalizedMapping['info']['example']
            : null;

        foreach (['default', 'maps'] as $section) {
            $options['section'] = $section;
            $normalizedMapping[$section] = $this->normalizeMaps($normalizedMapping[$section], $options);
        }

        return $normalizedMapping;
    }

    /**
     * Get the content of a file or a mapping from the reference.
     */
    protected function prepareMappingContent(?string $reference, ?string $defaultPrefix = null, int $loop = 0): ?string
    {
        if (empty($reference)) {
            return null;
        }

        $prefixes = [
            'user' => $this->bulk->getBasePath() . '/mapping/',
            'module' => dirname(__DIR__, 2) . '/data/mapping/',
            'base' => dirname(__DIR__, 2) . '/data/mapping/base/',
        ];

        $content = null;
        if (mb_substr($reference, 0, 8) === 'mapping:') {
            $mappingId = (int) mb_substr($reference, 8);
            /** @var \BulkImport\Api\Representation\MappingRepresentation $mapping */
            $bulkMapping = $this->bulk->api()->searchOne('bulk_mappings', ['id' => $mappingId])->getContent();
            if (!$bulkMapping) {
                return null;
            }
            $content = $bulkMapping->mapping();
        } else {
            $filename = basename($reference);
            if (empty($filename)) {
                return null;
            }

            if (strpos($reference, ':')) {
                $prefix = strtok($reference, ':');
            } else {
                $prefix = $defaultPrefix;
                $reference = $prefix . ':xml/' . $reference;
            }
            if (!isset($prefixes[$prefix])) {
                return null;
            }

            $file = mb_substr($reference, strlen($prefix) + 1);
            $filepath = $prefixes[$prefix] . $file;
            if (file_exists($filepath) && is_file($filepath) && is_readable($filepath) && filesize($filepath)) {
                $content = trim((string) file_get_contents($filepath));
            }
        }

        if (!$content) {
            return null;
        }

        // Xml mapping may include sub mappings, so merge them one time early.
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
        $defaultPrefix = strtok($reference, ':') ?: $defaultPrefix;
        foreach ($includes as $include) {
            $subContent = $this->prepareMappingContent((string) $include['mapping'], $defaultPrefix, ++$loop);
            // TODO Use standard simple xml way to replace a node,
            if ($subContent) {
                /** @see self::prepareMappingFullXml() */
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
    protected function prepareMappingFull(string $mappingString, array $options): array
    {
        // The mapping should be trimmed to check the first character.
        $mappingString = trim($mappingString);
        if (!strlen($mappingString)) {
            return [
                'options' => $options,
                'info' => [
                    'label' => $this->mappingName,
                    'from' => null,
                    'to' => null,
                    'querier' => null,
                    'mapper' => null,
                    'example' => null,
                ],
                'params' => [],
                'default' => [],
                'maps' => [],
                'tables' => [],
                'has_error' => false,
            ];
        }

        $isXml = mb_substr($mappingString, 0, 1) === '<';
        return $isXml
            ? $this->prepareMappingFullXml($mappingString, $options)
            : $this->prepareMappingFullIni($mappingString, $options);
    }

    protected function prepareMappingFullIni(string $mappingString, array $options): array
    {
        // parse_ini_string() cannot be used, because some characters are
        // forbidden on the left and the right part may be not quoted.
        // So process by line.

        $normalizedMapping = [];

        // This is not really useful, since this is not used anywhere.
        $sectionsTypes = $options['section_types'];
        unset($sectionsTypes['has_error']);

        // Lines are trimmed. Empty lines are removed.
        $lines = $this->bulk->stringToList($mappingString);

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
                    $normalizedMapping['has_error'] = new PsrMessage('A section should have a name.'); // @translate
                    continue;
                } elseif (!isset($sectionsTypes[$section])) {
                    $section = null;
                    $normalizedMapping['has_error'] = new PsrMessage(
                        'The section "{name}" is not managed.', // @translate
                        ['name' => $section]
                    );
                    continue;
                } else {
                    $normalizedMapping[$section] = [];
                }
                $sectionType = $sectionsTypes[$section];
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
                if (isset($map['from']) && is_scalar($map['from'])) {
                    // FIXME Manage section info and params.
                    $normalizedMapping[$section][$map['from']] = $map['to'];
                }
            } else {
                $normalizedMapping[$section][] = $map;
            }
        }

        // TODO Add tables for mapping ini.

        return $normalizedMapping;
    }

    protected function prepareMappingFullXml(string $mappingString, array $options): array
    {
        $normalizedMapping = [
            'options' => $options,
            'info' => [
                'label' => $options['label'] ?? $this->mappingName,
                'from' => null,
                'to' => null,
                'querier' => null,
                'mapper' => null,
                'example' => null,
            ],
            'params' => [],
            'default' => [],
            'maps' => [],
            'tables' => [],
            'has_error' => false,
        ];

        // The mapping is always a small file (less than some megabytes), so it
        // can be managed directly with SimpleXml.
        try {
            // TODO Check warn message.
            $xmlMapping = new SimpleXMLElement($mappingString);
            if (!$xmlMapping) {
                throw new \Exception;
            }
        } catch (\Exception $e) {
            $normalizedMapping['has_error'] = new PsrMessage('The xml string is not a valid xml.'); // @translate
            return $normalizedMapping;
        }

        if (isset($xmlMapping->info)) {
            foreach ($xmlMapping->info->children()  as $element) {
                $normalizedMapping['info'][$element->getName()] = $element->__toString();
            }
        }

        if (isset($xmlMapping->params)) {
            foreach ($xmlMapping->params->children()  as $element) {
                $normalizedMapping['params'][$element->getName()] = $element->__toString();
            }
        }

        // This value allows to keep the original dest single.
        // @todo Remove [to][dest] and "k".
        $k = 0;
        foreach (['default', 'maps'] as $section) {
            $isDefault = $section === 'default';
            // TODO Use an attribute or a sub-element ?
            $i = 0;
            $options['section'] = $section;
            // TODO Include xml includes here.
            foreach ($xmlMapping->map as $element) {
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
                $normalizedMapping[$section][] = $fromTo;
            }
        }

        // Prepare tables.
        foreach ($xmlMapping->table as $table) {
            $code = (string) $table['code'];
            if (!$code) {
                continue;
            }
            foreach ($table->list[0]->term as $term) {
                $termCode = (string) $term['code'];
                if (strlen($termCode)) {
                    $normalizedMapping['tables'][$code][$termCode] = (string) $term[0];
                }
            }
        }

        return $normalizedMapping;
    }

    /**
     * Normalize a list of maps.
     *
     * @return array The mapping is returned. Each map can contain an error.
     */
    public function normalizeMaps($mapping, array $options = []): array
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
                    'Mapping "{mapping_name}": contains an invalid map.', // @translate
                    ['mapping_name' => $this->mappingName]
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

    public function areValidMaps($mapping): bool
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

    public function isValidMap($map): bool
    {
        // TODO Add more check to check a valid map.
        return is_array($map)
            && empty($map['has_error']);
    }

    /**
     * Normalize a single map.
     *
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
        $resourceName = $normalizedMap['infos']['to'] ?? 'resources';
        switch ($resourceName) {
            case 'assets':
                $toKeys = [
                    'field' => null,
                ];
                break;
            case 'resources':
            default:
                $toKeys = [
                    'field' => null,
                    'property_id' => null,
                    'datatype' => null,
                    'language' => null,
                    'is_public' => null,
                ];
        }

        $modKeys = [
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
        // TODO Remove "[to][dest]".
        $normalizedMap['to']['dest'] = $toDest;
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
                $result['to']['datatype'] = array_values(array_filter(array_unique($result['to']['datatype'])));
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
     * Here, the array should be already tested and not a nested array.
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

    protected function normalizeToFromString(string $string, array $options): ?array
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
}
