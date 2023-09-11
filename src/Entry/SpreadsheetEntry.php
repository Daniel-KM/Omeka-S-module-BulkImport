<?php declare(strict_types=1);

namespace BulkImport\Entry;

/**
 * Like Entry, but allows to manage multi-valued cells.
 *
 * @todo Allow to use multiple times the same column (require to update mapping output of the form).
 */
class SpreadsheetEntry extends BaseEntry
{
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

        // Filter duplicated and null values early.
        foreach ($this->data as &$datas) {
            $datas = array_unique(array_filter(array_map('strval', $datas), 'strlen'));
        }
        unset($datas);
    }
}
