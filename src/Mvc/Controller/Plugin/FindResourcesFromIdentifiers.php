<?php declare(strict_types=1);

/*
 * Copyright 2017-2020 Daniel Berthereau
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software. You can use, modify and/or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software’s author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user’s attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software’s suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

namespace BulkImport\Mvc\Controller\Plugin;

use Doctrine\DBAL\Connection;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Mvc\Controller\Plugin\Api;

/**
 * Copy of the controller plugin of the module Csv Import
 *
 * @see \CSVImport\Mvc\Controller\Plugin\FindResourcesFromIdentifiers
 */
class FindResourcesFromIdentifiers extends AbstractPlugin
{
    /**
     * @var Connection
     */
    protected $connection;

    /**
     * @var Api
     */
    protected $api;

    /**
     * @param bool
     */
    protected $supportAnyValue;

    /**
     * @param Connection $connection
     * @param Api $api
     * @param bool $supportAnyValue
     */
    public function __construct(Connection $connection, Api $api, $supportAnyValue)
    {
        $this->connection = $connection;
        $this->api = $api;
        $this->supportAnyValue = $supportAnyValue;
    }

    /**
     * Find a list of resource ids from a list of identifiers (or one id).
     *
     * When there are true duplicates and case insensitive duplicates, the first
     * case sensitive is returned, else the first case insensitive resource.
     *
     * All identifiers are returned, even without id.
     *
     * @todo Manage Media source html.
     * @todo Clarify and simplify input and output.
     * @todo Check collation for some identifiers (see CleanUrl).
     *
     * @param array|string $identifiers Identifiers should be unique. If a
     * string is sent, the result will be the resource.
     * @param string|int|array $identifierName Property as integer or term,
     * "o:id", a media ingester (url or file), or an associative array with
     * multiple conditions (for media source). May be a list of identifier
     * metadata names, in which case the identifiers are searched in a list of
     * properties and/or in internal ids.
     * @param string $resourceType The resource type if any.
     * @param bool $uniqueOnly When true and there are duplicate identifiers,
     * returns an object with the list of identifiers and their count. When the
     * option is false, when there are true duplicates, it returns the first and
     * when there are case insensitive duplicates, it returns the first too.
     * This option is useless when identifiers are ids and not recommended when
     * there are multiple type of fields (doesnfor example, it doesn't work
     * totally with o:id and properties).
     * @return array|int|null|Object Associative array with the identifiers as key
     * and the ids or null as value. Order is kept, but duplicate identifiers
     * are removed. If $identifiers is a string, return directly the resource
     * id, or null. Returns standard object when there is at least one duplicated
     * identifiers in resource and the option "$uniqueOnly" is set.
     */
    public function __invoke($identifiers, $identifierName, $resourceType = null, $uniqueOnly = false)
    {
        $isSingle = !is_array($identifiers);

        if (empty($identifierName)) {
            return $isSingle ? null : [];
        }

        if ($isSingle) {
            $identifiers = [$identifiers];
        }
        $identifiers = array_unique(array_filter(array_map([$this, 'trimUnicode'], $identifiers)));
        if (empty($identifiers)) {
            return $isSingle ? null : [];
        }

        $args = $this->normalizeArgs($identifierName, $resourceType);
        if (empty($args)) {
            return $isSingle ? null : [];
        }
        list($identifierTypeNames, $resourceType, $itemId) = $args;

        $results = [
            'result' => [],
            'count' => [],
            'has_duplicate' => false,
        ];

        foreach ($identifierTypeNames as $identifierType => $identifierName) {
            $result = $this->findResources($identifierType, $identifiers, $identifierName, $resourceType, $itemId);
            if (empty($result['result'])) {
                continue;
            }

            // Keep the order and the first result.
            $results['result'] = array_replace(
                $results['result'],
                array_filter($results['result']) + array_filter($result['result'])
            );
            // TODO Count is not manageable with multiple types.
            $results['count'] = array_replace(
                $results['count'],
                array_filter($results['count']) + (empty($result['count']) ? [] : array_filter($result['count']))
            );
            $results['has_duplicate'] = $results['has_duplicate'] || $result['has_duplicate'];
        }

        if (empty($results['result'])) {
            return $isSingle ? null : [];
        }

        if ($results['has_duplicate'] && $uniqueOnly) {
            if ($isSingle) {
                return (object) ['result' => reset($results['result']), 'count' => reset($results['count'])];
            }
            unset($results['has_duplicate']);
            return (object) $results;
        }
        return $isSingle ? reset($results['result']) : $results['result'];
    }

