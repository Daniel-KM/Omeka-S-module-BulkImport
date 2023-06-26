<?php declare(strict_types=1);

namespace BulkImport\Entry;

/**
 * Like Entry, but allows to manage multi-valued cells.
 *
 * @todo Make Entry and SpreadsheetEntry output only Omeka array data, like XmlEntry (so move some processor process into reader).
 * @todo Use MetaMapper inside SpreadsheetEntry? Useless, it is mainly processor-driven?
 * @todo Allow to use multiple times the same column (require to update mapping output of the form).
 */
class SpreadsheetEntry extends BaseEntry
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
        unset($datas);

        // TODO Use metamapper early with metamapperconfig.
        // Apply map modification if any and set "is_formatted'.
    }

    public function valuesFromMap(array $map): array
    {
        if (isset($map['from']['index_numeric'])) {
            $values = isset($this->fields[$map['from']['index_numeric']])
                ? $this->offsetGet($this->fields[$map['from']['index_numeric']])
                : [];
        } elseif (isset($map['from']['index'])) {
            $values = $this->offsetGet($map['from']['index']);
        } elseif (isset($map['from']['path'])) {
            $values = $this->offsetGet($map['from']['path']);
        } else {
            return [];
        }
        if (!count($values)
            || empty($map['mod'])
            || !$this->metaMapper
            || !empty($this->options['is_formatted'])
        ) {
            return $values;
        }
        // TODO Pass the entry to set the context for complex transformation.
        foreach ($values as &$value) {
            $value = $this->metaMapper->convertString($value, $map);
        }
        return $values;
    }
}
