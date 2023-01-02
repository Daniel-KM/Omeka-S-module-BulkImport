<?xml version="1.0" encoding="UTF-8"?>
<!--
    Description : Convertit un fichier mets ExLibris en un item Omeka S avec les fichiers.

    Seules les options couramment utilisées par les prestataires de numérisation sont gérées.

    @copyright Daniel Berthereau, 2021-2022 pour la Sorbonne Nouvelle
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

    <xsl:import href="mets_to_omeka.xsl"/>

    <!-- Paramètres -->

    <!-- Cf. feuille importée. -->

    <!-- Constantes -->

    <!-- TODO Trouver un meilleur moyen d'identifier le nom source quand il y a plusieurs dc:source. -->
    <xsl:variable name="source_id">
        <xsl:value-of select="/mets:mets/mets:dmdSec/mets:mdWrap/mets:xmlData//dc:source[2]"/>
    </xsl:variable>

    <!-- Templates -->

    <xsl:template match="mets:fptr" mode="file">
        <!-- Les pointeurs renvoient vers des numéros et non des noms de fichier. -->
        <xsl:variable name="fptr" select="."/>
        <xsl:variable name="href">
            <xsl:apply-templates select="/mets:mets/mets:fileSec//mets:file[@ID = $fptr/@FILEID]/mets:FLocat/@xlink:href"/>
        </xsl:variable>
        <xsl:variable name="relation" select="/mets:mets/mets:dmdSec/mets:mdWrap/mets:xmlData/relations/relation[file_id = $href]"/>
        <xsl:variable name="number" select="substring($relation/file_id, 8)"/>
        <xsl:variable name="index" select="format-number($number, '0000')"/>
        <xsl:value-of select="concat(
            $relation/identifier,
            '_',
            $source_id,
            '_',
            $index,
            '.',
            $relation/file_extension
        )"/>
    </xsl:template>

</xsl:stylesheet>
