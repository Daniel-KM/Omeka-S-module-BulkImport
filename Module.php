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

    protected function preInstall(): void
    {
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
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.create.pre',
            [$this, 'handleBeforeCreateItem'],
            -10
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.update.pre',
            [$this, 'handleBeforeCreateItem'],
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

    public function handleBeforeCreateItem(Event $event): void
    {
        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');

        // This is the item representation array of the filtered post when the
        // user save an item directly edited, but not during a batch edit.
        // During a batch edit or some other process, the content may be empty,
        // so there is no process to do.
        $item = $request->getContent();
        if (is_array($item) && !count($item) || empty($item['o:media'])) {
            return;
        }

        $filesData = $request->getFileData();

        foreach ($item['o:media'] as $mediaIndex => $media) {
            $html = $this->convertToHtml($media, $filesData);
            if (is_null($html)) {
                continue;
            }

            // Remove only temp files, not sideload files.
            if (in_array($media['o:ingester'], ['upload', 'url'])) {
                unlink($filesData['file'][$media['file_index']]['tmp_name']);
            }
            $item['o:media'][$mediaIndex]['html'] = $html;
            $item['o:media'][$mediaIndex]['o:ingester'] = 'html';
            unset($item['o:media'][$mediaIndex]['file_index']);
            unset($filesData['file'][$media['file_index']]);
        }

        $request->setContent($item);
        $request->setFileData($filesData);
    }

    public function handleAfterCreateMedia(Event $event): void
    {
        /**
         * @var \Omeka\Api\Response $response
         * @var \Omeka\Entity\Media $media
         */
        $response = $event->getParam('response');
        $media = $response->getContent();
        $html = $this->convertToHtml($media);
        if (is_null($html)) {
            return;
        }

        $services = $this->getServiceLocator();
        $basePath = $services->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $entityManager = $services->get('Omeka\EntityManager');

        // There is no thumbnails, else keep them anyway.
        @unlink($basePath . '/original/' . $media->getFilename());

        $mediaData = $media->getData($data) ?: [];
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

    protected function convertToHtml($media, array $filesData = []): ?string
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

        $isItemMedia = is_array($media);

        if ($isItemMedia) {
            if ($filesData) {
                if (empty($filesData['file'])) {
                    return null;
                }
                if (!isset($media['file_index'])
                    || empty($filesData['file'][$media['file_index']])
                ) {
                    return null;
                }
                $mediaFileData = $filesData['file'][$media['file_index']];
                if (!empty($mediaFileData['error'])
                    || empty($mediaFileData['name'])
                    || empty($mediaFileData['type'])
                    || empty($mediaFileData['size'])
                    || empty($mediaFileData['tmp_name'])
                ) {
                    return null;
                }
                $mediaType = $mediaFileData['type'];
                $extension = strtolower(pathinfo($mediaFileData['name'], PATHINFO_EXTENSION));
                $filepath = $mediaFileData['tmp_name'];
            } else {
                // TODO Manage sideload and url ingesters.
                return null;
            }
        } else {
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
        }

        if (!$filepath || !file_exists($filepath) || !is_readable($filepath)) {
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
