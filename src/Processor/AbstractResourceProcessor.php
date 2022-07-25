<?php declare(strict_types=1);

namespace BulkImport\Processor;

use ArrayObject;
use BulkImport\Entry\Entry;
use BulkImport\Interfaces\Configurable;
use BulkImport\Interfaces\Parametrizable;
use BulkImport\Stdlib\MessageStore;
use BulkImport\Traits\ConfigurableTrait;
use BulkImport\Traits\ParametrizableTrait;
use BulkImport\Traits\TransformSourceTrait;
use Laminas\Form\Form;
use Log\Stdlib\PsrMessage;
use Omeka\Api\Exception\ValidationException;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Entity\Asset;

abstract class AbstractResourceProcessor extends AbstractProcessor implements Configurable, Parametrizable
{
    use ConfigurableTrait, ParametrizableTrait;
    use CheckTrait;
    use FileTrait;
    use ResourceUpdateTrait;
    use TransformSourceTrait;

    /**
     * @var string
     */
    protected $resourceName;

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
     * @var string
     */
    protected $actionIdentifier;

    /**
     * @var string
     */
    protected $actionMedia;

    /**
     * @var string
     */
    protected $actionItemSet;

    /**
     * Store the source identifiers for each index, reverted and mapped.
     * Manage possible duplicate identifiers.
     *
     * The keys are filled during first loop and values when found or available.
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
     * @var bool
     */
    protected $hasMapping = false;

    /**
     * @todo Rename this variable, that is used in AbstractFullProcessor with a different meaning.
     * @var array
     */
    protected $mapping;

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

    public function getResourceName(): ?string
    {
        return $this->resourceName;
    }

    public function getLabel(): string
    {
        return $this->resourceLabel;
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
        $config = new ArrayObject;
        $this->handleFormGeneric($config, $values);
        $this->handleFormSpecific($config, $values);
        $this->setConfig($config->getArrayCopy());
        return $this;
    }

    public function handleParamsForm(Form $form)
    {
        $values = $form->getData();
        $params = new ArrayObject;
        $this->handleFormGeneric($params, $values);
        $this->handleFormSpecific($params, $values);
        $params['mapping'] = $values['mapping'] ?? [];
        $this->setParams($params->getArrayCopy());
        return $this;
    }

    protected function handleFormGeneric(ArrayObject $args, array $values): \BulkImport\Processor\Processor
    {
        $defaults = [
            'processing' => 'stop_on_error',
            'action' => null,
            'action_unidentified' => null,
            'identifier_name' => null,
            'allow_duplicate_identifiers' => false,
            'action_identifier_update' => null,
            'action_media_update' => null,
            'action_item_set_update' => null,

            'value_datatype_literal' => false,

            'o:resource_template' => null,
            'o:resource_class' => null,
            'o:thumbnail' => null,
            'o:owner' => null,
            'o:is_public' => null,

            'entries_to_skip' => 0,
            'entries_max' => 0,
            'entries_by_batch' => null,
        ];

        $result = array_intersect_key($values, $defaults) + $args->getArrayCopy() + $defaults;
        $result['allow_duplicate_identifiers'] = (bool) $result['allow_duplicate_identifiers'];
        $args->exchangeArray($result);
        return $this;
    }

    protected function handleFormSpecific(ArrayObject $args, array $values): \BulkImport\Processor\Processor
    {
        return $this;
    }

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

            ->prepareActionIdentifier()
            ->prepareActionMedia()
            ->prepareActionItemSet()

            ->appendInternalParams()

            ->prepareMapping();

        $this->bulk->setAllowDuplicateIdentifiers($this->getParam('allow_duplicate_identifiers', false));

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

        $this->prepareListOfIdentifiers();

