<?php declare(strict_types=1);

namespace BulkImport\Job;

use ArrayObject;
use BulkImport\Entry\Entry;
use BulkImport\Interfaces\Parametrizable;

/**
 * Manage the process of import with a reader, a mapper and a processor.
 *
 * It uses the Job import as a base for now.
 */
trait ImportTrait
{
    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var \Omeka\Api\Adapter\Manager
     */
    protected $adapterManager;

    /**
     * @var \BulkImport\Mvc\Controller\Plugin\Bulk
     */
    protected $bulk;

    /**
     * @var \BulkImport\Mvc\Controller\Plugin\BulkCheckLog
     */
    protected $bulkCheckLog;

    /**
     * @var \BulkImport\Mvc\Controller\Plugin\BulkIdentifiers
     */
    protected $bulkIdentifiers;

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

    /**
     * @var \Laminas\Log\Logger
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
     * @var \BulkImport\Api\Representation\ImportRepresentation
     */
    protected $import;

    /**
     * @var \BulkImport\Api\Representation\ImporterRepresentation
     */
    protected $importer;

    /**
     * @var \BulkImport\Reader\Reader
     */
    protected $reader;

    /**
     * @var array
     */
    protected $mapping;

    /**
     * @var \BulkImport\Processor\Processor
     */
    protected $processor;

    /**
     * @var int
     */
    protected $toSkip = 0;

    /**
     * @var int
     */
    protected $maxEntries = 0;

    /**
     * @var string
     */
    protected $resourceLabel;

    /**
     * @var string
     */
    protected $configFormClass;

    /**
     * @var string
     */
    protected $paramsFormClass;

    /**
     * Allowed fields to create or update resources.
     *
     * See resource or asset processor for default.
     *
     * @see \Omeka\Api\Representation\AssetRepresentation
     * @var array
     */
    protected $metadataData = [
        // @todo Currently not used.
        'fields' => [],
        'skip' => [],
        // List of keys that can have only one value.
        'boolean' => [],
        // List of keys that can have only a single metadata.
        'single_data' => [
            'resource_name' => null,
            // Generic.
            'o:id' => null,
        ],
        // Keys that can have only one value that is an entity with an id.
        'single_entity' => [
            // Generic.
            'o:owner' => null,
        ],
        // TODO Attach to resources early. May be not managed.
        'multiple_entities' => [],
    ];

    /**
     * @var ArrayObject
     */
    protected $base;

    /**
     * May be :
     * - dry_run
     * - stop_on_error
     * - continue_on_error
     *
     * The behavior for missing files is set with "processingSkipMissingFile".
     *
     * @var string
     */
    protected $processingError;

    /**
     *
     * @var bool
     */
    protected $skipMissingFiles;

    /**
     * @var string
     */
    protected $action;

    /**
     * @var string
     */
    protected $actionUnidentified;

    /**
     * @var bool
     */
    protected $allowDuplicateIdentifiers = false;

    /**
     * @var \Omeka\File\TempFileFactory
     */
    protected $tempFileFactory;

    /**
     * The current resource name to process.
     *
     * @var string
     */

    protected $resourceName;

    /**
     * @see AbstractResourceProcessor.
     *
     * The identifiers should be the id for properties for quick check and for
     * use in UpdateResource().
     *
     * @var array
     */
    protected $identifierNames = [
        'o:id' => 'o:id',
        // 'dcterms:identifier' => 10,
    ];

    /**
     * Index of the current entry.
     *
     * The entry may be 0-based or 1-based, or inconsistent (IteratorIterator).
     * So this index is a stable one-based index.
     *
     * @todo It is a duplicate of indexResource for now.
     *
     * @var int
     */
    protected $currentEntryIndex = 0;

    /**
     * @var int
     */
    protected $totalToProcess = 0;

    /**
     * @var int
     */
    protected $totalIndexResources = 0;

    /**
     * @var int
     */
    protected $indexResource = 0;

    /**
     * @var int
     */
    protected $totalSkipped = 0;

    /**
     * @var int
     */
    protected $totalProcessed = 0;

    /**
     * @var int
     */
    protected $totalEmpty = 0;

    /**
     * @var int
     */
    protected $totalErrors = 0;

