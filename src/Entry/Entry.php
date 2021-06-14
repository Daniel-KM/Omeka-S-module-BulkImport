<?php declare(strict_types=1);

namespace BulkImport\Entry;

use ArrayIterator;
use BulkImport\Interfaces\Entry as EntryInterface;

class Entry implements EntryInterface
{
    const SIMPLE_DATA = false;

    /**
     * @var array|\Traversable
     */
    protected $data = [];

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

    public function __construct($data, array $fields, array $options = [])
    {
        $this->data = $data;
        $this->fields = $fields;
        $this->options = $options;
        $this->init();
    }

    protected function init(): void
    {
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
        return !count(array_filter($this->data, function ($v) {
            return is_array($v)
                ? count(array_filter($v, function ($w) {
                    return strlen((string) $w) > 0;
                }))
                : strlen((string) $v);
        }));
    }

    public function getArrayCopy(): array
    {
        return $this->data;
    }

    public function getIterator()
    {
        return new ArrayIterator($this->data);
    }

    public function offsetExists($offset)
    {
        return array_key_exists($offset, $this->data);
    }

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

    public function current()
    {
        return current($this->data);
    }

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

    public function valid()
    {
        return $this->valid;
    }

    public function count()
    {
        return count($this->data);
    }

    public function jsonSerialize()
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
     * @return string
     */
    protected function trimUnicode($string)
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
