Bulk Import (module pour Omeka S)
=================================

> __Les nouvelles versions de ce modules et l’assistance pour Omeka S version 3.0
> et supérieur sont disponibles sur [GitLab], qui semble mieux respecter les
> utilisateurs et la vie privée que le précédent entrepôt.__

See [English readme].

[Bulk Import] est un module pour [Omeka S] qui permet d’importer tout type de source
et il est construit pour être extensible. Il permet de gérer des importeurs et
de traiter l’importation de ressources en lot.

De plus, il ajoute un moyen de télécharger manuellement des fichiers en masse
sans limite de [taille ou nombre de fichiers].

Pour l’importation en masse, le module gère les lecteurs d’une source (xml, sql,
tableur, url…) et utilise des processeurs pour les importer en tant que
ressources Omeka et autres données (utilisateurs, modèles…) via des alignements.

Comme plusieurs importeurs peuvent être préparés avec les mêmes lecteurs et
processeurs, il est possible d’importer plusieurs fois le même type de fichiers
sans avoir à aligner les données chaque fois.

Les lecteurs par défaut sont le lecteur Omeka S (via l’api json endpoint), xml
(via transformation avec xslt), sql (pour s’adapter à chaque base de données, un
exemple pour [e-prints] est fourni), [Spip] (via la base de données), et tableur
(via ods, tsv ou csv). Le tableur utilise un processeur qui crée des ressources
sur la base d’un format d’en-tête spécifique, mais sans l’interface manuelle
comme le module module [CSV Import].


Installation
------------

Ce module nécessite le module [Log] et optionnellement [Generic]. Un processeur
xslt 2 externe peut être nécessaire si vous importez des fichiers xml qui ne
sont pas importables avec xlt 1. Certains lecteurs ou processeurs spécifiques
peuvent nécessiter d’autres modules.

À partir de la version 3.4.39, le module requiert php 7.4.

**Avertissement** : Certaines parties de ce module ne supportent pas les
fichiers distants : seuls ceux enregistrés localement sur le serveur peuvent
être gérés.

**Important** : Si vous utilisez le module [CSV Import] en parallèle, vous devez
utiliser une version égale ou supérieure à 2.3.0.

**Important** : Si vous utilisez le module [Numeric Data Types], vous devez
appliquer ce [correctif] ou utiliser cette [version].

Voir la documentation générale de l’utilisateur final pour [installer un module].

* A partir du zip

Téléchargez la dernière version [BulkImport.zip] depuis la liste des livraisons
et décompressez-la dans le répertoire `modules`.

* Depuis les sources et pour le développement

Si le module a été installé à partir de la source, renommez le nom du répertoire
de module en `BulkImport`, allez à la racine du module, et exécutez :

```sh
composer install --no-dev
```

Remarque : la bibliothèque "CodeMirror" n’a pas de fichier "codemirror.js" par
défaut : il est créé automatiquement lors de l’installation des paquets avec npm.
Pour l’utiliser via composer, le fichier manquant est ajouté dans le paquet
utilisé par composer.

Ensuite, installez-le comme n’importe quel autre module Omeka.

* Extensions de fichiers

Pour des raisons de sécurité, le module vérifie l’extension de chaque fichier
ingéré. Ainsi, si vous importez des fichiers spécifiques, en particulier des
fichiers de métadonnées XML et des fichiers json, ils devraient être autorisés
dans la page `/admin/setting`.

* Processeur XSLT

Le processeur xslt est nécessaire seulement pour l’import de fichiers xml qui ne
sont pas formatés en tant que ressources plates.

Xslt a deux versions principales : xslt 1.0 et xslt 2.0. La première est souvent
installée avec php via l’extension `php-xsl` ou le paquet `php5-xsl`, en
fonction de votre votre système. Il est jusqu’à dix fois plus lent que xslt 2.0
et les feuilles sont plus complexes à écrire.

Il est donc recommandé d’installer un processeur xslt 2, qui peut traiter xslt 1.0
et les xslt 2.0 et en général également xsl 3.0.

Pour installer xslt 2 sur Debian / Ubuntu :
```sh
sudo apt install --reinstall default-jre libsaxonhe-java
```

