<?php declare(strict_types=1);

namespace BulkImport\Entry;

/**
 * Represent a resource in s specific format.
 *
 * The iterator allows to iterate on each metadata. For example an entry may be
 * a row of a spreadsheet, so each metadata is a column, that is multivalued.
 * It may be a node of an xml source, where each metadata is a child node.
 *
 * @todo Extend Entry from ArrayObject (or ArrayIterator)?
 * \IteratorAggregate implies \Traversable.
 */
interface Entry extends \IteratorAggregate, \ArrayAccess, \Countable, \JsonSerializable
{
    /**
     * Indicates that the entry has no content, so probably to be skipped.
     *
     * @return bool
     */
    public function isEmpty(): bool;

    /**
     * Get the full entry as an array, instead of value by value.
     *
     * Generally the same output than jsonSerialize().
     *
     * @return array
     */
    public function getArrayCopy(): array;

    /**
     * @return int The 1-based source index of the resource.
     * It should be the same than the key output via a loop on the reader.
     */
    public function index(): int;

    /**
     * {@inheritDoc}
     * @see \Iterator::current()
     *
     * @return array The list of values for the current field of the entry.
     */
    #[\ReturnTypeWillChange]
    public function current();
}
