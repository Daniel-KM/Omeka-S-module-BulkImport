<?xml version="1.0" encoding="UTF-8"?>
<!--
    Description : Convertit un fichier mets en un item Omeka S avec les fichiers.

    Seuls les options couramment utilisées par les prestataires de numérisation sont gérées.

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
        "
    >

    <xsl:output method="xml" encoding="UTF-8" indent="yes"/>

    <xsl:strip-space elements="*"/>

    <!-- Constantes -->

    <!-- Paramètres -->
    <xsl:param name="basepath"></xsl:param>

    <!-- Identity template -->
    <xsl:template match="@*|node()">
        <xsl:copy>
            <xsl:apply-templates select="@*|node()"/>
        </xsl:copy>
    </xsl:template>

    <xsl:template match="/mets:mets">
        <resources>
            <!-- Item principal -->
            <xsl:element name="resource">
                <xsl:attribute name="o:created">
                    <xsl:apply-templates select="mets:metsHdr/@CREATEDATE"/>
                </xsl:attribute>
                <xsl:attribute name="o:modified">
                    <xsl:apply-templates select="mets:metsHdr/@LASTMODDATE"/>
                </xsl:attribute>
                <xsl:apply-templates select="mets:dmdSec"/>
                <!-- Fichiers -->
                <!-- Utilisation de structMap pour avoir le bon ordre des fichiers, de préférence l'index physique. -->
                <xsl:choose>
                    <xsl:when test="mets:structMap[@TYPE = 'physical']">
                        <xsl:apply-templates select="mets:structMap[@TYPE = 'physical']/descendant::mets:fptr"/>
                    </xsl:when>
                    <xsl:otherwise>
                        <xsl:apply-templates select="mets:structMap[1]/descendant::mets:fptr"/>
                    </xsl:otherwise>
                </xsl:choose>
            </xsl:element>
        </resources>
    </xsl:template>

    <xsl:template match="mets:dmdSec">
        <xsl:choose>
        <!-- Certains mets mettent un "dc:dc" intermédiaire. -->
        <xsl:when test="mets:mdWrap/@MDTYPE = 'DC' and mets:mdWrap/mets:xmlData/dc:dc">
                <xsl:apply-templates select="mets:mdWrap/mets:xmlData/dc:dc/*"/>
            </xsl:when>
            <xsl:when test="mets:mdWrap/@MDTYPE = 'DC'">
                <xsl:apply-templates select="mets:mdWrap/mets:xmlData/*"/>
            </xsl:when>
            <xsl:when test="mets:mdWrap/@MDTYPE = 'DCTERMS' and mets:mdWrap/mets:xmlData/dcterms:dcterms">
                <xsl:copy-of select="mets:mdWrap/@MDTYPE = 'DCTERMS' and mets:mdWrap/mets:xmlData/dcterms:dcterms/dcterms:*"/>
            </xsl:when>
            <xsl:when test="mets:mdWrap/@MDTYPE = 'DCTERMS'">
                <xsl:copy-of select="mets:mdWrap/@MDTYPE = 'DCTERMS' and mets:mdWrap/mets:xmlData/dcterms:*"/>
            </xsl:when>
        </xsl:choose>
    </xsl:template>

    <xsl:template match="dc:*">
        <xsl:element name="dcterms:{local-name()}">
            <xsl:apply-templates select="@* | node()"/>
        </xsl:element>
    </xsl:template>

    <xsl:template match="mets:fptr">
        <xsl:variable name="fptr" select="."/>
        <o:media o:ingester="file">
            <xsl:apply-templates select="/mets:mets/mets:fileSec//mets:file[@ID = $fptr/@FILEID]/mets:FLocat/@xlink:href"/>
        </o:media>
    </xsl:template>

    <xsl:template match="@xlink:href">
        <xsl:choose>
            <xsl:when test="substring(., 1, 2) = './' or substring(., 1, 2) = '.\'">
                <xsl:value-of select="concat($basepath, translate(substring(., 3), '\', '/'))"/>
            </xsl:when>
            <xsl:otherwise>
                <xsl:value-of select="concat($basepath, translate(., '\', '/'))"/>
            </xsl:otherwise>
        </xsl:choose>
    </xsl:template>

    <!-- Create an iso date for omeka database (2022-11-12T13:14:15). -->
    <!-- Warning: timezone is lost. -->
    <xsl:template match="@CREATEDATE | @LASTMODDATE">
        <xsl:value-of select="substring(., 1, 19)"/>
    </xsl:template>

</xsl:stylesheet>
