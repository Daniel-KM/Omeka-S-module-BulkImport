<?xml version="1.0" encoding="UTF-8"?>
<!--
    Description : Convertit un inventaire ead en liste de ressources avec indication du parent.

    Remarques :
    - Ead Header et Front Matter sont fusionnés en une ressource.
    - Tous les "cXX" sont convertis en "c" simples pour faciliter l’alignement.
    - Les attributs "_depth" et "_parentid" sont ajoutés sur chaque unité (archival description et
        composants) pour faciliter la création des relations.
    - Les métadonnées des composants supérieurs ne sont pas reprises aux niveaux inférieurs.
    - Aucun titre n’est ajouté par défaut.

    @copyright Daniel Berthereau, 2015-2023
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
    xmlns:curation="https://omeka.org/s/vocabs/curation/"

    xmlns:xlink="http://www.w3.org/1999/xlink"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:dc="http://purl.org/dc/elements/1.1/"

    exclude-result-prefixes="
        xsl rdf rdfs skos
        "
    >

    <xsl:output method="xml" encoding="UTF-8" indent="yes"/>

    <xsl:strip-space elements="*"/>

    <xsl:template match="/ead">
        <resources>
            <xsl:apply-templates select="eadheader"/>
            <xsl:apply-templates select="archdesc"/>
            <xsl:apply-templates select="//c | //c01 | //c02 | //c03 | //c04 | //c05 | //c06 | //c07 | //c08 | //c09 | //c10 | //c11 | //c12" mode="root"/>
        </resources>
    </xsl:template>

    <xsl:template match="eadheader">
        <resource type="eadheader">
            <eadheader id="{eadheader/eadid/text()}">
                <xsl:apply-templates select="@*|node()"/>
            </eadheader>
            <xsl:copy>
                <xsl:apply-templates select="../frontmatter/@* | ../frontmatter/node()"/>
            </xsl:copy>
        </resource>
    </xsl:template>

    <xsl:template match="archdesc">
        <resource>
            <archdesc _depth="0" _parent_id="{parent::ead/eadheader/eadid/text()}">
                <xsl:apply-templates select="@*|node()"/>
            </archdesc>
        </resource>
    </xsl:template>

    <!-- Ajout de la profondeur et de l’id parent à chaque composant. -->
    <xsl:template match="c | c01 | c02 | c03 | c04 | c05 | c06 | c07 | c08 | c09 | c10 | c11 | c12" mode="root">
        <resource>
            <!-- Supprime le nom des composants nommés pour faciliter le traitement ultérieur. -->
            <c>
                <xsl:attribute name="_depth">
                    <xsl:call-template name="depth"/>
                </xsl:attribute>
                <xsl:attribute name="_parent_id">
                    <xsl:choose>
                        <xsl:when test="parent::* and parent::*/@id and parent::*/@id != ''">
                            <xsl:value-of select="parent::*/@id"/>
                        </xsl:when>
                        <xsl:when test="parent::* and parent::*/did/unitid/@identifier and parent::*/did/unitid/@identifier != ''">
                            <xsl:value-of select="parent::*/did/unitid/@identifier"/>
                        </xsl:when>
                        <xsl:otherwise>
                            <xsl:text></xsl:text>
                        </xsl:otherwise>
                    </xsl:choose>
                </xsl:attribute>
                <xsl:apply-templates select="@*|node()"/>
            </c>
        </resource>
    </xsl:template>

    <!-- Suppression des sous-composants. -->
    <xsl:template match="archdesc/dsc | c/c | c01/c02 | c02/c03 | c03/c04 | c04/c05 | c05/c06 | c06/c07 | c07/c08 | c08/c09 | c09/c10 | c10/c11 | c11/c12">
    </xsl:template>

    <!-- Identity template -->
    <xsl:template match="@*|node()">
        <xsl:copy>
            <xsl:apply-templates select="@*|node()"/>
        </xsl:copy>
    </xsl:template>

    <!-- Templates spécifiques -->

    <!-- Profondeur d’un composant à partir de 0 pour archdesc. -->
    <xsl:template name="depth">
        <xsl:choose>
            <xsl:when test="local-name() = 'archdesc'">
                <xsl:value-of select="0"/>
            </xsl:when>
            <xsl:when test="local-name() = 'c'">
                <xsl:value-of select="count(ancestor::c) + 1"/>
            </xsl:when>
            <xsl:otherwise>
                <xsl:value-of select="substring(local-name(), 2, 2) + 0"/>
            </xsl:otherwise>
        </xsl:choose>
    </xsl:template>

</xsl:stylesheet>
