<?xml version="1.0" encoding="UTF-8"?>
<!--
    Description : Convertit un inventaire ead en liste de ressources avec indication du parent.

    Remarques :
    - Ead Header et Front Matter sont fusionnés en une ressource.
    - Tous les "cXX" sont convertis en "c" simples pour faciliter l’alignement.
    - Les attributs "_depth" et "_parentid" sont ajoutés sur chaque unité (archival description et
        composants) pour faciliter la création des relations.
    - Aucun titre n’est ajouté par défaut.

    Pour reprendre les métadonnées des composants supérieurs, utiliser les paramètres suivants :

    - "parent_copy_select" :
        - "all" (par défaut) : copier tous les éléments supérieurs.
        - "list" : copier les éléments supérieurs listés dans "parent_copy_list".
        - "no" : ne pas copier les éléments supérieurs pour les traiter ultérieurement.

    - "parent_copy_mode" :
        - "missing" (par défaut) : copier les éléments supérieurs manquants. C'est la logique normale de l'ead.
            Par exemple, si le composant a une description, les descriptions supérieures ne sont pas reprises.
        - "all" : copier tous les éléments supérieurs, même présent dans le niveau en cours.

    Attention : seuls les éléments dans "did" sont copiés.

    @copyright Daniel Berthereau, 2015-2023
    @license CeCILL 2.1 https://cecill.info/licences/Licence_CeCILL_V2.1-fr.txt
-->

