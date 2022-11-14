<?xml version="1.0" encoding="UTF-8"?>
<!--
    Description : Convertit un fichier mods en un item Omeka S avec les fichiers.

    La conversion se fait à plat, sans ressources associées, et uniquement pour les métadonnées courantes.
    Cela concerne aussi relatedItem. Il n'y a donc pas tous les détails pour les auteurs, les informations
    d'exemplaire (holding), etc.

    Pour faire des relations correctes ou des arborescences : il est préférable d'utiliser la feuille de
    conversion complète.

    Suit les recommandations de [mods](https://www.loc.gov/standards/mods/mods-dcsimple.html),
    en les adaptant au mods v3 et au Dublin Core complet, avec bibo.

    Pour mods v3.7/8.

    @todo Utiliser les uris @valueURI et authorityURI et autres (via un template générique).
    @todo Utiliser les lang.
    @todo Vérifier si la valeur est http pour mettre le type de données.

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

    xmlns:mods="http://www.loc.gov/mods/v3"
    xmlns:xlink="http://www.w3.org/1999/xlink"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:dc="http://purl.org/dc/elements/1.1/"

    exclude-result-prefixes="
        xsl rdf rdfs skos
        "
    >

    <xsl:output method="xml" encoding="UTF-8" indent="yes"/>

    <xsl:strip-space elements="*"/>

    <!-- Constantes -->

    <!-- Paramètres -->

    <!-- Identity template -->
    <xsl:template match="@*|node()">
        <xsl:copy>
            <xsl:apply-templates select="@*|node()"/>
        </xsl:copy>
    </xsl:template>

    <xsl:template match="/mods:mods">
        <resources>
            <!-- Item principal -->
            <xsl:element name="resource">
                <xsl:if test="mods:recordInfo/@recordCreationDate">
                    <xsl:attribute name="o:created">
                        <xsl:apply-templates select="mods:recordInfo/@recordCreationDate"/>
                    </xsl:attribute>
                </xsl:if>
                <xsl:if test="mods:recordInfo/@recordCreationDate">
                    <xsl:attribute name="o:modified">
                        <xsl:apply-templates select="mods:recordInfo/@recordChangeDate"/>
                    </xsl:attribute>
                </xsl:if>
                <!-- TODO Ajouter la classe de ressource quand elle est convertible en dc. -->
                <!--
                <xsl:if test="mods:typeOfResource">
                    <xsl:attribute name="o:class"></xsl:attribute>
                </xsl:attribute>
                -->

                <!-- TODO Reprendre languageOfCataloging pour mettre la langue sur toutes les propriétés textuelles -->
                <!-- TODO Faire un template générique pour les uri, lang, authority et autres éléments communs en attributs. -->

                <!-- Ordre des éléments du Dublin Core -->
                <xsl:apply-templates select="mods:titleInfo"/>
                <xsl:apply-templates select="mods:name"/>
                <xsl:apply-templates select="mods:subject/mods:topic"/>
                <xsl:apply-templates select="mods:subject/mods:geographic"/>
                <xsl:apply-templates select="mods:subject/mods:temporal"/>
                <xsl:apply-templates select="mods:subject/mods:titleInfo"/>
                <xsl:apply-templates select="mods:subject/mods:name"/>
                <xsl:apply-templates select="mods:subject/mods:genre"/>
                <xsl:apply-templates select="mods:subject/mods:hierarchicalGeographic"/>
                <xsl:apply-templates select="mods:subject/mods:cartographics"/>
                <xsl:apply-templates select="mods:subject/mods:geographicCode"/>
                <xsl:apply-templates select="mods:subject/mods:occupation"/>
                <xsl:apply-templates select="mods:classification"/>
                <xsl:apply-templates select="mods:abstract"/>
                <xsl:apply-templates select="mods:tableOfContents"/>
                <xsl:apply-templates select="mods:note"/>
                <!-- originInfo est un événement avec toutes les infos. -->
                <xsl:apply-templates select="mods:originInfo/mods:publisher"/>
                <xsl:apply-templates select="mods:originInfo/mods:edition"/>
                <!-- Pas de correspondance pour dcterms:contributor : name peut-être creator ou contributor. -->
                <xsl:apply-templates select="mods:originInfo/mods:displayDate"/>
                <xsl:apply-templates select="mods:originInfo/mods:dateOther"/>
                <xsl:apply-templates select="mods:originInfo/mods:dateIssued"/>
                <xsl:apply-templates select="mods:originInfo/mods:dateCreated"/>
                <xsl:apply-templates select="mods:originInfo/mods:dateCaptured"/>
                <xsl:apply-templates select="mods:originInfo/mods:dateValid"/>
                <xsl:apply-templates select="mods:originInfo/mods:dateModified"/>
                <xsl:apply-templates select="mods:originInfo/mods:copyrightDate"/>
                <xsl:apply-templates select="mods:typeOfResource"/>
                <xsl:apply-templates select="mods:genre"/>
                <xsl:apply-templates select="mods:physicalDescription/mods:form"/>
                <xsl:apply-templates select="mods:physicalDescription/mods:reformattingQuality"/>
                <xsl:apply-templates select="mods:physicalDescription/mods:internetMediaType"/>
                <xsl:apply-templates select="mods:physicalDescription/mods:extent"/>
                <xsl:apply-templates select="mods:physicalDescription/mods:digitalOrigin"/>
                <xsl:apply-templates select="mods:physicalDescription/mods:note"/>
                <xsl:apply-templates select="mods:identifier"/>
                <xsl:apply-templates select="mods:recordInfo/mods:recordIdentifier"/>
                <xsl:apply-templates select="mods:recordInfo/mods:recordContentSource"/>
                <xsl:apply-templates select="mods:recordInfo/mods:recordOrigin"/>
                <xsl:apply-templates select="mods:recordInfo/mods:recordInfoNote"/>
                <xsl:apply-templates select="mods:recordInfo/mods:languageOfCataloging"/>
                <xsl:apply-templates select="mods:recordInfo/mods:descriptionStandard"/>
                <xsl:apply-templates select="mods:language"/>
                <xsl:apply-templates select="mods:relatedItem"/>
                <xsl:apply-templates select="mods:part"/>
                <xsl:apply-templates select="mods:location/mods:physicalLocation"/>
                <xsl:apply-templates select="mods:location/mods:shelfLocator"/>
                <xsl:apply-templates select="mods:location/mods:url"/>
                <xsl:apply-templates select="mods:location/mods:holdingSimple"/>
                <xsl:apply-templates select="mods:location/mods:holdingExternal"/>
                <xsl:apply-templates select="mods:accessCondition"/>
                <xsl:apply-templates select="mods:targetAudience"/>
                <xsl:apply-templates select="mods:originInfo/mods:issuance"/>
                <xsl:apply-templates select="mods:originInfo/mods:frequency"/>
                <xsl:apply-templates select="mods:extension"/>
            </xsl:element>
        </resources>
    </xsl:template>

    <!-- Métadonnées de la notice -->

    <xsl:template match="mods:recordInfo/@recordCreationDate | mods:recordInfo/@recordChangeDate">
        <xsl:call-template name="date">
            <xsl:with-param name="date" select="text()"/>
            <xsl:with-param name="encoding" select="@encoding"/>
        </xsl:call-template>
    </xsl:template>

    <!-- Title -->

    <xsl:template match="mods:titleInfo">
        <!-- TODO Utiliser bibo pour les numéros. -->
        <xsl:choose>
            <xsl:when test="@type = 'uniform'">
                <dcterms:title><xsl:value-of select="normalize-space(concat(mods:nonSort, mods:title, ' ', mods:subTitle, ' ', mods:partNumber, mods:partName))"/></dcterms:title>
            </xsl:when>
            <xsl:when test="@type = 'translated'">
                <dcterms:title xml:lang="{@lang}"><xsl:value-of select="normalize-space(concat(mods:nonSort, mods:title, ' ', mods:subTitle, ' ', mods:partNumber, mods:partName))"/></dcterms:title>
            </xsl:when>
            <xsl:when test="@type = 'alternative'">
                <dcterms:alternative><xsl:value-of select="normalize-space(concat(mods:nonSort, mods:title, ' ', mods:subTitle, ' ', mods:partNumber, mods:partName))"/></dcterms:alternative>
            </xsl:when>
            <xsl:when test="@type = 'abbreviated'">
                <bibo:shortTitle><xsl:value-of select="normalize-space(concat(mods:nonSort, mods:title, ' ', mods:subTitle, ' ', mods:partNumber, mods:partName))"/></bibo:shortTitle>
            </xsl:when>
            <xsl:when test="@type and @type != ''">
                <dcterms:title dcterms:type="{@type}"><xsl:value-of select="normalize-space(concat(mods:nonSort, mods:title, ' ', mods:subTitle, ' ', mods:partNumber, mods:partName))"/></dcterms:title>
            </xsl:when>
            <xsl:otherwise>
                <dcterms:title><xsl:value-of select="normalize-space(concat(mods:nonSort, mods:title, ' ', mods:subTitle, ' ', mods:partNumber, mods:partName))"/></dcterms:title>
            </xsl:otherwise>
        </xsl:choose>
    </xsl:template>

    <!-- Creator -->

    <!-- TODO Choisir creator/contributor selon le type. -->
    <!-- TODO Créer une notice Personne ou Organisation ou Famille ou Congrès. -->
    <xsl:template match="mods:name">
        <xsl:apply-templates select="mods:namePart"/>
    </xsl:template>

    <xsl:template match="mods:namePart">
        <xsl:choose>
            <xsl:when test="parent::mods:name/mods:role/mods:roleTerm[@type = 'text']">
                <dcterms:creator dcterms:type="{parent::mods:name/mods:role/mods:roleTerm[@type = 'text']}"><xsl:value-of select="."/></dcterms:creator>
            </xsl:when>
            <xsl:when test="parent::mods:name/mods:role/mods:roleTerm[@type = 'code']">
                <dcterms:creator dcterms:type="{parent::mods:name/mods:role/mods:roleTerm[@type = 'code']}"><xsl:value-of select="."/></dcterms:creator>
            </xsl:when>
            <xsl:otherwise>
                <dcterms:creator><xsl:value-of select="."/></dcterms:creator>
            </xsl:otherwise>
        </xsl:choose>
    </xsl:template>

    <!-- Subject -->

    <xsl:template match="mods:subject/mods:topic">
        <dcterms:subject dcterms:conformsTo="{parent::mods:subject/@authority}"><xsl:value-of select="."/></dcterms:subject>
    </xsl:template>

    <xsl:template match="mods:subject/mods:geographic">
        <dcterms:spatial dcterms:conformsTo="{parent::mods:subject/@authority}"><xsl:value-of select="."/></dcterms:spatial>
    </xsl:template>

    <xsl:template match="mods:subject/mods:geographicCode">
        <dcterms:spatial dcterms:type="geographicCode" dcterms:conformsTo="{parent::mods:subject/@authority}"><xsl:value-of select="."/></dcterms:spatial>
    </xsl:template>

    <xsl:template match="mods:subject/mods:hierarchicalGeographic">
        <dcterms:spatial dcterms:type="hierarchicalGeographic" dcterms:conformsTo="{parent::mods:subject/@authority}"><xsl:value-of select="."/></dcterms:spatial>
    </xsl:template>

    <xsl:template match="mods:subject/mods:cartographics">
        <dcterms:spatial dcterms:type="cartographics" dcterms:conformsTo="{parent::mods:subject/@authority}"><xsl:value-of select="."/></dcterms:spatial>
    </xsl:template>

    <xsl:template match="mods:subject/mods:temporal">
        <dcterms:temporal dcterms:conformsTo="{parent::mods:subject/@authority}"><xsl:value-of select="."/></dcterms:temporal>
    </xsl:template>

    <!-- TODO À préciser : subject/titleInfo ou mettre en ressource liée -->
    <xsl:template match="mods:subject/mods:titleInfo">
        <dcterms:subject dcterms:type="titleInfo" dcterms:conformsTo="{parent::mods:subject/@authority}"><xsl:value-of select="mods:title"/></dcterms:subject>
    </xsl:template>

    <!-- TODO Il faudrait une relation avec une ressource Auteur. -->
    <xsl:template match="mods/subject/mods:name">
        <xsl:choose>
            <xsl:when test="parent::mods:name/mods:role[@type = 'text']">
                <dcterms:subject dcterms:type="{parent::mods:name/mods:role[@type = 'text']}" dcterms:conformsTo="{@authority}"><xsl:value-of select="."/></dcterms:subject>
            </xsl:when>
            <xsl:when test="parent::mods:name/mods:role[@type = 'code']">
                <dcterms:subject dcterms:type="{parent::mods:name/mods:role[@type = 'code']}" dcterms:conformsTo="{@authority}"><xsl:value-of select="."/></dcterms:subject>
            </xsl:when>
            <xsl:when test="@authority and @authority != ''">
                <dcterms:subject dcterms:conformsTo="{@authority}"><xsl:value-of select="."/></dcterms:subject>
            </xsl:when>
            <xsl:otherwise>
                <dcterms:subject><xsl:value-of select="."/></dcterms:subject>
            </xsl:otherwise>
        </xsl:choose>
    </xsl:template>

    <xsl:template match="mods:subject/mods:genre">
        <dcterms:subject dcterms:type="genre" dcterms:conformsTo="{parent::mods:subject/@authority}"><xsl:value-of select="."/></dcterms:subject>
    </xsl:template>

    <xsl:template match="mods:subject/mods:occupation">
        <dcterms:subject dcterms:type="occupation" dcterms:conformsTo="{parent::mods:subject/@authority}"><xsl:value-of select="."/></dcterms:subject>
    </xsl:template>

    <xsl:template match="mods:classification">
        <xsl:element name="dcterms:subject">
            <!-- Les attributs peuvent être les mêmes sous différents formats (recommandation). -->
            <xsl:if test="@authority and @authority != ''">
                <xsl:attribute name="dcterms:conformsTo"><xsl:value-of select="@authority"/></xsl:attribute>
                <xsl:if test="@edition and @edition != ''">
                    <xsl:attribute name="bibo:edition"><xsl:value-of select="@edition"/></xsl:attribute>
                </xsl:if>
            </xsl:if>
            <xsl:if test="@authorityURI and @authorityURI != ''">
                <xsl:attribute name="dcterms:conformsTo"><xsl:value-of select="@authorityURI"/></xsl:attribute>
                <xsl:if test="@edition and @edition != ''">
                    <xsl:attribute name="bibo:edition"><xsl:value-of select="@edition"/></xsl:attribute>
                </xsl:if>
            </xsl:if>
            <xsl:if test="@lang">
                <xsl:attribute name="xml:lang"><xsl:value-of select="@lang"/></xsl:attribute>
            </xsl:if>
            <xsl:if test="@xml:lang">
                <xsl:attribute name="xml:lang"><xsl:value-of select="@xml:lang"/></xsl:attribute>
            </xsl:if>
            <xsl:choose>
                <xsl:when test="@valueURI and @valueURI != ''">
                    <xsl:attribute name="o:type">uri</xsl:attribute>
                    <xsl:value-of select="@valueURI"/>
                </xsl:when>
                <xsl:otherwise>
                    <xsl:value-of select="."/>
                </xsl:otherwise>
            </xsl:choose>
        </xsl:element>
    </xsl:template>

    <!-- Description -->

    <xsl:template match="mods:abstract">
        <xsl:choose>
            <xsl:when test="@type = 'abstract' or @type = 'summary'">
                <dcterms:abstract><xsl:value-of select="."/></dcterms:abstract>
            </xsl:when>
            <xsl:when test="@type">
                <dcterms:description dcterms:type="{@type}"><xsl:value-of select="."/></dcterms:description>
            </xsl:when>
            <xsl:otherwise>
                <dcterms:description><xsl:value-of select="."/></dcterms:description>
            </xsl:otherwise>
        </xsl:choose>
    </xsl:template>

    <xsl:template match="mods:tableOfContents">
        <xsl:choose>
            <xsl:when test="@type">
                <dcterms:tableOfContents dcterms:type="{@type}"><xsl:value-of select="."/></dcterms:tableOfContents>
            </xsl:when>
            <xsl:otherwise>
                <dcterms:tableOfContents><xsl:value-of select="."/></dcterms:tableOfContents>
            </xsl:otherwise>
        </xsl:choose>
    </xsl:template>

    <xsl:template match="mods:note">
        <!-- La liste est ouverte. -->
        <!-- La recommandation utilise plutôt dcterms:description, mais on peut détailler et éviter de mélanger. -->
        <!-- @link https://www.loc.gov/standards/mods/mods-notes.html -->
        <xsl:choose>
            <xsl:when test="@type = 'accrual method'">
                <dcterms:accrualMethod dcterms:type="{@type}"><xsl:value-of select="."/></dcterms:accrualMethod>
            </xsl:when>
            <xsl:when test="@type = 'accrual policy'">
                <dcterms:accrualPolicy dcterms:type="{@type}"><xsl:value-of select="."/></dcterms:accrualPolicy>
            </xsl:when>
            <xsl:when test="@type = 'acquisition'">
                <bibo:annotates dcterms:type="{@type}"><xsl:value-of select="."/></bibo:annotates>
            </xsl:when>
            <xsl:when test="@type = 'action'">
                <bibo:annotates dcterms:type="{@type}"><xsl:value-of select="."/></bibo:annotates>
            </xsl:when>
            <xsl:when test="@type = 'additional physical form'">
                <bibo:annotates dcterms:type="{@type}"><xsl:value-of select="."/></bibo:annotates>
            </xsl:when>
            <xsl:when test="@type = 'admin'">
                <bibo:annotates dcterms:type="{@type}"><xsl:value-of select="."/></bibo:annotates>
            </xsl:when>
            <xsl:when test="@type = 'bibliographic history' or @type = 'bibliography' or @type = 'biographical/historical'">
                <bibo:annotates dcterms:type="{@type}"><xsl:value-of select="."/></bibo:annotates>
            </xsl:when>
            <xsl:when test="@type = 'citation' or @type = 'reference' or @type = 'citation/reference' or @type = 'preferred citation'">
                <dcterms:bibliographicCitation dcterms:typ="{@type}"><xsl:value-of select="."/></dcterms:bibliographicCitation>
            </xsl:when>
            <xsl:when test="@type = 'conservation history'">
                <bibo:annotates dcterms:type="{@type}"><xsl:value-of select="."/></bibo:annotates>
            </xsl:when>
            <xsl:when test="@type = 'content'">
                <bibo:content dcterms:type="{@type}"><xsl:value-of select="."/></bibo:content>
            </xsl:when>
            <xsl:when test="@type = 'creation/production credits'">
                <bibo:annotates dcterms:type="{@type}"><xsl:value-of select="."/></bibo:annotates>
            </xsl:when>
            <xsl:when test="@type = 'date'">
                <bibo:annotates dcterms:type="{@type}"><xsl:value-of select="."/></bibo:annotates>
            </xsl:when>
            <xsl:when test="@type = 'exhibitions'">
                <bibo:annotates dcterms:type="{@type}"><xsl:value-of select="."/></bibo:annotates>
            </xsl:when>
            <xsl:when test="@type = 'funding'">
                <bibo:annotates dcterms:type="{@type}"><xsl:value-of select="."/></bibo:annotates>
            </xsl:when>
            <xsl:when test="@type = 'handwritten'">
                <bibo:annotates dcterms:type="{@type}"><xsl:value-of select="."/></bibo:annotates>
            </xsl:when>
            <xsl:when test="@type = 'language'">
                <bibo:annotates dcterms:type="{@type}"><xsl:value-of select="."/></bibo:annotates>
            </xsl:when>
            <xsl:when test="@type = 'numbering'">
                <bibo:annotates dcterms:type="{@type}"><xsl:value-of select="."/></bibo:annotates>
            </xsl:when>
            <xsl:when test="@type = 'date/sequential designation'">
                <bibo:annotates dcterms:type="{@type}"><xsl:value-of select="."/></bibo:annotates>
            </xsl:when>
            <xsl:when test="@type = 'original location'">
                <bibo:annotates dcterms:type="{@type}"><xsl:value-of select="."/></bibo:annotates>
            </xsl:when>
            <xsl:when test="@type = 'original version'">
                <bibo:annotates dcterms:type="{@type}"><xsl:value-of select="."/></bibo:annotates>
            </xsl:when>
            <xsl:when test="@type = 'ownership'">
                <bibo:annotates dcterms:type="{@type}"><xsl:value-of select="."/></bibo:annotates>
            </xsl:when>
            <xsl:when test="@type = 'performers'">
                <bibo:annotates dcterms:type="{@type}"><xsl:value-of select="."/></bibo:annotates>
            </xsl:when>
            <xsl:when test="@type = 'publications'">
                <bibo:annotates dcterms:type="{@type}"><xsl:value-of select="."/></bibo:annotates>
            </xsl:when>
            <xsl:when test="@type = 'reproduction'">
                <bibo:reproducedIn dcterms:type="{@type}"><xsl:value-of select="."/></bibo:reproducedIn>
            </xsl:when>
            <xsl:when test="@type = 'restriction'">
                <bibo:annotates dcterms:type="{@type}"><xsl:value-of select="."/></bibo:annotates>
            </xsl:when>
            <xsl:when test="@type = 'source characteristics'">
                <bibo:annotates dcterms:type="{@type}"><xsl:value-of select="."/></bibo:annotates>
            </xsl:when>
            <xsl:when test="@type = 'source dimensions'">
                <bibo:annotates dcterms:type="{@type}"><xsl:value-of select="."/></bibo:annotates>
            </xsl:when>
            <xsl:when test="@type = 'source identifier'">
                <bibo:annotates dcterms:type="{@type}"><xsl:value-of select="."/></bibo:annotates>
            </xsl:when>
            <xsl:when test="@type = 'source note'">
                <bibo:annotates dcterms:type="{@type}"><xsl:value-of select="."/></bibo:annotates>
            </xsl:when>
            <xsl:when test="@type = 'source type'">
                <bibo:annotates dcterms:type="{@type}"><xsl:value-of select="."/></bibo:annotates>
            </xsl:when>
            <xsl:when test="@type = 'statement of responsibility'">
                <bibo:annotates dcterms:type="{@type}"><xsl:value-of select="."/></bibo:annotates>
            </xsl:when>
            <xsl:when test="@type = 'subject completeness'">
                <bibo:annotates dcterms:type="{@type}"><xsl:value-of select="."/></bibo:annotates>
            </xsl:when>
            <xsl:when test="@type = 'system details'">
                <bibo:annotates dcterms:type="{@type}"><xsl:value-of select="."/></bibo:annotates>
            </xsl:when>
            <xsl:when test="@type = 'thesis'">
                <bibo:annotates dcterms:type="{@type}"><xsl:value-of select="."/></bibo:annotates>
            </xsl:when>
            <xsl:when test="@type = 'venue'">
                <bibo:annotates dcterms:type="{@type}"><xsl:value-of select="."/></bibo:annotates>
            </xsl:when>
            <xsl:when test="@type = 'version identification'">
                <bibo:annotates dcterms:type="{@type}"><xsl:value-of select="."/></bibo:annotates>
            </xsl:when>
            <xsl:when test="@type">
                <bibo:annotates dcterms:type="{@type}"><xsl:value-of select="."/></bibo:annotates>
            </xsl:when>
            <xsl:otherwise>
                <bibo:annotates><xsl:value-of select="."/></bibo:annotates>
            </xsl:otherwise>
        </xsl:choose>
    </xsl:template>

    <!-- Éditeur (remplacé par agent dans v3.8) -->

    <!-- Il faudrait un événement lié, mais c'est assez spécifique pour l'essentiel. -->
    <xsl:template match="mods:originInfo/mods:publisher">
        <dcterms:publisher>
            <xsl:if test="parent::mods:originInfo/mods:place">
                <xsl:value-of select="parent::mods:originInfo/mods:place"/>
                <xsl:text>: </xsl:text>
            </xsl:if>
            <xsl:value-of select="."/>
        </dcterms:publisher>
    </xsl:template>

    <xsl:template match="mods:originInfo/mods:edition">
        <bibo:edition><xsl:value-of select="."/></bibo:edition>
    </xsl:template>

    <!-- Dates -->

    <xsl:template match="mods:originInfo/mods:displayDate | mods:originInfo/mods:dateOther">
        <dcterms:date><xsl:value-of select="."/></dcterms:date>
    </xsl:template>

    <xsl:template match="mods:originInfo/mods:dateIssued">
        <dcterms:issued><xsl:value-of select="."/></dcterms:issued>
    </xsl:template>

    <xsl:template match="mods:originInfo/mods:dateCreated">
        <dcterms:created><xsl:value-of select="."/></dcterms:created>
    </xsl:template>

    <!-- Date de la numérisation. -->
    <xsl:template match="mods:originInfo/mods:dateCaptured">
        <dcterms:available><xsl:value-of select="."/></dcterms:available>
    </xsl:template>

    <xsl:template match="mods:originInfo/mods:dateValid">
        <dcterms:valid><xsl:value-of select="."/></dcterms:valid>
    </xsl:template>

    <xsl:template match="mods:originInfo/mods:dateModified">
        <dcterms:modified><xsl:value-of select="."/></dcterms:modified>
    </xsl:template>

    <xsl:template match="mods:originInfo/mods:copyrightDate">
        <dcterms:dateCopyrighted><xsl:value-of select="."/></dcterms:dateCopyrighted>
    </xsl:template>

    <!-- Type -->

    <xsl:template match="mods:typeOfResource">
        <dcterms:type><xsl:value-of select="."/></dcterms:type>
    </xsl:template>

    <!-- Le genre est plus près d'un type de sujet, mais il est recommandé de le mettre en type. -->
    <xsl:template match="mods:genre">
        <xsl:choose>
            <xsl:when test="@authority and @authority != ''">
                <dcterms:type dcterms:conformsTo="{@authority}"><xsl:value-of select="."/></dcterms:type>
            </xsl:when>
            <xsl:otherwise>
                <dcterms:type><xsl:value-of select="."/></dcterms:type>
            </xsl:otherwise>
        </xsl:choose>
    </xsl:template>

    <!-- Format -->

    <xsl:template match="mods:physicalDescription/mods:form">
        <dcterms:format><xsl:value-of select="."/></dcterms:format>
    </xsl:template>

    <xsl:template match="mods:physicalDescription/mods:reformattingQuality">
        <!-- Liste fermée : access ; preservation ; replacement -->
        <dcterms:format><xsl:value-of select="concat('Reformatting qualiy: ', .)"/></dcterms:format>
    </xsl:template>

    <xsl:template match="mods:physicalDescription/mods:internetMediaType">
        <dcterms:format dcterms:type="media-type"><xsl:value-of select="."/></dcterms:format>
    </xsl:template>

    <xsl:template match="mods:physicalDescription/mods:extent">
        <xsl:choose>
            <xsl:when test="@unit = 'pages'">
                <bibo:numPages><xsl:value-of select="."/></bibo:numPages>
            </xsl:when>
            <xsl:otherwise>
                <dcterms:extent><xsl:value-of select="normalize-space(concat(., ' ', @unit))"/></dcterms:extent>
            </xsl:otherwise>
        </xsl:choose>
    </xsl:template>

    <xsl:template match="mods:physicalDescription/mods:digitalOrigin">
        <!-- Liste fermée : born digital ; reformatted digital ; digitized microfilm ; digitized other analog -->
        <dcterms:medium dcterms:type="digital origin"><xsl:value-of select="."/></dcterms:medium>
    </xsl:template>

    <xsl:template match="mods:physicalDescription/mods:note">
        <dcterms:format><xsl:value-of select="."/></dcterms:format>
    </xsl:template>

    <!-- Identifiant -->

    <xsl:template match="mods:identifier[@typeURI]">
        <!-- TODO Trouver l'équivalent du type uri (isbn, etc) et l. -->
        <dcterms:identifier dcterms:type="{@typeURI}"><xsl:value-of select="."/></dcterms:identifier>
    </xsl:template>

    <xsl:template match="mods:identifier">
        <xsl:choose>
            <xsl:when test="@type = 'asin'">
                <bibo:asin><xsl:value-of select="."/></bibo:asin>
            </xsl:when>
            <xsl:when test="@type = 'coden'">
                <bibo:coden><xsl:value-of select="."/></bibo:coden>
            </xsl:when>
            <xsl:when test="@type = 'doi'">
                <bibo:doi><xsl:value-of select="."/></bibo:doi>
            </xsl:when>
            <xsl:when test="@type = 'eanucc13'">
                <bibo:eanucc13><xsl:value-of select="."/></bibo:eanucc13>
            </xsl:when>
            <xsl:when test="@type = 'eissn'">
                <bibo:eissn><xsl:value-of select="."/></bibo:eissn>
            </xsl:when>
            <xsl:when test="@type = 'gtin14'">
                <bibo:gtin14><xsl:value-of select="."/></bibo:gtin14>
            </xsl:when>
            <xsl:when test="@type = 'handle' or @type = 'hdl'">
                <bibo:handle><xsl:value-of select="."/></bibo:handle>
            </xsl:when>
            <xsl:when test="@type = 'eissn'">
                <bibo:eissn><xsl:value-of select="."/></bibo:eissn>
            </xsl:when>
            <xsl:when test="@type = 'isbn'">
                <!-- TODO Choisir isbn10 ou 13. -->
                <bibo:isbn><xsl:value-of select="."/></bibo:isbn>
            </xsl:when>
            <xsl:when test="@type = 'isbn10'">
                <bibo:isbn10><xsl:value-of select="."/></bibo:isbn10>
            </xsl:when>
            <xsl:when test="@type = 'isbn'">
                <bibo:isbn13><xsl:value-of select="."/></bibo:isbn13>
            </xsl:when>
            <xsl:when test="@type = 'issn'">
                <bibo:issn><xsl:value-of select="."/></bibo:issn>
            </xsl:when>
            <xsl:when test="@type = 'lccn'">
                <bibo:lccn><xsl:value-of select="."/></bibo:lccn>
            </xsl:when>
            <xsl:when test="@type = 'locator' or @type = 'callnumber' or @type = 'local' or @type = 'local-callnumber'">
                <bibo:locator dcterms:type="{@type}"><xsl:value-of select="."/></bibo:locator>
            </xsl:when>
            <xsl:when test="@type = 'oclc' or @type = 'oclcnum'">
                <bibo:oclcnum><xsl:value-of select="."/></bibo:oclcnum>
            </xsl:when>
            <xsl:when test="@type = 'pmid'">
                <bibo:pmid><xsl:value-of select="."/></bibo:pmid>
            </xsl:when>
            <xsl:when test="@type = 'sici'">
                <bibo:sici><xsl:value-of select="."/></bibo:sici>
            </xsl:when>
            <xsl:when test="@type = 'upc'">
                <bibo:upc><xsl:value-of select="."/></bibo:upc>
            </xsl:when>
            <xsl:when test="@type = 'uri'">
                <bibo:uri o:type="uri"><xsl:value-of select="."/></bibo:uri>
            </xsl:when>
            <!-- Il y a une liste plus complète et ouverte de types. -->
            <xsl:when test="@type and @type != ''">
                <dcterms:identifier dcterms:type="{@type}"><xsl:value-of select="."/></dcterms:identifier>
            </xsl:when>
            <xsl:otherwise>
                <dcterms:identifier><xsl:value-of select="."/></dcterms:identifier>
            </xsl:otherwise>
        </xsl:choose>
    </xsl:template>

    <xsl:template match="mods:recordInfo/mods:recordIdentifier">
        <!-- Noms ou code de l'institution ou de la base gérant la notice. -->
        <bibo:identifier>
            <xsl:choose>
                <xsl:when test="@source and @source != ''">
                    <xsl:value-of select="concat(@source, ': ', .)"/>
                </xsl:when>
                <xsl:otherwise>
                    <xsl:value-of select="."/>
                </xsl:otherwise>
            </xsl:choose>
        </bibo:identifier>
    </xsl:template>

    <!-- Source -->

    <!--
    Pour les métadonnées de la notice, on utilise dcterms:mediator même si cela ne porte pas directement sur la ressource.
    dcterms:isFormatOf est possible, mais utilisé pour relatedItem.
    Ou encore dcterms:source, mais cela porte sur la ressource.
    -->
    <!-- Voir la version avec une ressource secondaire contenant les informations de la notice et liée à la ressource décrite. -->
    <xsl:template match="mods:recordInfo/mods:recordContentSource">
        <!-- Noms ou code de l'institution ou de la base gérant la notice. -->
        <dcterms:mediator dcterms:type="recordContentSource"><xsl:value-of select="."/></dcterms:mediator>
    </xsl:template>

    <xsl:template match="mods:recordInfo/mods:recordOrigin">
        <dcterms:mediator dcterms:type="recordOrigin"><xsl:value-of select="."/></dcterms:mediator>
    </xsl:template>

    <xsl:template match="mods:recordInfo/mods:recordInfoNote">
        <xsl:choose>
            <xsl:when test="@type and @type != ''">
                <dcterms:mediator dcterms:type="recordInfoNote" dcterms:description="{type}"><xsl:value-of select="."/></dcterms:mediator>
            </xsl:when>
            <xsl:otherwise>
                <dcterms:mediator dcterms:type="recordInfoNote"><xsl:value-of select="."/></dcterms:mediator>
            </xsl:otherwise>
        </xsl:choose>
    </xsl:template>

    <!-- TODO Factoriser languageOfCataloging avec language. -->
    <xsl:template match="mods:recordInfo/mods:languageOfCataloging">
        <xsl:element name="dcterms:source">
            <xsl:attribute name="dcterms:type">languageOfCataloging</xsl:attribute>
            <xsl:if test="mods:languageTerm/@authority and mods:languageTerm/@authority != ''">
                <xsl:attribute name="dcterms:conformsTo"><xsl:value-of select="mods:languageTerm/@authority"/></xsl:attribute>
            </xsl:if>
            <xsl:if test="mods:scriptTerm/text() and mods:scriptTerm/text() != ''">
                <xsl:attribute name="dcterms:format"><xsl:value-of select="mods:scriptTerm"/></xsl:attribute>
            </xsl:if>
            <xsl:choose>
                <xsl:when test="mods:languageTerm and mods:languageTerm/text() != ''">
                    <xsl:value-of select="mods:languageTerm"/>
                </xsl:when>
                <xsl:otherwise>
                    <xsl:value-of select="."/>
                </xsl:otherwise>
            </xsl:choose>
        </xsl:element>
    </xsl:template>

    <xsl:template match="mods:recordInfo/mods:descriptionStandard">
        <dcterms:source dcterms:type="descriptionStandard"><xsl:value-of select="."/></dcterms:source>
    </xsl:template>

    <!-- Langue -->

    <xsl:template match="mods:language">
        <!-- TODO Normaliser la langue ou utiliser Value Suggest. -->
        <xsl:element name="dcterms:language">
            <xsl:if test="mods:languageTerm/@authority and mods:languageTerm/@authority != ''">
                <xsl:attribute name="dcterms:conformsTo"><xsl:value-of select="mods:languageTerm/@authority"/></xsl:attribute>
            </xsl:if>
            <xsl:if test="mods:scriptTerm/text() and mods:scriptTerm/text() != ''">
                <xsl:attribute name="dcterms:format"><xsl:value-of select="mods:scriptTerm"/></xsl:attribute>
            </xsl:if>
            <xsl:value-of select="mods:languageTerm"/>
        </xsl:element>
    </xsl:template>

    <!-- Relation -->

    <!-- Dans cette xsl, on ne crée pas de ressources liées. -->
    <!-- TODO Les métadonnées des ressources liées sont forcément incomplètes si on ne crée pas une ressource liée. -->
    <xsl:template match="mods:relatedItem">
        <xsl:choose>
            <xsl:when test="@xlink and @xlink != ''">
                <xsl:choose>
                    <!-- @type est une liste fermée, sinon on utilise @otherType -->
                    <xsl:when test="@type = 'preceding' or @type = 'succeeding'">
                        <dcterms:relation dcterms:type="{@type}" o:type="uri"><xsl:value-of select="@xlink"/></dcterms:relation>
                    </xsl:when>
                    <xsl:when test="@type = 'original'">
                        <dcterms:isFormatOf dcterms:type="{@type}" o:type="uri"><xsl:value-of select="@xlink"/></dcterms:isFormatOf>
                    </xsl:when>
                    <xsl:when test="@type = 'host'">
                        <dcterms:isPartOf dcterms:type="{@type}" o:type="uri"><xsl:value-of select="@xlink"/></dcterms:isPartOf>
                    </xsl:when>
                    <xsl:when test="@type = 'constituent'">
                        <dcterms:hasPart dcterms:type="{@type}" o:type="uri"><xsl:value-of select="@xlink"/></dcterms:hasPart>
                    </xsl:when>
                    <xsl:when test="@type = 'series'">
                        <dcterms:hasPart dcterms:type="{@type}" o:type="uri"><xsl:value-of select="@xlink"/></dcterms:hasPart>
                    </xsl:when>
                    <xsl:when test="@type = 'otherVersion'">
                        <dcterms:hasVersion dcterms:type="{@type}" o:type="uri"><xsl:value-of select="@xlink"/></dcterms:hasVersion>
                    </xsl:when>
                    <xsl:when test="@type = 'otherFormat'">
                        <dcterms:hasFormat dcterms:type="{@type}" o:type="uri"><xsl:value-of select="@xlink"/></dcterms:hasFormat>
                    </xsl:when>
                    <xsl:when test="@type = 'isReferencedBy'">
                        <dcterms:isReferencedBy o:type="uri"><xsl:value-of select="@xlink"/></dcterms:isReferencedBy>
                    </xsl:when>
                    <xsl:when test="@type = 'references'">
                        <dcterms:references o:type="uri"><xsl:value-of select="@xlink"/></dcterms:references>
                    </xsl:when>
                    <xsl:when test="@type = 'reviewOf'">
                        <bibo:reviewOf o:type="uri"><xsl:value-of select="@xlink"/></bibo:reviewOf>
                    </xsl:when>
                    <xsl:when test="@type and @type != ''">
                        <bibo:relation dcterms:type="{@type}" o:type="uri"><xsl:value-of select="@xlink"/></bibo:relation>
                    </xsl:when>
                    <xsl:when test="@otherType and @otherType != ''">
                        <bibo:relation dcterms:type="{@otherType}" o:type="uri"><xsl:value-of select="@xlink"/></bibo:relation>
                    </xsl:when>
                    <xsl:when test="@otherTypeUri and @otherTypeUri != ''">
                        <bibo:relation dcterms:type="{@otherTypeUri}" o:type="uri"><xsl:value-of select="@xlink"/></bibo:relation>
                    </xsl:when>
                    <xsl:otherwise>
                        <dcterms:relation o:type="uri"><xsl:value-of select="@xlink"/></dcterms:relation>
                    </xsl:otherwise>
                </xsl:choose>
            </xsl:when>
            <xsl:when test="mods:location/mods:url and mods:location/mods:url != ''">
                <xsl:choose>
                    <!-- @type est une liste fermée, sinon on utilise @otherType -->
                    <xsl:when test="@type = 'preceding' or @type = 'succeeding'">
                        <dcterms:relation dcterms:type="{@type}" o:type="uri" o:label="{mods:titleInfo/mods:title}"><xsl:value-of select="mods:location/mods:url"/></dcterms:relation>
                    </xsl:when>
                    <xsl:when test="@type = 'original'">
                        <dcterms:isFormatOf dcterms:type="{@type}" o:type="uri" o:label="{mods:titleInfo/mods:title}"><xsl:value-of select="mods:location/mods:url"/></dcterms:isFormatOf>
                    </xsl:when>
                    <xsl:when test="@type = 'host'">
                        <dcterms:isPartOf dcterms:type="{@type}" o:type="uri" o:label="{mods:titleInfo/mods:title}"><xsl:value-of select="mods:location/mods:url"/></dcterms:isPartOf>
                    </xsl:when>
                    <xsl:when test="@type = 'constituent'">
                        <dcterms:hasPart dcterms:type="{@type}" o:type="uri" o:label="{mods:titleInfo/mods:title}"><xsl:value-of select="mods:location/mods:url"/></dcterms:hasPart>
                    </xsl:when>
                    <xsl:when test="@type = 'series'">
                        <dcterms:hasPart dcterms:type="{@type}" o:type="uri" o:label="{mods:titleInfo/mods:title}"><xsl:value-of select="mods:location/mods:url"/></dcterms:hasPart>
                    </xsl:when>
                    <xsl:when test="@type = 'otherVersion'">
                        <dcterms:hasVersion dcterms:type="{@type}" o:type="uri" o:label="{mods:titleInfo/mods:title}"><xsl:value-of select="mods:location/mods:url"/></dcterms:hasVersion>
                    </xsl:when>
                    <xsl:when test="@type = 'otherFormat'">
                        <dcterms:hasFormat dcterms:type="{@type}" o:type="uri" o:label="{mods:titleInfo/mods:title}"><xsl:value-of select="mods:location/mods:url"/></dcterms:hasFormat>
                    </xsl:when>
                    <xsl:when test="@type = 'isReferencedBy'">
                        <dcterms:isReferencedBy o:type="uri" o:label="{mods:titleInfo/mods:title}"><xsl:value-of select="mods:location/mods:url"/></dcterms:isReferencedBy>
                    </xsl:when>
                    <xsl:when test="@type = 'references'">
                        <dcterms:references o:type="uri" o:label="{mods:titleInfo/mods:title}"><xsl:value-of select="mods:location/mods:url"/></dcterms:references>
                    </xsl:when>
                    <xsl:when test="@type = 'reviewOf'">
                        <bibo:reviewOf o:type="uri" o:label="{mods:titleInfo/mods:title}"><xsl:value-of select="mods:location/mods:url"/></bibo:reviewOf>
                    </xsl:when>
                    <xsl:when test="@type and @type != ''">
                        <bibo:relation dcterms:type="{@type}" o:type="uri" o:label="{mods:titleInfo/mods:title}"><xsl:value-of select="mods:location/mods:url"/></bibo:relation>
                    </xsl:when>
                    <xsl:when test="@otherType and @otherType != ''">
                        <bibo:relation dcterms:type="{@otherType}" o:type="uri" o:label="{mods:titleInfo/mods:title}"><xsl:value-of select="mods:location/mods:url"/></bibo:relation>
                    </xsl:when>
                    <xsl:when test="@otherTypeUri and @otherTypeUri != ''">
                        <bibo:relation dcterms:type="{@otherTypeUri}" o:type="uri" o:label="{mods:titleInfo/mods:title}"><xsl:value-of select="mods:location/mods:url"/></bibo:relation>
                    </xsl:when>
                    <xsl:otherwise>
                        <dcterms:relation o:type="uri" o:label="{mods:titleInfo/mods:title}"><xsl:value-of select="mods:location/mods:url"/></dcterms:relation>
                    </xsl:otherwise>
                </xsl:choose>
            </xsl:when>
            <!-- Dans tous les aures cas, on met uniquement le titre. -->
            <!-- TODO Recréer la référence complète en ISBD pour relatedItem pour le premier niveau. -->
            <xsl:otherwise>
                <xsl:choose>
                    <!-- @type est une liste fermée, sinon on utilise @otherType -->
                    <xsl:when test="@type = 'preceding' or @type = 'succeeding'">
                        <dcterms:relation dcterms:type="{@type}"><xsl:value-of select="mods:titleInfo/mods:title"/></dcterms:relation>
                    </xsl:when>
                    <xsl:when test="@type = 'original'">
                        <dcterms:isFormatOf dcterms:type="{@type}"><xsl:value-of select="mods:titleInfo/mods:title"/></dcterms:isFormatOf>
                    </xsl:when>
                    <xsl:when test="@type = 'host'">
                        <dcterms:isPartOf dcterms:type="{@type}"><xsl:value-of select="mods:titleInfo/mods:title"/></dcterms:isPartOf>
                    </xsl:when>
                    <xsl:when test="@type = 'constituent'">
                        <dcterms:hasPart dcterms:type="{@type}"><xsl:value-of select="mods:titleInfo/mods:title"/></dcterms:hasPart>
                    </xsl:when>
                    <xsl:when test="@type = 'series'">
                        <dcterms:hasPart dcterms:type="{@type}"><xsl:value-of select="mods:titleInfo/mods:title"/></dcterms:hasPart>
                    </xsl:when>
                    <xsl:when test="@type = 'otherVersion'">
                        <dcterms:hasVersion dcterms:type="{@type}"><xsl:value-of select="mods:titleInfo/mods:title"/></dcterms:hasVersion>
                    </xsl:when>
                    <xsl:when test="@type = 'otherFormat'">
                        <dcterms:hasFormat dcterms:type="{@type}"><xsl:value-of select="mods:titleInfo/mods:title"/></dcterms:hasFormat>
                    </xsl:when>
                    <xsl:when test="@type = 'isReferencedBy'">
                        <dcterms:isReferencedBy><xsl:value-of select="mods:titleInfo/mods:title"/></dcterms:isReferencedBy>
                    </xsl:when>
                    <xsl:when test="@type = 'references'">
                        <dcterms:references><xsl:value-of select="mods:titleInfo/mods:title"/></dcterms:references>
                    </xsl:when>
                    <xsl:when test="@type = 'reviewOf'">
                        <bibo:reviewOf><xsl:value-of select="mods:titleInfo/mods:title"/></bibo:reviewOf>
                    </xsl:when>
                    <xsl:when test="@type and @type != ''">
                        <bibo:relation dcterms:type="{@type}"><xsl:value-of select="mods:titleInfo/mods:title"/></bibo:relation>
                    </xsl:when>
                    <xsl:when test="@otherType and @otherType != ''">
                        <bibo:relation dcterms:type="{@otherType}"><xsl:value-of select="mods:titleInfo/mods:title"/></bibo:relation>
                    </xsl:when>
                    <xsl:when test="@otherTypeUri and @otherTypeUri != ''">
                        <bibo:relation dcterms:type="{@otherTypeUri}"><xsl:value-of select="mods:titleInfo/mods:title"/></bibo:relation>
                    </xsl:when>
                    <xsl:otherwise>
                        <dcterms:relation><xsl:value-of select="mods:titleInfo/mods:title"/></dcterms:relation>
                    </xsl:otherwise>
                </xsl:choose>
            </xsl:otherwise>
        </xsl:choose>
    </xsl:template>

    <!--
    mods:part est complexe à gérer quand le type n'est pas précisé (auquel cas c'est bien la partie
    d'un tout), car il signifie tout aussi bien isPartOf que hasPart, en particulier quand c'est un relatedItem.
    Ne pas confondre avec partName et partNumber.
    -->
    <xsl:template match="mods:part">
        <xsl:variable name="part">
            <xsl:value-of select="normalize-space(concat(mods:detail, ' ', mods:extent, ' ', mods:date, ' ', mods:text))"/>
        </xsl:variable>
        <!-- Liste ouverte @type. -->
        <xsl:choose>
            <xsl:when test="@type = 'volume'">
                <bibo:volume bibo:number="{@order}"><xsl:value-of select="$part"/></bibo:volume>
            </xsl:when>
            <xsl:when test="@type = 'issue'">
                <bibo:issue bibo:number="{@order}"><xsl:value-of select="$part"/></bibo:issue>
            </xsl:when>
            <xsl:when test="@type = 'chapter'">
                <bibo:chapter bibo:number="{@order}"><xsl:value-of select="$part"/></bibo:chapter>
            </xsl:when>
            <xsl:when test="@type = 'section'">
                <bibo:section bibo:number="{@order}"><xsl:value-of select="$part"/></bibo:section>
            </xsl:when>
            <xsl:when test="@type = 'paragraph'">
                <bibo:section dcterms:type="{@type}"><xsl:value-of select="$part"/></bibo:section>
            </xsl:when>
            <xsl:when test="@type = 'track'">
                <bibo:section dcterms:type="{@type}"><xsl:value-of select="$part"/></bibo:section>
            </xsl:when>
            <xsl:when test="@type and @type != ''">
                <bibo:section dcterms:type="{@type}"><xsl:value-of select="$part"/></bibo:section>
            </xsl:when>
            <xsl:otherwise>
                <bibo:section><xsl:value-of select="$part"/></bibo:section>
            </xsl:otherwise>
        </xsl:choose>
    </xsl:template>

    <!-- Localisation de la ressource -->

    <xsl:template match="mods:location/mods:physicalLocation">
        <dcterms:mediator dcterms:type="physicalLocation"><xsl:value-of select="."/></dcterms:mediator>
    </xsl:template>

    <xsl:template match="mods:location/mods:shelfLocator">
        <bibo:locator dcterms:type="shelfLocator"><xsl:value-of select="."/></bibo:locator>
    </xsl:template>

    <xsl:template match="mods:location/mods:url">
        <!-- La recommandation indique dcerms:identifier, mais bibo:uri permet de distinguer les identifiants. -->
        <bibo:uri dcterms:title="{@access}"><xsl:value-of select="."/></bibo:uri>
    </xsl:template>

    <xsl:template match="mods:location/mods:holdingSimple">
        <dcterms:mediator dcterms:type="holdingSimple"><xsl:value-of select="mods:copyInformation"/></dcterms:mediator>
    </xsl:template>

    <!-- mods:holdingExternal représente des données d'exemplaires dans un autre format que le mods (exemple : iso20775). -->
    <xsl:template match="mods:location/mods:holdingExternal">
        <dcterms:mediator dcterms:type="holdingExternal" o:type="xml"><xsl:copy-of select="node()"/></dcterms:mediator>
    </xsl:template>

    <!-- Droits -->

    <xsl:template match="mods:accessCondition">
        <xsl:choose>
            <!-- Liste ouverte. Peut aussi être un autre format que le mods. -->
            <xsl:when test="@type = 'restriction on access'">
                <dcterms:accessRights><xsl:value-of select="."/></dcterms:accessRights>
            </xsl:when>
            <xsl:when test="@type = 'use and reproduction'">
                <dcterms:license dcterms:type="use and reproduction"><xsl:value-of select="."/></dcterms:license>
            </xsl:when>
            <xsl:when test="@type and @type != ''">
                <dcterms:rights dcterms:type="{@type}"><xsl:value-of select="."/></dcterms:rights>
            </xsl:when>
            <xsl:otherwise>
                <dcterms:rights dcterms:type="accessCondition"><xsl:value-of select="."/></dcterms:rights>
            </xsl:otherwise>
        </xsl:choose>
    </xsl:template>

    <!-- Audience -->

    <xsl:template match="mods:targetAudience">
        <dcterms:audience><xsl:value-of select="."/></dcterms:audience>
    </xsl:template>

    <!-- Modalités de gestion -->

    <xsl:template match="mods:issuance">
        <!-- Liste fermée : monographic ; single unit ; multipart monograph ; continuing ; serial ; integrating resource -->
        <dcterms:accrualMethod><xsl:value-of select="."/></dcterms:accrualMethod>
    </xsl:template>

    <xsl:template match="mods:frequency">
        <dcterms:accrualPeriodicity><xsl:value-of select="."/></dcterms:accrualPeriodicity>
    </xsl:template>

    <xsl:template match="mods:extension">
        <bibo:annotates dcterms:type="extension" o:type="xml"><xsl:copy-of select="node()"/></bibo:annotates>
    </xsl:template>

    <!-- Fonctions -->

    <!-- TODO La normalisation des dates se fait plus facilement avec xslt 2 ou exslt (en utilisant des templates importés). -->
    <!-- TODO Convertir les dates marc, edtf et temper en iso 8601 v2. -->
    <xsl:template name="date">
        <!-- Liste fermée pour encoding : w3cdtf, iso8601, marc, edtf, temper. -->
        <xsl:param name="date"/>
        <xsl:param name="encoding"/>

        <xsl:choose>

            <!-- @link http://www.w3c.org/TR/NOTE-datetime (format Omeka) -->
            <xsl:when test="$encoding = 'w3cdtf'">
                <xsl:value-of select="$date"/>
            </xsl:when>

            <!-- https://www.w3.org/TR/NOTE-datetime -->
            <!-- TODO Normaliser en format étendu. -->
            <xsl:when test="$encoding = 'iso8601'">
                <xsl:choose>
                    <!-- Extended format. -->
                    <xsl:when test="contains($date, '-')">
                        <xsl:value-of select="$date"/>
                    </xsl:when>
                    <!-- Basic format. -->
                    <xsl:otherwise>
                        <xsl:value-of select="$date"/>
                    </xsl:otherwise>
                </xsl:choose>
            </xsl:when>

            <!-- @see https://www.loc.gov/marc/bibliographic/bd008a.html -->
            <xsl:when test="$encoding = 'marc'">
                <xsl:choose>
                    <xsl:when test="$date = '||||' or $date = '||||||||'">
                        <xsl:text>No attempt to code</xsl:text>
                    </xsl:when>
                    <xsl:when test="string-length($date) = 4 and (contains($date, '#') or contains($date, 'u'))">
                        <xsl:value-of select="$date"/>
                    </xsl:when>
                    <xsl:when test="string-length($date) = 4">
                        <xsl:value-of select="$date"/>
                    </xsl:when>
                    <xsl:when test="string-length($date) = 8 and (contains($date, '#') or contains($date, 'u'))">
                        <xsl:value-of select="concat(substring($date, 1, 4), '-', substring($date, 5, 2), '-', substring($date, 7, 2))"/>
                    </xsl:when>
                    <xsl:when test="string-length($date) = 8">
                        <xsl:value-of select="concat(substring($date, 1, 4), '-', substring($date, 5, 2), '-', substring($date, 7, 2))"/>
                    </xsl:when>
                    <xsl:otherwise>
                        <xsl:value-of select="$date"/>
                    </xsl:otherwise>
                </xsl:choose>
            </xsl:when>

            <!-- Format iso 8601 étendu, mais non normalisé. -->
            <xsl:when test="$encoding = 'edtf'">
                <xsl:value-of select="$date"/>
            </xsl:when>

            <!-- @link https://datatracker.ietf.org/doc/id/draft-kunze-temper-01.txt -->
            <!-- Format alpha-numérique gèrant les dates non grégoriennes, les dates incertaines et les périodes. -->
            <xsl:when test="$encoding = 'temper'">
                <xsl:value-of select="$date"/>
            </xsl:when>

            <xsl:otherwise>
                <xsl:value-of select="$date"/>
            </xsl:otherwise>

        </xsl:choose>
    </xsl:template>

    <!-- TODO On peut aussi déterminer le type après la normalisation pour les cas autres. -->
    <xsl:template name="date_type">
        <!-- Liste fermée pour encoding : w3cdtf, iso8601, marc, edtf, temper. -->
        <xsl:param name="date"/>
        <xsl:param name="encoding"/>

        <xsl:choose>

            <!-- @link http://www.w3c.org/TR/NOTE-datetime (format Omeka) -->
            <xsl:when test="$encoding = 'w3cdtf'">
                <xsl:text>numeric:timestamp</xsl:text>
            </xsl:when>

            <!-- https://www.w3.org/TR/NOTE-datetime -->
            <xsl:when test="$encoding = 'iso8601'">
                <xsl:text>numeric:timestamp</xsl:text>
            </xsl:when>

            <!-- @see https://www.loc.gov/marc/bibliographic/bd008a.html -->
            <xsl:when test="$encoding = 'marc'">
                <xsl:choose>
                    <xsl:when test="$date = '||||' or $date = '||||||||'">
                        <xsl:text>literal</xsl:text>
                    </xsl:when>
                    <xsl:when test="contains($date, '#') or contains($date, 'u')">
                        <xsl:text>literal</xsl:text>
                    </xsl:when>
                    <xsl:when test="string-length($date) = 4 or string-length($date) = 8">
                        <xsl:text>numeric:timestamp</xsl:text>
                    </xsl:when>
                    <xsl:otherwise>
                        <xsl:text>literal</xsl:text>
                    </xsl:otherwise>
                </xsl:choose>
            </xsl:when>

            <!-- Format iso 8601 étendu, mais non normalisé. -->
            <xsl:when test="$encoding = 'edtf'">
                <xsl:text>literal</xsl:text>
            </xsl:when>

            <!-- @link https://datatracker.ietf.org/doc/id/draft-kunze-temper-01.txt -->
            <!-- Format alpha-numérique gèrant les dates non grégoriennes, les dates incertaines et les périodes. -->
            <xsl:when test="$encoding = 'temper'">
                <xsl:text>literal</xsl:text>
            </xsl:when>

            <xsl:otherwise>
                <xsl:text>literal</xsl:text>
            </xsl:otherwise>

        </xsl:choose>
    </xsl:template>

</xsl:stylesheet>