    protected function process(): self
    {
        // Prepare the file where the checks will be saved.
        $result = $this
            ->bulkCheckLog
            ->setBaseName($this->processor->getLabel())
            ->setNameFile((string) $this->getArg('bulk_import_id'))
            ->initializeCheckStore()
            ->initializeCheckLog();
        if ($result['status'] === 'error') {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->processFinalize();
            return $this;
        }

        // Store the file in params to get it in user interface and next.
        /** @var \Omeka\Entity\Job $jobJob */
        if ($this->job->getId()) {
            $jobArgs = $this->job->getArgs();
            $jobArgs['filename_log'] = basename($this->bulkCheckLog->getFilepathLog());
            $this->job->setArgs($jobArgs);
            $this->entityManager->persist($this->job);
            $this->entityManager->flush();
        }

        // Process  the import for the resource name.
        $this->resourceName = $this->processor->getResourceName();
        $this->reader->setResourceName($this->resourceName);

        // Prepare params one time for all loops.

        // Total processable, but some may be skipped by params.
        $this->totalToProcess = method_exists($this->reader, 'count')
            ? $this->reader->count()
            : null;

        $this->allowDuplicateIdentifiers = false;
        $this->toSkip = 0;
        $this->maxEntries = 0;
        $this->processingError = 'stop_on_error';
        $this->skipMissingFiles = false;
        $infoDiffs = false;

        $isProcessorParametrizable = $this->processor instanceof \BulkImport\Interfaces\Parametrizable;

        if ($isProcessorParametrizable) {
            $this->allowDuplicateIdentifiers = (bool) $this->processor->getParam('allow_duplicate_identifiers', false);
            $this->toSkip = (int) $this->processor->getParam('entries_to_skip', 0);
            $this->maxEntries = (int) $this->processor->getParam('entries_max', 0);
            $this->processingError = (string) $this->processor->getParam('processing', 'stop_on_error') ?: 'stop_on_error';
            $this->skipMissingFiles = (bool) $this->processor->getParam('skip_missing_files', false);
            $infoDiffs = (bool) $this->processor->getParam('info_diffs');
        }

        if ($this->toSkip === 1) {
            $this->logger->notice('The first entry is skipped by user.'); // @translate
        } elseif ($this->toSkip > 1) {
            $this->logger->notice(
                'The first {count} entries are skipped by user.', // @translate
                ['count' => $this->toSkip]
            );
        }

        if ($this->maxEntries === 1) {
            $this->logger->notice('Only one entry will be processed.'); // @translate
        } elseif ($this->maxEntries > 1) {
            $this->logger->notice(
                'Only {count} entries will be processed.', // @translate
                ['count' => $this->maxEntries]
            );
        }

        // Step 1/3: list and prepare identifiers.

        // Reset counts.
        $this->currentEntryIndex = 0;
        $this->totalIndexResources = 0;
        $this->indexResource = 0;
        $this->totalSkipped = 0;
        $this->totalProcessed = 0;
        $this->totalEmpty = 0;
        $this->totalErrors = 0;

        $this
            ->prepareListOfIdentifiers();

        // Step 2/3: process all rows to get errors.

        // Reset counts.
        $this->currentEntryIndex = 0;
        $this->totalIndexResources = 0;
        $this->indexResource = 0;
        $this->totalSkipped = 0;
        $this->totalProcessed = 0;
        $this->totalEmpty = 0;
        $this->totalErrors = 0;

        $this
            ->prepareFullRun();

        if ($infoDiffs) {
            $this->processInfoDIffs();
        }

        if ($this->processingError === 'dry_run') {
            $this->logger->notice(
                'Processing is ended: dry run.' // @translate
            );
            $this->processFinalize();
            return $this;
        }

        if ($this->totalErrors) {
            $this->logger->notice(
                $this->totalErrors <= 1
                    ? '{total} error has been found during checks.' // @translate
                    : '{total} errors have been found during checks.', // @translate
                ['total' => $this->totalErrors]
            );
            if ($this->processingError === 'stop_on_error') {
                $this->logger->notice(
                    'Processing is stopped because of error. No source was imported.' // @translate
                );
                $this->processFinalize();
                return $this;
            }
        }

        // A stop may occur during dry run. Message is already logged.
        if ($this->shouldStop()) {
            $this->processFinalize();
            return $this;
        }

        // Step 3/3: process real import.

        // Reset counts.
        $this->currentEntryIndex = 0;
        $this->totalIndexResources = 0;
        $this->indexResource = 0;
        $this->totalSkipped = 0;
        $this->totalProcessed = 0;
        $this->totalEmpty = 0;
        $this->totalErrors = 0;

        $this->processFullRun();

        $this->processFinalize();

        return $this;
    }

