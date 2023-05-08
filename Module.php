<?php declare(strict_types=1);

namespace BulkImport;

if (!class_exists(\Generic\AbstractModule::class)) {
    require file_exists(dirname(__DIR__) . '/Generic/AbstractModule.php')
        ? dirname(__DIR__) . '/Generic/AbstractModule.php'
        : __DIR__ . '/src/Generic/AbstractModule.php';
}

use Generic\AbstractModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\ModuleManager\ModuleManager;
use Laminas\Mvc\MvcEvent;
use Log\Stdlib\PsrMessage;
use Omeka\Entity\Media;
use Omeka\Module\Exception\ModuleCannotInstallException;

class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

    protected $dependencies = [
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

        if (PHP_VERSION_ID < 70400) {
            $message = new PsrMessage(
                'Since version {version}, this module requires php 7.4.', // @translate
                ['version' => '3.4.39']
            );
            throw new ModuleCannotInstallException((string) $message);
        }

        $js = __DIR__ . '/asset/vendor/flow.js/flow.min.js';
        if (!file_exists($js)) {
            $message = new PsrMessage(
                'The libraries should be installed. See module’s installation documentation.' // @translate
            );
            throw new ModuleCannotInstallException((string) $message);
        }

        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        if (!$this->checkDestinationDir($basePath . '/xsl')) {
            $message = new PsrMessage(
                'The directory "{path}" is not writeable.', // @translate
                ['path' => $basePath . '/xsl']
            );
            throw new ModuleCannotInstallException((string) $message);
        }

        if (!$this->checkDestinationDir($basePath . '/bulk_import')) {
            $message = new PsrMessage(
                'The directory "{path}" is not writeable.', // @translate
                ['path' => $basePath . '/bulk_import']
            );
            throw new ModuleCannotInstallException((string) $message);
        }
    }

    protected function postInstall(): void
    {
        $services = $this->getServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');

        $user = $services->get('Omeka\AuthenticationService')->getIdentity();

        // The resource "bulk_importers" is not available during upgrade.
        require_once __DIR__ . '/src/Entity/Import.php';
        require_once __DIR__ . '/src/Entity/Importer.php';

        $directory = new \RecursiveDirectoryIterator(__DIR__ . '/data/importers', \RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($directory);
        foreach (array_keys(iterator_to_array($iterator)) as $filepath) {
            $data = include $filepath;
            $data['owner'] = $user;
            $entity = new \BulkImport\Entity\Importer();
            foreach ($data as $key => $value) {
                $method = 'set' . ucfirst($key);
                $entity->$method($value);
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

        // Manage the conversion of documents to html.
        // Manage the extraction of medata from medias.
        // The process should be done only for new medias, so keep the list
        // of existing medias before processing.
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.update.pre',
            [$this, 'handleBeforeSaveItem'],
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.create.post',
            [$this, 'handleAfterSaveItem'],
            -10
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.update.post',
            [$this, 'handleAfterSaveItem'],
            -10
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\MediaAdapter::class,
            'api.create.post',
            [$this, 'handleAfterCreateMedia'],
            -10
        );

        $sharedEventManager->attach(
            \Omeka\Form\SettingForm::class,
            'form.add_elements',
            [$this, 'handleMainSettings']
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

    /**
     * Store ids of existing medias to avoid to process them twice.
     */
    public function handleBeforeSaveItem(Event $event): void
    {
        /**
         * @var \Omeka\Api\Request $request
         */
        $request = $event->getParam('request');

        $itemId = (int) $request->getId();
        if (!$itemId) {
            return;
        }

        /** @var \Omeka\Api\Manager $api */
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        try {
            /** @var \Omeka\Api\Representation\ItemRepresentation $item */
            $item = $api->read('items', $itemId, [], ['initialize' => false, 'finalize' => false])->getContent();
        } catch (\Exception $e) {
            return;
        }

        $mediaIds = [];
        foreach ($item->getMedia() as $media) {
            $mediaId = (int) $media->getId() ?? null;;
            if ($mediaId) {
                $mediaIds[$mediaId] = $mediaId;
            }
        }
        $this->storeExistingItemMediaIds($itemId, $mediaIds);
    }

    public function handleAfterSaveItem(Event $event): void
    {
        // TODO Use a process "pre" to get html and metadata when url file is not stored (rare).

        // Process conversion of documents to html if set.
        // And prepare thumbnailing if needed.
        $needThumbnailing = false;

        // Process extraction of metadata only when there is an original file.
        $hasFile = false;

        /**
         * @var \Omeka\Entity\Item $item
         * @var \Omeka\Entity\Media $media
         */
        $item = $event->getParam('response')->getContent();
        foreach ($item->getMedia() as $media) {
            if (!$media->getMediaType()) {
                continue;
            }
            $hasFile = true;
            $this->afterSaveMedia($media);
            if (!$needThumbnailing
                && $media->getIngester() === 'bulk_upload'
                && !$media->hasThumbnails()
            ) {
                $needThumbnailing = true;
            }
        }

        $services = $this->getServiceLocator();

        if ($hasFile
            && $services->get('Omeka\Settings')->get('bulkimport_extract_metadata', false)
        ) {
            $itemId = $item->getId();
            // Run a job for item to avoid the 30 seconds issue with many files.
            $args = [
                'itemId' => $itemId,
                'skipMediaIds' => $this->storeExistingItemMediaIds($itemId),
            ];
            // FIXME Use a plugin, not a fake job. Or strategy "sync", but there is a doctrine exception on owner of the job.
            // Of course, it is useless for a background job.
            // $strategy = $this->isBackgroundProcess() ? $services->get(\Omeka\Job\DispatchStrategy\Synchronous::class) : null;
            $strategy = null;
            if ($this->isBackgroundProcess()) {
                $job = new \Omeka\Entity\Job();
                $job->setPid(null);
                $job->setStatus(\Omeka\Entity\Job::STATUS_IN_PROGRESS);
                $job->setClass(\BulkImport\Job\ExtractMediaMetadata::class);
                $job->setArgs($args);
                $job->setOwner($services->get('Omeka\AuthenticationService')->getIdentity());
                $job->setStarted(new \DateTime('now'));
                $jobClass = new \BulkImport\Job\ExtractMediaMetadata($job, $services);
                $jobClass->perform();
            } else {
                /** @var \Omeka\Job\Dispatcher $dispatcher */
                $dispatcher = $services->get(\Omeka\Job\Dispatcher::class);
                $dispatcher->dispatch(\BulkImport\Job\ExtractMediaMetadata::class, $args, $strategy);
            }
        }

        if (!$needThumbnailing) {
            return;
        }

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

    public function handleAfterCreateMedia(Event $event): void
    {
        /** @var \Omeka\Entity\Media $media */
        $media = $event->getParam('response')->getContent();
        if (!$media->getMediaType()) {
            return;
        }
        $this->afterSaveMedia($media, true);
    }

    /**
     * @todo Use the same process (job) for extract html and extract metadata.
     *
     * @param Media $media Media with a media type.
     */
    protected function afterSaveMedia(Media $media, bool $isSingleMediaCreation = false): void
    {
        static $processedMedia = [];

        $mediaId = $media->getId();
        if (isset($processedMedia[$mediaId])) {
            return;
        }
        $processedMedia[$mediaId] = true;

        $services = $this->getServiceLocator();
        /** @var \Doctrine\ORM\EntityManager $entityManager */
        $entityManager = $services->get('Omeka\EntityManager');

        if ($isSingleMediaCreation) {
            $settings = $services->get('Omeka\Settings');
            if ($settings->get('bulkimport_extract_metadata', false)) {
                $extractFileMetadata = $services->get('ControllerPluginManager')->get('extractFileMetadata');
                $result = $extractFileMetadata->__invoke($media);
                if ($result) {
                    $entityManager->refresh($media);
                }
            }
        }

        $html = $this->convertToHtml($media);
        if (is_null($html)) {
            return;
        }

        $basePath = $services->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        // There is no thumbnails, else keep them anyway.
        @unlink($basePath . '/original/' . $media->getFilename());

        $mediaData = $media->getData() ?: [];
        $mediaData['html'] = $html;
        $media->setData($mediaData);
        $media->setRenderer('html');
        $media->setExtension(null);
        $media->setMediaType(null);
        $media->setHasOriginal(false);
        $media->setSha256(null);
        $media->setStorageId(null);

        $entityManager->persist($media);
        $entityManager->flush();
    }

    protected function convertToHtml(Media $media): ?string
    {
        static $settingsTypes;
        static $basePath;

        if (is_null($settingsTypes)) {
            $services = $this->getServiceLocator();
            $settings = $services->get('Omeka\Settings');
            $convertTypes = $settings->get('bulkimport_convert_html', []);
            $settingsTypes = [
                'doc' => 'MsDoc',
                'docx' => 'Word2007',
                'html' => 'HTML',
                'htm' => 'HTML',
                'odt' => 'ODText',
                'rtf' => 'RTF',
            ];
            $settingsTypes = array_intersect_key($settingsTypes, array_flip($convertTypes));
            $basePath = $services->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        }

        if (!$settingsTypes) {
            return null;
        }

        /** @var \Omeka\Entity\Media $media */
        // Api create post: the media is already saved.
        $filename = $media->getFilename();
        if (!$filename) {
            return null;
        }

        // TODO Manage cloud paths.
        $filepath = $basePath . '/original/' . $filename;
        $mediaType = $media->getMediaType();
        $extension = $media->getExtension();

        if (!file_exists($filepath) || !is_readable($filepath)) {
            return null;
        }

        $types = [
            'application/msword' => 'MsDoc',
            'application/rtf' => 'RTF',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'Word2007',
            'application/vnd.oasis.opendocument.text' => 'ODText',
            'text/html' => 'HTML',
            'doc' => 'MsDoc',
            'docx' => 'Word2007',
            'html' => 'HTML',
            'htm' => 'HTML',
            'odt' => 'ODText',
            'rtf' => 'RTF',
        ];
        $phpWordType = $types[$mediaType] ?? $types[$extension] ?? null;
        if (empty($phpWordType)
            || !in_array($phpWordType, $settingsTypes)
        ) {
            return null;
        }

        $phpWord = \PhpOffice\PhpWord\IOFactory::load($filepath, $phpWordType);
        $htmlWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'HTML');
        $html = $htmlWriter->getContent();
        if (!$html) {
            return null;
        }

        $startBody = mb_strpos($html, '<body>') + 6;
        $endBody = mb_strrpos($html, '</body>');
        return trim(mb_substr($html, $startBody, $endBody - $startBody))
            ?: null;
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
            && !$status->isSiteRequest();
    }

   /**
     * Check or create the destination folder.
     *
     * @param string $dirPath Absolute path.
     * @return string|null
     */
    protected function checkDestinationDir(string $dirPath): ?string
    {
        if (file_exists($dirPath)) {
            if (!is_dir($dirPath) || !is_readable($dirPath) || !is_writeable($dirPath)) {
                $this->getServiceLocator()->get('Omeka\Logger')->err(
                    'The directory "{path}" is not writeable.', // @translate
                    ['path' => $dirPath]
                );
                return null;
            }
            return $dirPath;
        }

        $result = @mkdir($dirPath, 0775, true);
        if (!$result) {
            $this->getServiceLocator()->get('Omeka\Logger')->err(
                'The directory "{path}" is not writeable: {error}.', // @translate
                ['path' => $dirPath, 'error' => error_get_last()['message']]
            );
            return null;
        }
        return $dirPath;
    }

    protected function storeExistingItemMediaIds(?int $itemId = null, ?array $mediaIds = null): ?array
    {
        static $store = [];
        if (!$itemId) {
            return $store;
        }
        if  (is_null($mediaIds)) {
            return $store[$itemId] ?? [];
        }
        $store[$itemId] = $mediaIds;
        return null;
    }
}
