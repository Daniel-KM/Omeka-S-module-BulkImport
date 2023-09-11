<?php declare(strict_types=1);

namespace BulkImport\Job;

use ArrayObject;
use BulkImport\Entry\Entry;
use BulkImport\Interfaces\Parametrizable;
use Log\Stdlib\PsrMessage;
use Omeka\Api\Representation\AbstractEntityRepresentation;
use Omeka\Api\Representation\AssetRepresentation;

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
     * @var \Omeka\Api\Manager
     */
    protected $entityManager;

    /**
     * @var \BulkImport\Mvc\Controller\Plugin\FindResourcesFromIdentifiers
     */
    protected $findResourcesFromIdentifiers;

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
     * @var array
     */
    protected $identifierNames = [
        'o:id' => 'o:id',
        // 'dcterms:identifier' => 10,
    ];

    /**
     * Store the source identifiers for each index, reverted and mapped.
     * Manage possible duplicate identifiers.
     *
     * The keys are filled during first loop and values when found or available.
     *
     * Identifiers are the id and the main resource name: "§resources" or
     * "§assets" is appended to the numeric id.
     *
     * @todo Remove "mapx" and "revert" ("revert" is only used to get "mapx"). "mapx" is a short to map[source index]. But a source can have no identifier and only an index.
     *
     * @var array
     */
    protected $identifiers = [
        // Source index to identifiers + suffix (array).
        'source' => [],
        // Identifiers  + suffix to source indexes (array).
        'revert' => [],
        // Source indexes to resource id + suffix.
        'mapx' => [],
        // Source identifiers + suffix to resource id + suffix.
        'map' => [],
    ];

    /**
     * Manage ids from different tables.
     *
     * @var array
     */
    protected $mainResourceNames = [
        'resources' => 'resources',
        'items' => 'resources',
        'item_sets' => 'resources',
        'media' => 'resources',
        'annotations' => 'resources',
        'assets' => 'assets',
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
            ->prepareListOfIdentifiers()
            ->prepareListOfIds();

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
        $this
            ->bulkCheckLog
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

            $this->extractSourceIdentifiers($resource, $entry);
        }

        // Clean identifiers for duplicates.
        $this->identifiers['source'] = array_map('array_unique', $this->identifiers['source']);

        // Simplify identifiers revert.
        $this->identifiers['revert'] = array_map('array_unique', $this->identifiers['revert']);
        foreach ($this->identifiers['revert'] as &$values) {
            $values = array_combine($values, $values);
        }
        unset($values);

        // Empty identifiers should be null to use isset().
        // A foreach is quicker than array_map.
        foreach ($this->identifiers['mapx'] as &$val) {
            if (!$val) {
                $val = null;
            }
        }
        unset($val);
        foreach ($this->identifiers['map'] as &$val) {
            if (!$val) {
                $val = null;
            }
        }
        unset($val);

        $this->logger->notice(
            'End of initial listing of identifiers: {total_resources} resources to process, {total_identifiers} unique identifiers, {total_skipped} skipped, {total_processed} processed, {total_empty} empty, {total_errors} errors.', // @translate
            [
                'total_resources' => $this->totalIndexResources,
                'total_identifiers' => count($this->identifiers['map']),
                'total_skipped' => $this->totalSkipped,
                'total_processed' => $this->totalProcessed,
                'total_empty' => $this->totalEmpty,
                'total_errors' => $this->totalErrors,
            ]
        );

        return $this;
    }

    /**
     * Get the list of ids from identifiers one time.
     */
    protected function prepareListOfIds(): self
    {
        if (empty($this->identifiers['map'])) {
            return $this;
        }

        $this->logger->notice(
            'Start preparing ids from {count} source identifiers.', // @translate
            ['count' => count($this->identifiers['map'])]
        );

        $mainResourceName = $this->mainResourceNames[$this->resourceName];

        // Process only identifiers without ids (normally all of them).
        $emptyIdentifiers = [];
        foreach ($this->identifiers['map'] as $identifier => $id) {
            if (empty($id)) {
                $emptyIdentifiers[] = strtok((string) $identifier, '§');
            }
        }

        // TODO Manage assets.
        if ($mainResourceName === 'assets') {
            $ids = $this->findAssetsFromIdentifiers($emptyIdentifiers, $this->identifierNames);
        } elseif ($mainResourceName === 'resources') {
            $ids = $this->findResourcesFromIdentifiers($emptyIdentifiers, $this->identifierNames);
        }

        foreach ($ids as $identifier => $id) {
            $this->identifiers['map'][$identifier . '§' . $mainResourceName] = $id
                ? $id . '§' . $mainResourceName
                : null;
        }

        // Fill mapx when possible.
        foreach ($ids as $identifier => $id) {
            if (!empty($this->identifiers['revert'][$identifier . '§' . $mainResourceName])) {
                $this->identifiers['mapx'][reset($this->identifiers['revert'][$identifier . '§' . $mainResourceName])]
                    = $id . '§' . $mainResourceName;
            }
        }

        $this->identifiers['mapx'] = array_map(function ($v) {
            return $v ?: null;
        }, $this->identifiers['mapx']);
        $this->identifiers['map'] = array_map(function ($v) {
            return $v ?: null;
        }, $this->identifiers['map']);

        $this->logger->notice(
            'End of initial listing of {total} ids from {count} source identifiers.', // @translate
            ['total' => count(array_filter($this->identifiers['map'])), 'count' => count($this->identifiers['map'])]
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
            if (!$resource || !empty($resource['has_error'])) {
                ++$this->totalErrors;
                continue;
            }

            $this->logger->info(
                'Index #{index}: Process started', // @translate
                ['index' => $this->indexResource]
            );

            ++$this->totalProcessed;

            $representation = $this->processor->processResource($resource, $this->indexResource);

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

        $bulkDiffResources = $plugins->get('bulkDiffResources');
        $result = $bulkDiffResources($this->action, $importId);
        if ($result['status'] === 'error') {
            // Log is already logged.
            ++$this->totalErrors;
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            return $this;
        }

        // TODO To be fixed: processor mapping.

        // Only spreadsheet is managed for now.
        // Spreadsheet is processor-driven, so no meta mapper mapping.
        if (!$this->hasProcessorMapping) {
            return $this;
        }

        $bulkDiffValues = $plugins->get('bulkDiffValues');
        $result = $bulkDiffValues($this->action, $importId, $this->mapping);
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

        // TODO Normalize process for entry (remove entry in fact).
        if ($entry instanceof \BulkImport\Entry\JsonEntry) {
            $data = $entry->getArrayCopy();
        } elseif ($entry instanceof \BulkImport\Entry\XmlEntry) {
            $data = $entry->getXmlCopy();
        } elseif ($entry instanceof \BulkImport\Entry\SpreadsheetEntry) {
            $data = $entry->getArrayCopy();
        } else {
            $data = $entry->getArrayCopy();
        }

        $resource = $this->metaMapper->convert($data);

        // Fill the result into the entity as array object.
        return $this->processor->fillResource($resource, $this->indexResource);
    }

    /**
     * Extract main and linked resource identifiers from a resource.
     *
     * @todo Check for duplicates.
     * @todo Take care of actions (update/create).
     * @todo Take care of specific identifiers (uri, filename, etc.).
     * @todo Make a process and an output with all identifiers only.
     * @todo Store the resolved id existing in database one time here (after deduplication), and remove the search of identifiers in second loop.
     */
    protected function extractSourceIdentifiers(?array $resource): self
    {
        if (!$resource) {
            return $this;
        }

        $storeMain = function ($idOrIdentifier, $mainResourceName) use ($resource): void {
            if ($idOrIdentifier) {
                // No check for duplicates here: it depends on action.
                $this->identifiers['source'][$this->indexResource][] = $idOrIdentifier . '§' . $mainResourceName;
                $this->identifiers['revert'][$idOrIdentifier . '§' . $mainResourceName][$this->indexResource] = $this->indexResource;
            }
            // Source indexes to resource id.
            $this->identifiers['mapx'][$this->indexResource] = empty($resource['o:id'])
                ? null
                : $resource['o:id'] . '§' . $mainResourceName;
            if ($idOrIdentifier) {
                // Source identifiers to resource id.
                // No check for duplicate here: last map is the right one.
                $this->identifiers['map'][$idOrIdentifier . '§' . $mainResourceName] = empty($resource['o:id'])
                    ? null
                    : $resource['o:id'] . '§' . $mainResourceName;
            }
        };

        $storeLinkedIdentifier = function ($idOrIdentifier, $vrId, $mainResourceName): void {
            // As soon as an array exists, a check can be done on identifier,
            // even if the id is defined later. The same for map.
            if (!isset($this->identifiers['revert'][$idOrIdentifier . '§' . $mainResourceName])) {
                $this->identifiers['revert'][$idOrIdentifier . '§' . $mainResourceName] = [];
            }
            $this->identifiers['map'][$idOrIdentifier . '§' . $mainResourceName] = $vrId;
        };

        $mainResourceName = $this->mainResourceNames[$this->resourceName];

        // Main identifiers.
        foreach ($this->identifierNames as $identifierName) {
            if ($identifierName === 'o:id') {
                $storeMain($resource['o:id'] ?? null, $mainResourceName);
            } elseif ($identifierName === 'o:storage_id') {
                $storeMain($resource['o:storage_id'] ?? null, $mainResourceName);
            } elseif ($identifierName === 'o:name') {
                $storeMain($resource['o:name'] ?? null, $mainResourceName);
            } else {
                // TODO Normally already initialized.
                $term = $this->bulk->propertyTerm($identifierName);
                foreach ($resource[$term] ?? [] as $value) {
                    if (!empty($value['@value'])) {
                        $storeMain($value['@value'], 'resources');
                    }
                }
            }
        }

        // TODO Move these checks in resource and asset processors.

        // Specific identifiers for items (item sets and media).
        if ($resource['resource_name'] === 'items') {
            foreach ($resource['o:item_set'] ?? [] as $itemSet) {
                if (!empty($itemSet['source_identifier'])) {
                    $storeLinkedIdentifier($itemSet['source_identifier'], $itemSet['o:id'] ?? null, 'resources');
                }
            }
            foreach ($resource['o:media'] ?? [] as $media) {
                if (!empty($media['source_identifier'])) {
                    $storeLinkedIdentifier($media['source_identifier'], $media['o:id'] ?? null, 'resources');
                }
            }
        }

        // Specific identifiers for media (item).
        elseif ($resource['resource_name'] === 'media') {
            if (!empty($resource['o:item']['source_identifier'])) {
                $storeLinkedIdentifier($resource['o:item']['source_identifier'], $resource['o:item']['o:id'] ?? null, 'resources');
            }
        }

        // Specific identifiers for resources attached to assets.
        elseif ($resource['resource_name'] === 'assets') {
            foreach ($resource['o:resource'] ?? [] as $thumbnailForResource) {
                if (!empty($thumbnailForResource['source_identifier'])) {
                    $storeLinkedIdentifier($thumbnailForResource['source_identifier'], $thumbnailForResource['o:id'] ?? null, 'resources');
                }
            }
        }

        // TODO It's now possible to store an identifier for the asset from the resource.

        // Store identifiers for linked resources.
        $properties = $this->bulk->propertyIds();
        foreach (array_intersect_key($resource, $properties) as $term => $values) {
            if (!is_array($values)) {
                continue;
            }
            foreach ($values as $value) {
                if (is_array($value)
                    && isset($value['property_id'])
                    && !empty($value['source_identifier'])
                ) {
                    $storeLinkedIdentifier($value['source_identifier'], $value['value_resource_id'] ?? null, 'resources');
                }
            }
        }

        return $this;
    }

    /**
     * Store new id when source contains identifiers not yet imported.
     *
     * Identifiers are already stored during first loop. So just set final id.
     *
     * @todo Factorize ImportTrait with AbstractResourceProcessor.
     */
    protected function storeSourceIdentifiersIds(array $dataResource, AbstractEntityRepresentation $resource): self
    {
        $resourceId = $resource->id();
        if (empty($resourceId) || empty($dataResource['source_index'])) {
            return $this;
        }

        $resourceName = $resource instanceof AssetRepresentation ? 'assets' : $resource->resourceName();
        $mainResourceName = $this->mainResourceNames[$resourceName];

        // Source indexes to resource id (filled when found or created).
        $this->identifiers['mapx'][$dataResource['source_index']] = $resourceId . '§' . $mainResourceName;

        // Source identifiers to resource id (filled when found or created).
        // No check for duplicate here: last map is the right one.
        foreach ($this->identifiers['source'][$dataResource['source_index']] ?? [] as $idOrIdentifierWithResourceName) {
            $this->identifiers['map'][$idOrIdentifierWithResourceName] = $resourceId . '§' . $mainResourceName;
        }

        return $this;
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

    /**
     * Find a list of resource ids from a list of identifiers (or one id).
     *
     * When there are true duplicates and case insensitive duplicates, the first
     * case sensitive is returned, else the first case insensitive resource.
     *
     * @todo Manage Media source html.
     *
     * @uses\BulkImport\Mvc\Controller\Plugin\FindResourcesFromIdentifiers
     *
     * @param array|string $identifiers Identifiers should be unique. If a
     * string is sent, the result will be the resource.
     * @param string|int|array $identifierName Property as integer or term,
     * "o:id", a media ingester (url or file), or an associative array with
     * multiple conditions (for media source). May be a list of identifier
     * metadata names, in which case the identifiers are searched in a list of
     * properties and/or in internal ids.
     * @param string $resourceName The resource type, name or class, if any.
     * @param \BulkImport\Stdlib\MessageStore $messageStore
     * @return array|int|null|Object Associative array with the identifiers as key
     * and the ids or null as value. Order is kept, but duplicate identifiers
     * are removed. If $identifiers is a string, return directly the resource
     * id, or null. Returns standard object when there is at least one duplicated
     * identifiers in resource and the option "$uniqueOnly" is set.
     *
     * Note: The option uniqueOnly is not taken in account. The object or the
     * boolean are not returned, but logged.
     * Furthermore, the identifiers without id are not returned.
     *
     * @todo Factorize findResourcesFromIdentifiers() in AbstractResourceImport with ImportTrait.
     */
    public function findResourcesFromIdentifiers(
        $identifiers,
        $identifierName = null,
        $resourceName = null,
        // TODO Remove message store.
        ?\BulkImport\Stdlib\MessageStore $messageStore = null
    ) {
        // TODO Manage non-resources here? Or a different helper for assets?

        $identifierName = $identifierName ?: $this->identifierNames;
        $result = $this->findResourcesFromIdentifiers->__invoke($identifiers, $identifierName, $resourceName, true);

        $isSingle = !is_array($identifiers);

        // Log duplicate identifiers.
        if (is_object($result)) {
            $result = (array) $result;
            if ($isSingle) {
                $result['result'] = [$identifiers => $result['result']];
                $result['count'] = [$identifiers => $result['count']];
            }

            // Remove empty identifiers.
            $result['result'] = array_filter($result['result']);

            // TODO Remove the logs from here.
            foreach (array_keys($result['result']) as $identifier) {
                if ($result['count'][$identifier] > 1) {
                    if ($messageStore) {
                        $messageStore->addWarning('identifier', new PsrMessage(
                            'Identifier "{identifier}" is not unique ({count} values). First is #{id}.', // @translate
                            ['identifier' => $identifier, 'count' => $result['count'][$identifier], 'id' => $result['result'][$identifier]]
                        ));
                    } else {
                        $this->logger->warn(
                            'Identifier "{identifier}" is not unique ({count} values). First is #{id}.', // @translate
                            ['identifier' => $identifier, 'count' => $result['count'][$identifier], 'id' => $result['result'][$identifier]]
                        );
                    }
                    // if (!$this->allowDuplicateIdentifiers) {
                    //     unset($result['result'][$identifier]);
                    // }
                }
            }

            if (!$this->allowDuplicateIdentifiers) {
                if ($messageStore) {
                    $messageStore->addError('identifier', new PsrMessage(
                        'Duplicate identifiers are not allowed.' // @translate
                    ));
                } else {
                    $this->logger->err(
                        'Duplicate identifiers are not allowed.' // @translate
                    );
                }
                return $isSingle ? null : [];
            }

            $result = $isSingle ? reset($result['result']) : $result['result'];
        } else {
            // Remove empty identifiers.
            if (!$isSingle) {
                $result = array_filter($result);
            }
        }

        return $result;
    }

    protected function findAssetsFromIdentifiers(array $identifiers, $identifierNames): array
    {
        // Extract all ids and identifiers: there are only two unique columns in
        // assets (id and storage id) and the table is generally small and the
        // api doesn't allow to search them.
        // The name is allowed too, even if not unique.

        if (!$identifiers || !$identifierNames) {
            return [];
        }

        if (!is_array($identifierNames)) {
            $identifierNames = [$identifierNames];
        }

        // TODO Allow to store statically ids and add new identifiers and ids in the map, or check in the map first.

        $idIds = [];
        if (in_array('o:id', $identifierNames)) {
            $idIds = $this->api->search('assets', [], ['returnScalar' => 'id'])->getContent();
        }
        $idNames = [];
        if (in_array('o:name', $identifierNames)) {
            $idNames = $this->api->search('assets', [], ['returnScalar' => 'name'])->getContent();
        }
        $idStorages = [];
        if (in_array('o:storage_id', $identifierNames)) {
            $idStorages = $this->api->search('assets', [], ['returnScalar' => 'storageId'])->getContent();
        }

        if (!$idIds && !$idNames && !$idStorages) {
            return [];
        }

        $result = [];
        $identifierKeys = array_fill_keys($identifiers, null);

        // Start by name to override it because it is not unique.
        if (in_array('o:name', $identifierNames)) {
            $result += array_intersect_key(array_flip($idNames), $identifierKeys);
        }

        if (in_array('o:storage_id', $identifierNames)) {
            $result += array_intersect_key(array_flip($idStorages), $identifierKeys);
        }

        if (in_array('o:id', $identifierNames)) {
            $result += array_intersect_key($idIds, $identifierKeys);
        }

        return array_filter($result);
    }
}
