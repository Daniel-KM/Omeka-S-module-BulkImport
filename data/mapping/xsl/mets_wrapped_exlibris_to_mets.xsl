<?xml version="1.0" encoding="UTF-8"?>
<!--
    Description : Convertit un fichier mets décomposé et enveloppé par un xml exlibris utilisant des cdata en un mets standard.

    Ce xslt a été conçu à partir d'un exemple, sans spécification : les informations publiques sur
    le "[digital entity manager](https://developers.exlibrisgroup.com/digitool/repository/digital-entity-manager)"
    d'ExLibris sont limitées.

    L'exemple contient plusieurs erreurs. Par exemple, il utilise "image/pdf" pour indiquer "application/pdf".
    Ou un fichier est référencé mais sans identifiant. Ou encore les urls href ne sont que des numéros.
    Ou encore les données sont dans des "cdata" les rendant plus difficile à gérer.

    On pourrait récupérer le xml mets original via "stream_ref", mais le lien est caché et leExLibris
    ne fonctionne pas bien sur les liens de l'exemple.

    La relation sans file_id est la vignette et non prise en compte par défaut dans la liste des relations (paramètre "include_thumbnail" = 0).
    A noter que le mets ne contient pas de référence à cette vignette.

    Configuration des options avec les valeurs par défaut :

    - include_thumbnail (0)
        Inclure (1) ou non (0) les fichiers des vignettes.

    @todo Normaliser les informations sur les "relations" (noms des fichiers, chemin, identifiants et autres données sur les fichiers).

    @copyright Daniel Berthereau, 2022 pour la Sorbonne Nouvelle
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

    xmlns:xb="http://com/exlibris/digitool/repository/api/xmlbeans"

    xmlns:mets="http://www.loc.gov/METS/"
    xmlns:xlink="http://www.w3.org/1999/xlink"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:dc="http://purl.org/dc/elements/1.1/"

    exclude-result-prefixes="
        xsl rdf rdfs skos
        xb
        "
    >

    <xsl:output method="xml" encoding="UTF-8" indent="yes"/>

    <xsl:strip-space elements="*"/>

    <!-- Paramètres -->

    <xsl:param name="include_thumbnail" select="'0'"/>

    <!-- Constantes -->

    <xsl:variable name="end_of_line"><xsl:text>&#x0A;</xsl:text></xsl:variable>
    <xsl:variable name="xml_prolog"><![CDATA[<?xml version="1.0" encoding="UTF-8"?>]]></xsl:variable>

    <xsl:variable name="lowercase" select="'abcdefghijklmnopqrstuvwxyz'" />
    <xsl:variable name="uppercase" select="'ABCDEFGHIJKLMNOPQRSTUVWXYZ'" />

    <xsl:variable name="usage_thumbnail">
        <xsl:choose>
            <xsl:when test="$include_thumbnail = '0'">
                <xsl:text>THUMBNAIL</xsl:text>
            </xsl:when>
            <xsl:otherwise>
                <xsl:text>ALL FILES</xsl:text>
            </xsl:otherwise>
        </xsl:choose>
    </xsl:variable>

    <!-- Templates -->

    <xsl:template match="/xb:digital_entity">
        <mets:mets xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.loc.gov/METS/ http://www.loc.gov/standards/mets/mets.xsd" xmlns:mets="http://www.loc.gov/METS/">
            <!--
            <mets:metsHdr CREATEDATE="{translate(/control/creation_date, ' ', 'T')}" RECORDSTATUS="" LASTMODDATE="{translate(/control/modification_date, ' ', 'T')}">
                <mets:agent TYPE="INDIVIDUAL" ROLE="CREATOR">
                    <mets:name><xsl:value-of select="/control/creator"/></mets:name>
                    <mets:note></mets:note>
                </mets:agent>
                <mets:agent TYPE="OTHER" OTHERTYPE="Software" ROLE="CREATOR">
                    <mets:name><xsl:value-of select="/control/note"/></mets:name>
                    <mets:note></mets:note>
                </mets:agent>
            </mets:metsHdr>
            -->

            <xsl:value-of select="$end_of_line"/>

            <xsl:choose>
                <xsl:when test="mds/md[name = 'mets_section' and type = 'metsHdr']/value/text() != ''">
                    <xsl:value-of select="mds/md[name = 'mets_section' and type = 'metsHdr']/value" disable-output-escaping="yes"/>
                    <xsl:value-of select="$end_of_line"/>
                </xsl:when>
                <xsl:otherwise>
                    <mets:metsHdr>
                    </mets:metsHdr>
                </xsl:otherwise>
            </xsl:choose>

            <xsl:choose>
                <xsl:when test="mds/md[name = 'mets_section' and type = 'dmdSec']/value/text() != ''">
                    <xsl:value-of select="mds/md[name = 'mets_section' and type = 'dmdSec']/value" disable-output-escaping="yes"/>
                    <xsl:value-of select="$end_of_line"/>
                </xsl:when>
                <xsl:when test="mds/md[name = 'descriptive']/value/text() != ''">
                    <xsl:variable name="descriptive" select="mds/md[name = 'descriptive']/value/text()"/>
                    <mets:dmdSec ID="{mds/md[name = 'descriptive']/name}">
                        <mets:mdWrap MDTYPE="{translate(mds/md[name = 'descriptive']/type, $lowercase, $uppercase)}" MIMETYPE="text/xml">
                            <mets:xmlData>
                                <xsl:choose>
                                    <xsl:when test="substring($descriptive, 1, 38) = $xml_prolog">
                                        <xsl:value-of select="substring($descriptive, 39)" disable-output-escaping="yes"/>
                                    </xsl:when>
                                    <xsl:otherwise>
                                        <xsl:value-of select="$descriptive" disable-output-escaping="yes"/>
                                    </xsl:otherwise>
                                </xsl:choose>
                                <dcterms:isFormatOf o:type="uri"><xsl:value-of select="urls/url[@type = 'resource_discovery']"/></dcterms:isFormatOf>
                                <!-- TODO Voir si on met dans une autre section. -->
                                <relations>
                                    <xsl:apply-templates select="relations/relation[usage_type != $usage_thumbnail]">
                                        <xsl:sort select ="substring(file_id, 8)" data-type="number" order="ascending"/>
                                    </xsl:apply-templates>
                                </relations>
                            </mets:xmlData>
                        </mets:mdWrap>
                    </mets:dmdSec>
                    <xsl:value-of select="$end_of_line"/>
                </xsl:when>
                <xsl:otherwise>
                    <mets:dmdSec>
                    </mets:dmdSec>
                </xsl:otherwise>
            </xsl:choose>

            <xsl:choose>
                <xsl:when test="mds/md[name = 'mets_section' and type = 'amdSec']/value/text() != ''">
                    <xsl:value-of select="mds/md[name = 'mets_section' and type = 'amdSec']/value" disable-output-escaping="yes"/>
                    <xsl:value-of select="$end_of_line"/>
                </xsl:when>
                <xsl:otherwise>
                    <mets:amdSec>
                    </mets:amdSec>
                </xsl:otherwise>
            </xsl:choose>

            <xsl:choose>
                <xsl:when test="mds/md[name = 'mets_section' and type = 'fileSec']/value/text() != ''">
                    <xsl:value-of select="mds/md[name = 'mets_section' and type = 'fileSec']/value" disable-output-escaping="yes"/>
                    <xsl:value-of select="$end_of_line"/>
                </xsl:when>
                <xsl:otherwise>
                    <mets:fileSec>
                    </mets:fileSec>
                </xsl:otherwise>
            </xsl:choose>

            <xsl:choose>
                <xsl:when test="mds/md[name = 'mets_section' and type = 'structLink']/value/text() != ''">
                    <xsl:value-of select="mds/md[name = 'mets_section' and type = 'structLink']/value" disable-output-escaping="yes"/>
                    <xsl:value-of select="$end_of_line"/>
                </xsl:when>
                <xsl:otherwise>
                    <mets:structLink>
                    </mets:structLink>
                </xsl:otherwise>
            </xsl:choose>

            <xsl:choose>
                <xsl:when test="mds/md[name = 'mets_section' and type = 'structMap']/value/text() != ''">
                    <xsl:value-of select="mds/md[name = 'mets_section' and type = 'structMap']/value" disable-output-escaping="yes"/>
                    <xsl:value-of select="$end_of_line"/>
                </xsl:when>
                <xsl:otherwise>
                    <mets:structMap>
                    </mets:structMap>
                </xsl:otherwise>
            </xsl:choose>

            <xsl:choose>
                <xsl:when test="mds/md[name = 'mets_section' and type = 'behaviorSec']/value/text() != ''">
                    <xsl:value-of select="mds/md[name = 'mets_section' and type = 'behaviorSec']/value" disable-output-escaping="yes"/>
                    <xsl:value-of select="$end_of_line"/>
                </xsl:when>
                <xsl:otherwise>
                    <mets:behaviorSec>
                    </mets:behaviorSec>
                </xsl:otherwise>
            </xsl:choose>

        </mets:mets>
    </xsl:template>

    <!-- Simplification des relations pour récupérer le nom du fichier et les données. -->
    <xsl:template match="relations/relation">
        <relation>
            <file_id><xsl:value-of select="file_id"/></file_id>
            <identifier><xsl:value-of select="pid"/></identifier>
            <directory_path><xsl:value-of select="directory_path"/></directory_path>
            <label><xsl:value-of select="label"/></label>
            <created><xsl:value-of select="translate(substring(creation_date, 1, 19), ' ', 'T')"/></created>
            <modified><xsl:value-of select="translate(substring(modification_date, 1, 19), ' ', 'T')"/></modified>
            <mime_type><xsl:value-of select="mime_type"/></mime_type>
            <file_extension><xsl:value-of select="file_extension"/></file_extension>
            <url><xsl:value-of select="urls/url[@type = 'stream_manifestation']"/></url>
        </relation>
    </xsl:template>

    <!-- Identity template -->
    <xsl:template match="@*|node()">
        <xsl:copy>
            <xsl:apply-templates select="@*|node()"/>
        </xsl:copy>
    </xsl:template>

</xsl:stylesheet>
