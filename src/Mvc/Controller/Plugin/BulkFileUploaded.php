<?php declare(strict_types=1);

namespace BulkImport\Mvc\Controller\Plugin;

use Laminas\Log\Logger;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Log\Stdlib\PsrMessage;
use Omeka\Stdlib\ErrorStore;
use ZipArchive;

/**
 * Manage files uploaded manually during an import.
 *
 * @todo Move to downloader or temp file factory or media ingester?
 */
class BulkFileUploaded extends AbstractPlugin
{
    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * @var string
     */
    protected $baseTempDir;

    /**
     * @var string
     */
    protected $baseTempDirPath;

    /**
     * @var \Omeka\Stdlib\ErrorStore
     */
    protected $errorStore;

    /**
     * @var array
     */
    protected $filesUploaded;

    public function __construct(
        Logger $logger,
        string $baseTempDir
    ) {
        $this->logger = $logger;
        $this->baseTempDir = $baseTempDir;
    }

    public function __invoke(): self
    {
        return $this;
    }

    public function setErrorStore(?ErrorStore $errorStore): self
    {
        $this->errorStore = $errorStore;
        return $this;
    }

    /**
     * Move uploaded files in a temp directory available for a background job.
     *
     * It is designed to be run in interface to manage input files.
     *
     * @throws \Omeka\Service\Exception\RuntimeException
     */
    public function prepareFilesUploaded(?array $files): ?array
    {
        if (!$files) {
            $this->filesUploaded = [];
            return [];
        }

        // Fix when no files are submitted in processor.
        if ($files === [['name' => '', 'type' => '', 'tmp_name' => '', 'error' => 4, 'size' => 0]]
            || $files === [['name' => '', 'full_path' => '', 'type' => '', 'tmp_name' =>'', 'error' => 4, 'size' => 0]]
        ) {
            $this->filesUploaded = [];
            return [];
        }

        if (is_array($this->filesUploaded)) {
            return $this->filesUploaded;
        }

        // Create a unique temp dir to avoid to override existing files and to
        // simplify distinction between imports.
        // Here, the job is unknown.
        $this->baseTempDirPath = tempnam($this->baseTempDir, sprintf('omk_bki_%s_', (new \DateTime('now'))->format('Ymd-His')));
        if (!$this->baseTempDirPath) {
            throw new \Omeka\Service\Exception\RuntimeException(
                'Unable to create directory in temp dir.' // @translate
            );
        }
        @unlink($this->baseTempDirPath);
        @mkdir($this->baseTempDirPath);
        @chmod($this->baseTempDirPath, 0775);

        foreach ($files as $key => $file) {
            if ($file['error'] ?? true) {
                throw new \Omeka\Service\Exception\RuntimeException((string) new PsrMessage(
                    'File "{file}" is not manageable.', // @translate
                    ['file' => $file['name']]
                ));
            }
            if (empty($file['tmp_name']) || !file_exists($file['tmp_name'])) {
                throw new \Omeka\Service\Exception\RuntimeException((string) new PsrMessage(
                    'File "{file}" is not manageable.', // @translate
                    ['file' => $file['name']]
                ));
            }
            // Keep original extension for simpler checks.
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filepath = @tempnam($this->baseTempDirPath, substr($file['name'], 0, 16) . '_')
                . (strlen((string) $extension) ? '.' . $extension : '');
            if (!move_uploaded_file($file['tmp_name'], $filepath)) {
                throw new \Omeka\Service\Exception\RuntimeException((string) new PsrMessage(
                    'Unable to move uploaded file "{file}" to "{filepath}".', // @translate
                    ['file' => $file['name'], 'filepath' => $filepath]
                ));
            }
            @chmod($filepath, 0664);
            //unset($files[$key]['tmp_name']);
            $files[$key]['filename'] = $filepath;
        }

        $this->filesUploaded = $files;
        return $files;
    }

    /**
     * When files are uploaded, set all filepaths during background job.
     */
    public function setFilesUploaded(array $files): self
    {
        $this->filesUploaded = $files;
        return $this;
    }

    /**
     * Extract uploaded files in temp directories.
     *
     * Here, the process occurs inside job.
     */
    public function prepareFilesZip(): self
    {
        if (empty($this->filesUploaded)) {
            return $this;
        }

        foreach ($this->filesUploaded as $key => $file) {
            if ($file['error']) {
                $message = new PsrMessage(
                    'File "{file}" is not manageable.', // @translate
                    ['file' => $file['name']]
                );
                $this->logger->err($message);
                ++$this->totalErrors;
                return $this;
            }

            if (!$file['size']
                || $file['type'] !== 'application/zip'
                || empty($file['filename'])
                || !empty($file['dirpath'])
                || !empty($file['entries'])
            ) {
                continue;
            }

            // To get the base temp dir path, that is not stored in params for
            // background process, get it from the first filname.
            if ($this->baseTempDirPath === null) {
                $this->baseTempDirPath = pathinfo($file['filename'], PATHINFO_DIRNAME);
            }

            // Create a temp dir to avoid overriding existing files.
            $tempDirPath = tempnam($this->baseTempDirPath, 'zip_');
            if (!$tempDirPath) {
                $message = new PsrMessage(
                    'Unable to create directory in temp dir.' // @translate
                );
                if ($this->errorStore) {
                    $this->errorStore->addError('uploaded_files', $message);
                }
                $this->logger->err($message);
                return $this;
            }
            @unlink($tempDirPath);
            @mkdir($tempDirPath);
            @chmod($tempDirPath, 0775);

            $zip = new ZipArchive();
            if ($zip->open($file['filename']) !== true) {
                $message = new PsrMessage(
                    'Unable to extract zip file "{file}".', // @translate
                    ['file' => $file['name']]
                );
                if ($this->errorStore) {
                    $this->errorStore->addError('uploaded_files', $message);
                }
                $this->logger->err($message);
                $zip->close();
                return $this;
            }

            $check = $zip->extractTo($tempDirPath);

            if (!$check) {
                $message = new PsrMessage(
                    'Unable to extract all files from zip file "{file}".', // @translate
                    ['file' => $file['name']]
                );
                if ($this->errorStore) {
                    $this->errorStore->addError('uploaded_files', $message);
                }
                $this->logger->err($message);
                return $this;
            }

            // Store the list of files and secure them.
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                if (mb_substr($filename, -1) === '/' || mb_substr($filename, -1) === '\\') {
                    @chmod($tempDirPath . '/' . $filename, 0775);
                } else {
                    $entries[] = $filename;
                    @chmod($tempDirPath . '/' . $filename, 0664);
                }
            }

            $zip->close();

            $this->filesUploaded[$key]['dirpath'] = $tempDirPath;
            $this->filesUploaded[$key]['entries'] = $entries;
        }

        return $this;
    }

    /**
     * Get files uploaded from the form via filepath from the metadata.
     *
     * When multiple files have the same name, the first one is used: individual
     * files first, then each zipped files in the order they were loaded.
     */
    public function getFileUploaded($sourceFilepath): ?string
    {
        if (!$this->filesUploaded) {
            return null;
        }
        foreach ($this->filesUploaded as $file) {
            if ($sourceFilepath === $file['name']) {
                return $file['filename'] ?? null;
            }
            if (!empty($file['dirpath'])
                && !empty($file['entries'])
                && ($pos = array_search($sourceFilepath, $file['entries'], true)) !== false
            ) {
                return $file['dirpath'] . '/' . $file['entries'][$pos];
            }
        }
        return null;
    }
}
