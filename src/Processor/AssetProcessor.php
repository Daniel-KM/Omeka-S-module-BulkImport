<?php declare(strict_types=1);

namespace BulkImport\Processor;

use ArrayObject;
use BulkImport\Entry\Entry;
use BulkImport\Interfaces\Configurable;
use BulkImport\Interfaces\Parametrizable;
use BulkImport\Stdlib\MessageStore;
use BulkImport\Traits\ConfigurableTrait;
use BulkImport\Traits\ParametrizableTrait;
use Laminas\Form\Form;
use Log\Stdlib\PsrMessage;
use Omeka\Api\Exception\ValidationException;
use Omeka\Api\Representation\AbstractEntityRepresentation;
use Omeka\Api\Representation\AssetRepresentation;
use Omeka\Stdlib\ErrorStore;

class AssetProcessor extends AbstractProcessor implements Configurable, Parametrizable
{
    use ConfigurableTrait, ParametrizableTrait;
    use CheckTrait;
    use FileTrait;

    const ACTION_SUB_UPDATE = 'sub_update';

    protected $resourceName = 'assets';

    protected $resourceLabel = 'Assets'; // @translate

    protected $configFormClass = \BulkImport\Form\Processor\AssetProcessorConfigForm::class;

    protected $paramsFormClass = \BulkImport\Form\Processor\AssetProcessorParamsForm::class;

    /**
     * @var ArrayObject
     */
    protected $base;

    /**
     * @var string
     */
    protected $action;

    /**
     * @var string
     */
    protected $actionUnidentified;

    /**
     * Store the source identifiers for each index, reverted and mapped.
     * Manage possible duplicate identifiers.
     *
     * The keys are filled during first loop and values when found or available.
     *
     * @todo Remove "mapx" and "revert" ("revert" is only used to get "mapx"). "mapx" is a short to map[source index]. But a source can have no identifier and only an index.
     *
     * @var array
     */
    protected $identifiers = [
        // Source index to identifiers.
        'source' => [],
        // Identifiers to source indexes.
        'revert' => [],
        // Source indexes to resource id.
        'mapx' => [],
        // Source identifiers to resource id.
        'map' => [],
    ];

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
    protected $processing = 0;

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
    protected $totalErrors = 0;

    /**
     * Allowed fields to create or update assets and resource attachments.
     *
     * @see \Omeka\Api\Representation\AssetRepresentation
     * @var array
     */
    protected $fields = [
        // Assets metadata and file.
        'file',
        'url',
        'o:id',
        'o:name',
        'o:storage_id',
        'o:owner',
        'o:alt_text',
        // To attach resources.
        'o:resource',
    ];

    public function getResourceName(): ?string
    {
        return $this->resourceName;
    }

    public function getLabel(): string
    {
        return 'Assets';
    }

    public function getConfigFormClass(): string
    {
        return $this->configFormClass;
    }

    public function getParamsFormClass(): string
    {
        return $this->paramsFormClass;
    }

    public function handleConfigForm(Form $form)
    {
        $values = $form->getData();
        $defaults = [
            'processing' => 'stop_on_error',
            'entries_to_skip' => 0,
            'entries_max' => 0,
            'entries_by_batch' => null,

            'action' => null,
            'action_unidentified' => null,
            'identifier_name' => null,

            'o:owner' => null,
        ];
        $result = array_intersect_key($values, $defaults) + $defaults;
        $this->setConfig($result);
        return $this;
    }

    public function handleParamsForm(Form $form)
    {
        $values = $form->getData();
        $defaults = [
            'processing' => 'stop_on_error',
            'entries_to_skip' => 0,
            'entries_max' => 0,
            'entries_by_batch' => null,

            'action' => null,
            'action_unidentified' => null,
            'identifier_name' => null,

            'o:owner' => null,

            'mapping' => [],
        ];
        $result = array_intersect_key($values, $defaults) + $defaults;
        $this->setParams($result);
        return $this;
    }

    /**
     * @todo Factorize with AbstractResourceProcessor if needed.
     * {@inheritDoc}
     * @see \BulkImport\Processor\Processor::process()
     */
    public function process(): void
    {
        $this->initFileTrait();

        $this->prepareAction();
        if (empty($this->action)) {
            return;
        }

        $this->prepareActionUnidentified();
        if (empty($this->actionUnidentified)) {
            return;
        }

        $this
            ->prepareIdentifierNames()

            ->prepareMetaConfig();

        // There is no identifier for assets, only unique data (ids and storage
        // ids), so no missing or duplicates, so allow them.
        $this->bulk->setAllowDuplicateIdentifiers(true);

        $this->totalToProcess = method_exists($this->reader, 'count')
            ? $this->reader->count()
            : null;

        $this->base = $this->baseEntity();

        $toSkip = (int) $this->getParam('entries_to_skip', 0);
        if ($toSkip) {
            $this->logger->notice(
                $toSkip <= 1
                    ? 'The first {count} entry is skipped by user.' // @translate
                    : 'The first {count} entries are skipped by user.', // @translate
                ['count' => $toSkip]
            );
        }

        $maxEntries = (int) $this->getParam('entries_max', 0);
        if ($maxEntries) {
            $this->logger->notice(
                $maxEntries <= 1
                    ? 'Only {count} entry will be processed.' // @translate
                    : 'Only {count} entries will be processed.', // @translate
                ['count' => $maxEntries]
            );
        }

        // Prepare the file where the checks will be saved.
        $this
            ->initializeCheckStore()
            ->initializeCheckOutput();

        if ($this->totalErrors) {
            $this
                ->purgeCheckStore()
                ->finalizeCheckOutput();
            return;
        }

        // Store the file in params to get it in user interface and next.
        /** @var \Omeka\Entity\Job $jobJob */
        $jobJob = $this->job->getJob();
        if ($jobJob->getId()) {
            $jobJobArgs = $jobJob->getArgs();
            $jobJobArgs['filename_check'] = basename($this->filepathCheck);
            $jobJob->setArgs($jobJobArgs);
            $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
            $entityManager->persist($jobJob);
            $entityManager->flush();
        }

        // Step 1/3: list and prepare identifiers.

        // FIXME Ids of assets are separated from the resource ones: a collision can occur.
        $this
            ->prepareListOfIdentifiers()
            ->prepareListOfIds();

        // Step 2/3: process all rows to get errors.

        // Reset counts.
        $this->totalIndexResources = 0;
        $this->indexResource = 0;
        $this->processing = 0;
        $this->totalSkipped = 0;
        $this->totalProcessed = 0;
        $this->totalErrors = 0;

        $this->prepareFullRun();

        $processingType = $this->getParam('processing', 'stop_on_error') ?: 'stop_on_error';
        $dryRun = $processingType === 'dry_run';
        if ($dryRun) {
            $this->logger->notice(
                'Processing is ended: dry run.' // @translate
            );
            $this
                ->purgeCheckStore()
                ->finalizeCheckOutput();
            return;
        }

        if ($this->totalErrors) {
            $this->logger->notice(
                $this->totalErrors <= 1
                    ? '{total} error has been found during checks.' // @translate
                    : '{total} errors have been found during checks.', // @translate
                ['total' => $this->totalErrors]
            );
            if ($processingType === 'stop_on_error') {
                $this->logger->notice(
                    'Processing is stopped because of error. No source was imported.' // @translate
                );
                $this
                    ->purgeCheckStore()
                    ->finalizeCheckOutput();
                return;
            }
        }

        // A stop may occur during dry run. Message is already logged.
        if ($this->job->shouldStop()) {
            $this
                ->purgeCheckStore()
                ->finalizeCheckOutput();
            return;
        }

        // Step 3/3: process real import.

        // Reset counts.
        $this->totalIndexResources = 0;
        $this->indexResource = 0;
        $this->processing = 0;
        $this->totalSkipped = 0;
        $this->totalProcessed = 0;
        $this->totalErrors = 0;

        $this->processFullRun();

        $this
            ->purgeCheckStore()
            ->finalizeCheckOutput();
    }

