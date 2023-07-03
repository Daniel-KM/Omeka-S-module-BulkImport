<?php declare(strict_types=1);

namespace BulkImport\Entry;

use ArrayIterator;

class BaseEntry implements Entry
{
    const SIMPLE_DATA = false;

    protected $false = [0, false, '0', 'false', 'no', 'off', 'private', 'closed', 'none'];
    protected $true = [1, true, '1', 'true', 'yes', 'on', 'public', 'open'];
    protected $null = [null, 'null'];

    /**
     * @var array|\Traversable
     */
    protected $data = [];

    /**
     * The index is 1-based.
     *
     * @var int
     */
    protected $index = 0;

    /**
     * @var array
     */
    protected $fields = [];

    /**
     * @var array
     */
    protected $options = [];

    /**
     * @var bool
     */
    protected $valid;

    /**
     * @var \BulkImport\Stdlib\MetaMapper|null
     */
    protected $metaMapper;

    public function __construct($data, int $index, array $fields, array $options = [])
    {
        $this->data = $data;
        $this->index = $index;
        $this->fields = $fields;
        $this->options = $options;
        $this->init();
    }

    protected function init(): void
    {
        if (!empty($this->options['is_formatted'])) {
            return;
        }

        if (!empty($this->options['metaMapper'])) {
            $this->metaMapper = $this->options['metaMapper'];
        }

        if (is_null($this->data)) {
            $this->data = [];
            return;
        }

        // Don't keep data that are not attached to a field.
        // Avoid issue when number of data is greater than number of fields.
        // TODO Collect data without field as garbage (for empty field "")?
        $datas = array_slice($this->data, 0, count($this->fields), true);
        $datas = array_map([$this, 'trimUnicode'], $datas);

        // The set fields should be kept set (for array_key_exists).
        $this->data = array_fill_keys($this->fields, []);

        foreach ($datas as $i => $value) {
            $this->data[$this->fields[$i]][] = $value;
        }

        // Filter duplicated and null values.
        foreach ($this->data as &$datas) {
            $datas = is_null($datas) ? [] : array_unique(array_filter($datas, 'strlen'));
        }
    }

    public function isEmpty(): bool
    {
        return !is_array($this->data) || !count(array_filter($this->data, function ($v) {
            return is_array($v)
                ? count(array_filter($v, function ($w) {
                    return is_array($w)
                        ? count($w)
                        : strlen((string) $w) > 0;
                }))
                : strlen((string) $v);
        }));
    }

    public function index(): int
    {
        return $this->index;
    }

    public function getArrayCopy(): array
    {
        return $this->data;
    }

    public function valuesFromMap(array $map): array
    {
        return [];
    }

    public function getIterator(): \Traversable
    {
        return new ArrayIterator($this->data);
    }

    public function offsetExists($offset): bool
    {
        return array_key_exists($offset, $this->data);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        if (array_key_exists($offset, $this->data)) {
            return $this->data[$offset];
        }
    }

    public function offsetSet($offset, $value): void
    {
        throw new \Exception('Modification forbidden'); // @translate
    }

    public function offsetUnset($offset): void
    {
        throw new \Exception('Modification forbidden'); // @translate
    }

    #[\ReturnTypeWillChange]
    public function current()
    {
        return current($this->data);
    }

    #[\ReturnTypeWillChange]
    public function key()
    {
        return key($this->data);
    }

    public function next(): void
    {
        $this->valid = next($this->data) !== false;
    }

    public function rewind(): void
    {
        reset($this->data);
        $this->valid = true;
    }

    public function valid(): bool
    {
        return $this->valid;
    }

    public function count(): int
    {
        return count($this->data);
    }

    public function jsonSerialize(): array
    {
        return $this->data;
    }

    public function __toString()
    {
        return print_r($this->data, true);
    }

    /**
     * Trim all whitespaces.
     *
     * @param string $string
     */
    protected function trimUnicode($string): string
    {
        if (is_object($string) && !method_exists($string, '__toString')) {
            if (!($string instanceof \DateTime)) {
                throw new \Omeka\Mvc\Exception\RuntimeException(
                    sprintf('Value of class "%s" cannot be converted to string.', get_class($string)) // @translate
                );
            }
            $string = $string->format('Y-m-d H:i:s');
        } else {
            $string = (string) $string;
        }
        return preg_replace('/^[\h\v\s[:blank:][:space:]]+|[\h\v\s[:blank:][:space:]]+$/u', '', $string);
    }
}
