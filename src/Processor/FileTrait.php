<?php declare(strict_types=1);

namespace BulkImport\Processor;

use finfo;
use Log\Stdlib\PsrMessage;
use Omeka\Stdlib\ErrorStore;
use SplFileInfo;
use ZipArchive;

/**
 * @todo Factorize with AbstractFileReader.
 * @todo Factorize with HttpClientTrait.
 * @todo Create a generic controller plugin or use via bulk.
 * @todo Or move to downloader or temp file factory or media ingester.
 */
trait FileTrait
{
    /**
     * @var bool
     */
    protected $isInitFileTrait = false;

    /**
     * @var \Omeka\File\TempFileFactory
     */
    protected $tempFileFactory;

    /**
     * @var \Omeka\File\Store\StoreInterface
     */
    protected $store;

    /**
     * @var bool
     */
    protected $disableFileValidation = false;

    /**
     * @var array
     */
    protected $allowedMediaTypes = [];

    /**
     * @var array
     */
    protected $allowedExtensions = [];

    /**
     * @var bool
     */
    protected $allowEmptyFiles = false;

    /**
     * @var bool
     */
    protected $isFileSideloadActive = false;

    /**
     * @var string
     */
    protected $sideloadPath = null;

    /**
     * @var bool
     */
    protected $sideloadDeleteFile = false;

    /**
     * @var resource
     */
    protected $streamContextHeadersOnly;

    /**
     * @var bool|int
     */
    protected $asAssociative = true;

    /**
     * @var bool
     */
    protected $checkAssetMediaType = false;

    /**
     * @var string
     */
    protected $baseTempDir;

    /**
     * @var string
     */
    protected $baseTempDirPath;

    /**
     * @var array
     */
    protected $filesUploaded;

    protected function initFileTrait(): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        // Unzip in Omeka temp directory.
        $config = $services->get('Config');
        $this->baseTempDir = $config['temp_dir'] ?: sys_get_temp_dir();
        if (!$this->baseTempDir) {
            throw new \Omeka\Service\Exception\RuntimeException(
                'The "temp_dir" is not configured' // @translate
            );
        }

        $this->tempFileFactory = $services->get('Omeka\File\TempFileFactory');
        $this->store = $services->get('Omeka\File\Store');

        $this->isFileSideloadActive = $this->bulk->isFileSideloadActive();
        $this->disableFileValidation = (bool) $settings->get('disable_file_validation');
        $this->allowedMediaTypes = $settings->get('media_type_whitelist') ?: [];
        $this->allowedExtensions = $settings->get('extension_whitelist') ?: [];
        $this->allowEmptyFiles = (bool) $settings->get('allow_empty_files');
        $this->sideloadPath = (string) $settings->get('file_sideload_directory');
        $this->sideloadDeleteFile = $settings->get('file_sideload_delete_file') === 'yes';
        $this->streamContextHeadersOnly = stream_context_create(['http' => ['method' => 'HEAD']]);

        // Required for strict types.
        if (PHP_MAJOR_VERSION < 8) {
            $this->asAssociative = 1;
        }

