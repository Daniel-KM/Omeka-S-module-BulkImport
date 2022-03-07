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

    protected $dependency = 'Log';

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
        $js = __DIR__ . '/asset/vendor/flow.js/flow.min.js';
        if (!file_exists($js)) {
            $services = $this->getServiceLocator();
            $t = $services->get('MvcTranslator');
            throw new ModuleCannotInstallException(
                sprintf(
                    $t->translate('The library "%s" should be installed.'), // @translate
                    'javascript'
                ) . ' '
                . $t->translate('See module’s installation documentation.') // @translate
            );
        }

        $config = $this->getServiceLocator()->get('Config');
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

        // TODO Re-enable the check when patch https://github.com/omeka-s-modules/CSVImport/pull/182 will be included.
        /*
        // The version of Box/Spout should be >= 3.0, but there is no version
        // inside the library, so check against a class.
        // This check is needed, because CSV Import still uses version 2.7.
        if (class_exists(\Box\Spout\Reader\ReaderFactory::class)) {
            $message = 'The dependency Box/Spout version should be >= 3.0. See readme.'; // @translate
            throw new \Omeka\Module\Exception\ModuleCannotInstallException($message);
        }
        */
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
     *
     * @param Event $event
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
                    if ($validateFile) {
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
        // Process conversion of documents to html if set.
        // And prepare thumbnailling if needed.
        $needThumbnailing = false;
        /**
         * @var \Omeka\Entity\Item $item
         * @var \Omeka\Entity\Media $media
         */
        $item = $event->getParam('response')->getContent();
        foreach ($item->getMedia() as $media) {
            $this->afterSaveMedia($media);
            if (!$needThumbnailing
                && $media->getIngester() === 'bulk_upload'
                && !$media->hasThumbnails()
            ) {
                $needThumbnailing = true;
            }
        }

        if (!$needThumbnailing) {
            return;
        }

        // Create the thumbnails for the media ingested with "bulk_upload" via a job.
        /** @var \Omeka\Job\Dispatcher $dispatcher */
        $dispatcher = $this->getServiceLocator()->get(\Omeka\Job\Dispatcher::class);
        $dispatcher->dispatch(\BulkImport\Job\FileDerivative::class, [
            'item_id' => $item->getId(),
            'ingester' => 'bulk_upload',
            'only_missing' => true,
        ]);
    }

    public function handleAfterCreateMedia(Event $event): void
    {
        $media = $event->getParam('response')->getContent();
        $this->afterSaveMedia($media);
    }

    protected function afterSaveMedia(Media $media): void
    {
        static $processedMedia = [];

        $mediaId = $media->getId();
        if (isset($processedMedia[$mediaId])) {
            return;
        }
        $processedMedia[$mediaId] = true;

        $html = $this->convertToHtml($media);
        if (is_null($html)) {
            return;
        }

        $services = $this->getServiceLocator();
        $basePath = $services->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $entityManager = $services->get('Omeka\EntityManager');

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
     * Check or create the destination folder.
     *
     * @param string $dirPath Absolute path.
     * @return string|null
     */
    protected function checkDestinationDir(string $dirPath): ?string
    {
        if (file_exists($dirPath)) {
            if (!is_dir($dirPath) || !is_readable($dirPath) || !is_writable($dirPath)) {
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
}
