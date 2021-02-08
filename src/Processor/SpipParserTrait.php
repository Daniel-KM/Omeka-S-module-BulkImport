<?php declare(strict_types = 1);

namespace BulkImport\Processor;

/**
 * Spip (Système de Publication pour un Internet Partagé)
 *
 * @link https://git.spip.net/spip/spip
 *
 * Les fonctions sont très largement imbriquées dans Spip et en appeler une
 * conduit à charger toute l'application (ce qui n'est pas totalement étonnant).
 * On regroupe et adapte ici les quelques fonctions permettant d'extraire le
 * code html sans perdre les liens internes.
 *
 * Copyright (c) 2001-2019, Arnaud Martin, Antoine Pitrou, Philippe Rivière, Emmanuel Saint-James
 */
trait SpipParserTrait
{
    /**
     * Expression régulière pour obtenir le contenu des extraits idiomes `<:module:cle:>`
     *
     * @var string
     */
    const _EXTRAIRE_IDIOME = '@<:(?:([a-z0-9_]+):)?([a-z0-9_]+):>@isS';

    /**
     * Expression régulière pour obtenir le contenu des extraits polyglottes `<multi>`
     *
     * @var string
     */

    // Correcteur typographique
    const _TYPO_PROTEGER = "!':;?~%-";
    const _TYPO_PROTECTEUR = "\x1\x2\x3\x4\x5\x6\x7\x8";
    // const _TYPO_BALISE = ",</?[a-z!][^<>]*[" . preg_quote(_TYPO_PROTEGER) . "][^<>]*>,imsS";

    // XHTML - Preserver les balises-bloc : on liste ici tous les elements
    // dont on souhaite qu'ils provoquent un saut de paragraphe

    /**
     * Déclaration de filtres pour les squelettes
     *
     * @package SPIP\Core\Filtres
     **/
    if (!defined('_ECRIRE_INC_VERSION')) {
        return;
    }

    /**
     * @link spip/ecrire/inc/charsets.php
     */

    /**
     * Gestion des charsets et des conversions
     *
     * Ce fichier contient les fonctions relatives à la gestion de charsets,
     * à la conversion de textes dans différents charsets et
     * propose des fonctions émulant la librairie mb si elle est absente
     *
     * @package SPIP\Core\Texte\Charsets
     **/

    /**
     * Charge en mémoire la liste des caractères d'un charset
     *
     * Charsets supportés en natif : voir les tables dans ecrire/charsets/
     * Les autres charsets sont supportés via mbstring()
     *
     * @param string $charset
     *     Charset à charger.
     *     Par défaut (AUTO), utilise le charset du site
     * @return string|bool
     *     - Nom du charset
     *     - false si le charset n'est pas décrit dans le répertoire charsets/
     **/
    protected function load_charset($charset = 'AUTO')
    {
        if ($charset == 'AUTO') {
            $charset = $GLOBALS['meta']['charset'];
        }
        $charset = trim(strtolower($charset));
        if (isset($GLOBALS['CHARSET'][$charset])) {
            return $charset;
        }

        if ($charset == 'utf-8') {
            $GLOBALS['CHARSET'][$charset] = [];

            return $charset;
        }

        // Quelques synonymes
        if ($charset == '') {
            $charset = 'iso-8859-1';
        } else {
            if ($charset == 'windows-1250') {
                $charset = 'cp1250';
            } else {
                if ($charset == 'windows-1251') {
                    $charset = 'cp1251';
                } else {
                    if ($charset == 'windows-1256') {
                        $charset = 'cp1256';
                    }
                }
            }
        }

        if (find_in_path($charset . '.php', 'charsets/', true)) {
            return $charset;
        } else {
            spip_log("Erreur: pas de fichier de conversion 'charsets/$charset'");
            $GLOBALS['CHARSET'][$charset] = [];

            return false;
        }
    }

    /**
     * Vérifier qu'on peut utiliser mb_string
     *
     * @return bool
     *     true si toutes les fonctions mb nécessaires sont présentes
     **/
    protected function init_mb_string()
    {
        static $mb;

        // verifier que tout est present (fonctions mb_string pour php >= 4.0.6)
        // et que le charset interne est connu de mb_string
        if (!$mb) {
            if (function_exists('mb_internal_encoding')
                and function_exists('mb_detect_order')
                and function_exists('mb_substr')
                and function_exists('mb_strlen')
                and function_exists('mb_strtolower')
                and function_exists('mb_strtoupper')
                and function_exists('mb_encode_mimeheader')
                and function_exists('mb_encode_numericentity')
                and function_exists('mb_decode_numericentity')
                and mb_detect_order(lire_config('charset', _DEFAULT_CHARSET))
            ) {
                mb_internal_encoding('utf-8');
                $mb = 1;
            } else {
                $mb = -1;
            }
        }

        return ($mb == 1);
    }

    /**
     * Test le fonctionnement correct d'iconv
     *
     * Celui-ci coupe sur certaines versions la chaine
     * quand un caractère n'appartient pas au charset
     *
     * @link http://php.net/manual/fr/function.iconv.php
     *
     * @return bool
     *     true si iconv fonctionne correctement
     **/
    protected function test_iconv()
    {
        static $iconv_ok;

        if (!$iconv_ok) {
            if (!function_exists('iconv')) {
                $iconv_ok = -1;
            } else {
                if (utf_32_to_unicode(@iconv('utf-8', 'utf-32', 'chaine de test')) == 'chaine de test') {
                    $iconv_ok = 1;
                } else {
                    $iconv_ok = -1;
                }
            }
        }

        return ($iconv_ok == 1);
    }

    /**
     * Test de fonctionnement du support UTF-8 dans PCRE
     *
     * Contournement bug Debian Woody
     *
     * @return bool
     *     true si PCRE supporte l'UTF-8 correctement
     **/
    protected function test_pcre_unicode()
    {
        static $pcre_ok = 0;

        if (!$pcre_ok) {
            $s = " " . chr(195) . chr(169) . "t" . chr(195) . chr(169) . " ";
            if (preg_match(',\W...\W,u', $s)) {
                $pcre_ok = 1;
            } else {
                $pcre_ok = -1;
            }
        }

        return $pcre_ok == 1;
    }

    /**
     * Renvoie une plage de caractères alphanumeriques unicodes (incomplet...)
     *
     * Retourne pour une expression rationnelle une plage
     * de caractères alphanumériques à utiliser entre crochets [$plage]
     *
     * @internal
     *    N'est pas utilisé
     *    Servait à inc/ortho passé dans le grenier
     * @return string
     *    Plage de caractères
     **/
    protected function pcre_lettres_unicode()
    {
        static $plage_unicode;

        if (!$plage_unicode) {
            if (test_pcre_unicode()) {
                // cf. http://www.unicode.org/charts/
                $plage_unicode = '\w' // iso-latin
                . '\x{100}-\x{24f}' // europeen etendu
                . '\x{300}-\x{1cff}' // des tas de trucs
                ;
            } else {
                // fallback a trois sous
                $plage_unicode = '\w';
            }
        }

        return $plage_unicode;
    }

    /**
     * Renvoie une plage de caractères de ponctuation unicode de 0x2000 a 0x206F
     *
     * Retourne pour une expression rationnelle une plage
     * de caractères de ponctuation à utiliser entre crochets [$plage]
     * (i.e. de 226-128-128 a 226-129-176)
     *
     * @internal
     *    N'est pas utilisé
     *    Servait à inc/ortho passé dans le grenier
     * @return string
     *    Plage de caractères
     **/
    protected function plage_punct_unicode()
    {
        return '\xE2(\x80[\x80-\xBF]|\x81[\x80-\xAF])';
    }

    /**
     * Corriger des caractères non-conformes : 128-159
     *
     * Cf. charsets/iso-8859-1.php (qu'on recopie ici pour aller plus vite)
     * On peut passer un charset cible en parametre pour accelerer le passage iso-8859-1 -> autre charset
     *
     * @param string|array $texte
     *     Le texte à corriger
     * @param string $charset
     *     Charset d'origine du texte
     *     Par défaut (AUTO) utilise le charset du site
     * @param string $charset_cible
     *     Charset de destination (unicode par défaut)
     * @return string|array
     *     Texte corrigé
     **/
    protected function corriger_caracteres_windows($texte, $charset = 'AUTO', $charset_cible = 'unicode')
    {
        static $trans;

        if (is_array($texte)) {
            return array_map('corriger_caracteres_windows', $texte);
        }

        if ($charset == 'AUTO') {
            $charset = lire_config('charset', _DEFAULT_CHARSET);
        }
        if ($charset == 'utf-8') {
            $p = chr(194);
            if (strpos($texte, $p) == false) {
                return $texte;
            }
        } else {
            if ($charset == 'iso-8859-1') {
                $p = '';
            } else {
                return $texte;
            }
        }

        if (!isset($trans[$charset][$charset_cible])) {
            $trans[$charset][$charset_cible] = [
                $p . chr(128) => "&#8364;",
                $p . chr(129) => ' ', # pas affecte
                $p . chr(130) => "&#8218;",
                $p . chr(131) => "&#402;",
                $p . chr(132) => "&#8222;",
                $p . chr(133) => "&#8230;",
                $p . chr(134) => "&#8224;",
                $p . chr(135) => "&#8225;",
                $p . chr(136) => "&#710;",
                $p . chr(137) => "&#8240;",
                $p . chr(138) => "&#352;",
                $p . chr(139) => "&#8249;",
                $p . chr(140) => "&#338;",
                $p . chr(141) => ' ', # pas affecte
                $p . chr(142) => "&#381;",
                $p . chr(143) => ' ', # pas affecte
                $p . chr(144) => ' ', # pas affecte
                $p . chr(145) => "&#8216;",
                $p . chr(146) => "&#8217;",
                $p . chr(147) => "&#8220;",
                $p . chr(148) => "&#8221;",
                $p . chr(149) => "&#8226;",
                $p . chr(150) => "&#8211;",
                $p . chr(151) => "&#8212;",
                $p . chr(152) => "&#732;",
                $p . chr(153) => "&#8482;",
                $p . chr(154) => "&#353;",
                $p . chr(155) => "&#8250;",
                $p . chr(156) => "&#339;",
                $p . chr(157) => ' ', # pas affecte
                $p . chr(158) => "&#382;",
                $p . chr(159) => "&#376;",
            ];
            if ($charset_cible != 'unicode') {
                foreach ($trans[$charset][$charset_cible] as $k => $c) {
                    $trans[$charset][$charset_cible][$k] = unicode2charset($c, $charset_cible);
                }
            }
        }

        return @str_replace(array_keys($trans[$charset][$charset_cible]),
            array_values($trans[$charset][$charset_cible]), $texte);
    }

    /**
     * Transforme les entités HTML en unicode
     *
     * Transforme les &eacute; en &#123;
     *
     * @param string $texte
     *     Texte à convertir
     * @param bool $secure
     *     true pour *ne pas convertir* les caracteres malins &lt; &amp; etc.
     * @return string
     *     Texte converti
     **/
    protected function html2unicode($texte, $secure = false)
    {
        if (strpos($texte, '&') === false) {
            return $texte;
        }
        static $trans = [];
        if (!$trans) {
            load_charset('html');
            foreach ($GLOBALS['CHARSET']['html'] as $key => $val) {
                $trans["&$key;"] = $val;
            }
        }

        if ($secure) {
            return str_replace(array_keys($trans), array_values($trans), $texte);
        } else {
            return str_replace(['&amp;', '&quot;', '&lt;', '&gt;'], ['&', '"', '<', '>'],
                str_replace(array_keys($trans), array_values($trans), $texte)
                );
        }
    }

    /**
     * Transforme les entités mathématiques (MathML) en unicode
     *
     * Transforme &angle; en &#x2220; ainsi que toutes autres entités mathématiques
     *
     * @param string $texte
     *     Texte à convertir
     * @return string
     *     Texte converti
     **/
    protected function mathml2unicode($texte)
    {
        static $trans;
        if (!$trans) {
            load_charset('mathml');

            foreach ($GLOBALS['CHARSET']['mathml'] as $key => $val) {
                $trans["&$key;"] = $val;
            }
        }

        return str_replace(array_keys($trans), array_values($trans), $texte);
    }

    /**
     * Transforme une chaine en entites unicode &#129;
     *
     * Utilise la librairie mb si elle est présente.
     *
     * @internal
     *     Note: l'argument $forcer est obsolete : il visait a ne pas
     *     convertir les accents iso-8859-1
     *
     * @param string $texte
     *     Texte à convertir
     * @param string $charset
     *     Charset actuel du texte
     *     Par défaut (AUTO), le charset est celui du site.
     * @return string
     *     Texte converti en unicode
     **/
    protected function charset2unicode($texte, $charset = 'AUTO' /* $forcer: obsolete*/)
    {
        static $trans;

        if ($charset == 'AUTO') {
            $charset = lire_config('charset', _DEFAULT_CHARSET);
        }

        if ($charset == '') {
            $charset = 'iso-8859-1';
        }
        $charset = strtolower($charset);

        switch ($charset) {
            case 'utf-8':
            case 'utf8':
                return utf_8_to_unicode($texte);

            case 'iso-8859-1':
                $texte = corriger_caracteres_windows($texte, 'iso-8859-1');
                // pas de break; ici, on suit sur default:

                // no break
            default:
                // mbstring presente ?
                if (init_mb_string()) {
                    if ($order = mb_detect_order() # mb_string connait-il $charset?
                        and mb_detect_order($charset)
                        ) {
                        $s = mb_convert_encoding($texte, 'utf-8', $charset);
                        if ($s && $s != $texte) {
                            return utf_8_to_unicode($s);
                        }
                    }
                    mb_detect_order($order); # remettre comme precedemment
                }

                // Sinon, peut-etre connaissons-nous ce charset ?
                if (!isset($trans[$charset])) {
                    if ($cset = load_charset($charset)
                        and is_array($GLOBALS['CHARSET'][$cset])
                        ) {
                        foreach ($GLOBALS['CHARSET'][$cset] as $key => $val) {
                            $trans[$charset][chr($key)] = '&#' . $val . ';';
                        }
                    }
                }
                if (count($trans[$charset])) {
                    return str_replace(array_keys($trans[$charset]), array_values($trans[$charset]), $texte);
                }

                // Sinon demander a iconv (malgre le fait qu'il coupe quand un
                // caractere n'appartient pas au charset, mais c'est un probleme
                // surtout en utf-8, gere ci-dessus)
                if (test_iconv()) {
                    $s = iconv($charset, 'utf-32le', $texte);
                    if ($s) {
                        return utf_32_to_unicode($s);
                    }
                }

                // Au pire ne rien faire
                spip_log("erreur charset '$charset' non supporte");

                return $texte;
        }
    }

    /**
     * Transforme les entites unicode &#129; dans le charset specifie
     *
     * Attention on ne transforme pas les entites < &#128; car si elles
     * ont ete encodees ainsi c'est a dessein
     *
     * @param string $texte
     *     Texte unicode à transformer
     * @param string $charset
     *     Charset à appliquer au texte
     *     Par défaut (AUTO), le charset sera celui du site.
     * @return string
     *     Texte transformé dans le charset souhaité
     **/
    protected function unicode2charset($texte, $charset = 'AUTO')
    {
        static $CHARSET_REVERSE = [];
        static $trans = [];

        if ($charset == 'AUTO') {
            $charset = lire_config('charset', _DEFAULT_CHARSET);
        }

        switch ($charset) {
            case 'utf-8':
                return unicode_to_utf_8($texte);
                break;

            default:
                $charset = load_charset($charset);

                if (empty($CHARSET_REVERSE[$charset])) {
                    $CHARSET_REVERSE[$charset] = array_flip($GLOBALS['CHARSET'][$charset]);
                }

                if (!isset($trans[$charset])) {
                    $trans[$charset] = [];
                    $t = &$trans[$charset];
                    for ($e = 128; $e < 255; $e++) {
                        $h = dechex($e);
                        if ($s = isset($CHARSET_REVERSE[$charset][$e])) {
                            $s = $CHARSET_REVERSE[$charset][$e];
                            $t['&#' . $e . ';'] = $t['&#0' . $e . ';'] = $t['&#00' . $e . ';'] = chr($s);
                            $t['&#x' . $h . ';'] = $t['&#x0' . $h . ';'] = $t['&#x00' . $h . ';'] = chr($s);
                        } else {
                            $t['&#' . $e . ';'] = $t['&#0' . $e . ';'] = $t['&#00' . $e . ';'] = chr($e);
                            $t['&#x' . $h . ';'] = $t['&#x0' . $h . ';'] = $t['&#x00' . $h . ';'] = chr($e);
                        }
                    }
                }
                $texte = str_replace(array_keys($trans[$charset]), array_values($trans[$charset]), $texte);

                return $texte;
        }
    }

    /**
     * Importer un texte depuis un charset externe vers le charset du site
     *
     * Les caractères non resolus sont transformés en `&#123`;
     *
     * @param string $texte
     *     Texte unicode à importer
     * @param string $charset
     *     Charset d'origine du texte
     *     Par défaut (AUTO), le charset d'origine est celui du site.
     * @return string
     *     Texte transformé dans le charset site
     **/
    protected function importer_charset($texte, $charset = 'AUTO')
    {
        static $trans = [];
        // on traite le cas le plus frequent iso-8859-1 vers utf directement pour aller plus vite !
        if (($charset == 'iso-8859-1') && ($GLOBALS['meta']['charset'] == 'utf-8')) {
            $texte = corriger_caracteres_windows($texte, 'iso-8859-1', $GLOBALS['meta']['charset']);
            if (init_mb_string()) {
                if ($order = mb_detect_order() # mb_string connait-il $charset?
                    and mb_detect_order($charset)
                    ) {
                    $s = mb_convert_encoding($texte, 'utf-8', $charset);
                }
                mb_detect_order($order); # remettre comme precedemment
                return $s;
            }
            // Sinon, peut-etre connaissons-nous ce charset ?
            if (!isset($trans[$charset])) {
                if ($cset = load_charset($charset)
                    and is_array($GLOBALS['CHARSET'][$cset])
                    ) {
                    foreach ($GLOBALS['CHARSET'][$cset] as $key => $val) {
                        $trans[$charset][chr($key)] = unicode2charset('&#' . $val . ';');
                    }
                }
            }
            if (count($trans[$charset])) {
                return str_replace(array_keys($trans[$charset]), array_values($trans[$charset]), $texte);
            }

            return $texte;
        }

        return unicode2charset(charset2unicode($texte, $charset));
    }

    /**
     * Transforme un texte UTF-8 en unicode
     *
     * Utilise la librairie mb si présente
     *
     * @param string $source
     *    Texte UTF-8 à transformer
     * @return string
     *    Texte transformé en unicode
     **/
    protected function utf_8_to_unicode($source)
    {

        // mb_string : methode rapide
        if (init_mb_string()) {
            $convmap = [0x7F, 0xFFFFFF, 0x0, 0xFFFFFF];

            return mb_encode_numericentity($source, $convmap, 'UTF-8');
        }

        // Sinon methode pas a pas
        static $decrement;
        static $shift;

        // Cf. php.net, par Ronen. Adapte pour compatibilite < php4
        if (!is_array($decrement)) {
            // array used to figure what number to decrement from character order value
            // according to number of characters used to map unicode to ascii by utf-8
            $decrement[4] = 240;
            $decrement[3] = 224;
            $decrement[2] = 192;
            $decrement[1] = 0;
            // the number of bits to shift each charNum by
            $shift[1][0] = 0;
            $shift[2][0] = 6;
            $shift[2][1] = 0;
            $shift[3][0] = 12;
            $shift[3][1] = 6;
            $shift[3][2] = 0;
            $shift[4][0] = 18;
            $shift[4][1] = 12;
            $shift[4][2] = 6;
            $shift[4][3] = 0;
        }

        $pos = 0;
        $len = strlen($source);
        $encodedString = '';
        while ($pos < $len) {
            $char = '';
            $ischar = false;
            $asciiPos = ord(substr($source, $pos, 1));
            if (($asciiPos >= 240) && ($asciiPos <= 255)) {
                // 4 chars representing one unicode character
                $thisLetter = substr($source, $pos, 4);
                $pos += 4;
            } else {
                if (($asciiPos >= 224) && ($asciiPos <= 239)) {
                    // 3 chars representing one unicode character
                    $thisLetter = substr($source, $pos, 3);
                    $pos += 3;
                } else {
                    if (($asciiPos >= 192) && ($asciiPos <= 223)) {
                        // 2 chars representing one unicode character
                        $thisLetter = substr($source, $pos, 2);
                        $pos += 2;
                    } else {
                        // 1 char (lower ascii)
                        $thisLetter = substr($source, $pos, 1);
                        $pos += 1;
                        $char = $thisLetter;
                        $ischar = true;
                    }
                }
            }

            if ($ischar) {
                $encodedString .= $char;
            } else {  // process the string representing the letter to a unicode entity
                $thisLen = strlen($thisLetter);
                $thisPos = 0;
                $decimalCode = 0;
                while ($thisPos < $thisLen) {
                    $thisCharOrd = ord(substr($thisLetter, $thisPos, 1));
                    if ($thisPos == 0) {
                        $charNum = intval($thisCharOrd - $decrement[$thisLen]);
                        $decimalCode += ($charNum << $shift[$thisLen][$thisPos]);
                    } else {
                        $charNum = intval($thisCharOrd - 128);
                        $decimalCode += ($charNum << $shift[$thisLen][$thisPos]);
                    }
                    $thisPos++;
                }
                $encodedLetter = "&#" . preg_replace('/^0+/', '', $decimalCode) . ';';
                $encodedString .= $encodedLetter;
            }
        }

        return $encodedString;
    }

    /**
     * Transforme un texte UTF-32 en unicode
     *
     * UTF-32 ne sert plus que si on passe par iconv, c'est-a-dire quand
     * mb_string est absente ou ne connait pas notre charset.
     *
     * Mais on l'optimise quand meme par mb_string
     * => tout ca sera osolete quand on sera surs d'avoir mb_string
     *
     * @param string $source
     *    Texte UTF-8 à transformer
     * @return string
     *    Texte transformé en unicode
     **/
    protected function utf_32_to_unicode($source)
    {

        // mb_string : methode rapide
        if (init_mb_string()) {
            $convmap = [0x7F, 0xFFFFFF, 0x0, 0xFFFFFF];
            $source = mb_encode_numericentity($source, $convmap, 'UTF-32LE');

            return str_replace(chr(0), '', $source);
        }

        // Sinon methode lente
        $texte = '';
        while ($source) {
            $words = unpack("V*", substr($source, 0, 1024));
            $source = substr($source, 1024);
            foreach ($words as $word) {
                if ($word < 128) {
                    $texte .= chr($word);
                } // ignorer le BOM - http://www.unicode.org/faq/utf_bom.html
                else {
                    if ($word != 65279) {
                        $texte .= '&#' . $word . ';';
                    }
                }
            }
        }

        return $texte;
    }

    /**
     * Transforme un numéro unicode en caractère utf-8
     *
     * Ce bloc provient de php.net
     *
     * @author Ronen
     *
     * @param int $num
     *    Numéro de l'entité unicode
     * @return char
     *    Caractère utf8 si trouvé, '' sinon
     **/
    protected function caractere_utf_8($num)
    {
        $num = intval($num);
        if ($num < 128) {
            return chr($num);
        }
        if ($num < 2048) {
            return chr(($num >> 6) + 192) . chr(($num & 63) + 128);
        }
        if ($num < 65536) {
            return chr(($num >> 12) + 224) . chr((($num >> 6) & 63) + 128) . chr(($num & 63) + 128);
        }
        if ($num < 1114112) {
            return chr(($num >> 18) + 240) . chr((($num >> 12) & 63) + 128) . chr((($num >> 6) & 63) + 128) . chr(($num & 63) + 128);
        }

        return '';
    }

    /**
     * Convertit un texte unicode en utf-8
     *
     * @param string $texte
     *     Texte à convertir
     * @return string
     *     Texte converti
     **/
    protected function unicode_to_utf_8($texte)
    {

        // 1. Entites &#128; et suivantes
        $vu = [];
        if (preg_match_all(',&#0*([1-9][0-9][0-9]+);,S',
            $texte, $regs, PREG_SET_ORDER)) {
            foreach ($regs as $reg) {
                if ($reg[1] > 127 and !isset($vu[$reg[0]])) {
                    $vu[$reg[0]] = caractere_utf_8($reg[1]);
                }
            }
        }
        //$texte = str_replace(array_keys($vu), array_values($vu), $texte);

        // 2. Entites > &#xFF;
        //$vu = array();
        if (preg_match_all(',&#x0*([1-9a-f][0-9a-f][0-9a-f]+);,iS',
                $texte, $regs, PREG_SET_ORDER)) {
            foreach ($regs as $reg) {
                if (!isset($vu[$reg[0]])) {
                    $vu[$reg[0]] = caractere_utf_8(hexdec($reg[1]));
                }
            }
        }

        return str_replace(array_keys($vu), array_values($vu), $texte);
    }

    /**
     * Convertit les unicode &#264; en javascript \u0108
     *
     * @param string $texte
     *     Texte à convertir
     * @return string
     *     Texte converti
     **/
    protected function unicode_to_javascript($texte)
    {
        $vu = [];
        while (preg_match(',&#0*([0-9]+);,S', $texte, $regs) and !isset($vu[$regs[1]])) {
            $num = $regs[1];
            $vu[$num] = true;
            $s = '\u' . sprintf("%04x", $num);
            $texte = str_replace($regs[0], $s, $texte);
        }

        return $texte;
    }

    /**
     * Convertit les %uxxxx (envoyés par javascript) en &#yyy unicode
     *
     * @param string $texte
     *     Texte à convertir
     * @return string
     *     Texte converti
     **/
    protected function javascript_to_unicode($texte)
    {
        while (preg_match(",%u([0-9A-F][0-9A-F][0-9A-F][0-9A-F]),", $texte, $regs)) {
            $texte = str_replace($regs[0], "&#" . hexdec($regs[1]) . ";", $texte);
        }

        return $texte;
    }

    /**
     * Convertit les %E9 (envoyés par le browser) en chaîne du charset du site (binaire)
     *
     * @param string $texte
     *     Texte à convertir
     * @return string
     *     Texte converti
     **/
    protected function javascript_to_binary($texte)
    {
        while (preg_match(",%([0-9A-F][0-9A-F]),", $texte, $regs)) {
            $texte = str_replace($regs[0], chr(hexdec($regs[1])), $texte);
        }

        return $texte;
    }

    /**
     * Substition rapide de chaque graphème selon le charset sélectionné.
     *
     * @uses caractere_utf_8()
     *
     * @global array $CHARSET
     * @staticvar array $trans
     *
     * @param string $texte
     * @param string $charset
     * @param string $complexe
     * @return string
     */
    protected function translitteration_rapide($texte, $charset = 'AUTO', $complexe = '')
    {
        static $trans = [];
        if ($charset == 'AUTO') {
            $charset = $GLOBALS['meta']['charset'];
        }
        if (!strlen($texte)) {
            return $texte;
        }

        $table_translit = 'translit' . $complexe;

        // 2. Translitterer grace a la table predefinie
        if (!isset($trans[$complexe])) {
            $trans[$complexe] = [];
            load_charset($table_translit);
            foreach ($GLOBALS['CHARSET'][$table_translit] as $key => $val) {
                $trans[$complexe][caractere_utf_8($key)] = $val;
            }
        }

        return str_replace(array_keys($trans[$complexe]), array_values($trans[$complexe]), $texte);
    }

    /**
     * Translittération charset => ascii (pour l'indexation)
     *
     * Permet, entre autres, d’enlever les accents,
     * car la table ASCII non étendue ne les comporte pas.
     *
     * Attention les caractères non reconnus sont renvoyés en utf-8
     *
     * @uses corriger_caracteres()
     * @uses unicode_to_utf_8()
     * @uses html2unicode()
     * @uses charset2unicode()
     * @uses translitteration_rapide()
     *
     * @param string $texte
     * @param string $charset
     * @param string $complexe
     * @return string
     */
    protected function translitteration($texte, $charset = 'AUTO', $complexe = '')
    {
        // 0. Supprimer les caracteres illegaux
        include_spip('inc/filtres');
        $texte = corriger_caracteres($texte);

        // 1. Passer le charset et les &eacute en utf-8
        $texte = unicode_to_utf_8(html2unicode(charset2unicode($texte, $charset, true)));

        return translitteration_rapide($texte, $charset, $complexe);
    }

    /**
     * Translittération complexe
     *
     * `&agrave;` est retourné sous la forme ``a` `` et pas `à`
     * mais si `$chiffre=true`, on retourne `a8` (vietnamien)
     *
     * @uses translitteration()
     * @param string $texte
     * @param bool $chiffres
     * @return string
     */
    protected function translitteration_complexe($texte, $chiffres = false)
    {
        $texte = translitteration($texte, 'AUTO', 'complexe');

        if ($chiffres) {
            $texte = preg_replace_callback(
                "/[aeiuoyd]['`?~.^+(-]{1,2}/S",
                function ($m) {
                    return translitteration_chiffree($m[0]);
                },
                $texte
            );
        }

        return $texte;
    }

    /**
     * Translittération chiffrée
     *
     * Remplace des caractères dans une chaîne par des chiffres
     *
     * @param string $car
     * @return string
     */
    protected function translitteration_chiffree($car)
    {
        return strtr($car, "'`?~.^+(-", "123456789");
    }

    /**
     * Reconnaitre le BOM utf-8 (0xEFBBBF)
     *
     * @param string $texte
     *    Texte dont on vérifie la présence du BOM
     * @return bool
     *    true s'il a un BOM
     **/
    protected function bom_utf8($texte)
    {
        return (substr($texte, 0, 3) == chr(0xEF) . chr(0xBB) . chr(0xBF));
    }

    /**
     * Vérifie qu'une chaîne est en utf-8 valide
     *
     * Note: preg_replace permet de contourner un "stack overflow" sur PCRE
     *
     * @link http://us2.php.net/manual/fr/function.mb-detect-encoding.php#50087
     * @link http://w3.org/International/questions/qa-forms-utf-8.html
     *
     * @param string $string
     *     Texte dont on vérifie qu'il est de l'utf-8
     * @return bool
     *     true si c'est le cas
     **/
    protected function is_utf8($string)
    {
        return !strlen(
            preg_replace(
                ',[\x09\x0A\x0D\x20-\x7E]'            # ASCII
                . '|[\xC2-\xDF][\x80-\xBF]'             # non-overlong 2-byte
                . '|\xE0[\xA0-\xBF][\x80-\xBF]'         # excluding overlongs
                . '|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}'  # straight 3-byte
                . '|\xED[\x80-\x9F][\x80-\xBF]'         # excluding surrogates
                . '|\xF0[\x90-\xBF][\x80-\xBF]{2}'      # planes 1-3
                . '|[\xF1-\xF3][\x80-\xBF]{3}'          # planes 4-15
                . '|\xF4[\x80-\x8F][\x80-\xBF]{2}'      # plane 16
                . ',sS',
                '', $string));
    }

    /**
     * Vérifie qu'une chaîne est en ascii valide
     *
     * @param string $string
     *     Texte dont on vérifie qu'il est de l'ascii
     * @return bool
     *     true si c'est le cas
     **/
    protected function is_ascii($string)
    {
        return !strlen(
            preg_replace(
                ',[\x09\x0A\x0D\x20-\x7E],sS',
                '', $string));
    }

    /**
     * Transcode une page vers le charset du site
     *
     * Transcode une page (attrapée sur le web, ou un squelette) vers le
     * charset du site en essayant par tous les moyens de deviner son charset
     * (y compris dans les headers HTTP)
     *
     * @param string $texte
     *     Page à transcoder, dont on souhaite découvrir son charset
     * @param string $headers
     *     Éventuels headers HTTP liés à cette page
     * @return string
     *     Texte transcodé dans le charset du site
     **/
    protected function transcoder_page($texte, $headers = '')
    {

        // Si tout est < 128 pas la peine d'aller plus loin
        if (is_ascii($texte)) {
            #spip_log('charset: ascii');
            return $texte;
        }

        // Reconnaitre le BOM utf-8 (0xEFBBBF)
        if (bom_utf8($texte)) {
            $charset = 'utf-8';
            $texte = substr($texte, 3);
        } // charset precise par le contenu (xml)
        else {
            if (preg_match(
                ',<[?]xml[^>]*encoding[^>]*=[^>]*([-_a-z0-9]+?),UimsS', $texte, $regs)) {
                $charset = trim(strtolower($regs[1]));
            } // charset precise par le contenu (html)
            else {
                if (preg_match(
                    ',<(meta|html|body)[^>]*charset[^>]*=[^>]*([-_a-z0-9]+?),UimsS',
                    $texte, $regs)
                    # eviter #CHARSET des squelettes
                    and (($tmp = trim(strtolower($regs[2]))) != 'charset')
                ) {
                    $charset = $tmp;
                } // charset de la reponse http
                else {
                    if (preg_match(',charset=([-_a-z0-9]+),i', $headers, $regs)) {
                        $charset = trim(strtolower($regs[1]));
                    } else {
                        $charset = '';
                    }
                }
            }
        }
        // normaliser les noms du shif-jis japonais
        if (preg_match(',^(x|shift)[_-]s?jis$,i', $charset)) {
            $charset = 'shift-jis';
        }

        if ($charset) {
            spip_log("charset: $charset");
        } else {
            // valeur par defaut
            if (is_utf8($texte)) {
                $charset = 'utf-8';
            } else {
                $charset = 'iso-8859-1';
            }
            spip_log("charset probable: $charset");
        }

        return importer_charset($texte, $charset);
    }

    //
    // Gerer les outils mb_string
    //

    /**
     * Coupe un texte selon substr()
     *
     * Coupe une chaîne en utilisant les outils mb* lorsque le site est en utf8
     *
     * @link http://fr.php.net/manual/fr/function.mb-substr.php
     * @link http://www.php.net/manual/fr/function.substr.php
     * @uses spip_substr_manuelle() si les fonctions php mb sont absentes
     *
     * @param string $c Le texte
     * @param int $start Début
     * @param null|int $length Longueur ou fin
     * @return string
     *     Le texte coupé
     **/
    protected function spip_substr($c, $start = 0, $length = null)
    {
        // Si ce n'est pas utf-8, utiliser substr
        if ($GLOBALS['meta']['charset'] != 'utf-8') {
            if ($length) {
                return substr($c, $start, $length);
            } else {
                substr($c, $start);
            }
        }

        // Si utf-8, voir si on dispose de mb_string
        if (init_mb_string()) {
            if ($length) {
                return mb_substr($c, $start, $length);
            } else {
                return mb_substr($c, $start);
            }
        }

        // Version manuelle (cf. ci-dessous)
        return spip_substr_manuelle($c, $start, $length);
    }

    /**
     * Coupe un texte comme mb_substr()
     *
     * Version manuelle de substr utf8, pour php vieux et/ou mal installe
     *
     * @link http://fr.php.net/manual/fr/function.mb-substr.php
     *
     * @param string $c Le texte
     * @param int $start Début
     * @param null|int $length Longueur ou fin
     * @return string
     *     Le texte coupé
     **/
    protected function spip_substr_manuelle($c, $start, $length = null)
    {

        // Cas pathologique
        if ($length === 0) {
            return '';
        }

        // S'il y a un demarrage, on se positionne
        if ($start > 0) {
            $c = substr($c, strlen(spip_substr_manuelle($c, 0, $start)));
        } elseif ($start < 0) {
            return spip_substr_manuelle($c, spip_strlen($c) + $start, $length);
        }

        if (!$length) {
            return $c;
        }

        if ($length > 0) {
            // on prend n fois la longueur desiree, pour etre surs d'avoir tout
            // (un caractere utf-8 prenant au maximum n bytes)
            $n = 0;
            while (preg_match(',[\x80-\xBF]{' . (++$n) . '},', $c)) {
            }
            $c = substr($c, 0, $n * $length);
            // puis, tant qu'on est trop long, on coupe...
            while (($l = spip_strlen($c)) > $length) {
                $c = substr($c, 0, $length - $l);
            }

            return $c;
        }

        // $length < 0
        return spip_substr_manuelle($c, 0, spip_strlen($c) + $length);
    }

    /**
     * Rend majuscule le premier caractère d'une chaîne utf-8
     *
     * Version utf-8 d'ucfirst
     *
     * @param string $c
     *     La chaîne à transformer
     * @return string
     *     La chaîne avec une majuscule sur le premier mot
     */
    protected function spip_ucfirst($c)
    {
        // Si on n'a pas mb_* ou si ce n'est pas utf-8, utiliser ucfirst
        if (!init_mb_string() or $GLOBALS['meta']['charset'] != 'utf-8') {
            return ucfirst($c);
        }

        $lettre1 = mb_strtoupper(spip_substr($c, 0, 1));

        return $lettre1 . spip_substr($c, 1);
    }

    /**
     * Passe une chaîne utf-8 en minuscules
     *
     * Version utf-8 de strtolower
     *
     * @param string $c
     *     La chaîne à transformer
     * @return string
     *     La chaîne en minuscules
     */
    protected function spip_strtolower($c)
    {
        // Si on n'a pas mb_* ou si ce n'est pas utf-8, utiliser strtolower
        if (!init_mb_string() or $GLOBALS['meta']['charset'] != 'utf-8') {
            return strtolower($c);
        }

        return mb_strtolower($c);
    }

    /**
     * Retourne la longueur d'une chaîne utf-8
     *
     * Version utf-8 de strlen
     *
     * @param string $c
     *     La chaîne à compter
     * @return int
     *     Longueur de la chaîne
     */
    protected function spip_strlen($c)
    {
        // On transforme les sauts de ligne pour ne pas compter deux caractères
        $c = str_replace("\r\n", "\n", $c);

        // Si ce n'est pas utf-8, utiliser strlen
        if ($GLOBALS['meta']['charset'] != 'utf-8') {
            return strlen($c);
        }

        // Sinon, utiliser mb_strlen() si disponible
        if (init_mb_string()) {
            return mb_strlen($c);
        }

        // Methode manuelle : on supprime les bytes 10......,
        // on compte donc les ascii (0.......) et les demarrages
        // de caracteres utf-8 (11......)
        return strlen(preg_replace(',[\x80-\xBF],S', '', $c));
    }

    // // noter a l'occasion dans la meta pcre_u notre capacite a utiliser le flag /u
    // // dans les preg_replace pour ne pas casser certaines lettres accentuees :
    // // en utf-8 chr(195).chr(160) = a` alors qu'en iso-latin chr(160) = nbsp
    // if (!isset($GLOBALS['meta']['pcre_u'])
    //     or (isset($_GET['var_mode']) and !isset($_GET['var_profile']))
    // ) {
    //     incltude_spip('inc/meta');
    //     ecrire_meta('pcre_u',
    //         $u = (lire_config('charset', _DEFAULT_CHARSET) == 'utf-8'
    //             and test_pcre_unicode())
    //         ? 'u' : ''
    //     );
    // }

    /**
     * Transforme une chaîne utf-8 en utf-8 sans "planes"
     * ce qui permet de la donner à MySQL "utf8", qui n'est pas un utf-8 complet
     * L'alternative serait d'utiliser utf8mb4
     *
     * @param string $x
     *     La chaîne à transformer
     * @return string
     *     La chaîne avec les caractères utf8 des hauts "planes" échappée
     *     en unicode : &#128169;
     */
    protected function utf8_noplanes($x)
    {
        $regexp_utf8_4bytes = '/(
      \xF0[\x90-\xBF][\x80-\xBF]{2}     # planes 1-3
   | [\xF1-\xF3][\x80-\xBF]{3}          # planes 4-15
   |  \xF4[\x80-\x8F][\x80-\xBF]{2}     # plane 16
)/xS';
        if (preg_match_all($regexp_utf8_4bytes, $x, $z, PREG_PATTERN_ORDER)) {
            foreach ($z[0] as $k) {
                $ku = utf_8_to_unicode($k);
                $x = str_replace($k, $ku, $x);
            }
        }

        return $x;
    }

    /**
     * @link spip/ecrire/inc/filtres.php
     */

    /**
     * Charger un filtre depuis le php
     *
     * - on inclue tous les fichiers fonctions des plugins et du skel
     * - on appelle chercher_filtre
     *
     * Pour éviter de perdre le texte si le filtre demandé est introuvable,
     * on transmet `filtre_identite_dist` en filtre par défaut.
     *
     * @uses filtre_identite_dist() Comme fonction par défaut
     *
     * @param string $fonc Nom du filtre
     * @param string $default Filtre par défaut
     * @return string Fonction PHP correspondante du filtre
     */
    protected function charger_filtre($fonc, $default = 'filtre_identite_dist')
    {
        include_spip('public/parametrer'); // inclure les fichiers fonctions
        return chercher_filtre($fonc, $default);
    }

    /**
     * Retourne le texte tel quel
     *
     * @param string $texte Texte
     * @return string Texte
     **/
    protected function filtre_identite_dist($texte)
    {
        return $texte;
    }

    /**
     * Cherche un filtre
     *
     * Pour une filtre `F` retourne la première fonction trouvée parmis :
     *
     * - filtre_F
     * - filtre_F_dist
     * - F
     *
     * Peut gérer des appels par des fonctions statiques de classes tel que `Foo::Bar`
     *
     * En absence de fonction trouvée, retourne la fonction par défaut indiquée.
     *
     * @param string $fonc
     *     Nom du filtre
     * @param null $default
     *     Nom du filtre appliqué par défaut si celui demandé n'est pas trouvé
     * @return string
     **/
    protected function extraire_multi($letexte, $lang = null, $options = [])
    {
        $regs = [];

        if ($letexte
            && preg_match_all(self::_EXTRAIRE_MULTI, $letexte, $regs, PREG_SET_ORDER)
        ) {
            if (!$lang) {
                $lang = $GLOBALS['spip_lang'];
            }

            return $f;
        }
        foreach (['filtre_' . $fonc, 'filtre_' . $fonc . '_dist', $fonc] as $f) {
            trouver_filtre_matrice($f); // charge des fichiers spécifiques éventuels
            // fonction ou name\space\fonction
            if (is_callable($f)) {
                return $f;
            }
            // méthode statique d'une classe Classe::methode ou name\space\Classe::methode
            elseif (false === strpos($f, '::') and is_callable([$f])) {
                return $f;
            }
        }

        return $default;
    }

    /**
     * Applique un filtre
     *
     * Fonction générique qui prend en argument l’objet (texte, etc) à modifier
     * et le nom du filtre. Retrouve les arguments du filtre demandé dans les arguments
     * transmis à cette fonction, via func_get_args().
     *
     * @see filtrer() Assez proche
     *
     * @param mixed $arg
     *     Texte (le plus souvent) sur lequel appliquer le filtre
     * @param string $filtre
     *     Nom du filtre à appliquer
     * @param bool $force
     *     La fonction doit-elle retourner le texte ou rien si le filtre est absent ?
     * @return string
     *     Texte traité par le filtre si le filtre existe,
     *     Texte d'origine si le filtre est introuvable et si $force à `true`
     *     Chaîne vide sinon (filtre introuvable).
     **/
    protected function appliquer_filtre($arg, $filtre, $force = null)
    {
        $f = chercher_filtre($filtre);
        if (!$f) {
            if (!$force) {
                return '';
            } else {
                return $arg;
            }
        }

        $args = func_get_args();
        array_shift($args); // enlever $arg
        array_shift($args); // enlever $filtre
        array_unshift($args, $arg); // remettre $arg
        return call_user_func_array($f, $args);
    }

    /**
     * Retourne la version de SPIP
     *
     * Si l'on retrouve un numéro de révision GIT ou SVN, il est ajouté entre crochets.
     * Si effectivement le SPIP est installé par Git ou Svn, 'GIT' ou 'SVN' est ajouté avant sa révision.
     *
     * @global spip_version_affichee Contient la version de SPIP
     * @uses version_vcs_courante() Pour trouver le numéro de révision
     *
     * @return string
     *     Version de SPIP
     **/
    protected function spip_version()
    {
        $version = $GLOBALS['spip_version_affichee'];
        if ($vcs_version = version_vcs_courante(_DIR_RACINE)) {
            $version .= " $vcs_version";
        }

        return $version;
    }

    /**
     * Retourne une courte description d’une révision VCS d’un répertoire
     *
     * @param string $dir Le répertoire à tester
     * @param array $raw True pour avoir les données brutes, false pour un texte à afficher
     * @retun string|array|null
     *    - array|null si $raw = true,
     *    - string|null si $raw = false
     */
    protected function version_vcs_courante($dir, $raw = false)
    {
        $desc = decrire_version_git($dir);
        if ($desc === null) {
            $desc = decrire_version_svn($dir);
        }
        if ($desc === null or $raw) {
            return $desc;
        }
        // affichage "GIT [master: abcdef]"
        $commit = $desc['commit_short'] ?? $desc['commit'];
        if ($desc['branch']) {
            $commit = $desc['branch'] . ': ' . $commit;
        }
        return "{$desc['vcs']} [$commit]";
    }

    /**
     * Retrouve un numéro de révision Git d'un répertoire
     *
     * @param string $dir Chemin du répertoire
     * @return array|null
     *      null si aucune info trouvée
     *      array ['branch' => xx, 'commit' => yy] sinon.
     **/
    protected function decrire_version_git($dir)
    {
        if (!$dir) {
            $dir = '.';
        }

        // version installee par GIT
        if (lire_fichier($dir . '/.git/HEAD', $c)) {
            $currentHead = trim(substr($c, 4));
            if (lire_fichier($dir . '/.git/' . $currentHead, $hash)) {
                return [
                    'vcs' => 'GIT',
                    'branch' => basename($currentHead),
                    'commit' => trim($hash),
                    'commit_short' => substr(trim($hash), 0, 8),
                ];
            }
        }

        return null;
    }

    /**
     * Retrouve un numéro de révision Svn d'un répertoire
     *
     * @param string $dir Chemin du répertoire
     * @return array|null
     *      null si aucune info trouvée
     *      array ['commit' => yy, 'date' => xx, 'author' => xx] sinon.
     **/
    protected function decrire_version_svn($dir)
    {
        if (!$dir) {
            $dir = '.';
        }
        // version installee par SVN
        if (file_exists($dir . '/.svn/wc.db') && class_exists('SQLite3')) {
            $db = new SQLite3($dir . '/.svn/wc.db');
            $result = $db->query('SELECT changed_revision FROM nodes WHERE local_relpath = "" LIMIT 1');
            if ($result) {
                $row = $result->fetchArray();
                if ($row['changed_revision'] != "") {
                    return [
                        'vcs' => 'SVN',
                        'branch' => '',
                        'commit' => $row['changed_revision'],
                    ];
                }
            }
        }
        return null;
    }

    /**
     * Charge et exécute un filtre (graphique ou non)
     *
     * Recherche la fonction prévue pour un filtre (qui peut être un filtre graphique `image_*`)
     * et l'exécute avec les arguments transmis à la fonction, obtenus avec `func_get_args()`
     *
     * @api
     * @uses image_filtrer() Pour un filtre image
     * @uses chercher_filtre() Pour un autre filtre
     *
     * @param string $filtre
     *     Nom du filtre à appliquer
     * @return string
     *     Code HTML retourné par le filtre
     **/
    protected function filtrer($filtre)
    {
        $tous = func_get_args();
        if (trouver_filtre_matrice($filtre) and substr($filtre, 0, 6) == 'image_') {
            return image_filtrer($tous);
        } elseif ($f = chercher_filtre($filtre)) {
            array_shift($tous);
            return call_user_func_array($f, $tous);
        } else {
            // le filtre n'existe pas, on provoque une erreur
            $msg = ['zbug_erreur_filtre', ['filtre' => texte_script($filtre)]];
            erreur_squelette($msg);
            return '';
        }

    $GLOBALS['spip_matrice']['couleur_html_to_hex'] = 'inc/filtres_images_mini.php';
    $GLOBALS['spip_matrice']['couleur_foncer'] = 'inc/filtres_images_mini.php';
    $GLOBALS['spip_matrice']['couleur_eclaircir'] = 'inc/filtres_images_mini.php';

    // ou pour inclure un script au moment ou l'on cherche le filtre
    $GLOBALS['spip_matrice']['filtre_image_dist'] = 'inc/filtres_mime.php';
    $GLOBALS['spip_matrice']['filtre_audio_dist'] = 'inc/filtres_mime.php';
    $GLOBALS['spip_matrice']['filtre_video_dist'] = 'inc/filtres_mime.php';
    $GLOBALS['spip_matrice']['filtre_application_dist'] = 'inc/filtres_mime.php';
    $GLOBALS['spip_matrice']['filtre_message_dist'] = 'inc/filtres_mime.php';
    $GLOBALS['spip_matrice']['filtre_multipart_dist'] = 'inc/filtres_mime.php';
    $GLOBALS['spip_matrice']['filtre_text_dist'] = 'inc/filtres_mime.php';
    $GLOBALS['spip_matrice']['filtre_text_csv_dist'] = 'inc/filtres_mime.php';
    $GLOBALS['spip_matrice']['filtre_text_html_dist'] = 'inc/filtres_mime.php';
    $GLOBALS['spip_matrice']['filtre_audio_x_pn_realaudio'] = 'inc/filtres_mime.php';


    /**
     * Charge et exécute un filtre (graphique ou non)
     *
     * Recherche la fonction prévue pour un filtre (qui peut être un filtre graphique `image_*`)
     * et l'exécute avec les arguments transmis à la fonction, obtenus avec `func_get_args()`
     *
     * @api
     * @uses image_filtrer() Pour un filtre image
     * @uses chercher_filtre() Pour un autre filtre
     *
     * @param string $filtre
     *     Nom du filtre à appliquer
     * @return string
     *     Code HTML retourné par le filtre
     **/
    function filtrer($filtre) {
        $tous = func_get_args();
        if (trouver_filtre_matrice($filtre) and substr($filtre, 0, 6) == 'image_') {
            return image_filtrer($tous);
        } elseif ($f = chercher_filtre($filtre)) {
            array_shift($tous);
            return call_user_func_array($f, $tous);
        } else {
            // le filtre n'existe pas, on provoque une erreur
            $msg = array('zbug_erreur_filtre', array('filtre' => texte_script($filtre)));
            erreur_squelette($msg);
            return '';
        }
    }

    /**
     * Cherche un filtre spécial indiqué dans la globale `spip_matrice`
     * et charge le fichier éventuellement associé contenant le filtre.
     *
     * Les filtres d'images par exemple sont déclarés de la sorte, tel que :
     * ```
     * $GLOBALS['spip_matrice']['image_reduire'] = true;
     * $GLOBALS['spip_matrice']['image_monochrome'] = 'filtres/images_complements.php';
     * ```
     *
     * @param string $filtre
     * @return bool true si on trouve le filtre dans la matrice, false sinon.
     */
    protected function trouver_filtre_matrice($filtre)
    {
        if (isset($GLOBALS['spip_matrice'][$filtre]) and is_string($f = $GLOBALS['spip_matrice'][$filtre])) {
            find_in_path($f, '', true);
            $GLOBALS['spip_matrice'][$filtre] = true;
        }
        return !empty($GLOBALS['spip_matrice'][$filtre]);
    }

    /**
     * Filtre `set` qui sauve la valeur en entrée dans une variable
     *
     * La valeur pourra être retrouvée avec `#GET{variable}`.
     *
     * @example
     *     `[(#CALCUL|set{toto})]` enregistre le résultat de `#CALCUL`
     *     dans la variable `toto` et renvoie vide.
     *     C'est équivalent à `[(#SET{toto, #CALCUL})]` dans ce cas.
     *     `#GET{toto}` retourne la valeur sauvegardée.
     *
     * @example
     *     `[(#CALCUL|set{toto,1})]` enregistre le résultat de `#CALCUL`
     *      dans la variable toto et renvoie la valeur. Cela permet d'utiliser
     *      d'autres filtres ensuite. `#GET{toto}` retourne la valeur.
     *
     * @filtre
     * @param array $Pile Pile de données
     * @param mixed $val Valeur à sauver
     * @param string $key Clé d'enregistrement
     * @param bool $continue True pour retourner la valeur
     * @return mixed
     */
    protected function filtre_set(&$Pile, $val, $key, $continue = null)
    {
        $Pile['vars'][$key] = $val;
        return $continue ? $val : '';
    }

    /**
     * Filtre `setenv` qui enregistre une valeur dans l'environnement du squelette
     *
     * La valeur pourra être retrouvée avec `#ENV{variable}`.
     *
     * @example
     *     `[(#CALCUL|setenv{toto})]` enregistre le résultat de `#CALCUL`
     *      dans l'environnement toto et renvoie vide.
     *      `#ENV{toto}` retourne la valeur.
     *
     *      `[(#CALCUL|setenv{toto,1})]` enregistre le résultat de `#CALCUL`
     *      dans l'environnement toto et renvoie la valeur.
     *      `#ENV{toto}` retourne la valeur.
     *
     * @filtre
     *
     * @param array $Pile
     * @param mixed $val Valeur à enregistrer
     * @param mixed $key Nom de la variable
     * @param null|mixed $continue Si présent, retourne la valeur en sortie
     * @return string|mixed Retourne `$val` si `$continue` présent, sinon ''.
     */
    protected function filtre_setenv(&$Pile, $val, $key, $continue = null)
    {
        $Pile[0][$key] = $val;
        return $continue ? $val : '';
    }

    /**
     * @param array $Pile
     * @param array|string $keys
     * @return string
     */
    protected function filtre_sanitize_env(&$Pile, $keys)
    {
        $Pile[0] = spip_sanitize_from_request($Pile[0], $keys);
        return '';
    }

    /**
     * Filtre `debug` qui affiche un debug de la valeur en entrée
     *
     * Log la valeur dans `debug.log` et l'affiche si on est webmestre.
     *
     * @example
     *     `[(#TRUC|debug)]` affiche et log la valeur de `#TRUC`
     * @example
     *     `[(#TRUC|debug{avant}|calcul|debug{apres}|etc)]`
     *     affiche la valeur de `#TRUC` avant et après le calcul,
     *     en précisant "avant" et "apres".
     *
     * @filtre
     * @link https://www.spip.net/5695
     * @param mixed $val La valeur à debugguer
     * @param mixed|null $key Clé pour s'y retrouver
     * @return mixed Retourne la valeur (sans la modifier).
     */
    protected function filtre_debug($val, $key = null)
    {
        $debug = (
            is_null($key) ? '' : (var_export($key, true) . " = ")
        ) . var_export($val, true);

        include_spip('inc/autoriser');
        if (autoriser('webmestre')) {
            echo "<div class='spip_debug'>\n", $debug, "</div>\n";
        }

        spip_log($debug, 'debug');

        return $val;
    }

    /**
     * Exécute un filtre image
     *
     * Fonction générique d'entrée des filtres images.
     * Accepte en entrée :
     *
     * - un texte complet,
     * - un img-log (produit par #LOGO_XX),
     * - un tag `<img ...>` complet,
     * - un nom de fichier *local* (passer le filtre `|copie_locale` si on veut
     *   l'appliquer à un document distant).
     *
     * Applique le filtre demande à chacune des occurrences
     *
     * @param array $args
     *     Liste des arguments :
     *
     *     - le premier est le nom du filtre image à appliquer
     *     - le second est le texte sur lequel on applique le filtre
     *     - les suivants sont les arguments du filtre image souhaité.
     * @return string
     *     Texte qui a reçu les filtres
     **/
    protected function image_filtrer($args)
    {
        $filtre = array_shift($args); # enlever $filtre
        $texte = array_shift($args);
        if (!strlen($texte)) {
            return;
        }
        find_in_path('filtres_images_mini.php', 'inc/', true);
        statut_effacer_images_temporaires(true); // activer la suppression des images temporaires car le compilo finit la chaine par un image_graver
        // Cas du nom de fichier local
        if (strpos(substr($texte, strlen(_DIR_RACINE)), '..') === false
            and !preg_match(',^/|[<>]|\s,S', $texte)
            and (
                file_exists(preg_replace(',[?].*$,', '', $texte))
                or tester_url_absolue($texte)
            )
        ) {
            array_unshift($args, "<img src='$texte' />");
            $res = call_user_func_array($filtre, $args);
            statut_effacer_images_temporaires(false); // desactiver pour les appels hors compilo
            return $res;
        }

        // Cas general : trier toutes les images, avec eventuellement leur <span>
        if (preg_match_all(
            ',(<([a-z]+) [^<>]*spip_documents[^<>]*>)?\s*(<img\s.*>),UimsS',
        $texte, $tags, PREG_SET_ORDER)) {
            foreach ($tags as $tag) {
                $class = extraire_attribut($tag[3], 'class');
                if (!$class or
                    (strpos($class, 'filtre_inactif') === false
                        // compat historique a virer en 3.2
                        and strpos($class, 'no_image_filtrer') === false)
                ) {
                    array_unshift($args, $tag[3]);
                    if ($reduit = call_user_func_array($filtre, $args)) {
                        // En cas de span spip_documents, modifier le style=...width:
                        if ($tag[1]) {
                            $w = extraire_attribut($reduit, 'width');
                            if (!$w and preg_match(",width:\s*(\d+)px,S", extraire_attribut($reduit, 'style'), $regs)) {
                                $w = $regs[1];
                            }
                            if ($w and ($style = extraire_attribut($tag[1], 'style'))) {
                                $style = preg_replace(",width:\s*\d+px,S", "width:${w}px", $style);
                                $replace = inserer_attribut($tag[1], 'style', $style);
                                $texte = str_replace($tag[1], $replace, $texte);
                            }
                        }
                        // traiter aussi un eventuel mouseover
                        if ($mouseover = extraire_attribut($reduit, 'onmouseover')) {
                            if (preg_match(",this[.]src=['\"]([^'\"]+)['\"],ims", $mouseover, $match)) {
                                $srcover = $match[1];
                                array_shift($args);
                                array_unshift($args, "<img src='" . $match[1] . "' />");
                                $srcover_filter = call_user_func_array($filtre, $args);
                                $srcover_filter = extraire_attribut($srcover_filter, 'src');
                                $reduit = str_replace($srcover, $srcover_filter, $reduit);
                            }
                        }
                        $texte = str_replace($tag[3], $reduit, $texte);
                    }
                    array_shift($args);
                }
            }
        }
        statut_effacer_images_temporaires(false); // desactiver pour les appels hors compilo
        return $texte;
    }

    /**
     * Retourne les tailles d'une image
     *
     * Pour les filtres `largeur` et `hauteur`
     *
     * @param string $img
     *     Balise HTML `<img ... />` ou chemin de l'image (qui peut être une URL distante).
     * @return array
     *     Liste (hauteur, largeur) en pixels
     **/
    protected function taille_image($img)
    {
        static $largeur_img = [], $hauteur_img = [];
        $srcWidth = 0;
        $srcHeight = 0;

        $src = extraire_attribut($img, 'src');

        if (!$src) {
            $src = $img;
        } else {
            $srcWidth = extraire_attribut($img, 'width');
            $srcHeight = extraire_attribut($img, 'height');
        }

        // ne jamais operer directement sur une image distante pour des raisons de perfo
        // la copie locale a toutes les chances d'etre la ou de resservir
        if (tester_url_absolue($src)) {
            include_spip('inc/distant');
            $fichier = copie_locale($src);
            $src = $fichier ? _DIR_RACINE . $fichier : $src;
        }
        if (($p = strpos($src, '?')) !== false) {
            $src = substr($src, 0, $p);
        }

        $srcsize = false;
        if (isset($largeur_img[$src])) {
            $srcWidth = $largeur_img[$src];
        }
        if (isset($hauteur_img[$src])) {
            $srcHeight = $hauteur_img[$src];
        }
        if (!$srcWidth or !$srcHeight) {
            if (file_exists($src)
                and $srcsize = spip_getimagesize($src)
            ) {
                if (!$srcWidth) {
                    $largeur_img[$src] = $srcWidth = $srcsize[0];
                }
                if (!$srcHeight) {
                    $hauteur_img[$src] = $srcHeight = $srcsize[1];
                }
            }
            // $src peut etre une reference a une image temporaire dont a n'a que le log .src
            // on s'y refere, l'image sera reconstruite en temps utile si necessaire
            elseif (@file_exists($f = "$src.src")
                and lire_fichier($f, $valeurs)
                and $valeurs = unserialize($valeurs)
            ) {
                if (!$srcWidth) {
                    $largeur_img[$src] = $srcWidth = $valeurs["largeur_dest"];
                }
                if (!$srcHeight) {
                    $hauteur_img[$src] = $srcHeight = $valeurs["hauteur_dest"];
                }
            }
        }

        return [$srcHeight, $srcWidth];
    }

    /**
     * Retourne la largeur d'une image
     *
     * @filtre
     * @link https://www.spip.net/4296
     * @uses taille_image()
     * @see  hauteur()
     *
     * @param string $img
     *     Balise HTML `<img ... />` ou chemin de l'image (qui peut être une URL distante).
     * @return int|null
     *     Largeur en pixels, NULL ou 0 si aucune image.
     **/
    protected function largeur($img)
    {
        if (!$img) {
            return;
        }
        list($h, $l) = taille_image($img);

        return $l;
    }

    /**
     * Retourne la hauteur d'une image
     *
     * @filtre
     * @link https://www.spip.net/4291
     * @uses taille_image()
     * @see  largeur()
     *
     * @param string $img
     *     Balise HTML `<img ... />` ou chemin de l'image (qui peut être une URL distante).
     * @return int|null
     *     Hauteur en pixels, NULL ou 0 si aucune image.
     **/
    protected function hauteur($img)
    {
        if (!$img) {
            return;
        }
        list($h, $l) = taille_image($img);

        return $h;
    }

    /**
     * Échappement des entités HTML avec correction des entités « brutes »
     *
     * Ces entités peuvent être générées par les butineurs lorsqu'on rentre des
     * caractères n'appartenant pas au charset de la page [iso-8859-1 par défaut]
     *
     * Attention on limite cette correction aux caracteres « hauts » (en fait > 99
     * pour aller plus vite que le > 127 qui serait logique), de manière à
     * préserver des eéhappements de caractères « bas » (par exemple `[` ou `"`)
     * et au cas particulier de `&amp;` qui devient `&amp;amp;` dans les URL
     *
     * @see corriger_toutes_entites_html()
     * @param string $texte
     * @return string
     **/
    protected function corriger_entites_html($texte)
    {
        if (strpos($texte, '&amp;') === false) {
            return $texte;
        }

        return preg_replace(',&amp;(#[0-9][0-9][0-9]+;|amp;),iS', '&\1', $texte);
    }

    /**
     * Échappement des entités HTML avec correction des entités « brutes » ainsi
     * que les `&amp;eacute;` en `&eacute;`
     *
     * Identique à `corriger_entites_html()` en corrigeant aussi les
     * `&amp;eacute;` en `&eacute;`
     *
     * @see corriger_entites_html()
     * @param string $texte
     * @return string
     **/
    protected function corriger_toutes_entites_html($texte)
    {
        if (strpos($texte, '&amp;') === false) {
            return $texte;
        }

        return preg_replace(',&amp;(#?[a-z0-9]+;),iS', '&\1', $texte);
    }

    /**
     * Échappe les `&` en `&amp;`
     *
     * @param string $texte
     * @return string
     **/
    protected function proteger_amp($texte)
    {
        return str_replace('&', '&amp;', $texte);
    }

    /**
     * Échappe en entités HTML certains caractères d'un texte
     *
     * Traduira un code HTML en transformant en entités HTML les caractères
     * en dehors du charset de la page ainsi que les `"`, `<` et `>`.
     *
     * Ceci permet d’insérer le texte d’une balise dans un `<textarea> </textarea>`
     * sans dommages.
     *
     * @filtre
     * @link https://www.spip.net/4280
     *
     * @uses echappe_html()
     * @uses echappe_retour()
     * @uses proteger_amp()
     * @uses corriger_entites_html()
     * @uses corriger_toutes_entites_html()
     *
     * @param string $texte
     *   chaine a echapper
     * @param bool $tout
     *   corriger toutes les `&amp;xx;` en `&xx;`
     * @param bool $quote
     *   Échapper aussi les simples quotes en `&#039;`
     * @return mixed|string
     */
    protected function entites_html($texte, $tout = false, $quote = true)
    {
        if (!is_string($texte) or !$texte
            or strpbrk($texte, "&\"'<>") == false
        ) {
            return $texte;
        }
        include_spip('inc/texte');
        $flags = ($quote ? ENT_QUOTES : ENT_NOQUOTES);
        $flags |= ENT_HTML401;
        $texte = spip_htmlspecialchars(echappe_retour(echappe_html($texte, '', true), '', 'proteger_amp'), $flags);
        if ($tout) {
            return corriger_toutes_entites_html($texte);
        } else {
            return corriger_entites_html($texte);
        }
    }

    /**
     * Convertit les caractères spéciaux HTML dans le charset du site.
     *
     * @exemple
     *     Si le charset de votre site est `utf-8`, `&eacute;` ou `&#233;`
     *     sera transformé en `é`
     *
     * @filtre
     * @link https://www.spip.net/5513
     *
     * @param string $texte
     *     Texte à convertir
     * @return string
     *     Texte converti
     **/
    protected function filtrer_entites($texte)
    {
        if (strpos($texte, '&') === false) {
            return $texte;
        }
        // filtrer
        $texte = html2unicode($texte);
        // remettre le tout dans le charset cible
        $texte = unicode2charset($texte);
        // cas particulier des " et ' qu'il faut filtrer aussi
        // (on le faisait deja avec un &quot;)
        if (strpos($texte, "&#") !== false) {
            $texte = str_replace(["&#039;", "&#39;", "&#034;", "&#34;"], ["'", "'", '"', '"'], $texte);
        }

        return $texte;
    }

    /**
     * Version sécurisée de filtrer_entites
     *
     * @uses interdire_scripts()
     * @uses filtrer_entites()
     *
     * @param string $t
     * @return string
     */
    protected function filtre_filtrer_entites_dist($t)
    {
        include_spip('inc/texte');
        return interdire_scripts(filtrer_entites($t));
    }

    /**
     * Supprime des caractères illégaux
     *
     * Remplace les caractères de controle par le caractère `-`
     *
     * @link http://www.w3.org/TR/REC-xml/#charsets
     *
     * @param string|array $texte
     * @return string|array
     **/
    protected function supprimer_caracteres_illegaux($texte)
    {
        static $from = "\x0\x1\x2\x3\x4\x5\x6\x7\x8\xB\xC\xE\xF\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F";
        static $to = null;

        if (is_array($texte)) {
            return array_map('supprimer_caracteres_illegaux', $texte);
        }

        if (!$to) {
            $to = str_repeat('-', strlen($from));
        }

        return strtr($texte, $from, $to);
    }

    /**
     * Correction de caractères
     *
     * Supprimer les caracteres windows non conformes et les caracteres de controle illégaux
     *
     * @param string|array $texte
     * @return string|array
     **/
    protected function corriger_caracteres($texte)
    {
        $texte = corriger_caracteres_windows($texte);
        $texte = supprimer_caracteres_illegaux($texte);

        return $texte;
    }

    /**
     * Encode du HTML pour transmission XML notamment dans les flux RSS
     *
     * Ce filtre transforme les liens en liens absolus, importe les entitées html et échappe les tags html.
     *
     * @filtre
     * @link https://www.spip.net/4287
     *
     * @param string $texte
     *     Texte à transformer
     * @return string
     *     Texte encodé pour XML
     */
    protected function texte_backend($texte)
    {
        static $apostrophe = ["&#8217;", "'"]; # n'allouer qu'une fois

        // si on a des liens ou des images, les passer en absolu
        $texte = liens_absolus($texte);

        // echapper les tags &gt; &lt;
        $texte = preg_replace(',&(gt|lt);,S', '&amp;\1;', $texte);

        // importer les &eacute;
        $texte = filtrer_entites($texte);

        // " -> &quot; et tout ce genre de choses
        $u = $GLOBALS['meta']['pcre_u'];
        $texte = str_replace("&nbsp;", " ", $texte);
        $texte = preg_replace('/\s{2,}/S' . $u, " ", $texte);
        // ne pas echapper les sinqle quotes car certains outils de syndication gerent mal
        $texte = entites_html($texte, false, false);
        // mais bien echapper les double quotes !
        $texte = str_replace('"', '&#034;', $texte);

        // verifier le charset
        $texte = charset2unicode($texte);

        // Caracteres problematiques en iso-latin 1
        if (isset($GLOBALS['meta']['charset']) and $GLOBALS['meta']['charset'] == 'iso-8859-1') {
            $texte = str_replace(chr(156), '&#156;', $texte);
            $texte = str_replace(chr(140), '&#140;', $texte);
            $texte = str_replace(chr(159), '&#159;', $texte);
        }

        // l'apostrophe curly pose probleme a certains lecteure de RSS
        // et le caractere apostrophe alourdit les squelettes avec PHP
        // ==> on les remplace par l'entite HTML
        return str_replace($apostrophe, "'", $texte);
    }

    /**
     * Encode et quote du HTML pour transmission XML notamment dans les flux RSS
     *
     * Comme texte_backend(), mais avec addslashes final pour squelettes avec PHP (rss)
     *
     * @uses texte_backend()
     * @filtre
     *
     * @param string $texte
     *     Texte à transformer
     * @return string
     *     Texte encodé et quote pour XML
     */
    protected function texte_backendq($texte)
    {
        return addslashes(texte_backend($texte));
    }

    /**
     * Enlève un numéro préfixant un texte
     *
     * Supprime `10. ` dans la chaine `10. Titre`
     *
     * @filtre
     * @link https://www.spip.net/4314
     * @see recuperer_numero() Pour obtenir le numéro
     * @example
     *     ```
     *     [<h1>(#TITRE|supprimer_numero)</h1>]
     *     ```
     *
     * @param string $texte
     *     Texte
     * @return int|string
     *     Numéro de titre, sinon chaîne vide
     **/
    protected function supprimer_numero($texte)
    {
        return preg_replace(
            ",^[[:space:]]*([0-9]+)([.)]|" . chr(194) . '?' . chr(176) . ")[[:space:]]+,S",
            "", $texte);
    }

    /**
     * Récupère un numéro préfixant un texte
     *
     * Récupère le numéro `10` dans la chaine `10. Titre`
     *
     * @filtre
     * @link https://www.spip.net/5514
     * @see supprimer_numero() Pour supprimer le numéro
     * @see balise_RANG_dist() Pour obtenir un numéro de titre
     * @example
     *     ```
     *     [(#TITRE|recuperer_numero)]
     *     ```
     *
     * @param string $texte
     *     Texte
     * @return int|string
     *     Numéro de titre, sinon chaîne vide
     **/
    protected function recuperer_numero($texte)
    {
        if (preg_match(
            ",^[[:space:]]*([0-9]+)([.)]|" . chr(194) . '?' . chr(176) . ")[[:space:]]+,S",
        $texte, $regs)) {
            return strval($regs[1]);
        } else {
            return '';
        }
    }

    /**
     * Suppression basique et brutale de tous les tags
     *
     * Supprime tous les tags `<...>`.
     * Utilisé fréquemment pour écrire des RSS.
     *
     * @filtre
     * @link https://www.spip.net/4315
     * @example
     *     ```
     *     <title>[(#TITRE|supprimer_tags|texte_backend)]</title>
     *     ```
     *
     * @note
     *     Ce filtre supprime aussi les signes inférieurs `<` rencontrés.
     *
     * @param string $texte
     *     Texte à échapper
     * @param string $rempl
     *     Inutilisé.
     * @return string
     *     Texte converti
     **/
    protected function supprimer_tags($texte, $rempl = "")
    {
        $texte = preg_replace(",<(!--|\w|/|!\[endif|!\[if)[^>]*>,US", $rempl, $texte);
        // ne pas oublier un < final non ferme car coupe
        $texte = preg_replace(",<(!--|\w|/).*$,US", $rempl, $texte);
        // mais qui peut aussi etre un simple signe plus petit que
        $texte = str_replace('<', '&lt;', $texte);

        return $texte;
    }

    /**
     * Convertit les chevrons de tag en version lisible en HTML
     *
     * Transforme les chevrons de tag `<...>` en entité HTML.
     *
     * @filtre
     * @link https://www.spip.net/5515
     * @example
     *     ```
     *     <pre>[(#TEXTE|echapper_tags)]</pre>
     *     ```
     *
     * @param string $texte
     *     Texte à échapper
     * @param string $rempl
     *     Inutilisé.
     * @return string
     *     Texte converti
     **/
    protected function echapper_tags($texte, $rempl = "")
    {
        $texte = preg_replace("/<([^>]*)>/", "&lt;\\1&gt;", $texte);

        return $texte;
    }

    /**
     * Convertit un texte HTML en texte brut
     *
     * Enlève les tags d'un code HTML, élimine les doubles espaces.
     *
     * @filtre
     * @link https://www.spip.net/4317
     * @example
     *     ```
     *     <title>[(#TITRE|textebrut) - ][(#NOM_SITE_SPIP|textebrut)]</title>
     *     ```
     *
     * @param string $texte
     *     Texte à convertir
     * @return string
     *     Texte converti
     **/
    protected function textebrut($texte)
    {
        $u = $GLOBALS['meta']['pcre_u'];
        $texte = preg_replace('/\s+/S' . $u, " ", $texte);
        $texte = preg_replace("/<(p|br)( [^>]*)?" . ">/iS", "\n\n", $texte);
        $texte = preg_replace("/^\n+/", "", $texte);
        $texte = preg_replace("/\n+$/", "", $texte);
        $texte = preg_replace("/\n +/", "\n", $texte);
        $texte = supprimer_tags($texte);
        $texte = preg_replace("/(&nbsp;| )+/S", " ", $texte);
        // nettoyer l'apostrophe curly qui pose probleme a certains rss-readers, lecteurs de mail...
        $texte = str_replace("&#8217;", "'", $texte);

        return $texte;
    }

    /**
     * Remplace les liens SPIP en liens ouvrant dans une nouvelle fenetre (target=blank)
     *
     * @filtre
     * @link https://www.spip.net/4297
     *
     * @param string $texte
     *     Texte avec des liens
     * @return string
     *     Texte avec liens ouvrants
     **/
    protected function liens_ouvrants($texte)
    {
        if (preg_match_all(",(<a\s+[^>]*https?://[^>]*class=[\"']spip_(out|url)\b[^>]+>),imsS",
        $texte, $liens, PREG_PATTERN_ORDER)) {
            foreach ($liens[0] as $a) {
                $rel = 'noopener noreferrer ' . extraire_attribut($a, 'rel');
                $ablank = inserer_attribut($a, 'rel', $rel);
                $ablank = inserer_attribut($ablank, 'target', '_blank');
                $texte = str_replace($a, $ablank, $texte);
            }
        }

        return $texte;
    }

    /**
     * Ajouter un attribut rel="nofollow" sur tous les liens d'un texte
     *
     * @param string $texte
     * @return string
     */
    protected function liens_nofollow($texte)
    {
        if (stripos($texte, "<a") === false) {
            return $texte;
        }

        if (preg_match_all(",<a\b[^>]*>,UimsS", $texte, $regs, PREG_PATTERN_ORDER)) {
            foreach ($regs[0] as $a) {
                $rel = extraire_attribut($a, "rel");
                if (strpos($rel, "nofollow") === false) {
                    $rel = "nofollow" . ($rel ? " $rel" : "");
                    $anofollow = inserer_attribut($a, "rel", $rel);
                    $texte = str_replace($a, $anofollow, $texte);
                }
            }
        }

        return $texte;
    }

    /**
     * Transforme les sauts de paragraphe HTML `p` en simples passages à la ligne `br`
     *
     * @filtre
     * @link https://www.spip.net/4308
     * @example
     *     ```
     *     [<div>(#DESCRIPTIF|PtoBR)[(#NOTES|PtoBR)]</div>]
     *     ```
     *
     * @param string $texte
     *     Texte à transformer
     * @return string
     *     Texte sans paraghaphes
     **/
    protected function PtoBR($texte)
    {
        $u = $GLOBALS['meta']['pcre_u'];
        $texte = preg_replace("@</p>@iS", "\n", $texte);
        $texte = preg_replace("@<p\b.*>@UiS", "<br />", $texte);
        $texte = preg_replace("@^\s*<br />@S" . $u, "", $texte);

        return $texte;
    }

    /**
     * Assure qu'un texte ne vas pas déborder d'un bloc
     * par la faute d'un mot trop long (souvent des URLs)
     *
     * Ne devrait plus être utilisé et fait directement en CSS par un style
     * `word-wrap:break-word;`
     *
     * @note
     *   Pour assurer la compatibilité du filtre, on encapsule le contenu par
     *   un `div` ou `span` portant ce style CSS inline.
     *
     * @filtre
     * @link https://www.spip.net/4298
     * @link http://www.alsacreations.com/tuto/lire/1038-gerer-debordement-contenu-css.html
     * @deprecated Utiliser le style CSS `word-wrap:break-word;`
     *
     * @param string $texte Texte
     * @return string Texte encadré du style CSS
     */
    protected function lignes_longues($texte)
    {
        if (!strlen(trim($texte))) {
            return $texte;
        }
        include_spip('inc/texte');
        $tag = preg_match(',</?(' . _BALISES_BLOCS . ')[>[:space:]],iS', $texte) ?
        'div' : 'span';

        return "<$tag style='word-wrap:break-word;'>$texte</$tag>";
    }

    /**
     * Passe un texte en majuscules, y compris les accents, en HTML
     *
     * Encadre le texte du style CSS `text-transform: uppercase;`.
     * Le cas spécifique du i turc est géré.
     *
     * @filtre
     * @example
     *     ```
     *     [(#EXTENSION|majuscules)]
     *     ```
     *
     * @param string $texte Texte
     * @return string Texte en majuscule
     */
    protected function majuscules($texte)
    {
        if (!strlen($texte)) {
            return '';
        }

        // Cas du turc
        if ($GLOBALS['spip_lang'] == 'tr') {
            # remplacer hors des tags et des entites
            if (preg_match_all(',<[^<>]+>|&[^;]+;,S', $texte, $regs, PREG_SET_ORDER)) {
                foreach ($regs as $n => $match) {
                    $texte = str_replace($match[0], "@@SPIP_TURC$n@@", $texte);
                }
            }

            $texte = str_replace('i', '&#304;', $texte);

            if ($regs) {
                foreach ($regs as $n => $match) {
                    $texte = str_replace("@@SPIP_TURC$n@@", $match[0], $texte);
                }
            }
        }

        // Cas general
        return "<span style='text-transform: uppercase;'>$texte</span>";
    }

    /**
     * Retourne une taille en octets humainement lisible
     *
     * Tel que "127.4 ko" ou "3.1 Mo"
     *
     * @example
     *     - `[(#TAILLE|taille_en_octets)]`
     *     - `[(#VAL{123456789}|taille_en_octets)]` affiche `117.7 Mo`
     *
     * @filtre
     * @link https://www.spip.net/4316
     * @param int $taille
     * @return string
     **/
    protected function taille_en_octets($taille)
    {
        if (!defined('_KILOBYTE')) {
            /*
             * Définit le nombre d'octets dans un Kilobyte
             *
             * @var int
             **/
            define('_KILOBYTE', 1024);
        }

        if ($taille < 1) {
            return '';
        }
        if ($taille < _KILOBYTE) {
            $taille = _T('taille_octets', ['taille' => $taille]);
        } elseif ($taille < _KILOBYTE * _KILOBYTE) {
            $taille = _T('taille_ko', ['taille' => round($taille / _KILOBYTE, 1)]);
        } elseif ($taille < _KILOBYTE * _KILOBYTE * _KILOBYTE) {
            $taille = _T('taille_mo', ['taille' => round($taille / _KILOBYTE / _KILOBYTE, 1)]);
        } else {
            $taille = _T('taille_go', ['taille' => round($taille / _KILOBYTE / _KILOBYTE / _KILOBYTE, 2)]);
        }

        return $taille;
    }

    /**
     * Rend une chaine utilisable sans dommage comme attribut HTML
     *
     * @example `<a href="#URL_ARTICLE" title="[(#TITRE|attribut_html)]">#TITRE</a>`
     *
     * @filtre
     * @link https://www.spip.net/4282
     * @uses textebrut()
     * @uses texte_backend()
     *
     * @param string $texte
     *     Texte à mettre en attribut
     * @param bool $textebrut
     *     Passe le texte en texte brut (enlève les balises html) ?
     * @return string
     *     Texte prêt pour être utilisé en attribut HTML
     **/
    protected function attribut_html($texte, $textebrut = true)
    {
        $u = $GLOBALS['meta']['pcre_u'];
        if ($textebrut) {
            $texte = preg_replace(array(",\n,", ",\s(?=\s),msS" . $u), array(" ", ""), textebrut($texte));
        }
        $texte = texte_backend($texte);
        $texte = str_replace(array("'", '"'), array('&#039;', '&#034;'), $texte);

        return preg_replace(["/&(amp;|#38;)/", "/&(?![A-Za-z]{0,4}\w{2,3};|#[0-9]{2,5};)/"], ["&", "&#38;"],
            $texte);
    }

    /**
     * Vider les URL nulles
     *
     * - Vide les URL vides comme `http://` ou `mailto:` (sans rien d'autre)
     * - échappe les entités et gère les `&amp;`
     *
     * @uses entites_html()
     *
     * @param string $url
     *     URL à vérifier et échapper
     * @param bool $entites
     *     `true` pour échapper les entités HTML.
     * @return string
     *     URL ou chaîne vide
     **/
    protected function vider_url($url, $entites = true)
    {
        # un message pour abs_url
        $GLOBALS['mode_abs_url'] = 'url';
        $url = trim($url);
        $r = ",^(?:" . _PROTOCOLES_STD . '):?/?/?$,iS';

        return preg_match($r, $url) ? '' : ($entites ? entites_html($url) : $url);
    }

    /**
     * Maquiller une adresse e-mail
     *
     * Remplace `@` par 3 caractères aléatoires.
     *
     * @uses creer_pass_aleatoire()
     *
     * @param string $texte Adresse email
     * @return string Adresse email maquillée
     **/
    protected function antispam($texte)
    {
        include_spip('inc/acces');
        $masque = creer_pass_aleatoire(3);

        return preg_replace("/@/", " $masque ", $texte);
    }

    /**
     * Vérifie un accès à faible sécurité
     *
     * Vérifie qu'un visiteur peut accéder à la page demandée,
     * qui est protégée par une clé, calculée à partir du low_sec de l'auteur,
     * et des paramètres le composant l'appel (op, args)
     *
     * @example
     *     `[(#ID_AUTEUR|securiser_acces{#ENV{cle}, rss, #ENV{op}, #ENV{args}}|sinon_interdire_acces)]`
     *
     * @see  bouton_spip_rss() pour générer un lien de faible sécurité pour les RSS privés
     * @see  afficher_low_sec() pour calculer une clé valide
     * @uses verifier_low_sec()
     *
     * @filtre
     * @param int $id_auteur
     *     L'auteur qui demande la page
     * @param string $cle
     *     La clé à tester
     * @param string $dir
     *     Un type d'accès (nom du répertoire dans lequel sont rangés les squelettes demandés, tel que 'rss')
     * @param string $op
     *     Nom de l'opération éventuelle
     * @param string $args
     *     Nom de l'argument calculé
     * @return bool
     *     True si on a le droit d'accès, false sinon.
     **/
    protected function securiser_acces($id_auteur, $cle, $dir, $op = '', $args = '')
    {
        include_spip('inc/acces');
        if ($op) {
            $dir .= " $op $args";
        }

        return verifier_low_sec($id_auteur, $cle, $dir);
    }

    /**
     * Retourne le second paramètre lorsque
     * le premier est considere vide, sinon retourne le premier paramètre.
     *
     * En php `sinon($a, 'rien')` retourne `$a`, ou `'rien'` si `$a` est vide.
     * En filtre SPIP `|sinon{#TEXTE, rien}` : affiche `#TEXTE` ou `rien` si `#TEXTE` est vide,
     *
     * @filtre
     * @see filtre_logique() pour la compilation du filtre dans un squelette
     * @link https://www.spip.net/4313
     * @note
     *     L'utilisation de `|sinon` en tant que filtre de squelette
     *     est directement compilé dans `public/references` par la fonction `filtre_logique()`
     *
     * @param mixed $texte
     *     Contenu de reference a tester
     * @param mixed $sinon
     *     Contenu a retourner si le contenu de reference est vide
     * @return mixed
     *     Retourne $texte, sinon $sinon.
     **/
    protected function sinon($texte, $sinon = '')
    {
        if ($texte or (!is_array($texte) and strlen($texte))) {
            return $texte;
        } else {
            return $sinon;
        }
    }

    /**
     * Filtre `|choixsivide{vide, pas vide}` alias de `|?{si oui, si non}` avec les arguments inversés
     *
     * @example
     *     `[(#TEXTE|choixsivide{vide, plein})]` affiche vide si le `#TEXTE`
     *     est considéré vide par PHP (chaîne vide, false, 0, tableau vide, etc…).
     *     C'est l'équivalent de `[(#TEXTE|?{plein, vide})]`
     *
     * @filtre
     * @see choixsiegal()
     * @link https://www.spip.net/4189
     *
     * @param mixed $a
     *     La valeur à tester
     * @param mixed $vide
     *     Ce qui est retourné si `$a` est considéré vide
     * @param mixed $pasvide
     *     Ce qui est retourné sinon
     * @return mixed
     **/
    protected function choixsivide($a, $vide, $pasvide)
    {
        return $a ? $pasvide : $vide;
    }

    /**
     * Filtre `|choixsiegal{valeur, sioui, sinon}`
     *
     * @example
     *     `#LANG_DIR|choixsiegal{ltr,left,right}` retourne `left` si
     *      `#LANG_DIR` vaut `ltr` et `right` sinon.
     *
     * @filtre
     * @link https://www.spip.net/4148
     *
     * @param mixed $a1
     *     La valeur à tester
     * @param mixed $a2
     *     La valeur de comparaison
     * @param mixed $v
     *     Ce qui est retourné si la comparaison est vraie
     * @param mixed $f
     *     Ce qui est retourné sinon
     * @return mixed
     **/
    protected function choixsiegal($a1, $a2, $v, $f)
    {
        return ($a1 == $a2) ? $v : $f;
    }

    //
    // Export iCal
    //

    /**
     * Adapte un texte pour être inséré dans une valeur d'un export ICAL
     *
     * Passe le texte en utf8, enlève les sauts de lignes et échappe les virgules.
     *
     * @example `SUMMARY:[(#TITRE|filtrer_ical)]`
     * @filtre
     *
     * @param string $texte
     * @return string
     **/
    protected function filtrer_ical($texte)
    {
        #include_spip('inc/charsets');
        $texte = html2unicode($texte);
        $texte = unicode2charset(charset2unicode($texte, $GLOBALS['meta']['charset'], 1), 'utf-8');
        $texte = preg_replace("/\n/", " ", $texte);
        $texte = preg_replace("/,/", "\,", $texte);

        return $texte;
    }

    /**
     * Transforme les sauts de ligne simples en sauts forcés avec `_ `
     *
     * Ne modifie pas les sauts de paragraphe (2 sauts consécutifs au moins),
     * ou les retours à l'intérieur de modèles ou de certaines balises html.
     *
     * @note
     *     Cette fonction pouvait être utilisée pour forcer les alinéas,
     *     (retours à la ligne sans saut de paragraphe), mais ce traitement
     *     est maintenant automatique.
     *     Cf. plugin Textwheel et la constante _AUTOBR
     *
     * @uses echappe_html()
     * @uses echappe_retour()
     *
     * @param string $texte
     * @param string $delim
     *      Ce par quoi sont remplacés les sauts
     * @return string
     **/
    protected function post_autobr($texte, $delim = "\n_ ")
    {
        if (!function_exists('echappe_html')) {
            include_spip('inc/texte_mini');
        }
        $texte = str_replace("\r\n", "\r", $texte);
        $texte = str_replace("\r", "\n", $texte);

        if (preg_match(",\n+$,", $texte, $fin)) {
            $texte = substr($texte, 0, -strlen($fin = $fin[0]));
        } else {
            $fin = '';
        }

        $texte = echappe_html($texte, '', true);

        // echapper les modeles
        if (strpos($texte, "<") !== false) {
            include_spip('inc/lien');
            if (defined('_PREG_MODELE')) {
                $preg_modeles = "@" . _PREG_MODELE . "@imsS";
                $texte = echappe_html($texte, '', true, $preg_modeles);
            }
        }

        $debut = '';
        $suite = $texte;
        while ($t = strpos('-' . $suite, "\n", 1)) {
            $debut .= substr($suite, 0, $t - 1);
            $suite = substr($suite, $t);
            $car = substr($suite, 0, 1);
            if (($car <> '-') and ($car <> '_') and ($car <> "\n") and ($car <> "|") and ($car <> "}")
                and !preg_match(',^\s*(\n|</?(quote|div|dl|dt|dd)|$),S', ($suite))
                and !preg_match(',</?(quote|div|dl|dt|dd)> *$,iS', $debut)
            ) {
                $debut .= $delim;
            } else {
                $debut .= "\n";
            }
            if (preg_match(",^\n+,", $suite, $regs)) {
                $debut .= $regs[0];
                $suite = substr($suite, strlen($regs[0]));
            }
        }
        $texte = $debut . $suite;

        $texte = echappe_retour($texte);

        return $texte . $fin;
    }

    /**
     * Expression régulière pour obtenir le contenu des extraits idiomes `<:module:cle:>`
     *
     * @var string
     */
    define('_EXTRAIRE_IDIOME', '@<:(?:([a-z0-9_]+):)?([a-z0-9_]+):>@isS');

    /**
     * Extrait une langue des extraits idiomes (`<:module:cle_de_langue:>`)
     *
     * Retrouve les balises `<:cle_de_langue:>` d'un texte et remplace son contenu
     * par l'extrait correspondant à la langue demandée (si possible), sinon dans la
     * langue par défaut du site.
     *
     * Ne pas mettre de span@lang=fr si on est déjà en fr.
     *
     * @filtre
     * @uses inc_traduire_dist()
     * @uses code_echappement()
     * @uses echappe_retour()
     *
     * @param string $letexte
     * @param string $lang
     *     Langue à retrouver (si vide, utilise la langue en cours).
     * @param array $options Options {
     * @type bool $echappe_span
     *         True pour échapper les balises span (false par défaut)
     * @type string $lang_defaut
     *         Code de langue : permet de définir la langue utilisée par défaut,
     *         en cas d'absence de traduction dans la langue demandée.
     *         Par défaut la langue du site.
     *         Indiquer 'aucune' pour ne pas retourner de texte si la langue
     *         exacte n'a pas été trouvée.
     * }
     * @return string
     **/
    protected function extraire_idiome($letexte, $lang = null, $options = [])
    {
        static $traduire = false;
        if ($letexte
            and preg_match_all(_EXTRAIRE_IDIOME, $letexte, $regs, PREG_SET_ORDER)
        ) {
            if (!$traduire) {
                $traduire = charger_fonction('traduire', 'inc');
                include_spip('inc/lang');
            }
            if (!$lang) {
                $lang = $GLOBALS['spip_lang'];
            }
            // Compatibilité avec le prototype de fonction précédente qui utilisait un boolean
            if (is_bool($options)) {
                $options = ['echappe_span' => $options];
            }
            if (!isset($options['echappe_span'])) {
                $options = array_merge($options, ['echappe_span' => false]);
            }

            foreach ($regs as $reg) {
                $cle = ($reg[1] ? $reg[1] . ':' : '') . $reg[2];
                $desc = $traduire($cle, $lang, true);
                $l = $desc->langue;
                // si pas de traduction, on laissera l'écriture de l'idiome entier dans le texte.
                if (strlen($desc->texte)) {
                    $trad = code_echappement($desc->texte, 'idiome', false);
                    if ($l !== $lang) {
                        $trad = str_replace("'", '"', inserer_attribut($trad, 'lang', $l));
                    }
                    if (lang_dir($l) !== lang_dir($lang)) {
                        $trad = str_replace("'", '"', inserer_attribut($trad, 'dir', lang_dir($l)));
                    }
                    if (!$options['echappe_span']) {
                        $trad = echappe_retour($trad, 'idiome');
                    }
                    $letexte = str_replace($reg[0], $trad, $letexte);
                }
            }
        }
        return $letexte;
    }

    /**
     * Expression régulière pour obtenir le contenu des extraits polyglottes `<multi>`
     *
     * @var string
     */
    define('_EXTRAIRE_MULTI', "@<multi>(.*?)</multi>@sS");


    /**
     * Extrait une langue des extraits polyglottes (`<multi>`)
     *
     * Retrouve les balises `<multi>` d'un texte et remplace son contenu
     * par l'extrait correspondant à la langue demandée.
     *
     * Si la langue demandée n'est pas trouvée dans le multi, ni une langue
     * approchante (exemple `fr` si on demande `fr_TU`), on retourne l'extrait
     * correspondant à la langue par défaut (option 'lang_defaut'), qui est
     * par défaut la langue du site. Et si l'extrait n'existe toujours pas
     * dans cette langue, ça utilisera la première langue utilisée
     * dans la balise `<multi>`.
     *
     * Ne pas mettre de span@lang=fr si on est déjà en fr.
     *
     * @filtre
     * @link https://www.spip.net/5332
     *
     * @uses extraire_trads()
     * @uses approcher_langue()
     * @uses lang_typo()
     * @uses code_echappement()
     * @uses echappe_retour()
     *
     * @param string $letexte
     * @param string $lang
     *     Langue à retrouver (si vide, utilise la langue en cours).
     * @param array $options Options {
     * @type bool $echappe_span
     *         True pour échapper les balises span (false par défaut)
     * @type string $lang_defaut
     *         Code de langue : permet de définir la langue utilisée par défaut,
     *         en cas d'absence de traduction dans la langue demandée.
     *         Par défaut la langue du site.
     *         Indiquer 'aucune' pour ne pas retourner de texte si la langue
     *         exacte n'a pas été trouvée.
     * }
     * @return string
     **/
    protected function extraire_multi($letexte, $lang = null, $options = [])
    {
        if ($letexte
            and preg_match_all(_EXTRAIRE_MULTI, $letexte, $regs, PREG_SET_ORDER)
        ) {
            if (!$lang) {
                $lang = $GLOBALS['spip_lang'];
            }

            // Compatibilité avec le prototype de fonction précédente qui utilisait un boolean
            if (is_bool($options)) {
                $options = array('echappe_span' => $options, 'lang_defaut' => _LANGUE_PAR_DEFAUT);
            }
            if (!isset($options['echappe_span'])) {
                $options = array_merge($options, ['echappe_span' => false]);
            }
            if (!isset($options['lang_defaut'])) {
                $options = array_merge($options, array('lang_defaut' => _LANGUE_PAR_DEFAUT));
            }

            include_spip('inc/lang');
            foreach ($regs as $reg) {
                // chercher la version de la langue courante
                $trads = extraire_trads($reg[1]);
                if ($l = approcher_langue($trads, $lang)) {
                    $trad = $trads[$l];
                } else {
                    if ($options['lang_defaut'] == 'aucune') {
                        $trad = '';
                    } else {
                        // langue absente, prendre le fr ou une langue précisée (meme comportement que inc/traduire.php)
                        // ou la premiere dispo
                        // mais typographier le texte selon les regles de celle-ci
                        // Attention aux blocs multi sur plusieurs lignes
                        if (!$l = approcher_langue($trads, $options['lang_defaut'])) {
                            $l = key($trads);
                        }
                        $trad = $trads[$l];
                        $typographie = charger_fonction(lang_typo($l), 'typographie');
                        $trad = $typographie($trad);
                        // Tester si on echappe en span ou en div
                        // il ne faut pas echapper en div si propre produit un seul paragraphe
                        include_spip('inc/texte');
                        $trad_propre = preg_replace(",(^<p[^>]*>|</p>$),Uims", "", propre($trad));
                        $mode = preg_match(',</?(' . _BALISES_BLOCS . ')[>[:space:]],iS', $trad_propre) ? 'div' : 'span';
                        $trad = code_echappement($trad, 'multi', false, $mode);
                        $trad = str_replace("'", '"', inserer_attribut($trad, 'lang', $l));
                        if (lang_dir($l) !== lang_dir($lang)) {
                            $trad = str_replace("'", '"', inserer_attribut($trad, 'dir', lang_dir($l)));
                        }
                        if (!$options['echappe_span']) {
                            $trad = echappe_retour($trad, 'multi');
                        }
                    }
                }
                $letexte = str_replace($reg[0], $trad, $letexte);
            }
        }

        return $letexte;
    }

    /**
     * Convertit le contenu d'une balise `<multi>` en un tableau
     *
     * Exemple de blocs.
     * - `texte par défaut [fr] en français [en] en anglais`
     * - `[fr] en français [en] en anglais`
     *
     * @param string $bloc
     *     Le contenu intérieur d'un bloc multi
     * @return array [code de langue => texte]
     *     Peut retourner un code de langue vide, lorsqu'un texte par défaut est indiqué.
     **/
    protected function extraire_trads($bloc)
    {
        $lang = '';
        // ce reg fait planter l'analyse multi s'il y a de l'{italique} dans le champ
        //	while (preg_match("/^(.*?)[{\[]([a-z_]+)[}\]]/siS", $bloc, $regs)) {
        while (preg_match("/^(.*?)[\[]([a-z_]+)[\]]/siS", $bloc, $regs)) {
            $texte = trim($regs[1]);
            if ($texte or $lang) {
                $trads[$lang] = $texte;
            }
            $bloc = substr($bloc, strlen($regs[0]));
            $lang = $regs[2];
        }
        $trads[$lang] = $bloc;

        return $trads;
    }

    /**
     * Calculer l'initiale d'un nom
     *
     * @param string $nom
     * @return string L'initiale en majuscule
     */
    protected function filtre_initiale($nom)
    {
        return spip_substr(trim(strtoupper(extraire_multi($nom))), 0, 1);
    }

    /**
     * Retourne la donnée si c'est la première fois qu'il la voit
     *
     * Il est possible de gérer différentes "familles" de données avec
     * le second paramètre.
     *
     * @filtre
     * @link https://www.spip.net/4320
     * @example
     *     ```
     *     [(#ID_SECTEUR|unique)]
     *     [(#ID_SECTEUR|unique{tete})] n'a pas d'incidence sur
     *     [(#ID_SECTEUR|unique{pied})]
     *     [(#ID_SECTEUR|unique{pied,1})] affiche le nombre d'éléments.
     *     Préférer totefois #TOTAL_UNIQUE{pied}
     *     ```
     *
     * @todo
     *    Ameliorations possibles :
     *
     *    1) si la donnée est grosse, mettre son md5 comme clé
     *    2) purger $mem quand on change de squelette (sinon bug inclusions)
     *
     * @param string $donnee
     *      Donnée que l'on souhaite unique
     * @param string $famille
     *      Famille de stockage (1 unique donnée par famille)
     *
     *      - _spip_raz_ : (interne) Vide la pile de mémoire et la retourne
     *      - _spip_set_ : (interne) Affecte la pile de mémoire avec la donnée
     * @param bool $cpt
     *      True pour obtenir le nombre d'éléments différents stockés
     * @return string|int|array|null|void
     *
     *      - string : Donnée si c'est la première fois qu'elle est vue
     *      - void : si la donnée a déjà été vue
     *      - int : si l'on demande le nombre d'éléments
     *      - array (interne) : si on dépile
     *      - null (interne) : si on empile
     **/
    protected function unique($donnee, $famille = '', $cpt = false)
    {
        static $mem = [];
        // permettre de vider la pile et de la restaurer
        // pour le calcul de introduction...
        if ($famille == '_spip_raz_') {
            $tmp = $mem;
            $mem = [];

            return $tmp;
        } elseif ($famille == '_spip_set_') {
            $mem = $donnee;

            return;
        }
        // eviter une notice
        if (!isset($mem[$famille])) {
            $mem[$famille] = [];
        }
        if ($cpt) {
            return count($mem[$famille]);
        }
        // eviter une notice
        if (!isset($mem[$famille][$donnee])) {
            $mem[$famille][$donnee] = 0;
        }
        if (!($mem[$famille][$donnee]++)) {
            return $donnee;
        }
    }

    /**
     * Filtre qui alterne des valeurs en fonction d'un compteur
     *
     * Affiche à tour de rôle et dans l'ordre, un des arguments transmis
     * à chaque incrément du compteur.
     *
     * S'il n'y a qu'un seul argument, et que c'est un tableau,
     * l'alternance se fait sur les valeurs du tableau.
     *
     * Souvent appliqué à l'intérieur d'une boucle, avec le compteur `#COMPTEUR_BOUCLE`
     *
     * @example
     *     - `[(#COMPTEUR_BOUCLE|alterner{bleu,vert,rouge})]`
     *     - `[(#COMPTEUR_BOUCLE|alterner{#LISTE{bleu,vert,rouge}})]`
     *
     * @filtre
     * @link https://www.spip.net/4145
     *
     * @param int $i
     *     Le compteur
     * @return mixed
     *     Une des valeurs en fonction du compteur.
     **/
    protected function alterner($i)
    {
        // recuperer les arguments (attention fonctions un peu space)
        $num = func_num_args();
        $args = func_get_args();

        if ($num == 2 && is_array($args[1])) {
            $args = $args[1];
            array_unshift($args, '');
            $num = count($args);
        }

        // renvoyer le i-ieme argument, modulo le nombre d'arguments
        return $args[(intval($i) - 1) % ($num - 1) + 1];
    }

    /**
     * Récupérer un attribut d'une balise HTML
     *
     * la regexp est mortelle : cf. `tests/unit/filtres/extraire_attribut.php`
     * Si on a passé un tableau de balises, renvoyer un tableau de résultats
     * (dans ce cas l'option `$complet` n'est pas disponible)
     *
     * @param string|array $balise
     *     Texte ou liste de textes dont on veut extraire des balises
     * @param string $attribut
     *     Nom de l'attribut désiré
     * @param bool $complet
     *     True pour retourner un tableau avec
     *     - le texte de la balise
     *     - l'ensemble des résultats de la regexp ($r)
     * @return string|array
     *     - Texte de l'attribut retourné, ou tableau des texte d'attributs
     *       (si 1er argument tableau)
     *     - Tableau complet (si 2e argument)
     **/
    protected function extraire_attribut($balise, $attribut, $complet = false)
    {
        if (is_array($balise)) {
            array_walk(
                $balise,
                function(&$a, $key, $t){
                    $a = extraire_attribut($a, $t);
                },
                $attribut
            );

            return $balise;
        }
        if (preg_match(
            ',(^.*?<(?:(?>\s*)(?>[\w:.-]+)(?>(?:=(?:"[^"]*"|\'[^\']*\'|[^\'"]\S*))?))*?)(\s+'
            . $attribut
            . '(?:=\s*("[^"]*"|\'[^\']*\'|[^\'"]\S*))?)()((?:[\s/][^>]*)?>.*),isS',

        $balise, $r)) {
            if (isset($r[3][0]) and ($r[3][0] == '"' || $r[3][0] == "'")) {
                $r[4] = substr($r[3], 1, -1);
                $r[3] = $r[3][0];
            } elseif ($r[3] !== '') {
                $r[4] = $r[3];
                $r[3] = '';
            } else {
                $r[4] = trim($r[2]);
            }
            $att = $r[4];
            if (strpos($att, "&#") !== false) {
                $att = str_replace(["&#039;", "&#39;", "&#034;", "&#34;"], ["'", "'", '"', '"'], $att);
            }
            $att = filtrer_entites($att);
        } else {
            $att = null;
        }

        if ($complet) {
            return [$att, $r];
        } else {
            return $att;
        }
    }

    /**
     * Insérer (ou modifier) un attribut html dans une balise
     *
     * @example
     *     - `[(#LOGO_ARTICLE|inserer_attribut{class, logo article})]`
     *     - `[(#LOGO_ARTICLE|inserer_attribut{alt, #TTTRE|attribut_html|couper{60}})]`
     *     - `[(#FICHIER|image_reduire{40}|inserer_attribut{data-description, #DESCRIPTIF})]`
     *       Laissera les balises HTML de la valeur (ici `#DESCRIPTIF`) si on n'applique pas le
     *       filtre `attribut_html` dessus.
     *
     * @filtre
     * @link https://www.spip.net/4294
     * @uses attribut_html()
     * @uses extraire_attribut()
     *
     * @param string $balise
     *     Code html de la balise (ou contenant une balise)
     * @param string $attribut
     *     Nom de l'attribut html à modifier
     * @param string $val
     *     Valeur de l'attribut à appliquer
     * @param bool $proteger
     *     Prépare la valeur en tant qu'attribut de balise (mais conserve les balises html).
     * @param bool $vider
     *     True pour vider l'attribut. Une chaîne vide pour `$val` fera pareil.
     * @return string
     *     Code html modifié
     **/
    protected function inserer_attribut($balise, $attribut, $val, $proteger = true, $vider = false)
    {
        // preparer l'attribut
        // supprimer les &nbsp; etc mais pas les balises html
        // qui ont un sens dans un attribut value d'un input
        if ($proteger) {
            $val = attribut_html($val, false);
        }

        // echapper les ' pour eviter tout bug
        $val = str_replace("'", "&#039;", $val);
        if ($vider and strlen($val) == 0) {
            $insert = '';
        } else {
            $insert = " $attribut='$val'";
        }

        list($old, $r) = extraire_attribut($balise, $attribut, true);

        if ($old !== null) {
            // Remplacer l'ancien attribut du meme nom
            $balise = $r[1] . $insert . $r[5];
        } else {
            // preferer une balise " />" (comme <img />)
            if (preg_match(',/>,', $balise)) {
                $balise = preg_replace(",\s?/>,S", $insert . " />", $balise, 1);
            } // sinon une balise <a ...> ... </a>
            else {
                $balise = preg_replace(",\s?>,S", $insert . ">", $balise, 1);
            }
        }

        return $balise;
    }

    /**
     * Supprime un attribut HTML
     *
     * @example `[(#LOGO_ARTICLE|vider_attribut{class})]`
     *
     * @filtre
     * @link https://www.spip.net/4142
     * @uses inserer_attribut()
     * @see  extraire_attribut()
     *
     * @param string $balise Code HTML de l'élément
     * @param string $attribut Nom de l'attribut à enlever
     * @return string Code HTML sans l'attribut
     **/
    protected function vider_attribut($balise, $attribut)
    {
        return inserer_attribut($balise, $attribut, '', false, true);
    }

    /**
     * Un filtre pour déterminer le nom du statut des inscrits
     *
     * @param void|int $id
     * @param string $mode
     * @return string
     */
    protected function tester_config($id, $mode = '')
    {
        include_spip('action/inscrire_auteur');

        return tester_statut_inscription($mode, $id);
    }

    //
    // Quelques fonctions de calcul arithmetique
    //
    protected function floatstr($a)
    {
        return str_replace(',', '.', (string) floatval($a));
    }
    protected function strize($f, $a, $b)
    {
        return floatstr($f(floatstr($a), floatstr($b)));
    }

    /**
     * Additionne 2 nombres
     *
     * @filtre
     * @link https://www.spip.net/4307
     * @see moins()
     * @example
     *     ```
     *     [(#VAL{28}|plus{14})]
     *     ```
     *
     * @param int $a
     * @param int $b
     * @return int $a+$b
     **/
    protected function plus($a, $b)
    {
        return $a + $b;
    }
    protected function strplus($a, $b)
    {
        return strize('plus', $a, $b);
    }
    /**
     * Soustrait 2 nombres
     *
     * @filtre
     * @link https://www.spip.net/4302
     * @see plus()
     * @example
     *     ```
     *     [(#VAL{28}|moins{14})]
     *     ```
     *
     * @param int $a
     * @param int $b
     * @return int $a-$b
     **/
    protected function moins($a, $b)
    {
        return $a - $b;
    }
    protected function strmoins($a, $b)
    {
        return strize('moins', $a, $b);
    }

    /**
     * Multiplie 2 nombres
     *
     * @filtre
     * @link https://www.spip.net/4304
     * @see div()
     * @see modulo()
     * @example
     *     ```
     *     [(#VAL{28}|mult{14})]
     *     ```
     *
     * @param int $a
     * @param int $b
     * @return int $a*$b
     **/
    protected function mult($a, $b)
    {
        return $a * $b;
    }
    protected function strmult($a, $b)
    {
        return strize('mult', $a, $b);
    }

    /**
     * Divise 2 nombres
     *
     * @filtre
     * @link https://www.spip.net/4279
     * @see mult()
     * @see modulo()
     * @example
     *     ```
     *     [(#VAL{28}|div{14})]
     *     ```
     *
     * @param int $a
     * @param int $b
     * @return int $a/$b (ou 0 si $b est nul)
     **/
    protected function div($a, $b)
    {
        return $b ? $a / $b : 0;
    }
    protected function strdiv($a, $b)
    {
        return strize('div', $a, $b);
    }

    /**
     * Retourne le modulo 2 nombres
     *
     * @filtre
     * @link https://www.spip.net/4301
     * @see mult()
     * @see div()
     * @example
     *     ```
     *     [(#VAL{28}|modulo{14})]
     *     ```
     *
     * @param int $nb
     * @param int $mod
     * @param int $add
     * @return int ($nb % $mod) + $add
     **/
    protected function modulo($nb, $mod, $add = 0)
    {
        return ($mod ? $nb % $mod : 0) + $add;
    }

    /**
     * Vérifie qu'un nom (d'auteur) ne comporte pas d'autres tags que <multi>
     * et ceux volontairement spécifiés dans la constante
     *
     * @param string $nom
     *      Nom (signature) proposé
     * @return bool
     *      - false si pas conforme,
     *      - true sinon
     **/
    protected function nom_acceptable($nom)
    {
        if (!is_string($nom)) {
            return false;
        }
        if (!defined('_TAGS_NOM_AUTEUR')) {
            define('_TAGS_NOM_AUTEUR', '');
        }
        $tags_acceptes = array_unique(explode(',', 'multi,' . _TAGS_NOM_AUTEUR));
        foreach ($tags_acceptes as $tag) {
            if (strlen($tag)) {
                $remp1[] = '<' . trim($tag) . '>';
                $remp1[] = '</' . trim($tag) . '>';
                $remp2[] = '\x60' . trim($tag) . '\x61';
                $remp2[] = '\x60/' . trim($tag) . '\x61';
            }
        }
        $v_nom = str_replace($remp2, $remp1, supprimer_tags(str_replace($remp1, $remp2, $nom)));

        return str_replace('&lt;', '<', $v_nom) == $nom;
    }

    /**
     * Vérifier la conformité d'une ou plusieurs adresses email (suivant RFC 822)
     *
     * @param string $adresses
     *      Adresse ou liste d'adresse
     * @return bool|string
     *      - false si pas conforme,
     *      - la normalisation de la dernière adresse donnée sinon
     **/
    protected function email_valide($adresses)
    {
        // eviter d'injecter n'importe quoi dans preg_match
        if (!is_string($adresses)) {
            return false;
        }

        // Si c'est un spammeur autant arreter tout de suite
        if (preg_match(",[\n\r].*(MIME|multipart|Content-),i", $adresses)) {
            spip_log("Tentative d'injection de mail : $adresses");

            return false;
        }

        foreach (explode(',', $adresses) as $v) {
            // nettoyer certains formats
            // "Marie Toto <Marie@toto.com>"
            $adresse = trim(preg_replace(",^[^<>\"]*<([^<>\"]+)>$,i", "\\1", $v));
            // RFC 822
            if (!preg_match('#^[^()<>@,;:\\"/[:space:]]+(@([-_0-9a-z]+\.)*[-_0-9a-z]+)$#i', $adresse)) {
                return false;
            }
        }

        return $adresse;
    }

    /**
     * Permet d'afficher un symbole à côté des liens pointant vers les
     * documents attachés d'un article (liens ayant `rel=enclosure`).
     *
     * @filtre
     * @link https://www.spip.net/4134
     *
     * @param string $tags Texte
     * @return string Texte
     **/
    protected function afficher_enclosures($tags)
    {
        $s = [];
        foreach (extraire_balises($tags, 'a') as $tag) {
            if (extraire_attribut($tag, 'rel') == 'enclosure'
                and $t = extraire_attribut($tag, 'href')
            ) {
                $s[] = preg_replace(',>[^<]+</a>,S',
                    '>'
                    . http_img_pack('attachment-16.png', $t,
                        'title="' . attribut_html($t) . '"')
                    . '</a>', $tag);
            }
        }

        return join('&nbsp;', $s);
    }

    /**
     * Filtre des liens HTML `<a>` selon la valeur de leur attribut `rel`
     * et ne retourne que ceux là.
     *
     * @filtre
     * @link https://www.spip.net/4187
     *
     * @param string $tags Texte
     * @param string $rels Attribut `rel` à capturer (ou plusieurs séparés par des virgules)
     * @return string Liens trouvés
     **/
    protected function afficher_tags($tags, $rels = 'tag,directory')
    {
        $s = [];
        foreach (extraire_balises($tags, 'a') as $tag) {
            $rel = extraire_attribut($tag, 'rel');
            if (strstr(",$rels,", ",$rel,")) {
                $s[] = $tag;
            }
        }

        return join(', ', $s);
    }

    /**
     * Convertir les médias fournis par un flux RSS (podcasts)
     * en liens conformes aux microformats
     *
     * Passe un `<enclosure url="fichier" length="5588242" type="audio/mpeg"/>`
     * au format microformat `<a rel="enclosure" href="fichier" ...>fichier</a>`.
     *
     * Peut recevoir un `<link` ou un `<media:content` parfois.
     *
     * Attention : `length="zz"` devient `title="zz"`, pour rester conforme.
     *
     * @filtre
     * @see microformat2enclosure() Pour l'inverse
     *
     * @param string $e Tag RSS `<enclosure>`
     * @return string Tag HTML `<a>` avec microformat.
     **/
    protected function enclosure2microformat($e)
    {
        if (!$url = filtrer_entites(extraire_attribut($e, 'url'))) {
            $url = filtrer_entites(extraire_attribut($e, 'href'));
        }
        $type = extraire_attribut($e, 'type');
        if (!$length = extraire_attribut($e, 'length')) {
            # <media:content : longeur dans fileSize. On tente.
            $length = extraire_attribut($e, 'fileSize');
        }
        $fichier = basename($url);

        return '<a rel="enclosure"'
            . ($url ? ' href="' . spip_htmlspecialchars($url) . '"' : '')
            . ($type ? ' type="' . spip_htmlspecialchars($type) . '"' : '')
            . ($length ? ' title="' . spip_htmlspecialchars($length) . '"' : '')
            . '>' . $fichier . '</a>';
    }

    /**
     * Convertir les liens conformes aux microformats en médias pour flux RSS,
     * par exemple pour les podcasts
     *
     * Passe un texte ayant des liens avec microformat
     * `<a rel="enclosure" href="fichier" ...>fichier</a>`
     * au format RSS `<enclosure url="fichier" ... />`.
     *
     * @filtre
     * @see enclosure2microformat() Pour l'inverse
     *
     * @param string $tags Texte HTML ayant des tag `<a>` avec microformat
     * @return string Tags RSS `<enclosure>`.
     **/
    protected function microformat2enclosure($tags)
    {
        $enclosures = [];
        foreach (extraire_balises($tags, 'a') as $e) {
            if (extraire_attribut($e, 'rel') == 'enclosure') {
                $url = filtrer_entites(extraire_attribut($e, 'href'));
                $type = extraire_attribut($e, 'type');
                if (!$length = intval(extraire_attribut($e, 'title'))) {
                    $length = intval(extraire_attribut($e, 'length'));
                } # vieux data
                $fichier = basename($url);
                $enclosures[] = '<enclosure'
                    . ($url ? ' url="' . spip_htmlspecialchars($url) . '"' : '')
                    . ($type ? ' type="' . spip_htmlspecialchars($type) . '"' : '')
                    . ($length ? ' length="' . $length . '"' : '')
                    . ' />';
            }
        }

        return join("\n", $enclosures);
    }

    /**
     * Créer les éléments ATOM `<dc:subject>` à partir des tags
     *
     * Convertit les liens avec attribut `rel="tag"`
     * en balise `<dc:subject></dc:subject>` pour les flux RSS au format Atom.
     *
     * @filtre
     *
     * @param string $tags Texte
     * @return string Tags RSS Atom `<dc:subject>`.
     **/
    protected function tags2dcsubject($tags)
    {
        $subjects = '';
        foreach (extraire_balises($tags, 'a') as $e) {
            if (extraire_attribut($e, rel) == 'tag') {
                $subjects .= '<dc:subject>'
                    . texte_backend(textebrut($e))
                    . '</dc:subject>' . "\n";
            }
        }

        return $subjects;
    }

    /**
     * Retourne la premiere balise html du type demandé
     *
     * Retourne le contenu d'une balise jusqu'à la première fermeture rencontrée
     * du même type.
     * Si on a passe un tableau de textes, retourne un tableau de resultats.
     *
     * @example `[(#DESCRIPTIF|extraire_balise{img})]`
     *
     * @filtre
     * @link https://www.spip.net/4289
     * @see extraire_balises()
     * @note
     *     Attention : les résultats peuvent être incohérents sur des balises imbricables,
     *     tel que demander à extraire `div` dans le texte `<div> un <div> mot </div> absent </div>`,
     *     ce qui retournerait `<div> un <div> mot </div>` donc.
     *
     * @param string|array $texte
     *     Texte(s) dont on souhaite extraire une balise html
     * @param string $tag
     *     Nom de la balise html à extraire
     * @return void|string|array
     *     - Code html de la balise, sinon rien
     *     - Tableau de résultats, si tableau en entrée.
     **/
    protected function extraire_balise($texte, $tag = 'a')
    {
        if (is_array($texte)) {
            array_walk(
                $texte,
                function (&$a, $key, $t): void {
                    $a = extraire_balise($a, $t);
                },
                $tag
            );

            return $texte;
        }

        if (preg_match(
            ",<$tag\b[^>]*(/>|>.*</$tag\b[^>]*>|>),UimsS",
        $texte, $regs)) {
            return $regs[0];
        }
    }

    /**
     * Extrait toutes les balises html du type demandé
     *
     * Retourne dans un tableau le contenu de chaque balise jusqu'à la première
     * fermeture rencontrée du même type.
     * Si on a passe un tableau de textes, retourne un tableau de resultats.
     *
     * @example `[(#TEXTE|extraire_balises{img}|implode{" - "})]`
     *
     * @filtre
     * @link https://www.spip.net/5618
     * @see extraire_balise()
     * @note
     *     Attention : les résultats peuvent être incohérents sur des balises imbricables,
     *     tel que demander à extraire `div` dans un texte.
     *
     * @param string|array $texte
     *     Texte(s) dont on souhaite extraire une balise html
     * @param string $tag
     *     Nom de la balise html à extraire
     * @return array
     *     - Liste des codes html des occurrences de la balise, sinon tableau vide
     *     - Tableau de résultats, si tableau en entrée.
     **/
    protected function extraire_balises($texte, $tag = 'a')
    {
        if (is_array($texte)) {
            array_walk(
                $texte,
                function (&$a, $key, $t): void {
                    $a = extraire_balises($a, $t);
                },
                $tag
            );

            return $texte;
        }

        if (preg_match_all(
            ",<${tag}\b[^>]*(/>|>.*</${tag}\b[^>]*>|>),UimsS",
        $texte, $regs, PREG_PATTERN_ORDER)) {
            return $regs[0];
        } else {
            return [];
        }
    }

    /**
     * Indique si le premier argument est contenu dans le second
     *
     * Cette fonction est proche de `in_array()` en PHP avec comme principale
     * différence qu'elle ne crée pas d'erreur si le second argument n'est pas
     * un tableau (dans ce cas elle tentera de le désérialiser, et sinon retournera
     * la valeur par défaut transmise).
     *
     * @example `[(#VAL{deux}|in_any{#LISTE{un,deux,trois}}|oui) ... ]`
     *
     * @filtre
     * @see filtre_find() Assez proche, avec les arguments valeur et tableau inversés.
     *
     * @param string $val
     *     Valeur à chercher dans le tableau
     * @param array|string $vals
     *     Tableau des valeurs. S'il ce n'est pas un tableau qui est transmis,
     *     la fonction tente de la désérialiser.
     * @param string $def
     *     Valeur par défaut retournée si `$vals` n'est pas un tableau.
     * @return string
     *     - ' ' si la valeur cherchée est dans le tableau
     *     - '' si la valeur n'est pas dans le tableau
     *     - `$def` si on n'a pas transmis de tableau
     **/
    protected function in_any($val, $vals, $def = '')
    {
        if (!is_array($vals) and $v = unserialize($vals)) {
            $vals = $v;
        }

        return (!is_array($vals) ? $def : (in_array($val, $vals) ? ' ' : ''));
    }

    /**
     * Retourne le résultat d'une expression mathématique simple
     *
     * N'accepte que les *, + et - (à ameliorer si on l'utilise vraiment).
     *
     * @filtre
     * @example
     *      ```
     *      valeur_numerique("3*2") retourne 6
     *      ```
     *
     * @param string $expr
     *     Expression mathématique `nombre operateur nombre` comme `3*2`
     * @return int
     *     Résultat du calcul
     **/
    protected function valeur_numerique($expr)
    {
        $a = 0;
        if (preg_match(',^[0-9]+(\s*[+*-]\s*[0-9]+)*$,S', trim($expr))) {
            eval("\$a = $expr;");
        }

        return intval($a);
    }

    /**
     * Retourne un calcul de règle de trois
     *
     * @filtre
     * @example
     *     ```
     *     [(#VAL{6}|regledetrois{4,3})] retourne 8
     *     ```
     *
     * @param int $a
     * @param int $b
     * @param int $c
     * @return int
     *      Retourne `$a*$b/$c`
     **/
    protected function regledetrois($a, $b, $c)
    {
        return round($a * $b / $c);
    }

    /**
     * Crée des tags HTML input hidden pour chaque paramètre et valeur d'une URL
     *
     * Fournit la suite de Input-Hidden correspondant aux paramètres de
     * l'URL donnée en argument, compatible avec les types_urls
     *
     * @filtre
     * @link https://www.spip.net/4286
     * @see balise_ACTION_FORMULAIRE()
     *     Également pour transmettre les actions à un formulaire
     * @example
     *     ```
     *     [(#ENV{action}|form_hidden)] dans un formulaire
     *     ```
     *
     * @param string $action URL
     * @return string Suite de champs input hidden
     **/
    protected function form_hidden($action)
    {
        $contexte = [];
        include_spip('inc/urls');
        if ($p = urls_decoder_url($action, '')
            and reset($p)
            ) {
            $fond = array_shift($p);
            if ($fond != '404') {
                $contexte = array_shift($p);
                $contexte['page'] = $fond;
                $action = preg_replace('/([?]' . preg_quote($fond) . '[^&=]*[0-9]+)(&|$)/', '?&', $action);
            }
        }
        // defaire ce qu'a injecte urls_decoder_url : a revoir en modifiant la signature de urls_decoder_url
        if (defined('_DEFINIR_CONTEXTE_TYPE') and _DEFINIR_CONTEXTE_TYPE) {
            unset($contexte['type']);
        }
        if (defined('_DEFINIR_CONTEXTE_TYPE_PAGE') and _DEFINIR_CONTEXTE_TYPE_PAGE) {
            unset($contexte['type-page']);
        }

        // on va remplir un tableau de valeurs en prenant bien soin de ne pas
        // ecraser les elements de la forme mots[]=1&mots[]=2
        $values = [];

        // d'abord avec celles de l'url
        if (false !== ($p = strpos($action, '?'))) {
            foreach (preg_split('/&(amp;)?/S', substr($action, $p + 1)) as $c) {
                $c = explode('=', $c, 2);
                $var = array_shift($c);
                $val = array_shift($c);
                if ($var) {
                    $val = rawurldecode($val);
                    $var = rawurldecode($var); // decoder les [] eventuels
                    if (preg_match(',\[\]$,S', $var)) {
                        $values[] = [$var, $val];
                    } else {
                        if (!isset($values[$var])) {
                            $values[$var] = [$var, $val];
                        }
                    }
                }
            }
        }

        // ensuite avec celles du contexte, sans doublonner !
        foreach ($contexte as $var => $val) {
            if (preg_match(',\[\]$,S', $var)) {
                $values[] = [$var, $val];
            } else {
                if (!isset($values[$var])) {
                    $values[$var] = [$var, $val];
                }
            }
        }

        // puis on rassemble le tout
        $hidden = [];
        foreach ($values as $value) {
            list($var, $val) = $value;
            $hidden[] = '<input name="'
                    . entites_html($var)
                    . '"'
                        . (is_null($val)
                            ? ''
                            : ' value="' . entites_html($val) . '"'
                            )
                            . ' type="hidden"' . "\n/>";
        }

        return join("", $hidden);
    }

    /**
     * Calcule les bornes d'une pagination
     *
     * @filtre
     *
     * @param int $courante
     *     Page courante
     * @param int $nombre
     *     Nombre de pages
     * @param int $max
     *     Nombre d'éléments par page
     * @return int[]
     *     Liste (première page, dernière page).
     **/
    protected function filtre_bornes_pagination_dist($courante, $nombre, $max = 10)
    {
        if ($max <= 0 or $max >= $nombre) {
            return [1, $nombre];
        }

        $premiere = max(1, $courante - floor(($max - 1) / 2));
        $derniere = min($nombre, $premiere + $max - 2);
        $premiere = $derniere == $nombre ? $derniere - $max + 1 : $premiere;

        return [$premiere, $derniere];
    }

    /**
     * Retourne la première valeur d'un tableau
     *
     * Plus précisément déplace le pointeur du tableau sur la première valeur et la retourne.
     *
     * @example `[(#LISTE{un,deux,trois}|reset)]` retourne 'un'
     *
     * @filtre
     * @link http://php.net/manual/fr/function.reset.php
     * @see filtre_end()
     *
     * @param array $array
     * @return mixed|null|false
     *    - null si $array n'est pas un tableau,
     *    - false si le tableau est vide
     *    - la première valeur du tableau sinon.
     **/
    protected function filtre_reset($array)
    {
        return !is_array($array) ? null : reset($array);
    }

    /**
     * Retourne la dernière valeur d'un tableau
     *
     * Plus précisément déplace le pointeur du tableau sur la dernière valeur et la retourne.
     *
     * @example `[(#LISTE{un,deux,trois}|end)]` retourne 'trois'
     *
     * @filtre
     * @link http://php.net/manual/fr/function.end.php
     * @see filtre_reset()
     *
     * @param array $array
     * @return mixed|null|false
     *    - null si $array n'est pas un tableau,
     *    - false si le tableau est vide
     *    - la dernière valeur du tableau sinon.
     **/
    protected function filtre_end($array)
    {
        return !is_array($array) ? null : end($array);
    }

    /**
     * Empile une valeur à la fin d'un tableau
     *
     * @example `[(#LISTE{un,deux,trois}|push{quatre}|print)]`
     *
     * @filtre
     * @link https://www.spip.net/4571
     * @link http://php.net/manual/fr/function.array-push.php
     *
     * @param array $array
     * @param mixed $val
     * @return array|string
     *     - '' si $array n'est pas un tableau ou si echec.
     *     - le tableau complété de la valeur sinon.
     *
     **/
    protected function filtre_push($array, $val)
    {
        if (!is_array($array) or !array_push($array, $val)) {
            return '';
        }

        return $array;
    }

    /**
     * Indique si une valeur est contenue dans un tableau
     *
     * @example `[(#LISTE{un,deux,trois}|find{quatre}|oui) ... ]`
     *
     * @filtre
     * @link https://www.spip.net/4575
     * @see in_any() Assez proche, avec les paramètres tableau et valeur inversés.
     *
     * @param array $array
     * @param mixed $val
     * @return bool
     *     - `false` si `$array` n'est pas un tableau
     *     - `true` si la valeur existe dans le tableau, `false` sinon.
     **/
    protected function filtre_find($array, $val)
    {
        return (is_array($array) and in_array($val, $array));
    }

    /**
     * Filtre calculant une pagination, utilisé par la balise `#PAGINATION`
     *
     * Le filtre cherche le modèle `pagination.html` par défaut, mais peut
     * chercher un modèle de pagination particulier avec l'argument `$modele`.
     * S'il `$modele='prive'`, le filtre cherchera le modèle `pagination_prive.html`.
     *
     * @filtre
     * @see balise_PAGINATION_dist()
     *
     * @param int $total
     *     Nombre total d'éléments
     * @param string $nom
     *     Nom identifiant la pagination
     * @param int $position
     *     Page à afficher (tel que la 3è page)
     * @param int $pas
     *     Nombre d'éléments par page
     * @param bool $liste
     *     - True pour afficher toute la liste des éléments,
     *     - False pour n'afficher que l'ancre
     * @param string $modele
     *     Nom spécifique du modèle de pagination
     * @param string $connect
     *     Nom du connecteur à la base de données
     * @param array $env
     *     Environnement à transmettre au modèle
     * @return string
     *     Code HTML de la pagination
     **/
    protected function filtre_pagination_dist(
        $total,
        $nom,
        $position,
        $pas,
        $liste = true,
        $modele = '',
        $connect = '',
        $env = []
        ) {
        static $ancres = [];
        if ($pas < 1) {
            return '';
        }
        $ancre = 'pagination' . $nom; // #pagination_articles
            $debut = 'debut' . $nom; // 'debut_articles'

            // n'afficher l'ancre qu'une fois
        if (!isset($ancres[$ancre])) {
            $bloc_ancre = $ancres[$ancre] = "<a name='" . $ancre . "' id='" . $ancre . "'></a>";
        } else {
            $bloc_ancre = '';
        }
        // liste = false : on ne veut que l'ancre
        if (!$liste) {
            return $ancres[$ancre];
        }

        $self = (empty($env['self']) ? self() : $env['self']);
        $pagination = [
                'debut' => $debut,
                'url' => parametre_url($self, 'fragment', ''), // nettoyer l'id ahah eventuel
                'total' => $total,
                'position' => intval($position),
                'pas' => $pas,
                'nombre_pages' => floor(($total - 1) / $pas) + 1,
                'page_courante' => floor(intval($position) / $pas) + 1,
                'ancre' => $ancre,
                'bloc_ancre' => $bloc_ancre,
            ];
        if (is_array($env)) {
            $pagination = array_merge($env, $pagination);
        }

        // Pas de pagination
        if ($pagination['nombre_pages'] <= 1) {
            return '';
        }

        if ($modele) {
            $modele = '_' . $modele;
        }

        return recuperer_fond("modeles/pagination$modele", $pagination, ['trim' => true], $connect);
    }

    /**
     * Passer les url relatives à la css d'origine en url absolues
     *
     * @uses suivre_lien()
     *
     * @param string $contenu
     *     Contenu du fichier CSS
     * @param string $source
     *     Chemin du fichier CSS
     * @return string
     *     Contenu avec urls en absolus
     **/
    protected function urls_absolues_css($contenu, $source)
    {
        $path = suivre_lien(url_absolue($source), './');

        return preg_replace_callback(
            ",url\s*\(\s*['\"]?([^'\"/#\s][^:]*)['\"]?\s*\),Uims",
            function ($x) use ($path) {
                return "url('" . suivre_lien($path, $x[1]) . "')";
            },
            $contenu
            );
    }

    /**
     * Inverse le code CSS (left <--> right) d'une feuille de style CSS
     *
     * Récupère le chemin d'une CSS existante et :
     *
     * 1. regarde si une CSS inversée droite-gauche existe dans le meme répertoire
     * 2. sinon la crée (ou la recrée) dans `_DIR_VAR/cache_css/`
     *
     * Si on lui donne à manger une feuille nommée `*_rtl.css` il va faire l'inverse.
     *
     * @filtre
     * @example
     *     ```
     *     [<link rel="stylesheet" href="(#CHEMIN{css/perso.css}|direction_css)" type="text/css" />]
     *     ```
     * @param string $css
     *     Chemin vers le fichier CSS
     * @param string $voulue
     *     Permet de forcer le sens voulu (en indiquant `ltr`, `rtl` ou un
     *     code de langue). En absence, prend le sens de la langue en cours.
     *
     * @return string
     *     Chemin du fichier CSS inversé
     **/
    protected function direction_css($css, $voulue = '')
    {
        if (!preg_match(',(_rtl)?\.css$,i', $css, $r)) {
            return $css;
        }

        // si on a precise le sens voulu en argument, le prendre en compte
        if ($voulue = strtolower($voulue)) {
            if ($voulue != 'rtl' and $voulue != 'ltr') {
                $voulue = lang_dir($voulue);
            }
        } else {
            $voulue = lang_dir();
        }

        $r = count($r) > 1;
        $right = $r ? 'left' : 'right'; // 'right' de la css lue en entree
        $dir = $r ? 'rtl' : 'ltr';
        $ndir = $r ? 'ltr' : 'rtl';

        if ($voulue == $dir) {
            return $css;
        }

        if (
            // url absolue
            preg_match(",^https?:,i", $css)
            // ou qui contient un ?
            or (($p = strpos($css, '?')) !== false)
        ) {
            $distant = true;
            $cssf = parse_url($css);
            $cssf = $cssf['path'] . ($cssf['query'] ? "?" . $cssf['query'] : "");
            $cssf = preg_replace(',[?:&=],', "_", $cssf);
        } else {
            $distant = false;
            $cssf = $css;
            // 1. regarder d'abord si un fichier avec la bonne direction n'est pas aussi
            //propose (rien a faire dans ce cas)
            $f = preg_replace(',(_rtl)?\.css$,i', '_' . $ndir . '.css', $css);
            if (@file_exists($f)) {
                return $f;
            }
        }

        // 2.
        $dir_var = sous_repertoire(_DIR_VAR, 'cache-css');
        $f = $dir_var
        . preg_replace(',.*/(.*?)(_rtl)?\.css,', '\1', $cssf)
        . '.' . substr(md5($cssf), 0, 4) . '_' . $ndir . '.css';

        // la css peut etre distante (url absolue !)
        if ($distant) {
            include_spip('inc/distant');
            $res = recuperer_url($css);
            if (!$res or !$contenu = $res['page']) {
                return $css;
            }
        } else {
            if ((@filemtime($f) > @filemtime($css))
                and (_VAR_MODE != 'recalcul')
            ) {
                return $f;
            }
            if (!lire_fichier($css, $contenu)) {
                return $css;
            }
        }

        // Inverser la direction gauche-droite en utilisant CSSTidy qui gere aussi les shorthands
        include_spip("lib/csstidy/class.csstidy");
        $parser = new csstidy();
        $parser->set_cfg('optimise_shorthands', 0);
        $parser->set_cfg('reverse_left_and_right', true);
        $parser->parse($contenu);

        $contenu = $parser->print->plain();

        // reperer les @import auxquels il faut propager le direction_css
        preg_match_all(",\@import\s*url\s*\(\s*['\"]?([^'\"/][^:]*)['\"]?\s*\),Uims", $contenu, $regs);
        $src = [];
        $src_direction_css = [];
        $src_faux_abs = [];
        $d = dirname($css);
        foreach ($regs[1] as $k => $import_css) {
            $css_direction = direction_css("$d/$import_css", $voulue);
            // si la css_direction est dans le meme path que la css d'origine, on tronque le path, elle sera passee en absolue
            if (substr($css_direction, 0, strlen($d) + 1) == "$d/") {
                $css_direction = substr($css_direction, strlen($d) + 1);
            } // si la css_direction commence par $dir_var on la fait passer pour une absolue
            elseif (substr($css_direction, 0, strlen($dir_var)) == $dir_var) {
                $css_direction = substr($css_direction, strlen($dir_var));
                $src_faux_abs["/@@@@@@/" . $css_direction] = $css_direction;
                $css_direction = "/@@@@@@/" . $css_direction;
            }
            $src[] = $regs[0][$k];
            $src_direction_css[] = str_replace($import_css, $css_direction, $regs[0][$k]);
        }
        $contenu = str_replace($src, $src_direction_css, $contenu);

        $contenu = urls_absolues_css($contenu, $css);

        // virer les fausses url absolues que l'on a mis dans les import
        if (count($src_faux_abs)) {
            $contenu = str_replace(array_keys($src_faux_abs), $src_faux_abs, $contenu);
        }

        if (!ecrire_fichier($f, $contenu)) {
            return $css;
        }

        return $f;
    }

    /**
     * Transforme les urls relatives d'un fichier CSS en absolues
     *
     * Récupère le chemin d'une css existante et crée (ou recrée) dans `_DIR_VAR/cache_css/`
     * une css dont les url relatives sont passées en url absolues
     *
     * Le calcul n'est pas refait si le fichier cache existe déjà et que
     * la source n'a pas été modifiée depuis.
     *
     * @uses recuperer_page() si l'URL source n'est pas sur le même site
     * @uses urls_absolues_css()
     *
     * @param string $css
     *     Chemin ou URL du fichier CSS source
     * @return string
     *     - Chemin du fichier CSS transformé (si source lisible et mise en cache réussie)
     *     - Chemin ou URL du fichier CSS source sinon.
     **/
    protected function url_absolue_css($css)
    {
        if (!preg_match(',\.css$,i', $css, $r)) {
            return $css;
        }

        $url_absolue_css = url_absolue($css);

        $f = basename($css, '.css');
        $f = sous_repertoire(_DIR_VAR, 'cache-css')
        . preg_replace(",(.*?)(_rtl|_ltr)?$,", "\\1-urlabs-" . substr(md5("$css-urlabs"), 0, 4) . "\\2", $f)
        . '.css';

        if ((@filemtime($f) > @filemtime($css)) and (_VAR_MODE != 'recalcul')) {
            return $f;
        }

        if ($url_absolue_css == $css) {
            if (strncmp($GLOBALS['meta']['adresse_site'], $css, $l = strlen($GLOBALS['meta']['adresse_site'])) != 0
                or !lire_fichier(_DIR_RACINE . substr($css, $l), $contenu)
            ) {
                include_spip('inc/distant');
                if (!$contenu = recuperer_page($css)) {
                    return $css;
                }
            }
        } elseif (!lire_fichier($css, $contenu)) {
            return $css;
        }

        // passer les url relatives a la css d'origine en url absolues
        $contenu = urls_absolues_css($contenu, $css);

        // ecrire la css
        if (!ecrire_fichier($f, $contenu)) {
            return $css;
        }

        return $f;
    }

    /**
     * Récupère la valeur d'une clé donnée
     * dans un tableau (ou un objet).
     *
     * @filtre
     * @link https://www.spip.net/4572
     * @example
     *     ```
     *     [(#VALEUR|table_valeur{cle/sous/element})]
     *     ```
     *
     * @param mixed $table
     *     Tableau ou objet PHP
     *     (ou chaîne serialisée de tableau, ce qui permet d'enchaîner le filtre)
     * @param string $cle
     *     Clé du tableau (ou paramètre public de l'objet)
     *     Cette clé peut contenir des caractères / pour sélectionner
     *     des sous éléments dans le tableau, tel que `sous/element/ici`
     *     pour obtenir la valeur de `$tableau['sous']['element']['ici']`
     * @param mixed $defaut
     *     Valeur par defaut retournée si la clé demandée n'existe pas
     * @param bool  $conserver_null
     *     Permet de forcer la fonction à renvoyer la valeur null d'un index
     *     et non pas $defaut comme cela est fait naturellement par la fonction
     *     isset. On utilise alors array_key_exists() à la place de isset().
     *
     * @return mixed
     *     Valeur trouvée ou valeur par défaut.
     **/
    protected function table_valeur($table, $cle, $defaut = '', $conserver_null = false)
    {
        foreach (explode('/', $cle) as $k) {
            $table = is_string($table) ? @unserialize($table) : $table;

            if (is_object($table)) {
                $table = (($k !== "") and isset($table->$k)) ? $table->$k : $defaut;
            } elseif (is_array($table)) {
                if ($conserver_null) {
                    $table = array_key_exists($k, $table) ? $table[$k] : $defaut;
                } else {
                    $table = $table[$k] ?? $defaut;
                }
            } else {
                $table = $defaut;
            }
        }

        return $table;
    }

    /**
     * Retrouve un motif dans un texte à partir d'une expression régulière
     *
     * S'appuie sur la fonction `preg_match()` en PHP
     *
     * @example
     *    - `[(#TITRE|match{toto})]`
     *    - `[(#TEXTE|match{^ceci$,Uims})]`
     *    - `[(#TEXTE|match{truc(...)$, UimsS, 1})]` Capture de la parenthèse indiquée
     *    - `[(#TEXTE|match{truc(...)$, 1})]` Équivalent, sans indiquer les modificateurs
     *
     * @filtre
     * @link https://www.spip.net/4299
     * @link http://php.net/manual/fr/function.preg-match.php Pour des infos sur `preg_match()`
     *
     * @param string $texte
     *     Texte dans lequel chercher
     * @param string|int $expression
     *     Expression régulière de recherche, sans le délimiteur
     * @param string $modif
     *     - string : Modificateurs de l'expression régulière
     *     - int : Numéro de parenthèse capturante
     * @param int $capte
     *     Numéro de parenthèse capturante
     * @return bool|string
     *     - false : l'expression n'a pas été trouvée
     *     - true : expression trouvée, mais pas la parenthèse capturante
     *     - string : expression trouvée.
     **/
    protected function filtre_match_dist($texte, $expression, $modif = "UimsS", $capte = 0)
    {
        if (intval($modif) and $capte == 0) {
            $capte = $modif;
            $modif = "UimsS";
        }
        $expression = str_replace("\/", "/", $expression);
        $expression = str_replace("/", "\/", $expression);

        if (preg_match('/' . $expression . '/' . $modif, $texte, $r)) {
            if (isset($r[$capte])) {
                return $r[$capte];
            } else {
                return true;
            }
        }

        return false;
    }

    /**
     * Remplacement de texte à base d'expression régulière
     *
     * @filtre
     * @link https://www.spip.net/4309
     * @see match()
     * @example
     *     ```
     *     [(#TEXTE|replace{^ceci$,cela,UimsS})]
     *     ```
     *
     * @param string $texte
     *     Texte
     * @param string $expression
     *     Expression régulière
     * @param string $replace
     *     Texte de substitution des éléments trouvés
     * @param string $modif
     *     Modificateurs pour l'expression régulière.
     * @return string
     *     Texte
     **/
    protected function replace($texte, $expression, $replace = '', $modif = "UimsS")
    {
        $expression = str_replace("\/", "/", $expression);
        $expression = str_replace("/", "\/", $expression);

        return preg_replace('/' . $expression . '/' . $modif, $replace, $texte);
    }

    /**
     * Cherche les documents numerotés dans un texte traite par `propre()`
     *
     * Affecte la liste des doublons['documents']
     *
     * @param array $doublons
     *     Liste des doublons
     * @param string $letexte
     *     Le texte
     * @return string
     *     Le texte
     **/
    protected function traiter_doublons_documents(&$doublons, $letexte)
    {

        // Verifier dans le texte & les notes (pas beau, helas)
        $t = $letexte . $GLOBALS['les_notes'];

        if (strstr($t, 'spip_document_') // evite le preg_match_all si inutile
            and preg_match_all(
                ',<[^>]+\sclass=["\']spip_document_([0-9]+)[\s"\'],imsS',
                $t, $matches, PREG_PATTERN_ORDER)
        ) {
            if (!isset($doublons['documents'])) {
                $doublons['documents'] = "";
            }
            $doublons['documents'] .= "," . join(',', $matches[1]);
        }

        return $letexte;
    }

    /**
     * Filtre vide qui ne renvoie rien
     *
     * @example
     *     `[(#CALCUL|vide)]` n'affichera pas le résultat du calcul
     * @filtre
     *
     * @param mixed $texte
     * @return string Chaîne vide
     **/
    protected function vide($texte)
    {
        return "";
    }

    //
    // Filtres pour le modele/emb (embed document)
    //

    /**
     * Écrit des balises HTML `<param...>` à partir d'un tableau de données tel que `#ENV`
     *
     * Permet d'écrire les balises `<param>` à indiquer dans un `<object>`
     * en prenant toutes les valeurs du tableau transmis.
     *
     * Certaines clés spécifiques à SPIP et aux modèles embed sont omises :
     * id, lang, id_document, date, date_redac, align, fond, recurs, emb, dir_racine
     *
     * @example `[(#ENV*|env_to_params)]`
     *
     * @filtre
     * @link https://www.spip.net/4005
     *
     * @param array|string $env
     *      Tableau cle => valeur des paramètres à écrire, ou chaine sérialisée de ce tableau
     * @param array $ignore_params
     *      Permet de compléter les clés ignorées du tableau.
     * @return string
     *      Code HTML résultant
     **/
    protected function env_to_params($env, $ignore_params = [])
    {
        $ignore_params = array_merge(
            ['id', 'lang', 'id_document', 'date', 'date_redac', 'align', 'fond', '', 'recurs', 'emb', 'dir_racine'],
            $ignore_params
            );
        if (!is_array($env)) {
            $env = unserialize($env);
        }
        $texte = "";
        if ($env) {
            foreach ($env as $i => $j) {
                if (is_string($j) and !in_array($i, $ignore_params)) {
                    $texte .= "<param name='" . $i . "'\n\tvalue='" . $j . "' />";
                }
            }
        }

        return $texte;
    }

    /**
     * Écrit des attributs HTML à partir d'un tableau de données tel que `#ENV`
     *
     * Permet d'écrire des attributs d'une balise HTML en utilisant les données du tableau transmis.
     * Chaque clé deviendra le nom de l'attribut (et la valeur, sa valeur)
     *
     * Certaines clés spécifiques à SPIP et aux modèles embed sont omises :
     * id, lang, id_document, date, date_redac, align, fond, recurs, emb, dir_racine
     *
     * @example `<embed src='#URL_DOCUMENT' [(#ENV*|env_to_attributs)] width='#GET{largeur}' height='#GET{hauteur}'></embed>`
     * @filtre
     *
     * @param array|string $env
     *      Tableau cle => valeur des attributs à écrire, ou chaine sérialisée de ce tableau
     * @param array $ignore_params
     *      Permet de compléter les clés ignorées du tableau.
     * @return string
     *      Code HTML résultant
     **/
    protected function env_to_attributs($env, $ignore_params = [])
    {
        $ignore_params = array_merge(
            ['id', 'lang', 'id_document', 'date', 'date_redac', 'align', 'fond', '', 'recurs', 'emb', 'dir_racine'],
            $ignore_params
            );
        if (!is_array($env)) {
            $env = unserialize($env);
        }
        $texte = "";
        if ($env) {
            foreach ($env as $i => $j) {
                if (is_string($j) and !in_array($i, $ignore_params)) {
                    $texte .= $i . "='" . $j . "' ";
                }
            }
        }

        return $texte;
    }

    /**
     * Concatène des chaînes
     *
     * @filtre
     * @link https://www.spip.net/4150
     * @example
     *     ```
     *     #TEXTE|concat{texte1,texte2,...}
     *     ```
     *
     * @return string Chaînes concaténés
     **/
    protected function concat()
    {
        $args = func_get_args();

        return join('', $args);
    }

    /**
     * Retourne le contenu d'un ou plusieurs fichiers
     *
     * Les chemins sont cherchés dans le path de SPIP
     *
     * @see balise_INCLURE_dist() La balise `#INCLURE` peut appeler cette fonction
     *
     * @param array|string $files
     *     - array : Liste de fichiers
     *     - string : fichier ou fichiers séparés par `|`
     * @param bool $script
     *     - si true, considère que c'est un fichier js à chercher `javascript/`
     * @return string
     *     Contenu du ou des fichiers, concaténé
     **/
    protected function charge_scripts($files, $script = true)
    {
        $flux = "";
        foreach (is_array($files) ? $files : explode("|", $files) as $file) {
            if (!is_string($file)) {
                continue;
            }
            if ($script) {
                $file = preg_match(",^\w+$,", $file) ? "javascript/$file.js" : '';
            }
            if ($file) {
                $path = find_in_path($file);
                if ($path) {
                    $flux .= spip_file_get_contents($path);
                }
            }
        }

        return $flux;
    }

    /**
     * Produit une balise img avec un champ alt d'office si vide
     *
     * Attention le htmlentities et la traduction doivent être appliqués avant.
     *
     * @param string $img
     * @param string $alt
     * @param string $atts
     * @param string $title
     * @param array $options
     *   chemin_image : utiliser chemin_image sur $img fourni, ou non (oui par dafaut)
     *   utiliser_suffixe_size : utiliser ou non le suffixe de taille dans le nom de fichier de l'image
     *   sous forme -xx.png (pour les icones essentiellement) (oui par defaut)
     *   variante_svg_si_possible: utiliser l'image -xx.svg au lieu de -32.png par exemple (si la variante svg est disponible)
     * @return string
     */
    protected function http_img_pack($img, $alt, $atts = '', $title = '', $options = [])
    {
        $img_file = $img;
        if ($p = strpos($img_file, '?')) {
            $img_file = substr($img_file, 0, $p);
        }
        if (!isset($options['chemin_image']) or $options['chemin_image'] == true) {
            $img_file = chemin_image($img);
        } else {
            if (!isset($options['variante_svg_si_possible']) or $options['variante_svg_si_possible'] == true) {
                // on peut fournir une icone generique -xx.svg qui fera le job dans toutes les tailles, et qui est prioritaire sur le png
                // si il y a un .svg a la bonne taille (-16.svg) a cote, on l'utilise en remplacement du -16.png
                if (preg_match(',-(\d+)[.](png|gif|svg)$,', $img_file, $m)
                    and $variante_svg_generique = substr($img_file, 0, -strlen($m[0])) . "-xx.svg"
                    and file_exists($variante_svg_generique)) {
                    if ($variante_svg_size = substr($variante_svg_generique, 0, -6) . $m[1] . ".svg" and file_exists($variante_svg_size)) {
                        $img_file = $variante_svg_size;
                    } else {
                        $img_file = $variante_svg_generique;
                    }
                }
            }
        }
        if (stripos($atts, 'width') === false) {
            // utiliser directement l'info de taille presente dans le nom
            if ((!isset($options['utiliser_suffixe_size'])
                or $options['utiliser_suffixe_size'] == true
                or strpos($img_file, '-xx.svg') !== false)
                and (preg_match(',-([0-9]+)[.](png|gif|svg)$,', $img, $regs)
                    or preg_match(',\?([0-9]+)px$,', $img, $regs))
            ) {
                $largeur = $hauteur = intval($regs[1]);
            } else {
                $taille = taille_image($img_file);
                list($hauteur, $largeur) = $taille;
                if (!$hauteur or !$largeur) {
                    return "";
                }
            }
            $atts .= " width='" . $largeur . "' height='" . $hauteur . "'";
        }

        if (file_exists($img_file)) {
            $img_file = timestamp($img_file);
        }
        return "<img src='$img_file' alt='" . attribut_html($alt ? $alt : $title) . "'"
            . ($title ? ' title="' . attribut_html($title) . '"' : '')
            . " " . ltrim($atts)
            . " />";
    }

    /**
     * Générer une directive `style='background:url()'` à partir d'un fichier image
     *
     * @param string $img
     * @param string $att
     * @param string $size
     * @return string
     */
    protected function http_style_background($img, $att = '', $size = null)
    {
        if ($size and is_numeric($size)) {
            $size = trim($size) . "px";
        }
        return " style='background" .
            ($att ? "" : "-image") . ": url(\"" . chemin_image($img) . "\")" . ($att ? (' ' . $att) : '') . ";"
                . ($size ? "background-size:{$size};" : '')
                . "'";
    }

    /**
     * Générer une balise HTML `img` à partir d'un nom de fichier
     *
     * @uses http_img_pack()
     *
     * @param string $img
     * @param string $alt
     * @param string $class
     * @param string $width
     * @return string
     *     Code HTML de la balise IMG
     */
    protected function filtre_balise_img_dist($img, $alt = "", $class = "", $width = null)
    {
        $atts = $class ? " class='" . attribut_html($class) . "'" : '';
        // ecriture courte : on donne le width en 2e arg
        if (empty($width) and is_numeric($alt)) {
            $width = $alt;
            $alt = '';
        }
        if ($width) {
            $atts .= " width='{$width}'";
        }
        return http_img_pack($img, $alt, $atts, '',
            ['chemin_image' => false, 'utiliser_suffixe_size' => false]);
    }

    /**
     * Inserer un svg inline
     * http://www.accede-web.com/notices/html-css-javascript/6-images-icones/6-2-svg-images-vectorielles/
     *
     * pour l'inserer avec une balise <img>, utiliser le filtre |balise_img
     *
     * @param string $img
     * @param string $alt
     * @param string $class
     * @return string
     */
    protected function filtre_balise_svg_dist($img, $alt = "", $class = "")
    {
        $img_file = $img;
        if ($p = strpos($img_file, '?')) {
            $img_file = substr($img_file, 0, $p);
        }

        if (!$img_file or !$svg = file_get_contents($img_file)) {
            return '';
        }

        if (!preg_match(",<svg\b[^>]*>,UimsS", $svg, $match)) {
            return '';
        }
        $balise_svg = $match[0];
        $balise_svg_source = $balise_svg;

        // entete XML à supprimer
        $svg = preg_replace(',^\s*<\?xml[^>]*\?' . '>,', '', $svg);

        // IE est toujours mon ami
        $balise_svg = inserer_attribut($balise_svg, 'focusable', 'false');
        if ($class) {
            $balise_svg = inserer_attribut($balise_svg, 'class', $class);
        }
        if ($alt) {
            $balise_svg = inserer_attribut($balise_svg, 'role', 'img');
            $id = "img-svg-title-" . substr(md5("$img_file:$svg:$alt"), 0, 4);
            $balise_svg = inserer_attribut($balise_svg, 'aria-labelledby', $id);
            $title = "<title id=\"$id\">" . entites_html($alt) . "</title>\n";
            $balise_svg .= $title;
        } else {
            $balise_svg = inserer_attribut($balise_svg, 'aria-hidden', 'true');
        }
        $svg = str_replace($balise_svg_source, $balise_svg, $svg);

        return $svg;
    }

    /**
     * Affiche chaque valeur d'un tableau associatif en utilisant un modèle
     *
     * @example
     *     - `[(#ENV*|unserialize|foreach)]`
     *     - `[(#ARRAY{a,un,b,deux}|foreach)]`
     *
     * @filtre
     * @link https://www.spip.net/4248
     *
     * @param array $tableau
     *     Tableau de données à afficher
     * @param string $modele
     *     Nom du modèle à utiliser
     * @return string
     *     Code HTML résultant
     **/
    protected function filtre_foreach_dist($tableau, $modele = 'foreach')
    {
        $texte = '';
        if (is_array($tableau)) {
            foreach ($tableau as $k => $v) {
                $res = recuperer_fond('modeles/' . $modele,
                    array_merge(['cle' => $k], (is_array($v) ? $v : ['valeur' => $v]))
                );
                $texte .= $res;
            }
        }

        return $texte;
    }

    /**
     * Obtient des informations sur les plugins actifs
     *
     * @filtre
     * @uses liste_plugin_actifs() pour connaître les informations affichables
     *
     * @param string $plugin
     *     Préfixe du plugin ou chaîne vide
     * @param string $type_info
     *     Type d'info demandée
     * @param bool $reload
     *     true (à éviter) pour forcer le recalcul du cache des informations des plugins.
     * @return array|string|bool
     *
     *     - Liste sérialisée des préfixes de plugins actifs (si $plugin = '')
     *     - Suivant $type_info, avec $plugin un préfixe
     *         - est_actif : renvoie true s'il est actif, false sinon
     *         - x : retourne l'information x du plugin si présente (et plugin actif)
     *         - tout : retourne toutes les informations du plugin actif
     **/
    protected function filtre_info_plugin_dist($plugin, $type_info, $reload = false)
    {
        include_spip('inc/plugin');
        $plugin = strtoupper($plugin);
        $plugins_actifs = liste_plugin_actifs();

        if (!$plugin) {
            return serialize(array_keys($plugins_actifs));
        } elseif (empty($plugins_actifs[$plugin]) and !$reload) {
            return '';
        } elseif (($type_info == 'est_actif') and !$reload) {
            return $plugins_actifs[$plugin] ? 1 : 0;
        } elseif (isset($plugins_actifs[$plugin][$type_info]) and !$reload) {
            return $plugins_actifs[$plugin][$type_info];
        } else {
            $get_infos = charger_fonction('get_infos', 'plugins');
            // On prend en compte les extensions
            if (!is_dir($plugins_actifs[$plugin]['dir_type'])) {
                $dir_plugins = constant($plugins_actifs[$plugin]['dir_type']);
            } else {
                $dir_plugins = $plugins_actifs[$plugin]['dir_type'];
            }
            if (!$infos = $get_infos($plugins_actifs[$plugin]['dir'], $reload, $dir_plugins)) {
                return '';
            }
            if ($type_info == 'tout') {
                return $infos;
            } elseif ($type_info == 'est_actif') {
                return $infos ? 1 : 0;
            } else {
                return strval($infos[$type_info]);
            }
        }
    }

    /**
     * Affiche la puce statut d'un objet, avec un menu rapide pour changer
     * de statut si possibilité de l'avoir
     *
     * @see inc_puce_statut_dist()
     *
     * @filtre
     *
     * @param int $id_objet
     *     Identifiant de l'objet
     * @param string $statut
     *     Statut actuel de l'objet
     * @param int $id_rubrique
     *     Identifiant du parent
     * @param string $type
     *     Type d'objet
     * @param bool $ajax
     *     Indique s'il ne faut renvoyer que le coeur du menu car on est
     *     dans une requete ajax suite à un post de changement rapide
     * @return string
     *     Code HTML de l'image de puce de statut à insérer (et du menu de changement si présent)
     */
    protected function puce_changement_statut($id_objet, $statut, $id_rubrique, $type, $ajax = false)
    {
        $puce_statut = charger_fonction('puce_statut', 'inc');

        return $puce_statut($id_objet, $statut, $id_rubrique, $type, $ajax);
    }

    /**
     * Affiche la puce statut d'un objet, avec un menu rapide pour changer
     * de statut si possibilité de l'avoir
     *
     * Utilisable sur tout objet qui a declaré ses statuts
     *
     * @example
     *     [(#STATUT|puce_statut{article})] affiche une puce passive
     *     [(#STATUT|puce_statut{article,#ID_ARTICLE,#ID_RUBRIQUE})] affiche une puce avec changement rapide
     *
     * @see inc_puce_statut_dist()
     *
     * @filtre
     *
     * @param string $statut
     *     Statut actuel de l'objet
     * @param string $objet
     *     Type d'objet
     * @param int $id_objet
     *     Identifiant de l'objet
     * @param int $id_parent
     *     Identifiant du parent
     * @return string
     *     Code HTML de l'image de puce de statut à insérer (et du menu de changement si présent)
     */
    protected function filtre_puce_statut_dist($statut, $objet, $id_objet = 0, $id_parent = 0)
    {
        static $puce_statut = null;
        if (!$puce_statut) {
            $puce_statut = charger_fonction('puce_statut', 'inc');
        }

        return $puce_statut($id_objet, $statut, $id_parent, $objet, false,
            objet_info($objet, 'editable') ? _ACTIVER_PUCE_RAPIDE : false);
    }

    /**
     * Encoder un contexte pour l'ajax
     *
     * Encoder le contexte, le signer avec une clé, le crypter
     * avec le secret du site, le gziper si possible.
     *
     * L'entrée peut-être sérialisée (le `#ENV**` des fonds ajax et ajax_stat)
     *
     * @see  decoder_contexte_ajax()
     * @uses calculer_cle_action()
     *
     * @param string|array $c
     *   contexte, peut etre un tableau serialize
     * @param string $form
     *   nom du formulaire eventuel
     * @param string $emboite
     *   contenu a emboiter dans le conteneur ajax
     * @param string $ajaxid
     *   ajaxid pour cibler le bloc et forcer sa mise a jour
     * @return string
     *   hash du contexte
     */
    protected function encoder_contexte_ajax($c, $form = '', $emboite = null, $ajaxid = '')
    {
        if (is_string($c)
            and @unserialize($c) !== false
        ) {
            $c = unserialize($c);
        }

        // supprimer les parametres debut_x
        // pour que la pagination ajax ne soit pas plantee
        // si on charge la page &debut_x=1 : car alors en cliquant sur l'item 0,
        // le debut_x=0 n'existe pas, et on resterait sur 1
        if (is_array($c)) {
            foreach ($c as $k => $v) {
                if (strpos($k, 'debut_') === 0) {
                    unset($c[$k]);
                }
            }
        }

        if (!function_exists('calculer_cle_action')) {
            include_spip("inc/securiser_action");
        }

        $c = serialize($c);
        $cle = calculer_cle_action($form . $c);
        $c = "$cle:$c";

        // on ne stocke pas les contextes dans des fichiers caches
        // par defaut, sauf si cette configuration a ete forcee
        // OU que la longueur de l''argument generee est plus long
        // que ce que telere Suhosin.
        $cache_contextes_ajax = (defined('_CACHE_CONTEXTES_AJAX') and _CACHE_CONTEXTES_AJAX);
        if (!$cache_contextes_ajax) {
            $env = $c;
            if (function_exists('gzdeflate') && function_exists('gzinflate')) {
                $env = gzdeflate($env);
                // https://core.spip.net/issues/2667 | https://bugs.php.net/bug.php?id=61287
                if ((PHP_VERSION_ID == 50400) and !@gzinflate($env)) {
                    $cache_contextes_ajax = true;
                    spip_log("Contextes AJAX forces en fichiers ! Erreur PHP 5.4.0", _LOG_AVERTISSEMENT);
                }
            }
            $env = _xor($env);
            $env = base64_encode($env);
            // tester Suhosin et la valeur maximale des variables en GET...
            if ($max_len = @ini_get('suhosin.get.max_value_length')
                and $max_len < ($len = strlen($env))
                ) {
                $cache_contextes_ajax = true;
                spip_log("Contextes AJAX forces en fichiers !"
                    . " Cela arrive lorsque la valeur du contexte"
                    . " depasse la longueur maximale autorisee par Suhosin"
                    . " ($max_len) dans 'suhosin.get.max_value_length'. Ici : $len."
                    . " Vous devriez modifier les parametres de Suhosin"
                    . " pour accepter au moins 1024 caracteres.", _LOG_AVERTISSEMENT);
            }
        }

        if ($cache_contextes_ajax) {
            $dir = sous_repertoire(_DIR_CACHE, 'contextes');
            // stocker les contextes sur disque et ne passer qu'un hash dans l'url
            $md5 = md5($c);
            ecrire_fichier("$dir/c$md5", $c);
            $env = $md5;
        }

        if ($emboite === null) {
            return $env;
        }
        if (!trim($emboite)) {
            return "";
        }
        // toujours encoder l'url source dans le bloc ajax
        $r = self();
        $r = ' data-origin="' . $r . '"';
        $class = 'ajaxbloc';
        if ($ajaxid and is_string($ajaxid)) {
            // ajaxid est normalement conforme a un nom de classe css
            // on ne verifie pas la conformite, mais on passe entites_html par dessus par precaution
            $class .= ' ajax-id-' . entites_html($ajaxid);
        }

        return "<div class='$class' " . "data-ajax-env='$env'$r>\n$emboite</div><!--ajaxbloc-->\n";
    }

    /**
     * Décoder un hash de contexte pour l'ajax
     *
     * Précude inverse de `encoder_contexte_ajax()`
     *
     * @see  encoder_contexte_ajax()
     * @uses calculer_cle_action()
     *
     * @param string $c
     *   hash du contexte
     * @param string $form
     *   nom du formulaire eventuel
     * @return array|string|bool
     *   - array|string : contexte d'environnement, possiblement sérialisé
     *   - false : erreur de décodage
     */
    protected function decoder_contexte_ajax($c, $form = '')
    {
        if (!function_exists('calculer_cle_action')) {
            include_spip("inc/securiser_action");
        }
        if (((defined('_CACHE_CONTEXTES_AJAX') and _CACHE_CONTEXTES_AJAX) or strlen($c) == 32)
            and $dir = sous_repertoire(_DIR_CACHE, 'contextes')
            and lire_fichier("$dir/c$c", $contexte)
        ) {
            $c = $contexte;
        } else {
            $c = @base64_decode($c);
            $c = _xor($c);
            if (function_exists('gzdeflate') && function_exists('gzinflate')) {
                $c = @gzinflate($c);
            }
        }

        // extraire la signature en debut de contexte
        // et la verifier avant de deserializer
        // format : signature:donneesserializees
        if ($p = strpos($c, ":")) {
            $cle = substr($c, 0, $p);
            $c = substr($c, $p + 1);

            if ($cle == calculer_cle_action($form . $c)) {
                $env = @unserialize($c);
                return $env;
            }
        }

        return false;
    }

    /**
     * Encrypte ou décrypte un message
     *
     * @link http://www.php.net/manual/fr/language.operators.bitwise.php#81358
     *
     * @param string $message
     *    Message à encrypter ou décrypter
     * @param null|string $key
     *    Clé de cryptage / décryptage.
     *    Une clé sera calculée si non transmise
     * @return string
     *    Message décrypté ou encrypté
     **/
    protected function _xor($message, $key = null)
    {
        if (is_null($key)) {
            if (!function_exists('calculer_cle_action')) {
                include_spip("inc/securiser_action");
            }
            $key = pack("H*", calculer_cle_action('_xor'));
        }

        $keylen = strlen($key);
        $messagelen = strlen($message);
        for ($i = 0; $i < $messagelen; $i++) {
            $message[$i] = ~($message[$i] ^ $key[$i % $keylen]);
        }

        return $message;
    }

    /**
     * Retourne une URL de réponse de forum (aucune action ici)
     *
     * @see filtre_url_reponse_forum() du plugin forum (prioritaire)
     * @note
     *   La vraie fonction est dans le plugin forum,
     *   mais on évite ici une erreur du compilateur en absence du plugin
     * @param string $texte
     * @return string
     */
    protected function url_reponse_forum($texte)
    {
        return $texte;
    }

    /**
     * retourne une URL de suivi rss d'un forum (aucune action ici)
     *
     * @see filtre_url_rss_forum() du plugin forum (prioritaire)
     * @note
     *   La vraie fonction est dans le plugin forum,
     *   mais on évite ici une erreur du compilateur en absence du plugin
     * @param string $texte
     * @return string
     */
    protected function url_rss_forum($texte)
    {
        return $texte;
    }

    /**
     * Génère des menus avec liens ou `<strong class='on'>` non clicable lorsque
     * l'item est sélectionné
     *
     * @filtre
     * @link https://www.spip.net/4004
     * @example
     *   ```
     *   [(#URL_RUBRIQUE|lien_ou_expose{#TITRE, #ENV{test}|=={en_cours}})]
     *   ```
     *
     * @param string $url
     *   URL du lien
     * @param string $libelle
     *   Texte du lien
     * @param bool $on
     *   État exposé (génère un strong) ou non (génère un lien)
     * @param string $class
     *   Classes CSS ajoutées au lien
     * @param string $title
     *   Title ajouté au lien
     * @param string $rel
     *   Attribut `rel` ajouté au lien
     * @param string $evt
     *   Complement à la balise `a` pour gérer un événement javascript,
     *   de la forme ` onclick='...'`
     * @return string
     *   Code HTML
     */
    protected function lien_ou_expose($url, $libelle = null, $on = false, $class = "", $title = "", $rel = "", $evt = '')
    {
        if ($on) {
            $bal = "strong";
            $att = "class='on'";
        } else {
            $bal = 'a';
            $att = "href='$url'"
            . ($title ? " title='" . attribut_html($title) . "'" : '')
            . ($class ? " class='" . attribut_html($class) . "'" : '')
            . ($rel ? " rel='" . attribut_html($rel) . "'" : '')
            . $evt;
        }
        if ($libelle === null) {
            $libelle = $url;
        }

        return "<$bal $att>$libelle</$bal>";
    }

    /**
     * Afficher un message "un truc"/"N trucs"
     * Les items sont à indiquer comme pour la fonction _T() sous la forme :
     * "module:chaine"
     *
     * @param int $nb : le nombre
     * @param string $chaine_un : l'item de langue si $nb vaut un
     * @param string $chaine_plusieurs : l'item de lanque si $nb >= 2
     * @param string $var : La variable à remplacer par $nb dans l'item de langue (facultatif, défaut "nb")
     * @param array $vars : Les autres variables nécessaires aux chaines de langues (facultatif)
     * @return string : la chaine de langue finale en utilisant la fonction _T()
     */
    protected function singulier_ou_pluriel($nb, $chaine_un, $chaine_plusieurs, $var = 'nb', $vars = [])
    {
        if (!is_numeric($nb) or $nb == 0) {
            return "";
        }
        if (!is_array($vars)) {
            return "";
        }
        $vars[$var] = $nb;
        if ($nb >= 2) {
            return _T($chaine_plusieurs, $vars);
        } else {
            return _T($chaine_un, $vars);
        }
    }

    /**
     * Fonction de base pour une icone dans un squelette
     * structure html : `<span><a><img><b>texte</b></span>`
     *
     * @param string $type
     *  'lien' ou 'bouton'
     * @param string $lien
     *  url
     * @param string $texte
     *  texte du lien / alt de l'image
     * @param string $fond
     *  objet avec ou sans son extension et sa taille (article, article-24, article-24.png)
     * @param string $fonction
     *  new/del/edit
     * @param string $class
     *  classe supplementaire (horizontale, verticale, ajax ...)
     * @param string $javascript
     *  "onclick='...'" par exemple
     * @return string
     */
    protected function prepare_icone_base($type, $lien, $texte, $fond, $fonction = "", $class = "", $javascript = "")
    {
        if (in_array($fonction, ["del", "supprimer.gif"])) {
            $class .= ' danger';
        } elseif ($fonction == "rien.gif") {
            $fonction = "";
        } elseif ($fonction == "delsafe") {
            $fonction = "del";
        }

        $fond_origine = $fond;
        // remappage des icone : article-24.png+new => article-new-24.png
        if ($icone_renommer = charger_fonction('icone_renommer', 'inc', true)) {
            list($fond, $fonction) = $icone_renommer($fond, $fonction);
        }

        // ajouter le type d'objet dans la class de l'icone
        $class .= " " . substr(basename($fond), 0, -4);

        $alt = attribut_html($texte);
        $title = " title=\"$alt\""; // est-ce pertinent de doubler le alt par un title ?

        $ajax = "";
        if (strpos($class, "ajax") !== false) {
            $ajax = "ajax";
            if (strpos($class, "preload") !== false) {
                $ajax .= " preload";
            }
            if (strpos($class, "nocache") !== false) {
                $ajax .= " nocache";
            }
            $ajax = " class='$ajax'";
        }

        $size = 24;
        if (preg_match("/-([0-9]{1,3})[.](gif|png|svg)$/i", $fond, $match)
            or preg_match("/-([0-9]{1,3})([.](gif|png|svg))?$/i", $fond_origine, $match)) {
            $size = $match[1];
        }

        $icone = http_img_pack($fond, $alt, "width='$size' height='$size'");
        $icone = "<span class=\"icone-image" . ($fonction ? " icone-fonction icone-fonction-$fonction" : "") . "\">$icone</span>";

        if ($type == 'lien') {
            return "<span class='icone s$size $class'>"
            . "<a href='$lien'$title$ajax$javascript>"
            . $icone
            . "<b>$texte</b>"
            . "</a></span>\n";
        } else {
            return bouton_action("$icone<b>$texte</b>", $lien, "icone s$size $class", $javascript, $alt);
        }
    }

    /**
     * Crée un lien ayant une icone
     *
     * @uses prepare_icone_base()
     *
     * @param string $lien
     *     URL du lien
     * @param string $texte
     *     Texte du lien
     * @param string $fond
     *     Objet avec ou sans son extension et sa taille (article, article-24, article-24.png)
     * @param string $fonction
     *     Fonction du lien (`edit`, `new`, `del`)
     * @param string $class
     *     Classe CSS, tel que `left`, `right` pour définir un alignement
     * @param string $javascript
     *     Javascript ajouté sur le lien
     * @return string
     *     Code HTML du lien
     **/
    protected function icone_base($lien, $texte, $fond, $fonction = "", $class = "", $javascript = "")
    {
        return prepare_icone_base('lien', $lien, $texte, $fond, $fonction, $class, $javascript);
    }

    /**
     * Crée un lien précédé d'une icone au dessus du texte
     *
     * @uses icone_base()
     * @see  icone_verticale() Pour un usage dans un code PHP.
     *
     * @filtre
     * @example
     *     ```
     *     [(#AUTORISER{voir,groupemots,#ID_GROUPE})
     *         [(#URL_ECRIRE{groupe_mots,id_groupe=#ID_GROUPE}
     *            |icone_verticale{<:mots:icone_voir_groupe_mots:>,groupe_mots-24.png,'',left})]
     *    ]
     *     ```
     *
     * @param string $lien
     *     URL du lien
     * @param string $texte
     *     Texte du lien
     * @param string $fond
     *     Objet avec ou sans son extension et sa taille (article, article-24, article-24.png)
     * @param string $fonction
     *     Fonction du lien (`edit`, `new`, `del`)
     * @param string $class
     *     Classe CSS à ajouter, tel que `left`, `right`, `center` pour définir un alignement.
     *     Il peut y en avoir plusieurs : `left ajax`
     * @param string $javascript
     *     Javascript ajouté sur le lien
     * @return string
     *     Code HTML du lien
     **/
    protected function filtre_icone_verticale_dist($lien, $texte, $fond, $fonction = "", $class = "", $javascript = "")
    {
        return icone_base($lien, $texte, $fond, $fonction, "verticale $class", $javascript);
    }

    /**
     * Crée un lien précédé d'une icone horizontale
     *
     * @uses icone_base()
     * @see  icone_horizontale() Pour un usage dans un code PHP.
     *
     * @filtre
     * @example
     *     En tant que filtre dans un squelettes :
     *     ```
     *     [(#URL_ECRIRE{sites}|icone_horizontale{<:sites:icone_voir_sites_references:>,site-24.png})]
     *
     *     [(#AUTORISER{supprimer,groupemots,#ID_GROUPE}|oui)
     *         [(#URL_ACTION_AUTEUR{supprimer_groupe_mots,#ID_GROUPE,#URL_ECRIRE{mots}}
     *             |icone_horizontale{<:mots:icone_supprimer_groupe_mots:>,groupe_mots,del})]
     *     ]
     *     ```
     *
     *     En tant que filtre dans un code php :
     *     ```
     *     $icone_horizontale=chercher_filtre('icone_horizontale');
     *     $icone = $icone_horizontale(generer_url_ecrire("stats_visites","id_article=$id_article"),
     *         _T('statistiques:icone_evolution_visites', array('visites' => $visites)),
     *         "statistique-24.png");
     *     ```
     *
     * @param string $lien
     *     URL du lien
     * @param string $texte
     *     Texte du lien
     * @param string $fond
     *     Objet avec ou sans son extension et sa taille (article, article-24, article-24.png)
     * @param string $fonction
     *     Fonction du lien (`edit`, `new`, `del`)
     * @param string $class
     *     Classe CSS à ajouter
     * @param string $javascript
     *     Javascript ajouté sur le lien
     * @return string
     *     Code HTML du lien
     **/
    protected function filtre_icone_horizontale_dist($lien, $texte, $fond, $fonction = "", $class = "", $javascript = "")
    {
        return icone_base($lien, $texte, $fond, $fonction, "horizontale $class", $javascript);
    }

    /**
     * Crée un bouton d'action intégrant une icone horizontale
     *
     * @uses prepare_icone_base()
     *
     * @filtre
     * @example
     *     ```
     *     [(#URL_ACTION_AUTEUR{supprimer_mot, #ID_MOT, #URL_ECRIRE{groupe_mots,id_groupe=#ID_GROUPE}}
     *         |bouton_action_horizontal{<:mots:info_supprimer_mot:>,mot-24.png,del})]
     *     ```
     *
     * @param string $lien
     *     URL de l'action
     * @param string $texte
     *     Texte du bouton
     * @param string $fond
     *     Objet avec ou sans son extension et sa taille (article, article-24, article-24.png)
     * @param string $fonction
     *     Fonction du bouton (`edit`, `new`, `del`)
     * @param string $class
     *     Classe CSS à ajouter
     * @param string $confirm
     *     Message de confirmation à ajouter en javascript sur le bouton
     * @return string
     *     Code HTML du lien
     **/
    protected function filtre_bouton_action_horizontal_dist($lien, $texte, $fond, $fonction = "", $class = "", $confirm = "")
    {
        return prepare_icone_base('bouton', $lien, $texte, $fond, $fonction, "horizontale $class", $confirm);
    }

    /**
     * Filtre `icone` pour compatibilité mappé sur `icone_base`
     *
     * @uses icone_base()
     * @see  filtre_icone_verticale_dist()
     *
     * @filtre
     * @deprecated Utiliser le filtre `icone_verticale`
     *
     * @param string $lien
     *     URL du lien
     * @param string $texte
     *     Texte du lien
     * @param string $fond
     *     Nom de l'image utilisée
     * @param string $align
     *     Classe CSS d'alignement (`left`, `right`, `center`)
     * @param string $fonction
     *     Fonction du lien (`edit`, `new`, `del`)
     * @param string $class
     *     Classe CSS à ajouter
     * @param string $javascript
     *     Javascript ajouté sur le lien
     * @return string
     *     Code HTML du lien
     */
    protected function filtre_icone_dist($lien, $texte, $fond, $align = "", $fonction = "", $class = "", $javascript = "")
    {
        return icone_base($lien, $texte, $fond, $fonction, "verticale $align $class", $javascript);
    }

    /**
     * Explose un texte en tableau suivant un séparateur
     *
     * @note
     *     Inverse l'écriture de la fonction PHP de même nom
     *     pour que le filtre soit plus pratique dans les squelettes
     *
     * @filtre
     * @example
     *     ```
     *     [(#GET{truc}|explode{-})]
     *     ```
     *
     * @param string $a Texte
     * @param string $b Séparateur
     * @return array Liste des éléments
     */
    protected function filtre_explode_dist($a, $b)
    {
        return explode($b, $a);
    }

    /**
     * Implose un tableau en chaine en liant avec un séparateur
     *
     * @note
     *     Inverse l'écriture de la fonction PHP de même nom
     *     pour que le filtre soit plus pratique dans les squelettes
     *
     * @filtre
     * @example
     *     ```
     *     [(#GET{truc}|implode{-})]
     *     ```
     *
     * @param array $a Tableau
     * @param string $b Séparateur
     * @return string Texte
     */
    protected function filtre_implode_dist($a, $b)
    {
        return is_array($a) ? implode($b, $a) : $a;
    }

    /**
     * Produire les styles privés qui associent item de menu avec icone en background
     *
     * @return string Code CSS
     */
    protected function bando_images_background()
    {
        include_spip('inc/bandeau');
        // recuperer tous les boutons et leurs images
        $boutons = definir_barre_boutons(definir_barre_contexte(), true, false);

        $res = "";
        foreach ($boutons as $page => $detail) {
            if ($detail->icone and strlen(trim($detail->icone))) {
                $res .= "\n.navigation_avec_icones #bando1_$page {background-image:url(" . $detail->icone . ");}";
            }
            $selecteur = (in_array($page, ['outils_rapides', 'outils_collaboratifs']) ? "" : ".navigation_avec_icones ");
            if (is_array($detail->sousmenu)) {
                foreach ($detail->sousmenu as $souspage => $sousdetail) {
                    if ($sousdetail->icone and strlen(trim($sousdetail->icone))) {
                        $res .= "\n$selecteur.bando2_$souspage {background-image:url(" . $sousdetail->icone . ");}";
                    }
                }
            }
        }

        return $res;
    }

    /**
     * Generer un bouton_action
     * utilise par #BOUTON_ACTION
     *
     * @param string $libelle
     * @param string $url
     * @param string $class
     * @param string $confirm
     *   message de confirmation oui/non avant l'action
     * @param string $title
     * @param string $callback
     *   callback js a appeler lors de l'evenement action (apres confirmation eventuelle si $confirm est non vide)
     *   et avant execution de l'action. Si la callback renvoie false, elle annule le declenchement de l'action
     * @return string
     */
    protected function bouton_action($libelle, $url, $class = "", $confirm = "", $title = "", $callback = "")
    {
        if ($confirm) {
            $confirm = "confirm(\"" . attribut_html($confirm) . "\")";
            if ($callback) {
                $callback = "$confirm?($callback):false";
            } else {
                $callback = $confirm;
            }
        }
        $onclick = $callback ? " onclick='return " . addcslashes($callback, "'") . "'" : "";
        $title = $title ? " title='$title'" : "";

        return "<form class='bouton_action_post $class' method='post' action='$url'><div>" . form_hidden($url)
        . "<button type='submit' class='submit'$title$onclick>$libelle</button></div></form>";
    }

    /**
     * Donner n'importe quelle information sur un objet de maniere generique.
     *
     * La fonction va gerer en interne deux cas particuliers les plus utilises :
     * l'URL et le titre (qui n'est pas forcemment le champ SQL "titre").
     *
     * On peut ensuite personnaliser les autres infos en creant une fonction
     * generer_<nom_info>_entite($id_objet, $type_objet, $ligne).
     * $ligne correspond a la ligne SQL de tous les champs de l'objet, les fonctions
     * de personnalisation n'ont donc pas a refaire de requete.
     *
     * @param int $id_objet
     * @param string $type_objet
     * @param string $info
     * @param string $etoile
     * @return string
     */
    protected function generer_info_entite($id_objet, $type_objet, $info, $etoile = "")
    {
        static $trouver_table = null;
        static $objets;

        // On verifie qu'on a tout ce qu'il faut
        $id_objet = intval($id_objet);
        if (!($id_objet and $type_objet and $info)) {
            return '';
        }

        // si on a deja note que l'objet n'existe pas, ne pas aller plus loin
        if (isset($objets[$type_objet]) and $objets[$type_objet] === false) {
            return '';
        }

        // Si on demande l'url, on retourne direct la fonction
        if ($info == 'url') {
            return generer_url_entite($id_objet, $type_objet);
        }

        // Sinon on va tout chercher dans la table et on garde en memoire
        $demande_titre = ($info == 'titre');

        // On ne fait la requete que si on a pas deja l'objet ou si on demande le titre mais qu'on ne l'a pas encore
        if (!isset($objets[$type_objet][$id_objet])
            or
            ($demande_titre and !isset($objets[$type_objet][$id_objet]['titre']))
        ) {
            if (!$trouver_table) {
                $trouver_table = charger_fonction('trouver_table', 'base');
            }
            $desc = $trouver_table(table_objet_sql($type_objet));
            if (!$desc) {
                return $objets[$type_objet] = false;
            }

            // Si on demande le titre, on le gere en interne
            $champ_titre = "";
            if ($demande_titre) {
                // si pas de titre declare mais champ titre, il sera peuple par le select *
                $champ_titre = (!empty($desc['titre'])) ? ', ' . $desc['titre'] : '';
            }
            include_spip('base/abstract_sql');
            include_spip('base/connect_sql');
            $objets[$type_objet][$id_objet] = sql_fetsel(
                '*' . $champ_titre,
                $desc['table_sql'],
                id_table_objet($type_objet) . ' = ' . intval($id_objet)
            );
        }

        // Si la fonction generer_TRUC_TYPE existe, on l'utilise pour formater $info_generee
        if ($generer = charger_fonction("generer_${info}_${type_objet}", '', true)) {
            $info_generee = $generer($id_objet, $objets[$type_objet][$id_objet]);
        } // Si la fonction generer_TRUC_entite existe, on l'utilise pour formater $info_generee
        else {
            if ($generer = charger_fonction("generer_${info}_entite", '', true)) {
                $info_generee = $generer($id_objet, $type_objet, $objets[$type_objet][$id_objet]);
            } // Sinon on prend directement le champ SQL tel quel
            else {
                $info_generee = ($objets[$type_objet][$id_objet][$info] ?? '');
            }
        }

        // On va ensuite appliquer les traitements automatiques si besoin
        if (!$etoile) {
            // FIXME: on fournit un ENV minimum avec id et type et connect=''
            // mais ce fonctionnement est a ameliorer !
            $info_generee = appliquer_traitement_champ($info_generee, $info, table_objet($type_objet),
                ['id_objet' => $id_objet, 'objet' => $type_objet, '']);
        }

        return $info_generee;
    }

    /**
     * Appliquer a un champ SQL le traitement qui est configure pour la balise homonyme dans les squelettes
     *
     * @param string $texte
     * @param string $champ
     * @param string $table_objet
     * @param array $env
     * @param string $connect
     * @return string
     */
    protected function appliquer_traitement_champ($texte, $champ, $table_objet = '', $env = [], $connect = '')
    {
        if (!$champ) {
            return $texte;
        }

        // On charge toujours les filtres de texte car la majorité des traitements les utilisent
        // et il ne faut pas partir du principe que c'est déjà chargé (form ajax, etc)
        include_spip('inc/texte');

        $champ = strtoupper($champ);
        $traitements = $GLOBALS['table_des_traitements'][$champ] ?? false;
        if (!$traitements or !is_array($traitements)) {
            return $texte;
        }

        $traitement = '';
        if ($table_objet and (!isset($traitements[0]) or count($traitements) > 1)) {
            // necessaire pour prendre en charge les vieux appels avec un table_objet_sql en 3e arg
            $table_objet = table_objet($table_objet);
            if (isset($traitements[$table_objet])) {
                $traitement = $traitements[$table_objet];
            }
        }
        if (!$traitement and isset($traitements[0])) {
            $traitement = $traitements[0];
        }
        // (sinon prendre le premier de la liste par defaut ?)

        if (!$traitement) {
            return $texte;
        }

        $traitement = str_replace('%s', "'" . texte_script($texte) . "'", $traitement);

        // Fournir $connect et $Pile[0] au traitement si besoin
        $Pile = [0 => $env];
        eval("\$texte = $traitement;");

        return $texte;
    }

    /**
     * Generer un lien (titre clicable vers url) vers un objet
     *
     * @param int $id_objet
     * @param $objet
     * @param int $longueur
     * @param null|string $connect
     * @return string
     */
    protected function generer_lien_entite($id_objet, $objet, $longueur = 80, $connect = null)
    {
        include_spip('inc/liens');
        $titre = traiter_raccourci_titre($id_objet, $objet, $connect);
        // lorsque l'objet n'est plus declare (plugin desactive par exemple)
        // le raccourcis n'est plus valide
        $titre = isset($titre['titre']) ? typo($titre['titre']) : '';
        // on essaye avec generer_info_entite ?
        if (!strlen($titre) and !$connect) {
            $titre = generer_info_entite($id_objet, $objet, 'titre');
        }
        if (!strlen($titre)) {
            $titre = _T('info_sans_titre');
        }
        $url = generer_url_entite($id_objet, $objet, '', '', $connect);

        return "<a href='$url' class='$objet'>" . couper($titre, $longueur) . "</a>";
    }

    /**
     * Englobe (Wrap) un texte avec des balises
     *
     * @example `wrap('mot','<b>')` donne `<b>mot</b>'`
     *
     * @filtre
     * @uses extraire_balises()
     *
     * @param string $texte
     * @param string $wrap
     * @return string
     */
    protected function wrap($texte, $wrap)
    {
        $balises = extraire_balises($wrap);
        if (preg_match_all(",<([a-z]\w*)\b[^>]*>,UimsS", $wrap, $regs, PREG_PATTERN_ORDER)) {
            $texte = $wrap . $texte;
            $regs = array_reverse($regs[1]);
            $wrap = "</" . implode("></", $regs) . ">";
            $texte = $texte . $wrap;
        }

        return $texte;
    }

    /**
     * afficher proprement n'importe quoi
     * On affiche in fine un pseudo-yaml qui premet de lire humainement les tableaux et de s'y reperer
     *
     * Les textes sont retournes avec simplement mise en forme typo
     *
     * le $join sert a separer les items d'un tableau, c'est en general un \n ou <br /> selon si on fait du html ou du texte
     * les tableaux-listes (qui n'ont que des cles numeriques), sont affiches sous forme de liste separee par des virgules :
     * c'est VOULU !
     *
     * @param $u
     * @param string $join
     * @param int $indent
     * @return array|mixed|string
     */
    protected function filtre_print_dist($u, $join = "<br />", $indent = 0)
    {
        if (is_string($u)) {
            $u = typo($u);

            return $u;
        }

        // caster $u en array si besoin
        if (is_object($u)) {
            $u = (array) $u;
        }

        if (is_array($u)) {
            $out = "";
            // toutes les cles sont numeriques ?
            // et aucun enfant n'est un tableau
            // liste simple separee par des virgules
            $numeric_keys = array_map('is_numeric', array_keys($u));
            $array_values = array_map('is_array', $u);
            $object_values = array_map('is_object', $u);
            if (array_sum($numeric_keys) == count($numeric_keys)
                and !array_sum($array_values)
                and !array_sum($object_values)
            ) {
                return join(", ", array_map('filtre_print_dist', $u));
            }

            // sinon on passe a la ligne et on indente
            $i_str = str_pad("", $indent, "\u{a0}");
            foreach ($u as $k => $v) {
                $out .= $join . $i_str . "$k: " . filtre_print_dist($v, $join, $indent + 2);
            }

            return $out;
        }

        // on sait pas quoi faire...
        return $u;
    }

    /**
     * Renvoyer l'info d'un objet
     * telles que definies dans declarer_tables_objets_sql
     *
     * @param string $objet
     * @param string $info
     * @return string
     */
    protected function objet_info($objet, $info)
    {
        $table = table_objet_sql($objet);
        $infos = lister_tables_objets_sql($table);

        return ($infos[$info] ?? '');
    }

    /**
     * Filtre pour afficher 'Aucun truc' ou '1 truc' ou 'N trucs'
     * avec la bonne chaîne de langue en fonction de l'objet utilisé
     *
     * @param int $nb
     *     Nombre d'éléments
     * @param string $objet
     *     Objet
     * @return mixed|string
     *     Texte traduit du comptage, tel que '3 articles'
     */
    protected function objet_afficher_nb($nb, $objet)
    {
        if (!$nb) {
            return _T(objet_info($objet, 'info_aucun_objet'));
        } else {
            return _T(objet_info($objet, $nb == 1 ? 'info_1_objet' : 'info_nb_objets'), ['nb' => $nb]);
        }
    }

    /**
     * Filtre pour afficher l'img icone d'un objet
     *
     * @param string $objet
     * @param int $taille
     * @param string $class
     * @return string
     */
    protected function objet_icone($objet, $taille = 24, $class = '')
    {
        $icone = objet_info($objet, 'icone_objet') . "-" . $taille . ".png";
        $icone = chemin_image($icone);
        $balise_img = charger_filtre('balise_img');

        return $icone ? $balise_img($icone, _T(objet_info($objet, 'texte_objet')), $class, $taille) : '';
    }

    /**
     * Renvoyer une traduction d'une chaine de langue contextuelle à un objet si elle existe,
     * la traduction de la chaine generique
     *
     * Ex : [(#ENV{objet}|objet_label{trad_reference})]
     * va chercher si une chaine objet:trad_reference existe et renvoyer sa trad le cas echeant
     * sinon renvoie la trad de la chaine trad_reference
     * Si la chaine fournie contient un prefixe il est remplacé par celui de l'objet pour chercher la chaine contextuelle
     *
     * Les arguments $args et $options sont ceux de la fonction _T
     *
     * @param string $objet
     * @param string $chaine
     * @param array $args
     * @param array $options
     * @return string
     */
    protected function objet_T($objet, $chaine, $args = [], $options = [])
    {
        $chaine = explode(':', $chaine);
        if ($t = _T($objet . ':' . end($chaine), $args, array_merge($options, ['force' => false]))) {
            return $t;
        }
        $chaine = implode(':', $chaine);
        return _T($chaine, $args, $options);
    }

    /**
     * Fonction de secours pour inserer le head_css de facon conditionnelle
     *
     * Appelée en filtre sur le squelette qui contient #INSERT_HEAD,
     * elle vérifie l'absence éventuelle de #INSERT_HEAD_CSS et y suplée si besoin
     * pour assurer la compat avec les squelettes qui n'utilisent pas.
     *
     * @param string $flux Code HTML
     * @return string      Code HTML
     */
    protected function insert_head_css_conditionnel($flux)
    {
        if (strpos($flux, '<!-- insert_head_css -->') === false
            and $p = strpos($flux, '<!-- insert_head -->')
        ) {
            // plutot avant le premier js externe (jquery) pour etre non bloquant
            if ($p1 = stripos($flux, '<script src=') and $p1 < $p) {
                $p = $p1;
            }
            $flux = substr_replace($flux, pipeline('insert_head_css', '<!-- insert_head_css -->'), $p, 0);
        }

        return $flux;
    }

    /**
     * Produire un fichier statique à partir d'un squelette dynamique
     *
     * Permet ensuite à Apache de le servir en statique sans repasser
     * par spip.php à chaque hit sur le fichier.
     *
     * Si le format (css ou js) est passe dans `contexte['format']`, on l'utilise
     * sinon on regarde si le fond finit par .css ou .js, sinon on utilie "html"
     *
     * @uses urls_absolues_css()
     *
     * @param string $fond
     * @param array $contexte
     * @param array $options
     * @param string $connect
     * @return string
     */
    protected function produire_fond_statique($fond, $contexte = [], $options = [], $connect = '')
    {
        if (isset($contexte['format'])) {
            $extension = $contexte['format'];
            unset($contexte['format']);
        } else {
            $extension = "html";
            if (preg_match(',[.](css|js|json)$,', $fond, $m)) {
                $extension = $m[1];
            }
        }
        // recuperer le contenu produit par le squelette
        $options['raw'] = true;
        $cache = recuperer_fond($fond, $contexte, $options, $connect);

        // calculer le nom de la css
        $dir_var = sous_repertoire(_DIR_VAR, 'cache-' . $extension);
        $nom_safe = preg_replace(",\W,", '_', str_replace('.', '_', $fond));
        $contexte_implicite = calculer_contexte_implicite();

        // par defaut on hash selon les contextes qui sont a priori moins variables
        // mais on peut hasher selon le contenu a la demande, si plusieurs contextes produisent un meme contenu
        // reduit la variabilite du nom et donc le nombre de css concatenees possibles in fine
        if (isset($options['hash_on_content']) and $options['hash_on_content']) {
            $hash = md5($contexte_implicite['host'] . '::' . $cache);
        } else {
            unset($contexte_implicite['notes']); // pas pertinent pour signaler un changeemnt de contenu pour des css/js
            ksort($contexte);
            $hash = md5($fond . json_encode($contexte_implicite) . json_encode($contexte) . $connect);
        }
        $filename = $dir_var . $extension . "dyn-$nom_safe-" . substr($hash, 0, 8) . ".$extension";

        // mettre a jour le fichier si il n'existe pas
        // ou trop ancien
        // le dernier fichier produit est toujours suffixe par .last
        // et recopie sur le fichier cible uniquement si il change
        if (!file_exists($filename)
            or !file_exists($filename . ".last")
            or (isset($cache['lastmodified']) and $cache['lastmodified'] and filemtime($filename . ".last") < $cache['lastmodified'])
            or (defined('_VAR_MODE') and _VAR_MODE == 'recalcul')
        ) {
            $contenu = $cache['texte'];
            // passer les urls en absolu si c'est une css
            if ($extension == "css") {
                $contenu = urls_absolues_css($contenu,
                    test_espace_prive() ? generer_url_ecrire('accueil') : generer_url_public($fond));
            }

            $comment = '';
            // ne pas insérer de commentaire si c'est du json
            if ($extension != "json") {
                $comment = "/* #PRODUIRE{fond=$fond";
                foreach ($contexte as $k => $v) {
                    $comment .= ",$k=$v";
                }
                // pas de date dans le commentaire car sinon ca invalide le md5 et force la maj
                // mais on peut mettre un md5 du contenu, ce qui donne un aperu rapide si la feuille a change ou non
                $comment .= "}\n   md5:" . md5($contenu) . " */\n";
            }
            // et ecrire le fichier si il change
            ecrire_fichier_calcule_si_modifie($filename, $comment . $contenu, false, true);
        }

        return timestamp($filename);
    }

    /**
     * Ajouter un timestamp a une url de fichier
     * [(#CHEMIN{monfichier}|timestamp)]
     *
     * @param string $fichier
     *    Le chemin du fichier sur lequel on souhaite ajouter le timestamp
     * @return string
     *    $fichier auquel on a ajouté le timestamp
     */
    protected function timestamp($fichier)
    {
        if (!$fichier
            or !file_exists($fichier)
            or !$m = filemtime($fichier)
        ) {
            return $fichier;
        }

        return "$fichier?$m";
    }

    /**
     * Supprimer le timestamp d'une url
     *
     * @param string $url
     * @return string
     */
    protected function supprimer_timestamp($url)
    {
        if (strpos($url, "?") === false) {
            return $url;
        }

        return preg_replace(",\?[[:digit:]]+$,", "", $url);
    }

    /**
     * Nettoyer le titre d'un email
     *
     * Éviter une erreur lorsqu'on utilise `|nettoyer_titre_email` dans un squelette de mail
     *
     * @filtre
     * @uses nettoyer_titre_email()
     *
     * @param string $titre
     * @return string
     */
    protected function filtre_nettoyer_titre_email_dist($titre)
    {
        include_spip('inc/envoyer_mail');

        return nettoyer_titre_email($titre);
    }

    /**
     * Afficher le sélecteur de rubrique
     *
     * Il permet de placer un objet dans la hiérarchie des rubriques de SPIP
     *
     * @uses chercher_rubrique()
     *
     * @param string $titre
     * @param int $id_objet
     * @param int $id_parent
     * @param string $objet
     * @param int $id_secteur
     * @param bool $restreint
     * @param bool $actionable
     *   true : fournit le selecteur dans un form directement postable
     * @param bool $retour_sans_cadre
     * @return string
     */
    protected function filtre_chercher_rubrique_dist(
        $titre,
        $id_objet,
        $id_parent,
        $objet,
        $id_secteur,
        $restreint,
        $actionable = false,
        $retour_sans_cadre = false
    ) {
        include_spip('inc/filtres_ecrire');

        return chercher_rubrique($titre, $id_objet, $id_parent, $objet, $id_secteur, $restreint, $actionable,
            $retour_sans_cadre);
    }

    /**
     * Rediriger une page suivant une autorisation,
     * et ce, n'importe où dans un squelette, même dans les inclusions.
     *
     * En l'absence de redirection indiquée, la fonction redirige par défaut
     * sur une 403 dans l'espace privé et 404 dans l'espace public.
     *
     * @example
     *     ```
     *     [(#AUTORISER{non}|sinon_interdire_acces)]
     *     [(#AUTORISER{non}|sinon_interdire_acces{#URL_PAGE{login}, 401})]
     *     ```
     *
     * @filtre
     * @param bool $ok
     *     Indique si l'on doit rediriger ou pas
     * @param string $url
     *     Adresse eventuelle vers laquelle rediriger
     * @param int $statut
     *     Statut HTML avec lequel on redirigera
     * @param string $message
     *     message d'erreur
     * @return string|void
     *     Chaîne vide si l'accès est autorisé
     */
    protected function sinon_interdire_acces($ok = false, $url = '', $statut = 0, $message = null)
    {
        if ($ok) {
            return '';
        }

        // Vider tous les tampons
        $level = @ob_get_level();
        while ($level--) {
            @ob_end_clean();
        }

        include_spip('inc/headers');

        // S'il y a une URL, on redirige (si pas de statut, la fonction mettra 302 par défaut)
        if ($url) {
            redirige_par_entete($url, '', $statut);
        }

        // ecriture simplifiee avec message en 3eme argument (= statut 403)
        if (!is_numeric($statut) and is_null($message)) {
            $message = $statut;
            $statut = 0;
        }
        if (!$message) {
            $message = '';
        }
        $statut = intval($statut);

        // Si on est dans l'espace privé, on génère du 403 Forbidden par defaut ou du 404
        if (test_espace_prive()) {
            if (!$statut or !in_array($statut, [404, 403])) {
                $statut = 403;
            }
            http_status(403);
            $echec = charger_fonction('403', 'exec');
            $echec($message);
        } else {
            // Sinon dans l'espace public on redirige vers une 404 par défaut, car elle toujours présente normalement
            if (!$statut) {
                $statut = 404;
            }
            // Dans tous les cas on modifie l'entité avec ce qui est demandé
            http_status($statut);
            // Si le statut est une erreur et qu'il n'y a pas de redirection on va chercher le squelette du même nom
            if ($statut >= 400) {
                echo recuperer_fond("$statut", ['erreur' => $message]);
            }
        }

        exit;
    }

    /**
     * Assurer le fonctionnement de |compacte meme sans l'extension compresseur
     *
     * @param string $source
     * @param null|string $format
     * @return string
     */
    protected function filtre_compacte_dist($source, $format = null)
    {
        if (function_exists('compacte')) {
            return compacte($source, $format);
        }

        return $source;
    }

    /**
     * @link spip/ecrire/inc/filtres_mini.php
     */

    /**
     * Filtres d'URL et de liens
     *
     * @package SPIP\Core\Filtres\Liens
     **/

    if (!defined('_ECRIRE_INC_VERSION')) {
        return;
    }


    /**
     * Nettoyer une URL contenant des `../`
     *
     * Inspiré (de loin) par PEAR:NetURL:resolvePath
     *
     * @example
     *     ```
     *     resolve_path('/.././/truc/chose/machin/./.././.././hopla/..');
     *     ```
     *
     * @param string $url URL
     * @return string URL nettoyée
     **/
    protected function resolve_path($url)
    {
        list($url, $query) = array_pad(explode('?', $url, 2), 2, null);
        while (preg_match(',/\.?/,', $url, $regs)    # supprime // et /./
            or preg_match(',/[^/]*/\.\./,S', $url, $regs)  # supprime /toto/../
            or preg_match(',^/\.\./,S', $url, $regs) # supprime les /../ du haut
        ) {
            $url = str_replace($regs[0], '/', $url);
        }

        if ($query) {
            $url .= '?' . $query;
        }

        return '/' . preg_replace(',^/,S', '', $url);
    }

    /**
     * Suivre un lien depuis une URL donnée vers une nouvelle URL
     *
     * @uses resolve_path()
     * @example
     *     ```
     *     suivre_lien(
     *         'https://rezo.net/sous/dir/../ect/ory/fi.html..s#toto',
     *         'a/../../titi.coco.html/tata#titi');
     *     ```
     *
     * @param string $url URL de base
     * @param string $lien Lien ajouté à l'URL
     * @return string URL complète.
     **/
    protected function suivre_lien($url, $lien)
    {
        if (preg_match(',^(mailto|javascript|data|tel|callto|file|ftp):,iS', $lien)) {
            return $lien;
        }
        if (preg_match(';^((?:[a-z]{3,33}:)?//.*?)(/.*)?$;iS', $lien, $r)) {
            $r = array_pad($r, 3, null);

            return $r[1] . resolve_path($r[2]);
        }

        # L'url site spip est un lien absolu aussi
        if (isset($GLOBALS['meta']['adresse_site']) and $lien == $GLOBALS['meta']['adresse_site']) {
            return $lien;
        }

        # lien relatif, il faut verifier l'url de base
        # commencer par virer la chaine de get de l'url de base
        $dir = '/';
        $debut = '';
        if (preg_match(';^((?:[a-z]{3,7}:)?//[^/]+)(/.*?/?)?([^/#?]*)([?][^#]*)?(#.*)?$;S', $url, $regs)) {
            $debut = $regs[1];
            $dir = !strlen($regs[2]) ? '/' : $regs[2];
            $mot = $regs[3];
            $get = $regs[4] ?? '';
            $hash = $regs[5] ?? '';
        }
        switch (substr($lien, 0, 1)) {
            case '/':
                return $debut . resolve_path($lien);
            case '#':
                return $debut . resolve_path($dir . $mot . $get . $lien);
            case '':
                return $debut . resolve_path($dir . $mot . $get . $hash);
            default:
                return $debut . resolve_path($dir . $lien);
        }
    }

    /**
     * Transforme une URL relative en URL absolue
     *
     * S'applique sur une balise SPIP d'URL.
     *
     * @filtre
     * @link https://www.spip.net/4127
     * @uses suivre_lien()
     * @example
     *     ```
     *     [(#URL_ARTICLE|url_absolue)]
     *     [(#CHEMIN{css/theme.css}|url_absolue)]
     *     ```
     *
     * @param string $url URL
     * @param string $base URL de base de destination (par défaut ce sera l'URL de notre site)
     * @return string Texte ou URL (en absolus)
     **/
    protected function url_absolue($url, $base = '')
    {
        if (strlen($url = trim($url)) == 0) {
            return '';
        }
        if (!$base) {
            $base = url_de_base() . (_DIR_RACINE ? _DIR_RESTREINT_ABS : '');
        }

        return suivre_lien($base, $url);
    }

    /**
     * Supprimer le protocole d'une url absolue
     * pour le rendre implicite (URL commencant par "//")
     *
     * @param string $url_absolue
     * @return string
     */
    protected function protocole_implicite($url_absolue)
    {
        return preg_replace(';^[a-z]{3,7}://;i', '//', $url_absolue);
    }

    /**
     * Verifier qu'une url est absolue et que son protocole est bien parmi une liste autorisee
     * @param string $url_absolue
     * @param array $protocoles_autorises
     * @return bool
     */
    protected function protocole_verifier($url_absolue, $protocoles_autorises = ['http','https'])
    {
        if (preg_match(';^([a-z]{3,7})://;i', $url_absolue, $m)) {
            $protocole = $m[1];
            if (in_array($protocole, $protocoles_autorises)
                or in_array(strtolower($protocole), array_map('strtolower', $protocoles_autorises))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Transforme les URLs relatives en URLs absolues
     *
     * Ne s'applique qu'aux textes contenant des liens
     *
     * @filtre
     * @uses url_absolue()
     * @link https://www.spip.net/4126
     *
     * @param string $texte Texte
     * @param string $base URL de base de destination (par défaut ce sera l'URL de notre site)
     * @return string Texte avec des URLs absolues
     **/
    protected function liens_absolus($texte, $base = '')
    {
        if (preg_match_all(',(<(a|link|image|img|script)\s[^<>]*(href|src)=[^<>]*>),imsS', $texte, $liens, PREG_SET_ORDER)) {
            if (!function_exists('extraire_attribut')) {
                include_spip('inc/filtres');
            }
            foreach ($liens as $lien) {
                foreach (array('href', 'src') as $attr) {
                    $href = extraire_attribut($lien[0], $attr);
                    if (strlen($href) > 0) {
                        if (!preg_match(';^((?:[a-z]{3,7}:)?//);iS', $href)) {
                            $abs = url_absolue($href, $base);
                            if (rtrim($href, '/') !== rtrim($abs, '/') and !preg_match('/^#/', $href)) {
                                $texte_lien = inserer_attribut($lien[0], $attr, $abs);
                                $texte = str_replace($lien[0], $texte_lien, $texte);
                            }
                        }
                    }
                }
            }
        }

        return $texte;
    }

    /**
     * Transforme une URL ou des liens en URL ou liens absolus
     *
     * @filtre
     * @link https://www.spip.net/4128
     * @global mode_abs_url Pour connaître le mode (url ou texte)
     *
     * @param string $texte Texte ou URL
     * @param string $base URL de base de destination (par défaut ce sera l'URL de notre site)
     * @return string Texte ou URL (en absolus)
     **/
    protected function abs_url($texte, $base = '')
    {
        if ($GLOBALS['mode_abs_url'] == 'url') {
            return url_absolue($texte, $base);
        } else {
            return liens_absolus($texte, $base);
        }
    }

    /**
     * htmlspecialchars wrapper (PHP >= 5.4 compat issue)
     *
     * @param string $string
     * @param int $flags
     * @param string $encoding
     * @param bool $double_encode
     * @return string
     */
    protected function spip_htmlspecialchars($string, $flags = null, $encoding = 'UTF-8', $double_encode = true)
    {
        if (is_null($flags)) {
            $flags = ENT_COMPAT | ENT_HTML401;
        }

        return htmlspecialchars($string, $flags, $encoding, $double_encode);
    }

    /**
     * htmlentities wrapper (PHP >= 5.4 compat issue)
     *
     * @param string $string
     * @param int $flags
     * @param string $encoding
     * @param bool $double_encode
     * @return string
     */
    protected function spip_htmlentities($string, $flags = null, $encoding = 'UTF-8', $double_encode = true)
    {
        if (is_null($flags)) {
            $flags = ENT_COMPAT | ENT_HTML401;
        }

        return htmlentities($string, $flags, $encoding, $double_encode);
    }

    /**
     * @link spip/ecrire/inc/lang.php
     */

    /**
     * Gestion des langues et choix de langue
     *
     * @package SPIP\Core\Langue
     **/
    if (!defined('_ECRIRE_INC_VERSION')) {
        return;
    }

    /**
     * Changer la langue courante
     *
     * Définit la langue utilisée par la langue désignée
     * si elle fait partie des langues utilisables dans le site.
     *
     * Cette fonction définit les globales :
     * spip_lang, spip_lang_rtl, spip_lang_right, spip_lang_left
     *
     * @param string $lang
     *     La langue à utiliser
     * @return string|bool
     *     string : La langue qui a été utilisée si trouvée
     *     false : aucune langue ne correspondait à la demande
     **/
    protected function changer_langue($lang)
    {
        $liste_langues = ',' . @$GLOBALS['meta']['langues_proposees']
        . ',' . @$GLOBALS['meta']['langues_multilingue'] . ',';

        // Si la langue demandee n'existe pas, on essaie d'autres variantes
        // Exemple : 'pt-br' => 'pt_br' => 'pt'
        $lang = str_replace('-', '_', trim($lang));
        if (!$lang) {
            return false;
        }

        if (strpos($liste_langues, ",$lang,") !== false
            or ($lang = preg_replace(',_.*,', '', $lang)
                and strpos($liste_langues, ",$lang,") !== false)
        ) {
            $GLOBALS['spip_lang_rtl'] = lang_dir($lang, '', '_rtl');
            $GLOBALS['spip_lang_right'] = $GLOBALS['spip_lang_rtl'] ? 'left' : 'right';
            $GLOBALS['spip_lang_left'] = $GLOBALS['spip_lang_rtl'] ? 'right' : 'left';

            return $GLOBALS['spip_lang'] = $lang;
        } else {
            return false;
        }
    }

    //
    // Gestion des blocs multilingues
    // Selection dans un tableau dont les index sont des noms de langues
    // de la valeur associee a la langue en cours
    // si absente, retourne le premier
    // remarque : on pourrait aussi appeler un service de traduction externe
    // ou permettre de choisir une langue "plus proche",
    // par exemple le francais pour l'espagnol, l'anglais pour l'allemand, etc.

    protected function choisir_traduction($trads, $lang = '')
    {
        $k = approcher_langue($trads, $lang);

        return $k ? $trads[$k] : array_shift($trads);
    }

    // retourne son 2e argument si c'est un index du premier
    // ou un index approchant sinon et si possible,
    // la langue X etant consideree comme une approche de X_Y
    protected function approcher_langue($trads, $lang = '')
    {
        if (!$lang) {
            $lang = $GLOBALS['spip_lang'];
        }

        if (isset($trads[$lang])) {
            return $lang;
        } // cas des langues xx_yy
        else {
            $r = explode('_', $lang);
            if (isset($trads[$r[0]])) {
                return $r[0];
            }
        }

        return '';
    }

    /**
     * Traduit un code de langue (fr, en, etc...) vers le nom de la langue
     * en toute lettres dans cette langue (français, English, etc....).
     *
     * Si le spip ne connait pas le nom de la langue, il retourne le code
     *
     * @param string $lang
     *     Code de langue
     * @return string
     *     Nom de la langue, sinon son code.
     **/
    protected function traduire_nom_langue($lang)
    {
        include_spip('inc/lang_liste');
        include_spip('inc/charsets');

        return html2unicode($GLOBALS['codes_langues'][$lang] ?? $lang);
    }

    //
    // Filtres de langue
    //

    // Donne la direction d'ecriture a partir de la langue. Retourne 'gaucher' si
    // la langue est arabe, persan, kurde, dari, pachto, ourdou (langues ecrites en
    // alphabet arabe a priori), hebreu, yiddish (langues ecrites en alphabet
    // hebreu a priori), 'droitier' sinon.
    // C'est utilise par #LANG_DIR, #LANG_LEFT, #LANG_RIGHT.
    // https://code.spip.net/@lang_dir
    protected function lang_dir($lang = '', $droitier = 'ltr', $gaucher = 'rtl')
    {
        static $lang_rtl = ['ar', 'fa', 'ku', 'prs', 'ps', 'ur', 'he', 'heb', 'hbo', 'yi'];

        return in_array(($lang ? $lang : $GLOBALS['spip_lang']), $lang_rtl) ?
        $gaucher : $droitier;
    }

    // typo francaise ou anglaise ?
    // $lang_objet est fixee dans l'interface privee pour editer
    // un texte anglais en interface francaise (ou l'inverse) ;
    // sinon determiner la typo en fonction de la langue courante

    // https://code.spip.net/@lang_typo
    protected function lang_typo($lang = '')
    {
        if (!$lang) {
            $lang = $GLOBALS['lang_objet']
            ?? $GLOBALS['spip_lang'];
        }
        if ($lang == 'eo'
            or $lang == 'fr'
            or strncmp($lang, 'fr_', 3) == 0
            or $lang == 'cpf'
            ) {
            return 'fr';
        } else {
            return 'en';
        }
    }

    // gestion de la globale $lang_objet pour que les textes soient affiches
    // avec les memes typo et direction dans l'espace prive que dans le public
    // https://code.spip.net/@changer_typo
    protected function changer_typo($lang = ''): void
    {
        if ($lang) {
            $GLOBALS['lang_objet'] = $lang;
        } else {
            unset($GLOBALS['lang_objet']);
        }
    }

    //
    // Afficher un menu de selection de langue
    // - 'var_lang_ecrire' = langue interface privee,
    // pour var_lang' = langue de l'article, espace public, voir les squelettes
    // pour 'changer_lang' (langue de l'article, espace prive), c'est en Ajax
    //
    // https://code.spip.net/@menu_langues
    protected function menu_langues($nom_select, $default = '')
    {
        include_spip('inc/actions');

        $langues = liste_options_langues($nom_select);
        $ret = "";
        if (!count($langues)) {
            return '';
        }

        if (!$default) {
            $default = $GLOBALS['spip_lang'];
        }
        foreach ($langues as $l) {
            $selected = ($l == $default) ? ' selected=\'selected\'' : '';
            $ret .= "<option value='$l'$selected>[" . $l . "] " . traduire_nom_langue($l) . "</option>\n";
        }

        if (!test_espace_prive()) {
            $cible = self();
            $base = '';
        } else {
            $cible = self();
            $base = spip_connect() ? 'base' : '';
        }

        $change = ' onchange="this.parentNode.parentNode.submit()"';

        return generer_action_auteur('converser', $base, $cible,
            (select_langues($nom_select, $change, $ret)
                . "<noscript><div style='display:inline'><input type='submit' class='fondo' value='" . _T('bouton_changer') . "' /></div></noscript>"),
            " method='post'");
    }

    // https://code.spip.net/@select_langues
    protected function select_langues($nom_select, $change, $options, $label = "")
    {
        static $cpt = 0;
        $id = "menu_langues" . $cpt++;

        return
        "<label for='$id'>" . ($label ? $label : _T('info_langues')) . "</label> " .
        "<select name='$nom_select' id='$id' "
        . ((!test_espace_prive()) ?
            ("class='forml menu_langues'") :
            (($nom_select == 'var_lang_ecrire') ?
                ("class='lang_ecrire'") :
                "class='fondl'"))
                . $change
                . ">\n"
                    . $options
                    . "</select>";
    }

    /**
     * Lister les langues disponibles
     *
     * Retourne un tableau de langue utilisables, triées par code de langue,
     * mais pas le même tableau en fonction du paramètre $nom_select.
     *
     * @param string $nom_select
     *     Attribut name du select
     *     Selon son nom, retourne une liste différente :
     *
     *     - var_lang ou changer_lang :
     *         liste des langues sélectionnées dans la config multilinguisme
     *     - var_lang_ecrire :
     *         toutes les langues présentes en fichier de langue
     * @return array
     *     Liste des langues
     */
    protected function liste_options_langues($nom_select)
    {
        switch ($nom_select) {
            # #MENU_LANG
            case 'var_lang':
                # menu de changement de la langue d'un article
                # les langues selectionnees dans la configuration "multilinguisme"
            case 'changer_lang':
                $langues = explode(',', $GLOBALS['meta']['langues_multilingue']);
                break;
                # menu de l'interface (privee, installation et panneau de login)
                # les langues presentes sous forme de fichiers de langue
                # on force la relecture du repertoire des langues pour etre synchrone.
            case 'var_lang_ecrire':
            default:
                    $GLOBALS['meta']['langues_proposees'] = '';
                    init_langues();
                    $langues = explode(',', $GLOBALS['meta']['langues_proposees']);
                    break;

                    # dernier choix possible : toutes les langues = langues_proposees
                    # + langues_multilingues ; mais, ne sert pas
                    #			$langues = explode(',', $GLOBALS['all_langs']);
        }
        if (count($langues) <= 1) {
            return [];
        }
        sort($langues);

        return $langues;
    }

    /**
     * Redirige sur la bonne langue lorsque l'option forcer_lang est active
     *
     * Cette fonction est appelee depuis ecrire/public.php si on a installé
     * la variable de personnalisation $forcer_lang ; elle renvoie le brouteur
     * si necessaire vers l'URL xxxx?lang=ll
     *
     **/
    protected function verifier_lang_url(): void
    {

        // quelle langue est demandee ?
        $lang_demandee = (test_espace_prive() ? $GLOBALS['spip_lang'] : $GLOBALS['meta']['langue_site']);
        if (isset($_COOKIE['spip_lang_ecrire'])) {
            $lang_demandee = $_COOKIE['spip_lang_ecrire'];
        }
        if (!test_espace_prive() and isset($_COOKIE['spip_lang'])) {
            $lang_demandee = $_COOKIE['spip_lang'];
        }
        if (isset($_GET['lang'])) {
            $lang_demandee = $_GET['lang'];
        }

        // Renvoyer si besoin (et si la langue demandee existe)
        if ($GLOBALS['spip_lang'] != $lang_demandee
            and changer_langue($lang_demandee)
            and $lang_demandee != @$_GET['lang']
        ) {
            $destination = parametre_url(self(), 'lang', $lang_demandee, '&');
            // ici on a besoin des var_truc
            foreach ($_GET as $var => $val) {
                if (!strncmp('var_', $var, 4)) {
                    $destination = parametre_url($destination, $var, $val, '&');
                }
            }
            include_spip('inc/headers');
            redirige_par_entete($destination);
        }

        // Subtilite : si la langue demandee par cookie est la bonne
        // alors on fait comme si $lang etait passee dans l'URL
        // (pour criteres {lang}).
        $GLOBALS['lang'] = $_GET['lang'] = $GLOBALS['spip_lang'];
    }

    /**
     * Utilise la langue du site
     *
     * Change la langue en cours d'utilisation par la langue du site
     * si ce n'est pas déjà le cas.
     *
     * Note : Cette fonction initialise la globale spip_lang au chargement de inc/lang
     *
     * @return string
     *     La langue sélectionnée
     **/
    protected function utiliser_langue_site()
    {
        // s'il existe une langue du site (en gros tout le temps en théorie)
        if (isset($GLOBALS['meta']['langue_site'])
            // et si spip_langue est pas encore définie (ce que va faire changer_langue())
            // ou qu'elle n'est pas identique à la langue du site
            and (!isset($GLOBALS['spip_lang'])
                or $GLOBALS['spip_lang'] != $GLOBALS['meta']['langue_site'])
        ) {
            return changer_langue($GLOBALS['meta']['langue_site']);//@:install
        }
        // en theorie là, la globale est définie, sinon c'est un problème.
        if (!isset($GLOBALS['spip_lang'])) {
            spip_log("La globale spip_lang est indéfinie dans utiliser_langue_site() !", _LOG_ERREUR);
        }

        return $GLOBALS['spip_lang'];
    }

    /**
     * Initialise la langue pour un visiteur du site
     *
     * La langue est choisie dans cet ordre :
     * - Dans le cookie 'spip_lang' ou 'spip_lang_ecrire' s'il existe (selon l'espace public ou privé).
     * - Sinon dans la session du visiteur.
     * - Sinon dans une des langues définie en préférence du navigateur
     * - Sinon la langue du site
     *
     * @return string
     *     La langue utilisée
     **/
    protected function utiliser_langue_visiteur()
    {
        $l = (!test_espace_prive() ? 'spip_lang' : 'spip_lang_ecrire');
        if (isset($_COOKIE[$l])) {
            if (changer_langue($l = $_COOKIE[$l])) {
                return $l;
            }
        }

        if (isset($GLOBALS['visiteur_session']['lang'])) {
            if (changer_langue($l = $GLOBALS['visiteur_session']['lang'])) {
                return $l;
            }
        }

        if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            foreach (explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']) as $s) {
                if (preg_match('#^([a-z]{2,3})(-[a-z]{2,3})?(;q=[0-9.]+)?$#i', trim($s), $r)) {
                    if (changer_langue($l = strtolower($r[1]))) {
                        return $l;
                    }
                }
            }
        }

        return utiliser_langue_site();
    }

    /**
     * Verifier qu'une chaine suceptible d'etre un nom de langue a le bon format
     * @param string $chaine
     * @return int
     */
    protected function match_langue($chaine)
    {
        return preg_match('/^[a-z]{2,3}(_[a-z]{2,3}){0,2}$/', $chaine);
    }

    /**
     * Initialisation des listes de langues
     *
     * Initialise les métas :
     * - langues_proposees : liste des traductions disponibles
     * - langue_site       : langue par défaut du site
     *
     * Lorsque ces métas n'existent pas encore (c'est à dire à l'installation),
     * elles sont calculées en obtenant la liste des langues
     * dans les fichiers de lang
     *
     **/
    protected function init_langues(): void
    {

        // liste des langues dans les meta, sauf a l'install
        $all_langs = @$GLOBALS['meta']['langues_proposees'];

        $tout = [];
        if (!$all_langs) {
            // trouver tous les modules lang/spip_xx.php
            $modules = find_all_in_path("lang/", "/spip_([a-z_]+)\.php$");
            foreach ($modules as $name => $path) {
                if (preg_match(',^spip_([a-z_]+)\.php$,', $name, $regs)) {
                    if (match_langue($regs[1])) {
                        $tout[] = $regs[1];
                    }
                }
            }
            sort($tout);
            $tout = join(',', $tout);
            // Si les langues n'ont pas change, ne rien faire
            if ($tout != $all_langs) {
                $GLOBALS['meta']['langues_proposees'] = $tout;
                include_spip('inc/meta');
                ecrire_meta('langues_proposees', $tout);
            }
        }
        if (!isset($GLOBALS['meta']['langue_site'])) {
            // Initialisation : le francais si dispo, sinon la premiere langue trouvee
            $GLOBALS['meta']['langue_site'] = $tout =
            (!$all_langs or (strpos(',' . _LANGUE_PAR_DEFAUT . ',', ",$all_langs,") !== false))
                ? _LANGUE_PAR_DEFAUT : substr($all_langs, 0, strpos($all_langs, ','));
            ecrire_meta('langue_site', $tout);
        }
    }

    /**
     * Retourne une balise <html>
     *
     * Retourne une balise HTML contenant les attributs 'lang' et 'dir'
     * définis sur la langue en cours d'utilisation,
     * ainsi que des classes CSS de ces du nom de la langue et direction choisie.
     *
     * @return string
     *     Code html de la balise <html>
     **/
    protected function html_lang_attributes()
    {
        $lang = $GLOBALS['spip_lang'];
        $dir = ($GLOBALS['spip_lang_rtl'] ? 'rtl' : 'ltr');

        return "<html class='$dir $lang no-js' xmlns='http://www.w3.org/1999/xhtml' lang='$lang' dir='$dir'>\n";
    }

    /**
     * Calcul de la direction du texte et la mise en page selon la langue
     *
     * En hébreu le ? ne doit pas être inversé.
     *
     * @param string $spip_lang
     * @param string $spip_lang_rtl
     * @return string
     */
    protected function aide_lang_dir($spip_lang, $spip_lang_rtl)
    {
        return ($spip_lang <> 'he') ? $spip_lang_rtl : '';
    }

    // initialise les globales (liste des langue, langue du site, spip_lang...)
    init_langues();
    utiliser_langue_site();

    /**
     * @link spip/ecrire/inc/texte.php
     */

    /**
     * Gestion des textes et raccourcis SPIP
     *
     * @package SPIP\Core\Texte
     **/

    if (!defined('_ECRIRE_INC_VERSION')) {
        return;
    }

    include_spip('inc/texte_mini');
    include_spip('inc/lien');

    /*************************************************************************************************************************
     * Fonctions inutilisees en dehors de inc/texte
     *
     */

    /**
     * Raccourcis dépendant du sens de la langue
     *
     * @return array Tablea ('','')
     */
    protected function definir_raccourcis_alineas()
    {
        return ['', ''];
    }

    /**
     * Traitement des raccourcis de tableaux
     *
     * Ne fait rien ici. Voir plugin Textwheel.
     *
     * @param string $bloc
     * @return string
     */
    protected function traiter_tableau($bloc)
    {
        return $bloc;
    }

    /**
     * Traitement des listes
     *
     * Ne fais rien. Voir Plugin Textwheel.
     * (merci a Michael Parienti)
     *
     * @param string $texte
     * @return string
     */
    protected function traiter_listes($texte)
    {
        return $texte;
    }

    /**
     * Nettoie un texte, traite les raccourcis autre qu'URL, la typo, etc.
     *
     * Ne fais rien ici. Voir plugin Textwheel.
     *
     * @pipeline_appel pre_propre
     * @pipeline_appel post_propre
     *
     * @param string $letexte
     * @return string
     */
    protected function traiter_raccourcis($letexte)
    {

        // Appeler les fonctions de pre_traitement
        $letexte = pipeline('pre_propre', $letexte);

        // APPELER ICI UN PIPELINE traiter_raccourcis ?
        // $letexte = pipeline('traiter_raccourcis', $letexte);

        // Appeler les fonctions de post-traitement
        $letexte = pipeline('post_propre', $letexte);

        return $letexte;
    }

    /*************************************************************************************************************************
     * Fonctions utilisees en dehors de inc/texte
     */

    /**
     * Échapper et affichier joliement les `<script` et `<iframe`...
     *
     * @param string $t
     * @param string $class Attributs HTML du conteneur à ajouter
     * @return string
     */
    protected function echappe_js($t, $class = ' class = "echappe-js"')
    {
        foreach (['script', 'iframe'] as $tag) {
            if (stripos($t, "<$tag") !== false
                and preg_match_all(',<' . $tag . '.*?($|</' . $tag . '.),isS', $t, $r, PREG_SET_ORDER)
            ) {
                foreach ($r as $regs) {
                    $t = str_replace($regs[0],
                        "<code$class>" . nl2br(spip_htmlspecialchars($regs[0])) . '</code>',
                        $t);
                }
            }
        }

        return $t;
    }

    /**
     * Empêcher l'exécution de code PHP et JS
     *
     * Sécurité : empêcher l'exécution de code PHP, en le transformant en joli code
     * dans l'espace privé. Cette fonction est aussi appelée par propre et typo.
     *
     * De la même manière, la fonction empêche l'exécution de JS mais selon le mode
     * de protection passe en argument
     *
     * Il ne faut pas désactiver globalement la fonction dans l'espace privé car elle protège
     * aussi les balises des squelettes qui ne passent pas forcement par propre ou typo après
     * si elles sont appelées en direct
     *
     * @param string $arg
     *     Code à protéger
     * @param int $mode_filtre
     *     Mode de protection
     *       -1 : protection dans l'espace privé et public
     *       0  : protection dans l'espace public
     *       1  : aucune protection
     *     utilise la valeur de la globale filtrer_javascript si non fourni
     * @return string
     *     Code protégé
     **/
    protected function interdire_scripts($arg, $mode_filtre = null)
    {
        // on memorise le resultat sur les arguments non triviaux
        static $dejavu = [];

        // Attention, si ce n'est pas une chaine, laisser intact
        if (!$arg or !is_string($arg) or !strstr($arg, '<')) {
            return $arg;
        }

        if (is_null($mode_filtre) or !in_array($mode_filtre, [-1, 0, 1])) {
            $mode_filtre = $GLOBALS['filtrer_javascript'];
        }

        if (isset($dejavu[$mode_filtre][$arg])) {
            return $dejavu[$mode_filtre][$arg];
        }

        // echapper les tags asp/php
        $t = str_replace('<' . '%', '&lt;%', $arg);

        // echapper le php
        $t = str_replace('<' . '?', '&lt;?', $t);

        // echapper le < script language=php >
        $t = preg_replace(',<(script\b[^>]+\blanguage\b[^\w>]+php\b),UimsS', '&lt;\1', $t);

        // Pour le js, trois modes : parano (-1), prive (0), ok (1)
        switch ($mode_filtre) {
            case 0:
                if (!_DIR_RESTREINT) {
                    $t = echappe_js($t);
                }
                break;
            case -1:
                $t = echappe_js($t);
                break;
        }

        // pas de <base href /> svp !
        $t = preg_replace(',<(base\b),iS', '&lt;\1', $t);

        // Reinserer les echappements des modeles
        if (defined('_PROTEGE_JS_MODELES')) {
            $t = echappe_retour($t, "javascript" . _PROTEGE_JS_MODELES);
        }
        if (defined('_PROTEGE_PHP_MODELES')) {
            $t = echappe_retour($t, "php" . _PROTEGE_PHP_MODELES);
        }

        return $dejavu[$mode_filtre][$arg] = $t;
    }

    /**
     * Applique la typographie générale
     *
     * Effectue un traitement pour que les textes affichés suivent les règles
     * de typographie. Fait une protection préalable des balises HTML et SPIP.
     * Transforme les balises `<multi>`
     *
     * @filtre
     * @uses traiter_modeles()
     * @uses corriger_typo()
     * @uses echapper_faux_tags()
     * @see  propre()
     *
     * @param string $letexte
     *     Texte d'origine
     * @param bool $echapper
     *     Échapper ?
     * @param string|null $connect
     *     Nom du connecteur à la bdd
     * @param array $env
     *     Environnement (pour les calculs de modèles)
     * @return string $t
     *     Texte transformé
     **/
    protected function typo($letexte, $echapper = true, $connect = null, $env = [])
    {
        // Plus vite !
        if (!$letexte) {
            return $letexte;
        }

        // les appels directs a cette fonction depuis le php de l'espace
        // prive etant historiquement ecrit sans argment $connect
        // on utilise la presence de celui-ci pour distinguer les cas
        // ou il faut passer interdire_script explicitement
        // les appels dans les squelettes (de l'espace prive) fournissant un $connect
        // ne seront pas perturbes
        $interdire_script = false;
        if (is_null($connect)) {
            $connect = '';
            $interdire_script = true;
            $env['espace_prive'] = test_espace_prive();
        }

        // Echapper les codes <html> etc
        if ($echapper) {
            $letexte = echappe_html($letexte, 'TYPO');
        }

        //
        // Installer les modeles, notamment images et documents ;
        //
        // NOTE : propre() ne passe pas par ici mais directement par corriger_typo
        // cf. inc/lien

        $letexte = traiter_modeles($mem = $letexte, false, $echapper ? 'TYPO' : '', $connect, null, $env);
        if ($letexte != $mem) {
            $echapper = true;
        }
        unset($mem);

        $letexte = corriger_typo($letexte);
        $letexte = echapper_faux_tags($letexte);

        // reintegrer les echappements
        if ($echapper) {
            $letexte = echappe_retour($letexte, 'TYPO');
        }

        // Dans les appels directs hors squelette, securiser ici aussi
        if ($interdire_script) {
            $letexte = interdire_scripts($letexte);
        }

        // Dans l'espace prive on se mefie de tout contenu dangereux
        // https://core.spip.net/issues/3371
        // et aussi dans l'espace public si la globale filtrer_javascript = -1
        // https://core.spip.net/issues/4166
        if ($GLOBALS['filtrer_javascript'] == -1
            or (isset($env['espace_prive']) and $env['espace_prive'] and $GLOBALS['filtrer_javascript'] <= 0)) {
            $letexte = echapper_html_suspect($letexte);
        }

        return $letexte;
    }

    // Correcteur typographique
    define('_TYPO_PROTEGER', "!':;?~%-");
    define('_TYPO_PROTECTEUR', "\x1\x2\x3\x4\x5\x6\x7\x8");

    define('_TYPO_BALISE', ",</?[a-z!][^<>]*[" . preg_quote(_TYPO_PROTEGER) . "][^<>]*>,imsS");

    /**
     * Corrige la typographie
     *
     * Applique les corrections typographiques adaptées à la langue indiquée.
     *
     * @pipeline_appel pre_typo
     * @pipeline_appel post_typo
     * @uses corriger_caracteres()
     * @uses corriger_caracteres()
     *
     * @param string $letexte Texte
     * @param string $lang Langue
     * @return string Texte
     */
    protected function corriger_typo($letexte, $lang = '')
    {

        // Plus vite !
        if (!$letexte) {
            return $letexte;
        }

        $letexte = pipeline('pre_typo', $letexte);

        // Caracteres de controle "illegaux"
        $letexte = corriger_caracteres($letexte);

        // Proteger les caracteres typographiques a l'interieur des tags html
        if (preg_match_all(_TYPO_BALISE, $letexte, $regs, PREG_SET_ORDER)) {
            foreach ($regs as $reg) {
                $insert = $reg[0];
                // hack: on transforme les caracteres a proteger en les remplacant
                // par des caracteres "illegaux". (cf corriger_caracteres())
                $insert = strtr($insert, _TYPO_PROTEGER, _TYPO_PROTECTEUR);
                $letexte = str_replace($reg[0], $insert, $letexte);
            }
        }

        // trouver les blocs idiomes et les traiter à part
        $letexte = extraire_idiome($ei = $letexte, $lang, true);
        $ei = ($ei !== $letexte);

        // trouver les blocs multi et les traiter a part
        $letexte = extraire_multi($em = $letexte, $lang, true);
        $em = ($em !== $letexte);

        // Charger & appliquer les fonctions de typographie
        $typographie = charger_fonction(lang_typo($lang), 'typographie');
        $letexte = $typographie($letexte);

        // Les citations en une autre langue, s'il y a lieu
        if ($em) {
            $letexte = echappe_retour($letexte, 'multi');
        }
        if ($ei) {
            $letexte = echappe_retour($letexte, 'idiome');
        }

        // Retablir les caracteres proteges
        $letexte = strtr($letexte, _TYPO_PROTECTEUR, _TYPO_PROTEGER);

        // pipeline
        $letexte = pipeline('post_typo', $letexte);

        # un message pour abs_url - on est passe en mode texte
        $GLOBALS['mode_abs_url'] = 'texte';

        return $letexte;
    }

    /**
     * Paragrapher seulement
     *
     * /!\ appelée dans inc/filtres et public/composer
     *
     * Ne fait rien ici. Voir plugin Textwheel
     *
     * @param string $letexte
     * @param null $forcer
     * @return string
     */
    protected function paragrapher($letexte, $forcer = true)
    {
        return $letexte;
    }

    /**
     * Harmonise les retours chariots et mange les paragraphes HTML
     *
     * Ne sert plus
     *
     * @param string $letexte Texte
     * @return string Texte
     **/
    protected function traiter_retours_chariots($letexte)
    {
        $letexte = preg_replace(",\r\n?,S", "\n", $letexte);
        $letexte = preg_replace(",<p[>[:space:]],iS", "\n\n\\0", $letexte);
        $letexte = preg_replace(",</p[>[:space:]],iS", "\\0\n\n", $letexte);

        return $letexte;
    }

    /**
     * Transforme les raccourcis SPIP, liens et modèles d'un texte en code HTML
     *
     * Filtre à appliquer aux champs du type `#TEXTE*`
     *
     * @filtre
     * @uses echappe_html()
     * @uses expanser_liens()
     * @uses traiter_raccourcis()
     * @uses echappe_retour_modeles()
     * @see  typo()
     *
     * @param string $t
     *     Texte avec des raccourcis SPIP
     * @param string|null $connect
     *     Nom du connecteur à la bdd
     * @param array $env
     *     Environnement (pour les calculs de modèles)
     * @return string $t
     *     Texte transformé
     **/
    protected function propre($t, $connect = null, $env = [])
    {
        // les appels directs a cette fonction depuis le php de l'espace
        // prive etant historiquement ecrits sans argment $connect
        // on utilise la presence de celui-ci pour distinguer les cas
        // ou il faut passer interdire_script explicitement
        // les appels dans les squelettes (de l'espace prive) fournissant un $connect
        // ne seront pas perturbes
        $interdire_script = false;
        if (is_null($connect)) {
            $connect = '';
            $interdire_script = true;
        }

        if (!$t) {
            return strval($t);
        }

        // Dans l'espace prive on se mefie de tout contenu dangereux
        // avant echappement des balises <html>
        // https://core.spip.net/issues/3371
        // et aussi dans l'espace public si la globale filtrer_javascript = -1
        // https://core.spip.net/issues/4166
        if ($interdire_script
            or $GLOBALS['filtrer_javascript'] == -1
            or (isset($env['espace_prive']) and $env['espace_prive'] and $GLOBALS['filtrer_javascript'] <= 0)
            or (isset($env['wysiwyg']) and $env['wysiwyg'] and $GLOBALS['filtrer_javascript'] <= 0)) {
            $t = echapper_html_suspect($t, false);
        }
        $t = echappe_html($t);
        $t = expanser_liens($t, $connect, $env);
        $t = traiter_raccourcis($t);
        $t = echappe_retour_modeles($t, $interdire_script);

        return $t;
    }

    /**
     * @link spip/ecrire/inc/texte_mini.php
     */

    /**
     * Gestion des textes et échappements (fonctions d'usages fréquents)
     *
     * @package SPIP\Core\Texte
     **/

    if (!defined('_ECRIRE_INC_VERSION')) {
        return;
    }
    include_spip('inc/filtres');
    include_spip('inc/lang');


    /**
     * Retourne une image d'une puce
     *
     * Le nom de l'image est déterminé par la globale 'puce' ou 'puce_prive'
     * ou les mêmes suffixées de '_rtl' pour ce type de langues.
     *
     * @note
     *     On initialise la puce pour éviter `find_in_path()` à chaque rencontre de `\n-`
     *     Mais attention elle depend de la direction et de X_fonctions.php, ainsi que
     *     de l'espace choisi (public/prive)
     *
     * @return string
     *     Code HTML de la puce
     **/
    protected function definir_puce()
    {

        // Attention au sens, qui n'est pas defini de la meme facon dans
        // l'espace prive (spip_lang est la langue de l'interface, lang_dir
        // celle du texte) et public (spip_lang est la langue du texte)
        $dir = _DIR_RESTREINT ? lang_dir() : lang_dir($GLOBALS['spip_lang']);

        $p = 'puce' . (test_espace_prive() ? '_prive' : '');
        if ($dir == 'rtl') {
            $p .= '_rtl';
        }

        if (!isset($GLOBALS[$p])) {
            $GLOBALS[$p] = '<span class="spip-puce ' . $dir . '"><b>–</b></span>';
        }

        return $GLOBALS[$p];
    }

    //
    // Echapper les elements perilleux en les passant en base64
    //

    // Creer un bloc base64 correspondant a $rempl ; au besoin en marquant
    // une $source differente ; le script detecte automagiquement si ce qu'on
    // echappe est un div ou un span
    // https://code.spip.net/@code_echappement
    protected function code_echappement($rempl, $source = '', $no_transform = false, $mode = null)
    {
        if (!strlen($rempl)) {
            return '';
        }

        // Tester si on echappe en span ou en div
        if (is_null($mode) or !in_array($mode, array('div', 'span'))) {
            $mode = preg_match(',</?(' . _BALISES_BLOCS . ')[>[:space:]],iS', $rempl) ? 'div' : 'span';
        }

        // Decouper en morceaux, base64 a des probleme selon la taille de la pile
        $taille = 30000;
        $return = "";
        for ($i = 0; $i < strlen($rempl); $i += $taille) {
            // Convertir en base64 et cacher dans un attribut
            // utiliser les " pour eviter le re-encodage de ' et &#8217
            $base64 = base64_encode(substr($rempl, $i, $taille));
            $return .= "<$mode class=\"base64$source\" title=\"$base64\"></$mode>";
        }

        return $return;
    }

    // Echapper les <html>...</ html>
    // https://code.spip.net/@traiter_echap_html_dist
    protected function traiter_echap_html_dist($regs)
    {
        return $regs[3];
    }

    // Echapper les <code>...</ code>
    // https://code.spip.net/@traiter_echap_code_dist
    protected function traiter_echap_code_dist($regs)
    {
        list(, , $att, $corps) = $regs;
        $echap = spip_htmlspecialchars($corps); // il ne faut pas passer dans entites_html, ne pas transformer les &#xxx; du code !

        // ne pas mettre le <div...> s'il n'y a qu'une ligne
        if (is_int(strpos($echap, "\n"))) {
            // supprimer les sauts de ligne debut/fin
            // (mais pas les espaces => ascii art).
            $echap = preg_replace("/^[\n\r]+|[\n\r]+$/s", "", $echap);
            $echap = nl2br($echap);
            $echap = "<div style='text-align: left;' "
                . "class='spip_code' dir='ltr'><code$att>"
                . $echap . "</code></div>";
        } else {
            $echap = "<code$att class='spip_code' dir='ltr'>" . $echap . "</code>";
        }

        $echap = str_replace("\t", "&nbsp; &nbsp; &nbsp; &nbsp; ", $echap);
        $echap = str_replace("  ", " &nbsp;", $echap);

        return $echap;
    }

    // Echapper les <cadre>...</ cadre> aka <frame>...</ frame>
    // https://code.spip.net/@traiter_echap_cadre_dist
    protected function traiter_echap_cadre_dist($regs)
    {
        $echap = trim(entites_html($regs[3]));
        // compter les lignes un peu plus finement qu'avec les \n
        $lignes = explode("\n", trim($echap));
        $n = 0;
        foreach ($lignes as $l) {
            $n += floor(strlen($l) / 60) + 1;
        }
        $n = max($n, 2);
        $echap = "\n<textarea readonly='readonly' cols='40' rows='$n' class='spip_cadre' dir='ltr'>$echap</textarea>";

        return $echap;
    }

    // https://code.spip.net/@traiter_echap_frame_dist
    protected function traiter_echap_frame_dist($regs)
    {
        return traiter_echap_cadre_dist($regs);
    }

    // https://code.spip.net/@traiter_echap_script_dist
    protected function traiter_echap_script_dist($regs)
    {
        // rendre joli (et inactif) si c'est un script language=php
        if (preg_match(',<script\b[^>]+php,ims', $regs[0])) {
            return highlight_string($regs[0], true);
        }

        // Cas normal : le script passe tel quel
        return $regs[0];
    }

    define('_PROTEGE_BLOCS', ',<(html|code|cadre|frame|script|style)(\s[^>]*)?>(.*)</\1>,UimsS');

    // - pour $source voir commentaire infra (echappe_retour)
    // - pour $no_transform voir le filtre post_autobr dans inc/filtres
    // https://code.spip.net/@echappe_html
    protected function echappe_html(
        $letexte,
        $source = '',
        $no_transform = false,
        $preg = ''
    ) {
        if (!is_string($letexte) or !strlen($letexte)) {
            return $letexte;
        }

        // si le texte recu est long PCRE risque d'exploser, on
        // fait donc un mic-mac pour augmenter pcre.backtrack_limit
        if (($len = strlen($letexte)) > 100000) {
            if (!$old = @ini_get('pcre.backtrack_limit')) {
                $old = 100000;
            }
            if ($len > $old) {
                $a = @ini_set('pcre.backtrack_limit', $len);
                spip_log("ini_set pcre.backtrack_limit=$len ($old)");
            }
        }

        if (($preg or strpos($letexte, "<") !== false)
            and preg_match_all($preg ? $preg : _PROTEGE_BLOCS, $letexte, $matches, PREG_SET_ORDER)
        ) {
            foreach ($matches as $regs) {
                // echappements tels quels ?
                if ($no_transform) {
                    $echap = $regs[0];
                } // sinon les traiter selon le cas
                else {
                    if (function_exists($f = 'traiter_echap_' . strtolower($regs[1]))) {
                        $echap = $f($regs);
                    } else {
                        if (function_exists($f = $f . '_dist')) {
                            $echap = $f($regs);
                        }
                    }
                }

                $p = strpos($letexte, $regs[0]);
                $letexte = substr_replace($letexte, code_echappement($echap, $source, $no_transform), $p, strlen($regs[0]));
            }
        }

        if ($no_transform) {
            return $letexte;
        }

        // Echapper le php pour faire joli (ici, c'est pas pour la securite)
        // seulement si on a echappe les <script>
        // (derogatoire car on ne peut pas faire passer < ? ... ? >
        // dans une callback autonommee
        if (strpos($preg ? $preg : _PROTEGE_BLOCS, 'script') !== false) {
            if (strpos($letexte, "<" . "?") !== false and preg_match_all(',<[?].*($|[?]>),UisS',
                $letexte, $matches, PREG_SET_ORDER)
            ) {
                foreach ($matches as $regs) {
                    $letexte = str_replace($regs[0],
                        code_echappement(highlight_string($regs[0], true), $source),
                        $letexte);
                }
            }
        }

        return $letexte;
    }

    //
    // Traitement final des echappements
    // Rq: $source sert a faire des echappements "a soi" qui ne sont pas nettoyes
    // par propre() : exemple dans multi et dans typo()
    // https://code.spip.net/@echappe_retour
    protected function echappe_retour($letexte, $source = '', $filtre = "")
    {
        if (strpos($letexte, "base64$source")) {
            # spip_log(spip_htmlspecialchars($letexte));  ## pour les curieux
            $max_prof = 5;
            while (strpos($letexte, "<") !== false
                and
                preg_match_all(',<(span|div)\sclass=[\'"]base64' . $source . '[\'"]\s(.*)>\s*</\1>,UmsS',
                    $letexte, $regs, PREG_SET_ORDER)
                and $max_prof--) {
                foreach ($regs as $reg) {
                    $rempl = base64_decode(extraire_attribut($reg[0], 'title'));
                    // recherche d'attributs supplementaires
                    $at = [];
                    foreach (['lang', 'dir'] as $attr) {
                        if ($a = extraire_attribut($reg[0], $attr)) {
                            $at[$attr] = $a;
                        }
                    }
                    if ($at) {
                        $rempl = '<' . $reg[1] . '>' . $rempl . '</' . $reg[1] . '>';
                        foreach ($at as $attr => $a) {
                            $rempl = inserer_attribut($rempl, $attr, $a);
                        }
                    }
                    if ($filtre) {
                        $rempl = $filtre($rempl);
                    }
                    $letexte = str_replace($reg[0], $rempl, $letexte);
                }
            }
        }

        return $letexte;
    }

    // Reinserer le javascript de confiance (venant des modeles)

    // https://code.spip.net/@echappe_retour_modeles
    protected function echappe_retour_modeles($letexte, $interdire_scripts = false)
    {
        $letexte = echappe_retour($letexte);

        // Dans les appels directs hors squelette, securiser aussi ici
        if ($interdire_scripts) {
            $letexte = interdire_scripts($letexte);
        }

        return trim($letexte);
    }

    /**
     * Coupe un texte à une certaine longueur.
     *
     * Il essaie de ne pas couper les mots et enlève le formatage du texte.
     * Si le texte original est plus long que l’extrait coupé, alors des points
     * de suite sont ajoutés à l'extrait, tel que ` (...)`.
     *
     * @note
     *     Les points de suite ne sont pas ajoutés sur les extraits
     *     très courts.
     *
     * @filtre
     * @link https://www.spip.net/4275
     *
     * @param string $texte
     *     Texte à couper
     * @param int $taille
     *     Taille de la coupe
     * @param string $suite
     *     Points de suite ajoutés.
     * @return string
     *     Texte coupé
     **/
    protected function couper($texte, $taille = 50, $suite = null)
    {
        if (!($length = strlen($texte)) or $taille <= 0) {
            return '';
        }
        $offset = 400 + 2 * $taille;
        while ($offset < $length
            and strlen(preg_replace(",<(!--|\w|/)[^>]+>,Uims", "", substr($texte, 0, $offset))) < $taille) {
            $offset = 2 * $offset;
        }
        if ($offset < $length
            && ($p_tag_ouvrant = strpos($texte, '<', $offset)) !== null
        ) {
            $p_tag_fermant = strpos($texte, '>', $offset);
            if ($p_tag_fermant && ($p_tag_fermant < $p_tag_ouvrant)) {
                $offset = $p_tag_fermant + 1;
            } // prolonger la coupe jusqu'au tag fermant suivant eventuel
        }
        $texte = substr($texte, 0, $offset); /* eviter de travailler sur 10ko pour extraire 150 caracteres */

        if (!function_exists('nettoyer_raccourcis_typo')) {
            include_spip('inc/lien');
        }
        $texte = nettoyer_raccourcis_typo($texte);

        // balises de sauts de ligne et paragraphe
        $texte = preg_replace("/<p( [^>]*)?" . ">/", "\r", $texte);
        $texte = preg_replace("/<br( [^>]*)?" . ">/", "\n", $texte);

        // on repasse les doubles \n en \r que nettoyer_raccourcis_typo() a pu modifier
        $texte = str_replace("\n\n", "\r", $texte);

        // supprimer les tags
        $texte = supprimer_tags($texte);
        $texte = trim(str_replace("\n", " ", $texte));
        $texte .= "\n";  // marquer la fin

        // corriger la longueur de coupe
        // en fonction de la presence de caracteres utf
        if ($GLOBALS['meta']['charset'] == 'utf-8') {
            $long = charset2unicode($texte);
            $long = spip_substr($long, 0, max($taille, 1));
            $nbcharutf = preg_match_all('/(&#[0-9]{3,6};)/S', $long, $matches);
            $taille += $nbcharutf;
        }

        // couper au mot precedent
        $long = spip_substr($texte, 0, max($taille - 4, 1));
        $u = $GLOBALS['meta']['pcre_u'];
        $court = preg_replace("/([^\s][\s]+)[^\s]*\n?$/" . $u, "\\1", $long);
        if (is_null($suite)) {
            $suite = (defined('_COUPER_SUITE') ? _COUPER_SUITE : '&nbsp;(...)');
        }
        $points = $suite;

        // trop court ? ne pas faire de (...)
        if (spip_strlen($court) < max(0.75 * $taille, 2)) {
            $points = '';
            $long = spip_substr($texte, 0, $taille);
            $texte = preg_replace("/([^\s][\s]+)[^\s]*\n?$/" . $u, "\\1", $long);
            // encore trop court ? couper au caractere
            if (spip_strlen($texte) < 0.75 * $taille) {
                $texte = $long;
            }
        } else {
            $texte = $court;
        }

        if (strpos($texte, "\n")) {  // la fin est encore la : c'est qu'on n'a pas de texte de suite
            $points = '';
        }

        // remettre les paragraphes
        $texte = preg_replace("/\r+/", "\n\n", $texte);

        // supprimer l'eventuelle entite finale mal coupee
        $texte = preg_replace('/&#?[a-z0-9]*$/S', '', $texte);

        return quote_amp(trim($texte)) . $points;
    }

    // https://code.spip.net/@protege_js_modeles
    protected function protege_js_modeles($t)
    {
        if (isset($GLOBALS['visiteur_session'])) {
            if (preg_match_all(',<script.*?($|</script.),isS', $t, $r, PREG_SET_ORDER)) {
                if (!defined('_PROTEGE_JS_MODELES')) {
                    include_spip('inc/acces');
                    define('_PROTEGE_JS_MODELES', creer_uniqid());
                }
                foreach ($r as $regs) {
                    $t = str_replace($regs[0], code_echappement($regs[0], 'javascript' . _PROTEGE_JS_MODELES), $t);
                }
            }
            if (preg_match_all(',<\?php.*?($|\?' . '>),isS', $t, $r, PREG_SET_ORDER)) {
                if (!defined('_PROTEGE_PHP_MODELES')) {
                    include_spip('inc/acces');
                    define('_PROTEGE_PHP_MODELES', creer_uniqid());
                }
                foreach ($r as $regs) {
                    $t = str_replace($regs[0], code_echappement($regs[0], 'php' . _PROTEGE_PHP_MODELES), $t);
                }
            }
        }

        return $t;
    }

    protected function echapper_faux_tags($letexte)
    {
        if (strpos($letexte, '<') === false) {
            return $letexte;
        }
        $textMatches = preg_split(',(</?[a-z!][^<>]*>),', $letexte, null, PREG_SPLIT_DELIM_CAPTURE);

        $letexte = "";
        while (count($textMatches)) {
            // un texte a echapper
            $letexte .= str_replace("<", '&lt;', array_shift($textMatches));
            // un tag html qui a servit a faite le split
            $letexte .= array_shift($textMatches);
        }

        return $letexte;
    }

    /**
     * Si le html contenu dans un texte ne passe pas sans transformation a travers safehtml
     * on l'echappe
     * si safehtml ne renvoie pas la meme chose on echappe les < en &lt; pour montrer le contenu brut
     *
     * @param string $texte
     * @param bool $strict
     * @return string
     */
    protected function echapper_html_suspect($texte, $strict = true)
    {
        static $echapper_html_suspect;
        if (!$texte or !is_string($texte)) {
            return $texte;
        }

        if (!isset($echapper_html_suspect)) {
            $echapper_html_suspect = charger_fonction('echapper_html_suspect', 'inc', true);
        }
        // si fonction personalisee, on delegue
        if ($echapper_html_suspect) {
            return $echapper_html_suspect($texte, $strict);
        }

        if (strpos($texte, '<') === false
            or strpos($texte, '=') === false) {
            return $texte;
        }

        // quand c'est du texte qui passe par propre on est plus coulant tant qu'il y a pas d'attribut du type onxxx=
        // car sinon on declenche sur les modeles ou ressources
        if (!$strict and
            (strpos($texte, 'on') === false or !preg_match(",<\w+.*\bon\w+\s*=,UimsS", $texte))
            ) {
            return $texte;
        }

        // on teste sur strlen car safehtml supprime le contenu dangereux
        // mais il peut aussi changer des ' en " sur les attributs html,
        // donc un test d'egalite est trop strict
        if (strlen(safehtml($texte)) !== strlen($texte)) {
            $texte = str_replace("<", "&lt;", $texte);
            if (!function_exists('attribut_html')) {
                include_spip('inc/filtres');
            }
            $texte = "<mark class='danger-js' title='" . attribut_html(_T('erreur_contenu_suspect')) . "'>⚠️</mark> " . $texte;
        }

        return $texte;
    }

    /**
     * Sécurise un texte HTML
     *
     * Échappe le code PHP et JS.
     * Applique en plus safehtml si un plugin le définit dans inc/safehtml.php
     *
     * Permet de protéger les textes issus d'une origine douteuse (forums, syndications...)
     *
     * @filtre
     * @link https://www.spip.net/4310
     *
     * @param string $t
     *      Texte à sécuriser
     * @return string
     *      Texte sécurisé
     **/
    protected function safehtml($t)
    {
        static $safehtml;

        if (!$t or !is_string($t)) {
            return $t;
        }
        # attention safehtml nettoie deux ou trois caracteres de plus. A voir
        if (strpos($t, '<') === false) {
            return str_replace("\x00", '', $t);
        }

        $t = interdire_scripts($t); // jolifier le php
        $t = echappe_js($t);

        if (!isset($safehtml)) {
            $safehtml = charger_fonction('safehtml', 'inc', true);
        }
        if ($safehtml) {
            $t = $safehtml($t);
        }

        return interdire_scripts($t); // interdire le php (2 precautions)
    }

    /**
     * Supprime les modèles d'image d'un texte
     *
     * Fonction en cas de texte extrait d'un serveur distant:
     * on ne sait pas (encore) rapatrier les documents joints
     * Sert aussi à nettoyer un texte qu'on veut mettre dans un `<a>` etc.
     *
     * @todo
     *     gérer les autres modèles ?
     *
     * @param string $letexte
     *     Texte à nettoyer
     * @param string|null $message
     *     Message de remplacement pour chaque image enlevée
     * @return string
     *     Texte sans les modèles d'image
     **/
    protected function supprime_img($letexte, $message = null)
    {
        if ($message === null) {
            $message = '(' . _T('img_indisponible') . ')';
        }

        return preg_replace(',<(img|doc|emb)([0-9]+)(\|([^>]*))?' . '\s*/?' . '>,i',
            $message, $letexte);
    }

    /**
     * @link spip/ecrire/inc/utils.php
     */

    /**
     * Utilitaires indispensables autour du serveur Http.
     *
     * @package SPIP\Core\Utilitaires
     **/

    if (!defined('_ECRIRE_INC_VERSION')) {
        return;
    }


    /**
     * Cherche une fonction surchargeable et en retourne le nom exact,
     * après avoir chargé le fichier la contenant si nécessaire.
     *
     * Charge un fichier (suivant les chemins connus) et retourne si elle existe
     * le nom de la fonction homonyme `$dir_$nom`, ou suffixé `$dir_$nom_dist`
     *
     * Peut être appelé plusieurs fois, donc optimisé.
     *
     * @api
     * @uses include_spip() Pour charger le fichier
     * @example
     *     ```
     *     $envoyer_mail = charger_fonction('envoyer_mail', 'inc');
     *     $envoyer_mail($email, $sujet, $texte);
     *     ```
     *
     * @param string $nom
     *     Nom de la fonction (et du fichier)
     * @param string $dossier
     *     Nom du dossier conteneur
     * @param bool $continue
     *     true pour ne pas râler si la fonction n'est pas trouvée
     * @return string
     *     Nom de la fonction, ou false.
     */
    protected function charger_fonction($nom, $dossier = 'exec', $continue = false)
    {
        static $echecs = [];

        if (strlen($dossier) and substr($dossier, -1) != '/') {
            $dossier .= '/';
        }
        $f = str_replace('/', '_', $dossier) . $nom;

        if (function_exists($f)) {
            return $f;
        }
        if (function_exists($g = $f . '_dist')) {
            return $g;
        }

        if (isset($echecs[$f])) {
            return $echecs[$f];
        }
        // Sinon charger le fichier de declaration si plausible

        if (!preg_match(',^\w+$,', $f)) {
            if ($continue) {
                return false;
            } //appel interne, on passe
            include_spip('inc/minipres');
            echo minipres();
            exit;
        }

        // passer en minuscules (cf les balises de formulaires)
        // et inclure le fichier
        if (!$inc = include_spip($dossier . ($d = strtolower($nom)))
            // si le fichier truc/machin/nom.php n'existe pas,
            // la fonction peut etre definie dans truc/machin.php qui regroupe plusieurs petites fonctions
            and strlen(dirname($dossier)) and dirname($dossier) != '.'
        ) {
            include_spip(substr($dossier, 0, -1));
        }
        if (function_exists($f)) {
            return $f;
        }
        if (function_exists($g)) {
            return $g;
        }

        if ($continue) {
            return $echecs[$f] = false;
        }

        // Echec : message d'erreur
        spip_log("fonction $nom ($f ou $g) indisponible" .
            ($inc ? "" : " (fichier $d absent de $dossier)"));

        include_spip('inc/minipres');
        echo minipres(_T('forum_titre_erreur'),
            _T('fichier_introuvable', ['fichier' => '<b>' . spip_htmlentities($d) . '</b>']),
            ['all_inline' => true,'status' => 404]);
        exit;
    }

    /**
     * Inclusion unique avec verification d'existence du fichier + log en crash sinon
     *
     * @param string $file
     * @return bool
     */
    protected function include_once_check($file)
    {
        if (file_exists($file)) {
            include_once $file;

            return true;
        }
        $crash = (isset($GLOBALS['meta']['message_crash_plugins']) ? unserialize($GLOBALS['meta']['message_crash_plugins']) : '');
        $crash = ($crash ? $crash : []);
        $crash[$file] = true;
        ecrire_meta('message_crash_plugins', serialize($crash));

        return false;
    }

    /**
     * Inclut un fichier PHP (en le cherchant dans les chemins)
     *
     * @api
     * @uses find_in_path()
     * @example
     *     ```
     *     include_spip('inc/texte');
     *     ```
     *
     * @param string $f
     *     Nom du fichier (sans l'extension)
     * @param bool $include
     *     - true pour inclure le fichier,
     *     - false ne fait que le chercher
     * @return string|bool
     *     - false : fichier introuvable
     *     - string : chemin du fichier trouvé
     **/
    protected function include_spip($f, $include = true)
    {
        return find_in_path($f . '.php', '', $include);
    }

    /**
     * Requiert un fichier PHP (en le cherchant dans les chemins)
     *
     * @uses find_in_path()
     * @see  include_spip()
     * @example
     *     ```
     *     require_spip('inc/texte');
     *     ```
     *
     * @param string $f
     *     Nom du fichier (sans l'extension)
     * @return string|bool
     *     - false : fichier introuvable
     *     - string : chemin du fichier trouvé
     **/
    protected function require_spip($f)
    {
        return find_in_path($f . '.php', '', 'required');
    }

    /**
     * Exécute une fonction (appellée par un pipeline) avec la donnée transmise.
     *
     * Un pipeline est lie a une action et une valeur
     * chaque element du pipeline est autorise a modifier la valeur
     * le pipeline execute les elements disponibles pour cette action,
     * les uns apres les autres, et retourne la valeur finale
     *
     * Cf. compose_filtres dans references.php, qui est la
     * version compilee de cette fonctionnalite
     * appel unitaire d'une fonction du pipeline
     * utilisee dans le script pipeline precompile
     *
     * on passe $val par reference pour limiter les allocations memoire
     *
     * @param string $fonc
     *     Nom de la fonction appelée par le pipeline
     * @param string|array $val
     *     Les paramètres du pipeline, son environnement
     * @return string|array $val
     *     Les paramètres du pipeline modifiés
     **/
    protected function minipipe($fonc, &$val)
    {
        // fonction
        if (function_exists($fonc)) {
            $val = call_user_func($fonc, $val);
        } // Class::Methode
        else {
            if (preg_match("/^(\w*)::(\w*)$/S", $fonc, $regs)
                and $methode = [$regs[1], $regs[2]]
                and is_callable($methode)
            ) {
                $val = call_user_func($methode, $val);
            } else {
                spip_log("Erreur - '$fonc' non definie !");
            }
        }

        return $val;
    }

    /**
     * Appel d’un pipeline
     *
     * Exécute le pipeline souhaité, éventuellement avec des données initiales.
     * Chaque plugin qui a demandé à voir ce pipeline vera sa fonction spécifique appelée.
     * Les fonctions (des plugins) appelées peuvent modifier à leur guise le contenu.
     *
     * Deux types de retours. Si `$val` est un tableau de 2 éléments, avec une clé `data`
     * on retourne uniquement ce contenu (`$val['data']`) sinon on retourne tout `$val`.
     *
     *
     * @example
     *     Appel du pipeline `pre_insertion`
     *     ```
     *     $champs = pipeline('pre_insertion', array(
     *         'args' => array('table' => 'spip_articles'),
     *         'data' => $champs
     *     ));
     *     ```
     *
     * @param string $action
     *     Nom du pipeline
     * @param null|string|array $val
     *     Données à l’entrée du pipeline
     * @return mixed|null
     *     Résultat
     */
    protected function pipeline($action, $val = null)
    {
        static $charger;

        // chargement initial des fonctions mises en cache, ou generation du cache
        if (!$charger) {
            if (!($ok = @is_readable($charger = _CACHE_PIPELINES))) {
                include_spip('inc/plugin');
                // generer les fichiers php precompiles
                // de chargement des plugins et des pipelines
                actualise_plugins_actifs();
                if (!($ok = @is_readable($charger))) {
                    spip_log("fichier $charger pas cree");
                }
            }

            if ($ok) {
                include_once $charger;
            }
        }

        // appliquer notre fonction si elle existe
        $fonc = 'execute_pipeline_' . strtolower($action);
        if (function_exists($fonc)) {
            $val = $fonc($val);
        } // plantage ?
        else {
            spip_log("fonction $fonc absente : pipeline desactive", _LOG_ERREUR);
        }

        // si le flux est une table avec 2 cle args&data
        // on ne ressort du pipe que les donnees dans 'data'
        // array_key_exists pour php 4.1.0
        if (is_array($val)
            and count($val) == 2
            and (array_key_exists('data', $val))
            ) {
            $val = $val['data'];
        }

        return $val;
    }

    /**
     * Enregistrement des événements
     *
     * Signature : `spip_log(message[,niveau|type|type.niveau])`
     *
     * Le niveau de log par défaut est la valeur de la constante `_LOG_INFO`
     *
     * Les différents niveaux possibles sont :
     *
     * - `_LOG_HS` : écrira 'HS' au début de la ligne logguée
     * - `_LOG_ALERTE_ROUGE` : 'ALERTE'
     * - `_LOG_CRITIQUE` :  'CRITIQUE'
     * - `_LOG_ERREUR` : 'ERREUR'
     * - `_LOG_AVERTISSEMENT` : 'WARNING'
     * - `_LOG_INFO_IMPORTANTE` : '!INFO'
     * - `_LOG_INFO` : 'info'
     * - `_LOG_DEBUG` : 'debug'
     *
     * @example
     *   ```
     *   spip_log($message)
     *   spip_log($message, 'recherche')
     *   spip_log($message, _LOG_DEBUG)
     *   spip_log($message, 'recherche.'._LOG_DEBUG)
     *   ```
     *
     * @api
     * @link https://programmer.spip.net/spip_log
     * @uses inc_log_dist()
     *
     * @param string $message
     *     Message à loger
     * @param string|int $name
     *
     *     - int indique le niveau de log, tel que `_LOG_DEBUG`
     *     - string indique le type de log
     *     - `string.int` indique les 2 éléments.
     *     Cette dernière notation est controversée mais le 3ème
     *     paramètre est planté pour cause de compatibilité ascendante.
     */
    protected function spip_log($message = null, $name = null): void
    {
        static $pre = [];
        static $log;
        preg_match('/^([a-z_]*)\.?(\d)?$/iS', (string) $name, $regs);
        if (!isset($regs[1]) or !$logname = $regs[1]) {
            $logname = null;
        }
        if (!isset($regs[2]) or !$niveau = $regs[2]) {
            $niveau = _LOG_INFO;
        }

        if ($niveau <= (defined('_LOG_FILTRE_GRAVITE') ? _LOG_FILTRE_GRAVITE : _LOG_INFO_IMPORTANTE)) {
            if (!$pre) {
                $pre = [
                    _LOG_HS => 'HS:',
                    _LOG_ALERTE_ROUGE => 'ALERTE:',
                    _LOG_CRITIQUE => 'CRITIQUE:',
                    _LOG_ERREUR => 'ERREUR:',
                    _LOG_AVERTISSEMENT => 'WARNING:',
                    _LOG_INFO_IMPORTANTE => '!INFO:',
                    _LOG_INFO => 'info:',
                    _LOG_DEBUG => 'debug:',
                ];
                $log = charger_fonction('log', 'inc');
            }
            if (!is_string($message)) {
                $message = print_r($message, true);
            }
            $log($pre[$niveau] . ' ' . $message, $logname);
        }
    }

    /**
     * Enregistrement des journaux
     *
     * @uses inc_journal_dist()
     * @param string $phrase Texte du journal
     * @param array $opt Tableau d'options
     **/
    protected function journal($phrase, $opt = []): void
    {
        $journal = charger_fonction('journal', 'inc');
        $journal($phrase, $opt);
    }

    /**
     * Renvoie le `$_GET` ou le `$_POST` émis par l'utilisateur
     * ou pioché dans un tableau transmis
     *
     * @api
     * @param string $var
     *     Clé souhaitée
     * @param bool|array $c
     *     Tableau transmis (sinon cherche dans GET ou POST)
     * @return mixed|null
     *     - null si la clé n'a pas été trouvée
     *     - la valeur de la clé sinon.
     **/
    protected function _request($var, $c = false)
    {
        if (is_array($c)) {
            return $c[$var] ?? null;
        }

        if (isset($_GET[$var])) {
            $a = $_GET[$var];
        } elseif (isset($_POST[$var])) {
            $a = $_POST[$var];
        } else {
            return null;
        }

        // Si on est en ajax et en POST tout a ete encode
        // via encodeURIComponent, il faut donc repasser
        // dans le charset local...
        if (defined('_AJAX')
            and _AJAX
            and isset($GLOBALS['meta']['charset'])
            and $GLOBALS['meta']['charset'] != 'utf-8'
            and is_string($a)
            // check rapide mais pas fiable
            and preg_match(',[\x80-\xFF],', $a)
            // check fiable
            and include_spip('inc/charsets')
            and is_utf8($a)
        ) {
            return importer_charset($a, 'utf-8');
        }

        return $a;
    }

    /**
     * Affecte une valeur à une clé (pour usage avec `_request()`)
     *
     * @see _request() Pour obtenir la valeur
     * @note Attention au cas ou l'on fait `set_request('truc', NULL);`
     *
     * @param string $var Nom de la clé
     * @param string $val Valeur à affecter
     * @param bool|array $c Tableau de données (sinon utilise `$_GET` et `$_POST`)
     * @return array|bool
     *     - array $c complété si un $c est transmis,
     *     - false sinon
     **/
    protected function set_request($var, $val = null, $c = false)
    {
        if (is_array($c)) {
            unset($c[$var]);
            if ($val !== null) {
                $c[$var] = $val;
            }

            return $c;
        }

        unset($_GET[$var]);
        unset($_POST[$var]);
        if ($val !== null) {
            $_GET[$var] = $val;
        }

        return false; # n'affecte pas $c
    }

    /**
     * Sanitizer une valeur *SI* elle provient du GET ou POST
     * Utile dans les squelettes pour les valeurs qu'on attrape dans le env,
     * dont on veut permettre à un squelette de confiance appelant de fournir une valeur complexe
     * mais qui doit etre nettoyee si elle provient de l'URL
     *
     * On peut sanitizer
     * - une valeur simple : `$where = spip_sanitize_from_request($value, 'where')`
     * - un tableau en partie : `$env = spip_sanitize_from_request($env, ['key1','key2'])`
     * - un tableau complet : `$env = spip_sanitize_from_request($env, '*')`
     *
     * @param string|array $value
     * @param string|array $key
     * @param string $sanitize_function
     * @return array|mixed|string
     */
    protected function spip_sanitize_from_request($value, $key, $sanitize_function = 'entites_html')
    {
        if (is_array($value)) {
            if ($key == '*') {
                $key = array_keys($value);
            }
            if (!is_array($key)) {
                $key = [$key];
            }
            foreach ($key as $k) {
                if (!empty($value[$k])) {
                    $value[$k] = spip_sanitize_from_request($value[$k], $k, $sanitize_function);
                }
            }
            return $value;
        }
        // si la valeur vient des GET ou POST on la sanitize
        if (!empty($value) and $value == _request($key)) {
            $value = $sanitize_function($value);
        }
        return $value;
    }

    /**
     * Tester si une URL est absolue
     *
     * On est sur le web, on exclut certains protocoles,
     * notamment 'file://', 'php://' et d'autres…

     * @param string $url
     * @return bool
     */
    protected function tester_url_absolue($url)
    {
        $url = trim($url);
        if (preg_match(";^([a-z]{3,7}:)?//;Uims", $url, $m)) {
            if (
                isset($m[1])
                and $p = strtolower(rtrim($m[1], ':'))
                and in_array($p, ['file', 'php', 'zlib', 'glob', 'phar', 'ssh2', 'rar', 'ogg', 'expect', 'zip'])
            ) {
                return false;
            }
            return true;
        }
        return false;
    }

    /**
     * Prend une URL et lui ajoute/retire un paramètre
     *
     * @filtre
     * @link https://www.spip.net/4255
     * @example
     *     ```
     *     [(#SELF|parametre_url{suite,18})] (ajout)
     *     [(#SELF|parametre_url{suite,''})] (supprime)
     *     [(#SELF|parametre_url{suite[],1})] (tableaux valeurs multiples)
     *     ```
     *
     * @param string $url URL
     * @param string $c Nom du paramètre
     * @param string|array|null $v Valeur du paramètre
     * @param string $sep Séparateur entre les paramètres
     * @return string URL
     */
    protected function parametre_url($url, $c, $v = null, $sep = '&amp;')
    {
        // requete erronnee : plusieurs variable dans $c et aucun $v
        if (strpos($c, "|") !== false and is_null($v)) {
            return null;
        }

        // lever l'#ancre
        if (preg_match(',^([^#]*)(#.*)$,', $url, $r)) {
            $url = $r[1];
            $ancre = $r[2];
        } else {
            $ancre = '';
        }

        // eclater
        $url = preg_split(',[?]|&amp;|&,', $url);

        // recuperer la base
        $a = array_shift($url);
        if (!$a) {
            $a = './';
        }

        $regexp = ',^(' . str_replace('[]', '\[\]', $c) . '[[]?[]]?)(=.*)?$,';
        $ajouts = array_flip(explode('|', $c));
        $u = is_array($v) ? $v : rawurlencode($v);
        $testv = (is_array($v) ? count($v) : strlen($v));
        $v_read = null;
        // lire les variables et agir
        foreach ($url as $n => $val) {
            if (preg_match($regexp, urldecode($val), $r)) {
                $r = array_pad($r, 3, null);
                if ($v === null) {
                    // c'est un tableau, on memorise les valeurs
                    if (substr($r[1], -2) == "[]") {
                        if (!$v_read) {
                            $v_read = [];
                        }
                        $v_read[] = $r[2] ? substr($r[2], 1) : '';
                    } // c'est un scalaire, on retourne direct
                    else {
                        return $r[2] ? substr($r[2], 1) : '';
                    }
                } // suppression
                elseif (!$testv) {
                    unset($url[$n]);
                }
                // Ajout. Pour une variable, remplacer au meme endroit,
                // pour un tableau ce sera fait dans la prochaine boucle
                elseif (substr($r[1], -2) != '[]') {
                    $url[$n] = $r[1] . '=' . $u;
                    unset($ajouts[$r[1]]);
                }
                // Pour les tableaux on laisse tomber les valeurs de
                // départ, on remplira à l'étape suivante
                else {
                    unset($url[$n]);
                }
            }
        }

        // traiter les parametres pas encore trouves
        if ($v === null
            and $args = func_get_args()
            and count($args) == 2
        ) {
            return $v_read; // rien trouve ou un tableau
        } elseif ($testv) {
            foreach ($ajouts as $k => $n) {
                if (!is_array($v)) {
                    $url[] = $k . '=' . $u;
                } else {
                    $id = (substr($k, -2) == '[]') ? $k : ($k . "[]");
                    foreach ($v as $w) {
                        $url[] = $id . '=' . (is_array($w) ? 'Array' : $w);
                    }
                }
            }
        }

        // eliminer les vides
        $url = array_filter($url);

        // recomposer l'adresse
        if ($url) {
            $a .= '?' . join($sep, $url);
        }

        return $a . $ancre;
    }

    /**
     * Ajoute (ou retire) une ancre sur une URL
     *
     * L’ancre est nettoyée : on translitère, vire les non alphanum du début,
     * et on remplace ceux à l'interieur ou au bout par `-`
     *
     * @example
     *     - `$url = ancre_url($url, 'navigation'); // => mettra l’ancre #navigation
     *     - `$url = ancre_url($url, ''); // => enlèvera une éventuelle ancre
     * @uses translitteration()
     * @param string $url
     * @param string $ancre
     * @return string
     */
    protected function ancre_url($url, $ancre)
    {
        // lever l'#ancre
        if (preg_match(',^([^#]*)(#.*)$,', $url, $r)) {
            $url = $r[1];
        }
        if (preg_match('/[^-_a-zA-Z0-9]+/S', $ancre)) {
            if (!function_exists('translitteration')) {
                include_spip('inc/charsets');
            }
            $ancre = preg_replace(
                ['/^[^-_a-zA-Z0-9]+/', '/[^-_a-zA-Z0-9]/'],
                ['', '-'],
                translitteration($ancre)
            );
        }
        return $url . (strlen($ancre) ? '#' . $ancre : '');
    }

    /**
     * Pour le nom du cache, les `types_urls` et `self`
     *
     * @param string|null $reset
     * @return string
     */
    protected function nettoyer_uri($reset = null)
    {
        static $done = false;
        static $propre = '';
        if (!is_null($reset)) {
            return $propre = $reset;
        }
        if ($done) {
            return $propre;
        }
        $done = true;
        return $propre = nettoyer_uri_var($GLOBALS['REQUEST_URI']);
    }

    /**
     * Nettoie une request_uri des paramètres var_xxx
     *
     * Attention, la regexp doit suivre _CONTEXTE_IGNORE_VARIABLES défini au début de public/assembler.php
     *
     * @param $request_uri
     * @return string
     */
    protected function nettoyer_uri_var($request_uri)
    {
        $uri1 = $request_uri;
        do {
            $uri = $uri1;
            $uri1 = preg_replace(',([?&])(var_[^=&]*|PHPSESSID|fbclid|utm_[^=&]*)=[^&]*(&|$),i',
                '\1', $uri);
        } while ($uri <> $uri1);
        return preg_replace(',[?&]$,', '', $uri1);
    }

    /**
     * Donner l'URL de base d'un lien vers "soi-meme", modulo les trucs inutiles
     *
     * @param string $amp
     *    Style des esperluettes
     * @param bool $root
     * @return string
     *    URL vers soi-même
     **/
    protected function self($amp = '&amp;', $root = false)
    {
        $url = nettoyer_uri();
        if (!$root
            and (
                // si pas de profondeur on peut tronquer
                $GLOBALS['profondeur_url'] < (_DIR_RESTREINT ? 1 : 2)
                // sinon c'est OK si _SET_HTML_BASE a ete force a false
                or (defined('_SET_HTML_BASE') and !_SET_HTML_BASE))
        ) {
            $url = preg_replace(',^[^?]*/,', '', $url);
        }
        // ajouter le cas echeant les variables _POST['id_...']
        foreach ($_POST as $v => $c) {
            if (substr($v, 0, 3) == 'id_') {
                $url = parametre_url($url, $v, $c, '&');
            }
        }

        // supprimer les variables sans interet
        if (test_espace_prive()) {
            $url = preg_replace(',([?&])('
                . 'lang|show_docs|'
                . 'changer_lang|var_lang|action)=[^&]*,i', '\1', $url);
            $url = preg_replace(',([?&])[&]+,', '\1', $url);
            $url = preg_replace(',[&]$,', '\1', $url);
        }

        // eviter les hacks
        include_spip('inc/filtres_mini');
        $url = spip_htmlspecialchars($url);

        $url = str_replace(["'", '"', '<', '[', ']', ':'], ['%27', '%22', '%3C', '%5B', '%5D', '%3A'], $url);

        // &amp; ?
        if ($amp != '&amp;') {
            $url = str_replace('&amp;', $amp, $url);
        }

        // Si ca demarre par ? ou vide, donner './'
        $url = preg_replace(',^([?].*)?$,', './\1', $url);

        return $url;
    }

    /**
     * Indique si on est dans l'espace prive
     *
     * @return bool
     *     true si c'est le cas, false sinon.
     */
    protected function test_espace_prive()
    {
        return defined('_ESPACE_PRIVE') ? _ESPACE_PRIVE : false;
    }

    /**
     * Vérifie la présence d'un plugin actif, identifié par son préfixe
     *
     * @param string $plugin
     * @return bool
     */
    protected function test_plugin_actif($plugin)
    {
        return ($plugin and defined('_DIR_PLUGIN_' . strtoupper($plugin))) ? true : false;
    }

    /**
     * Traduction des textes de SPIP
     *
     * Traduit une clé de traduction en l'obtenant dans les fichiers de langues.
     *
     * @api
     * @uses inc_traduire_dist()
     * @uses _L()
     * @example
     *     ```
     *     _T('bouton_enregistrer')
     *     _T('medias:image_tourner_droite')
     *     _T('medias:erreurs', array('nb'=>3))
     *     _T("email_sujet", array('spip_lang'=>$lang_usager))
     *     ```
     *
     * @param string $texte
     *     Clé de traduction
     * @param array $args
     *     Couples (variable => valeur) pour passer des variables à la chaîne traduite. la variable spip_lang permet de forcer la langue
     * @param array $options
     *     - string class : nom d'une classe a ajouter sur un span pour encapsuler la chaine
     *     - bool force : forcer un retour meme si la chaine n'a pas de traduction
     *     - bool sanitize : nettoyer le html suspect dans les arguments
     * @return string
     *     Texte
     */
    protected function _T($texte, $args = [], $options = [])
    {
        static $traduire = false;
        $o = ['class' => '', 'force' => true, 'sanitize' => true];
        if ($options) {
            // support de l'ancien argument $class
            if (is_string($options)) {
                $options = ['class' => $options];
            }
            $o = array_merge($o, $options);
        }

        if (!$traduire) {
            $traduire = charger_fonction('traduire', 'inc');
            include_spip('inc/lang');
        }

        // On peut passer explicitement la langue dans le tableau
        // On utilise le même nom de variable que la globale
        if (isset($args['spip_lang'])) {
            $lang = $args['spip_lang'];
            // On l'enleve pour ne pas le passer au remplacement
            unset($args['spip_lang']);
        } // Sinon on prend la langue du contexte
        else {
            $lang = $GLOBALS['spip_lang'];
        }
        $text = $traduire($texte, $lang);

        if (!strlen($text)) {
            if (!$o['force']) {
                return '';
            }

            $text = $texte;

            // pour les chaines non traduites, assurer un service minimum
            if (!$GLOBALS['test_i18n'] and (_request('var_mode') != 'traduction')) {
                $text = str_replace('_', ' ',
                    (($n = strpos($text, ':')) === false ? $texte :
                        substr($texte, $n + 1)));
            }
            $o['class'] = null;
        }

        return _L($text, $args, $o);
    }

    /**
     * Remplace les variables `@...@` par leur valeur dans une chaîne de langue.
     *
     * Cette fonction est également appelée dans le code source de SPIP quand une
     * chaîne n'est pas encore dans les fichiers de langue.
     *
     * @see _T()
     * @example
     *     ```
     *     _L('Texte avec @nb@ ...', array('nb'=>3)
     *     ```
     *
     * @param string $text
     *     Texte
     * @param array $args
     *     Couples (variable => valeur) à transformer dans le texte
     * @param array $options
     *     - string class : nom d'une classe a ajouter sur un span pour encapsuler la chaine
     *     - bool sanitize : nettoyer le html suspect dans les arguments
     * @return string
     *     Texte
     */
    protected function _L($text, $args = [], $options = [])
    {
        $f = $text;
        $defaut_options = [
            'class' => null,
            'sanitize' => true,
        ];
        // support de l'ancien argument $class
        if ($options and is_string($options)) {
            $options = ['class' => $options];
        }
        if (is_array($options)) {
            $options += $defaut_options;
        } else {
            $options = $defaut_options;
        }

        if (is_array($args) and count($args)) {
            if (!function_exists('interdire_scripts')) {
                include_spip('inc/texte');
            }
            if (!function_exists('echapper_html_suspect')) {
                include_spip('inc/texte_mini');
            }
            foreach ($args as $name => $value) {
                if (strpos($text, "@$name@") !== false) {
                    if ($options['sanitize']) {
                        $value = echapper_html_suspect($value);
                        $value = interdire_scripts($value, -1);
                    }
                    if (!empty($options['class'])) {
                        $value = "<span class='" . $options['class'] . "'>$value</span>";
                    }
                    $text = str_replace("@$name@", $value, $text);
                    unset($args[$name]);
                }
            }
            // Si des variables n'ont pas ete inserees, le signaler
            // (chaines de langues pas a jour)
            if ($args) {
                spip_log("$f:  variables inutilisees " . join(', ', array_keys($args)), _LOG_DEBUG);
            }
        }

        if (($GLOBALS['test_i18n'] or (_request('var_mode') == 'traduction')) and is_null($options['class'])) {
            return "<span class=debug-traduction-erreur>$text</span>";
        } else {
            return $text;
        }
    }

    /**
     * Retourne un joli chemin de répertoire
     *
     * Pour afficher `ecrire/action/` au lieu de `action/` dans les messages
     * ou `tmp/` au lieu de `../tmp/`
     *
     * @param string $rep Chemin d’un répertoire
     * @return string
     */
    protected function joli_repertoire($rep)
    {
        $a = substr($rep, 0, 1);
        if ($a <> '.' and $a <> '/') {
            $rep = (_DIR_RESTREINT ? '' : _DIR_RESTREINT_ABS) . $rep;
        }
        $rep = preg_replace(',(^\.\.\/),', '', $rep);

        return $rep;
    }

    /**
     * Débute ou arrête un chronomètre et retourne sa valeur
     *
     * On exécute 2 fois la fonction, la première fois pour démarrer le chrono,
     * la seconde fois pour l’arrêter et récupérer la valeur
     *
     * @example
     *     ```
     *     spip_timer('papoter');
     *     // actions
     *     $duree = spip_timer('papoter');
     *     ```
     *
     * @param string $t
     *     Nom du chronomètre
     * @param bool $raw
     *     - false : retour en texte humainement lisible
     *     - true : retour en millisecondes
     * @return float|int|string|void
     */
    protected function spip_timer($t = 'rien', $raw = false)
    {
        static $time;
        $a = time();
        $b = microtime();
        // microtime peut contenir les microsecondes et le temps
        $b = explode(' ', $b);
        if (count($b) == 2) {
            $a = end($b);
        } // plus precis !
        $b = reset($b);
        if (!isset($time[$t])) {
            $time[$t] = $a + $b;
        } else {
            $p = ($a + $b - $time[$t]) * 1000;
            unset($time[$t]);
            #			echo "'$p'";exit;
            if ($raw) {
                return $p;
            }
            if ($p < 1000) {
                $s = '';
            } else {
                $s = sprintf("%d ", $x = floor($p / 1000));
                $p -= ($x * 1000);
            }

            return $s . sprintf($s ? "%07.3f ms" : "%.3f ms", $p);
        }
    }

    // Renvoie False si un fichier n'est pas plus vieux que $duree secondes,
    // sinon renvoie True et le date sauf si ca n'est pas souhaite
    // https://code.spip.net/@spip_touch
    protected function spip_touch($fichier, $duree = 0, $touch = true)
    {
        if ($duree) {
            clearstatcache();
            if ((@$f = filemtime($fichier)) and ($f >= time() - $duree)) {
                return false;
            }
        }
        if ($touch !== false) {
            if (!@touch($fichier)) {
                spip_unlink($fichier);
                @touch($fichier);
            }
            @chmod($fichier, _SPIP_CHMOD & ~0111);
        }

        return true;
    }

    /**
     * Action qui déclenche une tache de fond
     *
     * @see  queue_affichage_cron()
     * @see  action_super_cron_dist()
     * @uses cron()
     **/
    protected function action_cron(): void
    {
        include_spip('inc/headers');
        http_status(204); // No Content
        header("Connection: close");
        define('_DIRECT_CRON_FORCE', true);
        cron();
    }

    /**
     * Exécution des tâches de fond
     *
     * @uses inc_genie_dist()
     *
     * @param array $taches
     *     Tâches forcées
     * @param array $taches_old
     *     Tâches forcées, pour compat avec ancienne syntaxe
     * @return bool
     *     True si la tache a pu être effectuée
     */
    protected function cron($taches = [], $taches_old = [])
    {
        // si pas en mode cron force, laisser tomber.
        if (!defined('_DIRECT_CRON_FORCE')) {
            return false;
        }
        if (!is_array($taches)) {
            $taches = $taches_old;
        } // compat anciens appels
        // si taches a inserer en base et base inaccessible, laisser tomber
        // sinon on ne verifie pas la connexion tout de suite, car si ca se trouve
        // queue_sleep_time_to_next_job() dira qu'il n'y a rien a faire
        // et on evite d'ouvrir une connexion pour rien (utilisation de _DIRECT_CRON_FORCE dans mes_options.php)
        if ($taches and count($taches) and !spip_connect()) {
            return false;
        }
        spip_log("cron !", 'jq' . _LOG_DEBUG);
        if ($genie = charger_fonction('genie', 'inc', true)) {
            return $genie($taches);
        }

        return false;
    }

    /**
     * Ajout d'une tache dans la file d'attente
     *
     * @param string $function
     *     Le nom de la fonction PHP qui doit être appelée.
     * @param string $description
     *     Une description humainement compréhensible de ce que fait la tâche
     *     (essentiellement pour l’affichage dans la page de suivi de l’espace privé)
     * @param array $arguments
     *     Facultatif, vide par défaut : les arguments qui seront passés à la fonction, sous forme de tableau PHP
     * @param string $file
     *     Facultatif, vide par défaut : nom du fichier à inclure, via `include_spip($file)`
     *     exemple : `'inc/mail'` : il ne faut pas indiquer .php
     *     Si le nom finit par un '/' alors on considère que c’est un répertoire et SPIP fera un `charger_fonction($function, $file)`
     * @param bool $no_duplicate
     *     Facultatif, `false` par défaut
     *
     *     - si `true` la tâche ne sera pas ajoutée si elle existe déjà en file d’attente avec la même fonction et les mêmes arguments.
     *     - si `function_only` la tâche ne sera pas ajoutée si elle existe déjà en file d’attente avec la même fonction indépendamment de ses arguments
     * @param int $time
     *     Facultatif, `0` par défaut : indique la date sous forme de timestamp à laquelle la tâche doit être programmée.
     *     Si `0` ou une date passée, la tâche sera exécutée aussitôt que possible (en général en fin hit, en asynchrone).
     * @param int $priority
     *     Facultatif, `0` par défaut : indique un niveau de priorité entre -10 et +10.
     *     Les tâches sont exécutées par ordre de priorité décroissante, une fois leur date d’exécution passée. La priorité est surtout utilisée quand une tâche cron indique qu’elle n’a pas fini et doit être relancée : dans ce cas SPIP réduit sa priorité pour être sûr que celle tâche ne monopolise pas la file d’attente.
     * @return int
     *     Le numéro de travail ajouté ou `0` si aucun travail n’a été ajouté.
     */
    protected function job_queue_add(
        $function,
        $description,
        $arguments = [],
        $file = '',
        $no_duplicate = false,
        $time = 0,
        $priority = 0
    ) {
        include_spip('inc/queue');

        return queue_add_job($function, $description, $arguments, $file, $no_duplicate, $time, $priority);
    }

    /**
     * Supprimer une tache de la file d'attente
     *
     * @param int $id_job
     *  id of jonb to delete
     * @return bool
     */
    protected function job_queue_remove($id_job)
    {
        include_spip('inc/queue');

        return queue_remove_job($id_job);
    }

    /**
     * Associer une tache a un/des objets de SPIP
     *
     * @param int $id_job
     *     id of job to link
     * @param array $objets
     *     can be a simple array('objet'=>'article', 'id_objet'=>23)
     *     or an array of simple array to link multiples objet in one time
     */
    protected function job_queue_link($id_job, $objets)
    {
        include_spip('inc/queue');

        return queue_link_job($id_job, $objets);
    }

    /**
     * Renvoyer le temps de repos restant jusqu'au prochain job
     *
     * @staticvar int $queue_next_job_time
     * @see queue_set_next_job_time()
     * @param int|bool $force
     *    Utilisée par `queue_set_next_job_time()` pour mettre à jour la valeur :
     *
     *    - si `true`, force la relecture depuis le fichier
     *    - si int, affecte la static directement avec la valeur
     * @return int
     *
     *  - `0` si un job est à traiter
     *  - `null` si la queue n'est pas encore initialisée
     */
    protected function queue_sleep_time_to_next_job($force = null)
    {
        static $queue_next_job_time = -1;
        if ($force === true) {
            $queue_next_job_time = -1;
        } elseif ($force) {
            $queue_next_job_time = $force;
        }

        if ($queue_next_job_time == -1) {
            if (!defined('_JQ_NEXT_JOB_TIME_FILENAME')) {
                define('_JQ_NEXT_JOB_TIME_FILENAME', _DIR_TMP . "job_queue_next.txt");
            }
            // utiliser un cache memoire si dispo
            if (function_exists("cache_get") and defined('_MEMOIZE_MEMORY') and _MEMOIZE_MEMORY) {
                $queue_next_job_time = cache_get(_JQ_NEXT_JOB_TIME_FILENAME);
            } else {
                $queue_next_job_time = null;
                if (lire_fichier(_JQ_NEXT_JOB_TIME_FILENAME, $contenu)) {
                    $queue_next_job_time = intval($contenu);
                }
            }
        }

        if (is_null($queue_next_job_time)) {
            return null;
        }
        if (!$_SERVER['REQUEST_TIME']) {
            $_SERVER['REQUEST_TIME'] = time();
        }

        return $queue_next_job_time - $_SERVER['REQUEST_TIME'];
    }

    /**
     * Transformation XML des `&` en `&amp;`
     *
     * @pipeline post_typo
     * @param string $u
     * @return string
     */
    protected function quote_amp($u)
    {
        return preg_replace(
            "/&(?![a-z]{0,4}\w{2,3};|#x?[0-9a-f]{2,6};)/i",
            "&amp;", $u);
    }

    /**
     * Produit une balise `<script>` valide
     *
     * @example
     *     ```
     *     echo http_script('alert("ok");');
     *     echo http_script('','js/jquery.js');
     *     ```
     *
     * @param string $script
     *     Code source du script
     * @param string $src
     *     Permet de faire appel à un fichier javascript distant
     * @param string $noscript
     *     Contenu de la balise  `<noscript>`
     * @return string
     *     Balise HTML `<script>` et son contenu
     **/
    protected function http_script($script, $src = '', $noscript = '')
    {
        static $done = [];

        if ($src && !isset($done[$src])) {
            $done[$src] = true;
            $src = find_in_path($src, _JAVASCRIPT);
            $src = " src='$src'";
        } else {
            $src = '';
        }
        if ($script) {
            $script = ("/*<![CDATA[*/\n" .
                preg_replace(',</([^>]*)>,', '<\/\1>', $script) .
                "/*]]>*/");
        }
        if ($noscript) {
            $noscript = "<noscript>\n\t$noscript\n</noscript>\n";
        }

        return ($src or $script or $noscript)
            ? "<script type='text/javascript'$src>$script</script>$noscript"
            : '';
    }

    /**
     * Sécurise du texte à écrire dans du PHP ou du Javascript.
     *
     * Transforme n'importe quel texte en une chaîne utilisable
     * en PHP ou Javascript en toute sécurité, à l'intérieur d'apostrophes
     * simples (`'` uniquement ; pas `"`)
     *
     * Utile particulièrement en filtre dans un squelettes
     * pour écrire un contenu dans une variable JS ou PHP.
     *
     * Échappe les apostrophes (') du contenu transmis.
     *
     * @link https://www.spip.net/4281
     * @example
     *     PHP dans un squelette
     *     ```
     *     $x = '[(#TEXTE|texte_script)]';
     *     ```
     *
     *     JS dans un squelette (transmettre une chaîne de langue)
     *     ```
     *     $x = '<:afficher_calendrier|texte_script:>';
     *     ```
     *
     * @filtre
     * @param string $texte
     *     Texte à échapper
     * @return string
     *     Texte échappé
     **/
    protected function texte_script($texte)
    {
        return str_replace('\'', '\\\'', str_replace('\\', '\\\\', $texte));
    }

    /**
     * Gestion des chemins (ou path) de recherche de fichiers par SPIP
     *
     * Empile de nouveaux chemins (à la suite de ceux déjà présents, mais avant
     * le répertoire `squelettes` ou les dossiers squelettes), si un répertoire
     * (ou liste de répertoires séparés par `:`) lui est passé en paramètre.
     *
     * Ainsi, si l'argument est de la forme `dir1:dir2:dir3`, ces 3 chemins sont placés
     * en tête du path, dans cet ordre (hormis `squelettes` & la globale
     * `$dossier_squelette` si définie qui resteront devant)
     *
     * Retourne dans tous les cas la liste des chemins.
     *
     * @note
     *     Cette fonction est appelée à plusieurs endroits et crée une liste
     *     de chemins finale à peu près de la sorte :
     *
     *     - dossiers squelettes (si globale précisée)
     *     - squelettes/
     *     - plugins (en fonction de leurs dépendances) : ceux qui dépendent
     *       d'un plugin sont devant eux (ils peuvent surcharger leurs fichiers)
     *     - racine du site
     *     - squelettes-dist/
     *     - prive/
     *     - ecrire/
     *
     * @param string $dir_path
     *     - Répertoire(s) à empiler au path
     *     - '' provoque un recalcul des chemins.
     * @return array
     *     Liste des chemins, par ordre de priorité.
     **/
    protected function _chemin($dir_path = null)
    {
        static $path_base = null;
        static $path_full = null;
        if ($path_base == null) {
            // Chemin standard depuis l'espace public
            $path = defined('_SPIP_PATH') ? _SPIP_PATH :
            _DIR_RACINE . ':' .
            _DIR_RACINE . 'squelettes-dist/:' .
            _DIR_RACINE . 'prive/:' .
            _DIR_RESTREINT;
            // Ajouter squelettes/
            if (@is_dir(_DIR_RACINE . 'squelettes')) {
                $path = _DIR_RACINE . 'squelettes/:' . $path;
            }
            foreach (explode(':', $path) as $dir) {
                if (strlen($dir) and substr($dir, -1) != '/') {
                    $dir .= "/";
                }
                $path_base[] = $dir;
            }
            $path_full = $path_base;
            // Et le(s) dossier(s) des squelettes nommes
            if (strlen($GLOBALS['dossier_squelettes'])) {
                foreach (array_reverse(explode(':', $GLOBALS['dossier_squelettes'])) as $d) {
                    array_unshift($path_full, ($d[0] == '/' ? '' : _DIR_RACINE) . $d . '/');
                }
            }
            $GLOBALS['path_sig'] = md5(serialize($path_full));
        }
        if ($dir_path === null) {
            return $path_full;
        }

        if (strlen($dir_path)) {
            $tete = "";
            if (reset($path_base) == _DIR_RACINE . 'squelettes/') {
                $tete = array_shift($path_base);
            }
            $dirs = array_reverse(explode(':', $dir_path));
            foreach ($dirs as $dir_path) {
                #if ($dir_path{0}!='/')
                #	$dir_path = $dir_path;
                if (substr($dir_path, -1) != '/') {
                    $dir_path .= "/";
                }
                if (!in_array($dir_path, $path_base)) {
                    array_unshift($path_base, $dir_path);
                }
            }
            if (strlen($tete)) {
                array_unshift($path_base, $tete);
            }
        }
        $path_full = $path_base;
        // Et le(s) dossier(s) des squelettes nommes
        if (strlen($GLOBALS['dossier_squelettes'])) {
            foreach (array_reverse(explode(':', $GLOBALS['dossier_squelettes'])) as $d) {
                array_unshift($path_full, ((isset($d[0]) and $d[0] == '/') ? '' : _DIR_RACINE) . $d . '/');
            }
        }

        $GLOBALS['path_sig'] = md5(serialize($path_full));

        return $path_full;
    }

    /**
     * Retourne la liste des chemins connus de SPIP, dans l'ordre de priorité
     *
     * Recalcule la liste si le nom ou liste de dossier squelettes a changé.
     *
     * @uses _chemin()
     *
     * @return array Liste de chemins
     **/
    protected function creer_chemin()
    {
        $path_a = _chemin();
        static $c = '';

        // on calcule le chemin si le dossier skel a change
        if ($c != $GLOBALS['dossier_squelettes']) {
            // assurer le non plantage lors de la montee de version :
            $c = $GLOBALS['dossier_squelettes'];
            $path_a = _chemin(''); // forcer un recalcul du chemin
        }

        return $path_a;
    }

    protected function lister_themes_prives()
    {
        static $themes = null;
        if (is_null($themes)) {
            // si pas encore definie
            if (!defined('_SPIP_THEME_PRIVE')) {
                define('_SPIP_THEME_PRIVE', 'spip');
            }
            $themes = [_SPIP_THEME_PRIVE];
            // lors d'une installation neuve, prefs n'est pas definie.
            if (isset($GLOBALS['visiteur_session']['prefs'])) {
                $prefs = $GLOBALS['visiteur_session']['prefs'];
            } else {
                $prefs = [];
            }
            if (is_string($prefs)) {
                $prefs = unserialize($GLOBALS['visiteur_session']['prefs']);
            }
            if (
                ((isset($prefs['theme']) and $theme = $prefs['theme'])
                    or (isset($GLOBALS['theme_prive_defaut']) and $theme = $GLOBALS['theme_prive_defaut']))
                and $theme != _SPIP_THEME_PRIVE
            ) {
                array_unshift($themes, $theme);
            } // placer le theme choisi en tete
        }

        return $themes;
    }

    protected function find_in_theme($file, $subdir = '', $include = false)
    {
        static $themefiles = [];
        if (isset($themefiles["$subdir$file"])) {
            return $themefiles["$subdir$file"];
        }
        // on peut fournir une icone generique -xx.svg qui fera le job dans toutes les tailles, et qui est prioritaire sur le png
        // si il y a un .svg a la bonne taille (-16.svg) a cote, on l'utilise en remplacement du -16.png
        if (preg_match(',-(\d+)[.](png|gif|svg)$,', $file, $m)
            and $file_svg_generique = substr($file, 0, -strlen($m[0])) . "-xx.svg"
            and $f = find_in_theme("$file_svg_generique")) {
            if ($fsize = substr($f, 0, -6) . $m[1] . ".svg" and file_exists($fsize)) {
                return $themefiles["$subdir$file"] = $fsize;
            } else {
                return $themefiles["$subdir$file"] = "$f?" . $m[1] . "px";
            }
        }

        $themes = lister_themes_prives();
        foreach ($themes as $theme) {
            if ($f = find_in_path($file, "prive/themes/$theme/$subdir", $include)) {
                return $themefiles["$subdir$file"] = $f;
            }
        }
        spip_log("$file introuvable dans le theme prive " . reset($themes), 'theme');

        return $themefiles["$subdir$file"] = "";
    }

    /**
     * Cherche une image dans les dossiers d'images
     *
     * Cherche en priorité dans les thèmes d'image (prive/themes/X/images)
     * et si la fonction n'en trouve pas, gère le renommage des icones (ex: 'supprimer' => 'del')
     * de facon temporaire le temps de la migration, et cherche de nouveau.
     *
     * Si l'image n'est toujours pas trouvée, on la cherche dans les chemins,
     * dans le répertoire défini par la constante `_NOM_IMG_PACK`
     *
     * @see find_in_theme()
     * @see inc_icone_renommer_dist()
     *
     * @param string $icone
     *     Nom de l'icone cherchée
     * @return string
     *     Chemin complet de l'icone depuis la racine si l'icone est trouée,
     *     sinon chaîne vide.
     **/
    protected function chemin_image($icone)
    {
        static $icone_renommer;
        if ($p = strpos($icone, '?')) {
            $icone = substr($icone, 0, $p);
        }
        // gerer le cas d'un double appel en evitant de refaire le travail inutilement
        if (strpos($icone, "/") !== false and file_exists($icone)) {
            return $icone;
        }

        // si c'est un nom d'image complet (article-24.png) essayer de le renvoyer direct
        if (preg_match(',[.](png|gif|jpg|svg)$,', $icone) and $f = find_in_theme("images/$icone")) {
            return $f;
        }
        // sinon passer par le module de renommage
        if (is_null($icone_renommer)) {
            $icone_renommer = charger_fonction('icone_renommer', 'inc', true);
        }
        if ($icone_renommer) {
            list($icone, $fonction) = $icone_renommer($icone, "");
            if (file_exists($icone)) {
                return $icone;
            }
        }

        return find_in_path($icone, _NOM_IMG_PACK);
    }

    //
    // chercher un fichier $file dans le SPIP_PATH
    // si on donne un sous-repertoire en 2e arg optionnel, il FAUT le / final
    // si 3e arg vrai, on inclut si ce n'est fait.
    $GLOBALS['path_sig'] = '';
    $GLOBALS['path_files'] = null;

    /**
     * Recherche un fichier dans les chemins de SPIP (squelettes, plugins, core)
     *
     * Retournera le premier fichier trouvé (ayant la plus haute priorité donc),
     * suivant l'ordre des chemins connus de SPIP.
     *
     * @api
     * @see  charger_fonction()
     * @uses creer_chemin() Pour la liste des chemins.
     * @example
     *     ```
     *     $f = find_in_path('css/perso.css');
     *     $f = find_in_path('perso.css', 'css');
     *     ```
     *
     * @param string $file
     *     Fichier recherché
     * @param string $dirname
     *     Répertoire éventuel de recherche (est aussi extrait automatiquement de $file)
     * @param bool|string $include
     *     - false : ne fait rien de plus
     *     - true : inclut le fichier (include_once)
     *     - 'require' : idem, mais tue le script avec une erreur si le fichier n'est pas trouvé.
     * @return string|bool
     *     - string : chemin du fichier trouvé
     *     - false : fichier introuvable
     **/
    protected function find_in_path($file, $dirname = '', $include = false)
    {
        static $dirs = [];
        static $inc = []; # cf https://git.spip.net/spip/spip/commit/42e4e028e38c839121efaee84308d08aee307eec
        static $c = '';

        if (!$file and !strlen($file)) {
            return false;
        }

        // on calcule le chemin si le dossier skel a change
        if ($c != $GLOBALS['dossier_squelettes']) {
            // assurer le non plantage lors de la montee de version :
            $c = $GLOBALS['dossier_squelettes'];
            creer_chemin(); // forcer un recalcul du chemin et la mise a jour de path_sig
        }

        if (isset($GLOBALS['path_files'][$GLOBALS['path_sig']][$dirname][$file])) {
            if (!$GLOBALS['path_files'][$GLOBALS['path_sig']][$dirname][$file]) {
                return false;
            }
            if ($include and !isset($inc[$dirname][$file])) {
                include_once _ROOT_CWD . $GLOBALS['path_files'][$GLOBALS['path_sig']][$dirname][$file];
                $inc[$dirname][$file] = $inc[''][$dirname . $file] = true;
            }

            return $GLOBALS['path_files'][$GLOBALS['path_sig']][$dirname][$file];
        }

        $a = strrpos($file, '/');
        if ($a !== false) {
            $dirname .= substr($file, 0, ++$a);
            $file = substr($file, $a);
        }

        foreach (creer_chemin() as $dir) {
            if (!isset($dirs[$a = $dir . $dirname])) {
                $dirs[$a] = (is_dir(_ROOT_CWD . $a) || !$a);
            }
            if ($dirs[$a]) {
                if (file_exists(_ROOT_CWD . ($a .= $file))) {
                    if ($include and !isset($inc[$dirname][$file])) {
                        include_once _ROOT_CWD . $a;
                        $inc[$dirname][$file] = $inc[''][$dirname . $file] = true;
                    }
                    if (!defined('_SAUVER_CHEMIN')) {
                        // si le chemin n'a pas encore ete charge, ne pas lever le flag, ne pas cacher
                        if (is_null($GLOBALS['path_files'])) {
                            return $a;
                        }
                        define('_SAUVER_CHEMIN', true);
                    }

                    return $GLOBALS['path_files'][$GLOBALS['path_sig']][$dirname][$file] = $GLOBALS['path_files'][$GLOBALS['path_sig']][''][$dirname . $file] = $a;
                }
            }
        }

        if ($include) {
            spip_log("include_spip $dirname$file non trouve");
            if ($include === 'required') {
                echo '<pre>',
                "<strong>Erreur Fatale</strong><br />";
                if (function_exists('debug_print_backtrace')) {
                    echo debug_print_backtrace();
                }
                echo '</pre>';
                die("Erreur interne: ne peut inclure $dirname$file");
            }
        }

        if (!defined('_SAUVER_CHEMIN')) {
            // si le chemin n'a pas encore ete charge, ne pas lever le flag, ne pas cacher
            if (is_null($GLOBALS['path_files'])) {
                return false;
            }
            define('_SAUVER_CHEMIN', true);
        }

        return $GLOBALS['path_files'][$GLOBALS['path_sig']][$dirname][$file] = $GLOBALS['path_files'][$GLOBALS['path_sig']][''][$dirname . $file] = false;
    }

    protected function clear_path_cache(): void
    {
        $GLOBALS['path_files'] = [];
        spip_unlink(_CACHE_CHEMIN);
    }

    protected function load_path_cache(): void
    {
        // charger le path des plugins
        if (@is_readable(_CACHE_PLUGINS_PATH)) {
            include_once _CACHE_PLUGINS_PATH;
        }
        $GLOBALS['path_files'] = [];
        // si le visiteur est admin,
        // on ne recharge pas le cache pour forcer sa mise a jour
        if (
            // la session n'est pas encore chargee a ce moment, on ne peut donc pas s'y fier
            //AND (!isset($GLOBALS['visiteur_session']['statut']) OR $GLOBALS['visiteur_session']['statut']!='0minirezo')
            // utiliser le cookie est un pis aller qui marche 'en general'
            // on blinde par un second test au moment de la lecture de la session
            // !isset($_COOKIE[$GLOBALS['cookie_prefix'].'_admin'])
            // et en ignorant ce cache en cas de recalcul explicite
            !_request('var_mode')
        ) {
            // on essaye de lire directement sans verrou pour aller plus vite
            if ($contenu = spip_file_get_contents(_CACHE_CHEMIN)) {
                // mais si semble corrompu on relit avec un verrou
                if (!$GLOBALS['path_files'] = unserialize($contenu)) {
                    lire_fichier(_CACHE_CHEMIN, $contenu);
                    if (!$GLOBALS['path_files'] = unserialize($contenu)) {
                        $GLOBALS['path_files'] = [];
                    }
                }
            }
        }
    }

    protected function save_path_cache(): void
    {
        if (defined('_SAUVER_CHEMIN')
            and _SAUVER_CHEMIN
        ) {
            ecrire_fichier(_CACHE_CHEMIN, serialize($GLOBALS['path_files']));
        }
    }

    /**
     * Trouve tous les fichiers du path correspondants à un pattern
     *
     * Pour un nom de fichier donné, ne retourne que le premier qui sera trouvé
     * par un `find_in_path()`
     *
     * @api
     * @uses creer_chemin()
     * @uses preg_files()
     *
     * @param string $dir
     * @param string $pattern
     * @param bool $recurs
     * @return array
     */
    protected function find_all_in_path($dir, $pattern, $recurs = false)
    {
        $liste_fichiers = [];
        $maxfiles = 10000;

        // cas borderline si dans mes_options on appelle redirige_par_entete qui utilise _T et charge un fichier de langue
        // on a pas encore inclus flock.php
        if (!function_exists('preg_files')) {
            include_once _ROOT_RESTREINT . 'inc/flock.php';
        }

        // Parcourir le chemin
        foreach (creer_chemin() as $d) {
            $f = $d . $dir;
            if (@is_dir($f)) {
                $liste = preg_files($f, $pattern, $maxfiles - count($liste_fichiers), $recurs === true ? [] : $recurs);
                foreach ($liste as $chemin) {
                    $nom = basename($chemin);
                    // ne prendre que les fichiers pas deja trouves
                    // car find_in_path prend le premier qu'il trouve,
                    // les autres sont donc masques
                    if (!isset($liste_fichiers[$nom])) {
                        $liste_fichiers[$nom] = $chemin;
                    }
                }
            }
        }

        return $liste_fichiers;
    }

    /**
     * Prédicat sur les scripts de ecrire qui n'authentifient pas par cookie
     * et beneficient d'une exception
     *
     * @param string $nom
     * @param bool $strict
     * @return bool
     */
    protected function autoriser_sans_cookie($nom, $strict = false)
    {
        static $autsanscookie = ['install', 'base_repair'];

        if (in_array($nom, $autsanscookie)) {
            if (test_espace_prive()) {
                include_spip('base/connect_sql');
                if (!$strict or !spip_connect()) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Fonction codant et décodant les URLs des objets SQL mis en page par SPIP
     *
     * @api
     * @param string $id
     *   numero de la cle primaire si nombre, URL a decoder si pas numerique
     * @param string $entite
     *   surnom de la table SQL (donne acces au nom de cle primaire)
     * @param string $args
     *   query_string a placer apres cle=$id&....
     * @param string $ancre
     *   ancre a mettre a la fin de l'URL a produire
     * @param bool|string $public
     *   produire l'URL publique ou privee (par defaut: selon espace)
     *   si string : serveur de base de donnee (nom du connect)
     * @param string $type
     *   fichier dans le repertoire ecrire/urls determinant l'apparence
     * @return string|array
     *   url codee ou fonction de decodage
     *   array : derogatoire, la fonction d'url retourne (objet,id_objet) utilises par nettoyer_raccourcis_typo() pour generer un lien titre
     *           (cas des raccourcis personalises [->spip20] : il faut implementer une fonction generer_url_spip et une fonction generer_url_ecrire_spip)
     */
    protected function generer_url_entite($id = '', $entite = '', $args = '', $ancre = '', $public = null, $type = null)
    {
        if ($public === null) {
            $public = !test_espace_prive();
        }
        $entite = objet_type($entite); // cas particulier d'appels sur objet/id_objet...

        if (!$public) {
            if (!$entite) {
                return '';
            }
            if (!function_exists('generer_url_ecrire_objet')) {
                include_spip('inc/urls');
            }
            $res = generer_url_ecrire_objet($entite, $id, $args, $ancre, false);
        } else {
            if ($type === null) {
                $type = (isset($GLOBALS['type_urls']))
                ? $GLOBALS['type_urls'] // pour surcharge via fichier d'options
                : ((isset($GLOBALS['meta']['type_urls'])) // sinon la config url_etendues
                    ? ($GLOBALS['meta']['type_urls']) : "page"); // sinon type "page" par défaut
            }

            $f = charger_fonction($type, 'urls', true);
            // se rabattre sur les urls page si les urls perso non dispo
            if (!$f) {
                $f = charger_fonction('page', 'urls', true);
            }

            // si $entite='', on veut la fonction de passage URL ==> id
            // sinon on veut effectuer le passage id ==> URL
            if (!$entite) {
                return $f;
            }

            // mais d'abord il faut tester le cas des urls sur une
            // base distante
            if (is_string($public)
                and $g = charger_fonction('connect', 'urls', true)
            ) {
                $f = $g;
            }

            $res = $f(intval($id), $entite, $args, $ancre, $public);
        }
        if ($res) {
            return $res;
        }
        // Sinon c'est un raccourci ou compat SPIP < 2
        if (!function_exists($f = 'generer_url_' . $entite)) {
            if (!function_exists($f .= '_dist')) {
                $f = '';
            }
        }
        if ($f) {
            $url = $f($id, $args, $ancre);
            if (strlen($args)) {
                $url .= strstr($url, '?')
                ? '&amp;' . $args
                : '?' . $args;
            }

            return $url;
        }
        // On a ete gentil mais la ....
        spip_log("generer_url_entite: entite $entite ($f) inconnue $type $public");

        return '';
    }

    protected function generer_url_ecrire_entite_edit($id, $entite, $args = '', $ancre = '')
    {
        $exec = objet_info($entite, 'url_edit');
        $url = generer_url_ecrire($exec, $args);
        if (intval($id)) {
            $url = parametre_url($url, id_table_objet($entite), $id);
        } else {
            $url = parametre_url($url, 'new', 'oui');
        }
        if ($ancre) {
            $url = ancre_url($url, $ancre);
        }

        return $url;
    }

    // https://code.spip.net/@urls_connect_dist
    protected function urls_connect_dist($i, &$entite, $args = '', $ancre = '', $public = null)
    {
        include_spip('base/connect_sql');
        $id_type = id_table_objet($entite, $public);

        return _DIR_RACINE . get_spip_script('./')
        . "?" . _SPIP_PAGE . "=$entite&$id_type=$i&connect=$public"
        . (!$args ? '' : "&$args")
        . (!$ancre ? '' : "#$ancre");
    }

    /**
     * Transformer les caractères utf8 d'une URL (farsi par exemple) selon la RFC 1738
     *
     * @param string $url
     * @return string
     */
    protected function urlencode_1738($url)
    {
        if (preg_match(',[^\x00-\x7E],sS', $url)) {
            $uri = '';
            for ($i = 0; $i < strlen($url); $i++) {
                if (ord($a = $url[$i]) > 127) {
                    $a = rawurlencode($a);
                }
                $uri .= $a;
            }
            $url = $uri;
        }

        return quote_amp($url);
    }

    // https://code.spip.net/@generer_url_entite_absolue
    protected function generer_url_entite_absolue($id = '', $entite = '', $args = '', $ancre = '', $connect = null)
    {
        if (!$connect) {
            $connect = true;
        }
        $h = generer_url_entite($id, $entite, $args, $ancre, $connect);
        if (!preg_match(',^\w+:,', $h)) {
            include_spip('inc/filtres_mini');
            $h = url_absolue($h);
        }

        return $h;
    }

    /**
     * Tester qu'une variable d'environnement est active
     *
     * Sur certains serveurs, la valeur 'Off' tient lieu de false dans certaines
     * variables d'environnement comme `$_SERVER['HTTPS']` ou `ini_get('display_errors')`
     *
     * @param string|bool $truc
     *     La valeur de la variable d'environnement
     * @return bool
     *     true si la valeur est considérée active ; false sinon.
     **/
    protected function test_valeur_serveur($truc)
    {
        if (!$truc) {
            return false;
        }

        return (strtolower($truc) !== 'off');
    }

    //
    // Fonctions de fabrication des URL des scripts de Spip
    //
    /**
     * Calcule l'url de base du site
     *
     * Calcule l'URL de base du site, en priorité sans se fier à la méta (adresse_site) qui
     * peut être fausse (sites avec plusieurs noms d’hôtes, déplacements, erreurs).
     * En dernier recours, lorsqu'on ne trouve rien, on utilise adresse_site comme fallback.
     *
     * @note
     *     La globale `$profondeur_url` doit être initialisée de manière à
     *     indiquer le nombre de sous-répertoires de l'url courante par rapport à la
     *     racine de SPIP : par exemple, sur ecrire/ elle vaut 1, sur sedna/ 1, et à
     *     la racine 0. Sur url/perso/ elle vaut 2
     *
     * @param int|bool|array $profondeur
     *    - si non renseignée : retourne l'url pour la profondeur $GLOBALS['profondeur_url']
     *    - si int : indique que l'on veut l'url pour la profondeur indiquée
     *    - si bool : retourne le tableau static complet
     *    - si array : réinitialise le tableau static complet avec la valeur fournie
     * @return string|array
     */
    protected function url_de_base($profondeur = null)
    {
        static $url = [];
        if (is_array($profondeur)) {
            return $url = $profondeur;
        }
        if ($profondeur === false) {
            return $url;
        }

        if (is_null($profondeur)) {
            $profondeur = $GLOBALS['profondeur_url'];
        }

        if (isset($url[$profondeur])) {
            return $url[$profondeur];
        }

        $http = 'http';

        if (
            isset($_SERVER["SCRIPT_URI"])
            and substr($_SERVER["SCRIPT_URI"], 0, 5) == 'https'
        ) {
            $http = 'https';
        } elseif (
            isset($_SERVER['HTTPS'])
            and test_valeur_serveur($_SERVER['HTTPS'])
            ) {
            $http = 'https';
        }

        // note : HTTP_HOST contient le :port si necessaire
        $host = $_SERVER['HTTP_HOST'] ?? null;
        // si on n'a pas trouvé d'hôte du tout, en dernier recours on utilise adresse_site comme fallback
        if (is_null($host) and isset($GLOBALS['meta']['adresse_site'])) {
            $host = $GLOBALS['meta']['adresse_site'];
            if ($scheme = parse_url($host, PHP_URL_SCHEME)) {
                $http = $scheme;
                $host = str_replace("{$scheme}://", '', $host);
            }
        }
        if (isset($_SERVER['SERVER_PORT'])
            and $port = $_SERVER['SERVER_PORT']
            and strpos($host, ":") == false
        ) {
            if (!defined('_PORT_HTTP_STANDARD')) {
                define('_PORT_HTTP_STANDARD', '80');
            }
            if (!defined('_PORT_HTTPS_STANDARD')) {
                define('_PORT_HTTPS_STANDARD', '443');
            }
            if ($http == "http" and !in_array($port, explode(',', _PORT_HTTP_STANDARD))) {
                $host .= ":$port";
            }
            if ($http == "https" and !in_array($port, explode(',', _PORT_HTTPS_STANDARD))) {
                $host .= ":$port";
            }
        }

        if (!$GLOBALS['REQUEST_URI']) {
            if (isset($_SERVER['REQUEST_URI'])) {
                $GLOBALS['REQUEST_URI'] = $_SERVER['REQUEST_URI'];
            } else {
                $GLOBALS['REQUEST_URI'] = (php_sapi_name() !== 'cli') ? $_SERVER['PHP_SELF'] : '';
                if (!empty($_SERVER['QUERY_STRING'])
                    and !strpos($_SERVER['REQUEST_URI'], '?')
                    ) {
                    $GLOBALS['REQUEST_URI'] .= '?' . $_SERVER['QUERY_STRING'];
                }
            }
        }

        $url[$profondeur] = url_de_($http, $host, $GLOBALS['REQUEST_URI'], $profondeur);

        return $url[$profondeur];
    }

    /**
     * fonction testable de construction d'une url appelee par url_de_base()
     *
     * @param string $http
     * @param string $host
     * @param string $request
     * @param int $prof
     * @return string
     */
    protected function url_de_($http, $host, $request, $prof = 0)
    {
        $prof = max($prof, 0);

        $myself = ltrim($request, '/');
        # supprimer la chaine de GET
        list($myself) = explode('?', $myself);
        // vieux mode HTTP qui envoie après le nom de la methode l'URL compléte
        // protocole, "://", nom du serveur avant le path dans _SERVER["REQUEST_URI"]
        if (strpos($myself, '://') !== false) {
            $myself = explode('://', $myself);
            array_shift($myself);
            $myself = implode('://', $myself);
            $myself = explode('/', $myself);
            array_shift($myself);
            $myself = implode('/', $myself);
        }
        $url = join('/', array_slice(explode('/', $myself), 0, -1 - $prof)) . '/';

        $url = $http . '://' . rtrim($host, '/') . '/' . ltrim($url, '/');

        return $url;
    }

    // Pour une redirection, la liste des arguments doit etre separee par "&"
    // Pour du code XHTML, ca doit etre &amp;
    // Bravo au W3C qui n'a pas ete capable de nous eviter ca
    // faute de separer proprement langage et meta-langage

    // Attention, X?y=z et "X/?y=z" sont completement differents!
    // http://httpd.apache.org/docs/2.0/mod/mod_dir.html

    /**
     * Crée une URL vers un script de l'espace privé
     *
     * @example
     *     ```
     *     generer_url_ecrire('admin_plugin')
     *     ```
     *
     * @param string $script
     *     Nom de la page privée (xx dans exec=xx)
     * @param string $args
     *     Arguments à transmettre, tel que `arg1=yy&arg2=zz`
     * @param bool $no_entities
     *     Si false : transforme les `&` en `&amp;`
     * @param bool|string $rel
     *     URL relative ?
     *
     *     - false : l’URL sera complète et contiendra l’URL du site
     *     - true : l’URL sera relavive.
     *     - string : on transmet l'url à la fonction
     * @return string URL
     **/
    protected function generer_url_ecrire($script = '', $args = "", $no_entities = false, $rel = false)
    {
        if (!$rel) {
            $rel = url_de_base() . _DIR_RESTREINT_ABS . _SPIP_ECRIRE_SCRIPT;
        } else {
            if (!is_string($rel)) {
                $rel = _DIR_RESTREINT ? _DIR_RESTREINT :
                ('./' . _SPIP_ECRIRE_SCRIPT);
            }
        }

        list($script, $ancre) = array_pad(explode('#', $script), 2, null);
        if ($script and ($script <> 'accueil' or $rel)) {
            $args = "?exec=$script" . (!$args ? '' : "&$args");
        } elseif ($args) {
            $args = "?$args";
        }
        if ($ancre) {
            $args .= "#$ancre";
        }

        return $rel . ($no_entities ? $args : str_replace('&', '&amp;', $args));
    }

    //
    // Adresse des scripts publics (a passer dans inc-urls...)
    //

    /**
     * Retourne le nom du fichier d'exécution de SPIP
     *
     * @see _SPIP_SCRIPT
     * @note
     *   Detecter le fichier de base, a la racine, comme etant spip.php ou ''
     *   dans le cas de '', un $default = './' peut servir (comme dans urls/page.php)
     *
     * @param string $default
     *     Script par défaut
     * @return string
     *     Nom du fichier (constante _SPIP_SCRIPT), sinon nom par défaut
     **/
    protected function get_spip_script($default = '')
    {
        # cas define('_SPIP_SCRIPT', '');
        if (_SPIP_SCRIPT) {
            return _SPIP_SCRIPT;
        } else {
            return $default;
        }
    }

    /**
     * Crée une URL vers une page publique de SPIP
     *
     * @example
     *     ```
     *     generer_url_public("rubrique","id_rubrique=$id_rubrique")
     *     ```
     *
     * @param string $script
     *     Nom de la page
     * @param string|array $args
     *     Arguments à transmettre a l'URL,
     *      soit sous la forme d'un string tel que `arg1=yy&arg2=zz`
     *      soit sous la forme d'un array tel que array( `arg1` => `yy`, `arg2` => `zz` )
     * @param bool $no_entities
     *     Si false : transforme les `&` en `&amp;`
     * @param bool $rel
     *     URL relative ?
     *
     *     - false : l’URL sera complète et contiendra l’URL du site
     *     - true : l’URL sera relavive.
     * @param string $action
     *     - Fichier d'exécution public (spip.php par défaut)
     * @return string URL
     **/
    protected function generer_url_public($script = '', $args = "", $no_entities = false, $rel = true, $action = '')
    {
        // si le script est une action (spip_pass, spip_inscription),
        // standardiser vers la nouvelle API

        if (!$action) {
            $action = get_spip_script();
        }
        if ($script) {
            $action = parametre_url($action, _SPIP_PAGE, $script, '&');
        }

        if ($args) {
            if (is_array($args)) {
                $r = '';
                foreach ($args as $k => $v) {
                    $r .= '&' . $k . '=' . $v;
                }
                $args = substr($r, 1);
            }
            $action .=
            (strpos($action, '?') !== false ? '&' : '?') . $args;
        }
        if (!$no_entities) {
            $action = quote_amp($action);
        }

        // ne pas generer une url avec /./?page= en cas d'url absolue et de _SPIP_SCRIPT vide
        return ($rel ? _DIR_RACINE . $action : rtrim(url_de_base(), '/') . preg_replace(",^/[.]/,", "/", "/$action"));
    }

    // https://code.spip.net/@generer_url_prive
    protected function generer_url_prive($script, $args = "", $no_entities = false)
    {
        return generer_url_public($script, $args, $no_entities, false, _DIR_RESTREINT_ABS . 'prive.php');
    }

    // Pour les formulaires en methode POST,
    // mettre le nom du script a la fois en input-hidden et dans le champ action:
    // 1) on peut ainsi memoriser le signet comme si c'etait un GET
    // 2) ca suit http://en.wikipedia.org/wiki/Representational_State_Transfer

    /**
     * Retourne un formulaire (POST par défaut) vers un script exec
     * de l’interface privée
     *
     * @param string $script
     *     Nom de la page exec
     * @param string $corps
     *     Contenu du formulaire
     * @param string $atts
     *     Si présent, remplace les arguments par défaut (method=post) par ceux indiqués
     * @param string $submit
     *     Si indiqué, un bouton de soumission est créé avec texte sa valeur.
     * @return string
     *     Code HTML du formulaire
     **/
    protected function generer_form_ecrire($script, $corps, $atts = '', $submit = '')
    {
        $script1 = explode('&', $script);
        $script1 = reset($script1);

        return "<form action='"
            . ($script ? generer_url_ecrire($script) : '')
            . "' "
                . ($atts ? $atts : " method='post'")
                . "><div>\n"
                    . "<input type='hidden' name='exec' value='$script1' />"
                    . $corps
                    . (!$submit ? '' :
                        ("<div style='text-align: " . $GLOBALS['spip_lang_right'] . "'><input class='fondo' type='submit' value=\"" . entites_html($submit) . "\" /></div>"))
                        . "</div></form>\n";
    }

    /**
     * Générer un formulaire pour lancer une action vers $script
     *
     * Attention, JS/Ajax n'aime pas le melange de param GET/POST
     * On n'applique pas la recommandation ci-dessus pour les scripts publics
     * qui ne sont pas destines a etre mis en signets
     *
     * @param string $script
     * @param string $corps
     * @param string $atts
     * @param bool $public
     * @return string
     */
    protected function generer_form_action($script, $corps, $atts = '', $public = false)
    {
        // si l'on est dans l'espace prive, on garde dans l'url
        // l'exec a l'origine de l'action, qui permet de savoir si il est necessaire
        // ou non de proceder a l'authentification (cas typique de l'install par exemple)
        $h = (_DIR_RACINE and !$public)
        ? generer_url_ecrire(_request('exec'))
        : generer_url_public();

        return "\n<form action='" .
            $h .
            "'" .
            $atts .
            ">\n" .
            "<div>" .
            "\n<input type='hidden' name='action' value='$script' />" .
            $corps .
            "</div></form>";
    }

    /**
     * Créer une URL
     *
     * @param  string $script
     *     Nom du script à exécuter
     * @param  string $args
     *     Arguments à transmettre a l'URL sous la forme `arg1=yy&arg2=zz`
     * @param bool $no_entities
     *     Si false : transforme les & en &amp;
     * @param bool $public
     *     URL relative ? false : l’URL sera complète et contiendra l’URL du site.
     *     true : l’URL sera relative.
     * @return string
     *     URL
     */
    protected function generer_url_action($script, $args = "", $no_entities = false, $public = false)
    {
        // si l'on est dans l'espace prive, on garde dans l'url
        // l'exec a l'origine de l'action, qui permet de savoir si il est necessaire
        // ou non de proceder a l'authentification (cas typique de l'install par exemple)
        $url = (_DIR_RACINE and !$public)
        ? generer_url_ecrire(_request('exec'))
        : generer_url_public('', '', false, false);
        $url = parametre_url($url, 'action', $script);
        if ($args) {
            $url .= quote_amp('&' . $args);
        }

        if ($no_entities) {
            $url = str_replace('&amp;', '&', $url);
        }

        return $url;
    }

    /**
     * Fonction d'initialisation groupée pour compatibilité ascendante
     *
     * @param string $pi Répertoire permanent inaccessible
     * @param string $pa Répertoire permanent accessible
     * @param string $ti Répertoire temporaire inaccessible
     * @param string $ta Répertoire temporaire accessible
     */
    protected function spip_initialisation($pi = null, $pa = null, $ti = null, $ta = null): void
    {
        spip_initialisation_core($pi, $pa, $ti, $ta);
        spip_initialisation_suite();
    }

    /**
     * Fonction d'initialisation, appellée dans inc_version ou mes_options
     *
     * Elle définit les répertoires et fichiers non partageables
     * et indique dans $test_dirs ceux devant être accessibles en écriture
     * mais ne touche pas à cette variable si elle est déjà définie
     * afin que mes_options.php puisse en spécifier d'autres.
     *
     * Elle définit ensuite les noms des fichiers et les droits.
     * Puis simule un register_global=on sécurisé.
     *
     * @param string $pi Répertoire permanent inaccessible
     * @param string $pa Répertoire permanent accessible
     * @param string $ti Répertoire temporaire inaccessible
     * @param string $ta Répertoire temporaire accessible
     */
    protected function spip_initialisation_core($pi = null, $pa = null, $ti = null, $ta = null): void
    {
        static $too_late = 0;
        if ($too_late++) {
            return;
        }

        // Declaration des repertoires

        // le nom du repertoire plugins/ activables/desactivables
        if (!defined('_DIR_PLUGINS')) {
            define('_DIR_PLUGINS', _DIR_RACINE . "plugins/");
        }

        // le nom du repertoire des extensions/ permanentes du core, toujours actives
        if (!defined('_DIR_PLUGINS_DIST')) {
            define('_DIR_PLUGINS_DIST', _DIR_RACINE . "plugins-dist/");
        }

        // le nom du repertoire des librairies
        if (!defined('_DIR_LIB')) {
            define('_DIR_LIB', _DIR_RACINE . "lib/");
        }

        if (!defined('_DIR_IMG')) {
            define('_DIR_IMG', $pa);
        }
        if (!defined('_DIR_LOGOS')) {
            define('_DIR_LOGOS', $pa);
        }
        if (!defined('_DIR_IMG_ICONES')) {
            define('_DIR_IMG_ICONES', _DIR_LOGOS . "icones/");
        }

        if (!defined('_DIR_DUMP')) {
            define('_DIR_DUMP', $ti . "dump/");
        }
        if (!defined('_DIR_SESSIONS')) {
            define('_DIR_SESSIONS', $ti . "sessions/");
        }
        if (!defined('_DIR_TRANSFERT')) {
            define('_DIR_TRANSFERT', $ti . "upload/");
        }
        if (!defined('_DIR_CACHE')) {
            define('_DIR_CACHE', $ti . "cache/");
        }
        if (!defined('_DIR_CACHE_XML')) {
            define('_DIR_CACHE_XML', _DIR_CACHE . "xml/");
        }
        if (!defined('_DIR_SKELS')) {
            define('_DIR_SKELS', _DIR_CACHE . "skel/");
        }
        if (!defined('_DIR_AIDE')) {
            define('_DIR_AIDE', _DIR_CACHE . "aide/");
        }
        if (!defined('_DIR_TMP')) {
            define('_DIR_TMP', $ti);
        }

        if (!defined('_DIR_VAR')) {
            define('_DIR_VAR', $ta);
        }

        if (!defined('_DIR_ETC')) {
            define('_DIR_ETC', $pi);
        }
        if (!defined('_DIR_CONNECT')) {
            define('_DIR_CONNECT', $pi);
        }
        if (!defined('_DIR_CHMOD')) {
            define('_DIR_CHMOD', $pi);
        }

        if (!isset($GLOBALS['test_dirs'])) {
            // Pas $pi car il est bon de le mettre hors ecriture apres intstall
            // il sera rajoute automatiquement si besoin a l'etape 2 de l'install
            $GLOBALS['test_dirs'] = [$pa, $ti, $ta];
        }

        // Declaration des fichiers

        if (!defined('_CACHE_PLUGINS_PATH')) {
            define('_CACHE_PLUGINS_PATH', _DIR_CACHE . "charger_plugins_chemins.php");
        }
        if (!defined('_CACHE_PLUGINS_OPT')) {
            define('_CACHE_PLUGINS_OPT', _DIR_CACHE . "charger_plugins_options.php");
        }
        if (!defined('_CACHE_PLUGINS_FCT')) {
            define('_CACHE_PLUGINS_FCT', _DIR_CACHE . "charger_plugins_fonctions.php");
        }
        if (!defined('_CACHE_PIPELINES')) {
            define('_CACHE_PIPELINES', _DIR_CACHE . "charger_pipelines.php");
        }
        if (!defined('_CACHE_CHEMIN')) {
            define('_CACHE_CHEMIN', _DIR_CACHE . "chemin.txt");
        }

        # attention .php obligatoire pour ecrire_fichier_securise
        if (!defined('_FILE_META')) {
            define('_FILE_META', $ti . 'meta_cache.php');
        }
        if (!defined('_DIR_LOG')) {
            define('_DIR_LOG', _DIR_TMP . 'log/');
        }
        if (!defined('_FILE_LOG')) {
            define('_FILE_LOG', 'spip');
        }
        if (!defined('_FILE_LOG_SUFFIX')) {
            define('_FILE_LOG_SUFFIX', '.log');
        }

        // Le fichier de connexion a la base de donnees
        // tient compte des anciennes versions (inc_connect...)
        if (!defined('_FILE_CONNECT_INS')) {
            define('_FILE_CONNECT_INS', 'connect');
        }
        if (!defined('_FILE_CONNECT')) {
            define('_FILE_CONNECT',
                (@is_readable($f = _DIR_CONNECT . _FILE_CONNECT_INS . '.php') ? $f
                    : (@is_readable($f = _DIR_RESTREINT . 'inc_connect.php') ? $f
                        : false)));
        }

        // Le fichier de reglages des droits
        if (!defined('_FILE_CHMOD_INS')) {
            define('_FILE_CHMOD_INS', 'chmod');
        }
        if (!defined('_FILE_CHMOD')) {
            define('_FILE_CHMOD',
                (@is_readable($f = _DIR_CHMOD . _FILE_CHMOD_INS . '.php') ? $f
                    : false));
        }

        if (!defined('_FILE_LDAP')) {
            define('_FILE_LDAP', 'ldap.php');
        }

        if (!defined('_FILE_TMP_SUFFIX')) {
            define('_FILE_TMP_SUFFIX', '.tmp.php');
        }
        if (!defined('_FILE_CONNECT_TMP')) {
            define('_FILE_CONNECT_TMP', _DIR_CONNECT . _FILE_CONNECT_INS . _FILE_TMP_SUFFIX);
        }
        if (!defined('_FILE_CHMOD_TMP')) {
            define('_FILE_CHMOD_TMP', _DIR_CHMOD . _FILE_CHMOD_INS . _FILE_TMP_SUFFIX);
        }

        // Definition des droits d'acces en ecriture
        if (!defined('_SPIP_CHMOD') and _FILE_CHMOD) {
            include_once _FILE_CHMOD;
        }

        // Se mefier des fichiers mal remplis!
        if (!defined('_SPIP_CHMOD')) {
            define('_SPIP_CHMOD', 0777);
        }

        if (!defined('_DEFAULT_CHARSET')) {
            /* Le charset par défaut lors de l'installation */
            define('_DEFAULT_CHARSET', 'utf-8');
        }
        if (!defined('_ROOT_PLUGINS')) {
            define('_ROOT_PLUGINS', _ROOT_RACINE . "plugins/");
        }
        if (!defined('_ROOT_PLUGINS_DIST')) {
            define('_ROOT_PLUGINS_DIST', _ROOT_RACINE . "plugins-dist/");
        }
        if (!defined('_ROOT_PLUGINS_SUPPL') && defined('_DIR_PLUGINS_SUPPL') && _DIR_PLUGINS_SUPPL) {
            define('_ROOT_PLUGINS_SUPPL', _ROOT_RACINE . str_replace(_DIR_RACINE, '', _DIR_PLUGINS_SUPPL));
        }

        // La taille des Log
        if (!defined('_MAX_LOG')) {
            define('_MAX_LOG', 100);
        }

        // Sommes-nous dans l'empire du Mal ?
        // (ou sous le signe du Pingouin, ascendant GNU ?)
        if (isset($_SERVER['SERVER_SOFTWARE']) and strpos($_SERVER['SERVER_SOFTWARE'], '(Win') !== false) {
            if (!defined('_OS_SERVEUR')) {
                define('_OS_SERVEUR', 'windows');
            }
            if (!defined('_SPIP_LOCK_MODE')) {
                define('_SPIP_LOCK_MODE', 1);
            } // utiliser le flock php
        } else {
            if (!defined('_OS_SERVEUR')) {
                define('_OS_SERVEUR', '');
            }
            if (!defined('_SPIP_LOCK_MODE')) {
                define('_SPIP_LOCK_MODE', 1);
            } // utiliser le flock php
            #if (!defined('_SPIP_LOCK_MODE')) define('_SPIP_LOCK_MODE',2); // utiliser le nfslock de spip mais link() est tres souvent interdite
        }

        // Langue par defaut
        if (!defined('_LANGUE_PAR_DEFAUT')) {
            define('_LANGUE_PAR_DEFAUT', 'fr');
        }

        //
        // Module de lecture/ecriture/suppression de fichiers utilisant flock()
        // (non surchargeable en l'etat ; attention si on utilise include_spip()
        // pour le rendre surchargeable, on va provoquer un reecriture
        // systematique du noyau ou une baisse de perfs => a etudier)
        include_once _ROOT_RESTREINT . 'inc/flock.php';

        // charger tout de suite le path et son cache
        load_path_cache();

        // *********** traiter les variables ************

        //
        // Securite
        //

        // Ne pas se faire manger par un bug php qui accepte ?GLOBALS[truc]=toto
        if (isset($_REQUEST['GLOBALS'])) {
            die();
        }
        // nettoyer les magic quotes \' et les caracteres nuls %00
        spip_desinfecte($_GET);
        spip_desinfecte($_POST);
        spip_desinfecte($_COOKIE);
        spip_desinfecte($_REQUEST);

        // appliquer le cookie_prefix
        if ($GLOBALS['cookie_prefix'] != 'spip') {
            include_spip('inc/cookie');
            recuperer_cookies_spip($GLOBALS['cookie_prefix']);
        }

        //
        // Capacites php (en fonction de la version)
        //
        $GLOBALS['flag_ob'] = (function_exists("ob_start")
            && function_exists("ini_get")
            && !strstr(@ini_get('disable_functions'), 'ob_'));
        $GLOBALS['flag_sapi_name'] = function_exists("php_sapi_name");
        $GLOBALS['flag_get_cfg_var'] = (@get_cfg_var('error_reporting') != "");
        $GLOBALS['flag_upload'] = (!$GLOBALS['flag_get_cfg_var'] ||
            (get_cfg_var('upload_max_filesize') > 0));

        // Compatibilite avec serveurs ne fournissant pas $REQUEST_URI
        if (isset($_SERVER['REQUEST_URI'])) {
            $GLOBALS['REQUEST_URI'] = $_SERVER['REQUEST_URI'];
        } else {
            $GLOBALS['REQUEST_URI'] = (php_sapi_name() !== 'cli') ? $_SERVER['PHP_SELF'] : '';
            if (!empty($_SERVER['QUERY_STRING'])
                and !strpos($_SERVER['REQUEST_URI'], '?')
            ) {
                $GLOBALS['REQUEST_URI'] .= '?' . $_SERVER['QUERY_STRING'];
            }
        }

        // Duree de validite de l'alea pour les cookies et ce qui s'ensuit.
        if (!defined('_RENOUVELLE_ALEA')) {
            define('_RENOUVELLE_ALEA', 12 * 3600);
        }
        if (!defined('_DUREE_COOKIE_ADMIN')) {
            define('_DUREE_COOKIE_ADMIN', 14 * 24 * 3600);
        }

        // charger les meta si possible et renouveller l'alea au besoin
        // charge aussi effacer_meta et ecrire_meta
        $inc_meta = charger_fonction('meta', 'inc');
        $inc_meta();

        // nombre de repertoires depuis la racine
        // on compare a l'adresse de spip.php : $_SERVER["SCRIPT_NAME"]
        // ou a defaut celle donnee en meta ; (mais si celle-ci est fausse
        // le calcul est faux)
        if (!_DIR_RESTREINT) {
            $GLOBALS['profondeur_url'] = 1;
        } else {
            $uri = isset($_SERVER['REQUEST_URI']) ? explode('?', $_SERVER['REQUEST_URI']) : '';
            $uri_ref = $_SERVER["SCRIPT_NAME"];
            if (!$uri_ref
                // si on est appele avec un autre ti, on est sans doute en mutu
                // si jamais c'est de la mutu avec sous rep, on est perdu si on se fie
                // a spip.php qui est a la racine du spip, et vue qu'on sait pas se reperer
                // s'en remettre a l'adresse du site. alea jacta est.
                or $ti !== _NOM_TEMPORAIRES_INACCESSIBLES
            ) {
                if (isset($GLOBALS['meta']['adresse_site'])) {
                    $uri_ref = parse_url($GLOBALS['meta']['adresse_site']);
                    $uri_ref = ($uri_ref['path'] ?? '') . '/';
                } else {
                    $uri_ref = "";
                }
            }
            if (!$uri or !$uri_ref) {
                $GLOBALS['profondeur_url'] = 0;
            } else {
                $GLOBALS['profondeur_url'] = max(0,
                    substr_count($uri[0], '/')
                    - substr_count($uri_ref, '/'));
            }
        }
        // s'il y a un cookie ou PHP_AUTH, initialiser visiteur_session
        if (_FILE_CONNECT) {
            if (verifier_visiteur() == '0minirezo'
                // si c'est un admin sans cookie admin, il faut ignorer le cache chemin !
                and !isset($_COOKIE['spip_admin'])
            ) {
                clear_path_cache();
            }
        }
    }

    /**
     * Complements d'initialisation non critiques pouvant etre realises
     * par les plugins
     */
    protected function spip_initialisation_suite(): void
    {
        static $too_late = 0;
        if ($too_late++) {
            return;
        }

        // taille mini des login
        if (!defined('_LOGIN_TROP_COURT')) {
            define('_LOGIN_TROP_COURT', 4);
        }

        // la taille maxi des logos (0 : pas de limite) (pas de define par defaut, ce n'est pas utile)
        #if (!defined('_LOGO_MAX_SIZE')) define('_LOGO_MAX_SIZE', 0); # poids en ko
        #if (!defined('_LOGO_MAX_WIDTH')) define('_LOGO_MAX_WIDTH', 0); # largeur en pixels
        #if (!defined('_LOGO_MAX_HEIGHT')) define('_LOGO_MAX_HEIGHT', 0); # hauteur en pixels

        // la taille maxi des images (0 : pas de limite) (pas de define par defaut, ce n'est pas utile)
        #if (!defined('_DOC_MAX_SIZE')) define('_DOC_MAX_SIZE', 0); # poids en ko
        #if (!defined('_IMG_MAX_SIZE')) define('_IMG_MAX_SIZE', 0); # poids en ko
        #if (!defined('_IMG_MAX_WIDTH')) define('_IMG_MAX_WIDTH', 0); # largeur en pixels
        #if (!defined('_IMG_MAX_HEIGHT')) define('_IMG_MAX_HEIGHT', 0); # hauteur en pixels

        if (!defined('_PASS_LONGUEUR_MINI')) {
            define('_PASS_LONGUEUR_MINI', 6);
        }

        // Qualite des images calculees automatiquement. C'est un nombre entre 0 et 100, meme pour imagick (on ramene a 0..1 par la suite)
        if (!defined('_IMG_QUALITE')) {
            define('_IMG_QUALITE', 85);
        } # valeur par defaut
        if (!defined('_IMG_GD_QUALITE')) {
            define('_IMG_GD_QUALITE', _IMG_QUALITE);
        } # surcharge pour la lib GD
        if (!defined('_IMG_CONVERT_QUALITE')) {
            define('_IMG_CONVERT_QUALITE', _IMG_QUALITE);
        } # surcharge pour imagick en ligne de commande
        // Historiquement la valeur pour imagick semble differente. Si ca n'est pas necessaire, il serait preferable de garder _IMG_QUALITE
        if (!defined('_IMG_IMAGICK_QUALITE')) {
            define('_IMG_IMAGICK_QUALITE', 75);
        } # surcharge pour imagick en PHP

        if (!defined('_COPIE_LOCALE_MAX_SIZE')) {
            define('_COPIE_LOCALE_MAX_SIZE', 33554432);
        } // poids en octet

        // qq chaines standard
        if (!defined('_ACCESS_FILE_NAME')) {
            define('_ACCESS_FILE_NAME', '.htaccess');
        }
        if (!defined('_AUTH_USER_FILE')) {
            define('_AUTH_USER_FILE', '.htpasswd');
        }
        if (!defined('_SPIP_DUMP')) {
            define('_SPIP_DUMP', 'dump@nom_site@@stamp@.xml');
        }
        if (!defined('_CACHE_RUBRIQUES')) {
            /* Fichier cache pour le navigateur de rubrique du bandeau */
            define('_CACHE_RUBRIQUES', _DIR_TMP . 'menu-rubriques-cache.txt');
        }
        if (!defined('_CACHE_RUBRIQUES_MAX')) {
            /* Nombre maxi de rubriques enfants affichées pour chaque rubrique du navigateur de rubrique du bandeau */
            define('_CACHE_RUBRIQUES_MAX', 500);
        }

        if (!defined('_EXTENSION_SQUELETTES')) {
            define('_EXTENSION_SQUELETTES', 'html');
        }

        if (!defined('_DOCTYPE_ECRIRE')) {
            /* Définit le doctype de l’espace privé */
            define('_DOCTYPE_ECRIRE', "<!DOCTYPE html>\n");
        }
        if (!defined('_DOCTYPE_AIDE')) {
            /* Définit le doctype de l’aide en ligne */
            define('_DOCTYPE_AIDE',
                "<!DOCTYPE html PUBLIC '-//W3C//DTD HTML 4.01 Frameset//EN' 'http://www.w3.org/TR/1999/REC-html401-19991224/frameset.dtd'>");
        }

        if (!defined('_SPIP_SCRIPT')) {
            /* L'adresse de base du site ; on peut mettre '' si la racine est gerée par
             * le script de l'espace public, alias index.php */
            define('_SPIP_SCRIPT', 'spip.php');
        }
        if (!defined('_SPIP_PAGE')) {
            /* Argument page, personalisable en cas de conflit avec un autre script */
            define('_SPIP_PAGE', 'page');
        }

        // le script de l'espace prive
        // Mettre a "index.php" si DirectoryIndex ne le fait pas ou pb connexes:
        // les anciens IIS n'acceptent pas les POST sur ecrire/ (#419)
        // meme pb sur thttpd cf. https://forum.spip.net/fr_184153.html
        if (!defined('_SPIP_ECRIRE_SCRIPT')) {
            if (!empty($_SERVER['SERVER_SOFTWARE']) and preg_match(',IIS|thttpd,', $_SERVER['SERVER_SOFTWARE'])) {
                define('_SPIP_ECRIRE_SCRIPT', 'index.php');
            } else {
                define('_SPIP_ECRIRE_SCRIPT', '');
            }
        }

        if (!defined('_SPIP_AJAX')) {
            define('_SPIP_AJAX', ((!isset($_COOKIE['spip_accepte_ajax']))
                ? 1
                : (($_COOKIE['spip_accepte_ajax'] != -1) ? 1 : 0)));
        }

        // La requete est-elle en ajax ?
        if (!defined('_AJAX')) {
            define('_AJAX',
                (isset($_SERVER['HTTP_X_REQUESTED_WITH']) # ajax jQuery
                    or !empty($_REQUEST['var_ajax_redir']) # redirection 302 apres ajax jQuery
                    or !empty($_REQUEST['var_ajaxcharset']) # compat ascendante pour plugins
                    or !empty($_REQUEST['var_ajax']) # forms ajax & inclure ajax de spip
                    )
                and empty($_REQUEST['var_noajax']) # horrible exception, car c'est pas parce que la requete est ajax jquery qu'il faut tuer tous les formulaires ajax qu'elle contient
                );
        }

        # nombre de pixels maxi pour calcul de la vignette avec gd
        # au dela de 5500000 on considere que php n'est pas limite en memoire pour cette operation
        # les configurations limitees en memoire ont un seuil plutot vers 1MPixel
        if (!defined('_IMG_GD_MAX_PIXELS')) {
            define('_IMG_GD_MAX_PIXELS',
                (isset($GLOBALS['meta']['max_taille_vignettes']) and $GLOBALS['meta']['max_taille_vignettes'])
                ? $GLOBALS['meta']['max_taille_vignettes']
                : 0);
        }

        if (!defined('_MEMORY_LIMIT_MIN')) {
            define('_MEMORY_LIMIT_MIN', 16);
        } // en Mo
        // si on est dans l'espace prive et si le besoin est superieur a 8Mo (qui est vraiment le standard)
        // on verifie que la memoire est suffisante pour le compactage css+js pour eviter la page blanche
        // il y aura d'autres problemes et l'utilisateur n'ira pas tres loin, mais ce sera plus comprehensible qu'une page blanche
        if (test_espace_prive() and _MEMORY_LIMIT_MIN > 8) {
            if ($memory = trim(ini_get('memory_limit')) and $memory != -1) {
                $unit = strtolower(substr($memory, -1));
                $memory = substr($memory, 0, -1);
                switch ($unit) {
                    // Le modifieur 'G' est disponible depuis PHP 5.1.0
                    case 'g':
                        $memory *= 1024;
                        // no break
                    case 'm':
                        $memory *= 1024;
                        // no break
                    case 'k':
                        $memory *= 1024;
                }
                if ($memory < _MEMORY_LIMIT_MIN * 1024 * 1024) {
                    @ini_set('memory_limit', $m = _MEMORY_LIMIT_MIN . 'M');
                    if (trim(ini_get('memory_limit')) != $m) {
                        if (!defined('_INTERDIRE_COMPACTE_HEAD_ECRIRE')) {
                            define('_INTERDIRE_COMPACTE_HEAD_ECRIRE', true);
                        } // evite une page blanche car on ne saura pas calculer la css dans ce hit
                    }
                }
            } else {
                if (!defined('_INTERDIRE_COMPACTE_HEAD_ECRIRE')) {
                    define('_INTERDIRE_COMPACTE_HEAD_ECRIRE', true);
                }
            } // evite une page blanche car on ne saura pas calculer la css dans ce hit
        }
        // Protocoles a normaliser dans les chaines de langues
        if (!defined('_PROTOCOLES_STD')) {
            define('_PROTOCOLES_STD', 'http|https|ftp|mailto|webcal');
        }

        init_var_mode();
    }

    /**
     * Repérer les variables d'URL spéciales `var_mode` qui conditionnent
     * la validité du cache ou certains affichages spéciaux.
     *
     * Le paramètre d'URL `var_mode` permet de
     * modifier la pérennité du cache, recalculer des urls
     * ou d'autres petit caches (trouver_table, css et js compactes ...),
     * d'afficher un écran de débug ou des traductions non réalisées.
     *
     * En fonction de ces paramètres dans l'URL appelante, on définit
     * da constante `_VAR_MODE` qui servira ensuite à SPIP.
     *
     * Le paramètre `var_mode` accepte ces valeurs :
     *
     * - `calcul` : force un calcul du cache de la page (sans forcément recompiler les squelettes)
     * - `recalcul` : force un calcul du cache de la page en recompilant au préabable les squelettes
     * - `inclure` : modifie l'affichage en ajoutant visuellement le nom de toutes les inclusions qu'elle contient
     * - `debug` :  modifie l'affichage activant le mode "debug"
     * - `preview` : modifie l'affichage en ajoutant aux boucles les éléments prévisualisables
     * - `traduction` : modifie l'affichage en affichant des informations sur les chaînes de langues utilisées
     * - `urls` : permet de recalculer les URLs des objets appelés dans la page par les balises `#URL_xx`
     * - `images` : permet de recalculer les filtres d'images utilisés dans la page
     *
     * En dehors des modes `calcul` et `recalcul`, une autorisation 'previsualiser' ou 'debug' est testée.
     *
     * @note
     *     Il éxiste également le paramètre `var_profile` qui modifie l'affichage pour incruster
     *     le nombre de requêtes SQL utilisées dans la page, qui peut se compléter avec le paramètre
     * `   var_mode` (calcul ou recalcul).
     */
    protected function init_var_mode(): void
    {
        static $done = false;
        if (!$done) {
            if (isset($_GET['var_mode'])) {
                $var_mode = explode(',', $_GET['var_mode']);
                // tout le monde peut calcul/recalcul
                if (!defined('_VAR_MODE')) {
                    if (in_array('recalcul', $var_mode)) {
                        define('_VAR_MODE', 'recalcul');
                    } elseif (in_array('calcul', $var_mode)) {
                        define('_VAR_MODE', 'calcul');
                    }
                }
                $var_mode = array_diff($var_mode, ['calcul', 'recalcul']);
                if ($var_mode) {
                    include_spip('inc/autoriser');
                    // autoriser preview si preview seulement, et sinon autoriser debug
                    if (autoriser(
                        ($_GET['var_mode'] == 'preview')
                        ? 'previsualiser'
                        : 'debug'
                    )) {
                        if (in_array('traduction', $var_mode)) {
                            // forcer le calcul pour passer dans traduire
                            if (!defined('_VAR_MODE')) {
                                define('_VAR_MODE', 'calcul');
                            }
                            // et ne pas enregistrer de cache pour ne pas trainer les surlignages sur d'autres pages
                            if (!defined('_VAR_NOCACHE')) {
                                define('_VAR_NOCACHE', true);
                            }
                            $var_mode = array_diff($var_mode, ['traduction']);
                        }
                        if (in_array('preview', $var_mode)) {
                            // basculer sur les criteres de preview dans les boucles
                            if (!defined('_VAR_PREVIEW')) {
                                define('_VAR_PREVIEW', true);
                            }
                            // forcer le calcul
                            if (!defined('_VAR_MODE')) {
                                define('_VAR_MODE', 'calcul');
                            }
                            // et ne pas enregistrer de cache
                            if (!defined('_VAR_NOCACHE')) {
                                define('_VAR_NOCACHE', true);
                            }
                            $var_mode = array_diff($var_mode, ['preview']);
                        }
                        if (in_array('inclure', $var_mode)) {
                            // forcer le compilo et ignorer les caches existants
                            if (!defined('_VAR_MODE')) {
                                define('_VAR_MODE', 'calcul');
                            }
                            if (!defined('_VAR_INCLURE')) {
                                define('_VAR_INCLURE', true);
                            }
                            // et ne pas enregistrer de cache
                            if (!defined('_VAR_NOCACHE')) {
                                define('_VAR_NOCACHE', true);
                            }
                            $var_mode = array_diff($var_mode, ['inclure']);
                        }
                        if (in_array('urls', $var_mode)) {
                            // forcer le compilo et ignorer les caches existants
                            if (!defined('_VAR_MODE')) {
                                define('_VAR_MODE', 'calcul');
                            }
                            if (!defined('_VAR_URLS')) {
                                define('_VAR_URLS', true);
                            }
                            $var_mode = array_diff($var_mode, ['urls']);
                        }
                        if (in_array('images', $var_mode)) {
                            // forcer le compilo et ignorer les caches existants
                            if (!defined('_VAR_MODE')) {
                                define('_VAR_MODE', 'calcul');
                            }
                            // indiquer qu'on doit recalculer les images
                            if (!defined('_VAR_IMAGES')) {
                                define('_VAR_IMAGES', true);
                            }
                            $var_mode = array_diff($var_mode, ['images']);
                        }
                        if (in_array('debug', $var_mode)) {
                            if (!defined('_VAR_MODE')) {
                                define('_VAR_MODE', 'debug');
                            }
                            // et ne pas enregistrer de cache
                            if (!defined('_VAR_NOCACHE')) {
                                define('_VAR_NOCACHE', true);
                            }
                            $var_mode = array_diff($var_mode, ['debug']);
                        }
                        if (count($var_mode) and !defined('_VAR_MODE')) {
                            define('_VAR_MODE', reset($var_mode));
                        }
                        if (isset($GLOBALS['visiteur_session']['nom'])) {
                            spip_log($GLOBALS['visiteur_session']['nom']
                                . " " . _VAR_MODE);
                        }
                    } // pas autorise ?
                    else {
                        // si on n'est pas connecte on se redirige
                        if (!$GLOBALS['visiteur_session']) {
                            include_spip('inc/headers');
                            redirige_par_entete(generer_url_public('login',
                                'url=' . rawurlencode(
                                    parametre_url(self(), 'var_mode', $_GET['var_mode'], '&')
                                    ), true));
                        }
                        // sinon tant pis
                    }
                }
            }
            if (!defined('_VAR_MODE')) {
                /*
                 * Indique le mode de calcul ou d'affichage de la page.
                 * @see init_var_mode()
                 */
                define('_VAR_MODE', false);
            }
            $done = true;
        }
    }

    // Annuler les magic quotes \' sur GET POST COOKIE et GLOBALS ;
    // supprimer aussi les eventuels caracteres nuls %00, qui peuvent tromper
    // la commande is_readable('chemin/vers/fichier/interdit%00truc_normal')
    // https://code.spip.net/@spip_desinfecte
    protected function spip_desinfecte(&$t, $deep = true): void
    {
        foreach ($t as $key => $val) {
            if (is_string($t[$key])) {
                $t[$key] = str_replace(chr(0), '-', $t[$key]);
            } // traiter aussi les "texte_plus" de article_edit
            else {
                if ($deep and is_array($t[$key]) and $key !== 'GLOBALS') {
                    spip_desinfecte($t[$key], $deep);
                }
            }
        }
    }

    //  retourne le statut du visiteur s'il s'annonce

    // https://code.spip.net/@verifier_visiteur
    protected function verifier_visiteur()
    {
        // Rq: pour que cette fonction marche depuis mes_options
        // il faut forcer l'init si ce n'est fait
        // mais on risque de perturber des plugins en initialisant trop tot
        // certaines constantes
        @spip_initialisation_core(
            (_DIR_RACINE . _NOM_PERMANENTS_INACCESSIBLES),
            (_DIR_RACINE . _NOM_PERMANENTS_ACCESSIBLES),
            (_DIR_RACINE . _NOM_TEMPORAIRES_INACCESSIBLES),
            (_DIR_RACINE . _NOM_TEMPORAIRES_ACCESSIBLES)
        );

        // Demarrer une session NON AUTHENTIFIEE si on donne son nom
        // dans un formulaire sans login (ex: #FORMULAIRE_FORUM)
        // Attention on separe bien session_nom et nom, pour eviter
        // les melanges entre donnees SQL et variables plus aleatoires
        $variables_session = ['session_nom', 'session_email'];
        foreach ($variables_session as $var) {
            if (_request($var) !== null) {
                $init = true;
                break;
            }
        }
        if (isset($init)) {
            #@spip_initialisation_suite();
            $session = charger_fonction('session', 'inc');
            $session();
            include_spip('inc/texte');
            foreach ($variables_session as $var) {
                if (($a = _request($var)) !== null) {
                    $GLOBALS['visiteur_session'][$var] = safehtml($a);
                }
            }
            if (!isset($GLOBALS['visiteur_session']['id_auteur'])) {
                $GLOBALS['visiteur_session']['id_auteur'] = 0;
            }
            $session($GLOBALS['visiteur_session']);

            return 0;
        }

        $h = (isset($_SERVER['PHP_AUTH_USER']) and !$GLOBALS['ignore_auth_http']);
        if ($h or isset($_COOKIE['spip_session']) or isset($_COOKIE[$GLOBALS['cookie_prefix'] . '_session'])) {
            $session = charger_fonction('session', 'inc');
            if ($session()) {
                return $GLOBALS['visiteur_session']['statut'];
            }
            if ($h and isset($_SERVER['PHP_AUTH_PW'])) {
                include_spip('inc/auth');
                $h = lire_php_auth($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
            }
            if ($h) {
                $GLOBALS['visiteur_session'] = $h;

                return $GLOBALS['visiteur_session']['statut'];
            }
        }

        // au moins son navigateur nous dit la langue preferee de cet inconnu
        include_spip('inc/lang');
        utiliser_langue_visiteur();

        return false;
    }

    /**
     * Sélectionne la langue donnée en argument et mémorise la courante
     *
     * Restaure l'ancienne langue si appellée sans argument.
     *
     * @note
     *     On pourrait économiser l'empilement en cas de non changemnt
     *     et lui faire retourner `False` pour prevenir l'appelant
     *     Le noyau de Spip sait le faire, mais pour assurer la compatibilité
     *     cette fonction retourne toujours non `False`
     *
     * @uses changer_langue()
     * @param null|string $lang
     *     - string : Langue à appliquer,
     *     - null : Pour restituer la dernière langue mémorisée.
     * @return string
     *     - string Langue utilisée.
     **/
    protected function lang_select($lang = null)
    {
        static $pile_langues = [];
        if (!function_exists('changer_langue')) {
            include_spip('inc/lang');
        }
        if ($lang === null) {
            $lang = array_pop($pile_langues);
        } else {
            array_push($pile_langues, $GLOBALS['spip_lang']);
        }
        if (isset($GLOBALS['spip_lang']) and $lang == $GLOBALS['spip_lang']) {
            return $lang;
        }
        changer_langue($lang);

        return $lang;
    }

    /**
     * Renvoie une chaîne qui identifie la session courante
     *
     * Permet de savoir si on peut utiliser un cache enregistré pour cette session.
     * Cette chaîne est courte (8 cars) pour pouvoir être utilisée dans un nom
     * de fichier cache.
     *
     * @pipeline_appel definir_session
     *
     * @param bool $force
     * @return string
     *     Identifiant de la session
     **/
    protected function spip_session($force = false)
    {
        static $session;
        if ($force or !isset($session)) {
            $s = pipeline('definir_session',
                $GLOBALS['visiteur_session']
                    ? serialize($GLOBALS['visiteur_session'])
                        . '_' . @$_COOKIE['spip_session']
                    : ''
            );
            $session = $s ? substr(md5($s), 0, 8) : '';
        }

        #spip_log('session: '.$session);
        return $session;
    }

    /**
     * Retourne un lien vers une aide
     *
     * Aide, aussi depuis l'espace privé à présent.
     * Surchargeable mais pas d'erreur fatale si indisponible.
     *
     * @param string $aide
     *    Cle d'identification de l'aide desiree
     * @param bool $distante
     *    Generer une url locale (par defaut)
     *    ou une url distante [directement sur spip.net]
     * @return
     *    Lien sur une icone d'aide
     **/
    protected function aider($aide = '', $distante = false)
    {
        $aider = charger_fonction('aide', 'inc', true);

        return $aider ? $aider($aide, '', [], $distante) : '';
    }

    /**
     * Page `exec=info` : retourne le contenu de la fonction php `phpinfo()`
     *
     * Si l’utiliseur est un webmestre.
     */
    protected function exec_info_dist(): void
    {
        include_spip('inc/autoriser');
        if (autoriser('phpinfos')) {
            $cookies_masques = ['spip_session', 'PHPSESSID'];
            $cookies_backup = [];
            foreach ($cookies_masques as $k) {
                if (!empty($_COOKIE[$k])) {
                    $cookies_backup[$k] = $_COOKIE[$k];
                    $_COOKIE[$k] = '******************************';
                }
            }
            phpinfo();
            foreach ($cookies_backup as $k => $v) {
                $_COOKIE[$k] = $v;
            }
        } else {
            include_spip('inc/filtres');
            sinon_interdire_acces();
        }
    }

    /**
     * Génère une erreur de squelette
     *
     * Génère une erreur de squelette qui sera bien visible par un
     * administrateur authentifié lors d'une visite de la page en erreur
     *
     * @param bool|string|array $message
     *     - Message d'erreur (string|array)
     *     - false pour retourner le texte des messages d'erreurs
     *     - vide pour afficher les messages d'erreurs
     * @param string|array|object $lieu
     *     Lieu d'origine de l'erreur
     * @return null|string
     *     - Rien dans la plupart des cas
     *     - string si $message à false.
     **/
    protected function erreur_squelette($message = '', $lieu = '')
    {
        $debusquer = charger_fonction('debusquer', 'public');
        if (is_array($lieu)) {
            include_spip('public/compiler');
            $lieu = reconstruire_contexte_compil($lieu);
        }

        return $debusquer($message, $lieu);
    }

    /**
     * Calcule un squelette avec un contexte et retourne son contenu
     *
     * La fonction de base de SPIP : un squelette + un contexte => une page.
     * $fond peut etre un nom de squelette, ou une liste de squelette au format array.
     * Dans ce dernier cas, les squelettes sont tous evalues et mis bout a bout
     * $options permet de selectionner les options suivantes :
     *
     * - trim => true (valeur par defaut) permet de ne rien renvoyer si le fond ne produit que des espaces ;
     * - raw  => true permet de recuperer la strucure $page complete avec entetes et invalideurs
     *          pour chaque $fond fourni.
     *
     * @api
     * @param string /array $fond
     *     - Le ou les squelettes à utiliser, sans l'extension, {@example prive/liste/auteurs}
     *     - Le fichier sera retrouvé dans la liste des chemins connus de SPIP (squelettes, plugins, spip)
     * @param array $contexte
     *     - Informations de contexte envoyées au squelette, {@example array('id_rubrique' => 8)}
     *     - La langue est transmise automatiquement (sauf option étoile).
     * @param array $options
     *     Options complémentaires :
     *
     *     - trim   : applique un trim sur le résultat (true par défaut)
     *     - raw    : retourne un tableau d'information sur le squelette (false par défaut)
     *     - etoile : ne pas transmettre la langue au contexte automatiquement (false par défaut),
     *                équivalent de INCLURE*
     *     - ajax   : gere les liens internes du squelette en ajax (équivalent du paramètre {ajax})
     * @param string $connect
     *     Non du connecteur de bdd a utiliser
     * @return string|array
     *     - Contenu du squelette calculé
     *     - ou tableau d'information sur le squelette.
     */
    protected function recuperer_fond($fond, $contexte = [], $options = [], $connect = '')
    {
        if (!function_exists('evaluer_fond')) {
            include_spip('public/assembler');
        }
        // assurer la compat avec l'ancienne syntaxe
        // (trim etait le 3eme argument, par defaut a true)
        if (!is_array($options)) {
            $options = ['trim' => $options];
        }
        if (!isset($options['trim'])) {
            $options['trim'] = true;
        }

        if (isset($contexte['connect'])) {
            $connect = $contexte['connect'];
            unset($contexte['connect']);
        }

        $texte = "";
        $pages = [];
        $lang_select = '';
        if (!isset($options['etoile']) or !$options['etoile']) {
            // Si on a inclus sans fixer le critere de lang, on prend la langue courante
            if (!isset($contexte['lang'])) {
                $contexte['lang'] = $GLOBALS['spip_lang'];
            }

            if ($contexte['lang'] != $GLOBALS['meta']['langue_site']) {
                $lang_select = lang_select($contexte['lang']);
            }
        }

        if (!isset($GLOBALS['_INC_PUBLIC'])) {
            $GLOBALS['_INC_PUBLIC'] = 0;
        }

        $GLOBALS['_INC_PUBLIC']++;

        // fix #4235
        $cache_utilise_session_appelant = ($GLOBALS['cache_utilise_session'] ?? null);

        foreach (is_array($fond) ? $fond : [$fond] as $f) {
            unset($GLOBALS['cache_utilise_session']);	// fix #4235

            $page = evaluer_fond($f, $contexte, $connect);
            if ($page === '') {
                $c = $options['compil'] ?? '';
                $a = ['fichier' => $f];
                $erreur = _T('info_erreur_squelette2', $a); // squelette introuvable
                erreur_squelette($erreur, $c);
                // eviter des erreurs strictes ensuite sur $page['cle'] en PHP >= 5.4
                $page = ['texte' => '', 'erreur' => $erreur];
            }

            $page = pipeline('recuperer_fond', [
                'args' => ['fond' => $f, 'contexte' => $contexte, 'options' => $options, 'connect' => $connect],
                'data' => $page,
            ]);
            if (isset($options['ajax']) and $options['ajax']) {
                if (!function_exists('encoder_contexte_ajax')) {
                    include_spip('inc/filtres');
                }
                $page['texte'] = encoder_contexte_ajax(
                    array_merge(
                        $contexte,
                        ['fond' => $f],
                        ($connect ? ['connect' => $connect] : [])
                    ),
                    '',
                    $page['texte'],
                    $options['ajax']
                );
            }

            if (isset($options['raw']) and $options['raw']) {
                $pages[] = $page;
            } else {
                $texte .= $options['trim'] ? rtrim($page['texte']) : $page['texte'];
            }

            // contamination de la session appelante, pour les inclusions statiques
            if (isset($page['invalideurs']['session'])) {
                $cache_utilise_session_appelant = $page['invalideurs']['session'];
            }
        }

        // restaurer le sessionnement du contexte appelant,
        // éventuellement contaminé si on vient de récupérer une inclusion statique sessionnée
        if (isset($cache_utilise_session_appelant)) {
            $GLOBALS['cache_utilise_session'] = $cache_utilise_session_appelant;
        }

        $GLOBALS['_INC_PUBLIC']--;

        if ($lang_select) {
            lang_select();
        }
        if (isset($options['raw']) and $options['raw']) {
            return is_array($fond) ? $pages : reset($pages);
        } else {
            return $options['trim'] ? ltrim($texte) : $texte;
        }
    }

    /**
     * Trouve un squelette dans le repertoire modeles/
     *
     * @param  $nom
     * @return string
     */
    protected function trouve_modele($nom)
    {
        return trouver_fond($nom, 'modeles/');
    }

    /**
     * Trouver un squelette dans le chemin
     * on peut specifier un sous-dossier dans $dir
     * si $pathinfo est a true, retourne un tableau avec
     * les composantes du fichier trouve
     * + le chemin complet sans son extension dans fond
     *
     * @param string $nom
     * @param string $dir
     * @param bool $pathinfo
     * @return array|string
     */
    protected function trouver_fond($nom, $dir = '', $pathinfo = false)
    {
        $f = find_in_path($nom . '.' . _EXTENSION_SQUELETTES, $dir ? rtrim($dir, '/') . '/' : '');
        if (!$pathinfo) {
            return $f;
        }
        // renvoyer un tableau detaille si $pathinfo==true
        $p = pathinfo($f);
        if (!isset($p['extension']) or !$p['extension']) {
            $p['extension'] = _EXTENSION_SQUELETTES;
        }
        if (!isset($p['extension']) or !$p['filename']) {
            $p['filename'] = ($p['basename'] ? substr($p['basename'], 0, -strlen($p['extension']) - 1) : '');
        }
        $p['fond'] = ($f ? substr($f, 0, -strlen($p['extension']) - 1) : '');

        return $p;
    }

    /**
     * Teste, pour un nom de page de l'espace privé, s'il est possible
     * de générer son contenu.
     *
     * Dans ce cas, on retourne la fonction d'exécution correspondante à utiliser
     * (du répertoire `ecrire/exec`). Deux cas particuliers et prioritaires :
     * `fond` ou `fond_monobloc` sont retournés si des squelettes existent.
     *
     * - `fond` : pour des squelettes de `prive/squelettes/contenu`
     *          ou pour des objets éditoriaux dont les suqelettes seront échaffaudés
     * - `fond_monobloc` (compatibilité avec SPIP 2.1) : pour des squelettes de `prive/exec`
     *
     * @param string $nom
     *     Nom de la page
     * @return string
     *     Nom de l'exec, sinon chaîne vide.
     **/
    protected function tester_url_ecrire($nom)
    {
        static $exec = [];
        if (isset($exec[$nom])) {
            return $exec[$nom];
        }
        // tester si c'est une page en squelette
        if (trouver_fond($nom, 'prive/squelettes/contenu/')) {
            return $exec[$nom] = 'fond';
        } // compat skels orthogonaux version precedente
        elseif (trouver_fond($nom, 'prive/exec/')) {
            return $exec[$nom] = 'fond_monobloc';
        } // echafaudage d'un fond !
        elseif (include_spip('public/styliser_par_z') and z_echafaudable($nom)) {
            return $exec[$nom] = 'fond';
        }
        // attention, il ne faut pas inclure l'exec ici
        // car sinon #URL_ECRIRE provoque des inclusions
        // et des define intrusifs potentiels
        return $exec[$nom] = ((find_in_path("{$nom}.php", 'exec/') or charger_fonction($nom, 'exec', true)) ? $nom : '');
    }

    /**
     * Teste la présence d’une extension PHP
     *
     * @deprected Utiliser directement la fonction native `extension_loaded($module)`
     * @example
     *     ```
     *     $ok = charger_php_extension('sqlite');
     *     ```
     * @param string $module Nom du module à charger
     * @return bool true si le module est chargé
     **/
    protected function charger_php_extension($module)
    {
        if (extension_loaded($module)) {
            return true;
        }
        return false;
    }

    /**
     * Indique si le code HTML5 est permis sur le site public
     *
     * @return bool
     *     true si et seulement si la configuration autorise le code HTML5 sur le site public
     **/
    protected function html5_permis()
    {
        return (isset($GLOBALS['meta']['version_html_max'])
            and ('html5' == $GLOBALS['meta']['version_html_max']));
    }

    /**
     * Lister les formats image acceptes par les lib et fonctions images
     * @param bool $gd
     * @param bool $svg_allowed
     * @return array
     */
    protected function formats_image_acceptables($gd = false, $svg_allowed = true)
    {
        $config = ($gd ? "gd_formats" : "formats_graphiques");
        $formats = ($GLOBALS['meta'][$config] ?? 'png,gif,jpg');
        $formats = explode(',', $formats);
        $formats = array_filter($formats);
        $formats = array_map('trim', $formats);

        if ($svg_allowed) {
            $formats[] = 'svg';
        }

        return $formats;
    }

    /**
     * Extension de la fonction getimagesize pour supporter aussi les images SVG
     * @param string $fichier
     * @return array|bool
     */
    protected function spip_getimagesize($fichier)
    {
        if (!$imagesize = @getimagesize($fichier)) {
            include_spip("inc/svg");
            if ($attrs = svg_lire_attributs($fichier)) {
                list($width, $height, $viewbox) = svg_getimagesize_from_attr($attrs);
                $imagesize = [
                    $width,
                    $height,
                    IMAGETYPE_SVG,
                    "width=\"{$width}\" height=\"{$height}\"",
                    "mime" => "image/svg+xml",
                ];
            }
        }
        return $imagesize;
    }

    /*
     * Bloc de compatibilite : quasiment tous les plugins utilisent ces fonctions
     * desormais depreciees ; plutot que d'obliger tout le monde a charger
     * vieilles_defs, on va assumer l'histoire de ces 3 fonctions ubiquitaires
     */

    /**
     * lire_meta : fonction dépréciée
     *
     * @deprecated Utiliser `$GLOBALS['meta'][$nom]` ou `lire_config('nom')`
     * @see lire_config()
     * @param string $nom Clé de meta à lire
     * @return mixed Valeur de la meta.
     **/
    protected function lire_meta($nom)
    {
        return $GLOBALS['meta'][$nom] ?? null;
    }

    /**
     * ecrire_metas : fonction dépréciée
     *
     * @deprecated
     **/
    protected function ecrire_metas(): void
    {
    }

    /**
     * Poser une alerte qui sera affiche aux auteurs de bon statut ('' = tous)
     * au prochain passage dans l'espace prive
     * chaque alerte doit avoir un nom pour eviter duplication a chaque hit
     * les alertes affichees une fois sont effacees
     *
     * @param string $nom
     * @param string $message
     * @param string $statut
     */
    protected function avertir_auteurs($nom, $message, $statut = ''): void
    {
        $alertes = $GLOBALS['meta']['message_alertes_auteurs'];
        if (!$alertes
            or !is_array($alertes = unserialize($alertes))
        ) {
            $alertes = [];
        }

        if (!isset($alertes[$statut])) {
            $alertes[$statut] = [];
        }
        $alertes[$statut][$nom] = $message;
        ecrire_meta("message_alertes_auteurs", serialize($alertes));
    }

    /**
     * Nettoie une chaine pour servir comme classes CSS.
     *
     * @note
     *     les classes CSS acceptent théoriquement tous les caractères sauf NUL.
     *     Ici, on limite (enlève) les caractères autres qu’alphanumérique, espace, - + _ @
     *
     * @param string|string[] $classes
     * @return string|string[]
     */
    protected function spip_sanitize_classname($classes)
    {
        if (is_array($classes)) {
            return array_map('spip_sanitize_classname', $classes);
        }
        return preg_replace("/[^ 0-9a-z_\-+@]/i", "", $classes);
    }
}
