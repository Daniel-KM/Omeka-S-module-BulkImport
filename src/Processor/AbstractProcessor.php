<?php
namespace BulkImport\Processor;

use ArrayObject;
use BulkImport\Interfaces\Processor;
use BulkImport\Interfaces\Reader;
use BulkImport\Traits\ServiceLocatorAwareTrait;
use Omeka\Job\AbstractJob as Job;
use Zend\Log\Logger;
use Zend\ServiceManager\ServiceLocatorInterface;

abstract class AbstractProcessor implements Processor
{
    use ServiceLocatorAwareTrait;

    /**
     * Default limit for the loop to avoid heavy sql requests.
     *
     * This value has no impact on process, but when it is set to "1" (default),
     * the order of internal ids will be in the same order than the input and
     * medias will follow their items. If it is greater, the order will follow
     * the number of entries by resource types. This option is used only for
     * creation.
     * Furthermore, statistics are more precise when this option is "1".
     *
     * @var int
     */
    const ENTRIES_BY_BATCH = 1;

    /**#@+
     * Processor actions
     *
     * The various update actions are probably too much related to spreadsheet
     * (what is the meaning of an empty cell?), and may be replaced with a more
     * simpler second option or automatic determination.
     */
    const ACTION_CREATE = 'create'; // @translate
    const ACTION_APPEND = 'append'; // @translate
    const ACTION_REVISE = 'revise'; // @translate
    const ACTION_UPDATE = 'update'; // @translate
    const ACTION_REPLACE = 'replace'; // @translate
    const ACTION_DELETE = 'delete'; // @translate
    const ACTION_SKIP = 'skip'; // @translate
    /**#@-*/

    /**
     * @var Reader
     */
    protected $reader;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var Job
     */
    protected $job;

    /**
     * @var \Omeka\Api\Manager
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
     * Processor constructor.
     *
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function __construct(ServiceLocatorInterface $serviceLocator)
    {
        $this->setServiceLocator($serviceLocator);
    }

    public function setReader(Reader $reader)
    {
        $this->reader = $reader;
        return $this;
    }

    /**
     * @return \BulkImport\Interfaces\Reader
     */
    public function getReader()
    {
        return $this->reader;
    }

    public function setLogger(Logger $logger)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setJob(Job $job)
    {
        $this->job = $job;
        return $this;
    }

    /**
     * Get a user id by email or id or name.
     *
     * @param string|int $emailOrIdOrName
     * @return int|null
     */
    protected function getUserId($emailOrIdOrName)
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
     * Check if a string or a id is a managed term.
     *
     * @param string|int $termOrId
     * @return bool
     */
    protected function isPropertyTerm($termOrId)
    {
        return $this->getPropertyId($termOrId) !== null;
    }

    /**
     * Get a property id by term or id.
     *
     * @param string|int $termOrId
     * @return int|null
     */
    protected function getPropertyId($termOrId)
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
    protected function getPropertyTerm($termOrId)
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
    protected function getPropertyIds()
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
     * Check if a string or a id is a resource class.
     *
     * @param string|int $termOrId
     * @return bool
     */
    protected function isResourceClass($termOrId)
    {
        return $this->getResourceClassId($termOrId) !== null;
    }

    /**
     * Get a resource class by term or by id.
     *
     * @param string|int $termOrId
     * @return int|null
     */
    protected function getResourceClassId($termOrId)
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
    protected function getResourceClassIds()
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
    protected function isResourceTemplate($labelOrId)
    {
        return $this->getResourceTemplateId($labelOrId) !== null;
    }

    /**
     * Get a resource template by label or by id.
     *
     * @param string|int $labelOrId
     * @return int|null
     */
    protected function getResourceTemplateId($labelOrId)
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
    protected function getResourceTemplateIds()
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
    protected function getDataType($type)
    {
        $dataTypes = $this->getDataTypes();
        return isset($dataTypes[$type])
            ? $dataTypes[$type]
            : null;
    }

