<?php

/*
 * Copyright 2017-2019 Daniel Berthereau
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

use Zend\Log\Logger;
use Zend\Mvc\Controller\Plugin\AbstractPlugin;
use Zend\ServiceManager\ServiceLocatorInterface;

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
        $propertyIds = $this->getPropertyIds();
        return is_numeric($termOrId)
            ? (array_search($termOrId, $propertyIds) ? $termOrId : null)
            : (isset($propertyIds[$termOrId]) ? $propertyIds[$termOrId] : null);
    }

    /**
     * Get a property term by term or id.
     *
     * @param string|int $termOrId
     * @return string|null
     */
    public function getPropertyTerm($termOrId)
    {
        $propertyIds = $this->getPropertyIds();
        return is_numeric($termOrId)
            ? (array_search($termOrId, $propertyIds) ?: null)
            : (isset($propertyIds[$termOrId]) ? $termOrId : null);
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

        $this->properties = [];
        $properties = $this->api()
            ->search('properties', [], ['responseContent' => 'resource'])->getContent();
        foreach ($properties as $property) {
            $term = $property->getVocabulary()->getPrefix() . ':' . $property->getLocalName();
            $this->properties[$term] = $property->getId();
        }

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
        $resourceClassIds = $this->getResourceClassIds();
        return is_numeric($termOrId)
            ? (array_search($termOrId, $resourceClassIds) ? $termOrId : null)
            : (isset($resourceClassIds[$termOrId]) ? $resourceClassIds[$termOrId] : null);
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

        $this->resourceClasses = [];
        $resourceClasses = $this->api()
            ->search('resource_classes', [], ['responseContent' => 'resource'])->getContent();
        foreach ($resourceClasses as $resourceClass) {
            $term = $resourceClass->getVocabulary()->getPrefix() . ':' . $resourceClass->getLocalName();
            $this->resourceClasses[$term] = $resourceClass->getId();
        }

        return $this->resourceClasses;
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
        $resourceTemplateIds = $this->getResourceTemplateIds();
        return is_numeric($labelOrId)
            ? (array_search($labelOrId, $resourceTemplateIds) ? $labelOrId : null)
            : (isset($resourceTemplateIds[$labelOrId]) ? $resourceTemplateIds[$labelOrId] : null);
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

        $this->resourceTemplate = [];
        $resourceTemplates = $this->api()
            ->search('resource_templates', [], ['responseContent' => 'resource'])->getContent();
        foreach ($resourceTemplates as $resourceTemplate) {
            $this->resourceTemplates[$resourceTemplate->getLabel()] = $resourceTemplate->getId();
        }

        return $this->resourceTemplates;
    }

    /**
     * @param string $type
     * @return string|null
     */
    public function getDataType($type)
    {
        $dataTypes = $this->getDataTypes();
        return isset($dataTypes[$type])
            ? $dataTypes[$type]
            : null;
    }

    /**
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
        $identifierName = $identifierName ?: $this->identifierNames;
        $result = $findResourcesFromIdentifiers($identifiers, $identifierName, $resourceType, true);

        $isSingle = !is_array($identifiers);

        // Log duplicate identifiers.
        if (is_object($result)) {
            $result = (array) $result;
            if ($isSingle) {
                $result['result'] = [$result['result']];
                $result['count'] = [$result['count']];
            }

            // Remove empty identifiers.
            $result['result'] = array_filter($result['result']);
            foreach (array_keys($result['result']) as $identifier) {
                if ($result['count'][$identifier] > 1) {
                    $this->logger->warn(
                        'Identifier "{identifier}" is not unique ({count} values).', // @translate
                        ['identifier' => $identifier, 'count' => $result['count'][$identifier]]
                    );
                    // if (!$this->allowDuplicateIdentifiers) {
                    //     unset($result['result'][$identifier]);
                    // }
                }
            }

            if (!$this->allowDuplicateIdentifiers) {
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

    public function logger()
    {
        return $this->logger;
    }

    public function api()
    {
        return $this->api;
    }

    /**
     * Set the default param to allow duplicate identifiers.
     *
     * @param bool $allowDuplicateIdentifiers
     * @return $this;
     */
    public function setAllowDuplicateIdentifiers($allowDuplicateIdentifiers = false)
    {
        $this->allowDuplicateIdentifiers = (bool) $allowDuplicateIdentifiers;
        return $this;
    }
}