    /**
     * Get the list of identifiers without any check.
     */
    protected function prepareListOfIdentifiers(): self
    {
        $this->identifiers = [
            // Source index to identifiers.
            'source' => [],
            // Identifiers to source indexes.
            'revert' => [],
            // Source indexes to resource id.
            'mapx' => [],
            // Source identifiers to resource id.
            'map' => [],
        ];

        $identifierNames = $this->bulk->getIdentifierNames();
        if (empty($identifierNames)) {
            return $this;
        }

        $this->logger->notice(
            'Start listing all identifiers from source data.' // @translate
        );

        $toSkip = (int) $this->getParam('entries_to_skip', 0);
        $maxEntries = (int) $this->getParam('entries_max', 0);
        $maxRemaining = $maxEntries;

        // Manage the case where the reader is zero-based or one-based.
        $firstIndexBase = null;
        foreach ($this->reader as $index => $entry) {
            if (is_null($firstIndexBase)) {
                $firstIndexBase = (int) empty($index);
            }
            if ($this->job->shouldStop()) {
                $this->logger->warn(
                    'Index #{index}: The job "Import" was stopped during initial listing of identifiers.', // @translate
                    ['index' => $this->indexResource]
                );
                break;
            }

            if ($this->totalProcessed && $this->totalProcessed % 100 === 0) {
                if ($this->totalToProcess) {
                    $this->logger->notice(
                        '{total_processed}/{total_resources} resources processed during initial listing of identifiers, {total_skipped} skipped or blank, {total_errors} errors.', // @translate
                        [
                            'total_processed' => $this->totalProcessed,
                            'total_resources' => $this->totalToProcess,
                            'total_skipped' => $this->totalSkipped,
                            'total_errors' => $this->totalErrors,
                        ]
                    );
                } else {
                    $this->logger->notice(
                        '{total_processed} resources processed during initial listing of identifiers, {total_skipped} skipped or blank, {total_errors} errors.', // @translate
                        [
                            'total_processed' => $this->totalProcessed,
                            'total_skipped' => $this->totalSkipped,
                            'total_errors' => $this->totalErrors,
                        ]
                    );
                }
            }

            // The first entry is #1, but the iterator (array) may number it 0.
            $this->indexResource = $index + $firstIndexBase;

            if ($toSkip) {
                --$toSkip;
                continue;
            }

            if ($maxEntries) {
                --$maxRemaining;
                if ($maxRemaining < 0) {
                    $this->logger->warn(
                        'Index #{index}: The job "Import" was stopped during initial listing of identifiers: max {count} entries processed.', // @translate
                        ['index' => $this->indexResource, 'count' => $maxEntries]
                    );
                    break;
                }
            }

            ++$this->totalIndexResources;
            $resource = $this->processEntry($entry);

            $this->extractSourceIdentifiers($resource, $entry);
        }

        // Clean identifiers for duplicates.
        $this->identifiers['source'] = array_map('array_unique', $this->identifiers['source']);
        $this->identifiers['revert'] = array_map('array_unique', $this->identifiers['revert']);
        $this->identifiers['mapx'] = array_map(function ($v) {
            return $v ? (int) $v : null;
        }, $this->identifiers['mapx']);
        $this->identifiers['map'] = array_map(function ($v) {
            return $v ? (int) $v : null;
        }, $this->identifiers['map']);

        $this->logger->notice(
            'End of initial listing of identifiers: {total_resources} resources to process, {total_identifiers} unique identifiers, {total_skipped} skipped or blank, {total_processed} processed, {total_errors} errors.', // @translate
            [
                'total_resources' => $this->totalIndexResources,
                'total_identifiers' => count($this->identifiers['map']),
                'total_skipped' => $this->totalSkipped,
                'total_processed' => $this->totalProcessed,
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

        // Process only identifiers without ids (normally all of them).
        $emptyIdentifiers = array_filter($this->identifiers['map'], function ($v) {
            return empty($v);
        });

        $identifierNames = $this->bulk->getIdentifierNames();
        $ids = $this->bulk->findResourcesFromIdentifiers(array_keys($emptyIdentifiers), $identifierNames);

        $this->identifiers['map'] = array_replace($this->identifiers['map'], $ids);

        // Fill mapx when possible.
        foreach ($ids as $identifier => $id) {
            if (!empty($this->identifiers['revert'][$identifier])) {
                $this->identifiers['mapx'][reset($this->identifiers['revert'][$identifier])] = $id;
            }
        }

        $this->identifiers['mapx'] = array_map(function ($v) {
            return $v ? (int) $v : null;
        }, $this->identifiers['mapx']);
        $this->identifiers['map'] = array_map(function ($v) {
            return $v ? (int) $v : null;
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

        $toSkip = (int) $this->getParam('entries_to_skip', 0);
        $maxEntries = (int) $this->getParam('entries_max', 0);
        $maxRemaining = $maxEntries;

        // TODO Do a first loop to get all identifiers and linked resources to speed up process.

        // Manage the case where the reader is zero-based or one-based.
        $firstIndexBase = null;
        foreach ($this->reader as $index => $entry) {
            if (is_null($firstIndexBase)) {
                $firstIndexBase = (int) empty($index);
            }
            if ($this->job->shouldStop()) {
                $this->logger->warn(
                    'Index #{index}: The job "Import" was stopped during initial checks.', // @translate
                    ['index' => $this->indexResource]
                );
                break;
            }

            if ($this->totalProcessed && $this->totalProcessed % 100 === 0) {
                if ($this->totalToProcess) {
                    $this->logger->notice(
                        '{total_processed}/{total_resources} resources checked, {total_skipped} skipped or blank, {total_errors} errors inside data.', // @translate
                        [
                            'total_processed' => $this->totalProcessed,
                            'total_resources' => $this->totalToProcess,
                            'total_skipped' => $this->totalSkipped,
                            'total_errors' => $this->totalErrors,
                        ]
                    );
                } else {
                    $this->logger->notice(
                        '{total_processed} resources checked, {total_skipped} skipped or blank, {total_errors} errors inside data.', // @translate
                        [
                            'total_processed' => $this->totalProcessed,
                            'total_skipped' => $this->totalSkipped,
                            'total_errors' => $this->totalErrors,
                        ]
                    );
                }
            }

            // The first entry is #1, but the iterator (array) may number it 0.
            $this->indexResource = $index + $firstIndexBase;

            if ($toSkip) {
                $this->logCheckedResource(null, null);
                --$toSkip;
                continue;
            }

            if ($maxEntries) {
                --$maxRemaining;
                if ($maxRemaining < 0) {
                    $this->logger->warn(
                        'Index #{index}: The job "Import" was stopped during initial checks: max {count} entries checked.', // @translate
                        ['index' => $this->indexResource, 'count' => $maxEntries]
                    );
                    break;
                }
            }

            // TODO Reuse and complete the resource extracted during listing of identifiers: only the id may be missing. Or store during previous loop.
            ++$this->totalIndexResources;
            $resource = $this->processEntry($entry);
            if (!$resource) {
                $this->logCheckedResource(null, $entry);
                continue;
            }

            if (!$this->checkEntity($resource)) {
                ++$this->totalErrors;
                $this->logCheckedResource($resource, $entry);
                continue;
            }

            // ++$this->processing;
            ++$this->totalProcessed;

            $this->processing = 0;

            // Only resources with messages are logged.
            if (!$resource['messageStore']->hasErrors()) {
                $this->storeCheckedResource($resource);
            }

            $this->logCheckedResource($resource, $entry);
        }

        $this->logger->notice(
            'End of global check: {total_resources} resources to process, {total_skipped} skipped or blank, {total_processed} processed, {total_errors} errors inside data.', // @translate
            [
                'total_resources' => $this->totalIndexResources,
                'total_skipped' => $this->totalSkipped,
                'total_processed' => $this->totalProcessed,
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

        $toSkip = (int) $this->getParam('entries_to_skip', 0);
        $maxEntries = (int) $this->getParam('entries_max', 0);
        $maxRemaining = $maxEntries;
        $batch = (int) $this->getParam('entries_by_batch', self::ENTRIES_BY_BATCH);

        /** @var \Doctrine\ORM\EntityManager $entityManager */
        $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');

        $shouldStop = false;
        $dataToProcess = [];
        // Manage the case where the reader is zero-based or one-based.
        $firstIndexBase = null;
        foreach ($this->reader as $index => $entry) {
            if (is_null($firstIndexBase)) {
                $firstIndexBase = (int) empty($index);
            }
            if ($shouldStop = $this->job->shouldStop()) {
                $this->logger->warn(
                    'Index #{index}: The job "Import" was stopped.', // @translate
                    ['index' => $this->indexResource]
                );
                break;
            }

            if ($this->totalProcessed && $this->totalProcessed % 100 === 0) {
                if ($this->totalToProcess) {
                    $this->logger->notice(
                        '{total_processed}/{total_resources} resources processed, {total_skipped} skipped or blank, {total_errors} errors inside data.', // @translate
                        [
                            'total_processed' => $this->totalProcessed,
                            'total_resources' => $this->totalToProcess,
                            'total_skipped' => $this->totalSkipped,
                            'total_errors' => $this->totalErrors,
                        ]
                    );
                } else {
                    $this->logger->notice(
                        '{total_processed} resources processed, {total_skipped} skipped or blank, {total_errors} errors inside data.', // @translate
                        [
                            'total_processed' => $this->totalProcessed,
                            'total_skipped' => $this->totalSkipped,
                            'total_errors' => $this->totalErrors,
                        ]
                    );
                }
            }

            if ($toSkip) {
                --$toSkip;
                continue;
            }

            ++$this->totalIndexResources;
            // The first entry is #1, but the iterator (array) may number it 0.
            $this->indexResource = $index + $firstIndexBase;

            if ($maxEntries) {
                --$maxRemaining;
                if ($maxRemaining < 0) {
                    break;
                }
            }

            // TODO Clarify computation of total errors.
            $resource = $this->loadCheckedResource();
            if (!$resource) {
                ++$this->totalErrors;
                continue;
            }

            $this->logger->info(
                'Index #{index}: Process started', // @translate
                ['index' => $this->indexResource]
            );

            ++$this->processing;
            ++$this->totalProcessed;

            $dataToProcess[] = $resource;

            // Only add every X for batch import (1 by default anyway).
            if ($this->processing >= $batch) {
                $this->processEntities($dataToProcess);
                // Avoid memory issue.
                unset($dataToProcess);
                $entityManager->flush();
                $entityManager->clear();
                // Reset for next batch.
                $dataToProcess = [];
                $this->processing = 0;
            }
        }

        // Take care of remainder from the modulo check.
        if (!$shouldStop && $dataToProcess) {
            $this->processEntities($dataToProcess);
            // Avoid memory issue.
            unset($dataToProcess);
            $entityManager->flush();
            $entityManager->clear();
        }

        if ($maxEntries && $maxRemaining < 0) {
            $this->logger->warn(
                'Index #{index}: The job "Import" was stopped: max {count} entries processed.', // @translate
                ['index' => $this->indexResource, 'count' => $maxEntries]
            );
        }

        $this->logger->notice(
            'End of process: {total_resources} resources to process, {total_skipped} skipped or blank, {total_processed} processed, {total_errors} errors inside data. Note: errors can occur separately for each imported file.', // @translate
            [
                'total_resources' => $this->totalIndexResources,
                'total_skipped' => $this->totalSkipped,
                'total_processed' => $this->totalProcessed,
                'total_errors' => $this->totalErrors,
            ]
        );

        return $this;
    }

    /**
     * Process one entry to create one resource (and eventually attached ones).
     */
    protected function processEntry(Entry $entry): ?ArrayObject
    {
        if ($entry->isEmpty()) {
            ++$this->totalSkipped;
            return null;
        }

        $mergedConfig = $this->metaMapperConfig->getMergedConfig('asset');
        return is_null($mergedConfig)
            ? $this->processEntryFromReader($entry)
            : $this->processEntryFromProcessor($entry);
    }

    /**
     * Reader-driven extraction of data.
     */
    protected function processEntryFromReader(Entry $entry): ArrayObject
    {
        /** @var \ArrayObject $resource */
        $resource = clone $this->base;

        $resource['source_index'] = $entry->index();

        // Added for security.
        $skipKeys = [
            'checked_id' => null,
            'source_index' => 0,
            'messageStore' => null,
        ];

        // List of keys that can have only one value.
        $booleanKeys = [
        ];

        $singleDataKeys = [
            'resource_name' => null,
            'file' => null,
            'url' => null,
            // Generic.
            'o:id' => null,
            // Asset.
            'o:name' => null,
            'o:media_type' => null,
            'o:storage_id' => null,
            'o:alt_text' => null,
        ];

        // Keys that can have only one value that is an entity with an id.
        $singleEntityKeys = [
            // Generic.
            'o:owner' => null,
        ];

        // TODO Attach to resources.
        /*
        $multipleEntityKeys = [
            // Attached resources for thumbnails.
            'o:resource' => null,
        ];
        */

        foreach ($entry as $key => $values) {
            if (array_key_exists($key, $skipKeys)) {
                // Nothing to do.
            } elseif (array_key_exists($key, $booleanKeys)) {
                $this->fillBoolean($resource, $key, $values);
            } elseif (array_key_exists($key, $singleDataKeys)) {
                $resource[$key] = $values;
            } elseif (array_key_exists($key, $singleEntityKeys)) {
                $this->fillSingleEntity($resource, $key, $values);
            } elseif ($resource->offsetExists($key) && is_array($resource[$key])) {
                $resource[$key] = array_merge($resource[$key], $values);
            } else {
                // Keep multiple entity keys (below) and extra data for modules.
                $resource[$key] = $values;
            }
        }

        return $resource;
    }

    /**
     * Processor-driven extraction of data.
     */
    protected function processEntryFromProcessor(Entry $entry): ArrayObject
    {
        /** @var \ArrayObject $resource */
        $resource = clone $this->base;
        $resource['source_index'] = $entry->index();
        $resource['messageStore']->clearMessages();

        $metaConfig = $this->metaMapperConfig->getMergedConfig('asset');

        foreach (['default', 'mapping'] as $section) foreach ($metaConfig[$section] as $map) {
            if (empty($map)
                // Empty from, to and mod mean a map to skip.
                || !array_filter($map)
            ) {
                continue;
            }
            if (!empty($map['has_error'])) {
                continue;
            }
            // Processor driven process.
            $values = $entry->valuesFromMap($map);
            if (!count($values)) {
                continue;
            }
            $this->fillAsset($resource, $map, $values);
        }

        return $resource;
    }

    protected function baseEntity(): ArrayObject
    {
        $resource = new ArrayObject;
        $resource['o:id'] = null;
        $resource['source_index'] = 0;
        $resource['checked_id'] = false;
        $resource['messageStore'] = new MessageStore();

        $resource['resource_name'] = 'assets';

        /** @see \Omeka\Api\Representation\AssetRepresentation */
        $ownerId = $this->getParam('o:owner', 'current') ?: 'current';
        if ($ownerId === 'current') {
            $ownerId = $this->userId;
        }
        $resource['o:owner'] = ['o:id' => $ownerId];

        $resource['o:name'] = null;
        $resource['o:filename'] = null;
        $resource['o:media_type'] = null;
        $resource['o:alt_text'] = null;

        // Storage id and extension are managed automatically.
        // Resource ids are managed separately.

        return $resource;
    }

    protected function fillAsset(ArrayObject $resource, array $map, array $values): self
    {
        $field = $map['to']['field'] ?? null;

        switch ($field) {
            default:
                break;

            case 'o:id':
                $value = (int) end($values);
                if (!$value) {
                    break;
                }
                $id = $this->identifiers['mapx'][$resource['source_index']]
                    ?? $this->bulk->api()->searchOne('assets', ['id' => $value])->getContent();
                if ($id) {
                    $resource['o:id'] = is_object($id) ? $id->id() : $id;
                    $resource['checked_id'] = true;
                } else {
                    $resource['messageStore']->addError('resource', new PsrMessage(
                        'Internal id #{id} cannot be found. The entry is skipped.', // @translate
                        ['id' => $id]
                    ));
                }
                break;

            case 'o:owner':
                $value = end($values);
                if (!$value) {
                    break;
                }
                if (is_array($value)) {
                    $id = empty($value['o:id']) ? null : $value['o:id'];
                    $email = empty($value['o:email']) ? null : $value['o:email'];
                    $value = $id ?? $email ?? reset($value);
                }
                $id = $this->bulk->getUserId($value);
                if ($id) {
                    $resource['o:owner'] = empty($email)
                        ? ['o:id' => $id]
                        : ['o:id' => $id, 'o:email' => $email];
                    } else {
                    $resource['messageStore']->addError('values', new PsrMessage(
                        'The user "{source}" does not exist.', // @translate
                        ['source' => $value]
                    ));
                }
                break;

            case 'o:name':
                // TODO Use asset o:name as an identiier? Probably not.
                $value = end($values);
                if ($value) {
                    $resource[$field] = $value;
                }
                break;

            case 'o:storage_id':
                $value = (int) end($values);
                if (!$value) {
                    break;
                }
                try {
                    $id = $this->identifiers['mapx'][$resource['source_index']]
                        ?? $this->bulk->api()->read('assets', ['storage_id' => $value])->getContent();
                } catch (\Exception $e) {
                    $id = null;
                }
                if ($id) {
                    $resource['o:id'] = is_object($id) ? $id->id() : $id;
                    $resource['checked_id'] = true;
                } else {
                    $resource['messageStore']->addError('resource', new PsrMessage(
                        'Storage id #{id} cannot be found. The entry is skipped.', // @translate
                        ['id' => $id]
                    ));
                }
                break;

            case 'o:media_type':
                $value = end($values);
                if ($value) {
                    if (preg_match('~(?:application|image|audio|video|model|text)/[\w.+-]+~', $value)) {
                        $resource[$field] = $value;
                    } else {
                        $resource['messageStore']->addError('values', new PsrMessage(
                            'The media type "{media_type}" is not valid.', // @translate
                            ['media_type' => $value]
                        ));
                    }
                }
                break;

            case 'o:alt_text':
                $value = end($values);
                $resource[$field] = $value;
                break;

            case 'url':
                $value = end($values);
                if ($value) {
                    $resource['o:ingester'] = 'url';
                    $resource['ingest_url'] = $value;
                }
                break;

            case 'file':
                $value = end($values);
                if (!$value) {
                    break;
                } elseif ($this->bulk->isUrl($value)) {
                    $resource['o:ingester'] = 'url';
                    $resource['ingest_url'] = $value;
                } else {
                    $resource['o:ingester'] = 'sideload';
                    $resource['ingest_filename'] = $value;
                }
                break;

            case 'o:resource':
                // FIXME Ids of assets are separated from the resource ones: a collision can occur.
                $identifierNames = $this->bulk->getIdentifierNames();
                // Check values one by one to manage source identifiers.
                foreach ($values as $value) {
                    $humbnailForResourceId = $this->bulk->findResourcesFromIdentifiers($value, $identifierNames, 'resources', $resource['messageStore']);
                    if ($humbnailForResourceId) {
                        $resource['o:resource'][] = [
                            'o:id' => $humbnailForResourceId,
                            'checked_id' => true,
                            // TODO Set the source identifier anywhere.
                        ];
                    } elseif (array_key_exists($value, $this->identifiers['map'])) {
                        $resource['o:resource'][] = [
                            'o:id' => $this->identifiers['map'][$value],
                            'checked_id' => true,
                            'source_identifier' => $value,
                        ];
                    } else {
                        // Only for first loop. Normally not possible after: all
                        // identifiers are stored in the list "map" during first loop.
                        $resource['messageStore']->addError('values', new PsrMessage(
                            'The value "{value}" is not a resource.', // @translate
                            ['value' => mb_substr((string) $value, 0, 50)]
                        ));
                    }
                }
                break;
        }

        return $this;
    }

    protected function fillBoolean(ArrayObject $resource, $key, $value): self
    {
        $resource[$key] = in_array(strtolower((string) $value), ['0', 'false', 'no', 'off', 'private', 'closed'], true)
            ? false
            : (bool) $value;
        return $this;
    }

    protected function fillSingleEntity(ArrayObject $resource, $key, $value): self
    {
        if (empty($value)) {
            $resource[$key] = null;
            return $this;
        }

        // Get the entity id.
        switch ($key) {
            case 'o:owner':
                if (is_array($value)) {
                    $id = empty($value['o:id']) ? null : $value['o:id'];
                    $email = empty($value['o:email']) ? null : $value['o:email'];
                    $value = $id ?? $email
                        // Check standard value too, that may be created by a
                        // xml flat mapping.
                        // TODO Remove this fix: it should be checked earlier.
                        ?? $value['@value'] ?? $value['value_resource_id']
                        ?? reset($value);
                }
                $id = $this->bulk->getUserId($value);
                if ($id) {
                    $resource['o:owner'] = empty($email)
                        ? ['o:id' => $id]
                        : ['o:id' => $id, 'o:email' => $email];
                } else {
                    $resource['o:owner'] = null;
                    $resource['messageStore']->addError('resource', new PsrMessage(
                        'The user "{source}" does not exist.', // @translate
                        ['source' => $value]
                    ));
                }
                return $this;

            default:
                return $this;
        }
    }

    /**
     * Check if a resource is well-formed.
     */
    protected function checkEntity(ArrayObject $resource): bool
    {
        if (!$this->checkId($resource)) {
            $this->fillId($resource);
        }

        if ($resource['o:id']) {
            if (!$this->actionRequiresId()) {
                if ($this->bulk->getAllowDuplicateIdentifiers()) {
                    // Action is create or skip.
                    // TODO This feature is not clear: disallow the action create when o:id is set in the source (allow only a found identifier).
                    if ($this->action === self::ACTION_CREATE) {
                        unset($resource['o:id']);
                    }
                } else {
                    $identifier = $this->extractIdentifierOrTitle($resource);
                    if ($this->action === self::ACTION_CREATE) {
                        $resource['messageStore']->addError('resource', new PsrMessage(
                            'The action "{action}" cannot have an id or a duplicate identifier ({identifier}, #{resource_id}).', // @translate
                            ['action' => $this->action, 'identifier' => $identifier, 'resource_id' => $resource['o:id']]
                        ));
                    } else {
                        $resource['messageStore']->addError('resource', new PsrMessage(
                            'The action "{action}" requires a unique identifier ({identifier}, #{resource_id}).', // @translate
                            ['action' => $this->action, 'identifier' => $identifier, 'resource_id' => $resource['o:id']]
                        ));
                    }
                }
            }
        }
        // No resource id, so it is an error, so add message if choice is skip.
        elseif ($this->actionRequiresId() && $this->actionUnidentified === self::ACTION_SKIP) {
            $identifier = $this->extractIdentifierOrTitle($resource);
            if ($this->bulk->getAllowDuplicateIdentifiers()) {
                $resource['messageStore']->addError('identifier', new PsrMessage(
                    'The action "{action}" requires an identifier ({identifier}).', // @translate
                    ['action' => $this->action, 'identifier' => $identifier]
                ));
            } else {
                $resource['messageStore']->addError('identifier', new PsrMessage(
                    'The action "{action}" requires a unique identifier ({identifier}).', // @translate
                    ['action' => $this->action, 'identifier' => $identifier]
                ));
            }
        }

        $this->checkAsset($resource);

        return !$resource['messageStore']->hasErrors();
    }

    protected function checkAsset(ArrayObject $resource): bool
    {
        // The resources are already checked when asset was filled.

        // TODO Add check for asset and file. See ResourceProcessor.

        return true;
    }

    /**
     * Check if new files (local system and urls) are available and allowed.
     *
     * By construction, it's not possible to check or modify existing files.
     */
    protected function checkNewFiles(ArrayObject $resource): bool
    {
        if (!empty($resource['o:id'])) {
            // Don't update existing file of asset.
        } elseif (!empty($resource['ingest_url'])) {
            $this->checkUrl($resource['ingest_url'], $resource['messageStore']);
        } elseif (!empty($resource['ingest_filename'])) {
            $this->checkFile($resource['ingest_filename'], $resource['messageStore']);
        } else {
            // Add a warning: cannot be checked for other media ingester? Or is it checked somewhere else?
        }
        return !$resource['messageStore']->hasErrors();
    }

    /**
     * Check the id of a resource.
     *
     * The action should be checked separately, else the result may have no
     * meaning.
     */
    protected function checkId(ArrayObject $resource): bool
    {
        if (!empty($resource['checked_id'])) {
            return !empty($resource['o:id']);
        }

        // The id is set, but not checked. So check it.
        if ($resource['o:id']) {
            // TODO getResourceName() is only in child AbstractResourceProcessor.
            if (empty($resource['resource_name'])) {
                $resourceName = method_exists($this, 'getResourceName') ? $this->getResourceName() : null;
            } else {
                $resourceName = $resource['resource_name'];
            }
            if (empty($resourceName) || $resourceName === 'resources') {
                if (isset($resource['messageStore'])) {
                    $resource['messageStore']->addError('resource_name', new PsrMessage(
                        'The resource id cannot be checked: the resource type is undefined.' // @translate
                    ));
                } else {
                    $this->logger->err(
                        'Index #{index}: The resource id cannot be checked: the resource type is undefined.', // @translate
                        ['index' => $this->indexResource]
                    );
                    $resource['has_error'] = true;
                }
            } else {
                $id = $this->bulk->api()->searchOne('assets', ['id' => $resource['o:id']])->getContent();
                if (!$id) {
                    if (isset($resource['messageStore'])) {
                        $resource['messageStore']->addError('resource_id', new PsrMessage(
                            'The id of this resource doesn’t exist.' // @translate
                        ));
                    } else {
                        $this->logger->err(
                            'Index #{index}: The id of this resource doesn’t exist.', // @translate
                            ['index' => $this->indexResource]
                        );
                        $resource['has_error'] = true;
                    }
                }
            }
        }

        $resource['checked_id'] = true;
        return !empty($resource['o:id']);
    }

    /**
     * Fill id of a resource if not set. No check is done if set, so use
     * checkId() first.
     *
     * The resource type is required, so this method should be used in the end
     * of the process.
     *
     * @return bool True if id is set.
     */
    protected function fillId(ArrayObject $resource): bool
    {
        if (is_numeric($resource['o:id'])) {
            return true;
        }

        // TODO getResourceName() is only in child AbstractResourceProcessor.
        $resourceName = empty($resource['resource_name'])
            ? $this->getResourceName()
            : $resource['resource_name'];
        if (empty($resourceName) || $resourceName === 'resources') {
            if (isset($resource['messageStore'])) {
                $resource['messageStore']->addError('resource_name', new PsrMessage(
                    'The resource id cannot be filled: the resource type is undefined.' // @translate
                ));
            } else {
                $this->logger->err(
                    'Index #{index}: The resource id cannot be filled: the resource type is undefined.', // @translate
                    ['index' => $this->indexResource]
                );
                $resource['has_error'] = true;
            }
        }

        $identifierNames = $this->bulk->getIdentifierNames();
        $key = array_search('o:id', $identifierNames);
        if ($key !== false) {
            unset($identifierNames[$key]);
        }
        if (empty($identifierNames) && !$this->actionRequiresId()) {
            return false;
        }
        if (empty($identifierNames)) {
            if ($this->bulk->getAllowDuplicateIdentifiers()) {
                if (isset($resource['messageStore'])) {
                    $resource['messageStore']->addWarning('identifier', new PsrMessage(
                        'The resource has no identifier.' // @translate
                    ));
                } else {
                    $this->logger->notice(
                        'Index #{index}: The resource has no identifier.', // @translate
                        ['index' => $this->indexResource]
                    );
                }
            } else {
                if (isset($resource['messageStore'])) {
                    $resource['messageStore']->addError('identifier', new PsrMessage(
                        'The resource id cannot be filled: no metadata defined as identifier and duplicate identifiers are not allowed.' // @translate
                    ));
                } else {
                    $this->logger->err(
                        'Index #{index}: The resource id cannot be filled: no metadata defined as identifier and duplicate identifiers are not allowed.', // @translate
                        ['index' => $this->indexResource]
                    );
                    $resource['has_error'] = true;
                }
            }
            return false;
        }

        // Don't try to fill id when resource has an error, but allow warnings.
        if (!empty($resource['has_error'])
            || (isset($resource['messageStore']) && $resource['messageStore']->hasErrors())
        ) {
            return false;
        }

        foreach (array_keys($identifierNames) as $identifierName) {
            // Get the list of identifiers from the resource metadata.
            $identifiers = [];
            if (!empty($resource[$identifierName])) {
                // Check if it is a property value.
                if (is_array($resource[$identifierName])) {
                    foreach ($resource[$identifierName] as $value) {
                        if (is_array($value)) {
                            // Check the different type of value. Only value is
                            // managed currently.
                            // TODO Check identifier that is not a property value.
                            if (isset($value['@value']) && strlen($value['@value'])) {
                                $identifiers[] = $value['@value'];
                            }
                        }
                    }
                } else {
                    // TODO Check identifier that is not a property.
                    $identifiers[] = $value;
                }
            }

            if (!$identifiers) {
                continue;
            }

            // Use source index first, because resource may have no identifier.
            $ids = !empty($this->identifiers['mapx'][$resource['source_index']])
                ? [$this->identifiers['mapx'][$resource['source_index']]]
                : $this->bulk->findResourcesFromIdentifiers($identifiers, $identifierName, $resourceName, $resource['messageStore'] ?? null);
            if (!$ids) {
                continue;
            }

            $flipped = array_flip($ids);
            if (count($flipped) > 1) {
                if (isset($resource['messageStore'])) {
                    $resource['messageStore']->addWarning('identifier', new PsrMessage(
                        'Resource doesn’t have a unique identifier. You may check options for resource identifiers.' // @translate
                    ));
                } else {
                    $this->logger->warn(
                        'Index #{index}: Resource doesn’t have a unique identifier. You may check options for resource identifiers.', // @translate
                        ['index' => $this->indexResource]
                    );
                }
                if (!$this->bulk->getAllowDuplicateIdentifiers()) {
                    if (isset($resource['messageStore'])) {
                        $resource['messageStore']->addError('identifier', new PsrMessage(
                            'Duplicate identifiers are not allowed. You may check options for resource identifiers.' // @translate
                        ));
                    } else {
                        $this->logger->err(
                            'Index #{index}: Duplicate identifiers are not allowed. You may check options for resource identifiers.', // @translate
                            ['index' => $this->indexResource]
                        );
                        $resource['has_error'] = true;
                    }
                    break;
                }
            }
            $resource['o:id'] = reset($ids);
            $resource['checked_id'] = true;
            if (isset($resource['messageStore'])) {
                $resource['messageStore']->addInfo('identifier', new PsrMessage(
                    'Identifier "{identifier}" ({metadata}) matches {resource_name} #{resource_id}.', // @translate
                    [
                        'identifier' => key($ids),
                        'metadata' => $identifierName,
                        'resource_name' => $this->bulk->label($resourceName),
                        'resource_id' => $resource['o:id'],
                    ]
                ));
            } else {
                $this->logger->info(
                    'Index #{index}: Identifier "{identifier}" ({metadata}) matches {resource_name} #{resource_id}.', // @translate
                    [
                        'index' => $this->indexResource,
                        'identifier' => key($ids),
                        'metadata' => $identifierName,
                        'resource_name' => $this->bulk->label($resourceName),
                        'resource_id' => $resource['o:id'],
                    ]
                );
            }
            return true;
        }

        return false;
    }

    /**
     * Process entities.
     */
    protected function processEntities(array $dataResources): self
    {
        switch ($this->action) {
            case self::ACTION_CREATE:
                $this->createEntities($dataResources);
                break;
            case self::ACTION_APPEND:
            case self::ACTION_REVISE:
            case self::ACTION_UPDATE:
            case self::ACTION_REPLACE:
                $this->updateEntities($dataResources);
                break;
            case self::ACTION_SKIP:
                $this->skipEntities($dataResources);
                break;
            case self::ACTION_DELETE:
                $this->deleteEntities($dataResources);
                break;
        }
        return $this;
    }

    /**
     * Process creation of entities.
     */
    protected function createEntities(array $dataResources): self
    {
        $resourceName = $this->getResourceName();
        $this->createResources($resourceName, $dataResources);
        return $this;
    }

    /**
     * Process creation of resources.
     */
    protected function createResources($resourceName, array $dataResources): self
    {
        if (!count($dataResources)) {
            return $this;
        }

        // Assets require an uploaded file, so bypass api.
        if ($resourceName === 'assets') {
            return $this->createAssets($dataResources);
        }

        // Linked ids from identifiers may be missing in data. So two solutions
        // to add missing ids: create resources one by one and add ids here, or
        // batch create and use an event to fill add ids.
        // In all cases, the ids should be stored for next resources.
        // The batch create in api adapter is more a loop than a bulk process.
        // The main difference is the automatic detachment of new entities,
        // instead of a clear. In doctrine 3, detachment will be removed.

        // So act as a loop.
        // Anyway, in most of the cases, the loop contains only one resource.

        $resources = [];
        foreach ($dataResources as $dataResource) {
            $dataResource = $this->completeResourceIdentifierIds($dataResource);
            try {
                $response = $this->bulk->api(null, true)
                    ->create($resourceName, $dataResource);
            } catch (ValidationException $e) {
                $r = $this->baseEntity();
                $r['messageStore']->addError('resource', new PsrMessage(
                    'Error during validation of the data before creation.' // @translate
                ));
                $messages = $this->listValidationMessages($e);
                $r['messageStore']->addError('resource', $messages);
                $this->logCheckedResource($r);
                ++$this->totalErrors;
                return $this;
            } catch (\Exception $e) {
                $r = $this->baseEntity();
                $r['messageStore']->addError('resource', new PsrMessage(
                    'Core error during creation: {exception}', // @translate
                    ['exception' => $e]
                ));
                $this->logCheckedResource($r);
                ++$this->totalErrors;
                return $this;
            }

            $resource = $response->getContent();
            $resources[$resource->id()] = $resource;
            $this->storeSourceIdentifiersIds($dataResource, $resource);
            if ($resource->resourceName() === 'media') {
                $this->logger->notice(
                    'Index #{index}: Created media #{media_id} (item #{item_id})', // @translate
                    ['index' => $this->indexResource, 'media_id' => $resource->id(), 'item_id' => $resource->item()->id()]
                );
            } else {
                $this->logger->notice(
                    'Index #{index}: Created {resource_name} #{resource_id}', // @translate
                    ['index' => $this->indexResource, 'resource_name' => $this->bulk->label($resourceName), 'resource_id' => $resource->id()]
                );
            }
        }

        $this->recordCreatedResources($resources);

        return $this;
    }

    /**
     * Create new assets.
     */
    protected function createAssets(array $dataResources): self
    {
        $resourceName = 'assets';

        $this->checkAssetMediaType = true;

        $baseResource = $this->baseEntity();
        $messageStore = $baseResource['messageStore'];

        $resources = [];
        foreach ($dataResources as $dataResource) {
            $resource = $this->createAsset($dataResource, $messageStore);
            if (!$resource) {
                $this->logCheckedResource($baseResource);
                ++$this->totalErrors;
                return $this;
            }

            $resources[$resource->id()] = $resource;
            $this->storeSourceIdentifiersIds($dataResource, $resource);
            $this->logger->notice(
                'Index #{index}: Created {resource_name} #{resource_id}', // @translate
                ['index' => $this->indexResource, 'resource_name' => $this->bulk->label($resourceName), 'resource_id' => $resource->id()]
            );

            $dataResource['o:id'] = $resource->id();
            if (!$this->updateThumbnailForResources($dataResource)) {
                return $this;
            }
        }

        $this->recordCreatedResources($resources);

        return $this;
    }

    /**
     * Create a new asset.
     *
     *AssetAdapter requires an uploaded file, but it's common to use urls in
     *bulk import.
     *
     * @todo Factorize with \BulkImport\Processor\AbstractResourceProcessor::createAssetFromUrl()
     */
    protected function createAsset(array $dataResource, ErrorStore $messageStore): ?AssetRepresentation
    {
        $dataResource = $this->completeResourceIdentifierIds($dataResource);
        // TODO Clarify use of ingester and allows any ingester for assets.
        $pathOrUrl = $dataResource['url'] ?? $dataResource['file']
            ?? $dataResource['ingest_url'] ?? $dataResource['ingest_filename']
            ?? null;
        $result = $this->checkFileOrUrl($pathOrUrl, $messageStore);
        if (!$result) {
            return null;
        }

        $storageId = bin2hex(\Laminas\Math\Rand::getBytes(20));
        $filename = mb_substr(basename($pathOrUrl), 0, 255);
        // TODO Set the real extension via tempFile().
        $extension = pathinfo($pathOrUrl, PATHINFO_EXTENSION);

        $isUrl = $this->bulk->isUrl($pathOrUrl);
        if ($isUrl) {
            $result = $this->fetchUrl(
                'asset',
                $filename,
                $filename,
                $storageId,
                $extension,
                $pathOrUrl
            );
            if ($result['status'] !== 'success') {
                $messageStore->addError('file', $result['message']);
                return null;
            }
            $fullPath = $result['data']['fullpath'];
        } else {
            $isAbsolutePathInsideDir = strpos($pathOrUrl, $this->sideloadPath) === 0;
            $fileinfo = $isAbsolutePathInsideDir
                ? new \SplFileInfo($pathOrUrl)
                : new \SplFileInfo($this->sideloadPath . DIRECTORY_SEPARATOR . $pathOrUrl);
            $realPath = $fileinfo->getRealPath();
            $this->store->put($realPath, 'asset/' . $storageId . '.' . $extension);
            $fullPath = $this->basePath . '/asset/' . $storageId . '.' . $extension;
        }

        // A check to get the real media-type and extension.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mediaType = $finfo->file($fullPath);
        $mediaType = \Omeka\File\TempFile::MEDIA_TYPE_ALIASES[$mediaType] ?? $mediaType;
        // TODO Get the extension from the media type or use standard asset uploaded.

        // This doctrine resource should be reloaded each time the entity
        // manager is cleared, else a error may occur on big import.
        $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
        $owner = $entityManager->find(\Omeka\Entity\User::class, $dataResource['o:owner']['o:id'] ?? $this->userId);

        $asset = new \Omeka\Entity\Asset;
        $asset->setName($dataResource['o:name'] ?? ($isUrl ? $pathOrUrl : $filename));
        // TODO Use the user specified in the config (owner).
        $asset->setOwner($owner);
        $asset->setStorageId($storageId);
        $asset->setExtension($extension);
        $asset->setMediaType($mediaType);
        $asset->setAltText($dataResource['o:alt_text'] ?? null);

        // TODO Remove this flush (required because there is a clear() after checks).
        $entityManager->persist($asset);
        $entityManager->flush();

        return $this->adapterManager->get('assets')->getRepresentation($asset);
    }

    /**
     * Process update of entities.
     */
    protected function updateEntities(array $dataResources): self
    {
        $resourceName = $this->getResourceName();

        $dataToCreateOrSkip = [];
        foreach ($dataResources as $key => $value) {
            if (empty($value['o:id'])) {
                $dataToCreateOrSkip[] = $value;
                unset($dataResources[$key]);
            }
        }
        if ($this->actionUnidentified === self::ACTION_CREATE) {
            $this->createResources($resourceName, $dataToCreateOrSkip);
        }

        $this->updateResources($resourceName, $dataResources);
        return $this;
    }

    /**
     * Process update of resources.
     */
    protected function updateResources($resourceName, array $dataResources): self
    {
        if (!count($dataResources)) {
            return $this;
        }

        // The "resources" cannot be updated directly.
        $checkResourceName = $resourceName === 'resources';

        // In the api manager, batchUpdate() allows to update a set of resources
        // with the same data. Here, data are specific to each entry, so each
        // resource is updated separately.

        // Clone is required to keep option to throw issue. The api plugin may
        // be used by other methods.
        $api = clone $this->bulk->api(null, true);
        foreach ($dataResources as $dataResource) {
            $options = [];
            $fileData = [];

            if ($checkResourceName) {
                $resourceName = $dataResource['resource_name'];
                // Normally already checked.
                if (!$resourceName) {
                    $r = $this->baseEntity();
                    $r['messageStore']->addError('resource', new PsrMessage(
                        'The resource id #"{id}" has no resource name.', // @translate
                        ['id' => $dataResource['o:id']]
                    ));
                    $this->logCheckedResource($r);
                    ++$this->totalErrors;
                    return $this;
                }
            }

            switch ($this->action) {
                case self::ACTION_APPEND:
                case self::ACTION_REPLACE:
                    $options['isPartial'] = false;
                    break;
                case self::ACTION_REVISE:
                case self::ACTION_UPDATE:
                case self::ACTION_SUB_UPDATE:
                    $options['isPartial'] = true;
                    $options['collectionAction'] = 'replace';
                    break;
                default:
                    return $this;
            }

            if ($this->action !== self::ACTION_SUB_UPDATE) {
                $dataResource = $resourceName === 'assets'
                    ? $this->updateDataAsset($resourceName, $dataResource)
                    : $this->updateData($resourceName, $dataResource);
                if (!$dataResource) {
                    return $this;
                }
            }

            try {
                $response = $api->update($resourceName, $dataResource['o:id'], $dataResource, $fileData, $options);
            } catch (ValidationException $e) {
                $r = $this->baseEntity();
                $r['messageStore']->addError('resource', new PsrMessage(
                    'Error during validation of the data before update.' // @translate
                ));
                $messages = $this->listValidationMessages($e);
                $r['messageStore']->addError('resource', $messages);
                $this->logCheckedResource($r);
                ++$this->totalErrors;
                return $this;
            } catch (\Exception $e) {
                $r = $this->baseEntity();
                $r['messageStore']->addError('resource', new PsrMessage(
                    'Core error during update: {exception}', // @translate
                    ['exception' => $e]
                ));
                $this->logCheckedResource($r);
                ++$this->totalErrors;
                return $this;
            }
            if (!$response) {
                $r = $this->baseEntity();
                $r['messageStore']->addError('resource', new PsrMessage(
                    'Unknown error occured during update.' // @translate
                ));
                ++$this->totalErrors;
                return $this;
            }

            if ($resourceName === 'assets') {
                if (!$this->updateThumbnailForResources($dataResource)) {
                    return $this;
                }
            }

            $this->logger->notice(
                'Index #{index}: Updated {resource_name} #{resource_id}', // @translate
                ['index' => $this->indexResource, 'resource_name' => $this->bulk->label($resourceName), 'resource_id' => $dataResource['o:id']]
            );
        }

        return $this;
    }

    /**
     * @see \BulkImport\Processor\ResourceUpdateTrait
     */
    protected function updateDataAsset($resourceName, array $dataResource): array
    {
        // Unlike resource, the only fields updatable via standard methods are
        // name, alternative text and attached resources.

        // Always reload the resource that is currently managed to manage
        // multiple update of the same resource.
        try {
            $this->bulk->api()->read('assets', $dataResource['o:id'], [], ['responseContent' => 'resource'])->getContent();
        } catch (\Exception $e) {
            // Normally already checked.
            $r = $this->baseEntity();
            $r['messageStore']->addError('resource', new PsrMessage(
                'Index #{index}: The resource {resource} #{id} is not available and cannot be updated.', // @translate
                ['index' => $this->indexResource, 'resource' => 'asset', 'id', $dataResource['o:id']]
            ));
            $this->logCheckedResource($r);
            ++$this->totalErrors;
            return null;
        }

        return $dataResource;
    }

    protected function updateThumbnailForResources(array $dataResource)
    {
        // The id is required to attach the asset to a resource.
        if (empty($dataResource['o:id'])) {
            return $this;
        }

        $api = clone $this->bulk->api(null, true);

        // Attach asset to the resources.
        $thumbnailResources = [];
        foreach ($dataResource['o:resource'] ?? [] as $thumbnailResource) {
            // Normally checked early.
            if (empty($thumbnailResource['resource_name'])) {
                try {
                    $thumbnailResource['resource_name'] = $api->read('resources', $thumbnailResource['o:id'], [], ['responseContent' => 'resource'])->getContent()
                        ->getResourceName();
                } catch (\Exception $e) {
                    $r = $this->baseEntity();
                    $r['messageStore']->addError('resource', new PsrMessage(
                        'The resource #{resource_id} for asset #{asset_id} does not exist.', // @translate
                        ['resource_id' => $thumbnailResource['o:id'], 'asset_id' => $dataResource['o:id']]
                    ));
                    $messages = $this->listValidationMessages(new ValidationException($e->getMessage()));
                    $r['messageStore']->addError('resource', $messages);
                    $this->logCheckedResource($r);
                    ++$this->totalErrors;
                    return null;
                }
            }
            $thumbnailResource['o:thumbnail'] = ['o:id' => $dataResource['o:id']];
            $thumbnailResources[] = $thumbnailResource;
        }

        // TODO Isolate the processes.
        $assetAction = $this->action;
        $this->action = self::ACTION_SUB_UPDATE;
        $this->updateResources('resources', $thumbnailResources);
        $this->action = $assetAction;
        return $this;
    }

    /**
     * Process deletion of entities.
     */
    protected function deleteEntities(array $dataResources): self
    {
        $resourceName = $this->getResourceName();
        $this->deleteResources($resourceName, $dataResources);
        return $this;
    }

    /**
     * Process deletion of resources.
     */
    protected function deleteResources($resourceName, array $dataResources): self
    {
        if (!count($dataResources)) {
            return $this;
        }

        // Get ids (already checked normally).
        $ids = [];
        foreach ($dataResources as $dataResource) {
            if (isset($dataResource['o:id'])) {
                $ids[] = $dataResource['o:id'];
            }
        }

        try {
            if (count($ids) === 1) {
                $this->bulk->api(null, true)
                    ->delete($resourceName, reset($ids))->getContent();
            } else {
                $this->bulk->api(null, true)
                    ->batchDelete($resourceName, $ids, [], ['continueOnError' => true])->getContent();
            }
        } catch (ValidationException $e) {
            $r = $this->baseEntity();
            $r['messageStore']->addError('resource', new PsrMessage(
                'Error during validation of the data before deletion.' // @translate
            ));
            $messages = $this->listValidationMessages($e);
            $r['messageStore']->addError('resource', $messages);
            $this->logCheckedResource($r);
            ++$this->totalErrors;
            return $this;
        } catch (\Exception $e) {
            $r = $this->baseEntity();
            // There is no error, only ids already deleted, so continue.
            $r['messageStore']->addWarning('resource', new PsrMessage(
                'Core error during deletion: {exception}', // @translate
                ['exception' => $e]
            ));
            $this->logCheckedResource($r);
            ++$this->totalErrors;
            return $this;
        }

        foreach ($ids as $id) {
            $this->logger->notice(
                'Index #{index}: Deleted {resource_name} #{resource_id}', // @translate
                ['index' => $this->indexResource, 'resource_name' => $this->bulk->label($resourceName), 'resource_id' => $id]
            );
        }
        return $this;
    }

    /**
     * Process skipping of entities.
     */
    protected function skipEntities(array $dataResources): self
    {
        $resourceName = $this->getResourceName();
        $this->skipResources($resourceName, $dataResources);
        return $this;
    }

    /**
     * Process skipping of resources.
     */
    protected function skipResources($resourceName, array $dataResources): self
    {
        return $this;
    }

    protected function extractIdentifierOrTitle(ArrayObject $resource): ?string
    {
        if (!empty($resource['dcterms:identifier'][0]['@value'])) {
            return (string) $resource['dcterms:identifier'][0]['@value'];
        }
        if (!empty($resource['o:display_title'])) {
            return (string) $resource['o:display_title'];
        }
        if (!empty($resource['o:resource_template']['o:id'])) {
            $templateTitleId = $this->bulk->getResourceTemplateTitleIds()[($resource['o:resource_template']['o:id'])] ?? null;
            if ($templateTitleId && !empty($resource[$templateTitleId][0]['@value'])) {
                return (string) $resource[$templateTitleId][0]['@value'];
            }
        }
        if (!empty($resource['dcterms:title'][0]['@value'])) {
            return (string) $resource['dcterms:title'][0]['@value'];
        }
        if (!empty($resource['foaf:name'][0]['@value'])) {
            return (string) $resource['foaf:name'][0]['@value'];
        }
        if (!empty($resource['skos:preferredLabel'][0]['@value'])) {
            return (string) $resource['skos:preferredLabel'][0]['@value'];
        }
        return null;
    }

    protected function actionRequiresId($action = null): bool
    {
        $actionsRequireId = [
            self::ACTION_APPEND,
            self::ACTION_REVISE,
            self::ACTION_UPDATE,
            self::ACTION_REPLACE,
            self::ACTION_DELETE,
        ];
        if (empty($action)) {
            $action = $this->action;
        }
        return in_array($action, $actionsRequireId);
    }

    protected function actionIsUpdate($action = null): bool
    {
        $actionsUpdate = [
            self::ACTION_APPEND,
            self::ACTION_REVISE,
            self::ACTION_UPDATE,
            self::ACTION_REPLACE,
        ];
        if (empty($action)) {
            $action = $this->action;
        }
        return in_array($action, $actionsUpdate);
    }

    protected function prepareAction(): self
    {
        $this->action = $this->getParam('action') ?: self::ACTION_CREATE;
        if (!in_array($this->action, [
            self::ACTION_CREATE,
            self::ACTION_UPDATE,
            self::ACTION_DELETE,
            self::ACTION_SKIP,
        ])) {
            $this->logger->err(
                'Action "{action}" is not managed.', // @translate
                ['action' => $this->action]
            );
        }
        return $this;
    }

    protected function prepareActionUnidentified(): self
    {
        $this->actionUnidentified = $this->getParam('action_unidentified') ?: self::ACTION_SKIP;
        if (!in_array($this->actionUnidentified, [
            self::ACTION_CREATE,
            self::ACTION_SKIP,
        ])) {
            $this->logger->err(
                'Action "{action}" for unidentified resource is not managed.', // @translate
                ['action' => $this->actionUnidentified]
            );
        }
        return $this;
    }

    protected function prepareIdentifierNames(): self
    {
        $identifierNames = $this->getParam('identifier_name', ['o:id']);
        if (empty($identifierNames)) {
            $this->bulk->setIdentifierNames([]);
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
            $id = $this->bulk->getPropertyId($identifierName);
            if ($id) {
                $result[$this->bulk->getPropertyTerm($id)] = $id;
            } else {
                $result[$identifierName] = $identifierName;
            }
        }
        $result = array_filter($result);
        if (empty($result)) {
            $this->logger->err(
                'Invalid identifier names: check your params.' // @translate
            );
        }
        $this->bulk->setIdentifierNames($result);
        return $this;
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
    protected function extractSourceIdentifiers(?ArrayObject $resource, ?Entry $entry = null): self
    {
        if (!$resource) {
            return $this;
        }

        $storeMain = function ($idOrIdentifier) use ($resource): void {
            if ($idOrIdentifier) {
                // No check for duplicates here: it depends on action.
                $this->identifiers['source'][$this->indexResource][] = $idOrIdentifier;
                $this->identifiers['revert'][$idOrIdentifier][] = $this->indexResource;
            }
            // Source indexes to resource id.
            $this->identifiers['mapx'][$this->indexResource] = $resource['o:id'] ?? null;
            if ($idOrIdentifier) {
                // Source identifiers to resource id.
                // No check for duplicate here: last map is the right one.
                $this->identifiers['map'][$idOrIdentifier] = $resource['o:id'] ?? null;
            }
        };

        $storeLinkedIdentifier = function ($idOrIdentifier, $vrId): void {
            // As soon as an array exists, a check can be done on identifier,
            // even if the id is defined later. The same for map.
            if (!isset($this->identifiers['revert'][$idOrIdentifier])) {
                $this->identifiers['revert'][$idOrIdentifier] = [];
            }
            $this->identifiers['map'][$idOrIdentifier] = $vrId;
        };

        // Main identifiers.
        $identifierNames = $this->bulk->getIdentifierNames();
        foreach ($identifierNames as $identifierName) {
            if ($identifierName === 'o:id') {
                $storeMain($resource['o:id'] ?? null);
            } elseif ($identifierName === 'o:storage_id') {
                $storeMain($resource['o:storage_id'] ?? null);
            } else {
                $term = $this->bulk->getPropertyTerm($identifierName);
                foreach ($resource[$term] ?? [] as $value) {
                    if (!empty($value['@value'])) {
                        $storeMain($value['@value']);
                    }
                }
            }
        }

        // Specific identifiers for items (item sets and media).
        if ($resource['resource_name'] === 'items') {
            foreach ($resource['o:item_set'] ?? [] as $itemSet) {
                if (!empty($itemSet['source_identifier'])) {
                    $storeLinkedIdentifier($itemSet['source_identifier'], $itemSet['o:id'] ?? null);
                }
            }
            foreach ($resource['o:media'] ?? [] as $media) {
                if (!empty($media['source_identifier'])) {
                    $storeLinkedIdentifier($media['source_identifier'], $media['o:id'] ?? null);
                }
            }
        }

        // Specific identifiers for media (item).
        if ($resource['resource_name'] === 'media') {
            if (!empty($resource['o:item']['source_identifier'])) {
                $storeLinkedIdentifier($resource['o:item']['source_identifier'], $resource['o:item']['o:id'] ?? null);
            }
        }

        // Specific identifiers for resources attached to assets.
        if ($resource['resource_name'] === 'assets') {
            foreach ($resource['o:resource'] ?? [] as $thumbnailForResource) {
                if (!empty($thumbnailForResource['source_identifier'])) {
                    $storeLinkedIdentifier($thumbnailForResource['source_identifier'], $thumbnailForResource['o:id'] ?? null);
                }
            }
        }

        // Store identifiers for linked resources.
        $properties = $this->bulk->getPropertyIds();
        foreach (array_intersect_key($resource->getArrayCopy(), $properties) as $term => $values) {
            if (!is_array($values)) {
                continue;
            }
            foreach ($values as $value) {
                if (is_array($value)
                    && isset($value['property_id'])
                    && !empty($value['source_identifier'])
                ) {
                    $storeLinkedIdentifier($value['source_identifier'], $value['value_resource_id'] ?? null);
                }
            }
        }

        return $this;
    }

    /**
     * Store new id when source contains identifiers not yet imported.
     *
     * Identifiers are already stored during first loop. So just set final id.
     */
    protected function storeSourceIdentifiersIds(array $dataResource, AbstractEntityRepresentation $resource): self
    {
        $resourceId = $resource->id();
        if (empty($resourceId) || empty($dataResource['source_index'])) {
            return $this;
        }

        // Source indexes to resource id (filled when found or created).
        $this->identifiers['mapx'][$dataResource['source_index']] = $resourceId;

        // Source identifiers to resource id (filled when found or created).
        // No check for duplicate here: last map is the right one.
        foreach ($this->identifiers['source'][$dataResource['source_index']] ?? [] as $idOrIdentifier) {
            $this->identifiers['map'][$idOrIdentifier] = $resourceId;
        }

        return $this;
    }

    /**
     * Set missing ids when source contains identifiers not yet imported during
     * resource building.
     */
    protected function completeResourceIdentifierIds(array $resource): array
    {
        if (empty($resource['o:id'])
            && !empty($resource['source_index'])
            && !empty($this->identifiers['mapx'][$resource['source_index']])
        ) {
            $resource['o:id'] = $this->identifiers['mapx'][$resource['source_index']];
        }

        if ($resource['resource_name'] === 'items') {
            foreach ($resource['o:item_set'] ?? [] as $key => $itemSet) {
                if (empty($itemSet['o:id'])
                    && !empty($itemSet['source_identifier'])
                    && !empty($this->identifiers['map'][$itemSet['source_identifier']])
                    // TODO Add a check for item set identifier.
                ) {
                    $resource['o:item_set'][$key]['o:id'] = $this->identifiers['map'][$itemSet['source_identifier']];
                }
            }
            // TODO Fill media identifiers for update here?
        }

        if ($resource['resource_name'] === 'media'
            && empty($resource['o:item']['o:id'])
            && !empty($resource['o:item']['source_identifier'])
            && !empty($this->identifiers['map'][$resource['o:item']['source_identifier']])
            // TODO Add a check for item identifier.
        ) {
            $resource['o:item']['o:id'] = $this->identifiers['map'][$resource['o:item']['source_identifier']];
        }

        // Useless for now: don't create resource on unknown resources.
        if ($resource['resource_name'] === 'assets') {
            foreach ($resource['o:resource'] ?? [] as $key => $thumbnailForResource) {
                if (empty($thumbnailForResource['o:id'])
                    && !empty($thumbnailForResource['source_identifier'])
                    && !empty($this->identifiers['map'][$thumbnailForResource['source_identifier']])
                    // TODO Add a check for resource identifier.
                ) {
                    $resource['o:resource'][$key]['o:id'] = $this->identifiers['map'][$thumbnailForResource['source_identifier']];
                }
            }
        }

        foreach ($resource as $term => $values) {
            if (!is_array($values)) {
                continue;
            }
            foreach ($values as $key => $value) {
                if (is_array($value)
                    && isset($value['property_id'])
                    // Avoid to test the type (resources and some custom vocabs).
                    && array_key_exists('value_resource_id', $value)
                    && empty($value['value_resource_id'])
                    && !empty($value['source_identifier'])
                    && !empty($this->identifiers['map'][$value['source_identifier']])
                ) {
                    $resource[$term][$key]['value_resource_id'] = $this->identifiers['map'][$value['source_identifier']];
                }
            }
        }

        return $resource;
    }

    /**
     * Prepare full mapping one time to simplify and speed process.
     *
     * The mapping is between the reader and the processor: it contains the maps
     * between the source and the destination. It can be provided via the source
     * (spreadsheet headers and processor form) or a mapping config.
     * So some processors can be reader-driven or processor-driven.
     */
    protected function prepareMetaConfig(): self
    {
        $mappingConfig = null;
        if (method_exists($this->reader, 'getConfigParam')) {
            $mappingConfig = $this->reader->getParam('mapping_config')
                ?: $this->reader->getConfigParam('mapping_config');
        }
        if (is_null($mappingConfig)) {
            $mappingConfig = $this->getParam('mapping', []);
        }
        if (!$mappingConfig) {
            return $this;
        }

        $normalizedConfig = $this->metaMapperConfig->__invoke('asset', $mappingConfig, [
            'to_keys' => [
                'field' => null,
            ],
        ]);

        if (!empty($normalizedConfig['has_error'])) {
            ++$this->totalErrors;
            if ($normalizedConfig['has_error'] === true) {
                $this->logger->err(new PsrMessage('Error in the mapping config.')); // @translate
            } else {
                $this->logger->err(new PsrMessage(
                    'Error in the mapping config: {message}', // @translate
                    ['message' => $normalizedConfig['has_error']]
                ));
            }
        }

        return $this;
    }

    protected function checkAdapter(string $resourceName, string $operation): bool
    {
        static $checks = [];
        if (!isset($checks[$resourceName][$operation])) {
            $adapter = $this->adapterManager->get($resourceName);
            $checks[$resourceName][$operation] = $this->acl->userIsAllowed($adapter, $operation);
        }
        return $checks[$resourceName][$operation];
    }

    protected function listValidationMessages(ValidationException $e): array
    {
        $messages = [];
        foreach ($e->getErrorStore()->getErrors() as $error) {
            foreach ($error as $message) {
                // Some messages can be nested.
                if (is_array($message)) {
                    $result = [];
                    array_walk_recursive($message, function ($v) use (&$result): void {
                        $result[] = $v;
                    });
                        $message = $result;
                        unset($result);
                } else {
                    $message = [$message];
                }
                $messages = array_merge($messages, array_values($message));
            }
        }
        return $messages;
    }

    /**
     * @param \Omeka\Api\Representation\AbstractRepresentation[] $resources
     */
    protected function recordCreatedResources(array $resources): void
    {
        // TODO Store the bulk import id instead of the job id in order to manage regular task?
        $jobId = $this->job->getJob()->getId();
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

        $this->bulk->api()->batchCreate('bulk_importeds', $importeds, [], ['continueOnError' => true]);
    }
}
