Bulk Import (module for Omeka S)
================================

> __New versions of this module and support for Omeka S version 3.0 and above
> are available on [GitLab], which seems to respect users and privacy better
> than the previous repository.__

[Bulk Import] is a module for [Omeka S] that allows to import any type of source
and is built to be extensible. It allows to manage importers and to process bulk
import of resources.

It manages readers of a source (xml, sql, spreadsheet, url…) and uses processors
to import them as Omeka resources and other data (users, templates…) via a
mapping.

Because multiple importers can be prepared with the same readers and processors,
it is possible to import multiple times the same type of files without needing
to do the mapping each time.

Default readers are Omeka S reader (via the api json endpoint), xml (via
transformation with xslt), sql (to adapt to each database), [Spip] reader (via a
dump of the database), and spreadsheet reader (via ods, tsv or csv). The
spreadsheet uses a processor that creates resources based on a specific header
format, but don't have a pretty manual ui like the module [CSV Import].

Furthermore, it adds a way to bulk upload files manually without limit of [size or number of files].


Installation
------------

This module requires the module [Log] and optionnaly [Generic]. An external xslt 2
processor may be needed if you import xml files that are not importable with
xlt 1. Some specific readers or processors may need some other modules.

**Important**: If you use the module [CSV Import] in parallel, you should apply
[this patch] or use [this version].

**Important**: If you use the module [Numeric Data Types], you should apply this
[other patch] or use this [other version].

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
the missing file is added in the package used by composer.

Then install it like any other Omeka module.

* Files extensions

For security reasons, the plugin checks the extension of each ingested file. So,
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
and xslt 2.0 sheets.

To intall xslt 2 on Debian / Ubuntu :
```sh
sudo apt install --reinstall default-jre libsaxonhe-java
```

Then, the command can be configured in the configuration page of the module.
Use "%1$s", "%2$s", "%3$s", without escape, for the file input, the stylesheet,
and the output.

Examples for Debian 6+ / Ubuntu / Mint (with the package "libsaxonb-java"):
```
saxonb-xslt -ext:on -versionmsg:off -warnings:silent -s:%1$s -xsl:%2$s -o:%3$s
```

Examples for Debian 8+ / Ubuntu / Mint (with the package "libsaxonhe-java"):
```
CLASSPATH=/usr/share/java/Saxon-HE.jar java net.sf.saxon.Transform -ext:on -versionmsg:off -warnings:silent -s:%1$s -xsl:%2$s -o:%3$s
```

Example for Fedora / RedHat / Centos / Mandriva / Mageia (package "saxon"):
```
java -cp /usr/share/java/saxon.jar net.sf.saxon.Transform -ext:on -versionmsg:off -warnings:silent -s:%1$s -xsl:%2$s -o:%3$s
```

Or with packages "saxon", "saxon-scripts", "xml-commons-apis" and "xerces-j2":
```
saxon -ext:on -versionmsg:off -warnings:silent -s:%1$s -xsl:%2$s -o:%3$s
```

Note: Only saxon is currently supported as xslt 2 processor. Because Saxon is a
Java tool, a JRE should be installed, for example `openjdk-11-jre-headless` or
upper (or use `default-jre`).

Note: Warnings are processed as errors. That’s why the parameter "-warnings:silent"
is important to be able to process an import with a bad xsl sheet. It can be
removed with default xsl, that doesn’t warn anything.

Anyway, if there is no xslt2 processor installed, the command field should be
cleared. The plugin will use the default xslt 1 processor of php, if installed.


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
"files/mapping" of Omeka (user ones). They can be edited online in the menu
"Mappings" too.

### Config files

The config contains four sections.

The first section is `info`, that defines the name and the label of the config.
An important key is `mapper`, that can be set to use as the base of the current
config. This generic mapper is available from the directory `mapper` of  the
module.

The second section is `params`, that allows to define some params, like how to
identify the root of the resources, or the fields of each resource. See the
example used to migrate a digital library from Content-dm. For this last, the
url should be the full one, like https://cdm21057.contentdm.oclc.org/digital/api/search/collection/coll3/maxRecords/100.

The third section is `default` and defines default metadata of the resources,
like the resource class or the template.

The fourth section is `mapping` and contains all the source fields that should
be imported and all the details about the destination field.

### Config of the mappings

The config contains a list of mappings between source data and destination data.
Mapping can be done in two formats: ini or xml.

For example:

```
source or xpath = dcterms:title @fr-fr ^^literal §private ~ pattern for the {{ value|trim }} with {{/source/record/data}}
```

will be converted internally and used to create a resource like that:

```php
[
     'from' => 'source or xpath',
     'to' => [
         'field' => 'dcterms:title',
         'property_id' => 1,
         'type' => 'literal',
         '@language' => 'fr-fr',
         'is_public' => false,
         'pattern' => 'pattern for the {{ value|trim }} with {{/source/record/data}}',
         'replace' => [
             '{{/source/record/data}}',
         ],
         'twig' => [
             '{{ value|trim }}',
         ],
     ],
]
```