    protected function processFinalize(): self
    {
        $this->bulkCheckLog
            ->purgeCheckStore()
            ->finalizeCheckLog();
        return $this;
    }

    /**
     * @todo Move preparation of identifier names inside the adapter or the controller.
     */
    protected function prepareIdentifierNames(): self
    {
        if (!$this->processor instanceof Parametrizable) {
            return $this;
        }

        $processorParams = $this->processor->getParams();

        $identifierNames = $this->processor->getParam('identifier_name', $this->identifierNames);
        if (empty($identifierNames)) {
            $this->identifierNames = [];
            $processorParams['identifier_name'] = $this->identifierNames;
            $this->processor->setParams($processorParams);
            $this->logger->warn(
                'No identifier name was selected.' // @translate
            );
            return $this;
        }

        if (!is_array($identifierNames)) {
            $identifierNames = [$identifierNames];
        }

        // For quicker search, prepare the ids of the properties.
        $result = [];
        foreach ($identifierNames as $identifierName) {
            $id = $this->bulk->propertyId($identifierName);
            if ($id) {
                $result[$this->bulk->propertyTerm($id)] = $id;
            } else {
                $result[$identifierName] = $identifierName;
            }
        }
        $result = array_filter($result);
        if (empty($result)) {
            ++$this->totalErrors;
            $this->logger->err(
                'Invalid identifier names: check your params.' // @translate
            );
        }
        $this->identifierNames = $result;

        $processorParams['identifier_name'] = $this->identifierNames;
        $this->processor->setParams($processorParams);

        return $this;
    }

    /**
     * Get the list of identifiers without any check.
     */
    protected function prepareListOfIdentifiers(): self
    {
        if (!$this->identifierNames) {
            return $this;
        }

        $this->logger->notice(
            'Start listing all identifiers from source data.' // @translate
        );

        $skip = $this->toSkip;
        $maxRemaining = $this->maxEntries;

        // Manage the case where the reader is zero-based or one-based.
        // Note: AppendIterator can return the same index multiple times (inner
        // iterator), so use an incrementor and use the combinaison of the main
        // iterator and the inner iterator for logs.
        // TODO Add log for the main iterator and the inner iterator.
        $this->currentEntryIndex = 0;

        // The main index is human one-based.

        foreach ($this->reader as /* $innerIndex => */ $entry) {
            ++$this->currentEntryIndex;
            if ($this->shouldStop()) {
                $this->logger->warn(
                    'Index #{index}: The job "Import" was stopped during initial listing of identifiers.', // @translate
                    ['index' => $this->indexResource]
                );
                break;
            }

            if ($this->totalProcessed && $this->totalProcessed % 100 === 0) {
                if ($this->totalToProcess) {
                    $this->logger->notice(
                        '{total_processed}/{total_resources} resources processed during initial listing of identifiers, {total_skipped} skipped, {total_empty} empty, {total_errors} errors.', // @translate
                        [
                            'total_processed' => $this->totalProcessed,
                            'total_resources' => $this->totalToProcess,
                            'total_skipped' => $this->totalSkipped,
                            'total_empty' => $this->totalEmpty,
                            'total_errors' => $this->totalErrors,
                        ]
                    );
                } else {
                    $this->logger->notice(
                        '{total_processed} resources processed during initial listing of identifiers, {total_skipped} skipped, {total_empty} empty, {total_errors} errors.', // @translate
                        [
                            'total_processed' => $this->totalProcessed,
                            'total_skipped' => $this->totalSkipped,
                            'total_empty' => $this->totalEmpty,
                            'total_errors' => $this->totalErrors,
                        ]
                    );
                }
            }

            // Note: a resource from the reader may contain multiple resources.
            $this->indexResource = $this->currentEntryIndex;

            if ($skip) {
                --$skip;
                ++$this->totalSkipped;
                continue;
            }

            if ($this->maxEntries) {
                --$maxRemaining;
                if ($maxRemaining < 0) {
                    $this->logger->warn(
                        'Index #{index}: The job "Import" was stopped during initial listing of identifiers: max {count} entries processed.', // @translate
                        ['index' => $this->indexResource, 'count' => $this->maxEntries]
                    );
                    break;
                }
            }

            // During this first loop, the entry cannot be processed fully when
            // there are identifiers and relations.
            ++$this->totalIndexResources;
            $resource = $this->processEntry($entry);

            if ($resource === null) {
                ++$this->totalEmpty;
                continue;
            }

            $this->bulkIdentifiers->extractSourceIdentifiers($resource);
        }

        $this->bulkIdentifiers->finalizeStorageIdentifiers($this->resourceName);

        $this->logger->notice(
            'End of initial listing of {total} ids from {count} source identifiers.', // @translate
            ['total' => $this->bulkIdentifiers->countMappedIdentifiers(), 'count' => $this->bulkIdentifiers->countIdentifiers()]
        );

        $this->logger->notice(
            'End of initial listing of identifiers: {total_resources} resources to process, {total_identifiers} unique identifiers, {total_skipped} skipped, {total_processed} processed, {total_empty} empty, {total_errors} errors.', // @translate
            [
                'total_resources' => $this->totalIndexResources,
                'total_identifiers' => $this->bulkIdentifiers->countIdentifiers(),
                'total_skipped' => $this->totalSkipped,
                'total_processed' => $this->totalProcessed,
                'total_empty' => $this->totalEmpty,
                'total_errors' => $this->totalErrors,
            ]
        );

        return $this;
    }