Ensuite, la commande peut être indiquée dans la page de configuration du module.
Utilisez "%1$s", "%2$s", "%3$s", sans échappement, pour le fichier d’entrée, la
feuille de style, et la sortie.

Exemples pour Debian 6+ / Ubuntu / Mint (avec le paquet "libsaxonb-java") :
```sh
saxonb-xslt -ext:on -versionmsg:off -warnings:silent -s:%1$s -xsl:%2$s -o:%3$s
```

Exemples pour Debian 8+ / Ubuntu / Mint (avec le paquet "libsaxonhe-java") :
```sh
CLASSPATH=/usr/share/java/Saxon-HE.jar java net.sf.saxon.Transform -ext:on -versionmsg:off -warnings:silent -s:%1$s -xsl:%2$s -o:%3$s
```

Exemple pour Fedora / RedHat / Centos / Mandriva / Mageia (paquet "saxon") :
```sh
java -cp /usr/share/java/saxon.jar net.sf.saxon.Transform -ext:on -versionmsg:off -warnings:silent -s:%1$s -xsl:%2$s -o:%3$s
```

Ou avec les paquets "saxon", "saxon-scripts", "xml-commons-apis" et "xerces-j2" :
```sh
saxon -ext:on -versionmsg:off -warnings:silent -s:%1$s -xsl:%2$s -o:%3$s
```

Note : Seul saxon est actuellement supporté comme processeur xslt 2. Comme Saxon
est un outil Java, un JRE doit être installé, par exemple `openjdk-11-jre-headless`
ou supérieur (ou utilisez `default-jre`).

Note : Les avertissements sont traités comme des erreurs. C’est la raison pour
laquelle le paramètre "-warnings:silent" est important pour pouvoir traiter un
import avec une mauvaise feuille xsl. Il peut être supprimée avec la feuille xsl
par défaut, qui n’avertit pas.

De toute façon, s’il n’y a pas de processeur xslt2 installé, le champ de commande
doit être effacé. Le module utilisera le processeur xslt 1 par défaut de php,
s’il est disponible.


Usage
-----

### Démarrage rapide

Cliquez sur le menu Import en lot > Tableau de bord, puis cliquez sur un importeur,
puis remplissez les deux formulaires, et enfin confirmez l’import.

Pour ajouter, supprimer ou configurer des importateurs, allez dans le menu Bulk Import > Configuration.
Un importeur correspond à un lecteur et un processeur.

Certains lecteurs peuvent utiliser un alignement, en particulier json et xml.
Cela permet d’aligner les données entre la source et la représentation omeka
json des ressources.

La configuration peut être sélectionnée dans le premier formulaire. Une nouvelle
config peut être ajoutée dans le dossier "data/mapping" du module (par défaut)
ou dans le dossier "files/mapping" d’Omeka (obsolète : utiliser de préférence
les fichiers de configuration). Ils peuvent être être édités en ligne dans le
menu « Alignements » également.

### Fichiers de configuration

Le fichier de configuration contient quatre sections.

La première section est `info`, qui définit le nom et le libellé de la configuration.
Une clé importante est `mapper` (aligneur), qui correspond à la base de la
configuration en cours. L’alignement générique est disponible dans le répertoire
`mapper` du module.

La deuxième section est `params`, qui permet de définir certains paramètres, par
exemple pour identifier la racine des ressources, ou les champs de chaque
ressource. Voir l’exemple qui a été utilisé pour migrer une bibliothèque numérique
depuis Content-DM. Pour ce dernier, l’url doit être complète, comme https://cdm21057.contentdm.oclc.org/digital/api/search/collection/coll3/maxRecords/100.

La troisième section est `default` et définit les métadonnées par défaut des
ressources, comme la classe de la ressource ou le modèle.

La quatrième section est `mapping` et contient tous les champs de la source à
importer et tous les détails sur le champ de destination (propriété et autres
métadonnées).

### Configuration des alignements

La config contient une liste d’alignements entre les données source et les
données de destination. L’alignement peut être réalisé dans deux formats : paire
clé-valeur ou xml.

Par exemple :

```
/record/datafield[@tag='200'][@ind1='1']/subfield[@code='a'] = dcterms:title ^^literal @fra §private ~ modèle pour {{ value|trim }} avec {{/source/record/data}}
```

