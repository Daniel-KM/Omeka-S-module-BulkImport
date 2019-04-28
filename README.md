Bulk Import (module for Omeka S)
================================

[Bulk Import] is yet another import module for [Omeka S]. This one intends to be
easily extensible by other modules. It allows to manage importers and to process
bulk import of resources.

The two main concepts are readers and processors. Readers read data from a
source (file, url…) and make it accessible for processors which turn these data
into Omeka objects (items, item sets, media, annotations…) via a mapping.

Because multiple importers can be prepared with the same readers and processors,
it is possible to import multiple times the same type of files without needing
to do the mapping each time.

As an example, this module defines a sample reader for spreadsheet files and a
processor that creates resources based on a user-defined mapping. Note: if your
only need is to import a CSV file into Omeka, you should probably use [CSV Import module],
which does a perfect job for that.

This module was initially based on a port of the [Omeka Classic] [Import plugin],
built by [Biblibre].


Installation
------------

This module requires the modules [Generic] and [Log].

See general end user documentation for [installing a module].

* From the zip

Download the last release [`BulkImport.zip`] from the list of releases, and
uncompress it in the `modules` directory.

* From the source and for development

If the module was installed from the source, rename the name of the folder of
the module to `BulkImport`.


Quick start
-----------

First, define an importer, that is a reader and a processor. By default, they
are only one.

Then, config the reader and the processor.

Finally, process the import.


Spreadsheet
-----------

To import a spreadsheet, choose its format and the multivalue separator if any.
Then do the mapping. The mapping is automatic when the header are properties
label, or existing terms, or Omeka metadata names, or existing keywords.

The header can have a language (with `@language`) and a datatype (with `^^datatype`).
For example to import a French title, use header `Title @fr` or `dcterms:title@fr`.
To import a relation as an uri, use header `Relation ^^uri` or `dcterms:relation^^uri`.
To import an uri with its label, if any, use header `Relation ^^uri-label`.
To import a value as an Omeka resource, use header `Relation ^^resource`. The
value should be the internal id or a resource identifier (generally dcterms:identifier).

Media can be imported with the item. The mapping is automatic with headers `Media url`,
`Media html`, etc.

### Internal differences with Csv Import

- Two columns with the same headers should be mapped the same.
- Empty values for boolean metadata (is_public…) in spreadsheet reader are
  skipped and they don't mean "false" or "true".
- In case of insensitive duplicate, the first one is always returned.


TODO
----

- Full dry-run.
- Fix numeric data type (doctrine issue).


Warning
-------

Use it at your own risk.

It’s always recommended to backup your files and your databases and to check
your archives regularly so you can roll back if needed.


Troubleshooting
---------------

See online issues on the [module issues] page on GitHub.


License
-------

This module is published under the [CeCILL v2.1] licence, compatible with
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
* Copyright Daniel Berthereau, 2017-2019 (see [Daniel-KM] on GitHub)


[Bulk Import]: https://github.com/Daniel-KM/Omeka-S-module-BulkImport
[Omeka S]: https://omeka.org/s
[CSV Import module]: https://omeka.org/s/modules/CSVImport
[Omeka Classic]: https://omeka.org/classic
[Import plugin]: https://github.com/BibLibre/Omeka-plugin-Import
[Generic]: https://github.com/Daniel-KM/Omeka-S-module-Generic
[Log]: https://github.com/Daniel-KM/Omeka-S-module-Log
[`BulkImport.zip`]: https://github.com/Daniel-KM/Omeka-S-module-BulkImport/releases
[installing a module]: http://dev.omeka.org/docs/s/user-manual/modules/#installing-modules
[module issues]: https://github.com/Daniel-KM/Omeka-S-module-BulkImport/issues
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[MIT]: https://github.com/sandywalker/webui-popover/blob/master/LICENSE.txt
[BibLibre]: https://github.com/BibLibre
[Daniel-KM]: https://github.com/Daniel-KM "Daniel Berthereau"
