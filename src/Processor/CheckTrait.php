<?php declare(strict_types=1);

namespace BulkImport\Processor;

use ArrayObject;
use BulkImport\Entry\Entry;
use Laminas\Log\Logger;

/**
 * Manage checks of source.
 *
 * @todo Create a message store with severity.
 * @todo Add data through a specific logger adapter. Note processing message are useless here (starting, ending…). Or use a separate logger.
 *
 * Adapted from BulkCheck.
 * @see \BulkCheck\Job\AbstractCheck
 */
trait CheckTrait
{
    /**
     * Max number of rows to output in spreadsheet.
     * @var int
     */
    protected $spreadsheetRowLimit = 1000000;

    /**
     * @var resource
     */
    protected $handleLog;

    /**
     * Default options for output (tsv).
     *
     * @var array
     */
    protected $options = [
        'delimiter' => "\t",
        'enclosure' => 0,
        'escape' => 0,
    ];

    protected $columnsLog = [
        'index' => 'Index', // @translate
        'errors' => 'Total errors', // @translate
        'index_message' => 'Index message', // @translate
        'severity' => 'Severity', // @translate
        'type' => 'Type', // @translate
        'message' => 'Message', // @translate
    ];

    /**
     * @var string
     */
    protected $filepathLog;

    /**
     * @var \Omeka\Settings\UserSettings
     */
    protected $userSettings;

    /**
     * @var string
     */
    protected $keyStore;

    /**
     * Prepare storage for checked resources to avoid to redo preparation.
     *
     * Use an internal user setting, because data can be big and main settings
     * may be all loaded in memory by Omeka.
     *
     * Normally, a resource is not stored when it has errors.
     *
     * @todo Use a temporary table or a static table.
     */
    protected function initializeCheckStore(): self
    {
        $this->purgeCheckStore();

        $services = $this->getServiceLocator();
        $identity = $services->get('ControllerPluginManager')->get('identity');
        $this->userSettings = $services->get('Omeka\Settings\User');
        $this->userSettings->setTargetId($identity()->getId());

        $base = preg_replace('/[^A-Za-z0-9]/', '_', $this->getLabel());
        $base = $base ? substr(preg_replace('/_+/', '_', $base), 0, 20) . '-' : '';
        $date = (new \DateTime())->format('Ymd-His');
        $random = substr(str_replace(['+', '/', '='], ['', '', ''], base64_encode(random_bytes(128))), 0, 8);
        $this->keyStore = sprintf('_cache_bulkimport_%s%s_%s', $base, $date, $random);

        return $this;
    }

    /**
     * Store a resource to avoid to redo checks and preparation for next step.
     */
    protected function storeCheckedResource(?ArrayObject $resource, ?int $index = null): self
    {
        if (is_null($index)) {
            $index = $this->indexResource;
        }
        if ($resource) {
            $data = $resource->getArrayCopy();
            $data['has_error'] = $data['has_error'] ?? $data['messageStore']->hasErrors();
            // There shall not be any object except message store inside array.
            unset($data['messageStore']);
        } else {
            $data = null;
        }
        $this->userSettings->set($this->keyStore . '_' . $index, $data);
        return $this;
    }

    /**
     * Load a stored resource.
     */
    protected function loadCheckedResource(?int $index = null): ?array
    {
        if (is_null($index)) {
            $index = $this->indexResource;
        }
        return $this->userSettings->get($this->keyStore . '_' . $index) ?: null;
    }

    /**
     * Purge all stored resources, even from previous imports.
     */
    protected function purgeCheckStore(): self
    {
        $connection = $this->getServiceLocator()->get('Omeka\Connection');
        $sql = <<<'SQL'
DELETE FROM `user_setting`
WHERE `id` LIKE "#_cache#_bulkimport#_%" ESCAPE "#"
;
SQL;
        $connection->executeStatement($sql);
        return $this;
    }