En xml, le même alignement est le suivant :

```xml
<mapping>
    <map>
        <from xpath="/record/datafield[@tag='200'][@ind1='1']/subfield[@code='a']"/>
        <to field="dcterms:title" datatype="literal" language="fra" visibility="private"/>
        <mod raw="" prepend="modèle pour " pattern="{{ value|trim }} avec {{/source/record/data}}" append=""/>
    </map>
</mapping>
```

Le format xml est plus clair, mais la paire clé-valeur peut être utilisée partout,
y compris les en-têtes d’un tableur.

Bien sûr, une configuration peut être composée de plusieurs alignements.

#### Notation des paires clé-valeur

Chaque ligne est formatée avec une source et une destination séparées par le signe
"=". Le format de chaque partie (à gauche et à droite du `=`) de chaque ligne est
vérifié en amont et ignorée si incorrecte.

La partie source peut être formatée de trois façons : notation javascript point,
JsonPath, JMESPath, ou XPath (voir ci-dessous).

La partie destination comporte un à cinq composants et seul le premier est
obligatoire.

Le premier doit être le champ de destination, c’est-à-dire l’une des clés
utilisées dans la représentation json d’une ressource, généralement une propriété,
mais aussi d’autres métadonnées (`o:resource_template`, etc.). Il peut également
s’agir d’un sous-champ, notamment pour spécifier des ressources connexes lors de
l’importation d’un élément : `o:media[o:original_url]`, `o:media[o:ingester]`,
ou `o:item_set[dcterms:identifier]`.

Les trois composants suivants sont spécifiques aux propriétés et peuvent
apparaître dans n’importe quel ordre. Ils sont préfixés par un code, similaire à
certaines représentations rdf :
- le type de données est préfixé par un `^^` : `^^resource:item` ou `^^customvocab:5` ;
- la langue est préfixée par un `@` : `@fra` ou `@fr-FR` ;
- la visibilité est préfixée par un `§` : `§private` ou `§public`.

Le dernier composant est un modèle utilisé pour transformer la valeur source si
nécessaire. Il est préfixé par un `~`. Ce peut être une simple chaîne de
remplacement, ou un motif complexe avec certaines commandes [Twig] (voir ci-dessous).

Pour les valeurs par défaut, la partie droite peut être une chaîne simple
commençant et finissant par des guillemets simples ou doubles, dans ce cas la
partie gauche est la destination. Les quatre lignes suivantes sont équivalentes :

```
dcterms:license = dcterms:license ^^literal ~ "Domaine public"
~ = dcterms:license ^^literal ~ "Domaine public"
dcterms:license = ^^literal ~ "Domaine public"
dcterms:license = "Domaine public"
```

#### Alignement via xml

L’exemple ci-dessus est clair et contient les principaux éléments et attributs.
Voir l’[exemple d’alignement pour Unimarc].

Le format Xml permet aussi d’indiquer des tables de correspondance pour les codes,
par exemple pour convertir un code iso en un littéral.

### Définition de la source de la valeur

Quatre formats sont supportés, les trois premiers pour une source en json, et le
dernier  pour une source xml :

