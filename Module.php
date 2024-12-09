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
use Omeka\Entity\Media;
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

    protected function preInstall(): void
    {
        $services = $this->getServiceLocator();
        $translate = $services->get('ControllerPluginManager')->get('translate');
        $translator = $services->get('MvcTranslator');

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.64')) {
            $message = new \Omeka\Stdlib\Message(
                $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.64'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
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

        // Included application/json and application/ld+json in the whitelist of
        // media-types and json and jsonld in the whitelist of extensions.
        $settings = $services->get('Omeka\Settings');

        $whitelist = $settings->get('media_type_whitelist', []);
        $whitelist = array_unique(array_merge(array_values($whitelist), [
            'application/json',
            'application/ld+json',
        ]));
        sort($whitelist);
        $settings->set('media_type_whitelist', $whitelist);

        $whitelist = $settings->get('extension_whitelist', []);
        $whitelist = array_unique(array_merge(array_values($whitelist), [
            'json',
            'jsonld',
        ]));
        sort($whitelist);
        $settings->set('extension_whitelist', $whitelist);

        // Create default importer and exporter.
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
        // TODO Check if the listener "api.create.post" for media is still needed.
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
        }

        $services = $this->getServiceLocator();

        if ($hasFile
            && $services->get('Omeka\Settings')->get('bulkimport_extract_metadata', false)
        ) {
            $itemId = $item->getId();
            // Run a job for item to avoid the 30 seconds issue with many files.
            $args = [
                'item_id' => $itemId,
                'skip_media_ids' => $this->storeExistingItemMediaIds($itemId),
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
    }

    /**
     * Create metadata for file.
     *
     * Normally, this method is never processed directly, only via item.
     *
     * @param \Laminas\EventManager\Event $event
     */
    public function handleAfterCreateMedia(Event $event): void
    {
        /** @var \Omeka\Entity\Media $media */
        $media = $event->getParam('response')->getContent();
        if ($media->getMediaType()) {
            $this->afterSaveMedia($media, true);
        }
    }

    /**
     * @param Media $media Media with a media type and not already processed.
     */
    protected function afterSaveMedia(Media $media): void
    {
        static $processedMedia = [];

        $mediaId = $media->getId();
        if (!$mediaId || isset($processedMedia[$mediaId])) {
            return;
        }

        $processedMedia[$mediaId] = true;

        $itemId = (int) $media->getItem()->getId();
        if (!$itemId) {
            return;
        }

        /**
         * @var \Doctrine\ORM\EntityManager $entityManager
         * @var \Omeka\Settings\Settings $settings
         * @var \Common\Stdlib\EasyMeta $easyMeta
         * @var \BulkImport\Mvc\Controller\Plugin\ExtractMediaMetadata $extractMediaMetadata
         *
         *  @see \BulkImport\Job\ExtractMediaMetadata
         */
        $services = $this->getServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');
        $settings = $services->get('Omeka\Settings');

        if ($settings->get('bulkimport_extract_metadata', false)) {
            $plugins = $services->get('ControllerPluginManager');
            $easyMeta = $plugins->get('easyMeta');
            $extractMediaMetadata = $plugins->get('extractMediaMetadata');
            $extractedData = $extractMediaMetadata->__invoke($media);
            if ($extractedData) {
                /** TODO Remove for Omeka v4. */
                if (!function_exists('array_key_last')) {
                    function array_key_last(array $array)
                    {
                        return empty($array) ? null : key(array_slice($array, -1, 1, true));
                    }
                }

                // Convert the extracted metadata into properties and resource.
                // TODO Move ResourceProcessor process into a separated Filler to be able to use it anywhere.
                // For now, just manage resource class, template and properties without check neither linked resource.
                $data = [];
                foreach ($extractedData as $dest => $values) {
                    // TODO Reconvert dest.
                    $field = strtok($dest, ' ');
                    if ($field === 'o:resource_class') {
                        $value = array_key_last($values);
                        $id = $easyMeta->resourceClassId($value);
                        $data['o:resource_class'] = $id ? ['o:id' => $id] : null;
                    } elseif ($field === 'o:resource_template') {
                        $value = array_key_last($values);
                        $id = $easyMeta->resourceTemplateId($value);
                        $data['o:resource_template'] = $id ? ['o:id' => $id] : null;
                    } elseif (isset($propertyIds[$field])) {
                        $data[$field] = [];
                        $values = array_unique($values);
                        foreach ($values as $value) {
                            $data[$field][] = [
                                'type' => 'literal',
                                'property_id' => $propertyIds[$field],
                                'is_public' => true,
                                '@value' => $value,
                            ];
                        }
                    }
                }

                if ($data) {
                    try {
                        $services->get('Omeka\ApiManager')->update('media', ['id' => $mediaId], $data, [], ['isPartial' => true]);
                        $services->get('Omeka\Logger')->notice(
                            'Data extracted for media #{media_id}.', // @translate
                            ['media_id' => $mediaId]
                        );
                    } catch (\Exception $e) {
                        $services->get('Omeka\Logger')->err(
                            'Media #{media_id}: an issue occurred during update: {exception}.', // @translate
                            ['media_id' => $mediaId, 'exception' => $e]
                        );
                    }
                }
            }
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