    /**
     * Log and store infos on an entry and conversion to one or more resources.
     *
     * Note: an entry may be multiple resources.
     *
     * One log or row by message, and one or more specific rows by sub-resource
     * (media for item, etc.).
     */
    protected function logCheckedResource(?ArrayObject $resource, ?Entry $entry = null): self
    {
        static $severities;

        if (is_null($severities)) {
            $severities = [
                Logger::EMERG => $this->translator->translate('Emergency'), // @translate
                Logger::ALERT => $this->translator->translate('Alert'), // @translate
                Logger::CRIT => $this->translator->translate('Critical'), // @translate
                Logger::ERR => $this->translator->translate('Error'), // @translate
                Logger::WARN => $this->translator->translate('Warning'), // @translate
                Logger::NOTICE => $this->translator->translate('Notice'), // @translate
                Logger::INFO => $this->translator->translate('Info'), // @translate
                Logger::DEBUG => $this->translator->translate('Debug'), // @translate
            ];
        }

        $row['index'] = $this->indexResource;

        $indexMessage = 0;

        if (!$resource && !$entry) {
            $this->logger->info(
                'Index #{index}: Skipped', // @translate
                ['index' => $this->indexResource]
            );
            $row['index_message'] = ++$indexMessage;
            $row['severity'] = $this->translator->translate('Info');
            $row['type'] = $this->translator->translate('General'); // @translate
            $row['message'] = $this->translator->translate('Skipped'); // @translate
            $this->writeRowLog($row);
            return $this;
        }

        if ($entry && $entry->isEmpty()) {
            $this->logger->notice(
                'Index #{index}: Empty source', // @translate
                ['index' => $this->indexResource]
            );
            $row['index_message'] = ++$indexMessage;
            $row['severity'] = $this->translator->translate('Notice');
            $row['type'] = $this->translator->translate('General');
            $row['message'] = $this->translator->translate('Empty source'); // @translate
            $this->writeRowLog($row);
            return $this;
        }

        if (!$resource) {
            $this->logger->err(
                'Index #{index}: Source cannot be converted', // @translate
                ['index' => $this->indexResource]
            );
            $row['index_message'] = ++$indexMessage;
            $row['errors'] = 1;
            $row['severity'] = $this->translator->translate('Error');
            $row['type'] = $this->translator->translate('General');
            $row['message'] = $this->translator->translate('Source cannot be converted'); // @translate
            $this->writeRowLog($row);
            return $this;
        }

        if ($resource['messageStore']->hasErrors()) {
            $this->logger->err(
                'Index #{index}: Error processing data.', // @translate
                ['index' => $this->indexResource]
            );
        } elseif ($resource['messageStore']->hasWarnings()) {
            $this->logger->warn(
                'Index #{index}: Warning about data processed.', // @translate
                ['index' => $this->indexResource]
            );
        } elseif ($resource['messageStore']->hasNotices()) {
            $this->logger->notice(
                'Index #{index}: Notices about data processed.', // @translate
                ['index' => $this->indexResource]
            );
        } elseif ($resource['messageStore']->hasMessages()) {
            $this->logger->info(
                'Index #{index}: Info about data processed.', // @translate
                ['index' => $this->indexResource]
            );
        } else {
            return $this;
        }

        $logging = function ($message): array {
            if (is_string($message)) {
                return [$message, []];
            }
            if ($message instanceof \Log\Stdlib\PsrMessage) {
                return [$message->getMessage(), $message->getContext()];
            }
            return [(string) $message, []];
        };

        $translating = function ($message): string {
            if (is_string($message)) {
                return (string) $this->translator->translate($message);
            }
            if (method_exists($message, 'translate')) {
                return (string) $message->translate();
            }
            if ($message instanceof \Omeka\Stdlib\Message) {
                return (string) vsprintf($this->translator->translate($message->getMessage()), $message->getArgs());
            }
            return (string) $this->translator->translate((string) $message);
        };

        $row['resource_name'] = $resource['resource_name'] ?? '';
        $baseRow = $row;

        $resource['messageStore']->flatMessages();
        foreach ($resource['messageStore']->getMessages() as $severity => $messages) {
            foreach ($messages as $name => $messagess) {
                foreach ($messagess as $message) {
                    [$msg, $context] = $logging($message);
                    $this->logger->log($severity, $msg, $context);
                    $row = $baseRow;
                    if ($severity === Logger::ERR) {
                        $row['errors'] = count($messagess);
                    }
                    $row['index_message'] = ++$indexMessage;
                    $row['severity'] = $severities[$severity];
                    $row['type'] = $name;
                    $row['message'] = $translating($message);
                    $this->writeRowLog($row);
                }
            }
        }

        return $this;
    }

