<?php declare(strict_types=1);

namespace BulkImport\Processor;

use ArrayObject;
use BulkImport\Reader\Reader;
use BulkImport\Traits\ServiceLocatorAwareTrait;
use Laminas\Log\Logger;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Log\Stdlib\PsrMessage;
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
     * (what is the meaning of an empty cell or a missing column?), and may be
     * replaced with a simpler second option or automatic determination.
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
     * @var \Omeka\Permissions\Acl
     */
    protected $acl;

    /**
     * @var \Omeka\Api\Adapter\Manager
     */
    protected $adapterManager;

    /**
     * The api is available through bulk->api() too.
     * Api manager doesn't manage ValidationException, but has more public
     * methods.
     *
     * @var \Omeka\Api\Manager
     */
    protected $apiManager;

    /**
     * @var \BulkImport\Mvc\Controller\Plugin\Bulk
     */
    protected $bulk;

    /**
     * @var \Laminas\Mvc\I18n\Translator
     */
    protected $translator;

    /**
     * @var \Omeka\Entity\User
     */
    protected $user;

    /**
     * Base path of the files.
     *
     * @var string
     */
    protected $basePath;

    /**
     * Temp path for the files.
     *
     * @var string
     */
    protected $tempPath;

    /**
     * Processor constructor.
     *
     * @param ServiceLocatorInterface $services
     */
    public function __construct(ServiceLocatorInterface $services)
    {
        $this->setServiceLocator($services);
        $this->acl = $services->get('Omeka\Acl');
        $this->adapterManager = $services->get('Omeka\ApiAdapterManager');
        $this->apiManager = $services->get('Omeka\ApiManager');
        $this->bulk = $services->get('ControllerPluginManager')->get('bulk');
        $this->translator = $services->get('MvcTranslator');
        $this->user = $services->get('Omeka\AuthenticationService')->getIdentity();
        $config = $services->get('Config');
        $this->basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $this->tempPath = $config['temp_dir'] ?: sys_get_temp_dir();
    }

    public function setReader(Reader $reader): \BulkImport\Processor\Processor
    {
        $this->reader = $reader;
        return $this;
    }

    public function getReader(): \BulkImport\Reader\Reader
    {
        return $this->reader;
    }

    public function setLogger(Logger $logger): \BulkImport\Processor\Processor
    {
        $this->logger = $logger;
        return $this;
    }

    public function setJob(Job $job): \BulkImport\Processor\Processor
    {
        $this->job = $job;
        return $this;
    }

    /**
     * Check the id of a resource.
     *
     * The action should be checked separately, else the result may have no
     * meaning.
     */
    protected function checkId(ArrayObject $resource): bool
    {
        if (!empty($resource['checked_id'])) {
            return !empty($resource['o:id']);
        }
        // The id is set, but not checked. So check it.
        if ($resource['o:id']) {
            // TODO getResourceName() is only in child AbstractResourceProcessor.
            $resourceName = empty($resource['resource_name'])
                ? $this->getResourceName()
                : $resource['resource_name'];
            if (empty($resourceName) || $resourceName === 'resources') {
                if (isset($resource['messageStore'])) {
                    $resource['messageStore']->addError('resource_name', new PsrMessage(
                        'The resource id cannot be checked: the resource type is undefined.' // @translate
                    ));
                } else {
                    $this->logger->err(
                        'Index #{index}: The resource id cannot be checked: the resource type is undefined.', // @translate
                        ['index' => $this->indexResource]
                    );
                    $resource['has_error'] = true;
                }
            } else {
                $id = $this->bulk->findResourceFromIdentifier($resource['o:id'], 'o:id', $resourceName, $resource['messageStore'] ?? null);
                if (!$id) {
                    if (isset($resource['messageStore'])) {
                        $resource['messageStore']->addError('resource_id', new PsrMessage(
                            'The id of this resource doesn’t exist.' // @translate
                        ));
                    } else {
                        $this->logger->err(
                            'Index #{index}: The id of this resource doesn’t exist.', // @translate
                            ['index' => $this->indexResource]
                        );
                        $resource['has_error'] = true;
                    }
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
     * @return bool True if id is set.
     */
    protected function fillId(ArrayObject $resource): bool
    {
        if (is_numeric($resource['o:id'])) {
            return true;
        }

        // TODO getResourceName() is only in child AbstractResourceProcessor.
        $resourceName = empty($resource['resource_name'])
            ? $this->getResourceName()
            : $resource['resource_name'];
        if (empty($resourceName) || $resourceName === 'resources') {
            if (isset($resource['messageStore'])) {
                $resource['messageStore']->addError('resource_name', new PsrMessage(
                    'The resource id cannot be filled: the resource type is undefined.' // @translate
                ));
            } else {
                $this->logger->err(
                    'Index #{index}: The resource id cannot be filled: the resource type is undefined.', // @translate
                    ['index' => $this->indexResource]
                );
                $resource['has_error'] = true;
            }
        }

        $identifierNames = $this->bulk->getIdentifierNames();
        $key = array_search('o:id', $identifierNames);
        if ($key !== false) {
            unset($identifierNames[$key]);
        }
        if (empty($identifierNames)) {
            if ($this->bulk->getAllowDuplicateIdentifiers()) {
                if (isset($resource['messageStore'])) {
                    $resource['messageStore']->addWarning('identifier', new PsrMessage(
                        'The resource has no identifier.' // @translate
                    ));
                } else {
                    $this->logger->notice(
                        'Index #{index}: The resource has no identifier.', // @translate
                        ['index' => $this->indexResource]
                    );
                }
            } else {
                if (isset($resource['messageStore'])) {
                    $resource['messageStore']->addError('identifier', new PsrMessage(
                        'The resource id cannot be filled: no metadata defined as identifier and duplicate identifiers are not allowed.' // @translate
                    ));
                } else {
                    $this->logger->err(
                        'Index #{index}: The resource id cannot be filled: no metadata defined as identifier and duplicate identifiers are not allowed.', // @translate
                        ['index' => $this->indexResource]
                    );
                    $resource['has_error'] = true;
                }
            }
            return false;
        }

        // Don't try to fill id when resource has an error, but allow warnings.
        if (!empty($resource['has_error'])
            || (isset($resource['messageStore']) && $resource['messageStore']->hasErrors())
        ) {
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

            $ids = $this->bulk->findResourcesFromIdentifiers($identifiers, $identifierName, $resourceName, $resource['messageStore'] ?? null);
            if (!$ids) {
                continue;
            }

            $flipped = array_flip($ids);
            if (count($flipped) > 1) {
                if (isset($resource['messageStore'])) {
                    $resource['messageStore']->addWarning('identifier', new PsrMessage(
                        'Resource doesn’t have a unique identifier.' // @translate
                    ));
                } else {
                    $this->logger->warn(
                        'Index #{index}: Resource doesn’t have a unique identifier.', // @translate
                        ['index' => $this->indexResource]
                    );
                }
                if (!$this->bulk->getAllowDuplicateIdentifiers()) {
                    if (isset($resource['messageStore'])) {
                        $resource['messageStore']->addError('identifier', new PsrMessage(
                            'Duplicate identifiers are not allowed.' // @translate
                        ));
                    } else {
                        $this->logger->err(
                            'Index #{index}: Duplicate identifiers are not allowed.', // @translate
                            ['index' => $this->indexResource]
                        );
                        $resource['has_error'] = true;
                    }
                    break;
                }
            }
            $resource['o:id'] = reset($ids);
            if (isset($resource['messageStore'])) {
                $resource['messageStore']->addInfo('identifier', new PsrMessage(
                    'Identifier "{identifier}" ({metadata}) matches {resource_name} #{resource_id}.', // @translate
                    [
                        'identifier' => key($ids),
                        'metadata' => $identifierName,
                        'resource_name' => $this->bulk->label($resourceName),
                        'resource_id' => $resource['o:id'],
                    ]
                ));
            } else {
                $this->logger->info(
                    'Index #{index}: Identifier "{identifier}" ({metadata}) matches {resource_name} #{resource_id}.', // @translate
                    [
                        'index' => $this->indexResource,
                        'identifier' => key($ids),
                        'metadata' => $identifierName,
                        'resource_name' => $this->bulk->label($resourceName),
                        'resource_id' => $resource['o:id'],
                    ]
                );
                $resource['has_error'] = true;
            }
            return true;
        }

        return false;
    }

    protected function standardOperation(string $action): ?string
    {
        $actionsToOperations = [
            self::ACTION_CREATE => \Omeka\Api\Request::CREATE,
            self::ACTION_APPEND => \Omeka\Api\Request::UPDATE,
            self::ACTION_REVISE => \Omeka\Api\Request::UPDATE,
            self::ACTION_UPDATE => \Omeka\Api\Request::UPDATE,
            self::ACTION_REPLACE => \Omeka\Api\Request::UPDATE,
            self::ACTION_DELETE => \Omeka\Api\Request::DELETE,
            self::ACTION_SKIP => null,
        ];
        return $actionsToOperations[$action] ?? null;
    }

    protected function checkAdapter(string $resourceName, string $operation): bool
    {
        static $checks = [];
        if (!isset($checks[$resourceName][$operation])) {
            $adapter = $this->adapterManager->get($resourceName);
            $checks[$resourceName][$operation] = $this->acl->userIsAllowed($adapter, $operation);
        }
        return $checks[$resourceName][$operation];
    }

    protected function listValidationMessages(ValidationException $e): array
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

    protected function recordCreatedResources(array $resources): void
    {
        /** @var \Omeka\Api\Adapter\Manager $adapterManager */
        $adapterManager = $this->getServiceLocator()->get('Omeka\ApiAdapterManager');
        $jobId = $this->job->getJobId();
        $classes = [];

        $importeds = [];
        /** @var \Omeka\Api\Representation\AbstractRepresentation $resource */
        foreach ($resources as $resource) {
            // The simplest way to get the adapter from any representation, when
            // the api name is unavailable.
            $class = get_class($resource);
            if (empty($classes[$class])) {
                $classes[$class] = $adapterManager
                    ->get(substr_replace(str_replace('\\Representation\\', '\\Adapter\\', get_class($resource)), 'Adapter', -14))
                    ->getResourceName();
            }
            $importeds[] = [
                'o:job' => ['o:id' => $jobId],
                'entity_id' => $resource->id(),
                'entity_name' => $classes[$class],
            ];
        }

        $this->bulk->api()->batchCreate('bulk_importeds', $importeds, [], ['continueOnError' => true]);
    }
}
