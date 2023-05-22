Bulk Import (module for Omeka S)
================================

> __New versions of this module and support for Omeka S version 3.0 and above
> are available on [GitLab], which seems to respect users and privacy better
> than the previous repository.__

Voir le [Lisez-moi] en français.

[Bulk Import] is a module for [Omeka S] that allows to import any type of source
and is built to be extensible. It allows to manage importers and to process bulk
import of resources.

Furthermore, it adds a way to bulk upload files manually without limit of [size or number of files].

For bulk import, the module manages readers of a source (xml, sql, spreadsheet,
url…) and uses processors to import them as Omeka resources and other data
(users, templates…) via a mapping.

Because multiple importers can be prepared with the same readers and processors,
it is possible to import multiple times the same type of files without needing
to do the mapping each time.

Default readers are Omeka S reader (via the api json endpoint), xml (via
transformation with xslt), sql (to adapt to each database, an example for [e-prints]
is provided), [Spip] reader (via the database), and spreadsheet reader (via ods,
tsv or csv). The spreadsheet uses a processor that creates resources based on a
specific header format, but don't have a pretty manual ui like the module [CSV Import].


Installation
------------

This module requires the module [Log] and optionaly [Generic]. An external xslt 2
processor may be needed if you import xml files that are not importable with
xlt 1. Some specific readers or processors may need some other modules.

Since 3.4.39, the module requires php 7.4.

**Warning**: Some parts of this module may not support use of remote files: only
files saved locally on the server may be managed.

**Important**: If you use the module [CSV Import] in parallel, you should use a
version equal or greater than 2.3.0.

**Important**: If you use the module [Numeric Data Types], you should apply this
[patch] or use this [version]. This patch is no more needed since version 4.1.

See general end user documentation for [installing a module].

* From the zip

Download the last release [BulkImport.zip] from the list of releases, and
uncompress it in the `modules` directory.

* From the source and for development

If the module was installed from the source, rename the name of the folder of
the module to `BulkImport`, go to the root of the module, and run:

```sh
composer install --no-dev
```

Note: the library "CodeMirror" has no file "codemirror.js" by default: it is
created automatically when installing packages with npm. To use it via composer,
the zip file from codemirror.net (v5) is used.

Then install it like any other Omeka module.

* Files extensions

For security reasons, the module checks the extension of each ingested file. So,
if you import specific files, in particular XML metadata files and json ones,
they should be allowed in the page `/admin/setting`.

* XSLT processor

The xslt processor is only needed to import xml files that are not formatted as
flat ressources.

Xslt has two main versions:  xslt 1.0 and xslt 2.0. The first is often installed
with php via the extension `php-xsl` or the package `php5-xsl`, depending on
your system. It is until ten times slower than xslt 2.0 and sheets are more
complex to write.

So it’s recommended to install an xslt 2 processor, that can process xslt 1.0
and xslt 2.0 sheets and generally xslt 3.0 too.

To intall xslt 2 on Debian / Ubuntu :
```sh
sudo apt install --reinstall default-jre libsaxonhe-java
```

Then, the command can be configured in the configuration page of the module.
Use "%1$s", "%2$s", "%3$s", without escape, for the file input, the stylesheet,
and the output.

Examples for Debian 6+ / Ubuntu / Mint (with the package "libsaxonb-java"):
```sh
saxonb-xslt -ext:on -versionmsg:off -warnings:silent -s:%1$s -xsl:%2$s -o:%3$s
```

Examples for Debian 8+ / Ubuntu / Mint (with the package "libsaxonhe-java"):
```sh
CLASSPATH=/usr/share/java/Saxon-HE.jar java net.sf.saxon.Transform -ext:on -versionmsg:off -warnings:silent -s:%1$s -xsl:%2$s -o:%3$s
```

Example for Fedora / RedHat / Centos / Mandriva / Mageia (package "saxon"):
```sh
java -cp /usr/share/java/saxon.jar net.sf.saxon.Transform -ext:on -versionmsg:off -warnings:silent -s:%1$s -xsl:%2$s -o:%3$s
```

