<?xml version="1.0" encoding="UTF-8"?>
<!--
    Description : Convertit un fichier mets en un item Omeka S avec les fichiers.

    Seules les options couramment utilisées par les prestataires de numérisation sont gérées.

    @copyright Daniel Berthereau, 2021-2022
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

    <xsl:output method="xml" encoding="UTF-8" indent="yes"/>

    <xsl:strip-space elements="*"/>

    <!-- Paramètres -->

    <!-- Chemin ou url jusqu'au dossier d'import. Inclure le "/" final. -->
    <xsl:param name="basepath"></xsl:param>

    <!-- Ajouter la table des matières pour iiif (cf. module IIIF Server). -->
    <!-- TODO Dans l'idéal, il faudrait tenir compte des informations de la structure : "book", "section", "page". -->
    <xsl:param name="toc_iiif">1</xsl:param>

    <!-- Détailler ou non la liste des pages dans la table pour éviter les longues listes de nombres dans les sections. -->
    <xsl:param name="full_page_ranges">0</xsl:param>

    <!-- Constantes -->

    <!--
    Certaines structures contiennent ou non un div pour les pointeurs :
    - <div><div><fptr></div><div><fptr></div></div>
    - <div><fptr><div><fptr></div></div>
    Le premier cas distingue clairement les sections et les pages.
    Le second cas est plus complexe à gérer pour créer la table des matières pour les groupes de pages.
    Cette valeur permet de déterminer le type de structure.
    -->
    <xsl:variable name="subdiv_fptr" select="count(/mets:mets/mets:structMap//mets:div[mets:div and mets:fptr]) = 0"/>

    <!-- Templates -->

    <xsl:template match="/mets:mets">
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
                <xsl:if test="$toc_iiif = '1'">
                    <xsl:apply-templates select="mets:structMap" mode="toc"/>
                </xsl:if>
                <!-- Fichiers -->
                <!-- Utilisation de structMap pour avoir le bon ordre des fichiers, de préférence la carte physique. -->
                <xsl:choose>
                    <xsl:when test="mets:structMap[@TYPE = 'physical']">
                        <xsl:apply-templates select="mets:structMap[@TYPE = 'physical']//mets:fptr"/>
                    </xsl:when>
                    <xsl:otherwise>
                        <xsl:apply-templates select="mets:structMap[1]//mets:fptr"/>
                    </xsl:otherwise>
                </xsl:choose>
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
        <o:media o:ingester="file">
            <xsl:value-of select="concat($basepath, $file)"/>
        </o:media>
    </xsl:template>

    <!-- Les pointeurs renvoient vers des numéros et non des noms de fichier. -->
    <xsl:template match="mets:fptr" mode="file">
        <xsl:variable name="fptr" select="."/>
        <xsl:apply-templates select="/mets:mets/mets:fileSec//mets:file[@ID = $fptr/@FILEID]/mets:FLocat/@xlink:href"/>
    </xsl:template>

    <xsl:template match="@xlink:href">
        <xsl:choose>
            <xsl:when test="substring(., 1, 2) = './' or substring(., 1, 2) = '.\'">
                <xsl:value-of select="translate(substring(., 3), '\', '/')"/>
            </xsl:when>
            <xsl:otherwise>
                <xsl:value-of select="translate(., '\', '/')"/>
            </xsl:otherwise>
        </xsl:choose>
    </xsl:template>

    <!-- Create an iso date for omeka database (2022-11-12T13:14:15). -->
    <!-- Warning: timezone is lost. -->
    <xsl:template match="@CREATEDATE | @LASTMODDATE">
        <xsl:value-of select="substring(., 1, 19)"/>
    </xsl:template>

    <!-- Création de la table des matières. -->
    <!-- La table n'est pas la liste de l'ensemble des pages, mais uniquement celle des divisions. -->
    <!-- TODO Ajouter le type de document (book). -->
    <!-- TODO Ajouter le type de structure (physical/logical). -->
    <xsl:template match="mets:structMap" mode="toc">
        <dcterms:tableOfContents o:type="xml">
            <xsl:apply-templates select="mets:div" mode="toc"/>
        </dcterms:tableOfContents>
    </xsl:template>

    <xsl:template match="mets:div" mode="toc">
        <c>
            <xsl:attribute name="id">
                <xsl:text>r</xsl:text>
                <xsl:number level="multiple" format="1-1" grouping-size="0"/>
            </xsl:attribute>
            <xsl:attribute name="label">
                <xsl:value-of select="@LABEL"/>
            </xsl:attribute>
            <!-- TODO La liste est inutile si elle ne contient que des sections, pas des pages individuelles. -->
            <xsl:attribute name="range">
                <xsl:choose>
                    <xsl:when test="$subdiv_fptr">
                        <xsl:apply-templates select="mets:div" mode="range"/>
                    </xsl:when>
                    <!-- La liste contient le div en cours, car il peut contenir un fptr non encapsulé avec un div comme les div enfants ("self or child"). -->
                    <xsl:otherwise>
                        <xsl:apply-templates select=". | mets:div" mode="range"/>
                    </xsl:otherwise>
                </xsl:choose>
            </xsl:attribute>
            <!-- Le nom du fichier est généralement inutile.
            <xsl:attribute name="file">
                <xsl:value-of select="$basepath"/>
                <xsl:apply-templates select="mets:fptr" mode="file_exlibris"/>
            </xsl:attribute>
            -->
            <xsl:apply-templates select="mets:div[mets:div]" mode="toc"/>
        </c>
    </xsl:template>

    <!-- Liste des sections ou des positions de page. -->
    <!-- Attention : ne pas compter les divs, mais les fptr, c'est à dire la position des fichiers dans les div. -->
    <xsl:template match="mets:div" mode="range">
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
            <xsl:otherwise>
                <!-- Nombre de pages indépendantes ininterrompues : sans sous-sections non séparées par une section. -->
                <!-- Attention : ne pas compter les divs, mais les fptr, c'est à dire la position des fichiers. -->
                <xsl:variable name="est_premier_dans_serie" select="position() = 1 or generate-id(preceding-sibling::mets:div[1]) != generate-id(preceding-sibling::mets:div[not(mets:div)][1])"/>
                <xsl:variable name="est_dernier_dans_serie" select="not(following-sibling::mets:div) or generate-id(following-sibling::mets:div) != generate-id(following-sibling::mets:div[not(mets:div)])"/>
                <xsl:choose>
                    <!-- Liste de toutes les pages. -->
                    <xsl:when test="$full_page_ranges = '1' or ($est_premier_dans_serie and $est_dernier_dans_serie)">
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

    <!-- Position du pointeur en cours dans la structure en cours. -->
    <xsl:template match="mets:fptr" mode="position">
        <xsl:variable name="structMapId" select="ancestor::mets:structMap/@ID"/>
        <xsl:value-of select="count(preceding::mets:fptr[ancestor::mets:structMap/@ID = $structMapId]) + 1"/>
    </xsl:template>

    <!-- Identity template -->
    <xsl:template match="@*|node()">
        <xsl:copy>
            <xsl:apply-templates select="@*|node()"/>
        </xsl:copy>
    </xsl:template>

</xsl:stylesheet>
