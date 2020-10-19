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

use Laminas\Log\Logger;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Laminas\ServiceManager\ServiceLocatorInterface;

/**
 * Copy of the controller plugin of the module Csv Import
 *
 * @see \CSVImport\Mvc\Controller\Plugin\FindResourcesFromIdentifiers
 */
class Bulk extends AbstractPlugin
{
    /**
     * @var ServiceLocatorInterface
     */
    protected $services;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var \Omeka\Mvc\Controller\Plugin\Api
     */
    protected $api;

    /**
     * @var \BulkImport\Mvc\Controller\Plugin\FindResourcesFromIdentifiers
     */
    protected $findResourcesFromIdentifiers;

    /**
     * @var bool
     */
    protected $allowDuplicateIdentifiers = false;

    /**
     * @var array|string
     */
    protected $identifierNames = [
        'o:id',
        'dcterms:identifier',
    ];

    /**
     * @var array
     */
    protected $properties;

    /**
     * @var array
     */
    protected $resourceClasses;

    /**
     * @var array
     */
    protected $resourceTemplates;

    /**
     * @var array
     */
    protected $dataTypes;

    /**
     * @param ServiceLocatorInterface $services
     */
    public function __construct(ServiceLocatorInterface $services)
    {
        $this->services = $services;
        $this->logger = $services->get('Omeka\Logger');

        // The controller is not yet available here.
        $pluginManager = $services->get('ControllerPluginManager');
        $this->api = $pluginManager->get('api');
        $this->findResourcesFromIdentifiers = $pluginManager
            // Use class name to use it even when CsvImport is installed.
            ->get(\BulkImport\Mvc\Controller\Plugin\FindResourcesFromIdentifiers::class);
    }

    /**
     * Manage various methods to manage bulk import.
     *
     * @return self
     */
    public function __invoke()
    {
        return $this;
    }

    /**
     * Check if a string or a id is a managed term.
     *
     * @param string|int $termOrId
     * @return bool
     */
    public function isPropertyTerm($termOrId)
    {
        return $this->getPropertyId($termOrId) !== null;
    }

    /**
     * Get a property id by term or id.
     *
     * @param string|int $termOrId
     * @return int|null
     */
    public function getPropertyId($termOrId)
    {
        $ids = $this->getPropertyIds();
        return is_numeric($termOrId)
            ? (array_search($termOrId, $ids) ? $termOrId : null)
            : ($ids[$termOrId] ?? null);
    }

    /**
     * Get a property term by term or id.
     *
     * @param string|int $termOrId
     * @return string|null
     */
    public function getPropertyTerm($termOrId)
    {
        $ids = $this->getPropertyIds();
        return is_numeric($termOrId)
            ? (array_search($termOrId, $ids) ?: null)
            : (array_key_exists($termOrId, $ids) ? $termOrId : null);
    }

    /**
     * Get all property ids by term.
     *
     * @return array Associative array of ids by term.
     */
    public function getPropertyIds()
    {
        if (isset($this->properties)) {
            return $this->properties;
        }

        $connection = $this->services->get('Omeka\Connection');
        $qb = $connection->createQueryBuilder();
        $qb
            ->select([
                'DISTINCT property.id AS id',
                'CONCAT(vocabulary.prefix, ":", property.local_name) AS term',
                // Only the two first selects are needed, but some databases
                // require "order by" or "group by" value to be in the select.
                'vocabulary.id',
                'property.id',
            ])
            ->from('property', 'property')
            ->innerJoin('property', 'vocabulary', 'vocabulary', 'property.vocabulary_id = vocabulary.id')
            ->orderBy('vocabulary.id', 'asc')
            ->addOrderBy('property.id', 'asc')
            ->addGroupBy('property.id')
        ;
        $stmt = $connection->executeQuery($qb);
        // Fetch by key pair is not supported by doctrine 2.0.
        $this->properties = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $this->properties = array_column($this->properties, 'id', 'term');
        return $this->properties;
    }

