<?php declare(strict_types=1);
/*
 * Mapping between common field name and standard Omeka metadata name from the
 * json-ld representation, or, in some cases, the value used inside the resource
 * form (mainly for media).
 *
 * To implement new mapping, see \BulkImport\ProcessorAbstractResourceProcessor::prepareMapping().
 *
 * This list can be completed or removed.
 */

return [
    // Owner may be email, id or name.
    'owner' // @translate
        => 'o:owner',
    'owner email' // @translate
        => 'o:email',
    'id' // @translate
        => 'o:id',
    'internal id' // @translate
        => 'o:id',
    'resource' // @translate
        => 'o:id',
    'resources' // @translate
        => 'o:id',
    'resource id' // @translate
        => 'o:id',
    'resource identifier' // @translate
        => 'dcterms:identifier',
    'record' // @translate
        => 'o:id',
    'records' // @translate
        => 'o:id',
    'record id' // @translate
        => 'o:id',
    'record identifier' // @translate
        => 'dcterms:identifier',
    'resource name' // @translate
        => 'resource_name',
    'resource type' // @translate
        => 'resource_name',
    'record type' // @translate
        => 'resource_name',
    'resource template' // @translate
        => 'o:resource_template',
    'template' // @translate
        => 'o:resource_template',
    'item type' // @translate
        => 'o:resource_class',
    'resource class' // @translate
        => 'o:resource_class',
    'class' // @translate
        => 'o:resource_class',
    'visibility' // @translate
        => 'o:is_public',
    'public' // @translate
        => 'o:is_public',
    'asset' // @translate
        => 'o:thumbnail',
    'thumbnail' // @translate
        => 'o:thumbnail',
    'item set' // @translate
        => 'o:item_set',
    'item sets' // @translate
        => 'o:item_set',
    'collection' // @translate
        => 'o:item_set',
    'collection name' // @translate
        => 'o:item_set',
    'collections' // @translate
        => 'o:item_set',
    'item set id' // @translate
        => 'o:item_set',
    'collection id' // @translate
        => 'o:item_set',
    'item set identifier' // @translate
        => 'o:item_set',
    'collection identifier' // @translate
        => 'o:item_set',
    'item set title' // @translate
        => 'o:item_set/dcterms:title',
    'collection title' // @translate
        => 'o:item_set/dcterms:title',
    'collection name' // @translate
        => 'o:item_set/dcterms:title',
    'open' // @translate
        => 'o:is_open',
    'openness' // @translate
        => 'o:is_open',
    'item' // @translate
        => 'o:item',
    'items' // @translate
        => 'o:item',
    'item id' // @translate
        => 'o:item',
    'item identifier' // @translate
        => 'o:item',
    'media' // @translate
        => 'o:media',
    'media id' // @translate
        => 'o:media',
    'media source' // @translate
        => 'o:source',
    'media filename' // @translate
        => 'o:filename',
    'media basename' // @translate
        => 'o:basename',
    'media storage' // @translate
        => 'o:storage_id',
    'media storage id' // @translate
        => 'o:storage_id',
    'media hash' // @translate
        => 'o:sha256',
    'media sha256' // @translate
        => 'o:sha256',
    'media identifier' // @translate
        => 'o:media',
    'media title' // @translate
        => 'o:media/dcterms:title',
    'media url' // @translate
        => 'url',
    'media file' // @translate
        => 'file',
    'media html' // @translate
        => 'html',
    'media public' // @translate
        => 'o:media/o:is_public',
    'media source' // @translate
        => 'o:source',
    'file source' // @translate
        => 'o:source',
    'file storage' // @translate
        => 'o:storage_id',
    'file hash' // @translate
        => 'o:sha256',
    'file sha256' // @translate
        => 'o:sha256',
    'storage' // @translate
        => 'o:storage_id',
    'storage id' // @translate
        => 'o:storage_id',
    'basename' // @translate
        => 'o:basename',
    'hash' // @translate
        => 'o:sha256',
    'sha256' // @translate
        => 'o:sha256',
    'html' // @translate
        => 'html',
    'iiif' // @translate
        => 'iiif',
    'iiif image' // @translate
        => 'iiif',
    'oembed' // @translate
        => 'oembed',
    'youtube' // @translate
        => 'youtube',
    'url' // @translate
        => 'url',
    'user' // @translate
        => 'o:user',
    'name' // @translate
        => 'o:name',
    'display name' // @translate
        => 'o:name',
    'username' // @translate
        => 'o:name',
    'user name' // @translate
        => 'o:name',
    'email' // @translate
        => 'o:email',
    'user email' // @translate
        => 'o:email',
    'role' // @translate
        => 'o:role',
    'user role' // @translate
        => 'o:role',
    'active' // @translate
        => 'o:is_active',
    'is active' // @translate
        => 'o:is_active',

    // Automapping from external modules.

    // A file can be a url or a local address (for sideload).
    'file' // @translate
        => 'file',
    'files' // @translate
        => 'file',
    'filename' // @translate
        => 'file',
    'filenames' // @translate
        => 'file',
    'file name' // @translate
        => 'file',
    'file names' // @translate
        => 'file',
    'upload' // @translate
        => 'file',
    'sideload' // @translate
        => 'file',
    'file sideload' // @translate
        => 'file',
    'dir' // @translate
        => 'directory',
    'dir sideload' // @translate
        => 'directory',
    'directory' // @translate
        => 'directory',
    'directory sideload' // @translate
        => 'directory',
    'folder' // @translate
        => 'directory',
    'folder sideload' // @translate
        => 'directory',
    'sideload dir' // @translate
        => 'directory',
    'sideload folder' // @translate
        => 'directory',
    'sideload_dir'
        => 'directory',
    'sideload_folder'
        => 'directory',
    'iiif' // @translate
        => 'iiif',
    'file iiif' // @translate
        => 'iiif',
    // When used with module Archive Repertory.
    'base file name' // @translate
        => 'o:basename',
    'base filename' // @translate
        => 'o:basename',

    // Deprecated: "tile" is only a renderer, no more an ingester since
    // ImageServer version 3.6.13. All images are automatically tiled, so "tile"
    // is a format similar to large/medium/square, but different.
    // Internally managed as a "file" in the module.
    // A tile for image server.
    'tile' // @translate
        => 'tile',
    'media tile' // @translate
        => 'tile',

    // From module Mapping.
    'o-module-mapping:mapping'
        => 'o-module-mapping:bounds',
    'o-module-mapping:bounds'
        => 'o-module-mapping:bounds',
    'bounds' // @translate
        => 'o-module-mapping:bounds',
    'mapping bounds' // @translate
        => 'o-module-mapping:bounds',

    'o-module-mapping:marker'
        => 'o-module-mapping:marker',
    'o-module-mapping:lat'
        => 'o-module-mapping:lat',
    'o-module-mapping:lng'
        => 'o-module-mapping:lng',
    'o-module-mapping:label'
        => 'o-module-mapping:label',
    'latitude' // @translate
        => 'o-module-mapping:lat',
    'longitude' // @translate
        => 'o-module-mapping:lng',
    'latitude/longitude' // @translate
        => 'o-module-mapping:marker',

    'default latitude' // @translate
        => 'o-module-mapping:default_lat',
    'default longitude' // @translate
        => 'o-module-mapping:default_lng',
    'default latitude/longitude' // @translate
        => 'o-module-mapping:default_marker',
    'default zoom' // @translate
        => 'o-module-mapping:default_zoom',

    // From module Folksonomy.
    // But compatibility with included vocabulary curation first.
    'tag' // @translate
        => 'curation:tag',
    'tags' // @translate
        => 'curation:tag',
    // 'tag' => 'o-module-folksonomy:tag',
    // 'tags' => 'o-module-folksonomy:tag',
    'tagger' // @translate
        => 'o-module-folksonomy:tagging/o:owner',
    'tag status' // @translate
        => 'o-module-folksonomy:tagging/o:status',
    'tag date' // @translate
        => 'o-module-folksonomy:tagging/o:created',

    // From module Group.
    'group' // @translate
        => 'o:group',
    'groups' // @translate
        => 'o:group',
];
