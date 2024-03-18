<?php declare(strict_types=1);

namespace BulkImport;

if (!class_exists(\Common\TraitModule::class)) {
    require_once dirname(__DIR__) . '/Common/TraitModule.php';
}

use Common\Stdlib\PsrMessage;
use Common\TraitModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\ModuleManager\ModuleManager;
use Laminas\Mvc\MvcEvent;
use Omeka\Module\AbstractModule;

/**
 * Bulk Import
 *
 * @copyright Daniel Berthereau, 2018-2024
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    use TraitModule;

    const NAMESPACE = __NAMESPACE__;

    protected $dependencies = [
        'Common',
        'Log',
    ];

    public function init(ModuleManager $moduleManager): void
    {
        require_once __DIR__ . '/vendor/autoload.php';
    }

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);

        /** @var \Omeka\Permissions\Acl $acl */
        $acl = $this->getServiceLocator()->get('Omeka\Acl');

        // Any user who can create an item can use bulk upload.
        // Admins are not included because they have the rights by default.
        $roles = [
            \Omeka\Permissions\Acl::ROLE_EDITOR,
            \Omeka\Permissions\Acl::ROLE_REVIEWER,
            \Omeka\Permissions\Acl::ROLE_AUTHOR,
        ];

        $acl
            ->allow(
                $roles,
                ['BulkImport\Controller\Admin\Upload'],
                [
                    'index',
                ]
            );
    }

    protected function preInstall(): void
    {
        $services = $this->getServiceLocator();
        $translate = $services->get('ControllerPluginManager')->get('translate');
        $translator = $services->get('MvcTranslator');

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.54')) {
            $message = new \Omeka\Stdlib\Message(
                $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.54'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }

        $js = __DIR__ . '/asset/vendor/flow.js/flow.min.js';
        if (!file_exists($js)) {
            $message = new PsrMessage(
                'The libraries should be installed. See module’s installation documentation.' // @translate
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message->setTranslator($translator));
        }

        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        if (!$this->checkDestinationDir($basePath . '/xsl')) {
            $message = new PsrMessage(
                'The directory "{directory}" is not writeable.', // @translate
                ['directory' => $basePath . '/xsl']
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message->setTranslator($translator));
        }

        if (!$this->checkDestinationDir($basePath . '/bulk_import')) {
            $message = new PsrMessage(
                'The directory "{directory}" is not writeable.', // @translate
                ['directory' => $basePath . '/bulk_import']
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message->setTranslator($translator));
        }
    }

    protected function postInstall(): void
    {
        $services = $this->getServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');

        $user = $services->get('Omeka\AuthenticationService')->getIdentity();

        // The module api is not available during install/upgrade.
        require_once __DIR__ . '/src/Entity/Import.php';
        require_once __DIR__ . '/src/Entity/Importer.php';

        $directory = new \RecursiveDirectoryIterator(__DIR__ . '/data/importers', \RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($directory);
        $inflector = \Doctrine\Inflector\InflectorFactory::create()->build();
        foreach (array_keys(iterator_to_array($iterator)) as $filepath) {
            $data = include $filepath;
            $data['o:owner'] = $user;
            $entity = new \BulkImport\Entity\Importer();
            foreach ($data as $key => $value) {
                $posColon = strpos($key, ':');
                $keyName = $posColon === false ? $key : substr($key, $posColon + 1);
                $method = 'set' . ucfirst($inflector->camelize($keyName));
                if (method_exists($entity, $method)) {
                    $entity->$method($value);
                }
            }
            $entityManager->persist($entity);
        }
        $entityManager->flush();
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        $sharedEventManager->attach(
            \Omeka\Media\Ingester\Manager::class,
            'service.registered_names',
            [$this, 'handleMediaIngesterRegisteredNames']
        );

        // Add js for the item add/edit pages to manage ingester "bulk_upload".
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.add.before',
            [$this, 'addHeadersAdmin']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.edit.before',
            [$this, 'addHeadersAdmin']
        );
        // Manage the special media ingester "bulk_upload".
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.hydrate.pre',
            [$this, 'handleItemApiHydratePre']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.create.post',
            [$this, 'handleAfterSaveItem'],
            -10
        );
    }

    /**
     * Avoid to display ingester in item edit, because it's an internal one.
     */
    public function handleMediaIngesterRegisteredNames(Event $event): void
    {
        $names = $event->getParam('registered_names');
        $key = array_search('bulk', $names);
        unset($names[$key]);
        $event->setParam('registered_names', $names);
    }

    public function addHeadersAdmin(Event $event): void
    {
        $view = $event->getTarget();
        $assetUrl = $view->plugin('assetUrl');
        $view->headLink()
            ->appendStylesheet($assetUrl('css/bulk-import.css', 'BulkImport'));
        $view->headScript()
            ->appendFile($assetUrl('vendor/flow.js/flow.min.js', 'BulkImport'), 'text/javascript', ['defer' => 'defer'])
            ->appendFile($assetUrl('js/bulk-import.js', 'BulkImport'), 'text/javascript', ['defer' => 'defer']);
    }

    public function handleItemApiHydratePre(Event $event): void
    {
        $services = $this->getServiceLocator();
        $tempDir = $services->get('Config')['temp_dir'] ?: sys_get_temp_dir();
        $tempDir = rtrim($tempDir, '/\\');

        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        $data = $request->getContent();

        // Remove removed files.
        $filesData = $data['filesData'] ?? [];
        if (empty($filesData['file'])) {
            return;
        }

        foreach ($filesData['file'] ?? [] as $key => $fileData) {
            $filesData['file'][$key] = json_decode($fileData, true) ?: [];
        }

        if (empty($data['o:media'])) {
            return;
        }

        /**
         * @var \Omeka\Stdlib\ErrorStore $errorStore
         * @var \Omeka\File\TempFileFactory $tempFileFactory
         * @var \Omeka\File\Validator $validator
         */
        $errorStore = $event->getParam('errorStore');
        $settings = $services->get('Omeka\Settings');
        $validator = $services->get(\Omeka\File\Validator::class);
        $tempFileFactory = $services->get(\Omeka\File\TempFileFactory::class);
        $validateFile = (bool) $settings->get('disable_file_validation', false);
        $allowEmptyFiles = (bool) $settings->get('bulkimport_allow_empty_files', false);

        $uploadErrorCodes = [
            UPLOAD_ERR_OK => 'File successfuly uploaded.', // @translate
            UPLOAD_ERR_INI_SIZE => 'The total of file sizes exceeds the the server limit directive.', // @translate
            UPLOAD_ERR_FORM_SIZE => 'The file size exceeds the specified limit.', // @translate
            UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.', // @translate
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.', // @translate
            UPLOAD_ERR_NO_TMP_DIR => 'The temporary folder to store the file is missing.', // @translate
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.', // @translate
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.', // @translate
        ];

        $newDataMedias = [];
        foreach ($data['o:media'] as $dataMedia) {
            $newDataMedias[] = $dataMedia;

            if (empty($dataMedia['o:ingester'])
                || $dataMedia['o:ingester'] !== 'bulk_upload'
            ) {
                continue;
            }

            $index = $dataMedia['file_index'] ?? null;
            if (is_null($index) || !isset($filesData['file'][$index])) {
                $errorStore->addError('upload', 'There is no uploaded files.'); // @translate
                continue;
            }

            if (empty($filesData['file'][$index])) {
                $errorStore->addError('upload', 'There is no uploaded files.'); // @translate
                continue;
            }

            // Convert the media to a list of media for the item hydration.
            // Check errors first to indicate issues to user early.
            $listFiles = [];
            $hasError = false;
            foreach ($filesData['file'][$index] as $subIndex => $fileData) {
                // The user selected "allow partial upload", so no data for this
                // index.
                if (empty($fileData)) {
                    continue;
                }
                // Fix strict type issues in can of an issue on a file.
                $fileData['name'] ??= '';
                $fileData['tmp_name'] ??= '';
                if (!empty($fileData['error'])) {
                    $errorStore->addError('upload', new PsrMessage(
                        'File #{index} "{filename}" has an error: {error}.',  // @translate
                        ['index' => ++$subIndex, 'filename' => $fileData['name'], 'error' => $uploadErrorCodes[$fileData['error']]]
                    ));
                    $hasError = true;
                    continue;
                } elseif (substr($fileData['name'], 0, 1) === '.') {
                    $errorStore->addError('upload', new PsrMessage(
                        'File #{index} "{filename}" must not start with a ".".', // @translate
                        ['index' => ++$subIndex, 'filename' => $fileData['name']]
                    ));
                    $hasError = true;
                    continue;
                } elseif (!preg_match('/^[^\/\\\\{}$?!<>]+$/', $fileData['name'])) {
                    $errorStore->addError('upload', new PsrMessage(
                        'File #{index} "{filename}" must not contain a reserved character.', // @translate
                        ['index' => ++$subIndex, 'filename' => $fileData['name']]
                    ));
                    $hasError = true;
                    continue;
                } elseif (!preg_match('/^[^\/\\\\{}$?!<>]+$/', $fileData['tmp_name'])) {
                    $errorStore->addError('upload', new PsrMessage(
                        'File #{index} temp name "{filename}" must not contain a reserved character.', // @translate
                        ['index' => ++$subIndex, 'filename' => $fileData['tmp_name']]
                    ));
                    $hasError = true;
                    continue;
                } elseif (empty($fileData['size'])) {
                    if ($validateFile && !$allowEmptyFiles) {
                        $errorStore->addError('upload', new PsrMessage(
                            'File #{index} "{filename}" is an empty file.', // @translate
                            ['index' => ++$subIndex, 'filename' => $fileData['name']]
                        ));
                        $hasError = true;
                        continue;
                    }
                } else {
                    // Don't use uploader::upload(), because the file would be
                    // renamed, so use temp file validator directly.
                    // Don't check media-type directly, because it should manage
                    // derivative media-types ("application/tei+xml", etc.) that
                    // may not be extracted by system.
                    $tempFile = $tempFileFactory->build();
                    $tempFile->setSourceName($fileData['name']);
                    $tempFile->setTempPath($tempDir . DIRECTORY_SEPARATOR . $fileData['tmp_name']);
                    if (!$validator->validate($tempFile, $errorStore)) {
                        // Errors are already stored.
                        continue;
                    }
                }
                $listFiles[] = $fileData;
            }
            if ($hasError) {
                continue;
            }

            // Remove the added media directory from list of media.
            array_pop($newDataMedias);
            foreach ($listFiles as $index => $fileData) {
                $dataMedia['ingest_file_data'] = $fileData;
                $newDataMedias[] = $dataMedia;
            }
        }

        $data['o:media'] = $newDataMedias;
        $request->setContent($data);
    }

    public function handleAfterSaveItem(Event $event): void
    {
        // Prepare thumbnailing only if needed.
        $needThumbnailing = false;

        /**
         * @var \Omeka\Entity\Item $item
         * @var \Omeka\Entity\Media $media
         */
        $item = $event->getParam('response')->getContent();
        foreach ($item->getMedia() as $media) {
            if (!$media->hasThumbnails()
                && $media->getMediaType()
                && $media->getIngester() === 'bulk_upload'
            ) {
                $needThumbnailing = true;
                break;
            }
        }

        if (!$needThumbnailing) {
            return;
        }

        $services = $this->getServiceLocator();

        // Create the thumbnails for the media ingested with "bulk_upload" via a
        // job to avoid the 30 seconds issue with numerous files.
        $args = [
            'item_id' => $item->getId(),
            'ingester' => 'bulk_upload',
            'only_missing' => true,
        ];
        // Of course, it is useless for a background job.
        // FIXME Use a plugin, not a fake job. Or strategy "sync", but there is a doctrine exception on owner of the job.
        // $strategy = $this->isBackgroundProcess() ? $services->get(\Omeka\Job\DispatchStrategy\Synchronous::class) : null;
        $strategy = null;
        if ($this->isBackgroundProcess()) {
            $job = new \Omeka\Entity\Job();
            $job->setPid(null);
            $job->setStatus(\Omeka\Entity\Job::STATUS_IN_PROGRESS);
            $job->setClass(\BulkImport\Job\FileDerivative::class);
            $job->setArgs($args);
            $job->setOwner($services->get('Omeka\AuthenticationService')->getIdentity());
            $job->setStarted(new \DateTime('now'));
            $jobClass = new \BulkImport\Job\FileDerivative($job, $services);
            $jobClass->perform();
        } else {
            /** @var \Omeka\Job\Dispatcher $dispatcher */
            $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);
            $dispatcher->dispatch(\BulkImport\Job\FileDerivative::class, $args, $strategy);
        }
    }

    /**
     * Check if the current process is a background one.
     *
     * The library to get status manages only admin, site or api requests.
     * A background process is none of them.
     */
    protected function isBackgroundProcess(): bool
    {
        // Warning: there is a matched route ("site") for backend processes.
        /** @var \Omeka\Mvc\Status $status */
        $status = $this->getServiceLocator()->get('Omeka\Status');
        return !$status->isApiRequest()
            && !$status->isAdminRequest()
            && !$status->isSiteRequest()
            && (!method_exists($status, 'isKeyauthRequest') || !$status->isKeyauthRequest());
    }
}