    /**
     * Get all property terms by id.
     *
     * @return array Associative array of terms by id.
     */
    public function getPropertyTerms()
    {
        return array_flip($this->getPropertyIds());
    }

    /**
     * Check if a string or a id is a resource class.
     *
     * @param string|int $termOrId
     * @return bool
     */
    public function isResourceClass($termOrId)
    {
        return $this->getResourceClassId($termOrId) !== null;
    }

    /**
     * Get a resource class by term or by id.
     *
     * @param string|int $termOrId
     * @return int|null
     */
    public function getResourceClassId($termOrId)
    {
        $ids = $this->getResourceClassIds();
        return is_numeric($termOrId)
            ? (array_search($termOrId, $ids) ? $termOrId : null)
            : ($ids[$termOrId] ?? null);
    }

    /**
     * Get a resource class term by term or id.
     *
     * @param string|int $termOrId
     * @return string|null
     */
    public function getResourceClassTerm($termOrId)
    {
        $ids = $this->getResourceClassIds();
        return is_numeric($termOrId)
            ? (array_search($termOrId, $ids) ?: null)
            : (array_key_exists($termOrId, $ids) ? $termOrId : null);
    }

    /**
     * Get all resource classes by term.
     *
     * @return array Associative array of ids by term.
     */
    public function getResourceClassIds()
    {
        if (isset($this->resourceClasses)) {
            return $this->resourceClasses;
        }

        $connection = $this->services->get('Omeka\Connection');
        $qb = $connection->createQueryBuilder();
        $qb
            ->select([
                'DISTINCT resource_class.id AS id',
                "CONCAT(vocabulary.prefix, ':', resource_class.local_name) AS term",
                // Only the two first selects are needed, but some databases
                // require "order by" or "group by" value to be in the select.
                'vocabulary.id',
                'resource_class.id',
            ])
            ->from('resource_class', 'resource_class')
            ->innerJoin('resource_class', 'vocabulary', 'vocabulary', 'resource_class.vocabulary_id = vocabulary.id')
            ->orderBy('vocabulary.id', 'asc')
            ->addOrderBy('resource_class.id', 'asc')
            ->addGroupBy('resource_class.id')
        ;
        $stmt = $connection->executeQuery($qb);
        // Fetch by key pair is not supported by doctrine 2.0.
        $this->resourceClasses = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $this->resourceClasses = array_column($this->resourceClasses, 'id', 'term');
        return $this->resourceClasses;
    }

    /**
     * Get all resource class terms by id.
     *
     * @return array Associative array of terms by id.
     */
    public function getResourceClassTerms()
    {
        return array_flip($this->getResourceClassIds());
    }

    /**
     * Check if a string or a id is a resource template.
     *
     * @param string|int $labelOrId
     * @return bool
     */
    public function isResourceTemplate($labelOrId)
    {
        return $this->getResourceTemplateId($labelOrId) !== null;
    }

    /**
     * Get a resource template by label or by id.
     *
     * @param string|int $labelOrId
     * @return int|null
     */
    public function getResourceTemplateId($labelOrId)
    {
        $ids = $this->getResourceTemplateIds();
        return is_numeric($labelOrId)
            ? (array_search($labelOrId, $ids) ? $labelOrId : null)
            : ($ids[$labelOrId] ?? null);
    }

    /**
     * Get a resource template label by label or id.
     *
     * @param string|int $labelOrId
     * @return string|null
     */
    public function getResourceTemplateLabel($labelOrId)
    {
        $ids = $this->getResourceTemplateIds();
        return is_numeric($labelOrId)
            ? (array_search($labelOrId, $ids) ?: null)
            : (array_key_exists($labelOrId, $ids) ? $labelOrId : null);
    }

