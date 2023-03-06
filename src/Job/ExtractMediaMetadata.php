<?php declare(strict_types=1);

namespace BulkImport\Job;

use Log\Stdlib\PsrMessage;
use Omeka\Job\AbstractJob;

/**
 * FIXME Warning: don't use shouldStop(), since it may be a fake job (see Module).
 */
class ExtractMediaMetadata extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();

        // Log is a dependency of the module, so add some data.
        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('bulk/extract/job_' . $this->job->getId());

        $logger = $services->get('Omeka\Logger');
        $logger->addProcessor($referenceIdProcessor);

        $settings = $services->get('Omeka\Settings');
        if (!$settings->get('bulkimport_extract_metadata', false)) {
            $logger->warn(new PsrMessage(
                'The setting to extract metadata is not set.' // @translate
            ));
            return;
        }

        $itemId = (int) $this->getArg('itemId');
        if (!$itemId) {
            $logger->warn(new PsrMessage(
                'No item is set.' // @translate
            ));
            return;
        }

        /** @var \Omeka\Api\Manager $api */
        $api = $services->get('Omeka\ApiManager');
        try {
            $item = $api->read('items', ['id' => $itemId], [], ['responseContent' => 'resource'])->getContent();
        } catch (\Exception $e) {
            $logger->err(new PsrMessage(
                'The item #{item_id} is not available.', // @translate
                ['item_id' => $itemId]
            ));
            return;
        }

        // Process only new medias if existing medias are set.
        $skipMediaIds = $this->getArg('skipMediaIds', []);

        /**
         * @var \BulkImport\Mvc\Controller\Plugin\Bulk $bulk
         * @var \BulkImport\Mvc\Controller\Plugin\ExtractMediaMetadata $extractMediaMetadata
         */
        $plugins = $services->get('ControllerPluginManager');
        $bulk = $plugins->get('bulk');
        $extractMediaMetadata = $plugins->get('extractMediaMetadata');

        $propertyIds = $bulk->getPropertyIds();

        /** TODO Remove for Omeka v4. */
        if (!function_exists('array_key_last')) {
            function array_key_last(array $array)
            {
                return empty($array) ? null : key(array_slice($array, -1, 1, true));
            }
        }

        $totalExtracted = 0;
        /** @var \Omeka\Entity\Media $media */
        foreach ($item->getMedia() as $media) {
            $mediaId = $media->getId();
            if ($skipMediaIds && in_array($mediaId, $skipMediaIds)) {
                continue;
            }
            $extractedData = $extractMediaMetadata->__invoke($media);
            if ($extractedData === null) {
                continue;
            }
            ++$totalExtracted;
            if (!$extractedData) {
                continue;
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
                    $id = $bulk->getResourceClassId($value);
                    $data['o:resource_class'] = $id ? ['o:id' => $id] : null;
                } elseif ($field === 'o:resource_template') {
                    $value = array_key_last($values);
                    $id = $bulk->getResourceTemplateId($value);
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

            if (!$data) {
                continue;
            }

            try {
                $api->update('media', ['id' => $media->getId()], $data, [], ['isPartial' => true]);
            } catch (\Exception $e) {
                $logger->err(
                    'Media #{media_id}: an issue occurred during update: {exception}.', // @translate
                    ['media_id' => $media->getId(), 'exception' => $e]
                );
            }
        }

        $logger->notice(
            'Item #{item_id}: data extracted for {total} media.', // @translate
            ['item_id' => $item->getId(), 'total' => $totalExtracted]
        );
    }
}
