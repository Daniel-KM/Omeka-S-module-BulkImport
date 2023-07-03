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
use Omeka\Api\Request;

/**
 * Can be used for all derivative of AbstractResourceRepresentation.
 */
abstract class AbstractResourceProcessor extends AbstractProcessor implements Configurable, Parametrizable
{
    use ConfigurableTrait, ParametrizableTrait;
    use CheckTrait;
    use DiffResourcesTrait;
    use DiffValuesTrait;
    use FileTrait;

    const ACTION_SUB_UPDATE = 'sub_update';

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
     * Allowed fields to create or update resources.
     *
     * @see \Omeka\Api\Representation\AssetRepresentation
     * @var array
     */
    protected $metadataData = [
        // @todo Currently not used.
        'fields' => [],
        'meta_mapper_config' => [],
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

    public function handleConfigForm(Form $form): self
    {
        $values = $form->getData();
        $config = new ArrayObject;
        $this->handleFormGeneric($config, $values);
        $this->handleFormSpecific($config, $values);
        $this->setConfig($config->getArrayCopy());
        return $this;
    }

    public function handleParamsForm(Form $form): self
    {
        $values = $form->getData();
        $params = new ArrayObject;
        $this->handleFormGeneric($params, $values);
        $this->handleFormSpecific($params, $values);
        $params['mapping'] = $values['mapping'] ?? [];
        $this->setParams($params->getArrayCopy());
        return $this;
    }

    protected function handleFormGeneric(ArrayObject $args, array $values): self
    {
        return $this;
    }

    protected function handleFormSpecific(ArrayObject $args, array $values): self
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

        $this->logger->notice(
            'The process will run action "{action}" with option "{mode}" for unindentified resources.', // @translate
            ['action' => $this->action, 'mode' => $this->actionUnidentified]
        );

        $mainResourceName = $this->mainResourceNames[$this->getResourceName()] ?? null;
        if (!$mainResourceName) {
            $this->logger->err(
                'The resource name is not set.' // @translate
            );
            ++$this->totalErrors;
            return;
        }

        // @todo To set the resource name as object type to reader, is it needed?
        // Warning: it may change for mixed resources.
        $this->reader->setObjectType($this->getResourceName());

        $this
            ->prepareIdentifierNames()
            ->prepareSpecific()
            ->prepareMetaConfig();

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
            ->initializeCheckLog();

        if ($this->totalErrors) {
            $this
                ->purgeCheckStore()
                ->finalizeCheckLog();
            return;
        }

        // Store the file in params to get it in user interface and next.
        /** @var \Omeka\Entity\Job $jobJob */
        $jobJob = $this->job->getJob();
        if ($jobJob->getId()) {
            $jobJobArgs = $jobJob->getArgs();
            $jobJobArgs['filename_log'] = basename((string) $this->filepathLog);
            $jobJob->setArgs($jobJobArgs);
            $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
            $entityManager->persist($jobJob);
            $entityManager->flush();
        }

        // Step 1/3: list and prepare identifiers.

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

        $this->processingError = $this->getParam('processing', 'stop_on_error') ?: 'stop_on_error';
        $this->skipMissingFiles = (bool) $this->getParam('skip_missing_files', false);

        $this
            ->prepareFullRun()
            ->checkDiffResources()
            ->checkDiffValues();

        $dryRun = $this->processingError === 'dry_run';
        if ($dryRun) {
            $this->logger->notice(
                'Processing is ended: dry run.' // @translate
            );
            $this
                ->purgeCheckStore()
                ->finalizeCheckLog();
            return;
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
                $this
                    ->purgeCheckStore()
                    ->finalizeCheckLog();
                return;
            }
        }

        // A stop may occur during dry run. Message is already logged.
        if ($this->job->shouldStop()) {
            $this
                ->purgeCheckStore()
                ->finalizeCheckLog();
            return;
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

        $this
            ->purgeCheckStore()
            ->finalizeCheckLog();
    }

    protected function prepareAction(): self
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

