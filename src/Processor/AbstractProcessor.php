<?php declare(strict_types=1);
namespace BulkImport\Processor;

use ArrayObject;
use BulkImport\Interfaces\Processor;
use BulkImport\Interfaces\Reader;
use BulkImport\Traits\ServiceLocatorAwareTrait;
use Laminas\Log\Logger;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Api\Exception\ValidationException;
use Omeka\Job\AbstractJob as Job;

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
     * @var \BulkImport\Mvc\Controller\Plugin\Bulk;
     */
    protected $bulk;

    /**
     * Processor constructor.
     *
     * @param ServiceLocatorInterface $services
     */
    public function __construct(ServiceLocatorInterface $services)
    {
        $this->setServiceLocator($services);
        $this->bulk = $services->get('ControllerPluginManager')->get('bulk');
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
     * Check if a string or a id is a managed term.
     *
     * @param string|int $termOrId
     * @return bool
     */
    protected function isPropertyTerm($termOrId)
    {
        return $this->bulk->isPropertyTerm($termOrId);
    }

    /**
     * Get a property id by term or id.
     *
     * @param string|int $termOrId
     * @return int|null
     */
    protected function getPropertyId($termOrId)
    {
        return $this->bulk->getPropertyId($termOrId);
    }

    /**
     * Get a property term by term or id.
     *
     * @param string|int $termOrId
     * @return string|null
     */
    protected function getPropertyTerm($termOrId)
    {
        return $this->bulk->getPropertyTerm($termOrId);
    }

    /**
     * Get all property ids by term.
     *
     * @return array Associative array of ids by term.
     */
    protected function getPropertyIds()
    {
        return $this->bulk->getPropertyIds();
    }

    /**
     * Check if a string or a id is a resource class.
     *
     * @param string|int $termOrId
     * @return bool
     */
    protected function isResourceClass($termOrId)
    {
        return $this->bulk->isResourceClass($termOrId);
    }

    /**
     * Get a resource class by term or by id.
     *
     * @param string|int $termOrId
     * @return int|null
     */
    protected function getResourceClassId($termOrId)
    {
        return $this->bulk->getResourceClassId($termOrId);
    }

    /**
     * Get all resource classes by term.
     *
     * @return array Associative array of ids by term.
     */
    protected function getResourceClassIds()
    {
        return $this->bulk->getResourceClassIds();
    }

    /**
     * Check if a string or a id is a resource template.
     *
     * @param string|int $labelOrId
     * @return bool
     */
    protected function isResourceTemplate($labelOrId)
    {
        return $this->bulk->isResourceTemplate($labelOrId);
    }

    /**
     * Get a resource template by label or by id.
     *
     * @param string|int $labelOrId
     * @return int|null
     */
    protected function getResourceTemplateId($labelOrId)
    {
        return $this->bulk->getResourceTemplateId($labelOrId);
    }

    /**
     * Get all resource templates by label.
     *
     * @return array Associative array of ids by label.
     */
    protected function getResourceTemplateIds()
    {
        return $this->bulk->getResourceTemplateIds();
    }

    /**
     * @param string $type
     * @return string|null
     */
    protected function getDataType($type)
    {
        return $this->bulk->getDataType($type);
    }

    /**
     * @return array
     */
    protected function getDataTypes()
    {
        return $this->bulk->getDataTypes();
    }

    /**
     * Get a user id by email or id or name.
     *
     * @param string|int $emailOrIdOrName
     * @return int|null
     */
    protected function getUserId($emailOrIdOrName)
    {
        return $this->bulk->getUserId($emailOrIdOrName);
    }

    /**
     * Trim all whitespaces.
     *
     * @param string $string
     * @return string
     */
    protected function trimUnicode($string)
    {
        return $this->bulk->trimUnicode($string);
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
        return $this->bulk->isUrl($string);
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
        return $this->bulk->label($resourceType);
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
        return $this->bulk->labelPlural($resourceType);
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
        return $this->bulk->findResourcesFromIdentifiers($identifiers, $identifierName, $resourceType);
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
        return $this->bulk->findResourceFromIdentifier($identifier, $identifierName, $resourceType);
    }

    /**
     * @param \Laminas\Form\Form $form Unused, but kept for compatibility with
     * default api.
     * @param bool $throwValidationException
     * @return \Omeka\Mvc\Controller\Plugin\Api
     */
    protected function api(\Laminas\Form\Form $form = null, $throwValidationException = false)
    {
        return $this->bulk->api($form, $throwValidationException);
    }

    /**
     * Set the default param to allow duplicate identifiers.
     *
     * @param bool $allowDuplicateIdentifiers
     * @return self
     */
    protected function setAllowDuplicateIdentifiers($allowDuplicateIdentifiers = false)
    {
        $this->bulk->setAllowDuplicateIdentifiers($allowDuplicateIdentifiers);
        return $this;
    }

    /**
     * Get the default param to allow duplicate identifiers.
     *
     * @return bool
     */
    protected function getAllowDuplicateIdentifiers()
    {
        return $this->bulk->getAllowDuplicateIdentifiers();
    }

    /**
     * Set the default identifier names.
     *
     * @param array|string $identifierNames
     * @return self
     */
    public function setIdentifierNames($identifierNames)
    {
        $this->bulk->setIdentifierNames($identifierNames);
        return $this;
    }

    /**
     * Get the default identifier names.
     *
     * @return array|string|int
     */
    public function getIdentifierNames()
    {
        return $this->bulk->getIdentifierNames();
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
            // TODO getResourceType() is only in child AbstractResourceProcessor.
            $resourceType = empty($resource['resource_type'])
                ? $this->getResourceType()
                : $resource['resource_type'];
            if (empty($resourceType) || $resourceType === 'resources') {
                $this->logger->err(
                    'Index #{index}: The resource id cannot be checked: the resource type is undefined.', // @translate
                    ['index' => $this->indexResource]
                );
                $resource['has_error'] = true;
            } else {
                $id = $this->findResourceFromIdentifier($resource['o:id'], 'o:id', $resourceType);
                if (!$id) {
                    $this->logger->err(
                        'Index #{index}: The id of this resource doesn’t exist.', // @translate
                        ['index' => $this->indexResource]
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

        // TODO getResourceType() is only in child AbstractResourceProcessor.
        $resourceType = empty($resource['resource_type'])
            ? $this->getResourceType()
            : $resource['resource_type'];
        if (empty($resourceType) || $resourceType === 'resources') {
            $this->logger->err(
                'Index #{index}: The resource id cannot be filled: the resource type is undefined.', // @translate
                ['index' => $this->indexResource]
            );
            $resource['has_error'] = true;
        }

        $identifierNames = $this->getIdentifierNames();
        $key = array_search('o:id', $identifierNames);
        if ($key !== false) {
            unset($identifierNames[$key]);
        }
        if (empty($identifierNames)) {
            $this->logger->err(
                'Index #{index}: The resource id cannot be filled: no metadata defined as identifier.', // @translate
                ['index' => $this->indexResource]
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
                    'Index #{index}: Resource doesn’t have a unique identifier.', // @translate
                    ['index' => $this->indexResource]
                );
                if (!$this->getAllowDuplicateIdentifiers()) {
                    $this->logger->err(
                        'Index #{index}: Duplicate identifiers are not allowed.', // @translate
                        ['index' => $this->indexResource]
                    );
                    break;
                }
            }
            $resource['o:id'] = reset($ids);
            $this->logger->info(
                'Index #{index}: Identifier "{identifier}" ({metadata}) matches {resource_type} #{resource_id}.', // @translate
                [
                    'index' => $this->indexResource,
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
     * @param ValidationException $e
     * @return array
     */
    protected function listValidationMessages(ValidationException $e)
    {
        $messages = [];
        foreach ($e->getErrorStore()->getErrors() as $error) {
            foreach ($error as $message) {
                // Some messages can be nested.
                if (is_array($message)) {
                    $result = [];
                    array_walk_recursive($message, function ($v) use (&$result): void {
                        $result[] = $v;
                    });
                    $message = $result;
                    unset($result);
                } else {
                    $message = [$message];
                }
                $messages = array_merge($messages, array_values($message));
            }
        }
        return $messages;
    }
}