    /**
     * @return array
     */
    protected function getDataTypes()
    {
        if (isset($this->dataTypes)) {
            return $this->dataTypes;
        }

        $dataTypes = $this->getServiceLocator()->get('Omeka\DataTypeManager')
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
     * Check the id of a resource.
     *
     * @param ArrayObject $resource
     * @return bool The action should be checked separately, else the result
     * may have no meaning.
     */
    protected function checkId(ArrayObject $resource)
    {
        if (!empty($resource['checked_id'])) {
            return !empty($resource['o:id']);
        }
        // The id is set, but not checked. So check it.
        if ($resource['o:id']) {
            $resourceType = empty($resource['resource_type'])
                ? $this->getResourceType()
                : $resource['resource_type'];
            if (empty($resourceType) || $resourceType === 'resources') {
                $this->logger->err(
                    'The resource id cannot be checked: the resource type is undefined.' // @translate
                );
                $resource['has_error'] = true;
            } else {
                $id = $this->findResourceFromIdentifier($resource['o:id'], 'o:id', $resourceType);
                if (!$id) {
                    $this->logger->err(
                        'The id of this resource doesn’t exist.' // @translate
                    );
                    $resource['has_error'] = true;
                }
            }
        }
        $resource['checked_id'] = true;
        return !empty($resource['o:id']);
    }

    /**
     * Fill id of a resource if not set. No check is done if set, so use
     * checkId() first.
     *
     * The resource type is required, so this method should be used in the end
     * of the process.
     *
     * @param ArrayObject $resource
     * @return bool
     */
    protected function fillId(ArrayObject $resource)
    {
        if (is_numeric($resource['o:id'])) {
            return true;
        }

        $resourceType = empty($resource['resource_type'])
            ? $this->getResourceType()
            : $resource['resource_type'];
        if (empty($resourceType) || $resourceType === 'resources') {
            $this->logger->err(
                'The resource id cannot be filled: the resource type is undefined.' // @translate
            );
            $resource['has_error'] = true;
        }

        $identifierNames = $this->identifierNames;
        $key = array_search('o:id', $identifierNames);
        if ($key !== false) {
            unset($identifierNames[$key]);
        }
        if (empty($identifierNames)) {
            $this->logger->err(
                'The resource id cannot be filled: no metadata defined as identifier.' // @translate
            );
            $resource['has_error'] = true;
        }

        // Don't try to fill id of a resource that has an error.
        if ($resource['has_error']) {
            return false;
        }

        foreach (array_keys($identifierNames) as $identifierName) {
            // Get the list of identifiers from the resource metadata.
            $identifiers = [];
            if (!empty($resource[$identifierName])) {
                // Check if it is a property value.
                if (is_array($resource[$identifierName])) {
                    foreach ($resource[$identifierName] as $value) {
                        if (is_array($value)) {
                            // Check the different type of value. Only value is
                            // managed currently.
                            // TODO Check identifier that is not a property value.
                            if (isset($value['@value']) && strlen($value['@value'])) {
                                $identifiers[] = $value['@value'];
                            }
                        }
                    }
                } else {
                    // TODO Check identifier that is not a property.
                    $identifiers[] = $value;
                }
            }

            if (!$identifiers) {
                continue;
            }

            $ids = $this->findResourcesFromIdentifiers($identifiers, $identifierName, $resourceType);
            if (!$ids) {
                continue;
            }

            $flipped = array_flip($ids);
            if (count($flipped) > 1) {
                $this->logger->warn(
                    'Resource doesn’t have a unique identifier.' // @translate
                );
                if (!$this->allowDuplicateIdentifiers) {
                    $this->logger->err(
                        'Duplicate identifiers are not allowed.' // @translate
                    );
                    break;
                }
            }
            $resource['o:id'] = reset($ids);
            $this->logger->info(
                'Identifier "{identifier}" ({metadata}) matches {resource_type} #{resource_id}.', // @translate
                [
                    'identifier' => key($ids),
                    'metadata' => $identifierName,
                    'resource_type' => $this->label($resourceType),
                    'resource_id' => $resource['o:id'],
                ]
            );
            return true;
        }

        return false;
    }

    /**
     * Trim all whitespaces.
     *
     * @param string $string
     * @return string
     */
    protected function trimUnicode($string)
    {
        return preg_replace('/^[\h\v\s[:blank:][:space:]]+|[\h\v\s[:blank:][:space:]]+$/u', '', $string);
    }

    /**
     * Check if a string seems to be an url.
     *
     * Doesn't use FILTER_VALIDATE_URL, so allow non-encoded urls.
     *
     * @param string $string
     * @return bool
     */
    protected function isUrl($string)
    {
        return strpos($string, 'https:') === 0
            || strpos($string, 'http:') === 0
            || strpos($string, 'ftp:') === 0;
    }

    /**
     * Allows to log resources with a singular name from the resource type, that
     * is plural in Omeka.
     *
     * @param string $resourceType
     * @return string
     */
    protected function label($resourceType)
    {
        $labels = [
            'items' => 'item', // @translate
            'item_sets' => 'item set', // @translate
            'media' => 'media', // @translate
            'resources' => 'resource', // @translate
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
    protected function labelPlural($resourceType)
    {
        $labels = [
            'items' => 'items', // @translate
            'item_sets' => 'item sets', // @translate
            'media' => 'media', // @translate
            'resources' => 'resources', // @translate
        ];
        return isset($labels[$resourceType])
            ? $labels[$resourceType]
            : $resourceType;
    }

    /**
     * @return \Omeka\Api\Manager
     */
    protected function api()
    {
        if (!$this->api) {
            $this->api = $this->getServiceLocator()->get('Omeka\ApiManager');
        }
        return $this->api;
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
    protected function findResourcesFromIdentifiers($identifiers, $identifierName = null, $resourceType = null)
    {
        if (!$this->findResourcesFromIdentifiers) {
            $this->findResourcesFromIdentifiers = $this->getServiceLocator()->get('ControllerPluginManager')
                // Use class name to use it even when CsvImport is installed.
                ->get(\BulkImport\Mvc\Controller\Plugin\FindResourcesFromIdentifiers::class);
        }

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
    protected function findResourceFromIdentifier($identifier, $identifierName = null, $resourceType = null)
    {
        return $this->findResourcesFromIdentifiers($identifier, $identifierName, $resourceType);
    }
}
