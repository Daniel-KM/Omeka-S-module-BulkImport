<?php declare(strict_types=1);

namespace BulkImport\Processor;

use ArrayObject;
use BulkImport\Entry\Entry;
use Log\Stdlib\PsrMessage;
use Omeka\Stdlib\ErrorStore;

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
     * Default options for output (tsv).
     *
     * @var array
     */
    protected $options = [
        'delimiter' => "\t",
        'enclosure' => 0,
        'escape' => 0,
    ];

    protected $columns = [
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
    protected $filepathCheck;

    /**
     * Log and store infos on an entry and conversion to one or more resources.
     *
     * One log or row by message, and one or more specific rows by sub-resource
     * (media for item).
     */
    protected function logCheckedResource(?ArrayObject $resource, ?Entry $entry = null): \BulkImport\Processor\Processor
    {
        $row['index'] = $this->indexResource;

        $indexMessage = 0;

        if (!$resource && !$entry) {
            $this->logger->log(\Laminas\Log\Logger::INFO,
                'Index #{index}: Skipped', // @translate
                ['index' => $this->indexResource]
            );
            $row['index_message'] = ++$indexMessage;
            $row['severity'] = $this->translator->translate('Info');
            $row['type'] = $this->translator->translate('General'); // @translate
            $row['message'] = $this->translator->translate('Skipped'); // @translate
            $this->writeRow($row);
            return $this;
        }

        if ($entry && $entry->isEmpty()) {
            $this->logger->log(\Laminas\Log\Logger::NOTICE,
                'Index #{index}: Empty source', // @translate
                ['index' => $this->indexResource]
            );
            $row['index_message'] = ++$indexMessage;
            $row['severity'] = $this->translator->translate('Notice');
            $row['type'] = $this->translator->translate('General');
            $row['message'] = $this->translator->translate('Empty source'); // @translate
            $this->writeRow($row);
            return $this;
        }

        if (!$resource) {
            $this->logger->log(\Laminas\Log\Logger::ERR,
                'Index #{index}: Source cannot be converted', // @translate
                ['index' => $this->indexResource]
            );
            $row['index_message'] = ++$indexMessage;
            $row['errors'] = 1;
            $row['severity'] = $this->translator->translate('Error');
            $row['type'] = $this->translator->translate('General');
            $row['message'] = $this->translator->translate('Source cannot be converted'); // @translate
            $this->writeRow($row);
            return $this;
        }

        if ($resource['errorStore']->hasErrors()) {
            $this->logger->log(\Laminas\Log\Logger::ERR,
                'Index #{index}: Error processing data.', // @translate
                ['index' => $this->indexResource]
            );
        } elseif ($resource['warningStore']->hasErrors()) {
            $this->logger->log(\Laminas\Log\Logger::WARN,
                'Index #{index}: Warning about data processed.', // @translate
                ['index' => $this->indexResource]
            );
        } elseif ($resource['infoStore']->hasErrors()) {
            $this->logger->log(\Laminas\Log\Logger::INFO,
                'Index #{index}: Info about data processed.', // @translate
                ['index' => $this->indexResource]
            );
        } else {
            return $this;
        }

        $logging = function($message): array {
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
                return $this->translator->translate($message);
            }
            if (method_exists($message, 'translate')) {
                return $message->translate();
            }
            if ($message instanceof \Omeka\Stdlib\Message) {
                return sprintf($this->translator->translate($message->getMessage()), $message->getArgs());
            }
            return $this->translator->translate($message);
        };

        $row['resource_name'] = $resource['resource_name'] ?? '';
        $baseRow = $row;

        $messages = $this->flatMessages($resource['errorStore']->getErrors());
        foreach ($messages as $name => $messagess) {
            foreach ($messagess as $message) {
                [$msg, $context] = $logging($message);
                $this->logger->log(\Laminas\Log\Logger::ERR, $msg, $context);
                $row = $baseRow;
                $row['errors'] = count($messagess);
                $row['index_message'] = ++$indexMessage;
                $row['severity'] = $this->translator->translate('Error');
                $row['type'] = $name;
                $row['message'] = $translating($message);
                $this->writeRow($row);
            }
        }

        $messages = $this->flatMessages($resource['warningStore']->getErrors());
        foreach ($messages as $name => $messagess) {
            foreach ($messagess as $message) {
                [$msg, $context] = $logging($message);
                $this->logger->log(\Laminas\Log\Logger::WARN, $msg, $context);
                $row = $baseRow;
                $row['index_message'] = ++$indexMessage;
                $row['severity'] = $this->translator->translate('Warning');
                $row['type'] = $name;
                $row['message'] = $translating($message);
                $this->writeRow($row);
            }
        }

        $messages = $this->flatMessages($resource['infoStore']->getErrors());
        foreach ($messages as $messagess) {
            foreach ($messagess as $message) {
                [$msg, $context] = $logging($message);
                $this->logger->log(\Laminas\Log\Logger::INFO, $msg, $context);
                $row = $baseRow;
                $row['index_message'] = ++$indexMessage;
                $row['severity'] = $this->translator->translate('Info');
                $row['type'] = $name;
                $row['message'] = $translating($message);
                $this->writeRow($row);
            }
        }

        return $this;
    }

    /**
     * Error store is recursive, so merge all sub-levels into a flat array.
     *
     * Normally useless, but some message may be added indirectly.
     *
     * @see \Omeka\Stdlib\ErrorStore::mergeErrors()
     * @todo Move this into a new class wrapping or replacing ErrorStore.
     */
    protected function flatMessages($messages): array
    {
        // The keys of the first level should be kept, but not the sub-ones.
        $flatArray = [];
        foreach ($messages as $key => $message) {
            if (is_array($message)) {
                array_walk_recursive($message, function($data) use(&$flatArray, $key) {
                    $flatArray[$key][] = $data;
                });
            } else {
                $flatArray[$key][] = $message;
            }
        }
        return $flatArray;
    }

    /**
     * Prepare an output file.
     *
     * @todo Use a temporary file and copy result at the end of the process.
     */
    protected function initializeCheckOutput(): \BulkImport\Processor\Processor
    {
        if (empty($this->columns)) {
            return $this;
        }

        $this->prepareFilename();
        if ($this->job->getJob()->getStatus() === \Omeka\Entity\Job::STATUS_ERROR) {
            return $this;
        }

        $this->handle = fopen($this->filepathCheck, 'w+');
        if (!$this->handle) {
            ++$this->totalErrors;
            $this->job->getJob()->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'Unable to open output: {error}.', // @translate
                ['error' => error_get_last()['message']]
            );
            return $this;
        }

        // Prepend the utf-8 bom.
        fwrite($this->handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

        if ($this->options['enclosure'] === 0) {
            $this->options['enclosure'] = chr(0);
        }
        if ($this->options['escape'] === 0) {
            $this->options['escape'] = chr(0);
        }

        $translator = $this->getServiceLocator()->get('MvcTranslator');
        $row = [];
        foreach ($this->columns as $column) {
            $row[] = $translator->translate($column);
        }
        $this->writeRow($row);

        return $this;
    }

    /**
     * Fill a row (tsv) in the output file.
     */
    protected function writeRow(array $row): \BulkImport\Processor\Processor
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
            $columnKeys = array_fill_keys(array_keys($this->columns), null);
        }

        // Order row according to the columns when associative array.
        if (array_values($row) !== $row) {
            $row = array_replace($columnKeys, array_intersect_key($row, $columnKeys));
        }

        fputcsv($this->handle, $row, $this->options['delimiter'], $this->options['enclosure'], $this->options['escape']);
        return $this;
    }

    /**
     * Finalize the output file. The output file is removed in case of error.
     *
     * @return self
     */
    protected function finalizeCheckOutput()
    {
        if (empty($this->columns)) {
            return $this;
        }

        if ($this->job->getJob()->getStatus() === \Omeka\Entity\Job::STATUS_ERROR) {
            if ($this->handle) {
                fclose($this->handle);
                @unlink($this->filepathCheck);
            }
            return $this;
        }

        if (!$this->handle) {
            $this->job->getJob()->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            return $this;
        }
        fclose($this->handle);
        $this->messageResultFile();
        return $this;
    }

    /**
     * Add a  message with the url to the file.
     */
    protected function messageResultFile(): \BulkImport\Processor\Processor
    {
        $baseUrl = $this->config['file_store']['local']['base_uri'] ?: $this->job->getJobArg('base_path') . '/files';
        $this->logger->notice(
            'Results are available in this spreadsheet: {url}.', // @translate
            ['url' => $baseUrl . '/bulk_import/' . mb_substr($this->filepathCheck, mb_strlen($this->basePath . '/bulk_import/'))]
        );
        return $this;
    }

    /**
     * Create the unique file name compatible on various os.
     *
     * Note: the destination dir is created during install.
     */
    protected function prepareFilename(): \BulkImport\Processor\Processor
    {
        $destinationDir = $this->basePath . '/bulk_import';

        $base = preg_replace('/[^A-Za-z0-9]/', '_', $this->getLabel());
        $base = $base ? preg_replace('/_+/', '_', $base) . '-' : '';
        $date = (new \DateTime())->format('Ymd-His');
        $extension = 'tsv';

        // Avoid issue on very big base.
        $i = 0;
        do {
            $filename = sprintf(
                '%s%s%s.%s',
                $base,
                $date,
                $i ? '-' . $i : '',
                $extension
            );

            $filePath = $destinationDir . '/' . $filename;
            if (!file_exists($filePath)) {
                try {
                    $result = touch($filePath);
                } catch (\Exception $e) {
                    ++$this->totalErrors;
                    $this->job->getJob()->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
                    $this->logger->err(
                        'Error when saving "{filename}" (temp file: "{tempfile}"): {exception}', // @translate
                        ['filename' => $filename, 'tempfile' => $filePath, 'exception' => $e]
                    );
                    return $this;
                }

                if (!$result) {
                    ++$this->totalErrors;
                    $this->job->getJob()->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
                    $this->logger->err(
                        'Error when saving "{filename}" (temp file: "{tempfile}"): {error}', // @translate
                        ['filename' => $filename, 'tempfile' => $filePath, 'error' => error_get_last()['message']]
                    );
                    return $this;
                }

                break;
            }
        } while (++$i);

        $this->filepathCheck = $filePath;
        return $this;
    }
}