    protected function findResources($identifierType, array $identifiers, $identifierName, $resourceType, $itemId)
    {
        switch ($identifierType) {
            case 'o:id':
                $result = $this->findResourcesFromInternalIds($identifiers, $resourceType);
                $count = array_map(function ($v) {
                    return empty($v) ? 0 : 1;
                }, $result);
                return [
                    'result' => $result,
                    'count' => $count,
                    'has_duplicate' => false,
                ];
            case 'property':
                if (!is_array($identifierName)) {
                    $identifierName = [$identifierName];
                }
                return $this->findResourcesFromPropertyIds($identifiers, $identifierName, $resourceType);
            case 'media_metadata':
                if (is_array($identifierName)) {
                    $identifierName = reset($identifierName);
                }
                return in_array($identifierName, ['o:filename', 'o:basename'])
                    ? $this->findResourcesFromMediaFilename($identifiers, $identifierName, $itemId)
                    : $this->findResourcesFromMediaMetadata($identifiers, $identifierName, null, $itemId);
            case 'media_source':
                if (is_array($identifierName)) {
                    $identifierName = reset($identifierName);
                }
                return $this->findResourcesFromMediaMetadata($identifiers, 'o:source', $identifierName, $itemId);
            default:
                return [];
        }
    }

    protected function normalizeArgs($identifierName, $resourceType)
    {
        $identifierType = null;
        $identifierTypeName = null;
        $itemId = null;

        // Process identifier metadata names as an array.
        if (is_array($identifierName)) {
            if (isset($identifierName['o:ingester'])) {
                // TODO Currently, the media source cannot be html.
                if ($identifierName['o:ingester'] === 'html') {
                    return null;
                }
                $identifierType = 'media_source';
                $identifierTypeName = $identifierName['o:ingester'];
                $resourceType = 'media';
                $itemId = empty($identifierName['o:item']['o:id']) ? null : $identifierName['o:item']['o:id'];
            } else {
                return $this->normalizeMultipleIdentifierMetadata($identifierName, $resourceType);
            }
        }
        // Next, identifierName is a string or an integer.
        elseif ($identifierName === 'o:id') {
            $identifierType = 'o:id';
            $identifierTypeName = 'o:id';
        } elseif (is_numeric($identifierName)) {
            $identifierType = 'property';
            // No check of the property id for quicker process.
            $identifierTypeName = (int) $identifierName;
        } elseif (in_array($identifierName, ['o:filename', 'o:basename', 'o:storage_id', 'o:source', 'o:sha256'])) {
            $identifierType = 'media_metadata';
            $identifierTypeName = $identifierName;
            $resourceType = 'media';
            $itemId = null;
        } elseif (in_array($identifierName, ['url', 'file', 'tile'])) {
            $identifierType = 'media_source';
            $identifierTypeName = $identifierName;
            $resourceType = 'media';
            $itemId = null;
        } else {
            $property = $this->api
                ->searchOne('properties', ['term' => $identifierName])->getContent();
            if ($property) {
                $identifierType = 'property';
                $identifierTypeName = $property->id();
            }
        }

        if (empty($identifierTypeName)) {
            return null;
        }

        if ($resourceType) {
            $resourceType = $this->normalizeResourceType($resourceType);
            if (is_null($resourceType)) {
                return null;
            }
        }

        return [
            [$identifierType => $identifierTypeName],
            $resourceType,
            $itemId,
        ];
    }

