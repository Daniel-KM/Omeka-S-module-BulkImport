<?php declare(strict_types=1);

namespace BulkImport\Entry;

/**
 * Like Entry, but allows to manage multi-valued cells.
 *
 * @todo Make Entry and SpreadsheetEntry output only Omeka array data, like XmlEntry (so move some processor process into reader).
 */
class SpreadsheetEntry extends Entry
{
    const SIMPLE_DATA = true;

    protected function init(): void
    {
        parent::init();

        // The standard process is used when there is no separator.
        if (!isset($this->options['separator']) || !strlen((string) $this->options['separator'])) {
            return;
        }

        // Explode each value.
        $separator = (string) $this->options['separator'];
        foreach ($this->data as $key => $values) {
            if (!count($values)) {
                continue;
            }
            if (count($values) === 1) {
                $value = reset($values);
                if (mb_strpos($value, $separator) !== false) {
                    $this->data[$key] = array_map([$this, 'trimUnicode'], explode($separator, $value));
                }
                continue;
            }
            $vs = [];
            foreach ($values as $value) {
                $vs[] = mb_strpos($value, $separator) === false
                    ? [$value]
                    : array_map([$this, 'trimUnicode'], explode($separator, $value));
            }
            $this->data[$key] = array_merge(...$vs);
        }

        // Filter duplicated and null values.
        foreach ($this->data as &$datas) {
            $datas = array_unique(array_filter(array_map('strval', $datas), 'strlen'));
        }
    }
}
