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

use Iso639p3\Iso639p3;
use Iso3166p1\Iso3166p1;

/**
 * @todo Finalize clarification of twig command. Normally, only a pattern, that may be pre-parsed, and variables, and make it a service.
 *
 * Currently, it requires ->bulk() (translation and api) and ->metaMapperConfig() (to get tables).
 */
trait TwigTrait
{
    /**
     * @var string
     */
    protected $patternVars = '';

    /**
     * @var array
     */
    protected $twigVars = [];

    /**
     * Convert a value into another value via twig filters.
     *
     * Only some common filters and some filter arguments are managed, and some
     * special functions for dates and index uris.
     *
     * @todo Separate preparation and process. Previous version in AdvancedResourceTemplate was simpler (but string only).
     * @todo Check for issues with separators or parenthesis included in values.
     * @todo Remove the need to use value|trim: by default, use current value.
     * @fixme The args extractor does not manage escaped quote and double quote in arguments (for now fixed pre/post via a string replacement).
     *
     * @param string $pattern The full pattern to process.
     * @param array $this->twigVars Associative list of twig expressions and value to
     *   use for quick and direct replacement. Contains generally "value" from
     *   the source.
     * @param array $twig List of twig expressions extracted from the pattern to
     *   use to transform value.
     * @param bool[] $twigHasReplace Associative list to Indicate if each twig
     *   expression has values to replace.
     * @param array $replace Associative list of replacements to use when the
     *   twig expression has values to replace.
     * @return string
     */
    protected function twig(string $pattern, array $twigVars, array $twig, array $twigHasReplace, array $replace): string
    {
        // Store twig vars statically to avoid to extract them multiple times.
        static $patterns = [];

        $this->twigVars = $twigVars;

        // Prepare the static vars regex for twig.
        if (count($this->twigVars)) {
            // serialize() doesn't store DOMNode properties.
            $tw = $this->twigVars;
            foreach ($tw as &$v) {
                if ($v instanceof \DOMNode) {
                    $v = (string) $v->nodeValue;
                }
            }
            $serialized = serialize($tw);
            if (!isset($patterns[$serialized])) {
                $r = [];
                foreach (array_keys($this->twigVars) as $v) {
                    $v = $v instanceof \DOMNode ? (string) $v->nodeValue : (string) $v;
                    $r[] = mb_substr($v, 0, 3) === '{{ '
                        ? preg_quote(mb_substr($v, 3, -3), '~')
                        : preg_quote($v, '~');
                }
                $patterns[$serialized] = implode('|', $r) . '|';
            }
            $this->patternVars = $patterns[$serialized];
        } else {
            $this->patternVars = '';
        }

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
                    ? $this->twigProcess($v, str_replace(array_keys($replace), array_values($replace), $filter))
                    : $this->twigProcess($v, $filter);
            }
            // A twig pattern may return an array.
            if (is_array($v)) {
                $v = reset($v);
                $v = $v instanceof \DOMNode ? (string) $v->nodeValue : (string) $v;
            }
            if ($hasReplaceQuery) {
                $twigReplace[str_replace(array_keys($replace), array_values($replace), $query)] = $v;
            } else {
                $twigReplace[$query] = $v;
            }
        }
        return str_replace(array_keys($twigReplace), array_values($twigReplace), $pattern);
    }

    /**
     * @param mixed $v Value to process, generally a string but may be an array.
     * @param string $filter The full function with arguments, like "slice(1, 4)".
     * @return string|array
     */
    protected function twigProcess($v, string $filter)
    {
        $matches = [];
        if (preg_match('~\s*(?<function>[a-zA-Z0-9_]+)\s*\(\s*(?<args>.*?)\s*\)\s*~U', $filter, $matches) > 0) {
            $function = $matches['function'];
            $args = $matches['args'];
        } else {
            $function = $filter;
            $args = '';
        }

        // TODO Remove this exception about xml process here and below (make string or array of string outside the function).
        if ($v instanceof \DOMNode) {
            $v = (string) $v->nodeValue;
        }

        // Most of the time, a string is required, but a function can return
        // an array. Only some functions can manage an array.
        $w = is_array($v) ? reset($v) : $v;
        $w = (string) ($w instanceof \DOMNode ? $v->nodeValue : $w);

        switch ($function) {
            case 'abs':
                $v = is_numeric($w) ? (string) abs($w) : $w;
                break;

            case 'capitalize':
                $v = ucfirst($w);
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
                $v = htmlspecialchars($w, ENT_COMPAT | ENT_HTML5);
                break;

            case 'first':
                $v = is_array($v) ? $w : mb_substr((string) $v, 0, 1);
                break;

            case 'format':
                $arga = $this->extractList($args);
                if ($arga) {
                    try {
                        $v = @vsprintf($w, $arga);
                    } catch (\Exception $e) {
                        // Nothing: keep value.
                    }
                }
                break;

            // The twig filter is "join", but here "implode" is a function.
            case 'implode':
                $arga = $this->extractList($args);
                if (count($arga)) {
                    $delimiter = array_shift($arga);
                    $v = implode($delimiter, $arga);
                } else {
                    $v = '';
                }
                break;

            // Implode only real values, not empty string.
            case 'implodev':
                $arga = $this->extractList($args);
                if (count($arga)) {
                    $arga = array_filter($arga, 'strlen');
                    // The string avoids strict type issue with empty array.
                    $delimiter = (string) array_shift($arga);
                    $v = implode($delimiter, $arga);
                } else {
                    $v = '';
                }
                break;

            case 'last':
                $v = is_array($v) ? (string) end($v) : mb_substr((string) $v, -1);
                break;

            case 'length':
                $v = (string) (is_array($v) ? count($v) : mb_strlen((string) $v));
                break;

            case 'lower':
                $v = mb_strtolower($w);
                break;

            case 'replace':
                $arga = $this->extractAssociative($args);
                if ($arga) {
                    $v = str_replace(array_keys($arga), array_values($arga), $w);
                }
                break;

            // case 'substr':
            case 'slice':
                $arga = $this->extractList($args);
                $start = (int) ($arga[0] ?? 0);
                $length = (int) ($arga[1] ?? 1);
                $v = is_array($v)
                    ? array_slice($v, $start, $length, !empty($arga[2]))
                    : mb_substr($w, $start, $length);
                break;

            case 'split':
                $arga = $this->extractList($args);
                $delimiter = $arga[0] ?? '';
                $limit = (int) ($arga[1] ?? 1);
                $v = strlen($delimiter)
                    ? explode($delimiter, $w, $limit)
                    : str_split($w, $limit);
                break;

            case 'striptags':
                $v = strip_tags($w);
                break;

            case 'table':
                // table() (included).
                $first = mb_substr($args, 0, 1);
                if ($first === '{') {
                    $table = $this->extractAssociative(trim(mb_substr($args, 1, -1)));
                    if ($table) {
                        $v = $table[$w] ?? $w;
                    }
                }
                // table() (named).
                else {
                    $arga = $this->extractList($args);
                    $name = $arga[0] ?? '';
                    // Check first for tables managed by module Table, if available.
                    $table = $this->table($name);
                    if ($table) {
                        $type = $arga[1] ?? '';
                        $strict = !empty($arga[2]);
                        if ($type === 'code') {
                            $v = $table->codeFromLabel($w, $strict) ?? $w;
                        } else {
                            $v = $table->labelFromCode($w, $strict) ?? $w;
                        }
                    } elseif ($name === 'iso-639-native') {
                        $v = Iso639p3::name($w) ?: $w;
                    } elseif ($name === 'iso-639-english') {
                        $v = Iso639p3::englishName($w) ?: $w;
                    } elseif ($name === 'iso-639-english-inverted') {
                        $v = Iso639p3::englishInvertedName($w) ?: $w;
                    } elseif ($name === 'iso-639-french') {
                        $v = Iso639p3::frenchName($w) ?: $w;
                    } elseif ($name === 'iso-639-french-inverted') {
                        $v = Iso639p3::frenchInvertedName($w) ?: $w;
                    } elseif ($name === 'iso-3166-native') {
                        $v = Iso3166p1::name($w) ?: $w;
                    } elseif ($name === 'iso-3166-english') {
                        $v = Iso3166p1::englishName($w) ?: $w;
                    } elseif ($name === 'iso-3166-french') {
                        $v = Iso3166p1::frenchName($w) ?: $w;
                    } else {
                        $v = $this->metaMapperConfig->getSectionSettingSub('tables', $name, $w, $w);
                    }
                }
                break;

            case 'title':
                $v = ucwords($w);
                break;

            case 'translate':
                $v = $this->bulk->translate($w);
                break;

            case 'trim':
                $arga = $this->extractList($args);
                $characterMask = $arga[0] ?? '';
                if (!strlen($characterMask)) {
                    $characterMask = " \t\n\r\0\x0B";
                }
                $side = $arga[1] ?? '';
                // Side is "both" by default.
                if ($side === 'left') {
                    $v = ltrim($w, $characterMask);
                } elseif ($side === 'right') {
                    $v = rtrim($w, $characterMask);
                } else {
                    $v = trim($w, $characterMask);
                }
                break;

            case 'upper':
                $v = mb_strtoupper($w);
                break;

            case 'url_encode':
                $v = rawurlencode($w);
                break;

            // Special filters and functions to manage common values.

            case 'dateIso':
                // "d1605110512" => "1605-11-05T12" (date iso).
                // "[1984]-" => kept.
                // Missing numbers may be set as "u", but this is not
                // manageable as iso 8601.
                // The first character may be a space to manage Unimarc.
                $v = $w;
                if (mb_strlen($v) && mb_strpos($v, 'u') === false) {
                    $firstChar = mb_substr($v, 0, 1);
                    if (in_array($firstChar, ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '-', '+', 'c', 'd', ' '])) {
                        if (in_array($firstChar, ['-', '+', 'c', 'd', ' '])) {
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

            // TODO Add a "dateFormat" with a dynamic format.
            case 'dateRevert':
                // Default spreadsheet "dd/mm/yy (or yyyy)" into iso ("yyyy-mm-dd").
                $v = trim($w);
                $mtch = [];
                preg_match('/\D/', $v, $mtch);
                $sep = mb_substr($mtch[0] ?? '', 0, 1);
                if (mb_strlen($sep)) {
                    $day = (int) strtok($v, $sep);
                    $month = (int) strtok($sep);
                    $year = strtok($sep);
                    $year = (int) (mb_strlen($year) === 2 ? '20' . $year : $year);
                    $v = sprintf('%04d', $year) . '-' . sprintf('%02d', $month) . '-' . sprintf('%02d', $day);
                } else {
                    $v = (mb_strlen($v) === 6 ? '20' . mb_substr($v, 4, 2) : mb_substr($v, 4, 4))
                        . '-' . mb_substr($v, 2, 2) . '-' . mb_substr($v, 0, 2);
                }
                break;

            case 'dateSql':
                // Unimarc 005.
                // "19850901141236.0" => "1985-09-01 14:12:36" (date sql).
                $v = trim($w);
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
                $arga = $this->extractList($args, ['a', 'b', 'c', 'd', 'f', 'g', 'k', 'o', 'p', '5']);
                // @todo Improve isbd for names.
                $v = $arga['a']
                    . ($arga['b'] ? ', ' . $arga['b'] : '')
                    . ($arga['g'] ? ' (' . $arga['g'] . ')' : '')
                    . ($arga['d'] ? ', ' . $arga['d'] : '')
                    . (
                        $arga['f']
                            ? ' (' . $arga['f']
                                . ($arga['c'] ? ' ; ' . $arga['c'] : '')
                                . ($arga['k'] ? ' ; ' . $arga['k'] : '')
                                . ')'
                            : (
                                $arga['c']
                                    ? (' (' . $arga['c'] . ($arga['k'] ? ' ; ' . $arga['k'] : '') . ')')
                                    : ($arga['k'] ? ' (' . $arga['k'] . ')' : '')
                            )
                    )
                    . ($arga['o'] ? ' {' . $arga['o'] . '}' : '')
                    . ($arga['p'] ? ', ' . $arga['p'] : '')
                    . ($arga['5'] ? ', ' . $arga['5'] : '')
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
                $arga = $this->extractList($args, ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'o', 'p', 'r', '5']);
                // @todo Improve isbd for organizations.
                $v = $arga['a']
                    . ($arga['b'] ? ', ' . $arga['b'] : '')
                    . ($arga['g']
                        ? ' (' . $arga['g'] . ($arga['h'] ? ' ; ' . $arga['h'] . '' : '') . ')'
                        : ($arga['h'] ? ' (' . $arga['h'] . ')' : ''))
                    . ($arga['d'] ? ', ' . $arga['d'] : '')
                    . ($arga['e'] ? ', ' . $arga['e'] : '')
                    . (
                        $arga['f']
                            ? ' (' . $arga['f']
                                . ($arga['c'] ? ' ; ' . $arga['c'] : '')
                                . ')'
                            : ($arga['c'] ? (' (' . $arga['c'] . ')') : '')
                    )
                    . ($arga['o'] ? ' {' . $arga['o'] . '}' : '')
                    . ($arga['p'] ? ', ' . $arga['p'] : '')
                    . ($arga['r'] ? ', ' . $arga['r'] : '')
                    . ($arga['5'] ? ', ' . $arga['5'] : '')
                ;
                break;

            case 'isbdMark':
                /* Unimarc 716 :
                $a Élément d’entrée
                $c Qualificatif
                $f Dates
                 */
                // isbdMark(a, b, c) (function).
                $arga = $this->extractList($args, ['a', 'b', 'c']);
                // @todo Improve isbd for marks.
                $v = $arga['a']
                    . ($arga['b'] ? ', ' . $arga['b'] : '')
                    . ($arga['c'] ? (' (' . $arga['c'] . ')') : '')
                ;
                break;

            case 'unimarcIndex':
                $arga = $this->extractList($args);
                $index = $arga[0] ?? '';
                if ($index) {
                    // Unimarc Index uri (filter or function).
                    $code = count($arga) === 1 ? $w : ($arga[1] ?? '');
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
                $v = $w;
                $firstChar = mb_strtoupper(mb_substr($v, 0, 1));
                $mappingChars = ['+' => 'N', '-' => 'S', 'W' => 'W', 'E' => 'E', 'N' => 'N', 'S' => 'S'];
                $v = ($mappingChars[$firstChar] ?? '?') . ' '
                    . intval(mb_substr($v, 1, 3)) . '°'
                    . intval(mb_substr($v, 4, 2)) . '’'
                    . intval(mb_substr($v, 6, 2)) . '”';
                break;

            case 'unimarcCoordinatesHexa':
                $v = $w;
                $v = mb_substr($v, 0, 2) . '°' . mb_substr($v, 2, 2) . '’' . mb_substr($v, 4, 2) . '”';
                break;

            case 'unimarcTimeHexa':
                // "150027" => "15h0m27s".
                $v = $w;
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
                $v = $this->twigVars['{{ ' . $filter . ' }}'] ?? $this->twigVars[$filter] ?? $v;
                break;
        }
        if (is_array($v)) {
            return $v;
        }
        return $v instanceof \DOMNode
            ? (string) $v->nodeValue
            : (string) $v;
    }

    protected function extractList(string $args, array $keys = []): array
    {
        $matches = [];
        // Args can be a string between double quotes, or a string between
        // single quotes, or a positive/negative float number.
        preg_match_all('~\s*(?<args>' . $this->patternVars . '"[^"]*?"|\'[^\']*?\'|[+-]?(?:\d*\.)?\d+)\s*,?\s*~', $args, $matches);
        $args = [];
        foreach ($matches['args'] as $key => $arg) {
            // If this is a var, take it, else this is a string or a number,
            // so remove the quotes if any.
            $args[$key] = $this->twigVars['{{ ' . $arg . ' }}'] ?? (is_numeric($arg)? $arg : mb_substr($arg, 1, -1));
        }
        $countKeys = count($keys);
        return $countKeys
            ? array_combine($keys, count($args) >= $countKeys ? array_slice($args, 0, $countKeys) : array_pad($args, $countKeys, ''))
            : $args;
    }

    protected function extractAssociative(string $args): array
    {
        // TODO Improve the regex to extract keys and values directly.
        $matches = [];
        preg_match_all('~\s*(?<args>' . $this->patternVars . '"[^"]*?"|\'[^\']*?\'|[+-]?(?:\d*\.)?\d+)\s*,?\s*~', $args, $matches);
        $output = [];
        foreach (array_chunk($matches['args'], 2) as $keyValue) {
            if (count($keyValue) === 2) {
                // The key cannot be a value, but may be numeric.
                $key = is_numeric($keyValue[0])? $keyValue[0] : mb_substr($keyValue[0], 1, -1);
                $value = $this->twigVars['{{ ' . $keyValue[1] . ' }}'] ?? (is_numeric($keyValue[1])? $keyValue[1] : mb_substr($keyValue[1], 1, -1));
                $output[$key] = $value;
            }
        }
        return $output;
    }

    /**
     * Get the table from a name. Require module Table.
     *
     * @param int|string $idOrSlug
     * @return \Table\Api\Representation\TableRepresentation|null
     */
    protected function table($idOrSlug)
    {
        /** @var \Table\Api\Representation\TableRepresentation[] $tables */
        static $tables = [];

        if ($tables === null || !class_exists('Table\Api\Representation\TableRepresentation')) {
            $tables = null;
            return null;
        }

        if (!array_key_exists($idOrSlug, $tables)) {
            $tables[$idOrSlug] = $this->bulk->api()->searchOne('tables', is_numeric($idOrSlug) ? ['id' => $idOrSlug] : ['slug' => $idOrSlug])->getContent();
        }

        return $tables[$idOrSlug];
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