    protected function normalizeMultipleIdentifierMetadata($identifierNames, $resourceType)
    {
        $identifierTypeNames = [];
        foreach ($identifierNames as $identifierName) {
            $args = $this->normalizeArgs($identifierName, $resourceType);
            if ($args) {
                list($identifierTypeName) = $args;
                $identifierName = reset($identifierTypeName);
                $identifierType = key($identifierTypeName);
                switch ($identifierType) {
                    case 'o:id':
                    case 'media_metadata':
                    case 'media_source':
                        $identifierTypeNames[$identifierType] = $identifierName;
                        break;
                    default:
                        $identifierTypeNames[$identifierType][] = $identifierName;
                        break;
                }
            }
        }
        if (!$identifierTypeNames) {
            return null;
        }

        if ($resourceType) {
            $resourceType = $this->normalizeResourceType($resourceType);
            if (is_null($resourceType)) {
                return null;
            }
        }

        return [
            $identifierTypeNames,
            $resourceType,
            null,
        ];
    }

    protected function normalizeResourceType($resourceType)
    {
        $resourceTypes = [
            'items' => \Omeka\Entity\Item::class,
            'item_sets' => \Omeka\Entity\ItemSet::class,
            'media' => \Omeka\Entity\Media::class,
            'resources' => '',
            'resource' => '',
            'resource:item' => \Omeka\Entity\Item::class,
            'resource:itemset' => \Omeka\Entity\ItemSet::class,
            'resource:media' => \Omeka\Entity\Media::class,
            // Avoid a check and make the plugin more flexible.
            \Omeka\Entity\Item::class => \Omeka\Entity\Item::class,
            \Omeka\Entity\ItemSet::class => \Omeka\Entity\ItemSet::class,
            \Omeka\Entity\Media::class => \Omeka\Entity\Media::class,
            \Omeka\Entity\Resource::class => '',
            'o:item' => \Omeka\Entity\Item::class,
            'o:item_set' => \Omeka\Entity\ItemSet::class,
            'o:media' => \Omeka\Entity\Media::class,
            // Other resource types.
            'item' => \Omeka\Entity\Item::class,
            'item_set' => \Omeka\Entity\ItemSet::class,
            'item-set' => \Omeka\Entity\ItemSet::class,
            'itemset' => \Omeka\Entity\ItemSet::class,
            'resource:item_set' => \Omeka\Entity\ItemSet::class,
            'resource:item-set' => \Omeka\Entity\ItemSet::class,
        ];
        return isset($resourceTypes[$resourceType])
            ? $resourceTypes[$resourceType]
            : null;
    }

    protected function findResourcesFromInternalIds(array $ids, $resourceType)
    {
        $ids = array_filter(array_map('intval', $ids));
        if (empty($ids)) {
            return [];
        }

        // The api manager doesn't manage this type of search.
        $conn = $this->connection;

        $qb = $conn->createQueryBuilder();
        $expr = $qb->expr();
        $qb
            ->select('resource.id')
            ->from('resource', 'resource')
            ->addGroupBy('resource.id')
            ->addOrderBy('resource.id', 'ASC');

        $parameters = [];
        if (count($ids) === 1) {
            $qb
                ->andWhere($expr->eq('resource.id', ':id'));
            $parameters['id'] = reset($ids);
        } else {
            // Warning: there is a difference between qb / dbal and qb / orm for
            // "in" in qb, when a placeholder is used, there should be one
            // placeholder for each value for expr->in().
            $placeholders = [];
            foreach (array_values($ids) as $key => $value) {
                $placeholder = 'id_' . $key;
                $parameters[$placeholder] = $value;
                $placeholders[] = ':' . $placeholder;
            }
            $qb
                ->andWhere($expr->in('resource.id', $placeholders));
        }

        if ($resourceType) {
            $qb
                ->andWhere($expr->eq('resource.resource_type', ':resource_type'));
            $parameters['resource_type'] = $resourceType;
        }

        $qb
            ->setParameters($parameters);

        $stmt = $conn->executeQuery($qb, $qb->getParameters());
        $result = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        // Reorder the result according to the input (simpler in php and there
        // is no duplicated identifiers).
        return array_replace(array_fill_keys($ids, null), array_combine($result, $result));
    }

