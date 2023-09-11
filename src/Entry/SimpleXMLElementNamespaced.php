<?php declare(strict_types=1);

namespace BulkImport\Entry;

/**
 * @see https://stackoverflow.com/questions/26400993/resolve-namespaces-with-simplexml-regardless-of-structure-or-namespace/64937070#64937070
 */
class SimpleXMLElementNamespaced extends \SimpleXMLElement implements \JsonSerializable
{
    private $withPrefix = false;

    public function setWithPrefix(bool $withPrefix): self
    {
        $this->withPrefix = $withPrefix;
        return $this;
    }

    /**
     * This method is recursive even if it is not called recursively.
     *
     * @TODO XML elements order is not kept when namespaces are used (not so important since data are mapped later).
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        // Keep attributes first as in standard json-encoding/decoding.
        $array = [
            '@attributes' => [],
        ];

        $namespaces = ['' => ''] + $this->getDocNamespaces(true);

        // json encode child elements if any. group on duplicate names as an array.
        foreach ($namespaces as $prefix => $namespace) {
            foreach ($this->attributes($namespace) as $name => $attribute) {
                if ($this->withPrefix && $namespace) {
                    $name = $prefix . ':' . $name;
                }
                $array['@attributes'][$name] = $attribute;
            }

            foreach ($this->children($namespace) as $name => $element) {
                if ($this->withPrefix && $namespace) {
                    $name = $prefix . ':' . $name;
                }
                if (isset($array[$name])) {
                    if (!is_array($array[$name])) {
                        $array[$name] = [$array[$name]];
                    }
                    $array[$name][] = $element;
                } else {
                    $array[$name] = $element;
                }
            }
        }

        if (!count($array['@attributes'])) {
            unset($array['@attributes']);
        }

        // json encode non-whitespace element simplexml text values.
        $text = trim((string) $this);
        if (strlen($text)) {
            if ($array) {
                $array['@text'] = $text;
            } else {
                $array = $text;
            }
        }

        // return empty elements as NULL (self-closing or empty tags)
        if (!$array) {
            $array = '';
        }

        return $array;
    }

    public function toArray(bool $assoc = false): array
    {
        $result = json_encode($this);
        if (!$result) {
            return [];
        }
        $result = (array) json_decode($result, $assoc);
        unset($result['withPrefix']);
        return $result;
    }
}
