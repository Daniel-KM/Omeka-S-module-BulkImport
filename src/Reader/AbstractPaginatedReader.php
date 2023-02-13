<?php declare(strict_types=1);

namespace BulkImport\Reader;

use ArrayIterator;

/**
 * A simple and quick paginator, since each source data is read as a whole.

 * @todo Replace with an IteratorIterator or AppendIterator (a iterator can be appended in a foreach loop).
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
     * The media type of the content.
     *
     * @var string
     */
    protected $mediaType;

    /**
     * The charset of the content.
     *
     * @var string
     */
    protected $charset;

    /**
     * @todo Remove this value, not used (urls are fetched).
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

    public function setObjectType($objectType): self
    {
        $this->objectType = $objectType;
        $this->initArgs();
        $this->resetIterator();
        $this->preparePageIterator();
        return $this;
    }

    public function isValid(): bool
    {
        $this->initArgs();
        return true;
    }

    /**
     * @fixme There is an issue with the sql iterator when there is no row.
     */
    #[\ReturnTypeWillChange]
    public function current()
    {
        return $this->getInnerIterator()->current();
    }

    #[\ReturnTypeWillChange]
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
    public function valid(): bool
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

    public function count(): int
    {
        return (int) $this->totalCount;
    }

    public function getArrayCopy(): array
    {
        $array = [];
        foreach ($this as $value) {
            $array[] = $value;
        }
        return $array;
    }

    public function jsonSerialize(): array
    {
        return $this->getArrayCopy();
    }

    public function hasNext(): bool
    {
        $inner = $this->getInnerIterator();
        return $inner->key() + 1 >= $inner->count()
            || $this->currentPage < $this->lastPage;
    }

    public function getInnerIterator()
    {
        return $this->innerIterator;
    }

    /**
     * @todo Merge with method initArgs().
     */
    protected function initializeReader(): self
    {
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

    /**
     * This method is called from the method setObjectType() and isValid().
     *
     * @todo Use method initializeReader().
     */
    protected function initArgs(): self
    {
        return $this;
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

        $this->perPage = self::PAGE_LIMIT;
        $this->totalCount = 0;
        $this->firstPage = 1;
        // At least the first page.
        $this->lastPage = 1;
        // The page is 1-based, but the index is 0-based, more common in loops.
        $this->currentPage = 1;
        $this->currentIndex = 0;
        $this->isValid = true;
    }
}