    /**
     * Check all source data (dry run).
     *
     * Check:
     * - identifiers,
     * - linked resources,
     * - template values,
     * - files presence.
     *
     * Prepare the list of identifiers one time too (existing and new ones).
     *
     * Store resources without errors for next step.
     */
    protected function prepareFullRun(): self
    {
        $this->logger->notice(
            'Start checking of source data.' // @translate
        );

        $skip = $this->toSkip;
        $maxRemaining = $this->maxEntries;

        // Manage the case where the reader is zero-based or one-based.
        // Note: AppendIterator can return the same index multiple times (inner
        // iterator), so use an incrementor and use the combinaison of the main
        // iterator and the inner iterator for logs.
        // TODO Add log for the main iterator and the inner iterator.
        $this->currentEntryIndex = 0;

        foreach ($this->reader as /* $innerIndex => */ $entry) {
            ++$this->currentEntryIndex;
            if ($this->shouldStop()) {
                $this->logger->warn(
                    'Index #{index}: The job "Import" was stopped during initial checks.', // @translate
                    ['index' => $this->indexResource]
                );
                break;
            }

            if ($this->totalProcessed && $this->totalProcessed % 100 === 0) {
                if ($this->totalToProcess) {
                    $this->logger->notice(
                        '{total_processed}/{total_resources} resources checked, {total_skipped} skipped, {total_empty} empty, {total_errors} errors inside data.', // @translate
                        [
                            'total_processed' => $this->totalProcessed,
                            'total_resources' => $this->totalToProcess,
                            'total_skipped' => $this->totalSkipped,
                            'total_empty' => $this->totalEmpty,
                            'total_errors' => $this->totalErrors,
                        ]
                    );
                } else {
                    $this->logger->notice(
                        '{total_processed} resources checked, {total_skipped} skipped, {total_empty} empty, {total_errors} errors inside data.', // @translate
                        [
                            'total_processed' => $this->totalProcessed,
                            'total_skipped' => $this->totalSkipped,
                            'total_empty' => $this->totalEmpty,
                            'total_errors' => $this->totalErrors,
                        ]
                    );
                }
            }

            // Note: a resource from the reader may contain multiple resources.
            $this->indexResource = $this->currentEntryIndex;

            if ($skip) {
                $this->bulkCheckLog->logCheckedResource($this->indexResource, null, null);
                --$skip;
                ++$this->totalSkipped;
                continue;
            }

            if ($this->maxEntries) {
                --$maxRemaining;
                if ($maxRemaining < 0) {
                    $this->logger->warn(
                        'Index #{index}: The job "Import" was stopped during initial checks: max {count} entries checked.', // @translate
                        ['index' => $this->indexResource, 'count' => $this->maxEntries]
                    );
                    break;
                }
            }

            // TODO Reuse and complete the resource extracted during listing of identifiers: only the id may be missing. Or store during previous loop.
            ++$this->totalIndexResources;
            $resource = $this->processEntry($entry);

            if ($resource === null) {
                ++$this->totalEmpty;
            }

            if (!$resource) {
                $this->bulkCheckLog->storeCheckedResource($this->indexResource, $resource);
                $this->bulkCheckLog->logCheckedResource($this->indexResource, null, $entry);
                continue;
            }

            $resource = $this->processor->checkResource($resource, $this->indexResource);
            if ($resource['messageStore']->hasErrors()) {
                ++$this->totalErrors;
                $this->bulkCheckLog->storeCheckedResource($this->indexResource, $resource);
                $this->bulkCheckLog->logCheckedResource($this->indexResource, $resource, $entry);
                continue;
            }

            ++$this->totalProcessed;
            $this->bulkCheckLog->storeCheckedResource($this->indexResource, $resource);
            $this->bulkCheckLog->logCheckedResource($this->indexResource, $resource, $entry);
        }

        $this->logger->notice(
            'Fields used to map identifiers: {names}. Check them if the mapping is not right or when existing or linked resources are not found.', // @translate
            ['names' => implode(', ', array_keys($this->identifierNames))]
        );

        $this->logger->notice(
            'End of global check: {total_resources} resources to process, {total_skipped} skipped, {total_processed} processed, {total_empty} empty, {total_errors} errors inside data.', // @translate
            [
                'total_resources' => $this->totalIndexResources,
                'total_skipped' => $this->totalSkipped,
                'total_processed' => $this->totalProcessed,
                'total_empty' => $this->totalEmpty,
                'total_errors' => $this->totalErrors,
            ]
        );

        return $this;
    }