    protected function prepareSpecific(): self
    {
        return $this;
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
                ?: $this->getConfigParam('mapping_config', '');
                // ?: ($this->getConfigParam('mapping_config', '') ?: null);
        }
        if (is_null($mappingConfig)) {
            $mappingConfig = $this->getParam('mapping', []);
        }
        if (!$mappingConfig) {
            return $this;
        }

        $this->metaMapper->getMetaMapperConfig(
            'resources',
            $mappingConfig,
            $this->metadataData['meta_mapper_config']
        );

        $error = $this->metaMapper->getMetaMapperConfig()->hasError();
        if ($error) {
            ++$this->totalErrors;
            if ($error === true) {
                $this->logger->err(new PsrMessage('Error in the mapping config.')); // @translate
            } else {
                $this->logger->err(new PsrMessage(
                    'Error in the mapping config: {message}', // @translate
                    ['message' => $error]
                ));
            }
        }

        return $this;
    }

    /**
     * Get the list of identifiers without any check.
     */
    protected function prepareListOfIdentifiers(): self
    {
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
        // Note: AppendIterator can return the same index multiple times (inner
        // iterator), so use an incrementor and use the combinaison of the main
        // iterator and the inner iterator for logs.
        // TODO Add log for the main iterator and the inner iterator.
        $this->currentEntryIndex = 0;

        // The main index is human one-based.

        foreach ($this->reader as /* $innerIndex => */ $entry) {
            ++$this->currentEntryIndex;
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

            if ($toSkip) {
                --$toSkip;
                ++$this->totalSkipped;
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

        // Simplify identifiers revert.
        $this->identifiers['revert'] = array_map('array_unique', $this->identifiers['revert']);
        foreach ($this->identifiers['revert'] as &$values) {
            $values = array_combine($values, $values);
        }
        unset($values);

        // Empty identifiers should be null to use isset().
        $this->identifiers['mapx'] = array_map(function ($v) {
            return $v ?: null;
        }, $this->identifiers['mapx']);
        $this->identifiers['map'] = array_map(function ($v) {
            return $v ?: null;
        }, $this->identifiers['map']);

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

        $mainResourceName = $this->mainResourceNames[$this->getResourceName()];

        // Process only identifiers without ids (normally all of them).
        $emptyIdentifiers = [];
        foreach ($this->identifiers['map'] as $identifier => $id) {
            if (empty($id)) {
                $emptyIdentifiers[] = strtok((string) $identifier, '§');
            }
        }

        $identifierNames = $this->bulk->getIdentifierNames();

        if ($mainResourceName === 'assets') {
            $ids = $this->findAssetsFromIdentifiers($emptyIdentifiers, $identifierNames);
        } elseif ($mainResourceName === 'resources') {
            $ids = $this->bulk->findResourcesFromIdentifiers($emptyIdentifiers, $identifierNames);
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

        $toSkip = (int) $this->getParam('entries_to_skip', 0);
        $maxEntries = (int) $this->getParam('entries_max', 0);
        $maxRemaining = $maxEntries;

        // TODO Do a first loop to get all identifiers and linked resources to speed up process.

        // Manage the case where the reader is zero-based or one-based.
        // Note: AppendIterator can return the same index multiple times (inner
        // iterator), so use an incrementor and use the combinaison of the main
        // iterator and the inner iterator for logs.
        // TODO Add log for the main iterator and the inner iterator.
        $this->currentEntryIndex = 0;

        foreach ($this->reader as /* $innerIndex => */ $entry) {
            ++$this->currentEntryIndex;
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

            if ($toSkip) {
                $this->logCheckedResource(null, null);
                --$toSkip;
                ++$this->totalSkipped;
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
                $this->storeCheckedResource($resource);
                $this->logCheckedResource(null, $entry);
                continue;
            }

            if (!$this->checkEntity($resource)) {
                ++$this->totalErrors;
                $this->storeCheckedResource($resource);
                $this->logCheckedResource($resource, $entry);
                continue;
            }

            ++$this->totalProcessed;
            $this->storeCheckedResource($resource);

            $this->logCheckedResource($resource, $entry);
        }

        $this->logger->notice(
            'Fields used to map identifiers: {names}. Check them if the mapping is not right or when existing or linked resources are not found.', // @translate
            ['names' => implode(', ', array_keys($this->bulk->getIdentifierNames()))]
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

        $toSkip = (int) $this->getParam('entries_to_skip', 0);
        $maxEntries = (int) $this->getParam('entries_max', 0);
        $maxRemaining = $maxEntries;

        /** @var \Doctrine\ORM\EntityManager $entityManager */
        $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');

        $dataToProcess = [];

        // Manage the case where the reader is zero-based or one-based.
        // Note: AppendIterator can return the same index multiple times (inner
        // iterator), so use an incrementor and use the combinaison of the main
        // iterator and the inner iterator for logs.
        // TODO Add log for the main iterator and the inner iterator.
        $this->currentEntryIndex = 0;

        foreach ($this->reader as /* $innerIndex => */ $entry) {
            ++$this->currentEntryIndex;
            if ($this->job->shouldStop()) {
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

            if ($toSkip) {
                --$toSkip;
                ++$this->totalSkipped;
                continue;
            }

            ++$this->totalIndexResources;

            // Note: a resource from the reader may contain multiple resources.
            $this->indexResource = $this->currentEntryIndex;

            if ($maxEntries) {
                --$maxRemaining;
                if ($maxRemaining < 0) {
                    break;
                }
            }

            // TODO Clarify computation of total errors.
            $resource = $this->loadCheckedResource();
            if (!$resource || !empty($resource['has_error'])) {
                ++$this->totalErrors;
                continue;
            }

            $this->logger->info(
                'Index #{index}: Process started', // @translate
                ['index' => $this->indexResource]
            );

            ++$this->totalProcessed;

            // The batch is one by one now.
            $dataToProcess[] = $resource;

            $this->processEntities($dataToProcess);
            // Avoid memory issue.
            unset($dataToProcess);
            $entityManager->flush();
            $entityManager->clear();
            // Reset for next.
            $dataToProcess = [];
        }

        if ($maxEntries && $maxRemaining < 0) {
            $this->logger->warn(
                'Index #{index}: The job "Import" was stopped: max {count} entries processed.', // @translate
                ['index' => $this->indexResource, 'count' => $maxEntries]
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

    /**
     * Process one entry to create one resource (and eventually attached ones).
     */
    protected function processEntry(Entry $entry): ?ArrayObject
    {
        // Generally, empty entries in spreadsheet are empty rows. But it may be
        // a bad conversion for other formats.
        if ($entry->isEmpty()) {
            ++$this->totalEmpty;
            return null;
        }

        $mapping = $this->metaMapper->__invoke('resources')->getMapping();
        return $mapping === null
            ? $this->processEntryFromReader($entry)
            : $this->processEntryFromProcessor($entry);
    }

    /**
     * Convert a prepared entry into a resource, setting ids for each key.
     *
     * Reader-driven extraction of data.
     *
     * So fill owner id, resource template id, resource class id, property ids.
     * Check boolean values too for is public and is open.
     */
    protected function processEntryFromReader(Entry $entry): ArrayObject
    {
        /** @var \ArrayObject $resource */
        $resource = clone $this->base;

        $resource['source_index'] = $this->indexResource;

        $keys = $this->metadataData;

        // Added for security.
        $keys['skip'] = [
            'checked_id' => null,
            // The human source index is one-based, so it means undetermined.
            'source_index' => 0,
            'messageStore' => null,
        ] + ($keys['skip'] ?? []);

        // TODO Manage filling multiple entities.
        foreach ($entry as $key => $values) {
            if (array_key_exists($key, $keys['skip'])) {
                // Nothing to do.
            } elseif (array_key_exists($key, $keys['boolean'])) {
                $this->fillBoolean($resource, $key, $values);
            } elseif (array_key_exists($key, $keys['single_data'])) {
                $resource[$key] = $values;
            } elseif (array_key_exists($key, $keys['single_entity'])) {
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
        $resource['source_index'] = $this->indexResource;
        $resource['messageStore']->clearMessages();

        $metaConfig = $this->metaMapper->__invoke('resources')->getMapping();

        foreach (['default', 'maps'] as $section) foreach ($metaConfig[$section] as $map) {
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
            $this->fillResource($resource, $map, $values);
        }

        return $resource;
    }

    protected function baseEntity(): ArrayObject
    {
        // TODO Use a specific class that extends ArrayObject to manage process metadata (check and errors).
        $resource = new ArrayObject;
        $resource['o:id'] = null;
        // The human source index is one-based, so it means undetermined.
        $resource['source_index'] = 0;
        $resource['checked_id'] = false;
        // This key is updated only for storage.
        $resource['has_error'] = null;
        $resource['messageStore'] = new MessageStore();
        $this->baseSpecific($resource);
        return $resource;
    }

    protected function baseSpecific(ArrayObject $resource): self
    {
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
     * @todo Make method fillResource a generic one (most metadata are similar).
     */
    abstract protected function fillResource(ArrayObject $resource, array $map, array $values): self;

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

        $this->checkEntitySpecific($resource);

        // Hydration check may be heavy, so check it only wen there are not
        // issue.
        if ($resource['messageStore']->hasErrors()) {
            return false;
        }

        $this->checkEntityViaHydration($resource);

        return !$resource['messageStore']->hasErrors();
    }

    protected function checkEntitySpecific(ArrayObject $resource): bool
    {
        return true;
    }

    protected function checkEntityViaHydration(ArrayObject $resource): bool
    {
        // Don't do more checks for skip.
        $operation = $this->standardOperation($this->action);
        if (!$operation) {
            return !$resource['messageStore']->hasErrors();
        }

        /** @see \Omeka\Api\Manager::execute() */
        if (!$this->checkAdapter($resource['resource_name'], $operation)) {
            $resource['messageStore']->addError('rights', new PsrMessage(
                'User has no rights to "{action}" {resource_name}.', // @translate
                ['action' => $operation, 'resource_name' => $resource['resource_name']]
            ));
            return false;
        }

        // Check through hydration and standard api.
        /** @var \Omeka\Api\Adapter\AbstractResourceEntityAdapter $adapter */
        $adapter = $this->adapterManager->get($resource['resource_name']);

        // Some options are useless here, but added anyway.
        /** @see \Omeka\Api\Request::setOption() */
        $requestOptions = [
            'continueOnError' => true,
            'flushEntityManager' => false,
            'responseContent' => 'resource',
        ];

        $request = new Request($operation, $resource['resource_name']);
        $request
            ->setContent($resource->getArrayCopy())
            ->setOption($requestOptions);

        if (empty($resource['o:id'])
            && $operation !== Request::CREATE
            && $this->actionUnidentified === self::ACTION_SKIP
        ) {
            return true;
        } elseif ($operation === Request::CREATE
            || (empty($resource['o:id']) && $this->actionUnidentified === self::ACTION_CREATE)
        ) {
            $entityClass = $adapter->getEntityClass();
            $entity = new $entityClass;
            // TODO This exception should be managed in resource or item processor.
            if ($resource['resource_name'] === 'media') {
                $entityItem = null;
                // Already checked, except when a source identifier is used.
                if (empty($resource['o:item']['o:id'])) {
                    if (!empty($resource['o:item']['source_identifier']) && !empty($resource['o:item']['checked_id'])) {
                        $entityItem = new \Omeka\Entity\Item;
                    }
                } else {
                    try {
                        $entityItem = $adapter->getAdapter('items')->findEntity($resource['o:item']['o:id']);
                    } catch (\Exception $e) {
                        // Managed below.
                    }
                }
                if (!$entityItem) {
                    $resource['messageStore']->addError('media', new PsrMessage(
                        'Media must belong to an item.' // @translate
                    ));
                    return false;
                }
                $entity->setItem($entityItem);
            } elseif ($resource['resource_name'] === 'assets') {
                $entity->setName($resource['o:name'] ?? '');
            }
        } else {
            $request
                ->setId($resource['o:id']);

            $entity = $adapter->findEntity($resource['o:id']);
            // \Omeka\Api\Adapter\AbstractEntityAdapter::authorize() is protected.
            if (!$this->acl->userIsAllowed($entity, $operation)) {
                $resource['messageStore']->addError('rights', new PsrMessage(
                    'User has no rights to "{action}" {resource_name} {resource_id}.', // @translate
                    ['action' => $operation, 'resource_name' => $resource['resource_name'], 'resource_id' => $resource['o:id']]
                ));
                return false;
            }

            // For deletion, just check rights.
            if ($operation === Request::DELETE) {
                return !$resource['messageStore']->hasErrors();
            }
        }

        // Complete from api modules (api.execute/create/update.pre).
        /** @see \Omeka\Api\Manager::initialize() */
        try {
            $this->apiManager->initialize($adapter, $request);
        } catch (\Exception $e) {
            $resource['messageStore']->addError('modules', new PsrMessage(
                'Initialization exception: {exception}', // @translate
                ['exception' => $e]
            ));
            return false;
        }

        // Check new files for assets, items and media before hydration to speed
        // process, because files are checked during hydration too, but with a
        // full download.
        $this->checkNewFiles($resource);

        // Some files may have been removed.
        if ($this->skipMissingFiles) {
            $request
                ->setContent($resource->getArrayCopy());
        }

        // The entity is checked here to store error when there is a file issue.
        $errorStore = new \Omeka\Stdlib\ErrorStore;
        $adapter->validateRequest($request, $errorStore);
        $adapter->validateEntity($entity, $errorStore);

        // TODO Process hydration checks except files or use an event to check files differently during hydration or store loaded url in order to get all results one time.
        // TODO In that case, check iiif image or other media that may have a file or url too.

        if ($resource['messageStore']->hasErrors() || $errorStore->hasErrors()) {
            $resource['messageStore']->mergeErrors($errorStore);
            return false;
        }

        // TODO Check the file for asset.
        if ($resource['resource_name'] === 'assets') {
            return !$resource['messageStore']->hasErrors();
        }

        // Don't check new files twice. Furthermore, the media are pre-hydrated
        // and a flush somewhere may duplicate the item.
        // TODO Use a second entity manager.
        /*
        $isItem = $resource['resource_name'] === 'items';
        if ($isItem) {
            $res = $request->getContent();
            unset($res['o:media']);
            $request->setContent($res);
        }
         */

        // Process hydration checks for remaining checks, in particular media.
        // This is the same operation than api create/update, but without
        // persisting entity.
        // Normally, all data are already checked, except actual medias.
        $errorStore = new \Omeka\Stdlib\ErrorStore;
        try {
            $adapter->hydrateEntity($request, $entity, $errorStore);
        } catch (\Exception $e) {
            $resource['messageStore']->addError('validation', new PsrMessage(
                'Validation exception: {exception}', // @translate
                ['exception' => $e]
            ));
            return false;
        }

        // Remove pre-hydrated entities from entity manager: it was only checks.
        // TODO Ideally, checks should be done on a different entity manager, so modify service before and after.
        /** @var \Doctrine\ORM\EntityManager $entityManager */
        $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
        $entityManager->clear();

        // Merge error store with resource message store.
        $resource['messageStore']->mergeErrors($errorStore, 'validation');
        if ($resource['messageStore']->hasErrors()) {
            return false;
        }

        // TODO Check finalize (post) api process. Note: some modules flush results.

        return !$resource['messageStore']->hasErrors();
    }

    /**
     * Check if new files (local system and urls) are available and allowed.
     *
     * Just warn when a file is missing with mode "skip missing files" (only for
     * items with medias, not media alone or assets).
     *
     * By construction, it's not possible to check or modify existing files.
     */
    protected function checkNewFiles(ArrayObject $resource): bool
    {
        if (!in_array($resource['resource_name'], ['items', 'media', 'assets'])) {
            return true;
        }

        if ($resource['resource_name'] === 'items') {
            $resourceFiles = $resource['o:media'] ?? [];
            if (!$resourceFiles) {
                return true;
            }
        } else {
            $resourceFiles = [$resource];
        }

        $isItem = $resource['resource_name'] === 'items';
        if ($this->skipMissingFiles && $isItem) {
            return $this->checkItemFilesWarn($resource, $resourceFiles);
        }

        foreach ($resourceFiles as $resourceFile) {
            // A file cannot be updated.
            if (!empty($resourceFile['o:id'])) {
                continue;
            }
            if (!empty($resourceFile['ingest_url'])) {
                $this->checkUrl($resourceFile['ingest_url'], $resource['messageStore']);
            } elseif (!empty($resourceFile['ingest_filename'])) {
                $this->checkFile($resourceFile['ingest_filename'], $resource['messageStore']);
            } elseif (!empty($resourceFile['ingest_directory'])) {
                $this->checkDirectory($resourceFile['ingest_directory'], $resource['messageStore']);
            } else {
                // Add a warning: cannot be checked for other media ingester? Or is it checked somewhere else?
            }
        }

         return !$resource['messageStore']->hasErrors();
    }

    /**
     * Warn if new files (local system and urls) are available and allowed.
     *
     * By construction, it's not possible to check or modify existing files.
     * So this method is only for items.
     */
    protected function checkItemFilesWarn(ArrayObject $resource): bool
    {
        // This array allows to skip check when the same file is imported
        // multiple times, in particular during tests of an import.
        static $missingFiles = [];

        $resource['messageStore']->setStoreNewErrorAsWarning(true);

        $ingestData = [
            'ingest_url' => [
                'method' => 'checkUrl',
                'message_msg' => 'Cannot fetch url "{url}". The url is skipped.', // @translate
                'message_key' => 'url',
            ],
            'ingest_filename' => [
                'method' => 'checkFile',
                'message_msg' => 'Cannot fetch file "{file}". The file is skipped.', // @translate
                'message_key' => 'file',
            ],
            'ingest_directory' => [
                'method' => 'checkDirectory',
                'message_msg' => 'Cannot fetch directory "{file}". The file is skipped.', // @translate
                'message_key' => 'file',
            ],
        ];

        $resourceFiles = $resource['o:media'];
        foreach ($resourceFiles as $key => $resourceFile) {
            // A file cannot be updated.
            if (!empty($resourceFile['o:id'])) {
                continue;
            }

            $ingestSourceKey = !empty($resourceFile['ingest_url'])
                ? 'ingest_url'
                : (!empty($resourceFile['ingest_filename'])
                    ? 'ingest_filename'
                    : (!empty($resourceFile['ingest_directory'])
                        ? 'ingest_directory'
                        : null));
            if (!$ingestSourceKey) {
                // Add a warning: cannot be checked for other media ingester? Or is it checked somewhere else?
                continue;
            }

            // Method is "checkFile", "checkUrl" or "checkDirectory".
            $ingestSource = $resourceFile[$ingestSourceKey];
            $method = $ingestData[$ingestSourceKey]['method'];
            $messageMsg = $ingestData[$ingestSourceKey]['message_msg'];
            $messageKey = $ingestData[$ingestSourceKey]['message_key'];

            if (isset($missingFiles[$ingestSourceKey][$ingestSource])) {
                $resource['messageStore']->addWarning($messageKey, new PsrMessage($messageMsg, [$messageKey => $ingestSource]));
            } else {
                $result = $this->$method($ingestSource, $resource['messageStore']);
                if (!$result) {
                    $missingFiles[$ingestSourceKey][$ingestSource] = true;
                }
            }
            if (isset($missingFiles[$ingestSourceKey][$ingestSource])) {
                unset($resource['o:media'][$key]);
            }
        }

        $resource['messageStore']->setStoreNewErrorAsWarning(false);

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
        // TODO Check if the resource name is the good one.
        if ($resource['o:id']) {
            $resourceName = $resource['resource_name'] ?: $this->getResourceName();
            if (empty($resourceName) || $resourceName === 'resources') {
                if (isset($resource['messageStore'])) {
                    $resource['messageStore']->addError('resource_name', new PsrMessage(
                        'The resource id #{id} cannot be checked: the resource type is undefined.', // @translate
                        ['id' => $resource['o:id']]
                    ));
                } else {
                    $this->logger->err(
                        'Index #{index}: The resource id #{id} cannot be checked: the resource type is undefined.', // @translate
                        ['index' => $this->indexResource, 'id' => $resource['o:id']]
                    );
                    $resource['has_error'] = true;
                }
            } else {
                $resourceId = $resourceName === 'assets'
                    ? $this->bulk->api()->searchOne('assets', ['id' => $resource['o:id']])->getContent()->id()
                    : $this->bulk->findResourceFromIdentifier($resource['o:id'], 'o:id', $resourceName, $resource['messageStore'] ?? null);
                if (!$resourceId) {
                    if (isset($resource['messageStore'])) {
                        $resource['messageStore']->addError('resource_id', new PsrMessage(
                            'The id #{id} of this resource doesn’t exist.', // @translate
                            ['id' => $resource['o:id']]
                        ));
                    } else {
                        $this->logger->err(
                            'Index #{index}: The id #{id} of this resource doesn’t exist.', // @translate
                            ['index' => $this->indexResource, 'id' => $resource['o:id']]
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
     * Fill id of resource if not set. No check if set, so use checkId() first.
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
            $ids = [];
            if (empty($this->identifiers['mapx'][$resource['source_index']])) {
                $mainResourceName = $this->mainResourceNames[$resourceName];
                if ($mainResourceName === 'assets') {
                    $ids = $this->findAssetsFromIdentifiers($identifiers, $identifierName);
                } elseif ($mainResourceName === 'resources') {
                    $ids = $this->bulk->findResourcesFromIdentifiers($identifiers, $identifierName, $resourceName, $resource['messageStore'] ?? null);
                }
                $ids = array_filter($ids);
                // Store the id one time.
                // TODO Merge with storeSourceIdentifiersIds().
                if ($ids) {
                    foreach ($ids as $identifier => $id) {
                        $idEntity = $id . '§' . $mainResourceName;
                        $this->identifiers['mapx'][$resource['source_index']] = $idEntity;
                        $this->identifiers['map'][$identifier . '§' . $mainResourceName] = $idEntity;
                    }
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
                }
            } elseif (!empty($this->identifiers['mapx'][$resource['source_index']])) {
                $ids = array_fill_keys($identifiers, (int) strtok((string) $this->identifiers['mapx'][$resource['source_index']], '§'));
            }
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
            return true;
        }

        return false;
    }

    /**
     * Process entities.
     *
     * @todo Keep order of resources when process is done by batch.
     * Useless when batch size of process is one.
     * @todo Create an option for full order by id for items, then media, but it should be done on all resources, not the batch one.
     * See previous version (before 3.4.39).
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
    protected function createResources($defaultResourceName, array $dataResources): self
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
            // Manage mixed resources.
            $resourceName = $dataResource['resource_name'] ?? $defaultResourceName;
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
            if (!$resource instanceof AssetRepresentation && $resource->resourceName() === 'media') {
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
    protected function updateResources($defaultResourceName, array $dataResources): self
    {
        if (!count($dataResources)) {
            return $this;
        }

        // The "resources" cannot be updated directly.
        $checkResourceName = $defaultResourceName === 'resources';

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

            $resourceName = $dataResource['resource_name'] ?? $defaultResourceName;

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
    protected function deleteResources($defaultResourceName, array $dataResources): self
    {
        if (!count($dataResources)) {
            return $this;
        }

        // Get ids (already checked normally).
        // Manage mixed resources too.
        $ids = [];
        foreach ($dataResources as $dataResource) {
            if (isset($dataResource['o:id'])) {
                $ids[$dataResource['o:id']] = $dataResource['resource_name'] ?? $defaultResourceName;
            }
        }
        $hasMultipleResourceNames = count(array_unique($ids)) > 1;

        try {
            if (count($ids) === 1) {
                $resourceName = reset($ids);
                $this->bulk->api(null, true)
                    ->delete($resourceName, key($ids))->getContent();
            } elseif ($ids) {
                if ($hasMultipleResourceNames) {
                    foreach ($ids as $id => $resourceName) {
                        $this->bulk->api(null, true)
                            ->batch($resourceName, $id)->getContent();
                    }
                } else {
                    $resourceName = reset($ids);
                    $this->bulk->api(null, true)
                        ->batchDelete($resourceName, array_keys($ids), [], ['continueOnError' => true])->getContent();
                }
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

        foreach ($ids as $id => $resourceName) {
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

    protected function standardOperation(string $action): ?string
    {
        $actionsToOperations = [
            self::ACTION_CREATE => \Omeka\Api\Request::CREATE,
            self::ACTION_APPEND => \Omeka\Api\Request::UPDATE,
            self::ACTION_REVISE => \Omeka\Api\Request::UPDATE,
            self::ACTION_UPDATE => \Omeka\Api\Request::UPDATE,
            self::ACTION_REPLACE => \Omeka\Api\Request::UPDATE,
            self::ACTION_DELETE => \Omeka\Api\Request::DELETE,
            self::ACTION_SKIP => null,
        ];
        return $actionsToOperations[$action] ?? null;
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

        $mainResourceName = $this->mainResourceNames[$this->getResourceName()];

        // Main identifiers.
        $identifierNames = $this->bulk->getIdentifierNames();
        foreach ($identifierNames as $identifierName) {
            if ($identifierName === 'o:id') {
                $storeMain($resource['o:id'] ?? null, $mainResourceName);
            } elseif ($identifierName === 'o:storage_id') {
                $storeMain($resource['o:storage_id'] ?? null, $mainResourceName);
            } elseif ($identifierName === 'o:name') {
                $storeMain($resource['o:name'] ?? null, $mainResourceName);
            } else {
                $term = $this->bulk->getPropertyTerm($identifierName);
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
     * Set missing ids when source contains identifiers not yet imported during
     * resource building.
     */
    protected function completeResourceIdentifierIds(array $resource): array
    {
        if (empty($resource['o:id'])
            && !empty($resource['source_index'])
            && !empty($this->identifiers['mapx'][$resource['source_index']])
        ) {
            $resource['o:id'] = (int) strtok((string) $this->identifiers['mapx'][$resource['source_index']], '§');
        }

        // TODO Move these checks into the right processor.
        // TODO Add checked_id?

        if ($resource['resource_name'] === 'items') {
            foreach ($resource['o:item_set'] ?? [] as $key => $itemSet) {
                if (empty($itemSet['o:id'])
                    && !empty($itemSet['source_identifier'])
                    && !empty($this->identifiers['map'][$itemSet['source_identifier'] . '§resources'])
                    // TODO Add a check for item set identifier.
                ) {
                    $resource['o:item_set'][$key]['o:id'] = (int) strtok((string) $this->identifiers['map'][$itemSet['source_identifier'] . '§resources'], '§');
                }
            }
            // TODO Fill media identifiers for update here?
        }

        if ($resource['resource_name'] === 'media'
            && empty($resource['o:item']['o:id'])
            && !empty($resource['o:item']['source_identifier'])
            && !empty($this->identifiers['map'][$resource['o:item']['source_identifier'] . '§resources'])
            // TODO Add a check for item identifier.
        ) {
            $resource['o:item']['o:id'] = (int) strtok((string) $this->identifiers['map'][$resource['o:item']['source_identifier'] . '§resources'], '§');
        }

        // TODO Useless for now with assets: don't create resource on unknown resources. Maybe separate options create/skip for main resources and related resources.
        if ($resource['resource_name'] === 'assets') {
            foreach ($resource['o:resource'] ?? [] as $key => $thumbnailForResource) {
                if (empty($thumbnailForResource['o:id'])
                    && !empty($thumbnailForResource['source_identifier'])
                    && !empty($this->identifiers['map'][$thumbnailForResource['source_identifier'] . '§resources'])
                    // TODO Add a check for resource identifier.
                ) {
                    $resource['o:resource'][$key]['o:id'] = (int) strtok((string) $this->identifiers['map'][$thumbnailForResource['source_identifier'] . '§resources'], '§');
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
                    && !empty($this->identifiers['map'][$value['source_identifier'] . '§resources'])
                ) {
                    $resource[$term][$key]['value_resource_id'] = (int) strtok((string) $this->identifiers['map'][$value['source_identifier'] . '§resources'], '§');
                }
            }
        }

        return $resource;
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
