<?php declare(strict_types=1);

namespace BulkImport\Service\ViewHelper;

use BulkImport\View\Helper\CustomVocabBaseType;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

/**
 * @see \AdvancedResourceTemplate\\View\Helper\CustomVocabBaseType
 * @see \BulkEdit\View\Helper\CustomVocabBaseType
 * @see \BulkExport\View\Helper\CustomVocabBaseType
 * @see \BulkImport\View\Helper\CustomVocabBaseType
 * Used in Contribute.
 */
class CustomVocabBaseTypeFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $hasCustomVocab = ($module = $services->get('Omeka\ModuleManager')->getModule('CustomVocab')) && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE;
        if ($hasCustomVocab) {
            /*
             $sql = <<<'SQL'
             SELECT
                 GROUP_CONCAT(DISTINCT CASE WHEN `terms` != "" THEN `id` ELSE NULL END ORDER BY `id` SEPARATOR " ") AS 'literal',
                 GROUP_CONCAT(DISTINCT CASE WHEN `item_set_id` IS NOT NULL THEN `id` ELSE NULL END ORDER BY `id` SEPARATOR " ") AS 'resource',
                 GROUP_CONCAT(DISTINCT CASE WHEN `uris` != "" THEN `id` ELSE NULL END ORDER BY `id` SEPARATOR " ") AS 'uri'
             FROM `custom_vocab`;
             SQL;
             $customVocabsByType = $site->get('Omeka\Connection')->executeQuery($sql)->fetchAssociative() ?: ['literal' => '', 'resource' => '', 'uri' => ''];
             */
            $sql = <<<'SQL'
SELECT
    `id` AS id,
    CASE
        WHEN `uris` != "" THEN "uri"
        WHEN `item_set_id` IS NOT NULL THEN "resource"
        ELSE "literal"
    END AS "type"
FROM `custom_vocab`
ORDER BY `id`;
SQL;
            $customVocabBaseTypes = $services->get('Omeka\Connection')->executeQuery($sql)->fetchAllKeyValue() ?: [];
        } else {
            $customVocabBaseTypes = null;
        }
        return new CustomVocabBaseType(
            $customVocabBaseTypes
        );
    }
}
