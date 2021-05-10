<?php declare(strict_types=1);

namespace BulkImport\Entry;

/**
 * Like Entry, but allows to manage multi-valued cells.
 */
class SpreadsheetEntry extends Entry
{
    protected function init($data, array $fields, array $options): void
    {
        // The standard process is used when there is no separator.
        if (!isset($options['separator']) || !strlen((string) $options['separator'])) {
            parent::init($fields, $data, $options);
            return;
        }

        // Don't keep data that are not attached to a field.
        $data = array_slice($data, 0, count($fields), true);

        // Fill empty values, so no check needed for duplicated headers (for
        // example multiple creators with one creator by column, with the same
        // header).
        foreach ($data as $i => $value) {
            $this->data[$fields[$i]] = [];
        }

        // Fill each key with multivalued values.
        $separator = (string) $options['separator'];
        foreach ($data as $i => $value) {
            if (is_object($value) && !method_exists($value, '__toString')) {
                if (!($value instanceof \DateTime)) {
                    throw new \Omeka\Mvc\Exception\RuntimeException(
                        sprintf('Value of class "%s" cannot be converted to string.', get_class($value)) // @translate
                    );
                }
                $value = $value->format('Y-m-d H:i:s');
            } else {
                $value = (string) $value;
            }
            $this->data[$fields[$i]] = array_merge(
                $this->data[$fields[$i]],
                array_map([$this, 'trimUnicode'], explode($separator, $value))
            );
        }
    }
}