- Notation javascript point : La source est définie à peu près comme un objet
  javascript avec la notation par point (même invalide) : `dcterms:title.0.value`.
  `.` et `\` doivent être doivent être échappés avec un `\`.

- [JsonPath] : C’est un portage de XPath pour json : `$.['dcterms:title'][0].['@value']`,
  et il peut gérer beaucoup d’expressions, de filtres, de fonctions, etc.

- [JMESPath] : C’est un autre portage de XPath pour json : `"dcterms:title"[0] "@value"`,
  et il peut gérer beaucoup d’expressions, de filtres, de fonctions, etc.
  JMESPath est similaire à l’idée originale de [JsonPath], mais ils ne sont pas
  compatibles entre eux.

- XPath : le format XPath standard : `/record/datafield[@tag='200'][@ind1='1']/subfield[@code='a']`
  lorsque la source est en xml.

### Définir la transformation (valeur brute, préfixes/suffixes, remplacements)

Si vous avez besoin de modifier la valeur source et de la formater différemment,
vous pouvez utiliser certains motifs.

Une chaîne de remplacement simple est un motif avec quelques valeurs de remplacement :
```
geonameId = dcterms:identifier ^^uri ~ https://www.geonames.org/{{ value }}
```

Les motifs de remplacement disponibles sont :
- la valeur de la source actuelle `{{ value }}`;
- toute requête source `{{xxx}}`, par exemple un xpath `{/doc/str[@name="ppn_z"]}}`.

`{{ value }}` et `{{value}}` ne sont pas identiques : le premier est la valeur
courante extraite de la partie source et la seconde est la clé utilisée pour
extraire la valeur avec la clé `value` d’un tableau source.

Pour une transformation complexe, le motif peut être construit comme un Twig
simplifié : c’est une chaîne où les valeurs entre `{{ ` et ` }}` sont converties
avec quelques filtres de base. Par exemple, `{{ value|trim }}` prend la valeur
de la source et supprime les espaces. L’espace après `{{` et avant `}}` est
nécessaire. Seuls certains filtres Twig courants sont pris en charge : `abs`,
`capitalize`, `date`, `e`, `escape`, `first`, `format`, `last`, `length`, `lower`,
`replace`, `slice`, `split`, `striptags`, `table`, `title`, `trim`, `upper`,
`url_encode`. Seuls certains arguments communs de ces filtres sont supportés.
Les filtres Twig peuvent être combinés, mais pas imbriqués.

Un exemple d’utilisation des filtres est la renormalisation d’une date, ici à
partir d’une source xml unimarc `17890804` en une date standard [ISO 8601] `1789-08-04` :
```
/record/datafield[@tag="103"]/subfield[@code="b"] = dcterms:valid ^^numeric:timestamp ~ {{ value|trim|slice(1,4) }}-{ value|trim|slice(5,2) }}-{ value|trim|slice(7,2) }}
```

Notez que dans le cas d’une telle date, une fonction plus simple peut être
utilisée. En effet, quelques fonctions spécifiques ont été ajoutées : `table`,
pour remplacer un code par une chaîne de caractères, `dateIso`, `dateSql`,
`isbdName`, `isbdNameColl`, `isbdMark`, `unimarcIndex`, `unimarcCoordinates`,
`unimarcCoordinatesHexa`, `unimarcTimeHexa`.

Pour voir un exemple complet, regardez le fichier qui gère la [conversion d’Unimarc vers Omeka].
Notez que ce fichier sera bientôt amélioré pour gérer les annotations de valeur,
une amélioration importante pour Omeka 3.2.

Les filtres Twig peuvent être utilisés en conjonction avec de simples remplacements.
Dans ce cas, ils sont traités après les remplacements.

La source peut également être `~`, dans ce cas la destination est un mélange de
plusieurs sources :
```
~ = dcterms:spatial ~ Coordonnées : {{lat}}/{lng}}
```

Ici, `{lat}}` et `{lng}}` sont des valeurs extraites de la source.

Le préfixe `~` ne doit pas être utilisé dans les autres composants ou dans la
partie gauche pour le moment.

Bien sûr, dans de nombreux cas, il est plus simple de corriger les valeurs dans
la source ou plus tard dans les ressources avec l’édition par lot ou avec le
module [Bulk Edit].


E-prints
--------

[e-prints] est un outil pour construire un dépôt institutionnel (articles de
recherche, travaux d’étudiants, ressources pédagogiques, etc.) C’est l’une des
plus anciennes bibliothèques numériques bibliothèques numériques libres gérant
le protocole OAI-PMH. Comme Omeka S peut aussi être un [dépôt OAI-PMH], il est
possible de migrer de l’un à l’autre. Le process utilise cependant la base sql
afin de récupérer toutes les données annexes.

Il suffit de sélectionner le lecteur sql et le processeur eprints, puis de
suivre les instructions.


Omeka S
-------

Il suffit de définir le point de terminaison et éventuellement les informations
d’identification et de l’exécuter. Toutes les données peuvent être récupérées :
vocabulaires, modèles de ressources, fichiers et bien sûr contenus, collections
et médias. Les vocabulaires personnalisés sont également importés. Il est
recommandé d’avoir les mêmes modules installés, en particulier ceux qui ajoutent
de nouveaux types de données (Suggestion de valeur, Numeric Data Types, Rdf Data Types,
Data Type Geometry). Les métadonnées spécifiques des autres modules ne sont
actuellement pas gérées.


Spip
----

Il suffit de définir les informations d’identification de la base de données et
l’url et de continuer. Vous devez installer d’autres modules : [Advanced Resource Template],
[Custom Vocab], [Data Type Rdf], [Numeric Data Types], [Spip ], [Thesaurus],
[User Profile].


Tableur
-------

Pour importer un tableur, choisissez son format et le séparateur multivaleurs
s’il y en a un. Ensuite, effectuez l’alignement. L’alignement est automatique
lorsque l’en-tête est constitué de libélés ou de noms de propriétés ou des noms
de métadonnées Omeka ou des mots-clés existants.

Lors de l’import d’un fichier ODS avec des formules, essayez de définir `string`
ou `general` comme format par défaut des cellules afin d’éviter une erreur de
saisie ou lorsqu’une formule retourne une chaîne de caractères, mais que le
format requiert autre chose, par exemple un `float`, pour lequel une chaîne vide
sera renvoyée sous la forme `0` lors de l’import, même si elle ne s’affiche pas
dans certaines tableurs.

Plus largement, il n’est pas recommandé d’utiliser des formules et des cellules
formatées, puisqu’il n’est pas possible de s’assurer de ce que sont les données
réelles (les vraies ou les celles qui sont affichées ?). Même si c’est bien géré
dans la plupart des cas, ceci est particulièrement important pour les dates, car
elles sont souvent différentes entre les éditeurs et même entre les versions des
tableurs. Certaines versions d’Excel sur Apple n’affichent pas les mêmes dates
que les versions équivalentes pour Windows. Vous pouvez sélectionner toutes les
cellules (ctrl + A), puis les copier (ctrl + C), puis les coller via le collage
spécial (ctrl + shift + V), puis choisir "Valeurs uniquement", et l’enregistrer
dans un autre fichier (ou faire un copier/coller dans une nouvelle feuille),
sinon vous perdrez les formules précédentes, les styles et les formats.

Contrairement à CSV Import, il n’y actuellement pas d’alignement manuel via
l’interface pour les propriétés, mais il gère les noms d’en-têtes avancés pour
indiquer les types de données, les langues et la visibilité automatiquement. Il
est donc recommandé de les utiliser, par exemple `dcterms:title ^^resource:item @fra §private`.
Le format est le même que celui décrit précédemment pour la destination.

En outre, il existe un mappage automatique configurable dans [data/mappings/fields_to_metadata.php],
et les libellés et noms de propriété standard sont déjà gérés.

Ainsi, l’en-tête de chaque colonne peut avoir un type de données (avec `^^datatype`),
une langue (avec `@language`), et une visibilité (avec `§private`). En outre, un
motif (préfixé par `~`) peut être ajouté pour transformer la valeur.

Par exemple, pour importer un titre français, utilisez l’en-tête `Titre @fra` ou `dcterms:title @fr`.

Pour importer une relation en tant qu’uri, utilisez l’en-tête `Relation ^^uri` ou `dcterms:relation ^^uri`.
Pour importer une uri avec un libellé, l’en-tête est le même, mais la valeur
doit être l’uri, un espace et le libellé.
Pour importer une valeur comme une ressource Omeka, utilisez l’en-tête `dcterms:relation ^^resource`.
La valeur devrait être l’id interne (recommandé) ou un identifiant de ressource
(généralement dcterms:identifier), mais il doit être unique dans toute la base
de données.
Pour importer une valeur de vocabulaire personnalisé, l’en-tête doit contenir `^^customvocab:xxx`,
où "xxx" est l’identifiant du vocabulaire ou son étiquette entouré de `"` ou `'`.

