<?xml version="1.0" encoding="UTF-8"?>
<!--
    Description : Convert SRU/SRW output from Dublin Core (oai_dc) to a list of resources for Omeka S.

    Manage elements non included in oai dc and manage all dcterms.

    To download files, see the xsl file "sru.dublin-core_with_file_gallica_to_omeka.xsl".

    @copyright Daniel Berthereau, 2021-2025
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
    xmlns:oai_dc="http://www.openarchives.org/OAI/2.0/oai_dc/"
    xmlns:dc="http://purl.org/dc/elements/1.1/"
    xmlns:o="http://omeka.org/s/vocabs/o#"

    exclude-result-prefixes="
        xsl rdf rdfs skos
        srw oai_dc dc
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
            <xsl:choose>
                <xsl:when test="srw:records/srw:record/srw:recordData/oai_dc:dcterms">
                    <xsl:apply-templates select="srw:records/srw:record/srw:recordData/oai_dc:dcterms"/>
                </xsl:when>
                <xsl:otherwise>
                    <xsl:apply-templates select="srw:records/srw:record/srw:recordData/oai_dc:dc"/>
                </xsl:otherwise>
            </xsl:choose>
        </resources>
    </xsl:template>

    <xsl:template match="oai_dc:dcterms | oai_dc:dc">
        <resource o:resource_template="{$resource_template}">
            <xsl:apply-templates select="*"/>
        </resource>
    </xsl:template>

    <!-- Replace prefix "dc" by "dcterms", used in omeka. -->
    <xsl:template match="dc:*">
        <xsl:element name="{concat('dcterms:', local-name(.))}">
            <xsl:value-of select="."/>
        </xsl:element>
    </xsl:template>

</xsl:stylesheet>