For xml, the mapping is like that:

```xml
<map>
    <from xpath="/record/datafield[@tag='200'][@ind1='1']/subfield[@code='a']"/>
    <to field="dcterms:title" datatypes="literal" lang="" visibility="" raw="" prepend="" pattern="" append=""/>
</map>
```

A config is composed of multiple lines. The sections like "[info]" are managed:
the next lines will be a sub-array.

Each line is formatted with a source and a destination separated with the sign
"=". The format of each part (left and right of the "=") of each line is
checked, but not if it has a meaning.

The source part may be the key in an array, or in a sub-array (`dcterms:title.0.@value`),
or a xpath (used when the input is xml).

The destination part is an automap field. It has till five components and only
the first is required.

The first must be the destination field. The field is one of the key used in the
json representation of a resource, generally a property, but other metadata too
("o:resource_template", etc.). It can be a sub-field too, in particular to
specify related resources when importing an item: `o:media[o:original_url]`,
`o:media[o:ingester]`, or `o:item_set[dcterms:identifier]`.

The next three components are specific to properties and can occur in any order
and are prefixed with a code, similar to some rdf representations:
The language is prefixed with a `@`: `@fr-FR` or `@fra`.
The data type is prefixed with a `^^`: `^^resource:item` or `^^customvocab:Liste des établissements`.
The visibility is prefixed with a `§`: `§public` or `§private`.

The last component is a pattern used to transform the source value when needed.
It is prefixed with a `~`. It can be a simple replacement string, or a complex
pattern with some [Twig] commands.

A simple replacement string is a pattern with some replacement values:
```
geonameId = dcterms:identifier ^^uri ~ https://www.geonames.org/{{ value }}
```

The available replacement patterns are: the current source value `{{ value }}`
and any source query `{{xxx}}`, for example a xpath `{{/doc/str[@name="ppn_z"]}}`.
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

Some specific functions are added: `table`, to replace a code with a string,
`dateIso`, `dateSql`, `isbdName`, `isbdNameColl`, `isbdMark`, `unimarcIndex`,
`unimarcCoordinates`, `unimarcCoordinatesHexa`, `unimarcTimeHexa`.

To see a full example, look to the file that manage [Unimarc conversion to Omeka].
Note that this file will be improved soon to manage value annotations, an very
interesting improvement for Omeka 3.2.

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


Omeka S
-------

Simply set the endpoint and eventually the credentials and run it. All data are
fetch: vocabularies, resource templates, assets and of course items, item sets
and media. Custom vocabs are imported too. It is recommended to have the same
modules installed, in particular those that add new data types (Value Suggest,
Numeric Data Types, Rdf Data Types, Data Type Geometry).
Specific metadata of other modules are currently not managed.


Spip
-------

Simply set the database credentials and  the endpoint and go on. You need to
install some more modules: [Advanced Resource Template], [Article], [Custom Vocab],
[Data Type Rdf], [Numeric Data Types], [Spip ], [Thesaurus], [User Profile].


Spreadsheet
-----------

To import a spreadsheet, choose its format and the multivalue separator if any.
Then do the mapping. The mapping is automatic when the header are properties
label, or existing terms, or Omeka metadata names, or existing keywords.

When importing an ODS file with formulas, try to set `string` or `general` as
the default format of cells to avoid an issue when a formula returns a string,
but the format requires something else, for example a `float`, in which case an
empty string will be returned as `0` during import even if it is not displayed
in some spreadheets.

More largely, it's not recommended to use formulas and formatted cells, since it
is not possible to be sure what are the actual data (the real ones or the
displayed ones?). Even if it is well managed in most of the case, this is
particularly important for dates, because they are often different between
spreadsheets and version of the spreadsheets too. Some versions of Excel on
Apple have does not display the same dates than the equivalent versions for
Windows. You can do select all cells (ctrl + A), then copy (ctrl + C), then
special paste (ctrl + shift + V), then choose "Values only", and save it in
another file (or do copy on a new sheet), else you will lose previous formulas,
styles and formats.

Unlike CSV Import, there is no UI mapper except for the properties, but it
manages advanced headers names to manage data types, languages and visibility
automatically, so it is recommended to use them, for example `dcterms:title @fra ^^resource:item §private`.

Furthermore, there is a configurable automatic mapping in [data/mappings/fields_to_metadata.php],
and standard property terms are already managed.

So the header of each column can have a language (with `@language`), a datatype
(with `^^datatype`), and a visibility (with `§private`). Furthermore, a pattern
(prefixed with "~") can be appended to transform the value.

For example to import a French title, use header `Title @fr` or `dcterms:title @fra`.