Les types de données gérées par défaut sont ceux d’Omeka :
- `literal` (par défaut)
- `uri`
- `resource`
- `resource:item`
- `resource:itemset`
- `resource:media`

Les types de données des autres modules sont également supportés (Custom Vocab,
Value Suggest, DataTypeRdf, Numeric Data Types) si les modules sont présents :
- `numeric:timestamp`
- `numeric:integer`
- `numeric:duration`
- `geometry:geography`
- `geometry:geometry`
- `customvocab:xxx`
- `valuesuggest:yy`
- `html`
- `xml`
- `boolean`

Les préfixes peuvent être omis lorsqu’ils sont simples : `item`, `itemset`, `media`,
`timestamp`, `integer`, `duration`, `geography`, `geometry`.

Plusieurs types de données peuvent être définis pour une colonne : `dcterms:relation ^^customvocab:15 ^^resource:item ^^resource ^^literal`.
Le type de données est vérifié pour chaque valeur et s’il n’est pas valide, le
type de données suivant est essayé. C’est utile lorsque certaines données sont
normalisées et d’autres non, par exemple avec une liste de dates ou de sujets :
`dcterms:date ^^numeric:timestamp ^^literal` ou `dcterms:subject ^^valuesuggest:idref:rameau ^^literal`.

Pour importer plusieurs destinations pour une colonne, utilisez le séparateur `|`
dans l’en-tête. Notez que s’il peut y avoir plusieurs propriétés, seuls la
première langue et le premier type seront utilisés pour l’instant : `dcterms:creator ^^resource:item ^^literal | foaf:name`.