    protected function findResourcesFromPropertyIds(array $identifiers, array $propertyIds, $resourceType)
    {
        // The api manager doesn't manage this type of search.
        $conn = $this->connection;

        $qb = $conn->createQueryBuilder();
        $expr = $qb->expr();
        if ($this->supportAnyValue) {
            $qb
                ->select([
                    'ANY_VALUE(value.value) AS "identifier"',
                    'ANY_VALUE(value.resource_id) AS "id"',
                    'COUNT(DISTINCT(value.resource_id)) AS "count"',
                ])
                ->from('value', 'value')
                ->leftJoin('value', 'resource', 'resource', 'value.resource_id = resource.id')
                // ->andWhere($expr->in('value.property_id', $propertyIds))
                // ->andWhere($expr->in('value.value', $identifiers))
                ->addGroupBy('value.value')
                ->addOrderBy('"id"', 'ASC');
        } else {
            $qb
                ->select([
                    'value.value AS "identifier"',
                    'value.resource_id AS "id"',
                    'COUNT(DISTINCT(value.resource_id)) AS "count"',
                ])
                ->from('value', 'value')
                ->leftJoin('value', 'resource', 'resource', 'value.resource_id = resource.id')
                // ->andWhere($expr->in('value.property_id', $propertyIds))
                // ->andWhere($expr->in('value.value', $identifiers))
                ->addGroupBy('value.value')
                ->addOrderBy('"id"', 'ASC')
                ->addOrderBy('value.id', 'ASC');
        }

        $parameters = [];
        if (count($identifiers) === 1) {
            $qb
                ->andWhere($expr->eq('value.value', ':identifier'));
            $parameters['identifier'] = reset($identifiers);
        } else {
            // Warning: there is a difference between qb / dbal and qb / orm for
            // "in" in qb, when a placeholder is used, there should be one
            // placeholder for each value for expr->in().
            $placeholders = [];
            foreach (array_values($identifiers) as $key => $value) {
                $placeholder = 'value_' . $key;
                $parameters[$placeholder] = $value;
                $placeholders[] = ':' . $placeholder;
            }
            $qb
                ->andWhere($expr->in('value.value', $placeholders));
        }

        if (count($propertyIds) === 1) {
            $qb
                ->andWhere($expr->eq('value.property_id', ':property_id'));
            $parameters['property_id'] = reset($propertyIds);
        } else {
            $placeholders = [];
            foreach (array_values($propertyIds) as $key => $value) {
                $placeholder = 'property_' . $key;
                $parameters[$placeholder] = $value;
                $placeholders[] = ':' . $placeholder;
            }
            $qb
                ->andWhere($expr->in('value.property_id', $placeholders));
        }

        if ($resourceType) {
            $qb
                ->andWhere($expr->eq('resource.resource_type', ':resource_type'));
            $parameters['resource_type'] = $resourceType;
        }

        $qb
            ->setParameters($parameters);

        $stmt = $conn->executeQuery($qb, $qb->getParameters());
        // $stmt->fetchAll(\PDO::FETCH_KEY_PAIR) cannot be used, because it
        // replaces the first id by later ids in case of true duplicates.
        // Anyway, count() is needed now.
        $result = $stmt->fetchAll();

        return $this->cleanResult($identifiers, $result);
    }

