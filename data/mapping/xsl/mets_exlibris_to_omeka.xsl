<?xml version="1.0" encoding="UTF-8"?>
<!--
    Description : Convertit un fichier mets ExLibris en un item Omeka S avec les fichiers.

    Seules les options couramment utilisées par les prestataires de numérisation sont gérées.

    Les fichiers ex-Libris et Arkhenum sont vraiment complexes, incomplets, sans références,
    changeants et non standard ou sans profil.

    Cf. les options dans le fichier mets_to_omeka.xsl, qui est importé dans le présent fichier.

    Options complémentaires :

    - extension_replace_from ("")
        Chaîne à remplacer dans les extensions de fichier, par exemple "pdf".

    - extension_replace_to ("")
        Chaîne de remplacement dans les extensions de fichier, par exemple "jpeg".

    @copyright Daniel Berthereau, 2021-2024 pour la Sorbonne Nouvelle
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

    <!-- Utilisation des variables, constantes, etc. de mets_to_omeka.xsl. -->
    <xsl:import href="mets_to_omeka.xsl"/>

    <!-- Paramètres -->

    <!-- Chaîne à remplacer dans les extensions de fichiers. -->
    <xsl:param name="extension_replace_from"></xsl:param>

    <!-- Chaîne de remplacement dans les extensions de fichiers. -->
    <xsl:param name="extension_replace_to"></xsl:param>

    <!-- Constantes -->

    <!-- TODO Trouver un meilleur moyen d'identifier le nom source quand il y a plusieurs dc:source. -->
    <xsl:variable name="source_id">
        <xsl:value-of select="/mets:mets/mets:dmdSec/mets:mdWrap/mets:xmlData//dc:source[2]"/>
    </xsl:variable>

    <!-- Templates -->

    <xsl:template match="mets:fptr" mode="file">
        <!-- Les pointeurs renvoient vers des numéros et non des noms de fichier. -->
        <xsl:variable name="fptr" select="."/>
        <!-- Mets le chemin du dossier seulement dans un second temps. -->
        <xsl:variable name="href">
            <xsl:apply-templates select="/mets:mets/mets:fileSec//mets:file[@ID = $fptr/@FILEID]/mets:FLocat/@xlink:href" mode="no_force"/>
        </xsl:variable>
        <xsl:variable name="relation" select="/mets:mets/mets:dmdSec/mets:mdWrap/mets:xmlData/relations/relation[file_id = $href]"/>
        <xsl:variable name="number" select="substring($relation/file_id, 8)"/>
        <xsl:variable name="index" select="format-number($number, '0000')"/>
        <xsl:variable name="url_source">
            <xsl:choose>
                <xsl:when test="$index != 'NaN'">
                    <xsl:value-of select="concat(
                        $basepath_slash,
                        $relation/identifier,
                        '_',
                        $source_id,
                        '_',
                        $index,
                        '.',
                        $relation/file_extension
                    )"/>
                </xsl:when>
                <!-- 2e cas. -->
                <xsl:otherwise>
                    <xsl:variable name="href_2">
                        <xsl:apply-templates select="/mets:mets/mets:fileSec//mets:file[@ID = $fptr/@FILEID]/mets:FLocat/@xlink:href"/>
                    </xsl:variable>
                    <xsl:variable name="relation_2" select="/mets:mets/mets:dmdSec/mets:mdWrap/mets:xmlData/relations/relation[file_id = $href_2]"/>
                    <xsl:variable name="number_2" select="substring($relation_2/file_id, 8)"/>
                    <xsl:variable name="index_2" select="format-number($number_2, '0000')"/>
                    <xsl:choose>
                        <xsl:when test="$index_2 != 'NaN'">
                            <xsl:value-of select="concat(
                                $basepath_slash,
                                $relation_2/identifier,
                                '_',
                                $source_id,
                                '_',
                                $index_2,
                                '.',
                                $relation_2/file_extension
                            )"/>
                        </xsl:when>
                        <xsl:otherwise>
                            <!-- Réapplique le traitement parent importé au cas où le même xslt est utilisé pour des fichiers mets standard. -->
                            <xsl:apply-templates select="/mets:mets/mets:fileSec//mets:file[@ID = $fptr/@FILEID]/mets:FLocat/@xlink:href"/>
                        </xsl:otherwise>
                    </xsl:choose>
                </xsl:otherwise>
            </xsl:choose>
        </xsl:variable>
        <xsl:choose>
            <xsl:when test="$extension_replace_from = ''">
                <xsl:value-of select="$url_source"/>
            </xsl:when>
            <xsl:otherwise>
                <xsl:variable name="filename">
                    <xsl:call-template name="get_filename">
                        <xsl:with-param name="filepath" select="$url_source"/>
                    </xsl:call-template>
                </xsl:variable>
                <xsl:variable name="extension">
                    <xsl:call-template name="string_part_last">
                        <xsl:with-param name="string" select="$filename"/>
                        <xsl:with-param name="character" select="'.'"/>
                    </xsl:call-template>
                </xsl:variable>
                <xsl:choose>
                    <!-- Ajoute l’extension si elle manque -->
                    <xsl:when test="$extension = ''">
                        <xsl:value-of select="concat($url_source, '.', $extension_replace_to)"/>
                    </xsl:when>
                    <xsl:when test="$extension = $extension_replace_from">
                        <xsl:value-of select="concat(substring($url_source, 1, string-length($url_source) - string-length($extension)), $extension_replace_to)"/>
                    </xsl:when>
                    <xsl:otherwise>
                        <xsl:value-of select="$url_source"/>
                    </xsl:otherwise>
                </xsl:choose>
            </xsl:otherwise>
        </xsl:choose>
    </xsl:template>

    <!-- Récupère et normalise les urls et les chemins locaux, sans les compléter. -->
    <xsl:template match="@xlink:href" mode="no_force">
        <xsl:variable name="href">
            <xsl:choose>
                <xsl:when test="$filepath_replace_from != ''">
                    <xsl:call-template name="search_and_replace">
                        <xsl:with-param name="input" select="."/>
                        <xsl:with-param name="search_string" select="$filepath_replace_from"/>
                        <xsl:with-param name="replace_string" select="$filepath_replace_to"/>
                    </xsl:call-template>
                </xsl:when>
                <xsl:otherwise>
                    <xsl:value-of select="."/>
                </xsl:otherwise>
            </xsl:choose>
        </xsl:variable>
        <xsl:choose>
            <xsl:when test="substring($href, 1, 8) = 'https://' or substring($href, 1, 7) = 'http://'">
                <xsl:value-of select="$href"/>
            </xsl:when>
            <!-- Corrige les chemins locaux incorrects.
            Parfois, seuls un ou deux "/" sont présents, mais il en faut trois : le protocole est suivi de "://" et le chemin local commence par un "/". -->
            <xsl:when test="substring($href, 1, 8) = 'file:///'">
                <xsl:value-of select="translate(substring($href, 8), '\', '/')"/>
            </xsl:when>
            <xsl:when test="substring($href, 1, 7) = 'file://'">
                <xsl:value-of select="translate(substring($href, 7), '\', '/')"/>
            </xsl:when>
            <xsl:when test="substring($href, 1, 6) = 'file:/'">
                <xsl:value-of select="translate(substring($href, 6), '\', '/')"/>
            </xsl:when>
            <xsl:when test="substring($href, 1, 1) = '/' or substring($href, 1, 1) = '\'">
                <xsl:value-of select="translate($href, '\', '/')"/>
            </xsl:when>
            <!-- Cas particulier du chemin relatif commençant par ".:". -->
            <xsl:when test="substring($href, 1, 2) = './' or substring($href, 1, 2) = '.\'">
                <xsl:value-of select="translate(substring($href, 3), '\', '/')"/>
            </xsl:when>
            <!-- Sinon on conserve le nom de fichier. -->
            <xsl:otherwise>
                <xsl:value-of select="translate($href, '\', '/')"/>
            </xsl:otherwise>
        </xsl:choose>
    </xsl:template>

    <xsl:template name="get_filename">
        <xsl:param name="filepath" />
        <xsl:choose>
            <xsl:when test="substring-after($filepath, '/')">
                <xsl:call-template name="get_filename">
                    <xsl:with-param name="filepath" select="substring-after($filepath, '/')" />
                </xsl:call-template>
            </xsl:when>
            <xsl:otherwise>
                <xsl:value-of select="$filepath" />
            </xsl:otherwise>
        </xsl:choose>
    </xsl:template>

</xsl:stylesheet>