La visibilité de chaque donnée peut être "publique" (par défaut) ou "privée",
préfixée par `§`.

Les médias peuvent être importés avec l’article. L’alignement est automatique
avec les en-têtes `Media url`, `Media html`, etc.

Pour plus de détails sur les valeurs possibles, voir la [config ci-dessus].

### Différences internes avec CSV Import

- Deux colonnes avec les mêmes en-têtes doivent être alignées de la même façon.
- Les valeurs vides pour les métadonnées booléennes (`is_public`…) dans le
  lecteur de feuille de calcul sont ignorées et elles ne signifient pas "faux"
  ou "vrai".
- En cas de doublon insensible à la casse, le premier est toujours retourné.


TODO
----

- [ ] Voir todo dans le code.
- [ ] Ajouter plus de tests.
- [x] Essai à blanc complet.
- [ ] Extraire la liste des noms de métadonnées pendant l’essai à blanc et la sortir pour aider à la construction de l’alignement.
- [ ] Correction du type de données numériques (problème de doctrine) : voir la correction dans https://github.com/omeka-s-modules/NumericDataTypes/pull/29.
- [ ] Distinction entre "ignoré" et "blanc" pour le tableur.
- [ ] Mise à jour pour le module Mapping.
- [ ] Importation des utilisateurs, en particulier pour l’importation Omeka S.
- [x] Importation d’uri avec label dans le tableur.
- [ ] Importation d’uri avec libellé pour Value Suggest.
- [ ] Sauter l’import des vocabulaires et des modèles de ressources pour l’import Omeka S.
- [ ] Permettre de définir une requête pour l’import Omeka S.
- [ ] Ajouter des vérificains, en particulier avec les tableurs multi-feuilles.
- [ ] Gérer l’import de vocabulaires personnalisés avec des éléments.
- [-] Pourquoi y a-t-il 752 ids manquants avec la création directe sql dans Spip ?
- [-] Spip : Utiliser la langue de la rubrique supérieure si pas de langue.
- [ ] Utiliser metaMapper() pour les imports sql (donc convertir les processeurs spéciaux) ou convertir les lignes plus tôt (comme les tableurs).
- [x] Pour les imports sql, utiliser une requête sql directe lorsque l’alignement est de table à table (comme les statistiques eprints).
- [ ] Convertir l’importeur spécifique en processeur de ressources standard + modèle.
- [ ] Dépréciation de tous les convertisseurs directs qui n’utilisent pas metaMapper() (donc mise à jour du processus de feuille de calcul).
- [ ] Le nombre de lignes sautées ou vides est différent pendant la vérification et le processus réel.
- [ ] Vérifier le jeu d’éléments, le modèle et la classe par défaut (ils peuvent ne pas être définis lors de la création, de la mise à jour ou du remplacement via le tableur).
- [ ] Vérifier une ressource avec `o:item_set[dcterms:title]`.
- [ ] Ajout d’une action "error" pour les ressources non identifiées.
- [ ] Séparer l’option "autoriser doublons" en "autoriser manquant" et "autoriser doublons" ?
- [x] Ajouter l’importation de fichiers xml multiples comme json.
- [ ] Afficher les détails des alignements : ajouter la liste des configurations utilisées comme importeur et comme parent/enfant.
- [ ] Ajouter la détermination automatique de la source (csv, fichier, iiif, iiif multiple, omeka classic, etc.).
- [ ] Remplacer jsdot interne par RoNoLo/json-query ou binary-cube/dot-array ou jasny/dotkey ? Probablement inutile.
- [ ] Compiler jmespath.
- [ ] Import/màj des annotations de valeur.
- [ ] Supprimer les importés quand la ressource est supprimée.
- [ ] Utiliser un index commençant à un dans Entry.
- [ ] Déplace l’option "convertir en html" dans un autre module.
- [ ] Normaliser la config pour extraire les métadonnées avec metamapper.
- [ ] Ajouter un alignement automatique pour les images etc. avec xmp.


