<?php declare(strict_types=1);

namespace BulkImport\Interfaces;

use Laminas\ServiceManager\ServiceLocatorInterface;

/**
 * A reader returns metadata and files data.
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

    /**
     * Check if the params of the reader are valid, for example the filepath.
     *
     * @return bool
     */
    public function isValid(): bool;

    /**
     * Get the last error message, in particular to know why reader is invalid.
     *
     * @return string|null
     */
    public function getLastErrorMessage();

    /**
     * @param string $objectType An Omeka api key like "items", "vocabularies"…
     */
    public function setObjectType($objectType): \BulkImport\Interfaces\Reader;

    /**
     * Allow to limit results.
     */
    public function setFilters(?array $filters): \BulkImport\Interfaces\Reader;

    /**
     * Prepare the order of  the result, for example a column for sql.
     */
    public function setOrder(?string $by, $dir = 'ASC'): \BulkImport\Interfaces\Reader;

    /**
     * List of fields used in the input, for example the first spreadsheet row.
     *
     * It allows to do the mapping in the user interface.
     *
     * Note that these available fields should not be the first output when
     * `rewind()` is called.
     */
    public function getAvailableFields(): array;

    /**
     * {@inheritDoc}
     * @see \Iterator::current()
     *
     * @return Entry|mixed
     */
    public function current();

    /**
     * Get the number of entries that will be read to be converted in resources.
     *
     * {@inheritDoc}
     * @see \Countable::count()
     */
    public function count();
}