    /**
     * Get all resource templates by label.
     *
     * @return array Associative array of ids by label.
     */
    public function getResourceTemplateIds()
    {
        if (isset($this->resourceTemplates)) {
            return $this->resourceTemplates;
        }

        $connection = $this->services->get('Omeka\Connection');
        $qb = $connection->createQueryBuilder();
        $qb
            ->select([
                'resource_template.id AS id',
                'resource_template.label AS label',
            ])
            ->from('resource_template', 'resource_template')
            ->orderBy('resource_template.id', 'asc')
        ;
        $stmt = $connection->executeQuery($qb);
        // Fetch by key pair is not supported by doctrine 2.0.
        $this->resourceTemplates = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $this->resourceTemplates = array_column($this->resourceTemplates, 'id', 'label');
        return $this->resourceTemplates;
    }

    /**
     * Get all resource template labels by id.
     *
     * @return array Associative array of labels by id.
     */
    public function getResourceTemplateLabels()
    {
        return array_flip($this->getResourceTemplateIds());
    }

    /**
     * Get the list of vocabulary uris by prefix.
     *
     * @param bool $fixed If fixed, the uri are returned without final "#" and "/".
     * @return array
     */
    public function getVocabularyUris($fixed = false)
    {
        static $vocabularies;
        static $fixedVocabularies;
        if (!is_null($vocabularies)) {
            return $fixed ? $fixedVocabularies : $vocabularies;
        }

        $connection = $this->services->get('Omeka\Connection');
        $qb = $connection->createQueryBuilder();
        $qb
            ->select([
                'vocabulary.prefix AS prefix',
                'vocabulary.namespace_uri AS uri',
            ])
            ->from('vocabulary', 'vocabulary')
            ->orderBy('vocabulary.prefix', 'asc')
        ;
        $stmt = $connection->executeQuery($qb);
        // Fetch by key pair is not supported by doctrine 2.0.
        $vocabularies = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $vocabularies = array_column($vocabularies, 'uri', 'prefix');
        $fixedVocabularies = array_map(function ($v) {
            return rtrim($v, '#/');
        }, $vocabularies);
        return $fixed ? $fixedVocabularies : $vocabularies;
    }

    /**
     * Check if a string is a managed data type.
     *
     * @param string $dataType
     * @return bool
     */
    public function isDataType($dataType)
    {
        return array_key_exists($dataType, $this->getDataTypes());
    }

    /**
     * @param string $dataType
     * @return string|null
     */
    public function getDataType($dataType)
    {
        $dataTypes = $this->getDataTypes();
        return $dataTypes[$dataType] ?? null;
    }

    /**
     * @todo Remove the short data types.
     *
     * @return array
     */
    public function getDataTypes()
    {
        if (isset($this->dataTypes)) {
            return $this->dataTypes;
        }

        $dataTypes = $this->services->get('Omeka\DataTypeManager')
            ->getRegisteredNames();

        // Append the short data types for easier process.
        $this->dataTypes = array_combine($dataTypes, $dataTypes);

        foreach ($dataTypes as $dataType) {
            $pos = strpos($dataType, ':');
            if ($pos === false) {
                continue;
            }
            $short = substr($dataType, $pos + 1);
            if (!is_numeric($short) && !isset($this->dataTypes[$short])) {
                $this->dataTypes[$short] = $dataType;
            }
        }
        return $this->dataTypes;
    }

    /**
     * Get a user id by email or id or name.
     *
     * @param string|int $emailOrIdOrName
     * @return int|null
     */
    public function getUserId($emailOrIdOrName)
    {
        if (is_numeric($emailOrIdOrName)) {
            $data = ['id' => $emailOrIdOrName];
        } elseif (filter_var($emailOrIdOrName, FILTER_VALIDATE_EMAIL)) {
            $data = ['email' => $emailOrIdOrName];
        } else {
            $data = ['name' => $emailOrIdOrName];
        }
        $data['limit'] = 1;

        $users = $this->api()
            ->search('users', $data, ['responseContent' => 'resource'])->getContent();
        return $users ? (reset($users))->getId() : null;
    }

