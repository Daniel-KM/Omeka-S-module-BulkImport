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

use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Mvc\Controller\Plugin\Logger;

class TransformSource extends AbstractPlugin
{
    /**
     * @var \Omeka\Mvc\Controller\Plugin\Logger
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
     * @var ?string
     */
    protected $config;

    /**
     * @return array
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
     * `{{ value }}` and `{{value}}` are not the same: the first is the current
     * value extracted from the source part and the second is the key used to
     * extract the value with the key `value` from a source array.
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

    public function setConfig(?string ...$config): self
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
        $this->normConfig = $mergedMappings;
        return $this;
    }

    public function getNormalizedConfig(): array
    {
        return $this->normConfig;
    }

    public function getConfigSection(string $section): array
    {
        return $this->normConfig[$section] ?? [];
    }

    public function getConfigSectionSetting(string $section, string $name, $default = null)
    {
        return $this->normConfig[$section][$name] ?? $default;
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
        $autofillerKey = null;
        // The reference simplifies section management.
        $normConfigRef = &$this->normConfig;
        foreach ($lines as $line) {
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
                    $sectionKey = trim(mb_substr($line, 1, -1));
                    if ($sectionKey === '') {
                        $this->normConfig[] = [];
                        $sectionKey = array_key_last($this->normConfig);
                    } else {
                        $this->normConfig[$sectionKey] = [];
                    }
                    unset($normConfigRef);
                    $normConfigRef = &$this->normConfig[$sectionKey];
                    continue;
                }
            }

            // Add a key/value pair to the current section.

            // The left part can be a xpath with a "=". On the right part, only
            // a pattern can contain a "=". So split the line according to the
            // presence of a pattern prefixed with a `~`.
            // TODO The left part cannot contain a "~" for now.
            $pos = $first === '~'
                ? mb_strpos($line, '=')
                : mb_strrpos(strtok($line, '~'), '=');
            $from = trim(mb_substr($line, 0, $pos));
            $to = trim(mb_substr($line, $pos + 1));
            if (!$from || !$to) {
                $this->logger->err(sprintf('The mapping "%s" has no source or destination.', $line));
                continue;
            }

            $ton = $this->normalizeDestination($to);
            if (!$ton) {
                $this->logger->err(sprintf('The destination "%s" is invalid.', $to));
                continue;
            }

            // Remove useless values for the mapping.
            $normConfigRef[] = [
                'from' => $from,
                'to' => array_filter($ton, function ($v) {
                    return !is_null($v);
                }),
            ];
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

        $result = reset($result);

        // Append the property id when the field is a property term.
        $termId = $this->bulk->getPropertyId($result['field']);
        if ($termId) {
            $result['property_id'] = $termId;
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
}