    protected function processFullRun(): self
    {
        $this->logger->notice(
            'Start actual import of source data.' // @translate
        );

        $skip = $this->toSkip;
        $maxRemaining = $this->maxEntries;

        $dataToProcess = [];

        // Manage the case where the reader is zero-based or one-based.
        // Note: AppendIterator can return the same index multiple times (inner
        // iterator), so use an incrementor and use the combinaison of the main
        // iterator and the inner iterator for logs.
        // TODO Add log for the main iterator and the inner iterator.
        $this->currentEntryIndex = 0;

        // Output entry too, because array_keys cannot be used in all cases.
        $entry = null;
        foreach ($this->reader as /* $innerIndex => */ $entry) {
            ++$this->currentEntryIndex;
            if ($this->shouldStop()) {
                $this->logger->warn(
                    'Index #{index}: The job "Import" was stopped.', // @translate
                    ['index' => $this->indexResource]
                );
                break;
            }

            if ($this->totalProcessed && $this->totalProcessed % 100 === 0) {
                if ($this->totalToProcess) {
                    $this->logger->notice(
                        '{total_processed}/{total_resources} resources processed, {total_skipped} skipped, {total_empty} empty, {total_errors} errors inside data.', // @translate
                        [
                            'total_processed' => $this->totalProcessed,
                            'total_resources' => $this->totalToProcess,
                            'total_skipped' => $this->totalSkipped,
                            'total_empty' => $this->totalEmpty,
                            'total_errors' => $this->totalErrors,
                        ]
                    );
                } else {
                    $this->logger->notice(
                        '{total_processed} resources processed, {total_skipped} skipped, {total_empty} empty, {total_errors} errors inside data.', // @translate
                        [
                            'total_processed' => $this->totalProcessed,
                            'total_skipped' => $this->totalSkipped,
                            'total_empty' => $this->totalEmpty,
                            'total_errors' => $this->totalErrors,
                        ]
                    );
                }
            }

            // Note: a resource from the reader may contain multiple resources.
            $this->indexResource = $this->currentEntryIndex;

            if ($skip) {
                --$skip;
                ++$this->totalSkipped;
                continue;
            }

            ++$this->totalIndexResources;

            if ($this->maxEntries) {
                --$maxRemaining;
                if ($maxRemaining < 0) {
                    break;
                }
            }

            // TODO Clarify computation of total errors.
            $resource = $this->bulkCheckLog->loadCheckedResource($this->indexResource);

            // Skip empty source.
            if ($resource === null) {
                ++$this->totalEmpty;
                ++$this->totalProcessed;
                continue;
            }

            if (!$resource
                || !empty($resource['has_error'])
                || (isset($resource['messageStore']) && $resource['messageStore']->hasErrors())
            ) {
                ++$this->totalErrors;
                continue;
            }

            $this->logger->info(
                'Index #{index}: Process started', // @translate
                ['index' => $this->indexResource]
            );

            ++$this->totalProcessed;

            $resource['source_index'] = $this->indexResource;
            $representation = $this->processor->processResource($resource);

            if ($representation) {
                $this->recordCreatedResources([$representation]);
            }

            // Avoid memory issue.
            unset($dataToProcess);
            $this->entityManager->flush();
            $this->entityManager->clear();
            // Reset for next.
            $dataToProcess = [];
        }

        if ($this->maxEntries && $maxRemaining < 0) {
            $this->logger->warn(
                'Index #{index}: The job "Import" was stopped: max {count} entries processed.', // @translate
                ['index' => $this->indexResource, 'count' => $this->maxEntries]
            );
        }

        $this->logger->notice(
            'End of process: {total_resources} resources to process, {total_skipped} skipped, {total_processed} processed, {total_empty} empty, {total_errors} errors inside data. Note: errors can occur separately for each imported file.', // @translate
            [
                'total_resources' => $this->totalIndexResources,
                'total_skipped' => $this->totalSkipped,
                'total_processed' => $this->totalProcessed,
                'total_empty' => $this->totalEmpty,
                'total_errors' => $this->totalErrors,
            ]
        );

        return $this;
    }