    /**
     * Trim all whitespaces.
     *
     * @param string $string
     * @return string
     */
    public function trimUnicode($string)
    {
        return preg_replace('/^[\s\h\v[:blank:][:space:]]+|[\s\h\v[:blank:][:space:]]+$/u', '', $string);
    }

    /**
     * Check if a string seems to be an url.
     *
     * Doesn't use FILTER_VALIDATE_URL, so allow non-encoded urls.
     *
     * @param string $string
     * @return bool
     */
    public function isUrl($string)
    {
        return strpos($string, 'https:') === 0
            || strpos($string, 'http:') === 0
            || strpos($string, 'ftp:') === 0
            || strpos($string, 'sftp:') === 0;
    }

    /**
     * Allows to log resources with a singular name from the resource type, that
     * is plural in Omeka.
     *
     * @param string $resourceType
     * @return string
     */
    public function label($resourceType)
    {
        $labels = [
            'items' => 'item', // @translate
            'item_sets' => 'item set', // @translate
            'media' => 'media', // @translate
            'resources' => 'resource', // @translate
            'annotations' => 'annotation', // @translate
            'vocabularies' => 'vocabulary', // @translate
            'properties' => 'property', // @translate
            'resource_classes' => 'resource class', // @translate
            'resource_templates' => 'resource template', // @translate
        ];
        return isset($labels[$resourceType])
            ? $labels[$resourceType]
            : $resourceType;
    }

    /**
     * Allows to log resources with a singular name from the resource type, that
     * is plural in Omeka.
     *
     * @param string $resourceType
     * @return string
     */
    public function labelPlural($resourceType)
    {
        $labels = [
            'items' => 'items', // @translate
            'item_sets' => 'item sets', // @translate
            'media' => 'media', // @translate
            'resources' => 'resources', // @translate
            'annotations' => 'annotations', // @translate
            'vocabularies' => 'vocabularies', // @translate
            'properties' => 'properties', // @translate
            'resource_classes' => 'resource classes', // @translate
            'resource_templates' => 'resource templates', // @translate
        ];
        return isset($labels[$resourceType])
            ? $labels[$resourceType]
            : $resourceType;
    }

    /**
     * Find a list of resource ids from a list of identifiers (or one id).
     *
     * When there are true duplicates and case insensitive duplicates, the first
     * case sensitive is returned, else the first case insensitive resource.
     *
     * @todo Manage Media source html.
     *
     * @uses\BulkImport\Mvc\Controller\Plugin\FindResourcesFromIdentifiers
     *
     * @param array|string $identifiers Identifiers should be unique. If a
     * string is sent, the result will be the resource.
     * @param string|int|array $identifierName Property as integer or term,
     * "o:id", a media ingester (url or file), or an associative array with
     * multiple conditions (for media source). May be a list of identifier
     * metadata names, in which case the identifiers are searched in a list of
     * properties and/or in internal ids.
     * @param string $resourceType The resource type if any.
     * @return array|int|null|Object Associative array with the identifiers as key
     * and the ids or null as value. Order is kept, but duplicate identifiers
     * are removed. If $identifiers is a string, return directly the resource
     * id, or null. Returns standard object when there is at least one duplicated
     * identifiers in resource and the option "$uniqueOnly" is set.
     *
     * Note: The option uniqueOnly is not taken in account. The object or the
     * boolean are not returned, but logged.
     * Furthermore, the identifiers without id are not returned.
     */
    public function findResourcesFromIdentifiers($identifiers, $identifierName = null, $resourceType = null)
    {
        $findResourcesFromIdentifiers = $this->findResourcesFromIdentifiers;
        $identifierName = $identifierName ?: $this->getIdentifierNames();
        $result = $findResourcesFromIdentifiers($identifiers, $identifierName, $resourceType, true);

        $isSingle = !is_array($identifiers);

        // Log duplicate identifiers.
        if (is_object($result)) {
            $result = (array) $result;
            if ($isSingle) {
                $result['result'] = [$identifiers => $result['result']];
                $result['count'] = [$identifiers => $result['count']];
            }

            // Remove empty identifiers.
            $result['result'] = array_filter($result['result']);
            foreach (array_keys($result['result']) as $identifier) {
                if ($result['count'][$identifier] > 1) {
                    $this->logger->warn(
                        'Identifier "{identifier}" is not unique ({count} values).', // @translate
                        ['identifier' => $identifier, 'count' => $result['count'][$identifier]]
                    );
                    // if (!$this->getAllowDuplicateIdentifiers() {
                    //     unset($result['result'][$identifier]);
                    // }
                }
            }

            if (!$this->getAllowDuplicateIdentifiers()) {
                $this->logger->err(
                    'Duplicate identifiers are not allowed.' // @translate
                );
                return $isSingle ? null : [];
            }

            $result = $isSingle ? reset($result['result']) : $result['result'];
        } else {
            // Remove empty identifiers.
            if (!$isSingle) {
                $result = array_filter($result);
            }
        }

        return $result;
    }

