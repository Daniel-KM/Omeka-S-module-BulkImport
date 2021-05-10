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

        $response = $api->search('bulk_importeds', ['job_id' => $import->job()->id()]);
        $totalResults = $response->getTotalResults();

        if (!$totalResults) {
            $logger->notice(new PsrMessage(
                'No resource processed by import #{import}.', // @translate
                ['import' => $id]
            ));
            return;
        }

        $logger->notice(new PsrMessage(
            'Processing undo of {total} resources for import #{import}.', // @translate
            ['total' => $totalResults, 'import' => $id]
        ));

        foreach (array_chunk($response->getContent(), 100) as $chunkIndex => $importedsChunk) {
            if ($this->shouldStop()) {
                $logger->warn(new PsrMessage(
                    'The job "Undo" was stopped: {count}/{total} resources deleted.', // @translate
                    ['count' => $chunkIndex * 100, 'total' => $totalResults]
                ));
                break;
            }
            foreach ($importedsChunk as $imported) {
                try {
                    $api->delete('bulk_importeds', $imported->id());
                    $api->delete($imported->resourceType(), $imported->entityId());
                } catch (\Exception $e) {
                    // Nothing to do: already deleted.
                    // TODO Implement on delete cascade in the entity Imported.
                }
            }
            $logger->info(new PsrMessage(
                '{count}/{total} resources deleted.', // @translate
                ['count' => $chunkIndex * 100 + count($importedsChunk), 'total' => $totalResults]
            ));
        }

        $logger->notice(new PsrMessage(
            'Undo of {total} imported resources ended for import #{import}.', // @translate
            ['total' => $totalResults, 'import' => $id]
        ));
    }
}
