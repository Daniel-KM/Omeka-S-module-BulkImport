<?xml version="1.0" encoding="UTF-8"?>
<!--
    Description : Convertit un fichier mets en un item Omeka S avec les fichiers.

    Seules les options couramment utilisées par les prestataires de numérisation sont gérées.

    Configuration des options avec les valeurs par défaut :

    - basepath (__dirpath__)
        Url ou chemin de base pour les fichiers, avec le "/" final.
        La valeur spéciale par défaut `__dirpath__` permet d’insérér le dossier du fichier xml.
        Cette valeur n’est pas utilisée lorsque le fichier est une url complète (commençant par
        `https://` ou `http://`) ou un chemin complet (commençant par `file:///` ou `/` ou `\`).

    - basepath_force (0)
        Ajoute la variable `basepath` ci-dessus même pour les fichiers ayant un chemin complet
        (commençant par `file:///` ou `/` ou `\`).

    - filepath_replace_from ("")
        Chaîne à remplacer dans le chemin des fichiers ou l’url.
        Le remplacement s’effectue sur toute la chaîne et avant l’ajout du chemin de base.

    - filepath_replace_to ("")
        Chaîne de remplacement dans le chemin des fichiers ou l’url.
        Le remplacement s’effectue sur toute la chaîne et avant l’ajout du chemin de base.

    - skip_label_media (0)
        Ignorer le libellé du média en tant que dcterms:title.

    - skip_label_media_relation (0)
        Ignorer le libellé du média en tant que dcterms:title présent dans les relations.

    - table_of_contents (1)
        Ajouter la table des matières au format texte (1 ou text ou true), xml (2 ou xml) ou non (0 ou false). Activé par défaut.
        Elle peut-être utilisée par le module IIIF Server.

    - table_of_views (0)
        Ajouter la table de toutes les vues de manière structurée au format texte (1 ou text ou true), xml (2 ou xml) ou non (0 ou false).
        Elle peut être utilisée par le module IIIF Server.

    - list_of_views (0)
        Ajouter la liste des vues au format texte (1 ou text ou true), xml (2 ou xml) ou non (0 ou false).
        La liste des vues correspond à la table des vues sans arborescence et sans la quatrième
        colonne des sous-sections.
        Elle peut être utilisée par le module IIIF Server.

    - index_of_views (0)
        Ajouter l’index des vues au format texte (1 ou text ou true), xml (2 ou xml) ou non (0 ou false).
        La liste des vues correspond à la liste des libellés et des pages.
        Elle peut être utilisée par le module IIIF Server.

    - full_ranges (0)
        Détailler (1) ou non (0) la liste des sections dans la table pour éviter les longues listes dans les sections.
        Sinon, seuls les premier et dernier numéros de chaque section sont indiqués.
        Utilisé uniquement pour la table non-xml.

    # Paramètres automatiques.

    - filepath (valeur interne)
        Url ou chemin du fichier xml, automatiquement passée.

    - dirpath (valeur interne)
        Url ou chemin du dossier du fichier xml, automatiquement passée.

    TODO

    - [ ] Factorize some templates.

    @copyright Daniel Berthereau, 2021-2024
    @license CeCILL 2.1 https://cecill.info/licences/Licence_CeCILL_V2.1-fr.txt
-->

<xsl:stylesheet version="1.0"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
    xmlns:rdfs="http://www.w3.org/2000/01/rdf-schema#"
    xmlns:skos="http://www.w3.org/2004/02/skos/core#"

    xmlns:o="http://omeka.org/s/vocabs/o#"
    xmlns:dcterms="http://purl.org/dc/terms/"
    xmlns:bibo="http://purl.org/ontology/bibo/"
    xmlns:foaf="http://xmlns.com/foaf/0.1/"

    xmlns:bio="http://purl.org/vocab/bio/0.1/"

    xmlns:mets="http://www.loc.gov/METS/"
    xmlns:xlink="http://www.w3.org/1999/xlink"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:dc="http://purl.org/dc/elements/1.1/"

    exclude-result-prefixes="
        xsl rdf rdfs skos
        mets xlink xsi dc
        "
    >

    <!-- Si la table des matières est en texte, ne pas indenter. -->
    <!-- TODO Trouver un moyen xsl d’indenter et de garder les sauts de ligne sans cdata au moins pour la table des matières. -->
    <xsl:output
        method="xml"
        encoding="UTF-8"
        indent="yes"
        cdata-section-elements="dcterms:tableOfContents"
    />
    <!-- Préserver les espaces de la source par défaut. -->
    <!--
    <xsl:strip-space elements="*"/>
    <xsl:preserve-space elements="dcterms:tableOfContents"/>
    -->

    <!-- Paramètres -->

    <!-- Url ou chemin de base pour les fichiers. La valeur spéciale par défaut `__dirpath__` correspond au dossier du fichier xml. -->
    <xsl:param name="basepath">__dirpath__</xsl:param>

    <!-- Ajoute le chemin de base même quand le chemin du fichier ressemble à un chemin complet (commence par `file:///` ou `/` ou `\`). -->
    <xsl:param name="basepath_force">0</xsl:param>

    <!-- Chaîne à remplacer dans le chemin des fichiers ou l’url. -->
    <!-- Le remplacement s’effectue sur toute la chaîne et avant l’ajout du chemin de base. -->
    <xsl:param name="filepath_replace_from"></xsl:param>

    <!-- Chaîne de remplacement dans le chemin des fichiers ou l’url. -->
    <xsl:param name="filepath_replace_to"></xsl:param>

    <!-- Url ou chemin du dossier du fichier xml, automatiquement passée. -->
    <xsl:param name="dirpath"></xsl:param>

    <!-- Url ou chemin du fichier xml, automatiquement passée. -->
    <xsl:param name="filepath"></xsl:param>

    <!-- Ignorer le libellé du média. -->
    <xsl:param name="skip_label_media">0</xsl:param>

    <!-- Ignorer le libellé du média présent dans relation/label. -->
    <xsl:param name="skip_label_media_relation">0</xsl:param>

    <!-- Ajouter la table des matières au format texte (1 ou text ou true), xml (2 ou xml) ou non (0 ou false). Activé par défaut. -->
    <!-- Elle peut-être utilisée par le module IIIF Server. -->
    <!-- TODO Dans l’idéal, il faudrait tenir compte des informations de la structure : "book", "section", "page", rarement mis dans les numérisations de masse actuellement. -->
    <xsl:param name="table_of_contents">1</xsl:param>

    <!-- Ajouter la table des matières avec toutes les vues de manière structurée au format texte (1 ou text ou true), xml (2 ou xml) ou non (0 ou false). -->
    <!-- Elle peut-être utilisée par le module IIIF Server. -->
    <!-- Une option dans le module IiifServer permet d’afficher cette table complète via le table des matières. -->
    <xsl:param name="table_of_views">0</xsl:param>

    <!-- Ajouter la liste simple des vues au format texte (1 ou text ou true), xml (2 ou xml) ou non (0 ou false). -->
    <!-- Elle peut-être utilisée par le module IIIF Server. -->
    <xsl:param name="list_of_views">0</xsl:param>

    <!-- Ajouter l’index des vues au format texte (1 ou text ou true), xml (2 ou xml) ou non (0 ou false). -->
    <!-- Elle peut-être utilisée par le module IIIF Server. -->
    <xsl:param name="index_of_views">0</xsl:param>

    <!-- Détailler ou non la liste des sections dans la table non-xml. -->
    <xsl:param name="full_ranges">0</xsl:param>

    <!-- Constantes -->

    <xsl:variable name="end_of_line"><xsl:text>&#x0A;</xsl:text></xsl:variable>

    <!-- Utilisation de structMap pour avoir le bon ordre des fichiers, de préférence la carte physique. -->
    <!-- La plupart du temps, il n’y en a qu’une de toute façon. -->
    <xsl:variable name="structmap_index">
        <xsl:choose>
            <xsl:when test="/mets:mets/mets:structMap[@TYPE = 'physical']">
                <xsl:value-of select="count(/mets:mets/mets:structMap[@TYPE = 'physical']/preceding-sibling::mets:structMap) + 1"/>
            </xsl:when>
            <xsl:otherwise>
                <xsl:value-of select="count(/mets:mets)"/>
            </xsl:otherwise>
        </xsl:choose>
    </xsl:variable>

    <!--
    Certaines structures contiennent ou non un div pour les pointeurs :
    - <div><div><fptr/></div><div><fptr/></div></div>
    - <div><fptr/><div><fptr/></div></div>
    Le premier cas distingue clairement les sections et les pages.
    Le second cas est plus complexe à gérer pour créer la table des matières pour les groupes de pages.
    Cette valeur permet de déterminer le type de structure.
    -->
    <xsl:variable name="subdiv_fptr" select="count(/mets:mets/mets:structMap[$structmap_index]//mets:div[mets:div and mets:fptr]) = 0"/>

    <xsl:variable name="basepath_no_slash">
        <xsl:choose>
            <xsl:when test="$basepath = '__dirpath__'">
                <xsl:choose>
                    <xsl:when test="substring($dirpath, string-length($dirpath)) = '/' or substring($dirpath, string-length($dirpath)) = '\'">
                        <xsl:value-of select="substring(translate($dirpath, '\', '/'), 1, string-length($dirpath) - 1)"/>
                    </xsl:when>
                    <xsl:otherwise>
                        <xsl:value-of select="translate($dirpath, '\', '/')"/>
                    </xsl:otherwise>
                </xsl:choose>
            </xsl:when>
            <xsl:when test="substring($basepath, string-length($basepath)) = '/' or substring($basepath, string-length($basepath)) = '\'">
                <xsl:value-of select="substring(translate($basepath, '\', '/'), string-length($basepath) - 1)"/>
            </xsl:when>
            <xsl:otherwise>
                <xsl:value-of select="translate($basepath, '\', '/')"/>
            </xsl:otherwise>
        </xsl:choose>
    </xsl:variable>

    <xsl:variable name="basepath_slash">
        <xsl:value-of select="concat($basepath_no_slash, '/')"/>
    </xsl:variable>

    <!-- Récupère le chemin de base quand l’option basepath_force est vraie. -->
    <!-- Sans "/" final. -->
    <xsl:variable name="basepath_forced">
        <xsl:choose>
            <xsl:when test="$basepath_force = 1">
                <xsl:value-of select="$basepath_no_slash"/>
            </xsl:when>
            <xsl:otherwise>
                <xsl:text></xsl:text>
            </xsl:otherwise>
        </xsl:choose>
    </xsl:variable>

    <!-- Templates -->

    <!-- Identity template -->
    <xsl:template match="@*|node()">
        <xsl:copy>
            <xsl:apply-templates select="@*|node()"/>
        </xsl:copy>
    </xsl:template>

    <xsl:template match="/mets:mets">
        <xsl:comment>
            <xsl:text>Créé par mets_to_omeka.xsl</xsl:text>
        </xsl:comment>
        <resources>
            <!-- Item principal -->
            <resource>
                <xsl:attribute name="o:created">
                    <xsl:apply-templates select="mets:metsHdr/@CREATEDATE"/>
                </xsl:attribute>
                <xsl:attribute name="o:modified">
                    <xsl:apply-templates select="mets:metsHdr/@LASTMODDATE"/>
                </xsl:attribute>
                <xsl:apply-templates select="mets:dmdSec"/>
                <xsl:if test="$table_of_contents = '1' or $table_of_contents = 'text' or $table_of_contents = 'true' or $table_of_contents = '2' or $table_of_contents = 'xml'">
                    <xsl:apply-templates select="mets:structMap[$structmap_index]" mode="toc">
                        <xsl:with-param name="as_xml" select="$table_of_contents = '2' or $table_of_contents = 'xml'"/>
                        <xsl:with-param name="toc" select="true()"/>
                    </xsl:apply-templates>
                </xsl:if>
                <xsl:if test="$table_of_views = '1' or $table_of_views = 'text' or $table_of_views = '2' or $table_of_views = 'xml'">
                    <xsl:apply-templates select="mets:structMap[$structmap_index]" mode="toc">
                        <xsl:with-param name="as_xml" select="$table_of_views = '2' or $table_of_views = 'xml'"/>
                        <xsl:with-param name="tov" select="true()"/>
                    </xsl:apply-templates>
                </xsl:if>
                <xsl:if test="$list_of_views = '1' or $list_of_views = 'text' or $list_of_views = '2' or $list_of_views = 'xml'">
                    <xsl:apply-templates select="mets:structMap[$structmap_index]" mode="toc">
                        <xsl:with-param name="as_xml" select="$list_of_views = '2' or $list_of_views = 'xml'"/>
                        <xsl:with-param name="lov" select="true()"/>
                    </xsl:apply-templates>
                </xsl:if>
               <xsl:if test="$index_of_views = '1' or $index_of_views = 'text' or $index_of_views = '2' or $index_of_views = 'xml'">
                     <xsl:apply-templates select="mets:structMap[$structmap_index]" mode="toc">
                        <xsl:with-param name="as_xml" select="$index_of_views = '2' or $index_of_views = 'xml'"/>
                        <xsl:with-param name="iov" select="true()"/>
                    </xsl:apply-templates>
                </xsl:if>
                <!-- Fichiers -->
                <xsl:apply-templates select="mets:structMap[$structmap_index]//mets:fptr"/>
            </resource>
        </resources>
    </xsl:template>

    <xsl:template match="mets:dmdSec">
        <xsl:choose>
            <!-- Certains mets mettent un "dc:dc" ou un "record" intermédiaire. -->
            <!-- Certains indiquent utiliser "dc" mais utilisent aussi "dcterms". -->
            <xsl:when test="mets:mdWrap/@MDTYPE = 'DC' or mets:mdWrap/@MDTYPE = 'DCTERMS'">
                <xsl:apply-templates select="mets:mdWrap/mets:xmlData//dc:*[not(self::dc:dc)] | mets:mdWrap/mets:xmlData//dcterms:*[not(self::dcterms:dcterms)]"/>
            </xsl:when>
        </xsl:choose>
    </xsl:template>

    <xsl:template match="dc:*">
        <xsl:element name="dcterms:{local-name()}">
            <xsl:apply-templates select="@*|node()"/>
        </xsl:element>
    </xsl:template>

    <xsl:template match="mets:fptr">
        <xsl:variable name="file">
            <xsl:apply-templates select="." mode="file"/>
        </xsl:variable>
        <o:media o:ingester="file" ingest_url="{$file}">
            <xsl:if test="$skip_label_media != '1'">
                <xsl:variable name="fptr" select="."/>
                <xsl:variable name="href" select="/mets:mets/mets:fileSec//mets:file[@ID = $fptr/@FILEID]/mets:FLocat/@xlink:href" />
                <xsl:variable name="media_title_div">
                    <xsl:value-of select="normalize-space(parent::mets:div/@LABEL)"/>
                </xsl:variable>
                <xsl:variable name="media_title_relation">
                    <xsl:if  test="$skip_label_media_relation != '1'">
                        <xsl:apply-templates select="/mets:mets/mets:dmdSec//relations/relation[file_id = $href]/label"/>
                    </xsl:if>
                </xsl:variable>
                <xsl:variable name="media_title">
                    <xsl:choose>
                        <xsl:when test="$media_title_div != ''">
                            <xsl:value-of select="$media_title_div"/>
                        </xsl:when>
                        <xsl:otherwise>
                            <xsl:value-of select="normalize-space($media_title_relation)"/>
                        </xsl:otherwise>
                    </xsl:choose>
                </xsl:variable>
                <xsl:if test="$media_title != ''">
                    <dcterms:title>
                        <xsl:value-of select="$media_title"/>
                    </dcterms:title>
                </xsl:if>
            </xsl:if>
        </o:media>
    </xsl:template>

    <!-- Les pointeurs renvoient vers des numéros et non des noms de fichier. -->
    <xsl:template match="mets:fptr" mode="file">
        <xsl:variable name="fptr" select="."/>
        <xsl:apply-templates select="/mets:mets/mets:fileSec//mets:file[@ID = $fptr/@FILEID]/mets:FLocat/@xlink:href"/>
    </xsl:template>

    <!-- Récupère et normalise les urls et les chemins locaux pour avoir des adresses complètes. -->
    <xsl:template match="@xlink:href">
        <xsl:variable name="href">
            <xsl:choose>
                <xsl:when test="$filepath_replace_from != ''">
                    <xsl:call-template name="search_and_replace">
                        <xsl:with-param name="input" select="."/>
                        <xsl:with-param name="search_string" select="$filepath_replace_from"/>
                        <xsl:with-param name="replace_string" select="$filepath_replace_to"/>
                    </xsl:call-template>
                </xsl:when>
                <xsl:otherwise>
                    <xsl:value-of select="."/>
                </xsl:otherwise>
            </xsl:choose>
        </xsl:variable>
        <xsl:choose>
            <xsl:when test="substring($href, 1, 8) = 'https://' or substring($href, 1, 7) = 'http://'">
                <xsl:value-of select="$href"/>
            </xsl:when>
            <!-- Corrige les chemins locaux incorrects.
            Parfois, seuls un ou deux "/" sont présents, mais il en faut trois (le protocole est suivi de "://" et le chemin local commence par un "/"). -->
            <xsl:when test="substring($href, 1, 8) = 'file:///'">
                <xsl:value-of select="$basepath_forced"/>
                <xsl:value-of select="translate(substring($href, 8), '\', '/')"/>
            </xsl:when>
            <xsl:when test="substring($href, 1, 7) = 'file://'">
                <xsl:value-of select="$basepath_forced"/>
                <xsl:value-of select="translate(substring($href, 7), '\', '/')"/>
            </xsl:when>
            <xsl:when test="substring($href, 1, 6) = 'file:/'">
                <xsl:value-of select="$basepath_forced"/>
                <xsl:value-of select="translate(substring($href, 6), '\', '/')"/>
            </xsl:when>
            <xsl:when test="substring($href, 1, 1) = '/' or substring($href, 1, 1) = '\'">
                <xsl:value-of select="$basepath_forced"/>
                <xsl:value-of select="translate($href, '\', '/')"/>
            </xsl:when>
            <!-- Cas particulier du chemin relatif commençant par ".:". -->
            <xsl:when test="substring($href, 1, 2) = './' or substring($href, 1, 2) = '.\'">
                <xsl:value-of select="concat($basepath_slash, translate(substring($href, 3), '\', '/'))"/>
            </xsl:when>
            <!-- Sinon concaténation du nom de dossier et du fichier (chemin relatif). -->
            <xsl:otherwise>
                <xsl:value-of select="concat($basepath_slash, translate($href, '\', '/'))"/>
            </xsl:otherwise>
        </xsl:choose>
    </xsl:template>

    <!-- Create an iso date for omeka database (2022-11-12T13:14:15). -->
    <!-- Warning: timezone is lost. -->
    <xsl:template match="@CREATEDATE | @LASTMODDATE">
        <xsl:value-of select="substring(., 1, 19)"/>
    </xsl:template>

    <!-- Création de la table des matières. -->
    <!-- La table n’est pas la liste de l’ensemble des pages, mais uniquement celle des divisions. -->
    <!-- TODO Ajouter le type de document (book). -->
    <!-- TODO Ajouter le type de structure (physical/logical). -->
    <xsl:template match="mets:structMap" mode="toc">
        <xsl:param name="as_xml" select="false()"/>
        <xsl:param name="toc" select="false()"/>
        <xsl:param name="tov" select="false()"/>
        <xsl:param name="lov" select="false()"/>
        <xsl:param name="iov" select="false()"/>

        <dcterms:tableOfContents>
            <xsl:choose>
                <xsl:when test="$as_xml">
                    <xsl:attribute name="o:type">xml</xsl:attribute>
                    <xsl:choose>
                        <xsl:when test="$lov">
                            <xsl:apply-templates select="mets:div" mode="lov_xml"/>
                        </xsl:when>
                        <xsl:when test="$iov">
                            <c>
                                <xsl:if test="mets:div[1][not(mets:fptr)]/@LABEL">
                                    <xsl:attribute name="label">
                                        <xsl:value-of select="normalize-space(mets:div[1][not(mets:fptr)]/@LABEL)"/>
                                    </xsl:attribute>
                                </xsl:if>
                                <xsl:apply-templates select="//mets:fptr" mode="iov_xml"/>
                            </c>
                        </xsl:when>
                        <xsl:otherwise>
                            <xsl:apply-templates select="mets:div" mode="table_format_xml">
                                <xsl:with-param name="toc" select="$toc"/>
                                <xsl:with-param name="tov" select="$tov"/>
                            </xsl:apply-templates>
                        </xsl:otherwise>
                    </xsl:choose>
                </xsl:when>
                <xsl:otherwise>
                    <xsl:choose>
                        <xsl:when test="$iov">
                            <xsl:apply-templates select="//mets:fptr" mode="iov_text"/>
                        </xsl:when>
                        <xsl:otherwise>
                            <xsl:apply-templates select="mets:div" mode="table_format_text">
                                <xsl:with-param name="toc" select="$toc"/>
                                <xsl:with-param name="tov" select="$tov"/>
                                <xsl:with-param name="lov" select="$lov"/>
                            </xsl:apply-templates>
                        </xsl:otherwise>
                    </xsl:choose>
                </xsl:otherwise>
            </xsl:choose>
        </dcterms:tableOfContents>
    </xsl:template>

    <!-- Version xml de la table des matières. -->
    <xsl:template match="mets:div" mode="table_format_xml">
        <xsl:param name="toc" select="false()"/>
        <xsl:param name="tov" select="false()"/>

        <c>
            <xsl:attribute name="id">
                <xsl:text>r</xsl:text>
                <xsl:number level="multiple" format="1-1" grouping-size="0"/>
            </xsl:attribute>
            <xsl:attribute name="label">
                <xsl:value-of select="normalize-space(@LABEL)"/>
            </xsl:attribute>
            <!-- Le numéro de la vue est le numéro du fichier numérisé. Ce n'est pas le numéro de la page, ni de l'index (quand il manque des images). -->
            <xsl:attribute name="views">
                <xsl:variable name="view_first">
                    <xsl:apply-templates select="." mode="view_number"/>
                </xsl:variable>
                <xsl:variable name="view_last">
                    <xsl:choose>
                        <xsl:when test="following-sibling::mets:div[1]">
                            <xsl:apply-templates select="following-sibling::mets:div[1]" mode="view_number"/>
                        </xsl:when>
                        <xsl:otherwise>
                            <xsl:apply-templates select="parent::mets:div/following-sibling::mets:div[1]" mode="view_number"/>
                        </xsl:otherwise>
                    </xsl:choose>
                </xsl:variable>
                <xsl:variable name="views">
                    <xsl:choose>
                        <xsl:when test="$view_first = ''">
                            <xsl:text></xsl:text>
                        </xsl:when>
                        <xsl:when test="$view_last = '' or $view_first &gt;= ($view_last - 1)">
                            <xsl:value-of select="$view_first"/>
                        </xsl:when>
                        <xsl:otherwise>
                            <xsl:value-of select="concat($view_first, '-', $view_last - 1)"/>
                        </xsl:otherwise>
                    </xsl:choose>
                </xsl:variable>
                <xsl:value-of select="$views"/>
            </xsl:attribute>
            <!-- Ajout d’information : position et nom du fichier, généralement inutile ; le type pourrait être utilisé pour bien distinguer section et pages. -->
            <!-- Le numéro d’ordre dans la section en cours n’est pas réellement utile.
            <xsl:attribute name="order">
                <xsl:number level="single" format="1"/>
            </xsl:attribute>
            <xsl:if test="@TYPE and @TYPE != ''">
                <xsl:attribute name="type">
                    <xsl:value-of select="@TYPE"/>
                </xsl:attribute>
            </xsl:if>
            <xsl:if test="mets:fptr">
                <xsl:attribute name="file">
                    <xsl:value-of select="$basepath_slash"/>
                    <xsl:apply-templates select="mets:fptr" mode="file_exlibris"/>
                </xsl:attribute>
            </xsl:if>
            -->
            <!-- La liste des sections est inutile car la structure est hiérarchique. Cf. mode toc si on veut l’ajouter. -->
            <!-- Ligne suivante. -->
            <xsl:choose>
                <xsl:when test="$toc">
                    <!-- Les sections qui ont un div interne ou dont l’un des siblings a une div interne. -->
                    <!-- Utiliser le parent ne fonctionne pas ! -->
                    <xsl:apply-templates select="mets:div[self::mets:div[mets:div] | preceding-sibling::mets:div[mets:div] | following-sibling::mets:div[mets:div]]" mode="table_format_xml">
                        <xsl:with-param name="toc" select="true()"/>
                    </xsl:apply-templates>
                </xsl:when>
                <xsl:when test="$tov">
                    <xsl:apply-templates select="mets:div" mode="table_format_xml">
                        <xsl:with-param name="tov" select="true()"/>
                    </xsl:apply-templates>
                </xsl:when>
            </xsl:choose>
        </c>
    </xsl:template>

    <!-- Version codifiée de la table des matières. -->
    <xsl:template match="mets:div" mode="table_format_text">
        <xsl:param name="toc" select="false()"/>
        <xsl:param name="tov" select="false()"/>
        <xsl:param name="lov" select="false()"/>
        <xsl:param name="level" select="0"/>
        <xsl:variable name="indentation">
            <xsl:value-of select="substring('                                                                                ', 1, $level * 4)"/>
        </xsl:variable>
        <xsl:variable name="range_number">
            <xsl:number level="multiple" format="1-1" grouping-size="0"/>
        </xsl:variable>
        <!-- Le numéro de la vue est le numéro du fichier numérisé. Ce n'est pas le numéro de la page, ni de l'index (quand il manque des images). -->
        <!-- L’ensemble des vues correspondand à la section est indiquée, conformément à la logique du mets. -->
        <xsl:variable name="view_first">
            <xsl:apply-templates select="." mode="view_number"/>
        </xsl:variable>
        <xsl:variable name="view_last">
            <xsl:choose>
                <xsl:when test="following-sibling::mets:div[1]">
                    <xsl:apply-templates select="following-sibling::mets:div[1]" mode="view_number"/>
                </xsl:when>
                <xsl:otherwise>
                    <xsl:apply-templates select="parent::mets:div/following-sibling::mets:div[1]" mode="view_number"/>
                </xsl:otherwise>
            </xsl:choose>
        </xsl:variable>
        <xsl:variable name="views">
            <xsl:choose>
                <xsl:when test="$view_first = ''">
                    <xsl:text></xsl:text>
                </xsl:when>
                <xsl:when test="$lov">
                    <xsl:value-of select="$view_first"/>
                </xsl:when>
                <xsl:when test="$view_last = '' or $view_first &gt;= ($view_last - 1)">
                    <xsl:value-of select="$view_first"/>
                </xsl:when>
                <xsl:otherwise>
                    <xsl:value-of select="concat($view_first, '-', $view_last - 1)"/>
                </xsl:otherwise>
            </xsl:choose>
        </xsl:variable>
        <xsl:variable name="views_comma">
            <xsl:choose>
                <xsl:when test="$lov">
                    <xsl:text></xsl:text>
                </xsl:when>
                <xsl:otherwise>
                    <xsl:text>,</xsl:text>
                </xsl:otherwise>
            </xsl:choose>
        </xsl:variable>
        <!-- La précédente version contenait la liste des sous-sections avec les pages
        et gérait le cas où le div en cours contenait un fptr non encapsulé avec un div comme les div enfants ("self or child"). -->
        <xsl:variable name="ranges_list">
            <xsl:choose>
                <xsl:when test="not($lov) and mets:div">
                    <xsl:apply-templates select="mets:div" mode="ranges_sub"/>
                </xsl:when>
                <xsl:otherwise>
                    <xsl:text>-</xsl:text>
                </xsl:otherwise>
            </xsl:choose>
        </xsl:variable>
        <xsl:variable name="ranges">
            <xsl:choose>
                <xsl:when test="$lov">
                    <xsl:text></xsl:text>
                </xsl:when>
                <xsl:when test="
                    $full_ranges = '1'
                    or (string-length($ranges_list) - string-length(translate($ranges_list, ';', ''))) &lt; 2
                    ">
                    <xsl:value-of select="concat(' ', normalize-space($ranges_list))"/>
                </xsl:when>
                <xsl:otherwise>
                    <xsl:variable name="range_first">
                        <xsl:value-of select="substring-before($ranges_list, ';')"/>
                    </xsl:variable>
                    <xsl:variable name="range_last">
                        <xsl:call-template name="string_part_last">
                            <xsl:with-param name="string" select="$ranges_list"/>
                            <xsl:with-param name="character" select="';'"/>
                        </xsl:call-template>
                    </xsl:variable>
                    <xsl:value-of select="concat(' ', normalize-space($range_first), '/', normalize-space($range_last))"/>
                </xsl:otherwise>
            </xsl:choose>
        </xsl:variable>
        <xsl:value-of select="concat(
            $indentation,
            'r', $range_number, ', ',
            normalize-space(@LABEL), ', ',
            $views, $views_comma,
            $ranges,
            $end_of_line
        )"/>
        <!-- Ligne suivante. -->
        <xsl:choose>
            <xsl:when test="$toc">
                <!-- Les sections qui ont un div interne ou dont l’un des siblings a une div interne. -->
                <!-- Utiliser le parent ne fonctionne pas ! -->
                <xsl:apply-templates select="mets:div[self::mets:div[mets:div] | preceding-sibling::mets:div[mets:div] | following-sibling::mets:div[mets:div]]" mode="table_format_text">
                    <xsl:with-param name="toc" select="true()"/>
                    <xsl:with-param name="level" select="$level + 1"/>
                </xsl:apply-templates>
            </xsl:when>
            <xsl:when test="$tov or $lov">
                <xsl:apply-templates select="mets:div" mode="table_format_text">
                    <xsl:with-param name="tov" select="$tov"/>
                    <xsl:with-param name="lov" select="$lov"/>
                    <xsl:with-param name="level" select="$level + 1"/>
                </xsl:apply-templates>
            </xsl:when>
        </xsl:choose>
    </xsl:template>

    <xsl:template match="mets:div" mode="lov_xml">
        <xsl:variable name="is_first_div">
            <xsl:value-of select="generate-id(.) = generate-id(//mets:div[1])"/>
        </xsl:variable>

        <xsl:choose>
            <xsl:when test="mets:fptr or $is_first_div">
                <c>
                    <xsl:attribute name="id">
                        <xsl:text>r</xsl:text>
                        <xsl:number level="multiple" format="1-1" grouping-size="0"/>
                    </xsl:attribute>
                    <xsl:attribute name="label">
                        <xsl:value-of select="normalize-space(@LABEL)"/>
                    </xsl:attribute>
                    <xsl:if test="mets:fptr">
                        <xsl:attribute name="views">
                            <xsl:apply-templates select="." mode="view_number"/>
                        </xsl:attribute>
                    </xsl:if>
                    <!-- Ligne suivante imbriquée seulement pour le premier niveau. -->
                    <xsl:if test="$is_first_div">
                        <xsl:apply-templates select="mets:div" mode="table_format_xml">
                            <xsl:with-param name="lov" select="true()"/>
                        </xsl:apply-templates>
                    </xsl:if>
                </c>
            </xsl:when>
        </xsl:choose>

        <!-- Ligne suivante. -->
        <xsl:if test="not($is_first_div)">
            <xsl:apply-templates select="mets:div" mode="table_format_xml">
                <xsl:with-param name="lov" select="true()"/>
            </xsl:apply-templates>
        </xsl:if>
    </xsl:template>

    <xsl:template match="mets:fptr" mode="iov_xml">
        <c label="{normalize-space(parent::mets:div/@LABEL)}" views="{position()}"/>
    </xsl:template>

    <xsl:template match="mets:fptr" mode="iov_text">
        <xsl:value-of select="concat(normalize-space(parent::mets:div/@LABEL), ', ', position(), $end_of_line)"/>
    </xsl:template>

    <xsl:template match="mets:div" mode="view_number">
        <xsl:apply-templates select="mets:fptr" mode="position"/>
    </xsl:template>

    <!-- Liste des sous-sections. -->
    <xsl:template match="mets:div" mode="ranges_sub">
        <xsl:if test="position() != 1">
            <xsl:text>; </xsl:text>
        </xsl:if>
        <xsl:text>r</xsl:text>
        <xsl:number level="multiple" format="1-1" grouping-size="0"/>
    </xsl:template>

    <!-- Liste des sections ou des positions de page. -->
    <!-- Attention : ne pas compter les divs, mais les fptr, c’est-à-dire la position des fichiers dans les div. -->
    <xsl:template match="mets:div" mode="range_standard">
        <xsl:variable name="position_fptr">
            <xsl:apply-templates select="mets:fptr" mode="position"/>
        </xsl:variable>
        <xsl:choose>
            <!-- Section nommée contenant des pages ou des sous-sections. -->
            <!-- Attention : contient "self" qui peut contenir un fptr sans sous div. -->
            <xsl:when test="mets:div">
                <xsl:if test="position() != 1">
                    <xsl:text>; </xsl:text>
                </xsl:if>
                <xsl:choose>
                    <xsl:when test="not($subdiv_fptr) and position() = 1">
                        <xsl:value-of select="$position_fptr"/>
                    </xsl:when>
                    <xsl:otherwise>
                        <xsl:text>r</xsl:text>
                        <xsl:number level="multiple" format="1-1" grouping-size="0"/>
                    </xsl:otherwise>
                </xsl:choose>
            </xsl:when>
            <!-- Nombre de pages indépendantes ininterrompues : sans sous-sections non séparées par une section. -->
            <!-- Attention : ne pas compter les divs, mais les fptr, c’est-à-dire la position des fichiers. -->
            <xsl:otherwise>
                <xsl:variable name="est_premier_dans_serie" select="position() = 1 or generate-id(preceding-sibling::mets:div[1]) != generate-id(preceding-sibling::mets:div[not(mets:div)][1])"/>
                <xsl:variable name="est_dernier_dans_serie" select="not(following-sibling::mets:div) or generate-id(following-sibling::mets:div) != generate-id(following-sibling::mets:div[not(mets:div)])"/>
                <xsl:choose>
                    <!-- Liste de toutes les pages. -->
                    <xsl:when test="$est_premier_dans_serie and $est_dernier_dans_serie">
                        <xsl:if test="position() != 1">
                            <xsl:text>; </xsl:text>
                        </xsl:if>
                        <xsl:value-of select="$position_fptr"/>
                    </xsl:when>
                    <!-- Liste des groupes de pages entre deux sections. -->
                    <xsl:when test="$est_premier_dans_serie">
                        <xsl:if test="position() != 1">; </xsl:if>
                        <xsl:value-of select="$position_fptr"/>
                    </xsl:when>
                    <xsl:when test="$est_dernier_dans_serie">
                        <!-- Ne pas tenir compte de la section "book". -->
                        <xsl:if test="$position_fptr != '1'">
                            <xsl:text>-</xsl:text>
                        </xsl:if>
                        <xsl:value-of select="$position_fptr"/>
                    </xsl:when>
                </xsl:choose>
            </xsl:otherwise>
        </xsl:choose>
    </xsl:template>

    <!-- Liste des sections ou des positions de page pour la liste complète. -->
    <!-- Attention : ne pas compter les divs, mais les fptr, c’est-à-dire la position des fichiers dans les div. -->
    <xsl:template match="mets:div" mode="range_full">
        <xsl:if test="position() != 1">
            <xsl:text>; </xsl:text>
        </xsl:if>
        <xsl:text>r</xsl:text>
        <xsl:number level="multiple" format="1-1" grouping-size="0"/>
    </xsl:template>

    <!-- Position du pointeur en cours dans la structure en cours. -->
    <xsl:template match="mets:fptr" mode="position">
        <xsl:variable name="structMapId" select="ancestor::mets:structMap/@ID"/>
        <xsl:value-of select="count(preceding::mets:fptr[ancestor::mets:structMap/@ID = $structMapId]) + 1"/>
    </xsl:template>

    <!-- Remplace les entités html (sauf dans cdata). -->
    <!-- Inutile.
    <xsl:template match="text()">
        <xsl:copy-of select="normalize-space(.)"/>
    </xsl:template>
    -->

    <!-- Remplace une chaîne par une autre. -->
    <!-- @see https://www.oreilly.com/library/view/xslt-cookbook/0596003722/ch01s07.html -->
    <xsl:template name="search_and_replace">
        <xsl:param name="input"/>
        <xsl:param name="search_string"/>
        <xsl:param name="replace_string"/>
        <xsl:choose>
            <!-- See if the input contains the search string -->
            <xsl:when test="$search_string and contains($input, $search_string)">
                <!-- If so, then concatenate the substring before the search
                string to the replacement string and to the result of
                recursively applying this template to the remaining substring. -->
                <xsl:value-of select="substring-before($input, $search_string)"/>
                <xsl:value-of select="$replace_string"/>
                <xsl:call-template name="search_and_replace">
                    <xsl:with-param name="input" select="substring-after($input, $search_string)"/>
                    <xsl:with-param name="search_string" select="$search_string"/>
                    <xsl:with-param name="replace_string" select="$replace_string"/>
                </xsl:call-template>
            </xsl:when>
            <xsl:otherwise>
                <!-- There are no more occurences of the search string so
                just return the current input string -->
                <xsl:value-of select="$input"/>
            </xsl:otherwise>
        </xsl:choose>
    </xsl:template>

    <xsl:template name="string_part_last">
        <xsl:param name="string"/>
        <xsl:param name="character"/>
        <xsl:choose>
            <xsl:when test="contains($string, $character)">
                <xsl:call-template name="string_part_last">
                    <xsl:with-param name="string" select="substring-after($string, $character)"/>
                    <xsl:with-param name="character" select="$character"/>
                </xsl:call-template>
            </xsl:when>
            <xsl:otherwise>
                <xsl:value-of select="$string"/>
            </xsl:otherwise>
        </xsl:choose>
    </xsl:template>

</xsl:stylesheet>