        $this->prepareListOfIds();

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
    protected function prepareListOfIdentifiers(): \BulkImport\Processor\Processor
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
                'total_identifiers' => count($this->identifiers['revert']),
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
    protected function prepareListOfIds(): \BulkImport\Processor\Processor
    {
        if (empty($this->identifiers['map'])) {
            return $this;
        }

        $this->logger->notice(
            'Start preparing ids from {count} source identifiers.', // @translate
            ['count' => count($this->identifiers['map'])]
        );

        // Process only identifiers without ids (normally all of them).
        $emptyIdentifiers = array_filter($this->identifiers['map'], function($v) {
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
            ['total' => count($ids), 'count' => count($this->identifiers['map'])]
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
    protected function prepareFullRun(): \BulkImport\Processor\Processor
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

    protected function processFullRun(): \BulkImport\Processor\Processor
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

//             $dataToProcess[] = is_array($resource) ? $resource : $resource->getArrayCopy();
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

        return $this->hasMapping
            ? $this->processEntryWithMapping($entry)
            : $this->processEntryDirectly($entry);
    }

    /**
     * Convert a prepared entry into a resource, setting ids for each key.
     *
     * So fill owner id, resource template id, resource class id, property ids.
     * Check boolean values too for is public and is open.
     */
    protected function processEntryDirectly(Entry $entry): ArrayObject
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
        // Cf. baseSpecific(), fillItem(), fillItemSet() and fillMedia().
        $booleanKeys = [
            'o:is_public' => true,
            'o:is_open' => true,
        ];

        $singleDataKeys = [
            // Generic.
            'o:id' => null,
            // Resource.
            'resource_name' => null,
            // Media.
            'o:lang' => null,
            'o:ingester' => null,
            'o:source' => null,
            'ingest_filename' => null,
            'ingest_directory' => null,
            'ingest_url' => null,
            'html' => null,
        ];

        // Keys that can have only one value that is an entity with an id.
        $singleEntityKeys = [
            // Generic.
            'o:resource_template' => null,
            'o:resource_class' => null,
            'o:thumbnail' => null,
            'o:owner' => null,
            // Media.
            'o:item' => null,
        ];

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

        // Clean the property id in all cases.
        $properties = $this->bulk->getPropertyIds();
        foreach (array_intersect_key($resource->getArrayCopy(), $properties) as $term => $values) {
            foreach (array_keys($values) as $key) {
                $resource[$term][$key]['property_id'] = $properties[$term];
            }
        }

        // TODO Fill the source identifiers of the main resource.

        $fillPropertiesAndResourceData = function (array $resourceArray) use ($properties, $booleanKeys, $singleEntityKeys): array {
            // Fill the properties.
            foreach (array_intersect_key($resourceArray, $properties) as $term => $values) {
                foreach (array_keys($values) as $key) {
                    $resourceArray[$term][$key]['property_id'] = $properties[$term];
                }
            }
            // Fill other metadata (for media and item set).
            $resourceObject = new ArrayObject($resourceArray);
            foreach (array_keys($booleanKeys) as $key) {
                if (array_key_exists($key, $resourceArray)) {
                    $this->fillBoolean($resourceObject, $key, $resourceObject[$key]);
                }
            }
            foreach (array_keys($singleEntityKeys) as $key) {
                if (array_key_exists($key, $resourceArray)) {
                    $this->fillSingleEntity($resourceObject, $key, $resourceObject[$key]);
                }
            }
            // TODO Fill the source identifiers of related resources.
            return $resourceObject->getArrayCopy();
        };

        // Do the same for sub-resources (multiple entity keys: ''o:media" and
        // "o:item_set" for items).
        foreach (['o:item_set', 'o:media'] as $key) {
            if (!empty($resource[$key])) {
                foreach ($resource[$key] as &$resourceData) {
                    $resourceData = $fillPropertiesAndResourceData($resourceData);
                }
            }
        }

        return $resource;
    }

    protected function processEntryWithMapping(Entry $entry): ArrayObject
    {
        /** @var \ArrayObject $resource */
        $resource = clone $this->base;
        $resource['source_index'] = $entry->index();
        $resource['messageStore']->clearMessages();

        $this->skippedSourceFields = [];
        foreach ($this->mapping as $sourceField => $targets) {
            // Check if the entry has a value for this source field.
            if (!isset($entry[$sourceField])) {
                // Probably an issue in the config.
                /*
                // TODO Warn when it is not a multisheet. Check updates with a multisheet.
                if (!$entry->offsetExists($sourceField)) {
                    $resource['messageStore']->addWarning('values', new PsrMessage(
                        'The source field "{field}" is set in the mapping, but not in the entry. The params may have an issue.', // @translate
                        ['field' => $sourceField]
                    ));
                }
                */
                $this->skippedSourceFields[] = $sourceField;
                continue;
            }

            $values = $entry[$sourceField];
            if (!count($values)) {
                $this->skippedSourceFields[] = $sourceField;
                continue;
            }

            $this->fillResource($resource, $targets, $values);
        }

        return $resource;
    }

    protected function baseEntity(): ArrayObject
    {
        // TODO Use a specific class that extends ArrayObject to manage process metadata (check and errors).
        $resource = new ArrayObject;
        $resource['o:id'] = null;
        $resource['source_index'] = 0;
        $resource['checked_id'] = false;
        $resource['messageStore'] = new MessageStore();
        $this->baseGeneric($resource);
        $this->baseSpecific($resource);
        return $resource;
    }

    protected function baseGeneric(ArrayObject $resource): \BulkImport\Processor\Processor
    {
        $resourceTemplateId = $this->getParam('o:resource_template');
        if ($resourceTemplateId) {
            $resource['o:resource_template'] = ['o:id' => $resourceTemplateId];
        }
        $resourceClassId = $this->getParam('o:resource_class');
        if ($resourceClassId) {
            $resource['o:resource_class'] = ['o:id' => $resourceClassId];
        }
        $thumbnailId = $this->getParam('o:thumbnail');
        if ($thumbnailId) {
            $resource['o:thumbnail'] = ['o:id' => $thumbnailId];
        }
        $ownerId = $this->getParam('o:owner', 'current') ?: 'current';
        if ($ownerId === 'current') {
            $identity = $this->getServiceLocator()->get('ControllerPluginManager')
                ->get('identity');
            $ownerId = $identity()->getId();
        }
        $resource['o:owner'] = ['o:id' => $ownerId];
        $resource['o:is_public'] = $this->getParam('o:is_public') !== 'false';
        return $this;
    }

    protected function baseSpecific(ArrayObject $resource): \BulkImport\Processor\Processor
    {
        return $this;
    }

    protected function fillResource(ArrayObject $resource, array $targets, array $values): \BulkImport\Processor\Processor
    {
        foreach ($targets as $target) {
            switch ($target['target']) {
                case $this->fillProperty($resource, $target, $values):
                    break;
                case $this->fillGeneric($resource, $target, $values):
                    break;
                case $this->fillSpecific($resource, $target, $values):
                    break;
                default:
                    // The resource name should be set only in fillSpecific.
                    if ($target['target'] !== 'resource_name') {
                        $resource[$target['target']] = array_pop($values);
                    }
                    break;
            }
        }
        return $this;
    }

    protected function fillProperty(ArrayObject $resource, $target, array $values): bool
    {
        // Return true in all other cases, when this is a property process, with
        // or without issue.
        if (!isset($target['value']['property_id'])) {
            return false;
        }

        if (!empty($target['value']['type'])) {
            $datatypeNames = [$target['value']['type']];
        } elseif (!empty($target['datatype'])) {
            $datatypeNames = $target['datatype'];
        } else {
            // Normally not possible, so use "literal", whatever the option is.
            $datatypeNames = ['literal'];
        }

        // The datatype should be checked for each value. The value is checked
        // against each datatype and get the first valid one.
        // TODO Factorize instead of doing check twice.
        foreach ($values as $value) {
            $hasDatatype = false;
            // The data type name is normally already checked, but may be empty.
            foreach ($datatypeNames as $datatypeName) {
                /** @var \Omeka\DataType\DataTypeInterface $datatype */
                $datatype = $this->bulk->getDataType($datatypeName);
                if (!$datatype) {
                    continue;
                }
                $datatypeName = $datatype->getName();
                $target['value']['type'] = $datatypeName;
                if ($datatypeName === 'literal') {
                    $this->fillPropertyForValue($resource, $target, $value);
                    $hasDatatype = true;
                    break;
                } elseif (substr($datatypeName, 0, 8) === 'resource') {
                    $vrId = $this->identifiers['map'][$value]
                        ?? $this->bulk->findResourceFromIdentifier($value, null, $datatypeName, $resource['messageStore']);
                    // Normally always true: all identifiers are stored first.
                    if ($vrId || isset($this->identifiers['revert'][$value])) {
                        $this->fillPropertyForValue($resource, $target, $value, $vrId ? (int) $vrId : null);
                        $hasDatatype = true;
                        break;
                    }
                } elseif (substr($datatypeName, 0, 11) === 'customvocab') {
                    // The resource is not checked for custom vocab member here.
                    if ($this->bulk->getCustomVocabBaseType($datatypeName) === 'resource') {
                        $vrId = $this->identifiers['map'][$value]
                            ?? $this->bulk->findResourceFromIdentifier($value, null, $datatypeName, $resource['messageStore']);
                        // Normally always true: all identifiers are stored first.
                        if ($vrId || isset($this->identifiers['revert'][$value])) {
                            $this->fillPropertyForValue($resource, $target, $value, $vrId ? (int) $vrId : null);
                            $hasDatatype = true;
                            break;
                        }
                    } elseif ($this->bulk->isCustomVocabMember($datatypeName, $value)) {
                        $this->fillPropertyForValue($resource, $target, $value);
                        $hasDatatype = true;
                        break;
                    }
                } elseif (substr($datatypeName, 0, 3) === 'uri'
                    || substr($datatypeName, 0, 12) === 'valuesuggest'
                ) {
                    if ($this->bulk->isUrl($value)) {
                        $this->fillPropertyForValue($resource, $target, $value);
                        $hasDatatype = true;
                        break;
                    }
                } else {
                    // Some data types may be more complex than "@value", but it
                    // manages most of the common other modules.
                    $valueArray = [
                        '@value' => $value,
                    ];
                    if ($datatype->isValid($valueArray)) {
                        $this->fillPropertyForValue($resource, $target, $value);
                        $hasDatatype = true;
                        break;
                    }
                }
            }
            // TODO Add an option for literal data-type by default.
            if (!$hasDatatype) {
                if ($this->getParam('value_datatype_literal')) {
                    $targetLiteral = $target;
                    $targetLiteral['value']['type'] = 'literal';
                    $this->fillPropertyForValue($resource, $targetLiteral, $value);
                    $resource['messageStore']->addNotice('values', new PsrMessage(
                        'The value "{value}" is not compatible with datatypes "{datatypes}". Data type "literal" is used.', // @translate
                        ['value' => mb_substr((string) $value, 0, 50), 'datatypes' => implode('", "', $datatypeNames)]
                    ));
                } else {
                    $resource['messageStore']->addError('values', new PsrMessage(
                        'The value "{value}" is not compatible with datatypes "{datatypes}". Try adding "literal" to datatypes or default to it.', // @translate
                        ['value' => mb_substr((string) $value, 0, 50), 'datatypes' => implode('", "', $datatypeNames)]
                    ));
                }
            }
        }

        return true;
    }

    protected function fillPropertyForValue(ArrayObject $resource, $target, $value, ?int $vrId = null): bool
    {
        // Prepare the new resource value from the target.
        $resourceValue = $target['value'];
        $datatype = $resourceValue['type'];
        switch ($datatype) {
            default:
            case 'literal':
                $resourceValue['@value'] = $value;
                break;

            // "uri-label" is deprecated: use simply "uri".
            case 'uri-label':
            case 'uri':
            case substr($datatype, 0, 12) === 'valuesuggest':
                if (strpos($value, ' ')) {
                    list($uri, $label) = explode(' ', $value, 2);
                    $label = trim($label);
                    if (!strlen($label)) {
                        $label = null;
                    }
                    $resourceValue['@id'] = $uri;
                    $resourceValue['o:label'] = $label;
                } else {
                    $resourceValue['@id'] = $value;
                    // $resourceValue['o:label'] = null;
                }
                break;

            case 'resource':
            case 'resource:item':
            case 'resource:itemset':
            case 'resource:media':
                $resourceValue['value_resource_id'] = $vrId;
                $resourceValue['@language'] = null;
                if (!$vrId) {
                    $resourceValue['source_identifier'] = $value;
                }
                break;

            case substr($datatype, 0, 11) === 'customvocab':
                $customVocabBaseType = $this->bulk->getCustomVocabBaseType($datatype);
                $result = $this->bulk->isCustomVocabMember($datatype, $vrId ?? $value);
                if ($result || ($customVocabBaseType === 'resource' && !$vrId)) {
                    switch ($customVocabBaseType) {
                        default:
                        case 'literal':
                            $resourceValue['@value'] = $value;
                            break;
                        case 'uri':
                            if (strpos($value, ' ')) {
                                list($uri, $label) = explode(' ', $value, 2);
                                $label = trim($label);
                                if (!strlen($label)) {
                                    $label = null;
                                }
                                $resourceValue['@id'] = $uri;
                                $resourceValue['o:label'] = $label;
                            } else {
                                $resourceValue['@id'] = $value;
                                // $resourceValue['o:label'] = null;
                            }
                            break;
                        case 'resource':
                            $resourceValue['value_resource_id'] = $vrId;
                            $resourceValue['@language'] = null;
                            // TODO Check identifier as member of custom vocab later.
                            if (!$vrId) {
                                $resourceValue['source_identifier'] = $value;
                            }
                            break;
                    }
                } else {
                    if ($this->getParam('value_datatype_literal')) {
                        $resourceValue['@value'] = $value;
                        $resourceValue['type'] = 'literal';
                        $resource['messageStore']->addNotice('values', new PsrMessage(
                            'The value "{value}" is not member of custom vocab "{customvocab}". A literal value is used instead.', // @translate
                            ['value' => mb_substr((string) $value, 0, 50), 'customvocab' => $datatype]
                        ));
                    } else {
                        $resource['messageStore']->addError('values', new PsrMessage(
                            'The value "{value}" is not member of custom vocab "{customvocab}".', // @translate
                            ['value' => mb_substr((string) $value, 0, 50), 'customvocab' => $datatype]
                        ));
                    }
                }
                break;

            // TODO Support other special data types for geometry, numeric, etc.
        }
        $resource[$target['target']][] = $resourceValue;

        return true;
    }

    protected function fillGeneric(ArrayObject $resource, $target, array $values): bool
    {
        switch ($target['target']) {
            case 'o:id':
                $value = (int) array_pop($values);
                if (!$value) {
                    return true;
                }
                $resourceName = $resource['resource_name'] ?? null;
                $id = $this->identifiers['mapx'][$resource['source_index']]
                    ?? $this->bulk->findResourceFromIdentifier($value, 'o:id', $resourceName, $resource['messageStore']);
                if ($id) {
                    $resource['o:id'] = $id;
                    $resource['checked_id'] = !empty($resourceName) && $resourceName !== 'resources';
                } else {
                    $resource['messageStore']->addError('resource', new PsrMessage(
                        'Internal id #{id} cannot be found. The entry is skipped.', // @translate
                        ['id' => $id]
                    ));
                }
                return true;

            case 'o:resource_template':
                $value = array_pop($values);
                if (!$value) {
                    return true;
                }
                if (is_array($value)) {
                    $id = empty($value['o:id']) ? null : $value['o:id'];
                    $label = empty($value['o:label']) ? null : $value['o:label'];
                    $value = $id ?? $label ?? reset($value);
                }
                $id = $this->bulk->getResourceTemplateId($value);
                if ($id) {
                    $resource['o:resource_template'] = empty($label)
                        ? ['o:id' => $id]
                        : ['o:id' => $id, 'o:label' => $label];
                } else {
                    $resource['messageStore']->addError('template', new PsrMessage(
                        'The resource template "{source}" does not exist.', // @translate
                        ['source' => $value]
                    ));
                }
                return true;

            case 'o:resource_class':
                $value = array_pop($values);
                if (!$value) {
                    return true;
                }
                if (is_array($value)) {
                    $id = empty($value['o:id']) ? null : $value['o:id'];
                    $term = empty($value['o:term']) ? null : $value['o:term'];
                    $value = $id ?? $term ?? reset($value);
                }
                $id = $this->bulk->getResourceClassId($value);
                if ($id) {
                    $resource['o:resource_class'] = empty($term)
                        ? ['o:id' => $id]
                        : ['o:id' => $id, 'o:term' => $term];
                } else {
                    $resource['messageStore']->addError('values', new PsrMessage(
                        'The resource class "{source}" does not exist.', // @translate
                        ['source' => $value]
                    ));
                }
                return true;

            case 'o:thumbnail':
                $value = array_pop($values);
                if (!$value) {
                    return true;
                }
                if (is_array($value)) {
                    $id = empty($value['o:id']) ? null : $value['o:id'];
                    $url = empty($value['ingest_url']) ? null : $value['ingest_url'];
                    $altText = empty($value['o:alt_text']) ? null : $value['o:alt_text'];
                    $value = $id ?? $url ?? null;
                }
                if (is_numeric($value)) {
                    $id = $this->bulk->getAssetId($value);
                } elseif (is_string($value)) {
                    // TODO Temporary creation of the asset.
                    $asset = $this->createAssetFromUrl($value, $resource['messageStore']);
                    $id = $asset ? $asset->getId() : null;
                }
                if ($id) {
                    $resource['o:thumbnail'] = empty($altText)
                        ? ['o:id' => $id]
                        // TODO Check if the alt text is updated.
                        : ['o:id' => $id, 'o:alt_text' => $altText];
                } else {
                    $resource['messageStore']->addError('values', new PsrMessage(
                        'The thumbnail "{source}" does not exist or cannot be created.', // @translate
                        ['source' => $value]
                    ));
                }
                return true;

            case 'o:owner':
                $value = array_pop($values);
                if (!$value) {
                    return true;
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
                return true;

            case 'o:email':
                $value = array_pop($values);
                if (!$value) {
                    return true;
                }
                $id = $this->bulk->getUserId($value);
                if ($id) {
                    $resource['o:owner'] = ['o:id' => $id, 'o:email' => $value];
                } else {
                    $resource['messageStore']->addError('values', new PsrMessage(
                        'The user "{source}" does not exist.', // @translate
                        ['source' => $value]
                    ));
                }
                return true;

            case 'o:is_public':
                $value = (string) array_pop($values);
                $resource['o:is_public'] = in_array(strtolower((string) $value), ['0', 'false', 'no', 'off', 'private'], true)
                    ? false
                    : (bool) $value;
                return true;

            case 'o:created':
            case 'o:modified':
                $value = array_pop($values);
                $resource[$target['target']] = is_array($value)
                    ? $value
                    : ['@value' => substr_replace('0000-00-00 00:00:00', $value, 0, strlen($value))];
                return true;

            default:
                return false;
        }
    }

    protected function fillSpecific(ArrayObject $resource, $target, array $values): bool
    {
        return false;
    }

    protected function fillBoolean(ArrayObject $resource, $key, $value): \BulkImport\Processor\Processor
    {
        $resource[$key] = in_array(strtolower((string) $value), ['0', 'false', 'no', 'off', 'private', 'closed'], true)
            ? false
            : (bool) $value;
        return $this;
    }

    /**
     * @todo Factorize with fillGeneric().
     */
    protected function fillSingleEntity(ArrayObject $resource, $key, $value): \BulkImport\Processor\Processor
    {
        if (empty($value)) {
            $resource[$key] = null;
            return $this;
        }

        // Get the entity id.
        switch ($key) {
            case 'o:resource_template':
                if (is_array($value)) {
                    $id = empty($value['o:id']) ? null : $value['o:id'];
                    $label = empty($value['o:label']) ? null : $value['o:label'];
                    $value = $id ?? $label ?? reset($value);
                }
                $id = $this->bulk->getResourceTemplateId($value);
                if ($id) {
                    $resource['o:resource_template'] = empty($label)
                        ? ['o:id' => $id]
                        : ['o:id' => $id, 'o:label' => $label];
                } else {
                    $resource['o:resource_template'] = null;
                    $resource['messageStore']->addError('values', new PsrMessage(
                        'The resource template "{source}" does not exist.', // @translate
                        ['source' => $value]
                    ));
                }
                return $this;

            case 'o:resource_class':
                if (is_array($value)) {
                    $id = empty($value['o:id']) ? null : $value['o:id'];
                    $term = empty($value['o:term']) ? null : $value['o:term'];
                    $value = $id ?? $term ?? reset($value);
                }
                $id = $this->bulk->getResourceClassId($value);
                if ($id) {
                    $resource['o:resource_class'] = empty($term)
                        ? ['o:id' => $id]
                        : ['o:id' => $id, 'o:term' => $term];
                } else {
                    $resource['o:resource_class'] = null;
                    $resource['messageStore']->addError('values', new PsrMessage(
                        'The resource class "{source}" does not exist.', // @translate
                        ['source' => $value]
                    ));
                }
                return $this;

            case 'o:thumbnail':
                if (is_array($value)) {
                    $id = empty($value['o:id']) ? null : $value['o:id'];
                    $url = empty($value['ingest_url']) ? null : $value['ingest_url'];
                    $altText = empty($value['o:alt_text']) ? null : $value['o:alt_text'];
                    $value = $id ?? $url ?? null;
                }
                if (is_numeric($value)) {
                    $id = $this->bulk->getAssetId($value);
                } elseif (is_string($value)) {
                    // TODO Temporary creation of the asset.
                    $asset = $this->createAssetFromUrl($value, $resource['messageStore']);
                    $id = $asset ? $asset->getId() : null;
                }
                if ($id) {
                    $resource['o:thumbnail'] = empty($altText)
                        ? ['o:id' => $id]
                        // TODO Check if the alt text is updated.
                        : ['o:id' => $id, 'o:alt_text' => $altText];
                } else {
                    $resource['messageStore']->addError('values', new PsrMessage(
                        'The thumbnail "{source}" does not exist or cannot be created.', // @translate
                        ['source' => $value]
                    ));
                }
                return $this;

            case 'o:owner':
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
                    $resource['o:owner'] = null;
                    $resource['messageStore']->addError('resource', new PsrMessage(
                        'The user "{source}" does not exist.', // @translate
                        ['source' => $value]
                    ));
                }
                return $this;

            case 'o:item':
                // For media.
                if (is_array($value)) {
                    $id = empty($value['o:id']) ? null : $value['o:id'];
                    $value = $id ?? reset($value);
                }
                $id = $this->identifiers['mapx'][$resource['source_index']]
                    ?? $this->bulk->findResourceFromIdentifier($value, null, null, $resource['messageStore']);
                if ($id) {
                    $resource['o:item'] = ['o:id' => $id];
                } else {
                    $resource['o:item'] = null;
                    $resource['messageStore']->addError('resource', new PsrMessage(
                        'The item "{source}" for media does not exist.', // @translate
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

        return !$resource['messageStore']->hasErrors();
    }

    /**
     * Check if new files (local system and urls) are available and allowed.
     *
     * By construction, it's not possible to check or modify existing files.
     */
    protected function checkNewFiles(ArrayObject $resource): bool
    {
        if (!in_array($resource['resource_name'], ['items', 'media'])) {
            return true;
        }

        if ($resource['resource_name'] === 'media') {
            $medias = [$resource];
        } else {
            $medias = $resource['o:media'] ?? [];
            if (!$medias) {
                return true;
            }
        }

        foreach ($medias as $media) {
            if (!empty($media['o:id'])) {
                continue;
            }
            if (!empty($media['ingest_url'])) {
                $this->checkUrl($media['ingest_url'], $resource['messageStore']);
            } elseif (!empty($media['ingest_filename'])) {
                $this->checkFile($media['ingest_filename'], $resource['messageStore']);
            } elseif (!empty($media['ingest_directory'])) {
                $this->checkDirectory($media['ingest_directory'], $resource['messageStore']);
            } else {
                // Add a warning: cannot be checked for other media ingester? Or is it checked somewhere else?
                continue;
            }
        }

        return !$resource['messageStore']->hasErrors();
    }

    /**
     * Process entities.
     */
    protected function processEntities(array $dataResources): \BulkImport\Processor\Processor
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
    protected function createEntities(array $dataResources): \BulkImport\Processor\Processor
    {
        $resourceName = $this->getResourceName();
        $this->createResources($resourceName, $dataResources);
        return $this;
    }

    /**
     * Process creation of resources.
     */
    protected function createResources($resourceName, array $dataResources): \BulkImport\Processor\Processor
    {
        if (!count($dataResources)) {
            return $this;
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
     * Process update of entities.
     */
    protected function updateEntities(array $dataResources): \BulkImport\Processor\Processor
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
    protected function updateResources($resourceName, array $dataResources): \BulkImport\Processor\Processor
    {
        if (!count($dataResources)) {
            return $this;
        }

        // In the api manager, batchUpdate() allows to update a set of resources
        // with the same data. Here, data are specific to each entry, so each
        // resource is updated separately.

        // Clone is required to keep option to throw issue. The api plugin may
        // be used by other methods.
        $api = clone $this->bulk->api(null, true);
        foreach ($dataResources as $dataResource) {
            $options = [];
            $fileData = [];

            switch ($this->action) {
                case self::ACTION_APPEND:
                case self::ACTION_REPLACE:
                    $options['isPartial'] = false;
                    break;
                case self::ACTION_REVISE:
                case self::ACTION_UPDATE:
                    $options['isPartial'] = true;
                    $options['collectionAction'] = 'replace';
                    break;
                default:
                    return $this;
            }
            $dataResource = $this->updateData($resourceName, $dataResource);

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
            $this->logger->notice(
                'Index #{index}: Updated {resource_name} #{resource_id}', // @translate
                ['index' => $this->indexResource, 'resource_name' => $this->bulk->label($resourceName), 'resource_id' => $dataResource['o:id']]
            );
        }

        return $this;
    }

    /**
     * Process deletion of entities.
     */
    protected function deleteEntities(array $dataResources): \BulkImport\Processor\Processor
    {
        $resourceName = $this->getResourceName();
        $this->deleteResources($resourceName, $dataResources);
        return $this;
    }

    /**
     * Process deletion of resources.
     */
    protected function deleteResources($resourceName, array $dataResources): \BulkImport\Processor\Processor
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
    protected function skipEntities(array $dataResources): \BulkImport\Processor\Processor
    {
        $resourceName = $this->getResourceName();
        $this->skipResources($resourceName, $dataResources);
        return $this;
    }

    /**
     * Process skipping of resources.
     */
    protected function skipResources($resourceName, array $dataResources): \BulkImport\Processor\Processor
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

    protected function prepareAction(): \BulkImport\Processor\Processor
    {
        $this->action = $this->getParam('action') ?: self::ACTION_CREATE;
        if (!in_array($this->action, [
            self::ACTION_CREATE,
            self::ACTION_APPEND,
            self::ACTION_REVISE,
            self::ACTION_UPDATE,
            self::ACTION_REPLACE,
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

    protected function prepareActionUnidentified(): \BulkImport\Processor\Processor
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

    protected function prepareIdentifierNames(): \BulkImport\Processor\Processor
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

    protected function prepareActionIdentifier(): \BulkImport\Processor\Processor
    {
        if (!in_array($this->action, [
            self::ACTION_REVISE,
            self::ACTION_UPDATE,
        ])) {
            $this->actionIdentifier = self::ACTION_SKIP;
            return $this;
        }

        // This option doesn't apply when "o:id" is the only one identifier.
        $identifierNames = $this->bulk->getIdentifierNames();
        if (empty($identifierNames)
            || (count($identifierNames) === 1 && reset($identifierNames) === 'o:id')
        ) {
            $this->actionIdentifier = self::ACTION_SKIP;
            return $this;
        }

        $this->actionIdentifier = $this->getParam('action_identifier_update') ?: self::ACTION_APPEND;
        if (!in_array($this->actionIdentifier, [
            self::ACTION_APPEND,
            self::ACTION_UPDATE,
        ])) {
            $this->logger->err(
                'Action "{action}" for identifier is not managed.', // @translate
                ['action' => $this->actionIdentifier]
            );
            $this->actionIdentifier = self::ACTION_APPEND;
        }

        // TODO Prepare the list of identifiers one time (only properties) (see extractIdentifiers())?
        return $this;
    }

    protected function prepareActionMedia(): \BulkImport\Processor\Processor
    {
        $this->actionMedia = $this->getParam('action_media_update') ?: self::ACTION_APPEND;
        if (!in_array($this->actionMedia, [
            self::ACTION_APPEND,
            self::ACTION_UPDATE,
        ])) {
            $this->logger->err(
                'Action "{action}" for media (update of item) is not managed.', // @translate
                ['action' => $this->actionMedia]
            );
            $this->actionMedia = self::ACTION_APPEND;
        }
        return $this;
    }

    protected function prepareActionItemSet(): \BulkImport\Processor\Processor
    {
        $this->actionItemSet = $this->getParam('action_item_set_update') ?: self::ACTION_APPEND;
        if (!in_array($this->actionItemSet, [
            self::ACTION_APPEND,
            self::ACTION_UPDATE,
        ])) {
            $this->logger->err(
                'Action "{action}" for item set (update of item) is not managed.', // @translate
                ['action' => $this->actionItemSet]
            );
            $this->actionItemSet = self::ACTION_APPEND;
        }
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
    protected function extractSourceIdentifiers(?ArrayObject $resource, ?Entry $entry = null): \BulkImport\Processor\Processor
    {
        if (!$resource) {
            return $this;
        }

        $storeMain = function ($idOrIdentifier) use ($resource) {
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

        $storeLinkedIdentifier = function ($idOrIdentifier, $vrId) {
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

        // Store identifiers for linked resources.
        foreach ($resource as $term => $values) {
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
    protected function storeSourceIdentifiersIds(array $dataResource, AbstractResourceEntityRepresentation $resource): \BulkImport\Processor\Processor
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
     * Add automapped metadata for properties (language and datatypes).
     *
     * @todo Merge this method with transformSource::getNormalizedConfig().
     */
    protected function prepareMapping(): \BulkImport\Processor\Processor
    {
        $isPrepared = false;
        if (method_exists($this->reader, 'getConfigParam')) {
            $mappingConfig = $this->reader->getParam('mapping_config') ?: $this->reader->getConfigParam('mapping_config');
            if ($mappingConfig) {
                $isPrepared = true;
                $mapping = [];
                // TODO Avoid to prepare the mapping a second time when the reader prepared it.
                $this->initTransformSource($mappingConfig, $this->reader->getParams());
                $mappingSource = array_merge(
                    $this->transformSource->getSection('default'),
                    $this->transformSource->getSection('mapping')
                );
                foreach ($mappingSource as $fromTo) {
                    // The from is useless here, the entry takes care of it.
                    if (isset($fromTo['to']['dest'])) {
                        // Manage multimapping: there may be multiple target fields.
                        // TODO Improve multimapping management (for spreadsheet in fact).
                        $mapping[$fromTo['to']['dest']][] = $fromTo['to']['field'] ?? null;
                    }
                }
                // Filter duplicated and null values.
                foreach ($mapping as &$datas) {
                    $datas = array_values(array_unique(array_filter(array_map('strval', $datas), 'strlen')));
                }
                unset($datas);

                // These mappings are added automatically with JsonEntry.
                // TODO Find a way to add automatic mapping in transformSource or default mapping.
                if (!isset($mapping['url'])) {
                    $mapping['url'] = ['url'];
                }
                if (!isset($mapping['iiif'])) {
                    $mapping['iiif'] = ['iiif'];
                }
            }
        }

        if (!$isPrepared) {
            $mapping = $this->getParam('mapping', []);
        }

        if (!count($mapping)) {
            $this->hasMapping = false;
            $this->mapping = [];
            return $this;
        }

        // The automap is only used for language, datatypes and visibility:
        // the properties are the one that are set by the user.
        // TODO Avoid remapping or factorize when done in transformSource.
        /** @var \BulkImport\Mvc\Controller\Plugin\AutomapFields $automapFields */
        $automapFields = $this->getServiceLocator()->get('ControllerPluginManager')->get('automapFields');
        $sourceFields = $automapFields(array_keys($mapping), ['output_full_matches' => true]);

        $index = -1;
        foreach ($mapping as $sourceField => $targets) {
            ++$index;
            if (empty($targets)) {
                continue;
            }

            // The automap didn't find any matching.
            if (empty($sourceFields[$index])) {
                foreach ($targets as $target) {
                    $sourceFields[$index][] = [
                        'field' => $target,
                        'language' => null,
                        'type' => null,
                        'is_public' => null,
                    ];
                }
            }

            // Default metadata (datatypes, language and visibility).
            // For consistency, only the first metadata is used.
            $metadatas = $sourceFields[$index];
            $metadata = reset($metadatas);

            $fullTargets = [];
            foreach ($targets as $target) {
                $result = [];
                // Field is the property found by automap, or any other metadata.
                // This value is not used, but may be useful for messages.
                $result['field'] = $metadata['field'];

                // Manage the property of a target when it is a resource type,
                // like "o:item_set [dcterms:title]".
                // It is used to set a metadata for derived resource (media for
                // item) or to find another resource (item set for item, as an
                // identifier name).
                $pos = strpos($target, '[');
                if ($pos) {
                    $targetData = trim(substr($target, $pos + 1), '[] ');
                    $target = trim(substr($target, $pos));
                    $result['target'] = $target;
                    $result['target_data'] = $targetData;
                    $propertyId = $this->bulk->getPropertyId($targetData);
                    if ($propertyId) {
                        $subValue = [];
                        $subValue['property_id'] = $propertyId;
                        // TODO Allow different types for subvalues (inside "[]").
                        $subValue['type'] = 'literal';
                        $subValue['is_public'] = true;
                        $result['target_data_value'] = $subValue;
                    }
                } else {
                    $result['target'] = $target;
                }

                $propertyId = $this->bulk->getPropertyId($target);
                if ($propertyId) {
                    $datatypes = [];
                    // Normally already checked.
                    foreach ($metadata['datatype'] ?? [] as $datatype) {
                        $datatypes[] = $this->bulk->getDataTypeName($datatype);
                    }
                    $datatypes = array_filter(array_unique($datatypes));
                    if (empty($datatypes)) {
                        $datatype = 'literal';
                    } elseif (count($datatypes) === 1) {
                        $datatype = reset($datatypes);
                    } else {
                        $datatype = null;
                    }
                    $result['value']['property_id'] = $propertyId;
                    $result['value']['type'] = $datatype;
                    $result['value']['@language'] = $metadata['language'];
                    $result['value']['is_public'] = $metadata['is_public'] !== 'private';
                    if (is_null($datatype)) {
                        $result['datatype'] = $datatypes;
                    }
                }
                // A specific or module field. These fields may be useless.
                // TODO Check where this exception is used.
                else {
                    $result['full_field'] = $sourceField;
                    $result['@language'] = $metadata['language'];
                    $result['type'] = empty($metadata['datatype'])
                        ? null
                        : (is_array($metadata['datatype']) ? reset($metadata['datatype']) : (string) $metadata['datatype']);
                    $result['is_public'] = $metadata['is_public'] !== 'private';
                }

                $fullTargets[] = $result;
            }
            $mapping[$sourceField] = $fullTargets;
        }

        // Filter the mapping to avoid to loop entries without target.
        $this->mapping = array_filter($mapping);
        // Some readers don't need a mapping (xml reader do the process itself).
        $this->hasMapping = (bool) $this->mapping;

        return $this;
    }

    /**
     * Prepare other internal data.
     */
    protected function appendInternalParams(): \BulkImport\Processor\Processor
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $internalParams = [];
        $internalParams['iiifserver_media_api_url'] = $settings->get('iiifserver_media_api_url', '');
        if ($internalParams['iiifserver_media_api_url']
            && mb_substr($internalParams['iiifserver_media_api_url'], -1) !== '/'
        ) {
            $internalParams['iiifserver_media_api_url'] .= '/';
        }
        $this->setParams(array_merge($this->getParams() + $internalParams));
        return $this;
    }

    /**
     * Create a new asset from a url.
     *
     * @todo Find the good position of this function.
     * @todo Rewrite and simplify.
     */
    protected function createAssetFromUrl(string $pathOrUrl, ?MessageStore $messageStore = null): ?Asset
    {
        // AssetAdapter requires an uploaded file, but it's common to use urls
        // in bulk import.
        $isUrl = $this->bulk->isUrl($pathOrUrl);
        $this->checkAssetMediaType = true;
        if ($isUrl) {
            $result = $this->checkUrl($pathOrUrl, $messageStore);
        } else {
            $result = $this->checkFile($pathOrUrl, $messageStore);
        }
        if (!$result) {
            return null;
        }

        $storageId = bin2hex(\Laminas\Math\Rand::getBytes(20));
        $filename = mb_substr(basename($pathOrUrl), 0, 255);
        // TODO Set the real extension via tempFile().
        $extension = pathinfo($pathOrUrl, PATHINFO_EXTENSION);

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

        // A check to get the real medai-type and extension.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mediaType = $finfo->file($fullPath);
        $mediaType = \Omeka\File\TempFile::MEDIA_TYPE_ALIASES[$mediaType] ?? $mediaType;
        // TODO Get the extension from the media type or use standard asset uploaded.

        // This doctrine resource should be reloaded each time the entity
        // manager is cleared, else a error may occur on big import.
        $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
        $this->user = $entityManager->find(\Omeka\Entity\User::class, $this->userId);

        $asset = new \Omeka\Entity\Asset;
        $asset->setName($filename);
        // TODO Use the user specified in the config (owner).
        $asset->setOwner($this->user);
        $asset->setStorageId($storageId);
        $asset->setExtension($extension);
        $asset->setMediaType($mediaType);
        $asset->setAltText(null);

        // TODO Remove this flush (required because there is a clear() after checks).
        $entityManager->persist($asset);
        $entityManager->flush();

        return $asset;
    }
}