Avertissement
-------------

À utiliser à vos propres risques.

Il est toujours recommandé de sauvegarder vos fichiers et vos bases de données
et de vérifier vos archives régulièrement afin de pouvoir les reconstituer si
nécessaire.


Dépannage
---------

Voir les problèmes en ligne sur la page des [questions du module] du GitLab.


Licence
-------

### Module

Ce module est publié sous la licence [CeCILL v2.1], compatible avec [GNU/GPL] et
approuvée par la [FSF] et l’[OSI].

Ce logiciel est régi par la licence CeCILL de droit français et respecte les
règles de distribution des logiciels libres. Vous pouvez utiliser, modifier
et/ou redistribuer le logiciel selon les termes de la licence CeCILL telle que
diffusée par le CEA, le CNRS et l’INRIA à l’URL suivante "http://www.cecill.info".

En contrepartie de l’accès au code source et des droits de copie, de
modification et de redistribution accordée par la licence, les utilisateurs ne
bénéficient que d’une garantie limitée et l’auteur du logiciel, le détenteur des
droits patrimoniaux, et les concédants successifs n’ont qu’une responsabilité
limitée.

À cet égard, l’attention de l’utilisateur est attirée sur les risques liés au
chargement, à l’utilisation, à la modification et/ou au développement ou à la
reproduction du logiciel par l’utilisateur compte tenu de son statut spécifique
de logiciel libre, qui peut signifier qu’il est compliqué à manipuler, et qui
signifie donc aussi qu’il est réservé aux développeurs et aux professionnels
expérimentés ayant des connaissances informatiques approfondies. Les
utilisateurs sont donc encouragés à charger et à tester l’adéquation du logiciel
à leurs besoins dans des conditions permettant d’assurer la sécurité de leurs
systèmes et/ou de leurs données et, plus généralement, à l’utiliser et à
l’exploiter dans les mêmes conditions en matière de sécurité.

Le fait que vous lisez actuellement ce document signifie que vous avez pris
connaissance de la licence CeCILL et que vous en acceptez les termes.


### Bibliothèques

- Flow.js / Flow php server

  Licence [MIT]

- CodeMirror

  Licence [MIT]

Voir les licences des autres bibliothèques dans composer.json.

### Data

- Table des pays et des langues :
  - https://www.iso.org/obp/ui/
  - https://download.geonames.org/export/dump/countryInfo.txt


Copyright
---------

* Copyright BibLibre, 2016-2017
* Copyright Roy Rosenzweig Center for History and New Media, 2015-2018
* Copyright Daniel Berthereau, 2017-2022 (voir [Daniel-KM] sur GitLab)
* Copyright (c) 2001-2019, Arnaud Martin, Antoine Pitrou, Philippe Rivière, Emmanuel Saint-James (code de Spip)
* Copyright 2011-2022, Steffen Fagerström Christensen & alii (bibliothèques [Flow.js] et [flow-php-server])
* Copyright 2011-2022, Marijn Haverbeke & alii (Bibliothèque [CodeMirror])

Ce module s’est initialement inspiré du plugin [Omeka Classic] [Import plugin],
conçu par [BibLibre] et a été développé pour divers projets, notamment la future
bibliothèque numérique [Manioc] de l’Université des Antilles et de l’Université
de la Guyane, actuellement gérée avec [Greenstone]. D’autres fonctionnalités ont
été conçues pour la future bibliothèque numérique [Le Menestrel] ainsi que pour
l’entrepôt institutionnel des travaux étudiants [Dante] de l’[Université de Toulouse Jean-Jaurès].


