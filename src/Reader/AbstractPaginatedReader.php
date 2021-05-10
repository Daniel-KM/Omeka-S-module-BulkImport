<?php declare(strict_types=1);

namespace BulkImport\Reader;

use ArrayIterator;

/**
 * A simple and quick paginator, since each source data is read as a whole.
 *
 * A full recursive array iterator is useless; it's mainly a paginator. Use yield? AppendGenerator?
 * TODO Implement Caching ? ArrayAccess, Seekable, Limit, Filter, OuterIterator…? Or only Reader interface?
 * TODO Simplify use of parallel readers (vocabulary and properties inside) without cloning.
 */
abstract class AbstractPaginatedReader extends AbstractReader
{
    /**
     * Limit for the loop to avoid heavy requests.
     *
     * @var int
     */
    const PAGE_LIMIT = 100;

    /**
     * @var array
     */
    protected $pageIterator;

    /**
     * @var \ArrayIterator
     */
    protected $innerIterator;

    /**
     * @var bool
     */
    protected $isValid = false;

    /**
     * @var array
     */
    protected $query = [];

    /**
     * The per-page may be different from the Omeka one when there is only one
     * page of result.
     *
     * @var int
     */
    protected $perPage = 0;

    /**
     * @var int
     */
    protected $firstPage = 0;

    /**
     * @var int
     */
    protected $lastPage = 0;

    /**
     * @var int
     */
    protected $currentPage = 0;

    /**
     * @var int
     */
    protected $currentIndex = 0;

    /**
     * @var int|null Null means not computed.
     */
    protected $totalCount = null;

    /**
     * @var ArrayIterator Any iterator, validable and countable.
     */
    protected $currentResponse;

    public function setObjectType($objectType): \BulkImport\Interfaces\Reader
    {
        $this->objectType = $objectType;
        $this->initArgs();
        $this->resetIterator();
        $this->preparePageIterator();
        return $this;
    }

    /**
     * @fixme The query should not be set after object type.
     *
     * Only basic queries are supported.
     *
     * @param string $query
     * @return \BulkImport\Reader\AbstractPaginatedReader
     */
    public function setQuery(array $query)
    {
        $this->query = $query;
        return $this;
    }

    /**
     * @param iterable $iterator Should be validable and countable too (ArrayIterator).
     * @return \BulkImport\Reader\AbstractPaginatedReader
     */
    protected function setInnerIterator(iterable $iterator)
    {
        $this->innerIterator = $iterator;
        return $this;
    }

    public function getInnerIterator()
    {
        return $this->innerIterator;
    }

    public function count(): int
    {
        return (int) $this->totalCount;
    }

    public function rewind(): void
    {
        $this->currentPage = $this->firstPage;
        $this->currentIndex = 0;
        $this->getInnerIterator()->rewind();
        $this->currentPage();
    }

    /**
     * Check if the current position is valid.
     *
     * This meaning of this method is different in some iterator classes.
     *
     * @return bool
     */
    public function valid()
    {
        if (!$this->isValid) {
            return false;
        }
        $valid = $this->getInnerIterator()->valid();
        if ($this->currentPage < $this->lastPage) {
            return true;
        }
        return $valid;
    }

    public function current()
    {
        return $this->getInnerIterator()->current();
    }

    public function key()
    {
        // The inner iterator key cannot be used, because it should be a unique
        // index for all the pages.
        return $this->currentIndex;
    }

    public function next(): void
    {
        // Key is zero-based, not count.
        // Inner key may be reset to 0 or -1.
        // if ($inner->key() + 1 >= $inner->count()) {
        ++$this->currentIndex;
        $this->perPage && $this->currentIndex % $this->perPage === 0
            ? $this->nextPage()
            : $this->getInnerIterator()->next();
    }

    public function hasNext()
    {
        $inner = $this->getInnerIterator();
        return $inner->key() + 1 >= $inner->count()
            || $this->currentPage < $this->lastPage;
    }

    public function getArrayCopy()
    {
        $array = [];
        foreach ($this as $value) {
            $array[] = $value;
        }
        return $array;
    }

    public function jsonSerialize()
    {
        return $this->getArrayCopy();
    }

    /**
     * @return bool
     */
    public function isValid(): bool
    {
        $this->initArgs();
        return true;
    }

    protected function initArgs(): void
    {
    }

    protected function resetIterator(): void
    {
        $this->isValid = false;
        $this->perPage = 0;
        $this->firstPage = 0;
        $this->lastPage = 0;
        $this->currentPage = 0;
        $this->currentIndex = 0;
        $this->totalCount = null;
        $this->setInnerIterator(new ArrayIterator([]));
    }

    /**
     * Load the current page in the inner iterator.
     */
    protected function currentPage(): void
    {
        $this->currentResponse = [];
        $this->setInnerIterator(new ArrayIterator($this->currentResponse));
    }

    /**
     * Load the next page in the inner iterator.
     */
    protected function nextPage(): void
    {
        if ($this->currentPage >= $this->lastPage) {
            $this->resetIterator();
            return;
        }
        ++$this->currentPage;
        $this->currentPage();
    }

    /**
     * Prepare the values needed to iterate by page.
     */
    protected function preparePageIterator(): void
    {
        $this->currentPage();
        if (is_null($this->currentResponse)) {
            return;
        }

        // The page is 1-based, but the index is 0-based, more common in loops.
        $this->isValid = true;
        $this->perPage = self::PAGE_LIMIT;
        $this->firstPage = 1;
        $this->lastPage = 1;
        $this->currentPage = 1;
        $this->currentIndex = 0;
        $this->totalCount = 0;
    }
}
