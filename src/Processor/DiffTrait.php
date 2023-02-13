<?php declare(strict_types=1);

namespace BulkImport\Processor;

/**
 * Manage diff of source before and after process.
 */
trait DiffTrait
{
    /**
     * @var string
     */
    protected $filepathDiff;

    /**
     * Create an output to list diff between existing data and new data.
     */
    protected function checkDiff(): self
    {
        $this->initializeDiff();
        if (!$this->filepathDiff) {
            // Log is already logged.
            ++$this->totalErrors;
            $this->job->getJob()->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            return $this;
        }

        $plugins = $this->getServiceLocator()->get('ControllerPluginManager');
        $diffResources = $plugins->get('diffResources');

        $result = [];
        // The storage is one-based.
        for ($i = 1; $i <= $this->totalIndexResources; $i++) {
            $resource2 = $this->loadCheckedResource($i);
            if ($resource2 === null) {
                continue;
            }
            if (!empty($resource2['o:id'])) {
                try {
                    $resource1 = $this->apiManager->read('resources', ['id' => $resource2['o:id']])->getContent();
                } catch (\Exception $e) {
                    $resource1 = null;
                }
            }
            $result[] = $diffResources($resource1, $resource2)->asArray();
        }

        file_put_contents($this->filepathDiff, json_encode($result, 448));

        $this->messageResultFileDiff();

        return $this;
    }

    protected function initializeDiff(): self
    {
        $this->filepathDiff = null;

        $plugins = $this->getServiceLocator()->get('ControllerPluginManager');
        $bulk = $plugins->get('bulk');

        $filepath = $bulk->prepareFile('diff', 'json');
        if ($filepath) {
            $this->filepathDiff = $filepath;
        }

        return $this;
    }

    /**
     * Add a  message with the url to the file.
     */
    protected function messageResultFileDiff(): self
    {
        $services = $this->getServiceLocator();
        $baseUrl = $services->get('Config')['file_store']['local']['base_uri'] ?: $services->get('Router')->getBaseUrl() . '/files';
        $this->logger->notice(
            'Differences between resources are available in this json {url}.', // @translate
            ['url' => $baseUrl . '/bulk_import/' . mb_substr($this->filepathDiff, mb_strlen($this->basePath . '/bulk_import/'))]
        );
        return $this;
    }
}