[Bulk Import]: https://gitlab.com/Daniel-KM/Omeka-S-module-BulkImport
[English readme]: https://gitlab.com/Daniel-KM/Omeka-S-module-BulkImport/-/blob/master/README.md
[Omeka S]: https://omeka.org/s
[CSV Import]: https://omeka.org/s/modules/CSVImport
[Omeka Classic]: https://omeka.org/classic
[Import plugin]: https://github.com/BibLibre/Omeka-plugin-Import
[taille ou nombre de fichierss]: https://github.com/omeka/omeka-s/issues/1785
[Generic]: https://gitlab.com/Daniel-KM/Omeka-S-module-Generic
[Log]: https://gitlab.com/Daniel-KM/Omeka-S-module-Log
[BulkImport.zip]: https://gitlab.com/Daniel-KM/Omeka-S-module-BulkImport/-/releases
[installer un module]: https://omeka.org/s/docs/user-manual/modules/#installing-modules
[CSV Import]: https://github.com/omeka-s-modules/CSVImport
[correctif]: https://github.com/omeka-s-modules/NumericDataTypes/pull/29
[version]: https://github.com/Daniel-KM/Omeka-S-module-NumericDataTypes
[exemple d’alignement pour Unimarc]: https://gitlab.com/Daniel-KM/Omeka-S-module-BulkImport/-/blob/master/data/mapping/xml/unimarc_to_omeka.xml
[ISO 8601]: https://www.iso.org/iso-8601-date-and-time-format.html
[JsonPath]: https://goessner.net/articles/JsonPath/index.html
[JMESPath]: https://jmespath.org
[conversion d’Unimarc vers Omeka]: https://gitlab.com/Daniel-KM/Omeka-S-module-BulkImport/-/blob/master/data/mapping/xml/unimarc_to_omeka.xml
[Twig]: https://twig.symfony.com/doc/3.x
[config above]: https://gitlab.com/Daniel-KM/Omeka-S-module-BulkImport#config-of-the-mappings
[Bulk Edit]: https://gitlab.com/Daniel-KM/Omeka-S-module-BulkEdit
[e-prints]: https://eprints.org/
[OAI-PMH repository]: https://gitlab.com/Daniel-KM/Omeka-S-module-OaiPmhRepository
[Spip]: https://spip.net
[data/mappings/fields_to_metadata.php]: https://gitlab.com/Daniel-KM/Omeka-S-module-BulkImport/-/blob/master/data/mappings/fields_to_metadata.php
[Advanced Resource Template]: https://gitlab.com/Daniel-KM/Omeka-S-module-AdvancedResourceTemplate
[Custom Vocab]: https://github.com/Omeka-S-modules/CustomVocab
[Data Type Rdf]: https://gitlab.com/Daniel-KM/Omeka-S-module-DataTypeRdf
[Numeric Data Types]: https://github.com/Omeka-S-modules/NumericDataTypes
[Spip ]: https://gitlab.com/Daniel-KM/Omeka-S-module-Spip
[Thesaurus]: https://gitlab.com/Daniel-KM/Omeka-S-module-Thesaurus
[User Profile]: https://gitlab.com/Daniel-KM/Omeka-S-module-UserProfile
[questions du module]: https://gitlab.com/Daniel-KM/Omeka-S-module-BulkImport/-/issues
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[MIT]: https://github.com/sandywalker/webui-popover/blob/master/LICENSE.txt
[Flow.js]: https://flowjs.github.io/ng-flow
[flow-php-server]: https://github.com/flowjs/flow-php-server
[CodeMirror]: https://codemirror.net
[BibLibre]: https://github.com/BibLibre
[Manioc]: http://www.manioc.org
[Greenstone]: http://www.greenstone.org
[Le Menestrel]: http://www.menestrel.fr
[Dante]: https://dante.univ-tlse2.fr
[Université de Toulouse Jean-Jaurès]: https://www.univ-tlse2.fr
[GitLab]: https://gitlab.com/Daniel-KM
[Daniel-KM]: https://gitlab.com/Daniel-KM "Daniel Berthereau"