    protected function processInfoDIffs(): self
    {
        $importId = (int) $this->getArg('bulk_import_id');

        $plugins = $this->getServiceLocator()->get('ControllerPluginManager');

        /** @var \BulkImport\Mvc\Controller\Plugin\BulkDiffResources $bulkDiffResources*/
        $bulkDiffResources = $plugins->get('bulkDiffResources');
        $result = $bulkDiffResources($this->action, $importId);
        if ($result['status'] === 'error') {
            // Log is already logged.
            ++$this->totalErrors;
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            return $this;
        }

        /** @var \BulkImport\Mvc\Controller\Plugin\BulkDiffValues $bulkDiffValues*/
        $bulkDiffValues = $plugins->get('bulkDiffValues');
        $result = $bulkDiffValues($this->action, $importId, $this->metaMapper->getMetaMapping());
        if ($result['status'] === 'error') {
            // Log is already logged.
            ++$this->totalErrors;
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            return $this;
        }

        return $this;
    }

    /**
     * Process one entry to create one resource (and eventually attached ones).
     */
    protected function processEntry(Entry $entry): ?array
    {
        // Generally, empty entries in spreadsheet are empty rows. But it may be
        // a bad conversion for other formats.
        if ($entry->isEmpty()) {
            ++$this->totalEmpty;
            return null;
        }

        $metaMapping = $this->metaMapper->getMetaMapping();
        $noMapping = empty($metaMapping) || empty($metaMapping['maps']);

        // TODO Normalize process for entry (remove entry in fact).
        if ($entry instanceof \BulkImport\Entry\JsonEntry) {
            $data = $entry->getArrayCopy();
        } elseif ($entry instanceof \BulkImport\Entry\XmlEntry) {
            $data = $noMapping
                ? $entry->extractWithoutMapping()
                : $entry->getXmlCopy();
        } elseif ($entry instanceof \BulkImport\Entry\SpreadsheetEntry) {
            $data = $entry->getArrayCopy();
        } else {
            $data = $entry->getArrayCopy();
        }

        $resource = $noMapping
            ? $data
            : $this->metaMapper->convert($data);

        // Fill the result into the entity as array object.
        return $this->processor->fillResource($resource, $this->indexResource);
    }

    /**
     * @param \Omeka\Api\Representation\AbstractRepresentation[] $resources
     */
    protected function recordCreatedResources(array $resources): void
    {
        // TODO Store the bulk import id instead of the job id in order to manage regular task?
        $jobId = $this->job->getId();
        if (!$jobId) {
            return;
        }

        $classes = [];

        $importeds = [];
        foreach ($resources as $resource) {
            // The simplest way to get the adapter from any representation, when
            // the api name is unavailable.
            $class = get_class($resource);
            if (empty($classes[$class])) {
                $classes[$class] = $this->adapterManager
                    ->get(substr_replace(str_replace('\\Representation\\', '\\Adapter\\', get_class($resource)), 'Adapter', -14))
                    ->getResourceName();
            }
            $importeds[] = [
                'o:job' => ['o:id' => $jobId],
                'entity_id' => $resource->id(),
                'entity_name' => $classes[$class],
            ];
        }

        $this->api->batchCreate('bulk_importeds', $importeds, [], ['continueOnError' => true]);
    }
}