    /**
     * Prepare an output file.
     *
     * @todo Use a temporary file and copy result at the end of the process.
     */
    protected function initializeCheckLog(): self
    {
        if (empty($this->columnsLog)) {
            return $this;
        }

        $importId = (int) $this->job->getArg('bulk_import_id');
        $this->filepathLog = $this->bulk->prepareFile(['name' => $importId . '-log', 'extension' => 'tsv']);
        if (!$this->filepathLog) {
            ++$this->totalErrors;
            $this->job->getJob()->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            return $this;
        }

        if ($this->job->getJob()->getStatus() === \Omeka\Entity\Job::STATUS_ERROR) {
            return $this;
        }

        $this->handleLog = fopen($this->filepathLog, 'w+');
        if (!$this->handleLog) {
            ++$this->totalErrors;
            $this->job->getJob()->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'Unable to open output: {error}.', // @translate
                ['error' => error_get_last()['message']]
            );
            return $this;
        }

        // Prepend the utf-8 bom.
        fwrite($this->handleLog, chr(0xEF) . chr(0xBB) . chr(0xBF));

        if ($this->options['enclosure'] === 0) {
            $this->options['enclosure'] = chr(0);
        }
        if ($this->options['escape'] === 0) {
            $this->options['escape'] = chr(0);
        }

        $translator = $this->getServiceLocator()->get('MvcTranslator');
        $row = [];
        foreach ($this->columnsLog as $column) {
            $row[] = $translator->translate($column);
        }
        $this->writeRowLog($row);

        return $this;
    }

    /**
     * Fill a row (tsv) in the output file.
     */
    protected function writeRowLog(array $row): self
    {
        static $columnKeys;
        static $total = 0;
        static $skipNext = false;

        ++$total;
        if ($total > $this->spreadsheetRowLimit) {
            if ($skipNext) {
                return $this;
            }
            $skipNext = true;
            $this->logger->err(
                'Trying to output more than {max} messages. Next messages are skipped.', // @translate
                ['max' => $this->spreadsheetRowLimit]
            );
            return $this;
        }

        if (is_null($columnKeys)) {
            $columnKeys = array_fill_keys(array_keys($this->columnsLog), null);
        }

        // Order row according to the columns when associative array.
        if (array_values($row) !== $row) {
            $row = array_replace($columnKeys, array_intersect_key($row, $columnKeys));
        }

        // Remove end of lines for each cell.
        $row = array_map(function ($v) {
            $v = str_replace(["\r\n", "\n\r", "\r", "\n"], ['  ', '  ', '  ', '  '], (string) $v);
            if (($pos = strpos($v, 'Stack trace:')) > 0) {
                $v = substr($v, 0, $pos);
            }
            return $v;
        }, $row);

        fputcsv($this->handleLog, $row, $this->options['delimiter'], $this->options['enclosure'], $this->options['escape']);
        return $this;
    }

    /**
     * Finalize the output file. The output file is removed in case of error.
     *
     * @return self
     */
    protected function finalizeCheckLog()
    {
        if (empty($this->columnsLog)) {
            return $this;
        }

        if ($this->job->getJob()->getStatus() === \Omeka\Entity\Job::STATUS_ERROR) {
            if ($this->handleLog) {
                fclose($this->handleLog);
                @unlink($this->filepathLog);
            }
            return $this;
        }

        if (!$this->handleLog) {
            $this->job->getJob()->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            return $this;
        }
        fclose($this->handleLog);
        $this->messageResultFileLog();
        return $this;
    }

    /**
     * Add a  message with the url to the file.
     */
    protected function messageResultFileLog(): self
    {
        $services = $this->getServiceLocator();
        $baseUrl = $services->get('Config')['file_store']['local']['base_uri'] ?: $services->get('Router')->getBaseUrl() . '/files';
        $this->logger->notice(
            'Results are available in this spreadsheet: {url}.', // @translate
            ['url' => $baseUrl . '/bulk_import/' . mb_substr($this->filepathLog, mb_strlen($this->basePath . '/bulk_import/'))]
        );
        return $this;
    }
}
