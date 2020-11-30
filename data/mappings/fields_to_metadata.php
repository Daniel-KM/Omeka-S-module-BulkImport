<?php declare(strict_types=1);
/**
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
    'owner' => 'o:owner',
    'owner email' => 'o:email',
    'id' => 'o:id',
    'internal id' => 'o:id',
    'resource' => 'o:id',
    'resources' => 'o:id',
    'resource id' => 'o:id',
    'resource identifier' => 'dcterms:identifier',
    'record' => 'o:id',
    'records' => 'o:id',
    'record id' => 'o:id',
    'record identifier' => 'dcterms:identifier',
    'resource type' => 'resource_type',
    'record type' => 'resource_type',
    '@type' => 'resource_type',
    'resource template' => 'o:resource_template',
    'item type' => 'o:resource_class',
    'resource class' => 'o:resource_class',
    'visibility' => 'o:is_public',
    'public' => 'o:is_public',
    'item set' => 'o:item_set',
    'item sets' => 'o:item_set',
    'collection' => 'o:item_set',
    'collections' => 'o:item_set',
    'item set id' => 'o:item_set[o:id]',
    'collection id' => 'o:item_set[o:id]',
    'item set identifier' => 'o:item_set[dcterms:identifier]',
    'collection identifier' => 'o:item_set[dcterms:identifier]',
    'item set title' => 'o:item_set[dcterms:title]',
    'collection title' => 'o:item_set[dcterms:title]',
    'collection name' => 'o:item_set[dcterms:title]',
    'additions' => 'o:is_open',
    'open' => 'o:is_open',
    'openness' => 'o:is_open',
    'item' => 'o:item',
    'items' => 'o:item',
    'item id' => 'o:item[o:id]',
    'item identifier' => 'o:item[dcterms:identifier]',
    'media' => 'o:media',
    'media id' => 'o:media[o:id]',
    'media source' => 'o:source',
    'media filename' => 'o:filename',
    'media basename' => 'o:basename',
    'media storage' => 'o:storage_id',
    'media storage id' => 'o:storage_id',
    'media hash' => 'o:sha256',
    'media sha256' => 'o:sha256',
    'media identifier' => 'o:media[dcterms:identifier]',
    'media title' => 'o:media[dcterms:title]',
    'media url' => 'url',
    'media html' => 'html',
    'media public' => 'o:media[o:is_public]',
    'media source' => 'o:source',
    'file source' => 'o:source',
    'file storage' => 'o:storage_id',
    'file hash' => 'o:sha256',
    'file sha256' => 'o:sha256',
    'storage' => 'o:storage_id',
    'storage id' => 'o:storage_id',
    'basename' => 'o:basename',
    'hash' => 'o:sha256',
    'sha256' => 'o:sha256',
    'html' => 'html',
    'iiif' => 'iiif',
    'iiif image' => 'iiif',
    'oembed' => 'oembed',
    'youtube' => 'youtube',
    'url' => 'url',
    'user' => 'o:user',
    'name' => 'o:name',
    'display name' => 'o:name',
    'username' => 'o:name',
    'user name' => 'o:name',
    'email' => 'o:email',
    'user email' => 'o:email',
    'role' => 'o:role',
    'user role' => 'o:role',
    'active' => 'o:is_active',
    'is active' => 'o:is_active',

    // Automapping from external modules.

    // A file can be a url or a local address (for sideload).
    'file' => 'file',
    'files' => 'file',
    'filename' => 'file',
    'filenames' => 'file',
    'upload' => 'file',
    'sideload' => 'file',
    'file sideload' => 'file',
    // When used with module Archive Repertory.
    'base file name' => 'o:basename',
    'base filename' => 'o:basename',

    // A tile for image server.
    'tile' => 'tile',
    'media tile' => 'tile',

    // From module Mapping.
    'o-module-mapping:mapping' => 'o-module-mapping:bounds',
    'o-module-mapping:bounds' => 'o-module-mapping:bounds',
    'bounds' => 'o-module-mapping:bounds',
    'mapping bounds' => 'o-module-mapping:bounds',

    'o-module-mapping:marker' => 'o-module-mapping:marker',
    'o-module-mapping:lat' => 'o-module-mapping:lat',
    'o-module-mapping:lng' => 'o-module-mapping:lng',
    'o-module-mapping:label' => 'o-module-mapping:label',
    'latitude' => 'o-module-mapping:lat',
    'longitude' => 'o-module-mapping:lng',
    'latitude/longitude' => 'o-module-mapping:marker',

    'default latitude' => 'o-module-mapping:default_lat',
    'default longitude' => 'o-module-mapping:default_lng',
    'default latitude/longitude' => 'o-module-mapping:default_marker',
    'default zoom' => 'o-module-mapping:default_zoom',

    // From module Folksonomy.
    'tag' => 'o-module-folksonomy:tag',
    'tags' => 'o-module-folksonomy:tag',
    'tagger' => 'o-module-folksonomy:tagging[o:owner]',
    'tag status' => 'o-module-folksonomy:tagging[o:status]',
    'tag date' => 'o-module-folksonomy:tagging[o:created]',

    // From module Group.
    'group' => 'o:group',
    'groups' => 'o:group',
];