        $this->isInitFileTrait = true;
    }

    /**
     * Move uploaded files in a temp directory available for a background job.
     *
     * Here, the process is not inside the job, but during the configuration.
     *
     * @todo Factorize with \BulkImport\Reader\FileAndUrlTrait::getUploadedFile()
     */
    protected function prepareFilesUploaded(?array $files): ?array
    {
        if (!$files) {
            $this->filesUploaded = [];
            return [];
        }

        if (is_array($this->filesUploaded)) {
            return $this->filesUploaded;
        }

        if (!$this->isInitFileTrait) {
            $this->initFileTrait();
        }

        // Create a unique temp dir to avoid to override existing files and to
        // simplify distinction between imports.
        // Here, the job is unknown.
        $this->baseTempDirPath = tempnam($this->baseTempDir, sprintf('omk_bki_%s_', (new \DateTime('now'))->format('Ymd-H:i:s')));
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
            $filepath = @tempnam($this->baseTempDirPath, substr($file['name'], 0, 16) . '_');
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
     * Extract uploaded files in temp directories.
     *
     * Here, the process occurs inside job.
     */
    protected function prepareFilesZip(): self
    {
        if (empty($this->filesUploaded)) {
            return [];
        }

        if (!$this->isInitFileTrait) {
            $this->initFileTrait();
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
                $this->logger->err($message);
                ++$this->totalErrors;
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
                $this->logger->err($message);
                $zip->close();
                ++$this->totalErrors;
                return $this;
            }

            $check = $zip->extractTo($tempDirPath);

            if (!$check) {
                $message = new PsrMessage(
                    'Unable to extract all files from zip file "{file}".', // @translate
                    ['file' => $file['name']]
                );
                $this->logger->err($message);
                ++$this->totalErrors;
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

    protected function setFilesUploaded(array $files): self
    {
        $this->filesUploaded = $files;
        return $this;
    }

    /**
     * Check if a file or url exists and is readable.
     */
    protected function checkFileOrUrl($fileOrUrl, ?ErrorStore $messageStore = null): bool
    {
        return $this->bulk->isUrl($fileOrUrl)
            ? $this->checkUrl($fileOrUrl)
            : $this->checkFile($fileOrUrl);
    }

    /**
     * Check if a file exists and is readable.
     *
     * @todo Add an option to check media types for assets.
     */
    protected function checkFile($filepath, ?ErrorStore $messageStore = null): bool
    {
        if (!$this->isInitFileTrait) {
            $this->initFileTrait();
        }

        $filepath = (string) $filepath;

        // Check if this is a directly uploaded file. They are already checked.
        $uploadedFile = $this->getFileUploaded($filepath);
        if ($uploadedFile) {
            $realPath = $uploadedFile;
        } elseif (!$this->isFileSideloadActive) {
            if ($messageStore) {
                $messageStore->addError('file', new PsrMessage(
                    'Cannot sideload file: module FileSideload inactive or not installed.' // @translate
                ));
            }
            return false;
        } else {
            $realPath = $this->verifyFile($filepath, $messageStore);
            if (is_null($realPath)) {
                return false;
            }
        }

        if (!filesize($realPath) && !$this->allowEmptyFiles) {
            if ($messageStore) {
                $messageStore->addError('file', new PsrMessage(
                    'Cannot sideload file "{filepath}": File empty.', // @translate
                    ['filepath' => $filepath]
                ));
            }
            return false;
        }

        // TODO Check max size and remaining server size.

        // In all cases, the media type is checked for aliases.
        // @see \Omeka\File\TempFile::getMediaType().
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mediaType = $finfo->file($realPath);
        $mediaType = \Omeka\File\TempFile::MEDIA_TYPE_ALIASES[$mediaType] ?? $mediaType;
        $extension = pathinfo($realPath, PATHINFO_EXTENSION);

        // Here, use the source filepath because this is only for message.
        return $this->checkMediaTypeAndExtension($filepath, $mediaType, $extension, $messageStore);
    }

    /**
     * Check if a directory exists, is readable and has files.
     */
    protected function checkDirectory($dirpath, ?ErrorStore $messageStore = null): bool
    {
        if (!$this->isInitFileTrait) {
            $this->initFileTrait();
        }

        $filepath = (string) $dirpath;

        $realPath = $this->verifyFile($filepath, $messageStore, true);
        if (is_null($realPath)) {
            return false;
        }

        // Check each file.
        $listFiles = $this->listFiles($realPath, false);
        if (!count($listFiles)) {
            if ($messageStore) {
                $messageStore->addError('file', new PsrMessage(
                    'Ingest directory "{filepath}" is empty.',  // @translate
                    ['filepath' => $filepath]
                ));
            }
            return false;
        }

        $result = true;
        foreach ($listFiles as $file) {
            if (!$this->checkFile($file, $messageStore)) {
                $result = false;
            }
        }

        return $result;
    }

    /**
     * Check if a url exists and is readable via a ping (get headers only).
     *
     * @todo Move to \Omeka\File\Downloader
     */
    protected function checkUrl($url, ?ErrorStore $messageStore = null): bool
    {
        if (!$this->isInitFileTrait) {
            $this->initFileTrait();
        }

        $url = (string) $url;
        if (!strlen($url)) {
            if ($messageStore) {
                $messageStore->addError('url', new PsrMessage(
                    'Cannot fetch url: empty url.' // @translate
                ));
            }
            return false;
        }

        if (substr($url, 0, 6) !== 'https:'
            && substr($url, 0, 5) !== 'http:'
            && substr($url, 0, 4) !== 'ftp:'
            && substr($url, 0, 5) !== 'sftp:'
        ) {
            if ($messageStore) {
                $messageStore->addError('url', new PsrMessage(
                    'Cannot fetch url "{url}": invalid protocol.', // @translate
                    ['url' => $url]
                ));
            }
            return false;
        }

        $contentDispositionFilename = function ($string): ?string {
            $matches = [];
            preg_match('/filename[^;\n]*=\s*(UTF-\d[\'"]*)?(?<filename>([\'"]).*?[.]$\2|[^;\n]*)?/', (string) $string, $matches);
            return isset($matches['filename']) ? trim($matches['filename'], "\"' \n\r\t\v\0") : null;
        };

        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            if (!$curl) {
                if ($messageStore) {
                    $messageStore->addError('url', new PsrMessage(
                        'Cannot fetch url "{url}": url cannot be fetched.', // @translate
                        ['url' => $url]
                    ));
                }
                return false;
            }
            curl_setopt_array($curl, [
                CURLOPT_HEADER => true,
                CURLOPT_NOBODY => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_USERAGENT => 'curl/' . curl_version()['version'],
            ]);
            $result = curl_exec($curl);
            if ($result === false) {
                if ($messageStore) {
                    $messageStore->addError('url', new PsrMessage(
                        'Cannot fetch url "{url}": url doesn’t exist.', // @translate
                        ['url' => $url]
                    ));
                }
                curl_close($curl);
                return false;
            }
            $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $mediaType = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
            $contentLength = (int) curl_getinfo($curl, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
            $filename = $contentDispositionFilename($result);
            curl_close($curl);
        } else {
            $headers = get_headers($url, $this->asAssociative, $this->streamContextHeadersOnly);
            if (!$headers) {
                if ($messageStore) {
                    $messageStore->addError('url', new PsrMessage(
                        'Cannot fetch url "{url}": url does not exist.', // @translate
                        ['url' => $url]
                    ));
                }
                return false;
            }
            $httpCode = (int) substr($headers[0], 9, 3);
            $mediaType = $headers['Content-Type'] ?? null;
            $contentLength = isset($headers['Content-Length']) ? (int) $headers['Content-Length'] : -1;
            if (!empty($headers['Content-Disposition'])) {
                $filename = $contentDispositionFilename($headers['Content-Disposition']);
            }
        }

        if ($httpCode !== 200) {
            if ($messageStore) {
                $messageStore->addError('url', new PsrMessage(
                    'Cannot fetch url "{url}": server returned http code "{http_code}".', // @translate
                    ['url' => $url, 'http_code' => $httpCode]
                ));
            }
            return false;
        }

        if (!$contentLength) {
            if ($messageStore) {
                $messageStore->addError('file', new PsrMessage(
                    'Cannot fetch url "{url}": Content length empty.', // @translate
                    ['url' => $url]
                ));
            }
            return false;
        }

        if (!$mediaType) {
            if ($messageStore && method_exists($messageStore, 'addWarning')) {
                $messageStore->addWarning('url', new PsrMessage(
                    'Cannot fetch url "{url}": cannot pre-check media-type.', // @translate
                    ['url' => $url]
                ));
            }
        } else {
            // In all cases, the media type is checked for aliases.
            // @see \Omeka\File\TempFile::getMediaType().
            $mediaType = \Omeka\File\TempFile::MEDIA_TYPE_ALIASES[$mediaType] ?? $mediaType;
        }

        $extension = empty($filename) ? null : pathinfo($filename, PATHINFO_EXTENSION);

        // TODO Check actual media-type of an url.
        // @link https://stackoverflow.com/questions/676949/best-way-to-determine-if-a-url-is-an-image-in-php

        return $this->checkMediaTypeAndExtension($url, $mediaType, $extension, $messageStore);
    }

    /**
     * Get all files available to sideload from a directory inside the main dir.
     *
     * @return array List of filepaths relative to the main directory.
     */
    protected function listFiles(string $directory, bool $recursive = false): array
    {
        $dir = new \SplFileInfo($directory);
        if (!$dir->isDir() || !$dir->isReadable() || !$dir->isExecutable()) {
            return [];
        }

        // Check if the dir is inside main directory: don't import root files.
        $directory = $this->verifyFile($directory, null, true);
        if (is_null($directory)) {
            return [];
        }

        $listFiles = [];

        // To simplify sort.
        $listRootFiles = [];

        $lengthDir = strlen($this->sideloadPath) + 1;
        if ($recursive) {
            $dir = new \RecursiveDirectoryIterator($directory);
            // Prevent UnexpectedValueException "Permission denied" by excluding
            // directories that are not executable or readable.
            $dir = new \RecursiveCallbackFilterIterator($dir, function ($current, $key, $iterator) {
                if ($iterator->isDir() && (!$iterator->isExecutable() || !$iterator->isReadable())) {
                    return false;
                }
                return true;
            });
            $iterator = new \RecursiveIteratorIterator($dir);
            /** @var \SplFileInfo $file */
            foreach ($iterator as $filepath => $file) {
                if ($this->verifyFile($filepath)) {
                    // For security, don't display the full path to the user.
                    $relativePath = substr($filepath, $lengthDir);
                    // Use keys for quicker process on big directories.
                    $listFiles[$relativePath] = null;
                    if (pathinfo($filepath, PATHINFO_DIRNAME) === $directory) {
                        $listRootFiles[$relativePath] = null;
                    }
                }
            }
        } else {
            $iterator = new \DirectoryIterator($directory);
            /** @var \DirectoryIterator $file */
            foreach ($iterator as $filepath => $file) {
                $filepath = $this->verifyFile($file->getRealPath());
                if (!is_null($filepath)) {
                    // For security, don't display the full path to the user.
                    $relativePath = substr($filepath, $lengthDir);
                    // Use keys for quicker process on big directories.
                    $listFiles[$relativePath] = null;
                }
            }
        }

        // Don't mix directories and files. List root files, then sub-directories.
        $listFiles = array_keys($listFiles);
        natcasesort($listFiles);
        $listRootFiles = array_keys($listRootFiles);
        natcasesort($listRootFiles);
        return array_values(array_unique(array_merge($listRootFiles, $listFiles)));
    }

    /**
     * Verify the passed filepath.
     *
     * Working off the "real" base directory and "real" filepath: both must
     * exist and have sufficient permissions; the filepath must begin with the
     * base directory path to avoid problems with symlinks; the base directory
     * must be server-writable to delete the file; and the file must be a
     * readable regular file.
     *
     * @return string|null The real file path or null if the file is invalid
     *
     * @see \FileSideload\Media\Ingester\Sideload::verifyFile()
     */
    protected function verifyFile($filepath, ?ErrorStore $messageStore = null, $isDir = false): ?string
    {
        if (!$this->sideloadPath || strlen($this->sideloadPath) <= 1) {
            if ($messageStore) {
                $messageStore->addError('file', new PsrMessage(
                    'Cannot sideload file: configuration of module File Sideload not ready.' // @translate
                ));
            }
            return null;
        }

        $filepath = (string) $filepath;
        if (!strlen($filepath)) {
            if ($messageStore) {
                $messageStore->addError('file', new PsrMessage(
                    'Cannot sideload file: no file path.' // @translate
                ));
            }
            return null;
        }

        $isAbsolutePathInsideDir = strpos($filepath, $this->sideloadPath) === 0;
        $fileinfo = $isAbsolutePathInsideDir
            ? new SplFileInfo($filepath)
            : new SplFileInfo($this->sideloadPath . DIRECTORY_SEPARATOR . $filepath);

        $realPath = $fileinfo->getRealPath();
        if (!$realPath) {
            if ($messageStore) {
                $messageStore->addError('file', new PsrMessage(
                    'Cannot sideload file "{filepath}": path invalid or no file.', // @translate
                    ['filepath' => $filepath]
                ));
            }
            return null;
        }

        if (strpos($realPath, $this->sideloadPath) !== 0) {
            if ($messageStore) {
                $messageStore->addError('file', new PsrMessage(
                    'Cannot sideload file "{filepath}": file not inside sideload directory.', // @translate
                    ['filepath' => $filepath]
                ));
            }
            return null;
        }

        if ($isDir) {
            if (!$fileinfo->isDir() || !$fileinfo->isExecutable()) {
                if ($messageStore) {
                    $messageStore->addError('file', new PsrMessage(
                        'Cannot sideload directory "{filepath}": not a directory.', // @translate
                        ['filepath' => $filepath]
                    ));
                }
                return null;
            }
        } elseif (!$fileinfo->isFile()) {
            if ($messageStore) {
                $messageStore->addError('file', new PsrMessage(
                    'Cannot sideload file "{filepath}": not a file.', // @translate
                    ['filepath' => $filepath]
                ));
            }
            return null;
        }

        if (!$fileinfo->isReadable()) {
            if ($messageStore) {
                $messageStore->addError('file', new PsrMessage(
                    'Cannot sideload file "{filepath}": file is not readable.', // @translate
                    ['filepath' => $filepath]
                ));
            }
            return null;
        }

        if ($this->sideloadDeleteFile && !$fileinfo->getPathInfo()->isWritable()) {
            if ($messageStore) {
                $messageStore->addError('file', new PsrMessage(
                    'Cannot sideload file "{filepath}": File Sideload option "delete" is set, but file is not writeable.', // @translate
                    ['filepath' => $filepath]
                ));
            }
            return null;
        }

        return $realPath;
    }

    /**
     * Validate media type and extension if available and if required.
     *
     * Similar to the validator, but without temp file.
     * @see \Omeka\File\Validator
     */
    protected function checkMediaTypeAndExtension(
        string $filepathOrUrl,
        ?string $mediaType = null,
        ?string $extension = null,
        ?ErrorStore $messageStore = null
    ): bool {
        if ($this->disableFileValidation) {
            return true;
        }
        $isValid = true;
        if ($mediaType) {
            if ((!$this->checkAssetMediaType && !in_array($mediaType, $this->allowedMediaTypes))
                || ($this->checkAssetMediaType && !in_array($mediaType, \Omeka\Api\Adapter\AssetAdapter::ALLOWED_MEDIA_TYPES))
            ) {
                $isValid = false;
                if ($messageStore) {
                    $messageStore->addError('file', new PsrMessage(
                        'Error validating "{file}". Cannot store files with the media type "{media_type}".', // @translate
                        ['file' => $filepathOrUrl, 'media_type' => $mediaType]
                    ));
                }
            }
        }
        if ($extension) {
            $extension = strtolower($extension);
            if (!in_array($extension, $this->allowedExtensions)) {
                $isValid = false;
                if ($messageStore) {
                    $messageStore->addError('file', new PsrMessage(
                        'Error validating "{file}". Cannot store files with the resolved extension "{extension}".', // @translate
                        ['file' => $filepathOrUrl, 'extension' => $extension]
                    ));
                }
            }
        }
        $this->checkAssetMediaType = false;
        return $isValid;
    }

    /**
     * Get files uploaded from the form via filepath from the metadata.
     *
     * When multiple files have the same name, the first one is used: individual
     * files first, then each zipped files in the order they were loaded.
     */
    protected function getFileUploaded($sourceFilepath): ?string
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

    /**
     * Fetch, check and save a file for an asset or a media.
     *
     * @todo Create derivative files (thumbnails) with the tempfile factory.
     * @fixme Source name is not used, only filename.
     *
     * @param string $type
     * @param string $sourceName
     * @param string $filename
     * @param string $storageId
     * @param string $extension
     * @param string $fileOrUrl Full url or filepath (relative or absolute).
     * @return array
     *
     * @todo Use \Omeka\File\Downloader
     * @todo Rewrite method to fetch url: the filename and extension may not be known.
     */
    protected function fetchFile($type, $sourceName, $filename, $storageId, $extension, $fileOrUrl)
    {
        // Quick check.
        if (!$this->disableFileValidation
            && $type !== 'asset'
            && !in_array($extension, $this->allowedExtensions)
            && in_array(strtolower($extension), $this->allowedExtensions)
        ) {
            $this->logger->err(
                'In the current version of Omeka, only lower case extensions are managed. You should disable file validation to import files.' // @translate
            );
        }

        // Quick check.
        if (!$this->disableFileValidation
            && $type !== 'asset'
            && !in_array($extension, $this->allowedExtensions)
        ) {
            return [
                'status' => 'error',
                'message' => new PsrMessage(
                    'File {url} ({source}) don’t have an allowed extension: "{extension}".', // @translate
                    ['url' => $fileOrUrl, 'source' => $sourceName, 'extension' => $extension]
                ),
            ];
        }

        $destFile = $storageId . '.' . $extension;
        $destPath = $this->basePath . '/' . $type . '/' . $destFile;

        if ($this->getParam('fake_files')) {
            // TODO Add a temp file for fake files.
            $fakeFile = OMEKA_PATH . '/application/asset/thumbnails/image.png';
            if ($type === 'asset') {
                $this->store->put($fakeFile, 'asset/' . $destFile);
            } else {
                $this->store->put($fakeFile, 'original/' . $destFile);
                $this->store->put($fakeFile, 'large/' . $destFile);
                $this->store->put($fakeFile, 'medium/' . $destFile);
                $this->store->put($fakeFile, 'square/' . $destFile);
            }
            return [
                'status' => 'success',
                'data' => [
                    'fullpath' => $destPath,
                    'media_type' => 'image/png',
                    'sha256' => 'fa4ef17efa4ef17efa4ef17efa4ef17efa4ef17efa4ef17efa4ef17efa4ef17e',
                    'has_thumbnails' => $type === 'asset',
                    'size' => 894,
                    'is_fake_file' => true,
                ],
            ];
        }

        $isUrl = $this->bulk->isUrl($fileOrUrl);

        if ($isUrl) {
            $tempname = @tempnam($this->tempPath, 'omk_bki_');
            // @see https://stackoverflow.com/questions/724391/saving-image-from-php-url
            // Curl is faster than copy or file_get_contents/file_put_contents.
            if (function_exists('curl_init')) {
                $curl = curl_init($fileOrUrl);
                if (!$curl) {
                    return [
                        'status' => 'error',
                        'message' => new PsrMessage(
                            'File {file} invalid: {error}', // @translate
                            ['file' => $fileOrUrl, 'error' => 'Url not available.'] // @translate
                        ),
                    ];
                }
                $fp = fopen($tempname, 'wb');
                curl_setopt_array($curl, [
                    CURLOPT_FILE => $fp,
                    CURLOPT_HEADER => false,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_USERAGENT => 'curl/' . curl_version()['version'],
                ]);
                curl_exec($curl);
                curl_close($curl);
                fclose($fp);
            } else {
                // copy($url, $tempname);
                $result = file_put_contents($tempname, (string) file_get_contents($fileOrUrl), \LOCK_EX);
                if ($result === false) {
                    return [
                        'status' => 'error',
                        'message' => new PsrMessage(
                            'File {file} invalid: {error}', // @translate
                            ['file' => $fileOrUrl, 'error' => 'Url not available.'] // @translate
                        ),
                    ];
                }
            }
        } else {
            $errorStore = new ErrorStore();
            if (!$this->checkFile($fileOrUrl)) {
                return [
                    'status' => 'error',
                    'message' => new PsrMessage(
                        'File {file} invalid: {error}', // @translate
                        ['file' => $fileOrUrl, 'error' => reset($errorStore->getErrors())]
                    ),
                ];
            }
            $tempname = $fileOrUrl;
        }

        $filesize = filesize($tempname);
        if (!$filesize && !$this->allowEmptyFiles) {
            if ($isUrl) {
                unlink($tempname);
            }
            return [
                'status' => 'error',
                'message' => new PsrMessage(
                    'Unable to download file {url}.', // @translate
                    ['url' => $fileOrUrl]
                ),
            ];
        }

        /** @var \Omeka\File\TempFile $tempFile */
        $tempFile = $this->tempFileFactory->build();
        $tempFile->setTempPath($tempname);
        $tempFile->setStorageId($storageId);
        $tempFile->setSourceName($filename);

        $mediaType = $tempFile->getMediaType();

        // Check the media type for security.
        if (!$this->disableFileValidation) {
            if ($type === 'asset') {
                if (!in_array($mediaType, \Omeka\Api\Adapter\AssetAdapter::ALLOWED_MEDIA_TYPES)) {
                    if ($isUrl) {
                        unlink($tempname);
                    }
                    return [
                        'status' => 'error',
                        'message' => new PsrMessage(
                            'Asset {url} is not an image.', // @translate
                            ['url' => $fileOrUrl]
                        ),
                    ];
                }
            } elseif (!in_array($mediaType, $this->allowedMediaTypes)) {
                if ($isUrl) {
                    unlink($tempname);
                }
                return [
                    'status' => 'error',
                    'message' => new PsrMessage(
                        'File {url} is not an allowed file.', // @translate
                        ['url' => $fileOrUrl]
                    ),
                ];
            }
        }

        $tempFile->store($type, $extension, $tempname);
        /*
        $result = rename($tempname, $destPath);
        if (!$result) {
            if ($isUrl) {
                unlink($tempname);
            }
            return [
                'status' => 'error',
                'message' => new PsrMessage(
                    'File {url} cannot be saved.', // @translate
                    ['url' => $url]
                ),
            ];
        }
         */

        $hasThumbnails = $type !== 'asset';
        if ($hasThumbnails) {
            $hasThumbnails = $tempFile->storeThumbnails();
        }

        $extension = $tempFile->getExtension();
        $sha256 = $tempFile->getSha256();

        if ($isUrl) {
            @unlink($tempname);
        }

        return [
            'status' => 'success',
            'data' => [
                'fullpath' => $destPath,
                'extension' => $extension,
                'media_type' => $mediaType,
                'sha256' => $sha256,
                'has_thumbnails' => $hasThumbnails,
                'size' => $filesize,
                'tempFile' => $tempFile,
            ],
        ];
    }
}