    protected function findResourcesFromMediaMetadata(array $identifiers, $identifierName, $ingesterName = null, $itemId = null)
    {
        // The api manager doesn't manage this type of search.
        $conn = $this->connection;

        $mapColumns = [
            'o:sha256' => 'sha256',
            'o:source' => 'source',
            'o:storage_id' => 'storage_id',
        ];
        $column = $mapColumns[$identifierName];

        $qb = $conn->createQueryBuilder();
        $expr = $qb->expr();
        $parameters = [];

        if ($this->supportAnyValue) {
            $qb
                ->select([
                    'ANY_VALUE(media.' . $column . ') AS "identifier"',
                    'ANY_VALUE(media.id) AS "id"',
                    'COUNT(media.' . $column . ') AS "count"',
                ])
                ->from('media', 'media')
                // ->andWhere('media.source IN (' . implode(',', array_map([$conn, 'quote'], $identifiers)) . ')')
                ->addGroupBy('media.' . $column)
                ->addOrderBy('"id"', 'ASC');
        } else {
            $qb
                ->select([
                    'media.' . $column . ' AS "identifier"',
                    'media.id AS "id"',
                    'COUNT(media.' . $column . ') AS "count"',
                ])
                ->from('media', 'media')
                // ->andWhere('media.source IN (' . implode(',', array_map([$conn, 'quote'], $identifiers)) . ')')
                ->addGroupBy('media.' . $column)
                ->addOrderBy('media.id', 'ASC');
        }

        if ($ingesterName) {
            $qb
                ->andWhere('media.ingester = :ingester');
            $parameters['ingester'] = $ingesterName;
        }

        if (count($identifiers) === 1) {
            $qb
                ->andWhere($expr->eq('media.' . $mapColumns[$identifierName], ':identifier'));
            $parameters['identifier'] = reset($identifiers);
        } else {
            // Warning: there is a difference between qb / dbal and qb / orm for
            // "in" in qb, when a placeholder is used, there should be one
            // placeholder for each value for expr->in().
            $placeholders = [];
            foreach (array_values($identifiers) as $key => $value) {
                $placeholder = 'value_' . $key;
                $parameters[$placeholder] = $value;
                $placeholders[] = ':' . $placeholder;
            }
            $qb
                ->andWhere($expr->in('media.' . $mapColumns[$identifierName], $placeholders));
        }

        if ($itemId) {
            $qb
                ->andWhere($expr->eq('media.item_id', ':item_id'));
            $parameters['item_id'] = $itemId;
        }

        $qb
            ->setParameters($parameters);

        $stmt = $conn->executeQuery($qb, $qb->getParameters());
        // $stmt->fetchAll(\PDO::FETCH_KEY_PAIR) cannot be used, because it
        // replaces the first id by later ids in case of true duplicates.
        // Anyway, count() is needed now.
        $result = $stmt->fetchAll();

        return $this->cleanResult($identifiers, $result);
    }