Or with packages "saxon", "saxon-scripts", "xml-commons-apis" and "xerces-j2":
```sh
saxon -ext:on -versionmsg:off -warnings:silent -s:%1$s -xsl:%2$s -o:%3$s
```

Note: Only saxon is currently supported as xslt 2 processor. Because Saxon is a
Java tool, a JRE should be installed, for example `openjdk-11-jre-headless` or
upper (or use `default-jre`).

Note: Warnings are processed as errors. That’s why the parameter "-warnings:silent"
is important to be able to process an import with a bad xsl sheet. It can be
removed with default xsl, that doesn’t warn anything.

Anyway, if there is no xslt2 processor installed, the command field should be
cleared. The module will use the default xslt 1 processor of php, if installed.


Usage
-----

### Quick start

Click on menu Bulk Import > Dashboard, then click on one importer, then fill
the two forms, and finally confirm the import.

To add, remove or config importers, you can go to menu Bulk Import > Configuration.
An importer is a reader and a processor.

Some readers can use a mapping, in particular json and xml. It allows to map
data between the source and the omeka json representation of resources.

The config can be selected in the first form. New config can be added in the
directory "data/mapping" of the module (default ones) or in the directory
"files/mapping" of Omeka (user ones, deprecated: use config files preferably).
They can be edited online in the menu "Mappings" too.

### Config files

The config contains four sections.

The first section is `info`, that defines the name and the label of the config.
An important key is `mapper`, that can be set to use as the base of the current
config. This generic mapper is available from the directory `mapper` of  the
module.

The second section is `params`, that allows to define some params, like how to
identify the root of the resources, or the fields of each resource. See the
example used to migrate a digital library from Content-DM. For this last, the
url should be the full one, like https://cdm21057.contentdm.oclc.org/digital/api/search/collection/coll3/maxRecords/100.

The third section is `default` and defines default metadata of the resources,
like the resource class or the template.

The fourth section is `mapping` and contains all the source fields that should
be imported and all the details about the destination field (properties or other
metadata).

### Config of the mappings

The config contains a list of mappings between source data and destination data.
Mapping can be done in two formats: key-value pair or xml.

For example:

```
/record/datafield[@tag='200'][@ind1='1']/subfield[@code='a'] = dcterms:title ^^literal @fra §private ~ pattern for {{ value|trim }} with {{/source/record/data}}
```

For xml, the same mapping is like that:

```xml
<mapping>
    <map>
        <from xpath="/record/datafield[@tag='200'][@ind1='1']/subfield[@code='a']"/>
        <to field="dcterms:title" datatype="literal" language="fra" visibility="private"/>
        <mod raw="" prepend="pattern for " pattern="{{ value|trim }} with {{/source/record/data}}" append=""/>
    </map>
</mapping>
```

The xml format is clearer, but the key-value pair can be used anywhere, included
the headers of a spreadsheet.

#### Key-value pair notation

Each line is formatted with a source and a destination separated with the sign
`=`. The format of each part (left and right of the `=`) of each line is
checked in a first step and is skipped if incorrect.

The source part can be specified in three ways: javascript dot notation,
JsonPath, JMESPath, or XPath (see below).

The destination part has one till five components and only the first is
required.

The first must be the destination field. The field is one of the key used in the
json representation of a resource, generally a property, but other metadata too
(`o:resource_template`, etc.). It can be a sub-field too, in particular to
specify related resources when importing an item: `o:media[o:original_url]`,
`o:media[o:ingester]`, or `o:item_set[dcterms:identifier]`.

The next three components are specific to properties and can occur in any order
and are prefixed with a code, similar to some rdf representations:
- the data type is prefixed with a `^^`: `^^resource:item` or `^^customvocab:5`;
- the language is prefixed with a `@`: `@fra` or `@fr-FR`;
- the visibility is prefixed with a `§`: `§private` or `§public`.

The last component is a pattern used to transform the source value when needed.
It is prefixed with a `~`. It can be a simple replacement string, or a complex
pattern with some [Twig] commands (see below).

For default values, the right part may be a simple string starting and ending
with a simple or double quotes, in which case the left part is the destination.
Next three lines are equivalent:

```
dcterms:license = dcterms:license ^^literal ~ "Public domain"
~ = dcterms:license ^^literal ~ "Public domain"
dcterms:license = ^^literal ~ "Public domain"
dcterms:license = "Public domain"
```

#### xml mapping

The example above is clear and contains main elements and attributes. See the
[example mapping for Unimarc].

Le format Xml allows too to specify mapping tables for codes, for example to
convert an iso code to a literal.

### Defining the source of the value

Four formats are supported, the first three for a json endpoint, and the last
for an xml source:

- Javascript dot object notation: The source is set nearly like a javascript
  with dot notation (even invalid): `dcterms:title.0.value`. `.` and `\` must be
  escaped with a `\`.

- [JsonPath]: This is a port of XPath for json: `$.['dcterms:title'][0].['@value']`,
  and it can manage a lot of expressions, filters, functions, etc.

- [JMESPath]: This is another port of xpath for json: `"dcterms:title"[0]"@value"`,
  and it can manage a lot of expressions, filters, functions, etc. JMESPath is
  similar to the original idea for a [JsonPath], but they are not compatible
  themselves.

- XPath: it can use any standard XPath: `/record/datafield[@tag='200'][@ind1='1']/subfield[@code='a']`
  when the source is xml.

### Defining the transformation (raw value, prepend/append, replacements)

If you need to alter the source value and format it differently, you can use
some patterns.

A simple replacement string is a pattern with some replacement values:
```
geonameId = dcterms:identifier ^^uri ~ https://www.geonames.org/{{ value }}
```

The available replacement patterns are:
- the current source value `{{ value }}`
- any source query `{{xxx}}`, for example a xpath `{{/doc/str[@name="ppn_z"]}}`.

`{{ value }}` and `{{value}}` are not the same: the first is the current value
extracted from the source part and the second is the key used to extract the
value with the key `value` from a source array.

For complex transformation, the pattern may be build as a simplified Twig one:
this is a string where the values between `{{ ` and ` }}` are converted with
some basic filters. For example, `{{ value|trim }}` takes the value from the
source and trims it. The space after `{{` and before `}}` is required.
Only some common Twig filters are supported: `abs`, `capitalize`, `date`, `e`,
`escape`, `first`, `format`, `last`, `length`, `lower`, `replace`, `slice`,
`split`, `striptags`, `table`, `title`, `trim`, `upper`, `url_encode`.
Only some common arguments of these filters are supported. Twig filters can be
combined, but not nested.

An example of the use of the filters is the renormalization of a date, here from
a xml unimarc source `17890804` into a standard [ISO 8601] numeric date time `1789-08-04`:
```
/record/datafield[@tag="103"]/subfield[@code="b"] = dcterms:valid ^^numeric:timestamp ~ {{ value|trim|slice(1,4) }}-{{ value|trim|slice(5,2) }}-{{ value|trim|slice(7,2) }}
```

Note that in that case of such a date, a simpler function can be used. Indeed,
some specific functions are added: `table`, to replace a code with a string,
`dateIso`, `dateSql`, `isbdName`, `isbdNameColl`, `isbdMark`, `unimarcIndex`,
`unimarcCoordinates`, `unimarcCoordinatesHexa`, `unimarcTimeHexa`.

To see a full example, look to the file that manage [Unimarc conversion to Omeka].
Note that this file will be improved soon to manage value annotations, an
important improvement for Omeka 3.2.

The Twig filters can be used in conjunction with simple replacements. In that
case, they are processed after the replacements.

The source can be `~` too, in which case the destination is a composite of
multiple sources:
```
~ = dcterms:spatial ~ Coordinates: {{lat}}/{{lng}}
```

Here, `{{lat}}` and `{{lng}}` are values extracted from the source.

The prefix `~` must not be used in other components or in the left part for now.

Of course, in many cases, it is simpler to fix the values in the source or later
in the resources with the batch edit or with the module [Bulk Edit].


E-prints
--------

[e-prints] is a tool to build institutional repository (research articles,
student works, learning resources, etc.). It is one of the oldest free digital
libraries that support the OAI-PMH protocol. Because Omeka S can be [OAI-PMH repository],
it is possible to upgrade to it. The process uses the sql database in order to
fetch all metadata.

Simply select the sql reader and the eprints processor, then follow the forms.


Omeka S
-------

Simply set the endpoint and eventually the credentials and run it. All data can
be fetched: vocabularies, resource templates, assets and of course items, item
sets and media. Custom vocabs are imported too. It is recommended to have the
same modules installed, in particular those that add new data types (Value Suggest,
Numeric Data Types, Rdf Data Types, Data Type Geometry).
Specific metadata of other modules are currently not managed.


Spip
----

Simply set the database credentials and  the endpoint and go on. You need to
install some more modules: [Advanced Resource Template], [Custom Vocab],
[Data Type Rdf], [Numeric Data Types], [Spip ], [Thesaurus], [User Profile].


Spreadsheet
-----------

To import a spreadsheet, choose its format and the multivalue separator if any.
Then do the mapping. The mapping is automatic when the header are properties
labels or terms, or Omeka metadata names, or existing keywords.

When importing an ODS file with formulas, try to set `string` or `general` as
the default format of cells to avoid an issue when a formula returns a string,
but the format requires something else, for example a `float`, in which case an
empty string will be returned as `0` during import even if it is not displayed
in some spreadheets.

More largely, it's not recommended to use formulas and formatted cells, since it
is not possible to be sure what are the actual data (the real ones or the
displayed ones?). Even if it is well managed in most of the cases, this is
particularly important for dates, because they are often different between
publishers and even between versions of the spreadsheets too. Some versions of
Excel on Apple does not display the same dates than the equivalent versions for
Windows. You can do select all cells (ctrl + A), then copy (ctrl + C), then
special paste (ctrl + shift + V), then choose "Values only", and save it in
another file (or do copy on a new sheet), else you will lose previous formulas,
styles and formats.

Unlike CSV Import, there is no UI mapper except for the properties, but it
manages advanced headers names to manage data types, languages and visibility
automatically, so it is recommended to use them, for example `dcterms:title ^^resource:item @fra §private`.
The format is  the same than described above for the destination.

Furthermore, there is a configurable automatic mapping in [data/mappings/fields_to_metadata.php],
and labels and names of standard property terms are already managed.

So the header of each column can have a data type (with `^^datatype`), a
language (with `@language`), and a visibility (with `§private`). Furthermore, a
pattern (prefixed with `~`) can be appended to transform the value.

For example to import a French title, use header `Title @fra` or `dcterms:title @fr`.

To import a relation as an uri, use header `Relation ^^uri` or `dcterms:relation ^^uri`.
To import an uri with a label, the header is the same, but the value should be
the uri, a space and the label.
To import a value as an Omeka resource, use header `dcterms:relation ^^resource`.
The value should be the internal id (recommended) or a resource identifier
(generally dcterms:identifier), but it should be unique in all the database.
To import a custom vocab value, the header should contain `^^customvocab:xxx`,
where "xxx" is the identifier of the vocab recommended or its label wrapped with
`"` or `'`.