    /**
     * Find a resource id from a an identifier.
     *
     * @uses self::findResourcesFromIdentifiers()
     * @param string $identifier
     * @param string|int|array $identifierName Property as integer or term,
     * media ingester or "o:id", or an array with multiple conditions.
     * @param string $resourceType The resource type if any.
     * @return int|null|false
     */
    public function findResourceFromIdentifier($identifier, $identifierName = null, $resourceType = null)
    {
        return $this->findResourcesFromIdentifiers($identifier, $identifierName, $resourceType);
    }

    /**
     * Escape a value for use in XML.
     *
     * From Omeka Classic application/libraries/globals.php
     *
     * @param string $value
     * @return string
     */
    public function xml_escape($value)
    {
        return htmlspecialchars(
            preg_replace('#[\x00-\x08\x0B\x0C\x0E-\x1F]+#', '', $value),
            ENT_QUOTES
        );
    }

    /**
     * @return \Laminas\Log\Logger
     */
    public function logger()
    {
        return $this->logger;
    }

    /**
     * Proxy to api() to get the errors even without form.
     *
     * @param \Laminas\Form\Form $form
     * @param bool $throwValidationException
     * @return \Omeka\Mvc\Controller\Plugin\Api
     */
    public function api(\Laminas\Form\Form $form = null, $throwValidationException = false)
    {
        // @see \Omeka\Api\Manager::handleValidationException()
        try {
            $result = $this->api->__invoke($form, true);
        } catch (\Omeka\Api\Exception\ValidationException $e) {
            if ($throwValidationException) {
                throw $e;
            }
            $this->logger->err($e);
        }
        return $result;
    }

    /**
     * Set the default param to allow duplicate identifiers.
     *
     * @param bool $allowDuplicateIdentifiers
     * @return self
     */
    public function setAllowDuplicateIdentifiers($allowDuplicateIdentifiers = false)
    {
        $this->allowDuplicateIdentifiers = (bool) $allowDuplicateIdentifiers;
        return $this;
    }

    /**
     * Get the default param to allow duplicate identifiers.
     *
     * @return bool
     */
    public function getAllowDuplicateIdentifiers()
    {
        return $this->allowDuplicateIdentifiers;
    }

    /**
     * Set the default identifier names.
     *
     * @param array|string|int $identifierNames
     * @return self
     */
    public function setIdentifierNames($identifierNames)
    {
        $this->identifierNames = $identifierNames;
        return $this;
    }

    /**
     * Get the default identifier names.
     *
     * @return array|string
     */
    public function getIdentifierNames()
    {
        return $this->identifierNames;
    }
}
