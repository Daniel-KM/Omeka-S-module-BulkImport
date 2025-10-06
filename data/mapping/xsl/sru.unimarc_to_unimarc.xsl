<?xml version="1.0" encoding="UTF-8"?>
<!--
    Description : Convertit les sorties UNIMARC SRU en liste de ressources pour Omeka S.

    Nécessite d'installer l'ensemble des ontologies Unimarc (element sets).
    @link https://www.iflastandards.info/unimarc

    Cette feuille xsl est une version spécifique de "sru.unimarc_to_omeka.xsl".

    Contrairement à "sru.unimarc_to_resources.xsl", cette feuille xsl permet de
    créer directement des ressources Omeka et il n'est pas nécessaire d'utiliser
    un fichier d'alignement.

    Les mentions locales (9xx, x9x, etc.) ne sont pas importées.
    Les mentions d'exemplaire (995) ne sont pas importées.

    Exemple de fichier xml source : https://bu.unistra.fr/opac/sru?version=1.1&operation=searchRetrieve&query=(dc.source=BUSBN)and(dc.identifier=BUS4683173)&recordSchema=unimarcxml

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
    xmlns:ub0xx="http://iflastandards.info/ns/unimarc/unimarcb/elements/0XX/"
    xmlns:ub1xx="http://iflastandards.info/ns/unimarc/unimarcb/elements/1XX/"
    xmlns:ub2xx="http://iflastandards.info/ns/unimarc/unimarcb/elements/2XX/"
    xmlns:ub3xx="http://iflastandards.info/ns/unimarc/unimarcb/elements/3XX/"
    xmlns:ub41x="http://iflastandards.info/ns/unimarc/unimarcb/elements/41X/"
    xmlns:ub42x="http://iflastandards.info/ns/unimarc/unimarcb/elements/42X/"
    xmlns:ub43x="http://iflastandards.info/ns/unimarc/unimarcb/elements/43X/"
    xmlns:ub44x="http://iflastandards.info/ns/unimarc/unimarcb/elements/44X/"
    xmlns:ub45x="http://iflastandards.info/ns/unimarc/unimarcb/elements/45X/"
    xmlns:ub46x="http://iflastandards.info/ns/unimarc/unimarcb/elements/46X/"
    xmlns:ub47x="http://iflastandards.info/ns/unimarc/unimarcb/elements/47X/"
    xmlns:ub48x="http://iflastandards.info/ns/unimarc/unimarcb/elements/48X/"
    xmlns:ub5xx="http://iflastandards.info/ns/unimarc/unimarcb/elements/5XX/"
    xmlns:ub60x="http://iflastandards.info/ns/unimarc/unimarcb/elements/60X/"
    xmlns:ub61x="http://iflastandards.info/ns/unimarc/unimarcb/elements/61X/"
    xmlns:ub62x="http://iflastandards.info/ns/unimarc/unimarcb/elements/62X/"
    xmlns:ub66x="http://iflastandards.info/ns/unimarc/unimarcb/elements/66X/"
    xmlns:ub67x="http://iflastandards.info/ns/unimarc/unimarcb/elements/67X/"
    xmlns:ub68x="http://iflastandards.info/ns/unimarc/unimarcb/elements/68X/"
    xmlns:ub7xx="http://iflastandards.info/ns/unimarc/unimarcb/elements/7XX/"
    xmlns:ub801="http://iflastandards.info/ns/unimarc/unimarcb/elements/801/"
    xmlns:ub802="http://iflastandards.info/ns/unimarc/unimarcb/elements/802/"
    xmlns:ub830="http://iflastandards.info/ns/unimarc/unimarcb/elements/830/"
    xmlns:ub850="http://iflastandards.info/ns/unimarc/unimarcb/elements/850/"
    xmlns:ub856="http://iflastandards.info/ns/unimarc/unimarcb/elements/856/"
    xmlns:ub886="http://iflastandards.info/ns/unimarc/unimarcb/elements/886/"
    xmlns:curation="https://omeka.org/s/vocabs/curation/"
    xmlns:o="http://omeka.org/s/vocabs/o#"

    exclude-result-prefixes="
        xsl rdf rdfs skos
        srw
        "
    >

    <xsl:output method="xml" encoding="UTF-8" indent="yes"/>

    <xsl:strip-space elements="*"/>

    <!-- Constants -->
    <!-- Set the name of the resource template. None by default. -->
    <xsl:variable name="resource_template" select="''"/>

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
        <resource o:resource_template="{$resource_template}">
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