Default supported datatypes are the ones managed by Omeka:
- `literal` (default)
- `uri`
- `resource`
- `resource:item`
- `resource:itemset`
- `resource:media`

Datatypes of other modules are supported too (Custom Vocab, Value Suggest, DataTypeRdf,
Numeric Data Types) if modules are present:
- `numeric:timestamp`
- `numeric:integer`
- `numeric:duration`
- `geometry:geography`
- `geometry:geometry`
- `customvocab:xxx`
- `valuesuggest:yyy`
- `html`
- `xml`
- `boolean`

The prefixes can be omitted when they are simple: `item`, `itemset`, `media`,
`timestamp`, `integer`, `duration`, `geography`, `geometry`.

Multiple datatypes can be set for one column: `dcterms:relation ^^customvocab:15 ^^resource:item ^^resource ^^literal`.
The datatype is checked for each value and if it is not valid, the next datatype
is tried. It is useful when some data are normalized and some not, for example
with a list of dates or subjects: `dcterms:date ^^numeric:timestamp ^^literal`,
or `dcterms:subject ^^valuesuggest:idref:rameau ^^literal`.

To import multiple target destination for a column, use the separator `|` in the
header. Note that if there may be multiple properties, only the first language and type
will be used for now: `dcterms:creator ^^resource:item ^^literal | foaf:name`.

