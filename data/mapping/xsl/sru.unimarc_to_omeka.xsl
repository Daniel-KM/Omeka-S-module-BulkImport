<?xml version="1.0" encoding="UTF-8"?>
<!--
    Description : Convertit les sorties UNIMARC SRU en liste de ressources pour Omeka S.

    Nécessite d'installer l'ensemble des ontologies Unimarc (element sets).
    @link https://www.iflastandards.info/unimarc

    Cette feuille xsl est une version générique de "sru.unimarc_to_unimarc.xsl".

    Contrairement à "sru.unimarc_to_resources.xsl", cette feuille xsl permet de
    créer directement des ressources Omeka et il n'est pas nécessaire d'utiliser
    un fichier d'alignement.

    Les mentions locales (9xx, x9x, etc.) ne sont pas importées.
    Les mentions d'exemplaire (995) ne sont pas importées.

    @copyright Daniel Berthereau, 2021-2022
    @license CeCILL 2.1 https://cecill.info/licences/Licence_CeCILL_V2.1-fr.txt
-->

<xsl:stylesheet version="1.0"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
    xmlns:rdfs="http://www.w3.org/2000/01/rdf-schema#"
    xmlns:skos="http://www.w3.org/2004/02/skos/core#"
    xmlns:dcterms="http://purl.org/dc/terms/"
    xmlns:bibo="http://purl.org/ontology/bibo/"
    xmlns:foaf="http://xmlns.com/foaf/0.1/"
    xmlns:bio="http://purl.org/vocab/bio/0.1/"
    xmlns:srw="http://www.loc.gov/zing/srw/"
    xmlns:curation="https://omeka.org/s/vocabs/curation/"
    xmlns:o="http://omeka.org/s/vocabs/o#"

    exclude-result-prefixes="
        xsl rdf rdfs skos
        srw
        "
    >

    <xsl:output method="xml" encoding="UTF-8" indent="yes"/>

    <xsl:strip-space elements="*"/>

    <!-- Constantes -->
    <xsl:variable name="resource_template" select="'Base ressource Unimarc'"/>

    <xsl:template match="@*|node()">
        <xsl:copy>
            <xsl:apply-templates select="@*|node()"/>
        </xsl:copy>
    </xsl:template>

    <xsl:template match="/srw:searchRetrieveResponse">
        <resources>
            <xsl:apply-templates select="srw:records/srw:record/srw:recordData/record"/>
        </resources>
    </xsl:template>

    <xsl:template match="record">
        <resource o:is_public="true" o:resource_template="{$resource_template}">
            <!--
            <xsl:apply-templates select="leader"/>
            -->
            <xsl:apply-templates select="controlfield"/>
            <xsl:apply-templates select="datafield"/>
        </resource>
    </xsl:template>

    <xsl:template match="leader">
        <curation:note><xsl:value-of select="."/></curation:note>
    </xsl:template>

    <xsl:template match="controlfield">
        <dcterms:identifier><xsl:value-of select="."/></dcterms:identifier>
    </xsl:template>

    <xsl:template match="datafield">
        <xsl:variable name="unimarc_prefix">
            <xsl:call-template name="unimarc_prefix">
                <xsl:with-param name="tag" select="@tag"/>
            </xsl:call-template>
        </xsl:variable>
        <xsl:if test="string-length($unimarc_prefix) = 5 and not(contains($unimarc_prefix, '9'))">
            <xsl:variable name="unimarc_field">
                <xsl:call-template name="unimarc_field">
                    <xsl:with-param name="field" select="."/>
                </xsl:call-template>
            </xsl:variable>
            <xsl:if test="not(contains($unimarc_field, '9'))">
                <xsl:apply-templates select="subfield">
                    <xsl:with-param name="unimarc_prefix" select="$unimarc_prefix"/>
                    <xsl:with-param name="unimarc_field" select="$unimarc_field"/>
                </xsl:apply-templates>
            </xsl:if>
        </xsl:if>
    </xsl:template>

    <xsl:template match="subfield">
        <xsl:param name="unimarc_prefix"/>
        <xsl:param name="unimarc_field"/>
        <xsl:variable name="term" select="concat($unimarc_prefix, ':', $unimarc_field, @code)"/>
        <xsl:if test="not(contains($term, '9'))">
            <xsl:element name="{$term}">
                <xsl:value-of select="."/>
            </xsl:element>
        </xsl:if>
    </xsl:template>

    <xsl:template name="unimarc_prefix">
        <xsl:param name="tag" select="."/>
        <xsl:choose>
            <xsl:when test="contains($tag, '9')">
                <!-- Skip -->
            </xsl:when>
            <xsl:when test="$tag = 801 or $tag = 802 or $tag = 830 or $tag = 850 or $tag = 856 or $tag = 886">
                <xsl:value-of select="concat('ub', $tag)"/>
            </xsl:when>
            <xsl:otherwise>
                <xsl:variable name="first" select="substring($tag, 1, 1)"/>
                <xsl:choose>
                    <xsl:when test="$first = '0' or $first = '1' or $first = '2' or $first = '3' or $first = '5' or $first = '7'">
                        <xsl:value-of select="concat('ub', $first, 'xx')"/>
                    </xsl:when>
                    <xsl:when test="$first = '4' or $first = '6'">
                        <xsl:value-of select="concat('ub', substring($tag, 1, 2), 'x')"/>
                    </xsl:when>
                </xsl:choose>
            </xsl:otherwise>
        </xsl:choose>
    </xsl:template>

    <xsl:template name="unimarc_field">
        <xsl:param name="field" select="."/>
        <xsl:value-of select="concat('U', $field/@tag, translate(@ind1, ' ', '_'), translate(@ind2, ' ', '_'))"/>
    </xsl:template>

</xsl:stylesheet>
