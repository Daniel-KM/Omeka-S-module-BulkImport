<?php declare(strict_types=1);

namespace BulkImport\Processor;

use BulkImport\Reader\Reader;
use BulkImport\Traits\ServiceLocatorAwareTrait;
use Laminas\Log\Logger;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Job\AbstractJob as Job;

abstract class AbstractProcessor implements Processor
{
    use ServiceLocatorAwareTrait;

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
     * @var \BulkImport\Stdlib\MetaMapper
     */
    protected $metaMapper;

    /**
     * @var \Laminas\Mvc\I18n\Translator
     */
    protected $translator;

    /**
     * @var \Omeka\Entity\User
     */
    protected $user;

    /**
     * @var int
     */
    protected $userId;

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
        $this->translator = $services->get('MvcTranslator');
        $this->apiManager = $services->get('Omeka\ApiManager');
        $this->adapterManager = $services->get('Omeka\ApiAdapterManager');

        $this->metaMapper = $services->get('Bulk\MetaMapper');

        $plugins = $services->get('ControllerPluginManager');
        $this->bulk = $plugins->get('bulk');

        $config = $services->get('Config');
        $this->basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $this->tempPath = $config['temp_dir'] ?: sys_get_temp_dir();

        // This doctrine resource should be reloaded each time the entity
        // manager is cleared, else an error may occur on big import.
        $this->user = $services->get('Omeka\AuthenticationService')->getIdentity();
        $this->userId = $this->user->getId();
    }

    public function setReader(Reader $reader): self
    {
        $this->reader = $reader;
        return $this;
    }

    public function getReader(): Reader
    {
        return $this->reader;
    }

    public function setLogger(Logger $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    public function setJob(Job $job): self
    {
        $this->job = $job;
        return $this;
    }
}
