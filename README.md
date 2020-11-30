Bulk Import (module for Omeka S)
================================

> __New versions of this module and support for Omeka S version 3.0 and above
> are available on [GitLab], which seems to respect users and privacy better
> than the previous repository.__

[Bulk Import] is yet another import module for [Omeka S]. This one intends to be
easily extensible by other modules. It allows to manage importers and to process
bulk import of resources.

The two main concepts are readers and processors. Readers read data from a
source (file, url…) and make it accessible for processors which turn these data
into Omeka objects (items, item sets, media, annotations…) via a mapping.

Because multiple importers can be prepared with the same readers and processors,
it is possible to import multiple times the same type of files without needing
to do the mapping each time.

Default readers are Omeka S reader (via the api json endpoint) and spreadsheet
reader. The spreadsheet uses a processor that creates resources based on a
user-defined mapping. Note: if your only need is to import a CSV file into
Omeka, you should probably use [CSV Import module], which does a perfect job for
that.


Installation
------------

This module requires the module [Log] and optionnaly [Generic]. An external xslt 2
processor may be needed if you import xml files that are not importable with
xlt 1.

**Important**: If you use the module [CSVImport] in parallel, you should apply [this patch]
of use [this version].

See general end user documentation for [installing a module].

* From the zip

Download the last release [BulkImport.zip] from the list of releases, and
uncompress it in the `modules` directory.

* From the source and for development

If the module was installed from the source, rename the name of the folder of
the module to `BulkImport`, go to the root of the module, and run:

```
composer install --no-dev
```

Then install it like any other Omeka module.

* Files extensions

For security reasons, the plugin checks the extension of each ingested file. So,
if you import specific files, in particular XML metadata files and json ones,
they should be allowed in the page `/admin/setting`.

* XSLT processor

Xslt has two main versions:  xslt 1.0 and xslt 2.0. The first is often installed
with php via the extension `php-xsl` or the package `php5-xsl`, depending on
your system. It is until ten times slower than xslt 2.0 and sheets are more
complex to write.

So it’s recommended to install an xslt 2 processor, that can process xslt 1.0
and xslt 2.0 sheets. The command can be configured in the configuration page of
the plugin. Use "%1$s", "%2$s", "%3$s", without escape, for the file input, the
stylesheet, and the output.

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
Java tool, a JRE should be installed, for example `openjdk-8-jre-headless` or
upper.

Note: Warnings are processed as errors. That’s why the parameter "-warnings:silent"
is important to be able to process an import with a bad xsl sheet. It can be
removed with default xsl, that doesn’t warn anything.

Anyway, if there is no xslt2 processor installed, the command field should be
cleared. The plugin will use the default xslt 1 processor of php, if installed.


Quick start
-----------

First, define an importer, that is a reader and a processor. By default, they
are only one.

Then, config the reader and the processor.

Finally, process the import.


Omeka S
-------

Simply set the endpoint and eventually the credentials and run it. All data are
fetch: vocabularies, resource templates, assets and of course items, item sets
and media. Custom vocabs are imported too. It is recommended to have the same
modules installed, in particular those that add new data types (Value Suggest,
Numeric Data Types, Rdf Data Types, Data Type Geometry).
Specific metadata of other modules are currently not managed.


Spreadsheet
-----------

To import a spreadsheet, choose its format and the multivalue separator if any.
Then do the mapping. The mapping is automatic when the header are properties
label, or existing terms, or Omeka metadata names, or existing keywords.

The header can have a language (with `@language`), a datatype (with `^^datatype`)
and a visibility (with `§private`).
For example to import a French title, use header `Title @fr` or `dcterms:title @fr`.
To import a relation as an uri, use header `Relation ^^uri` or `dcterms:relation ^^uri`.
To import an uri with its label, if any, use header `Relation ^^uri-label`.
To import a value as an Omeka resource, use header `Relation ^^resource`. The
value should be the internal id or a resource identifier (generally dcterms:identifier).
To import multiple targets for a column, use the separator "|" in the header.
Note that if there may be multiple properties, only the first language and type
will be used. It allows to keep consistency in the metadata.

Media can be imported with the item. The mapping is automatic with headers `Media url`,
`Media html`, etc.


### Internal differences with Csv Import

- Two columns with the same headers should be mapped the same.
- Empty values for boolean metadata (is_public…) in spreadsheet reader are
  skipped and they don't mean "false" or "true".
- In case of insensitive duplicate, the first one is always returned.


TODO
----

- [ ] Full dry-run.
- [ ] Fix numeric data type (doctrine issue).
- [ ] Distinction between skipped and blank (for spreadsheet).
- [ ] Update for module Mapping.
- [ ] Import of users, in particular for Omeka S import.
- [ ] Skip import of vocabularies and resource templates for Omeka S import.
- [ ] Manage import of Custom vocab with items.


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


Copyright
---------

* Copyright BibLibre, 2016-2017
* Copyright Roy Rosenzweig Center for History and New Media, 2015-2018
* Copyright Daniel Berthereau, 2017-2020 (see [Daniel-KM] on GitLab)

This module was initially inspired by the [Omeka Classic] [Import plugin], built
by [BibLibre].


[Bulk Import]: https://gitlab.com/Daniel-KM/Omeka-S-module-BulkImport
[Omeka S]: https://omeka.org/s
[CSV Import module]: https://omeka.org/s/modules/CSVImport
[Omeka Classic]: https://omeka.org/classic
[Import plugin]: https://github.com/BibLibre/Omeka-plugin-Import
[Generic]: https://gitlab.com/Daniel-KM/Omeka-S-module-Generic
[Log]: https://gitlab.com/Daniel-KM/Omeka-S-module-Log
[BulkImport.zip]: https://gitlab.com/Daniel-KM/Omeka-S-module-BulkImport/-/releases
[installing a module]: http://dev.omeka.org/docs/s/user-manual/modules/#installing-modules
[CSV Import]: https://github.com/omeka-s-modules/CSVImport
[this patch]: https://github.com/omeka-s-modules/CSVImport/pull/182
[this version]: https://github.com/Daniel-KM/Omeka-S-module-CSVImport
[module issues]: https://gitlab.com/Daniel-KM/Omeka-S-module-BulkImport/-/issues
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[MIT]: https://github.com/sandywalker/webui-popover/blob/master/LICENSE.txt
[BibLibre]: https://github.com/BibLibre
[GitLab]: https://gitlab.com/Daniel-KM
[Daniel-KM]: https://gitlab.com/Daniel-KM "Daniel Berthereau"
