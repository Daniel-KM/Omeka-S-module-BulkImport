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

    <!-- Constantes -->

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

    <!-- Identity template -->
    <xsl:template match="@*|node()">
        <xsl:copy>
            <xsl:apply-templates select="@*|node()"/>
        </xsl:copy>
    </xsl:template>

</xsl:stylesheet>