The visibility of each data can be "public" (default) or "private", prefixed by `§`.

Media can be imported with the item. The mapping is automatic with headers `Media url`,
`Media html`, etc.

For more details about the possible values, see the [config above].

### Internal differences with Csv Import

- Two columns with the same headers should be mapped the same.
- Empty values for boolean metadata (`is_public`…) in spreadsheet reader are
  skipped and they don't mean "false" or "true".
- In case of insensitive duplicate, the first one is always returned.


TODO
----

- [ ] See todo in code.
- [ ] Add more tests.
- [x] Full dry-run.
- [ ] Extract list of metadata names during dry-run and output it to help building mapping.
- [ ] Fix numeric data type (doctrine issue): see fix in https://github.com/omeka-s-modules/NumericDataTypes/pull/29.
- [ ] Distinction between "skipped" and "blank" for spreadsheet.
- [ ] Update for module Mapping.
- [ ] Import of users, in particular for Omeka S import.
- [x] Import of uri with label in spreadsheet.
- [ ] Import of uri with label in value suggest.
- [ ] Skip import of vocabularies and resource templates for Omeka S import.
- [ ] Allow to set a query for Omeka S import.
- [ ] Add check, in particular with multi-sheets.
- [ ] Manage import of Custom vocab with items.
- [-] Why are there 752 missing ids with direct sql creation in Spip?
- [-] Spip: Utiliser la langue de la rubrique supérieure si pas de langue.
- [ ] Use metaMapper() for sql imports (so convert special processors) or convert rows early (like spreadsheets).
- [x] For sql import, use a direct sql query when mapping is table to table (like eprints statistics).
- [ ] Convert specific importer into standard resource processor + pattern.
- [ ] Deprecate all direct converters that don't use metaMapper() (so upgrade spreadsheet process).
- [ ] Count of skipping or empty rows is different during check and real process.
- [ ] Check default item set, template and class (they may be not set during creation or update or replace via spreadsheet).
- [ ] Check a resource with `o:item_set[dcterms:title]`.
- [ ] Add action "error" for unidentified resources.
- [ ] Divide option "allow duplicate" as "allow missing" and "allow duplicate"?
- [x] Add import multiple xml files like json.
- [ ] Show details for mappings: add list of used configuration as importer and as parent/child.
- [ ] Add automatic determination of the source (csv, file, iiif, multiple iiif, omeka classic, etc.).
- [ ] Replace internal jsdot by RoNoLo/json-query or binary-cube/dot-array or jasny/dotkey? Probably useless.
- [ ] Compile jmespath.
- [ ] Import/update value annotations.
- [ ] Remove importeds when resource is removed.
- [ ] Output a one-based index in Entry.
- [ ] Move option "convert into html" somewhere else.
- [ ] Normalize config of metadata extraction with metamapper.
- [ ] Add an automatic mapping for images etc. with xmp.
- [ ] Manage import params and params.


Warning
-------

Use it at your own risk.

It’s always recommended to backup your files and your databases and to check
your archives regularly so you can roll back if needed.


Troubleshooting
---------------

See online issues on the [module issues] page on GitLab.


License
-------

### Module

This module is published under the [CeCILL v2.1] license, compatible with
[GNU/GPL] and approved by [FSF] and [OSI].

This software is governed by the CeCILL license under French law and abiding by
the rules of distribution of free software. You can use, modify and/ or
redistribute the software under the terms of the CeCILL license as circulated by
CEA, CNRS and INRIA at the following URL "http://www.cecill.info".

As a counterpart to the access to the source code and rights to copy, modify and
redistribute granted by the license, users are provided only with a limited
warranty and the software’s author, the holder of the economic rights, and the
successive licensors have only limited liability.

