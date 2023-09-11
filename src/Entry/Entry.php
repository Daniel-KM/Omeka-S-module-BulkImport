<?php declare(strict_types=1);

namespace BulkImport\Entry;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use SimpleXMLElement;

/**
 * Represent a resource in s specific format.
 *
 * The iterator allows to iterate on each metadata. For example an entry may be
 * a row of a spreadsheet, so each metadata is a column, that is multivalued.
 * It may be a node of an xml source, where each metadata is a child node.
 * This feature may is no more available for some formats for now (xml).
 *
 * @todo Extend Entry from ArrayObject (or ArrayIterator)?
 * @todo Remove iterator since it is managed by metaMapper.
 *
 * \IteratorAggregate implies \Traversable.
 */
interface Entry extends IteratorAggregate, ArrayAccess, Countable, JsonSerializable
{
    /**
     * Indicates that the entry has no content, so probably to be skipped.
     */
    public function isEmpty(): bool;

    /**
     * Get the full entry as an array, instead of value by value.
     *
     * Some format cannot be converted into an array for now (xml).
     *
     * Generally the same output than jsonSerialize().
     *
     * @todo Convert xml to nested array to allow mapping?
     */
    public function getArrayCopy(): array;

    /**
     * Get the full entry as xml.
     *
     * It may have been processed internally (xml).
     *
     * Some formats cannot be converted into an xml for now (array).
     *
     * @todo Convert nested array to xml to simplify mapping.
     */
    public function getXmlCopy(): ?SimpleXMLElement;

    /**
     * @return int The 1-based source index of the resource.
     * It should be the same than the key output via a loop on the reader.
     */
    public function index(): int;

    /**
     * {@inheritDoc}
     * @see \Iterator::current()
     *
     * @return array List of values of the current field or column of the entry.
     */
    #[\ReturnTypeWillChange]
    public function current();
}