To import a relation as an uri, use header `Relation ^^uri` or `dcterms:relation ^^uri`.
To import an uri with a label, the header is the same, but the value should be
the uri, a space and the label.
To import a value as an Omeka resource, use header `dcterms:relation ^^resource`.
The value should be the internal id (recommended) or a resource identifier
(generally dcterms:identifier), but it should be unique in all the database.
To import a custom vocab value, the header should contain `^^customvocab:xxx`,
where "xxx" is the identifier of the vocab or its label without punctuation.

Default supported datatypes are the ones managed by omeka:
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
- `customvocab:xxx` (where xxx is the id, or the label without punctuation, but space is allowed)
- `valuesuggest:xxx`
- `html`
- `xml`
- `boolean`

The prefixes can be omitted when they are simple: `item`, `itemset`, `media`,
`timestamp`, `integer`, `duration`, `geography`, `geometry`.

Multiple datatypes can be set for one column, separated with a `;`: `dcterms:relation ^^customvocab:15 ; resource:item ; resource ; literal`.
The datatype is checked for each value and if it is not valid, the next datatype
is tried. It is useful when some data are normalized and some not, for example
with a list of dates or subjects: `dcterms:date ^^numeric:timestamp ; literal`,
or `dcterms:subject ^^valuesuggest:idref:rameau ; literal`.

To import multiple targets for a column, use the separator "|" in the header.
Note that if there may be multiple properties, only the first language and type
will be used for now: `dcterms:creator ^^resource ; literal | foaf:name`.

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
- [x] Full dry-run.
- [ ] Extract list of metadata names during dry-run and output it to help building mapping.
- [ ] Fix numeric data type (doctrine issue): see fix in https://github.com/omeka-s-modules/NumericDataTypes/pull/29.
- [ ] Distinction between skipped and blank (for spreadsheet).
- [ ] Update for module Mapping.
- [ ] Import of users, in particular for Omeka S import.
- [x] Import of uri with label in spreadsheet.
- [ ] Import of uri with label in value suggest.
- [ ] Skip import of vocabularies and resource templates for Omeka S import.
- [ ] Allow to set a query for Omeka S import.
- [ ] Add check, in particular with multi-sheets.
- [ ] Manage import of Custom vocab with items.
- [ ] Convert specific importer into standard resource processor + pattern.
- [ ] Why are there 752 missing ids with direct sql creation in Spip?
- [ ] Spip: Utiliser la langue de la rubrique supérieure si pas de langue.


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

  Licence [MIT]

- CodeMirror

  Licence [MIT}

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
* Copyright 2011-2022, Marijn Haverbeke & alii (library [CodeMirror])

This module was initially inspired by the [Omeka Classic] [Import plugin], built
by [BibLibre].


[Bulk Import]: https://gitlab.com/Daniel-KM/Omeka-S-module-BulkImport
[Omeka S]: https://omeka.org/s
[CSV Import]: https://omeka.org/s/modules/CSVImport
[Omeka Classic]: https://omeka.org/classic
[Import plugin]: https://github.com/BibLibre/Omeka-plugin-Import
[size or number of files]: https://github.com/omeka/omeka-s/issues/1785
[Generic]: https://gitlab.com/Daniel-KM/Omeka-S-module-Generic
[Log]: https://gitlab.com/Daniel-KM/Omeka-S-module-Log
[BulkImport.zip]: https://gitlab.com/Daniel-KM/Omeka-S-module-BulkImport/-/releases
[installing a module]: http://dev.omeka.org/docs/s/user-manual/modules/#installing-modules
[CSV Import]: https://github.com/omeka-s-modules/CSVImport
[this patch]: https://github.com/omeka-s-modules/CSVImport/pull/182
[this version]: https://gitlab.com/Daniel-KM/Omeka-S-module-CSVImport
[other patch]: https://github.com/omeka-s-modules/NumericDataTypes/pull/29
[other version]: https://github.com/Daniel-KM/Omeka-S-module-NumericDataTypes
[ISO 8601]: https://www.iso.org/iso-8601-date-and-time-format.html
[Unimarc conversion to Omeka]: https://gitlab.com/Daniel-KM/Omeka-S-module-BulkImport/-/blob/master/data/mapping/xml/unimarc_to_omeka.xml
[Twig]: https://twig.symfony.com/doc/3.x
[config above]: https://gitlab.com/Daniel-KM/Omeka-S-module-BulkImport#config-of-the-mappings
[Bulk Edit]: https://gitlab.com/Daniel-KM/Omeka-S-module-BulkEdit
[Spip]: https://spip.net
[data/mappings/fields_to_metadata.php]: https://gitlab.com/Daniel-KM/Omeka-S-module-BulkImport/-/blob/master/data/mappings/fields_to_metadata.php
[Advanced Resource Template]: https://gitlab.com/Daniel-KM/Omeka-S-module-AdvancedResourceTemplate
[Article]: https://gitlab.com/Daniel-KM/Omeka-S-module-Article
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
