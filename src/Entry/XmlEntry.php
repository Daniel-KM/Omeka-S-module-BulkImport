<?php declare(strict_types=1);

namespace BulkImport\Entry;

class XmlEntry extends Entry
{
    protected function init($data, array $fields, array $options): void
    {
        /** @var \XMLReaderNode $data */
        // Prepare the full list of data as array to simplify process.
        // TODO Use the new import process (see spip).
        // TODO Keep and use the original data type (as o:type) of values.
        // $simpleData = $data->asSimpleXML();
        $simpleData = $data->getSimpleXMLElement();
        $namespaces = [null] + $simpleData->getNamespaces(true);

        $array = [];
        $pnsArray = [];
        foreach ($namespaces as $prefix => $namespace) {
            $nsArray = $namespace
                ? (array) $simpleData->children($namespace)
                : (array) $simpleData->children();
            if (!count($nsArray)) {
                continue;
            }
            // Keep full term and create multivalued data everywhere.
            if ($namespace) {
                $pnsArray = [];
                // TODO Use the prefixes registered in Omeka instead of the resource ones.
                foreach ($nsArray as $name => $value) {
                    $pnsArray[$prefix . ':' . $name] = is_array($value) ? $value : [$value];
                }
            } else {
                $pnsArray = array_map(function ($v) {
                    return is_array($v) ? $v : [$v];
                }, $nsArray);
            }
            $array = array_merge_recursive($array, $pnsArray);
        }
        $this->data = $array;
   }
}