In this respect, the user’s attention is drawn to the risks associated with
loading, using, modifying and/or developing or reproducing the software by the
user in light of its specific status of free software, that may mean that it is
complicated to manipulate, and that also therefore means that it is reserved for
developers and experienced professionals having in-depth computer knowledge.
Users are therefore encouraged to load and test the software’s suitability as
regards their requirements in conditions enabling the security of their systems
and/or data to be ensured and, more generally, to use and operate it in the same
conditions as regards security.

The fact that you are presently reading this means that you have had knowledge
of the CeCILL license and that you accept its terms.

### Libraries

- Flow.js / Flow php server

  License [MIT]

- CodeMirror

  License [MIT]

See licenses of other libraries in composer.json.

### Data

- Table of countries and languages:
  - https://www.iso.org/obp/ui/
  - https://download.geonames.org/export/dump/countryInfo.txt


Copyright
---------

* Copyright BibLibre, 2016-2017
* Copyright Roy Rosenzweig Center for History and New Media, 2015-2018
* Copyright Daniel Berthereau, 2017-2022 (see [Daniel-KM] on GitLab)
* Copyright (c) 2001-2019, Arnaud Martin, Antoine Pitrou, Philippe Rivière, Emmanuel Saint-James (code from Spip)
* Copyright 2011-2022, Steffen Fagerström Christensen & alii (libraries [Flow.js] and [flow-php-server])
* Copyright 2011-2023, Marijn Haverbeke & alii (library [CodeMirror])

This module was initially inspired by the [Omeka Classic] [Import plugin], built
by [BibLibre] and has been built for the future digital library [Manioc] of the
Université des Antilles and Université de la Guyane, currently managed with
[Greenstone]. Some other features were built for the future digital library [Le Menestrel]
and for the institutional repository of student works [Dante] of the [Université de Toulouse Jean-Jaurès].


[Bulk Import]: https://gitlab.com/Daniel-KM/Omeka-S-module-BulkImport
[Lisez-moi]: https://gitlab.com/Daniel-KM/Omeka-S-module-BulkImport/-/blob/master/LISEZMOI.md
[Omeka S]: https://omeka.org/s
[CSV Import]: https://omeka.org/s/modules/CSVImport
[Omeka Classic]: https://omeka.org/classic
[Import plugin]: https://github.com/BibLibre/Omeka-plugin-Import
[size or number of files]: https://github.com/omeka/omeka-s/issues/1785
[Generic]: https://gitlab.com/Daniel-KM/Omeka-S-module-Generic
[Log]: https://gitlab.com/Daniel-KM/Omeka-S-module-Log
[BulkImport.zip]: https://gitlab.com/Daniel-KM/Omeka-S-module-BulkImport/-/releases
[Installing a module]: https://omeka.org/s/docs/user-manual/modules/#installing-modules
[CSV Import]: https://github.com/omeka-s-modules/CSVImport
[patch]: https://github.com/omeka-s-modules/NumericDataTypes/pull/29
[version]: https://github.com/Daniel-KM/Omeka-S-module-NumericDataTypes
[example mapping for Unimarc]: https://gitlab.com/Daniel-KM/Omeka-S-module-BulkImport/-/blob/master/data/mapping/xml/unimarc_to_omeka.xml
[ISO 8601]: https://www.iso.org/iso-8601-date-and-time-format.html
[JsonPath]: https://goessner.net/articles/JsonPath/index.html
[JMESPath]: https://jmespath.org
[Unimarc conversion to Omeka]: https://gitlab.com/Daniel-KM/Omeka-S-module-BulkImport/-/blob/master/data/mapping/xml/unimarc_to_omeka.xml
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
[module issues]: https://gitlab.com/Daniel-KM/Omeka-S-module-BulkImport/-/issues
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[MIT]: https://github.com/sandywalker/webui-popover/blob/master/LICENSE.txt
[Flow.js]: https://flowjs.github.io/ng-flow
[flow-php-server]: https://github.com/flowjs/flow-php-server
[CodeMirror]: https://codemirror.net
[BibLibre]: https://github.com/BibLibre
[GitLab]: https://gitlab.com/Daniel-KM
[Daniel-KM]: https://gitlab.com/Daniel-KM "Daniel Berthereau"
