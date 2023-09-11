<?php declare(strict_types=1);

namespace BulkImport\Reader;

use Laminas\Log\Logger;
use Laminas\ServiceManager\ServiceLocatorInterface;

/**
 * A reader returns metadata and files data by resource name for the mapper.
 *
 * It can have a config (implements Configurable) and parameters (implements
 * Parametrizable).
 */
interface Reader extends \Iterator, \Countable
{
    /**
     * Reader constructor.
     */
    public function __construct(ServiceLocatorInterface $services);

    /**
     * Label of the reader.
     */
    public function getLabel(): string;

    public function setLogger(Logger $logger): self;

    /**
     * Check if the params of the reader are valid, for example the filepath.
     */
    public function isValid(): bool;

    /**
     * Get the last error message, in particular to know why reader is invalid.
     *
     * @deprecated
     * @todo Use ErrorStore.
     */
    public function getLastErrorMessage(): ?string;

    /**
     * Set the current resource name to read.
     *
     * @param string $resourceName An Omeka api key like items, vocabularies…
     */
    public function setResourceName(string $resourceName): self;

    /**
     * Allow to limit results.
     */
    public function setFilters(?array $filters): self;

    /**
     * Prepare the order of  the result, for example a column for sql.
     *
     * @param string|array $by
     */
    public function setOrders($by, $dir = 'ASC'): self;

    /**
     * List of fields used in the input, for example the first spreadsheet row.
     *
     * It allows to do the mapping in the user interface.
     *
     * Note that these available fields should not be the first output when
     * `rewind()` is called, in particular for spreadsheet.
     */
    public function getAvailableFields(): array;

    /**
     * {@inheritDoc}
     * @see \Iterator::current()
     *
     * @return \BulkImport\Entry\Entry|mixed
     */
    #[\ReturnTypeWillChange]
    public function current();

    /**
     * Get the number of entries that will be read to be converted in resources.
     *
     * {@inheritDoc}
     * @see \Countable::count()
     */
    public function count(): int;
}
