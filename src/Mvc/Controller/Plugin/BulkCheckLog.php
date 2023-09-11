<?php declare(strict_types=1);

namespace BulkImport\Mvc\Controller\Plugin;

use BulkImport\Entry\Entry;
use Doctrine\DBAL\Connection;
use Laminas\Log\Logger;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Laminas\Mvc\I18n\Translator;
use Log\Stdlib\PsrMessage;
use Omeka\Settings\UserSettings;

class BulkCheckLog extends AbstractPlugin
{
    use BulkCheckDiffTrait;

    /**
     * @var \BulkImport\Mvc\Controller\Plugin\Bulk
     */
    protected $bulk;

    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * @var \Laminas\Mvc\I18n\Translator
     */
    protected $translator;

    /**
     * @var \Omeka\Settings\UserSettings
     */
    protected $userSettings;

    /**
     * @var string
     */
    protected $basePath;

    /**
     * @var string
     */
    protected $baseUrl;

    /**
     * Max number of rows to output in spreadsheet.
     * @var int
     */
    protected $spreadsheetRowLimit = 1000000;

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
    protected $baseName;

    /**
     * @var resource
     */
    protected $handleLog;

    /**
     * @var string
     */
    protected $filepathLog;

    /**
     * @var string
     */
    protected $keyStore;

    /**
     * @var string
     */
    protected $nameFile;

    public function __construct(
        Bulk $bulk,
        Connection $connection,
        Logger $logger,
        Translator $translator,
        UserSettings $userSettings,
        string $basePath,
        string $baseUrl
    ) {
        $this->bulk = $bulk;
        $this->connection = $connection;
        $this->logger = $logger;
        $this->translator = $translator;
        $this->userSettings = $userSettings;
        $this->basePath = $basePath;
        $this->baseUrl = $baseUrl;

        $this->purgeCheckStore();
    }

    /**
     * Manage checks of source.
     *
     * @todo Create a message store with severity.
     * @todo Add data through a specific logger adapter. Note processing message are useless here (starting, ending…). Or use a separate logger.
     *
     * Adapted from BulkCheck.
     * @see \EasyAdmin\Job\AbstractCheck
     */
    public function __invoke(): self
    {
        return $this;
    }

    public function setBaseName(string $baseName): self
    {
        $this->baseName = $baseName;
        return $this;
    }

    public function setNameFile(string $nameFile): self
    {
        $this->nameFile = $nameFile;
        return $this;
    }

    public function getFilepathLog(): string
    {
        return (string) $this->filepathLog;
    }

    /**
     * Prepare storage for checked resources to avoid to redo preparation.
     *
     * Use an internal user setting, because data can be big and main settings
     * may be all loaded in memory by Omeka.
     *
     * Normally, a resource is not stored when it has errors.
     *
     * @todo Use a temporary table or a static table or a cache file or a package like Laminas cache.
     */
    public function initializeCheckStore(): self
    {
        $this->purgeCheckStore();

        $base = preg_replace('/[^A-Za-z0-9]/', '_', $this->baseName);
        $base = $base ? substr(preg_replace('/_+/', '_', $base), 0, 20) . '-' : '';
        $date = (new \DateTime())->format('Ymd-His');
        $random = substr(str_replace(['+', '/', '='], ['', '', ''], base64_encode(random_bytes(128))), 0, 6);
        $this->keyStore = sprintf('_cache_bulkimport_%s%s_%s', $base, $date, $random);

        return $this;
    }

    /**
     * Purge all stored resources, even from previous imports.
     *
     * @fixme Check if a job is running before purging (but batch upload is limited to admins).
     */
    public function purgeCheckStore(): self
    {
        $sql = <<<'SQL'
DELETE FROM `user_setting`
WHERE `id` LIKE "#_cache#_bulkimport#_%" ESCAPE "#"
;
SQL;
        $this->connection->executeStatement($sql);
        return $this;
    }

    /**
     * Store a resource to avoid to redo checks and preparation for next step.
     *
     * The storage is one-based.
     */
    public function storeCheckedResource(int $index, ?array $data): self
    {
        if (is_array($data)) {
            $data['has_error'] = $data['has_error'] ?? (isset($data['messageStore']) ? $data['messageStore']->hasErrors() : null);
            // There shall not be any object except message store insi$e array.
            unset($data['messageStore']);
        }
        $this->userSettings->set($this->keyStore . ':' . str_pad((string) $index, 6, '0', STR_PAD_LEFT), $data);
        return $this;
    }

    /**
     * Load a stored resource.
     *
     * The storage is one-based.
     */
    public function loadCheckedResource(int $index): ?array
    {
        return $this->userSettings->get($this->keyStore . ':' . str_pad((string) $index, 6, '0', STR_PAD_LEFT)) ?: null;
    }