    protected function findResourcesFromMediaFilename(array $identifiers, $identifierName, $itemId = null)
    {
        // The api manager doesn't manage this type of search.
        $conn = $this->connection;

        $qb = $conn->createQueryBuilder();
        $expr = $qb->expr();
        $parameters = [];

        // Basename allows to manage the filename with a partial path prepended,
        // that are used by module Archive Repertory.
        $isPartial = $identifierName === 'o:basename';

        if ($this->supportAnyValue) {
            $qb
                ->select([
                    // TODO There may be no extension.
                    $isPartial
                        ? 'CONCAT(SUBSTRING_INDEX(ANY_VALUE(media.storage_id), "/", -1), ".", ANY_VALUE(media.extension)) AS "identifier"'
                        : 'CONCAT(ANY_VALUE(media.storage_id), ".", ANY_VALUE(media.extension)) AS "identifier"',
                    'ANY_VALUE(media.id) AS "id"',
                    'COUNT(media.source) AS "count"',
                ])
                ->from('media', 'media')
                ->addOrderBy('"id"', 'ASC');
        } else {
            $qb
                ->select([
                    $isPartial
                        ? 'CONCAT(SUBSTRING_INDEX(media.storage_id, "/", -1), ".", media.extension) AS "identifier"'
                        : 'CONCAT(media.storage_id, ".", media.extension) AS "identifier"',
                    'media.id AS "id"',
                    'COUNT(media.source) AS "count"',
                ])
                ->from('media', 'media')
                ->addOrderBy('media.id', 'ASC');
        }

        $getStorageIdAndExtension = function ($identifier) {
            $extension = pathinfo($identifier, PATHINFO_EXTENSION);
            $storageId = mb_strlen($extension)
                ? mb_substr($identifier, 0, mb_strlen($identifier) - mb_strlen($extension) - 1)
                : $identifier;
            return [$storageId, $extension];
        };

        $prefixLike = $isPartial ? '%' : '';
        if (count($identifiers) === 1) {
            list($storageId, $extension) = $getStorageIdAndExtension(reset($identifiers));
            $andWhere = $expr->andX(
                $isPartial
                    ? $expr->like('media.storage_id', ':storage_id')
                    : $expr->eq('media.storage_id', ':storage_id'),
                $expr->eq('media.extension', ':extension')
            );
            $parameters['storage_id'] = $prefixLike . $storageId;
            $parameters['extension'] = $extension;
            $qb
                ->andWhere($andWhere);
        } else {
            $orX = [];
            foreach (array_values($identifiers) as $key => $value) {
                list($storageId, $extension) = $getStorageIdAndExtension($value);
                $placeholderStorageId = 'value_storageid_' . $key;
                $parameters[$placeholderStorageId] = $prefixLike . $storageId;
                $placeholderExtension = 'value_extension_' . $key;
                $parameters[$placeholderExtension] = $extension;
                $orX[] = $expr->andX(
                    $isPartial
                        ? $expr->like('media.storage_id', $placeholderStorageId)
                        : $expr->eq('media.storage_id', $placeholderStorageId),
                    $expr->eq('media.extension', $placeholderExtension)
                );
            }
            $qb
                ->andWhere($expr->orX(...$orX));
        }

        if ($itemId) {
            $qb
                ->andWhere($expr->eq('media.item_id', ':item_id'));
            $parameters['item_id'] = $itemId;
        }

        $qb
            ->setParameters($parameters);

        $stmt = $conn->executeQuery($qb, $qb->getParameters());
        // $stmt->fetchAll(\PDO::FETCH_KEY_PAIR) cannot be used, because it
        // replaces the first id by later ids in case of true duplicates.
        // Anyway, count() is needed now.
        $result = $stmt->fetchAll();

        return $this->cleanResult($identifiers, $result);
    }

    /**
     * Reorder the result according to the input (simpler in php and there is no
     * duplicated identifiers).
     *
     * @param array $identifiers
     * @param array $result
     * @return array
     */
    protected function cleanResult(array $identifiers, array $result)
    {
        $cleanedResult = array_fill_keys($identifiers, null);

        $count = [];

        // Prepare the lowercase result one time only.
        $lowerResult = array_map(function ($v) {
            return ['identifier' => strtolower((string) $v['identifier']), 'id' => $v['id'], 'count' => $v['count']];
        }, $result);

        foreach (array_keys($cleanedResult) as $key) {
            // Look for the first case sensitive result.
            foreach ($result as $resultValue) {
                if ($resultValue['identifier'] == $key) {
                    $cleanedResult[$key] = $resultValue['id'];
                    $count[$key] = $resultValue['count'];
                    continue 2;
                }
            }
            // Look for the first case insensitive result.
            $lowerKey = strtolower((string) $key);
            foreach ($lowerResult as $lowerResultValue) {
                if ($lowerResultValue['identifier'] == $lowerKey) {
                    $cleanedResult[$key] = $lowerResultValue['id'];
                    $count[$key] = $lowerResultValue['count'];
                    continue 2;
                }
            }
        }

        $duplicates = array_filter($count, function ($v) {
            return $v > 1;
        });

        return [
            'result' => $cleanedResult,
            'count' => $count,
            'has_duplicate' => !empty($duplicates),
        ];
    }

    /**
     * Trim all whitespaces.
     *
     * @param string $string
     * @return string
     */
    protected function trimUnicode($string)
    {
        return preg_replace('/^[\s\h\v[:blank:][:space:]]+|[\s\h\v[:blank:][:space:]]+$/u', '', (string) $string);
    }
}
