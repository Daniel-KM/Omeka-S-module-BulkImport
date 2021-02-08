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
    const EXTRAIRE_MULTI = '@<multi>(.*?)</multi>@sS';

    // Correcteur typographique
    const _TYPO_PROTEGER = "!':;?~%-";
    const _TYPO_PROTECTEUR = "\x1\x2\x3\x4\x5\x6\x7\x8";
    // const _TYPO_BALISE = ",</?[a-z!][^<>]*[" . preg_quote(_TYPO_PROTEGER) . "][^<>]*>,imsS";

    // XHTML - Preserver les balises-bloc : on liste ici tous les elements
    // dont on souhaite qu'ils provoquent un saut de paragraphe

    /**
     * @link https://git.spip.net/spip/spip/src/commit/4c6c1bb2f85f07890a0bc571a1e4936ae3e41e3a/ecrire/inc/texte_mini.php#L60-L67
     *
     * @var string
     */
    const BALISES_BLOCS = 'address|applet|article|aside|blockquote|button|center|d[ltd]|div|fieldset|fig(ure|caption)|footer|form|h[1-6r]|hgroup|head|header|iframe|li|map|marquee|nav|noscript|object|ol|pre|section|t(able|[rdh]|body|foot|extarea)|ul|script|style';
    // const self::BALISES_BLOCS_REGEXP = ',</?(' . self::BALISES_BLOCS . ')[>[:space:]],iS';

    const _PROTEGE_BLOCS = ',<(html|code|cadre|frame|script|style)(\s[^>]*)?>(.*)</\1>,UimsS';


    /**
     * @link spip/ecrire/inc/filtres.php
     */

    /**
     * @link spip/ecrire/inc/filtres.php
     */

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
        $regs = [];
        if ($letexte
            && preg_match_all(self::_EXTRAIRE_MULTI, $letexte, $regs, PREG_SET_ORDER)
        ) {
            if (!$lang) {
                $lang = $GLOBALS['spip_lang'];
            }

            // Compatibilité avec le prototype de fonction précédente qui utilisait un boolean
            if (is_bool($options)) {
                $options = ['echappe_span' => $options, 'lang_defaut' => LANGUE_PAR_DEFAUT];
            }
            if (!isset($options['echappe_span'])) {
                $options = array_merge($options, ['echappe_span' => false]);
            }
            if (!isset($options['lang_defaut'])) {
                $options = array_merge($options, ['lang_defaut' => LANGUE_PAR_DEFAUT]);
            }

            // include_spip('inc/lang');
            foreach ($regs as $reg) {
                // chercher la version de la langue courante
                $trads = $this->extraire_trads($reg[1]);
                if ($l = $this->approcher_langue($trads, $lang)) {
                    $trad = $trads[$l];
                } else {
                    if ($options['lang_defaut'] == 'aucune') {
                        $trad = '';
                    } else {
                        // langue absente, prendre le fr ou une langue précisée (meme comportement que inc/traduire.php)
                        // ou la premiere dispo
                        // mais typographier le texte selon les regles de celle-ci
                        // Attention aux blocs multi sur plusieurs lignes
                        if (!$l = $this->approcher_langue($trads, $options['lang_defaut'])) {
                            $l = key($trads);
                        }
                        $trad = $trads[$l];

                        // Désactive la typographie puisque ce n'est pas pour l'affichage.
                        // $typographie = charger_fonction(lang_typo($l), 'typographie');
                        // $trad = $typographie($trad);

                        // Tester si on echappe en span ou en div
                        // il ne faut pas echapper en div si propre produit un seul paragraphe
                        // include_spip('inc/texte');
                        $trad_propre = preg_replace(",(^<p[^>]*>|</p>$),Uims", "", $this->propre($trad));
                        $mode = preg_match(',</?(' . self::BALISES_BLOCS . ')[>[:space:]],iS', $trad_propre) ? 'div' : 'span';
                        $trad = $this->code_echappement($trad, 'multi', false, $mode);
                        $trad = str_replace("'", '"', $this->inserer_attribut($trad, 'lang', $l));
                        if ($this->lang_dir($l) !== $this->lang_dir($lang)) {
                            $trad = str_replace("'", '"', $this->inserer_attribut($trad, 'dir', $this->lang_dir($l)));
                        }
                        if (!$options['echappe_span']) {
                            $trad = $this->echappe_retour($trad, 'multi');
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
        $regs = [];
        // ce reg fait planter l'analyse multi s'il y a de l'{italique} dans le champ
        //	while (preg_match("/^(.*?)[{\[]([a-z_]+)[}\]]/siS", $bloc, $regs)) {
        while (preg_match("/^(.*?)[\[]([a-z_]+)[\]]/siS", $bloc, $regs)) {
            $texte = trim($regs[1]);
            if ($texte || $lang) {
                $trads[$lang] = $texte;
            }
            $bloc = substr($bloc, strlen($regs[0]));
            $lang = $regs[2];
        }
        $trads[$lang] = $bloc;

        return $trads;
    }

    /**
     * @link spip/ecrire/inc/lang.php
     */

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
     * @link spip/ecrire/inc/texte.php
     */

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

        // Dans l'espace prive on se mefie de tout contenu dangereux
        // avant echappement des balises <html>
        // https://core.spip.net/issues/3371
        // et aussi dans l'espace public si la globale filtrer_javascript = -1
        // https://core.spip.net/issues/4166
        if ($interdire_script
            || $GLOBALS['filtrer_javascript'] == -1
            || (isset($env['espace_prive']) && $env['espace_prive'] && $GLOBALS['filtrer_javascript'] <= 0)
            || (isset($env['wysiwyg']) && $env['wysiwyg'] && $GLOBALS['filtrer_javascript'] <= 0)
        ) {
            $t = $this->echapper_html_suspect($t, false);
        }
        $t = $this->echappe_html($t);
        $t = $this->expanser_liens($t, $connect, $env);
        $t = $this->traiter_raccourcis($t);
        $t = $this->echappe_retour_modeles($t, $interdire_script);

        return $t;
    }

    /**
     * @link spip/ecrire/inc/texte_mini.php
     */

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
        // static $echapper_html_suspect;

        if (!$texte || !is_string($texte)) {
            return $texte;
        }
        /*
        if (!isset($echapper_html_suspect)) {
            $echapper_html_suspect = charger_fonction('echapper_html_suspect', 'inc', true);
        }
        // si fonction personalisee, on delegue
        if ($echapper_html_suspect) {
            return $echapper_html_suspect($texte, $strict);
        }
        */

        if (strpos($texte, '<') === false
            || strpos($texte, '=') === false
        ) {
            return $texte;
        }

        // quand c'est du texte qui passe par propre on est plus coulant tant qu'il y a pas d'attribut du type onxxx=
        // car sinon on declenche sur les modeles ou ressources
        if (!$strict &&
            (strpos($texte, 'on') === false || !preg_match(",<\w+.*\bon\w+\s*=,UimsS", $texte))
        ) {
            return $texte;
        }

        // on teste sur strlen car safehtml supprime le contenu dangereux
        // mais il peut aussi changer des ' en " sur les attributs html,
        // donc un test d'egalite est trop strict
        if (strlen($this->safehtml($texte)) !== strlen($texte)) {
            $texte = str_replace("<", "&lt;", $texte);
            /*
            if (!function_exists('attribut_html')) {
                include_spip('inc/filtres');
            }
            */
            $texte = "<mark class='danger-js' title='" . $this->attribut_html(_T('erreur_contenu_suspect')) . "'>⚠️</mark> " . $texte;
        }

        return $texte;
    }
}