    /**
     * Get the total of checked resources.
     */
    public function getTotalCheckedResources(): int
    {
        $escapeSqlLike = function ($string) {
            return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $string);
        };
        return (int) $this->connection
            ->executeQuery('SELECT COUNT(`id`) FROM `user_setting` WHERE `id` LIKE :key_store', [
                'key_store' => $escapeSqlLike($this->keyStore) . '%',
            ])
            ->fetchOne();
    }

    /**
     * Log and store infos on an entry and conversion to one or more resources.
     *
     * Note: an entry may be multiple resources.
     *
     * One log or row by message, and one or more specific rows by sub-resource
     * (media for item, etc.).
     *
     * The storage is one-based.
     */
    public function logCheckedResource(int $index, ?array $resource, ?Entry $entry = null): self
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

        $row['index'] = $index;

        $indexMessage = 0;

        if (!$resource && !$entry) {
            $this->logger->info(
                'Index #{index}: Skipped', // @translate
                ['index' => $index]
            );
            $row['index_message'] = ++$indexMessage;
            $row['severity'] = $severities[Logger::INFO];
            $row['type'] = $this->translator->translate('General'); // @translate
            $row['message'] = $this->translator->translate('Skipped'); // @translate
            $this->writeRowLog($row);
            return $this;
        }

        if ($entry && $entry->isEmpty()) {
            $this->logger->notice(
                'Index #{index}: Empty source', // @translate
                ['index' => $index]
            );
            $row['index_message'] = ++$indexMessage;
            $row['severity'] = $severities[Logger::NOTICE];
            $row['type'] = $this->translator->translate('General');
            $row['message'] = $this->translator->translate('Empty source'); // @translate
            $this->writeRowLog($row);
            return $this;
        }

        if (!$resource) {
            $this->logger->err(
                'Index #{index}: Source cannot be converted', // @translate
                ['index' => $index]
            );
            $row['index_message'] = ++$indexMessage;
            $row['errors'] = 1;
            $row['severity'] = $severities[Logger::ERR];
            $row['type'] = $this->translator->translate('General');
            $row['message'] = $this->translator->translate('Source cannot be converted'); // @translate
            $this->writeRowLog($row);
            return $this;
        }

        if ($resource['messageStore']->hasErrors()) {
            $this->logger->err(
                'Index #{index}: Error processing data.', // @translate
                ['index' => $index]
            );
        } elseif ($resource['messageStore']->hasWarnings()) {
            $this->logger->warn(
                'Index #{index}: Warning about data processed.', // @translate
                ['index' => $index]
            );
        } elseif ($resource['messageStore']->hasNotices()) {
            $this->logger->notice(
                'Index #{index}: Notices about data processed.', // @translate
                ['index' => $index]
            );
        } elseif ($resource['messageStore']->hasMessages()) {
            $this->logger->info(
                'Index #{index}: Info about data processed.', // @translate
                ['index' => $index]
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
     *
     * @return array Result status.
     */
    public function initializeCheckLog(): array
    {
        if (empty($this->columnsLog)) {
            return [
                'status' => 'success',
            ];
        }

        $this->filepathLog = $this->prepareFile(['name' => $this->nameFile . '-log', 'extension' => 'tsv']);
        if (!$this->filepathLog) {
            return [
                'status' => 'error',
            ];
        }

        $this->handleLog = fopen($this->filepathLog, 'w+');
        if (!$this->handleLog) {
            $message = new PsrMessage(
                'Unable to open output: {error}.', // @translate
                ['error' => error_get_last()['message']]
            );
            $this->logger->err($message->getMessage(), $message->getContext());
            return [
                'status' => 'error',
                'message' => $message,
            ];
        }

        // Prepend the utf-8 bom.
        fwrite($this->handleLog, chr(0xEF) . chr(0xBB) . chr(0xBF));

        if ($this->options['enclosure'] === 0) {
            $this->options['enclosure'] = chr(0);
        }
        if ($this->options['escape'] === 0) {
            $this->options['escape'] = chr(0);
        }

        $row = [];
        foreach ($this->columnsLog as $column) {
            $row[] = $this->translator->translate($column);
        }
        $this->writeRowLog($row);

        return [
            'status' => 'success',
        ];
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
     * @return array Result status.
     */
    public function finalizeCheckLog(): array
    {
        if (empty($this->columnsLog)) {
            return [
                'status' => 'success',
            ];
        }

        if (!$this->handleLog) {
            @unlink($this->filepathLog);
            return [
                'status' => 'error',
            ];
        }

        fclose($this->handleLog);
        $this->messageResultFile();
        return [
            'status' => 'success',
        ];
    }

    /**
     * Add a  message with the url to the file.
     */
    protected function messageResultFile(): self
    {
        $this->logger->notice(
            'Results are available in this spreadsheet: {url}.', // @translate
            ['url' => $this->baseUrl . '/bulk_import/' . mb_substr($this->filepathLog, mb_strlen($this->basePath . '/bulk_import/'))]
        );
        return $this;
    }
}
