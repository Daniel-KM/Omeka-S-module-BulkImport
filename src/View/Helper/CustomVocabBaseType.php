<?php declare(strict_types=1);

namespace BulkImport\View\Helper;

use Laminas\View\Helper\AbstractHelper;

/**
 * @see \AdvancedResourceTemplate\\View\Helper\CustomVocabBaseType
 * @see \BulkEdit\View\Helper\CustomVocabBaseType
 * @see \BulkExport\View\Helper\CustomVocabBaseType
 * @see \BulkImport\View\Helper\CustomVocabBaseType
 * Used in Contribute.
 */
class CustomVocabBaseType extends AbstractHelper
{
    /**
     * @var ?array
     */
    protected $customVocabBaseTypes;

    public function __construct(?array $customVocabBaseTypes)
    {
        $this->customVocabBaseTypes = $customVocabBaseTypes;
    }

    /**
     * Get the sub type of a customvocab ("literal", "resource", "uri") or all.
     *
     * @return array|string|null
     */
    public function __invoke($customVocabId = null)
    {
        if (is_null($this->customVocabBaseTypes)) {
            return is_null($customVocabId) ? [] : null;
        }
        return is_null($customVocabId)
            ? $this->customVocabBaseTypes ?? []
            : ($this->customVocabBaseTypes[(int) $customVocabId] ?? null);
    }
}
