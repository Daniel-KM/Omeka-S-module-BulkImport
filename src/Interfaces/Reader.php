<?php
namespace BulkImport\Interfaces;

use Zend\ServiceManager\ServiceLocatorInterface;

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
     *
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function __construct(ServiceLocatorInterface $services);

    /**
     * @return string
     */
    public function getLabel();

    /**
     * Check if the params of the reader are valid, for example the filepath.
     *
     * @return bool
     */
    public function isValid();

    /**
     * Get the last error message, in particular to know why reader is invalid.
     *
     * @return string
     */
    public function getLastErrorMessage();

    /**
     * @param string$objectType An Omeka api key like "items", "vocabularies"…
     * @return self
     */
    public function setObjectType($objectType);

    /**
     * List of fields used in the input, for example the first spreadsheet row.
     *
     * It allows to do the mapping in the user interface.
     *
     * Note that these available fields should not be the first output when
     * `rewind()` is called.
     *
     * @return array
     */
    public function getAvailableFields();

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
