<?php declare(strict_types=1);

namespace BulkImport\Processor;

use BulkImport\Traits\ServiceLocatorAwareTrait;
use Laminas\Log\Logger;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Common\Stdlib\PsrMessage;
use Omeka\Api\Representation\AbstractEntityRepresentation;

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
     * The resource name to process with this processor.
     *
     * @var string
     */
    protected $resourceName;

    /**
     * @var string
     */
    protected $resourceLabel;

    /**
     * The resource field types to process mapping.
     *
     * @var array
     */
    protected $fieldTypes = [];

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
    protected $api;

    /**
     * @var \BulkImport\Mvc\Controller\Plugin\Bulk
     */
    protected $bulk;

    /**
     * @var \BulkImport\Mvc\Controller\Plugin\BulkCheckLog
     */
    protected $bulkCheckLog;

    /**
     * @var \BulkImport\Mvc\Controller\Plugin\BulkFile
     */
    protected $bulkFile;

    /**
     * @var \BulkImport\Mvc\Controller\Plugin\BulkFileUploaded
     */
    protected $bulkFileUploaded;

    /**
     * @var \BulkImport\Mvc\Controller\Plugin\BulkIdentifiers
     */
    protected $bulkIdentifiers;

    /**
     * @var \Common\Stdlib\EasyMeta
     */
    protected $easyMeta;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var \BulkImport\Stdlib\MetaMapper
     */
    protected $metaMapper;

    /**
     * @var \Omeka\Settings\Settings
     */
    protected $settings;

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
     * @var bool
     */
    protected $isOldOmeka;

    /**
     * @var int
     */
    protected $totalErrors;

        /**
     * @var string|null
     */
    protected $lastErrorMessage;

    /**
     * Processor constructor.
     *
     * @param ServiceLocatorInterface $services
     */
    public function __construct(ServiceLocatorInterface $services)
    {
        $this->setServiceLocator($services);
        $this->acl = $services->get('Omeka\Acl');
        $this->settings = $services->get('Omeka\Settings');
        $this->translator = $services->get('MvcTranslator');
        $this->api = $services->get('Omeka\ApiManager');
        $this->adapterManager = $services->get('Omeka\ApiAdapterManager');

        $this->easyMeta = $services->get('Common\EasyMeta');
        $this->metaMapper = $services->get('Bulk\MetaMapper');

        $plugins = $services->get('ControllerPluginManager');
        $this->bulk = $plugins->get('bulk');
        $this->bulkCheckLog= $plugins->get('bulkCheckLog');
        $this->bulkFile = $plugins->get('bulkFile');
        $this->bulkFileUploaded = $plugins->get('bulkFileUploaded');
        $this->bulkIdentifiers = $plugins->get('bulkIdentifiers');

        $config = $services->get('Config');
        $this->basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $this->tempPath = $config['temp_dir'] ?: sys_get_temp_dir();

        // This doctrine resource should be reloaded each time the entity
        // manager is cleared, else an error may occur on big import.
        $this->user = $services->get('Omeka\AuthenticationService')->getIdentity();
        $this->userId = $this->user->getId();

        $this->isOldOmeka = version_compare(\Omeka\Module::VERSION, '4', '<');
    }

    public function getResourceName(): string
    {
        return $this->resourceName;
    }

    public function getLabel(): string
    {
        return $this->resourceLabel;
    }

    public function getFieldTypes(): array
    {
        return $this->fieldTypes;
    }

    public function setLogger(Logger $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    public function isValid(): bool
    {
        if (!$this->lastErrorMessage) {
            return true;
        } elseif ($this->lastErrorMessage instanceof PsrMessage) {
            $this->logger->err($this->lastErrorMessage->getMessage(), $this->lastErrorMessage->getContext());
        } elseif (!is_bool($this->lastErrorMessage)) {
            $this->logger->err($this->lastErrorMessage);
        }
        return false;
    }

    // TODO Remove these useless methods from here, used to simplify restructuration.

    public function fillResource(array $data, ?int $index = null): ?array
    {
        return null;
    }

    public function checkResource(array $resource): array
    {
        return $resource;
    }

    public function processResource(array $resource): ?AbstractEntityRepresentation
    {
        return null;
    }

    /**
     * Clean string according to options.
     */
    protected function cleanString($string): string
    {
        static $cleaners;

        $string = (string) $string;

        if ($cleaners === false) {
            return $string;
        }

        if ($cleaners === null) {
            $cleaners = $this->getParam('clean') ?: false;
            if (!$cleaners) {
                return $string;
            }
        }

        if (in_array('trim', $cleaners)) {
            $string = trim($string);
        }
        if (in_array('merge_space', $cleaners)) {
            $string = preg_replace('/\s+/', ' ', $string);
        }
        if (in_array('trim_punctuation', $cleaners)) {
            $string = trim($string, " \n\r\t\v\x00.,-?!:;");
        }
        if (in_array('lowercase', $cleaners)) {
            $string = mb_strtolower($string);
        }
        if (in_array('ucfirst', $cleaners)) {
            $string = ucfirst(mb_strtolower($string));
        }
        if (in_array('ucwords', $cleaners)) {
            $string = ucwords(mb_strtolower($string));
        }
        if (in_array('uppercase', $cleaners)) {
            $string = mb_strtoupper($string);
        }
        if (in_array('apostrophe', $cleaners)) {
            $string = str_replace("'", '’', $string);
        }
        if (in_array('single_quote', $cleaners)) {
            $string = str_replace('’', "'", $string);
        }

        return $string;
    }
}