<xsl:stylesheet version="1.0"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    xmlns:exsl="http://exslt.org/common"

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
        xsl exsl rdf rdfs skos
        "

    extension-element-prefixes="exsl"
    >

    <xsl:output method="xml" encoding="UTF-8" indent="yes"/>

    <xsl:strip-space elements="*"/>

    <!-- Paramètres -->

    <!-- Copie des éléments parents (all / list / no). -->
    <xsl:param name="parent_copy_select">all</xsl:param>

    <!-- Mode de copie des éléments parents (missing / all). -->
    <xsl:param name="parent_copy_mode">missing</xsl:param>

    <!-- Liste des éléments parents à inclure pour l’option "list". -->
    <xsl:param name="parent_copy_list">
        <e>physdesc</e>
        <e>physloc</e>
        <e>repository</e>
        <e>unitdate</e>
    </xsl:param>

    <!-- Constantes. -->

    <xsl:variable name="ead_tags" select="document('ead_tags.xml')/tags"/>

    <!-- xslt v1.0 requires an intermediate constant to get a node set. -->
    <xsl:variable name="parent_set_fragment">
        <xsl:choose>
            <xsl:when test="$parent_copy_select = 'all'">
                <xsl:call-template name="parent_set_ead"/>
            </xsl:when>
            <xsl:when test="$parent_copy_select = 'list'">
                <xsl:call-template name="parent_set_list"/>
            </xsl:when>
        </xsl:choose>
    </xsl:variable>

    <xsl:variable name="parent_set" select="exsl:node-set($parent_set_fragment)"/>

    <!-- Templates. -->

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
                    <xsl:call-template name="id_sub"/>
                </xsl:attribute>
                <xsl:apply-templates select="@*|node()"/>
            </c>
        </resource>
    </xsl:template>

    <!-- Copie des éléments du did et ceux des éléments parents. -->
    <!-- Contient : abstract, container, dao, daogrp, head, langmaterial, materialspec, note, origination, physdesc, physloc, repository, unitdate, unitid, unittitle -->
    <xsl:template match="did">
        <xsl:variable name="node" select="."/>
        <xsl:copy>
            <!-- Ajout des éléments parents selon le paramètre. -->
            <xsl:choose>
                <!-- Copie uniquement du premier élément ancètre : dans la liste des parents qui ont un did avec un élément, prendre le plus proche. -->
                <xsl:when test="$parent_copy_mode = 'missing'">
                    <!-- TODO Enlever le for-each. -->
                    <xsl:for-each select="$parent_set/e[not(e)][@name = $ead_tags/e[@name = 'did']/e/@name]">
                        <xsl:variable name="element" select="@name"/>
                        <xsl:if test="not($node/*[local-name() = $element])">
                            <xsl:apply-templates select="$node/ancestor::*[did/*[local-name() = $element]][1]/did/*[local-name() = $element]" mode="ancestor"/>
                        </xsl:if>
                    </xsl:for-each>
                </xsl:when>
            </xsl:choose>
            <!-- Dans tous les cas, on prend les éléments en cours. -->
            <xsl:apply-templates select="@*|node()"/>
        </xsl:copy>
    </xsl:template>

    <!-- Copie des sous-éléments du did et ceux des éléments parents. -->
    <xsl:template match="did/node()">
        <xsl:variable name="node" select="."/>
        <xsl:variable name="node_name" select="local-name()"/>
        <xsl:variable name="element_1" select="$node_name"/>
        <xsl:copy>
            <xsl:choose>
                <xsl:when test="$parent_copy_mode = 'missing'">
                    <!-- TODO Vérifier que le sous-élément appartient à l'élément. -->
                    <!-- TODO On ne peut pas filtrer sur substring-before et substring-after dans /e. Pourtant "substring-after('physdesc/dimensions', '/')", sans le ".", fonctionne.
                        and normalize-space(substring-after(., '/')) = $ead_tags/e[@name = 'did']/e/e/@name
                        and normalize-space(substring-after('physdesc/dimensions', '/')) = $ead_tags/e[@name = 'did']/e/e/@name
                    -->
                    <xsl:for-each select="$parent_set/e[@name = $node_name]/e">
                        <xsl:variable name="element_2" select="@name"/>
                        <xsl:if test="
                            $ead_tags
                            /e[@name = $element_1]
                            /e[@name = $element_2]
                            and
                            not(
                                $node
                                [local-name() = $element_1]
                                /*[local-name() = $element_2]
                            )
                            ">
                            <xsl:apply-templates select="
                                $node
                                /ancestor::*
                                    [
                                        did
                                        /*[local-name() = $element_1]
                                        /*[local-name() = $element_2]
                                    ]
                                    [1]
                                /did
                                /*[local-name() = $element_1]
                                /*[local-name() = $element_2]
                                " mode="ancestor"/>
                        </xsl:if>
                    </xsl:for-each>
                </xsl:when>
            </xsl:choose>
            <xsl:apply-templates select="@*|node()"/>
        </xsl:copy>
    </xsl:template>

    <!-- Suppression des sous-composants. -->
    <xsl:template match="archdesc/dsc | c/c | c01/c02 | c02/c03 | c03/c04 | c04/c05 | c05/c06 | c06/c07 | c07/c08 | c08/c09 | c09/c10 | c10/c11 | c11/c12">
    </xsl:template>

    <!-- Ajout d’attributs aux éléments copiés d’un niveau supérieur. -->
    <xsl:template match="*" mode="ancestor">
        <xsl:copy>
            <!--
            <xsl:attribute name="_depth">
                <xsl:call-template name="depth_sub"/>
            </xsl:attribute>
            -->
            <xsl:attribute name="_uid">
                <xsl:call-template name="id_sub"/>
            </xsl:attribute>
            <xsl:apply-templates select="@*|node()"/>
        </xsl:copy>
    </xsl:template>

    <!-- Identity template -->
    <xsl:template match="@*|node()">
        <xsl:copy>
            <xsl:apply-templates select="@*|node()"/>
        </xsl:copy>
    </xsl:template>

    <!-- Templates spécifiques -->

    <!-- Identifiant d’un composant. -->
    <xsl:template name="id">
        <xsl:choose>
            <xsl:when test="@id and @id != ''">
                <xsl:value-of select="@id"/>
            </xsl:when>
            <xsl:when test="did/unitid/@identifier and did/unitid/@identifier != ''">
                <xsl:value-of select="did/unitid/@identifier"/>
            </xsl:when>
            <xsl:otherwise>
                <xsl:text></xsl:text>
            </xsl:otherwise>
        </xsl:choose>
    </xsl:template>

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

    <!-- Identifiant d’un composant à partir d’un sous-élément. -->
    <xsl:template name="id_sub">
        <xsl:for-each select="ancestor::*[local-name() = 'archdesc' or local-name() = 'c'  or local-name() = 'c01'  or local-name() = 'c02'  or local-name() = 'c03'  or local-name() = 'c04'  or local-name() = 'c05'  or local-name() = 'c06'  or local-name() = 'c07'  or local-name() = 'c08'  or local-name() = 'c09'  or local-name() = 'c10'  or local-name() = 'c11'  or local-name() = 'c12' ][1]">
            <xsl:call-template name="id"/>
        </xsl:for-each>
    </xsl:template>

    <!-- Profondeur d’un composant à partir d’un sous-élément. -->
    <xsl:template name="depth_sub">
        <xsl:for-each select="ancestor::*[local-name() = 'archdesc' or local-name() = 'c'  or local-name() = 'c01'  or local-name() = 'c02'  or local-name() = 'c03'  or local-name() = 'c04'  or local-name() = 'c05'  or local-name() = 'c06'  or local-name() = 'c07'  or local-name() = 'c08'  or local-name() = 'c09'  or local-name() = 'c10'  or local-name() = 'c11'  or local-name() = 'c12' ][1]">
            <xsl:call-template name="depth"/>
        </xsl:for-each>
    </xsl:template>

    <!-- Préparation de la liste des éléments et sous-éléments à partir du schéma ead. -->
    <xsl:template name="parent_set_ead">
        <xsl:for-each select="$ead_tags/e[@name = 'did']/e">
            <!-- Copie du premier niveau seul et du second niveau. -->
            <e name="{@name}"/>
            <e name="{@name}">
                <xsl:variable name="node" select="@name"/>
                <xsl:copy-of select="$ead_tags/e[@name = $node]/e"/>
            </e>
        </xsl:for-each>
    </xsl:template>

    <!-- Préparation de la liste des éléments et sous-éléments. -->
    <xsl:template name="parent_set_list">
        <xsl:for-each select="exsl:node-set($parent_copy_list)/e">
            <xsl:apply-templates select="text()" mode="split_to_elements"/>
        </xsl:for-each>
    </xsl:template>

    <!-- TODO Vérifier la suite des éléments sur deux niveaux. -->
    <xsl:template match="text()" name="split_to_elements" mode="split_to_elements">
        <xsl:param name="text" select="normalize-space(.)"/>
        <xsl:choose>
            <xsl:when test="string-length($text) = 0">
                <!-- Rien. -->
            </xsl:when>
            <xsl:when test="not(contains($text, '/'))">
                <xsl:if test="$ead_tags/e[@name = $text]">
                    <e name="{$text}"/>
                </xsl:if>
            </xsl:when>
            <xsl:otherwise>
                <xsl:variable name="first_part" select="normalize-space(substring-before(concat($text, '/'), '/'))"/>
                <xsl:if test="$ead_tags/e[@name = $first_part]">
                    <e name="{$first_part}">
                        <xsl:call-template name="split_to_elements">
                            <xsl:with-param name="text" select="normalize-space(substring-after($text, '/'))"/>
                        </xsl:call-template>
                    </e>
                </xsl:if>
            </xsl:otherwise>
        </xsl:choose>
    </xsl:template>

</xsl:stylesheet>
