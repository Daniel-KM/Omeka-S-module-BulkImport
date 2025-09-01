<?xml version="1.0" encoding="UTF-8"?>
<!--
    Description : Convertit les sorties UNIMARC SRU en liste de ressources pour
    Omeka S à convertir via un fichier d'alignement.

    Concrètement, crée simplement les ressources sans modifier leur contenu
    de façon à pouvoir les traiter. Il supprime les éléments liés à la recherche SRU.

    Cette feuille nécessite un fichier d'alignement, par exemple "unimarc_to_omeka.xml",
    ou une configuration dans le module.

    Exemple de fichier xml source : https://bu.unistra.fr/opac/sru?version=1.1&operation=searchRetrieve&query=(dc.source=BUSBN)and(dc.identifier=BUS4683173)&recordSchema=unimarcxml

    @copyright Daniel Berthereau, 2021-2023
    @license CeCILL 2.1 https://cecill.info/licences/Licence_CeCILL_V2.1-fr.txt
-->

<xsl:stylesheet version="1.0"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    xmlns:srw="http://www.loc.gov/zing/srw/"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:ns1="http://www.loc.gov/zing/srw/"
    xmlns:o="http://omeka.org/s/vocabs/o#"

    exclude-result-prefixes="
        xsl
        srw
        xsi
        ns1
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
        <resource wrapper="1">
            <xsl:copy>
                <xsl:apply-templates select="@*|node()"/>
            </xsl:copy>
        </resource>
    </xsl:template>

</xsl:stylesheet>
