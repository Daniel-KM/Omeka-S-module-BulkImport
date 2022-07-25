<?php declare(strict_types=1);

namespace BulkImport\Job;

use Log\Stdlib\PsrMessage;
use Omeka\Job\AbstractJob;

class Undo extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();

        /** @var \Omeka\Api\Manager $api */
        $api = $services->get('Omeka\ApiManager');
        $logger = $services->get('Omeka\Logger');
        $entityManager = $services->get('Omeka\EntityManager');

        $id = $this->getArg('bulkImportId');

        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('bulk/import/' . $id);
        $logger->addProcessor($referenceIdProcessor);

        if ($id) {
            $content = $api->search('bulk_imports', ['id' => $id, 'limit' => 1])->getContent();
            $import = is_array($content) && count($content) ? reset($content) : null;
        }

        if (empty($import)) {
            $logger->err(new PsrMessage(
                'The import #{import} does not exist.', // @translate
                ['import' => $id]
            ));
            return;
        }

        if (!$import->job()) {
            $logger->warn(new PsrMessage(
                'The import #{import} has no resource.', // @translate
                ['import' => $id]
            ));
            return;
        }

        $response = $api->search('bulk_importeds', ['job_id' => $import->job()->id()], ['returnScalar' => 'entityId']);
        $totalResults = $response->getTotalResults();

        if (!$totalResults) {
            $logger->notice(new PsrMessage(
                'No resource processed by import #{import} or resources not recorded.', // @translate
                ['import' => $id]
            ));
            return;
        }

        $logger->notice(new PsrMessage(
            'Processing undo of {total} resources for import #{import}.', // @translate
            ['total' => $totalResults, 'import' => $id]
        ));

        foreach (array_chunk($response->getContent(), 100, true) as $chunkIndex => $importedIdsResourceIds) {
            if ($this->shouldStop()) {
                $logger->warn(new PsrMessage(
                    'The job "Undo" was stopped: {count}/{total} resources deleted.', // @translate
                    ['count' => $chunkIndex * 100, 'total' => $totalResults]
                ));
                break;
            }
            // Delete medias first to avoid the entity manager issue "new entity is found"
            // with media deleted before or after items.
            $importedIdsMediaIds = $api->search('bulk_importeds', ['id' => array_keys($importedIdsResourceIds), 'entity_name' => 'media'], ['returnScalar' => 'entityId'])->getContent();
            if (count($importedIdsMediaIds)) {
                try {
                    $api->batchDelete('media', $importedIdsMediaIds, [], ['continueOnError' => true]);
                } catch (\Exception $e) {
                    // Probably nothing to do.
                }
            }
            if (count($importedIdsMediaIds) < count($importedIdsResourceIds)) {
                // Entity names may not be resources (assets), so they should be
                // removed one by one.
                // TODO Improve the possibility to delete resources in bulk (use ResourceAdapter).
                $notMediaIds = array_diff_key($importedIdsResourceIds, $importedIdsMediaIds);
                // A search is needed only to get the entity name (resource type).
                // Use return scalar for speed, because the entity ids are
                // already available.
                $importedIdsNotMediaIds = $api->search('bulk_importeds', ['id' => array_keys($notMediaIds)], ['returnScalar' => 'entityName'])->getContent();
                foreach ($importedIdsNotMediaIds as $importedId => $entityName) {
                    $entityId = $notMediaIds[$importedId] ?? null;
                    try {
                        $api->delete($entityName, $entityId);
                    } catch (\Exception $e) {
                        // Nothing to do: already deleted.
                        // TODO Implement on delete cascade in the entity Imported, but check for doctrine events (search index…).
                    }
                }
            }
            try {
                $api->batchDelete('bulk_importeds', array_keys($importedIdsResourceIds), [], ['continueOnError' => true]);
            } catch (\Exception $e) {
            }
            $entityManager->clear();
            $logger->info(new PsrMessage(
                '{count}/{total} resources deleted.', // @translate
                ['count' => $chunkIndex * 100 + count($importedIdsResourceIds), 'total' => $totalResults]
            ));
        }

        $logger->notice(new PsrMessage(
            'Undo of {total} imported resources ended for import #{import}.', // @translate
            ['total' => $totalResults, 'import' => $id]
        ));
    }
}
