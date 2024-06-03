<?php declare(strict_types=1);

/*
 * Copyright 2017-2023 Daniel Berthereau
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software. You can use, modify and/or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software’s author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user’s attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software’s suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

namespace BulkImport\Mvc\Controller\Plugin;

use finfo;
use Laminas\Form\Form;
use Laminas\Log\Logger;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Common\Stdlib\PsrMessage;
use Omeka\File\Store\StoreInterface;
use Omeka\File\TempFileFactory;
use Omeka\Stdlib\ErrorStore;
use SplFileInfo;

/**
 * Manage all common fonctions to manage files and urls.
 *
 * @todo Move to downloader or temp file factory or media ingester?
 * @todo Factorize with HttpClientTrait.
 */
class BulkFile extends AbstractPlugin
{
    use HttpClientTrait;

    /**
     * @var \BulkImport\Mvc\Controller\Plugin\Bulk
     */
    protected $bulk;

    /**
     * @var \BulkImport\Mvc\Controller\Plugin\BulkFileUploaded
     */
    protected $bulkFileUploaded;

    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * @var \Omeka\File\TempFileFactory
     */
    protected $tempFileFactory;

    /**
     * @var \Omeka\File\Store\StoreInterface
     */
    protected $store;

    /**
     * Required for strict types.
     *
     * @var bool|int
     */
    protected $asAssociative = true;

    /**
     * @var bool
     */
    protected $allowEmptyFiles = false;

    /**
     * @var array
     */
    protected $allowedExtensions = [];

    /**
     * @var array
     */
    protected $allowedExtensionsAssets = [];

    /**
     * @var array
     */
    protected $allowedMediaTypes = [];

    /**
     * @var array
     */
    protected $allowedMediaTypesAssets = [];

    /**
     * @var string
     */
    protected $basePath;

    /**
     * @var bool
     */
    protected $disableFileValidation = false;

    /**
     * @var bool
     */
    protected $fakeFiles = false;

    /**
     * Asset media-types and extensions are checked differently.
     *
     * @var bool
     */
    protected $isAsset = false;

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
     * @var string
     */
    protected $tempDir;

    public function __construct(
        Bulk $bulk,
        BulkFileUploaded $bulkFileUploaded,
        Logger $logger,
        TempFileFactory $tempFileFactory,
        StoreInterface $store,
        string $basePath,
        string $tempDir,
        bool $isFileSideloadActive,
        bool $disableFileValidation,
        array $allowedMediaTypes,
        array $allowedExtensions,
        array $allowedMediaTypesAssets,
        array $allowedExtensionsAssets,
        bool $allowEmptyFiles,
        string $sideloadPath,
        bool $sideloadDeleteFile
    ) {
        $this->bulk = $bulk;
        $this->bulkFileUploaded = $bulkFileUploaded;
        $this->logger = $logger;
        $this->tempFileFactory = $tempFileFactory;
        $this->store = $store;
        $this->basePath = $basePath;
        $this->tempDir = $tempDir;
        $this->isFileSideloadActive = $isFileSideloadActive;
        $this->disableFileValidation = $disableFileValidation;
        $this->allowedMediaTypes = $allowedMediaTypes;
        $this->allowedExtensions = $allowedExtensions;
        $this->allowedMediaTypesAssets = $allowedMediaTypesAssets;
        $this->allowedExtensionsAssets = $allowedExtensionsAssets;
        $this->allowEmptyFiles = $allowEmptyFiles;
        $this->sideloadPath = $sideloadPath;
        $this->sideloadDeleteFile = $sideloadDeleteFile;

        $this->streamContextHeadersOnly = stream_context_create([
            'http' => ['method' => 'HEAD'],
        ]);

        // Required for strict types.
        $this->asAssociative = PHP_MAJOR_VERSION < 8 ? 1 : true;
    }

    public function __invoke(): self
    {
        return $this;
    }

    /**
     * Check if a file or url exists and is readable.
     */
    public function checkFileOrUrl($fileOrUrl, ?ErrorStore $messageStore = null): bool
    {
        $result = $this->bulk->isUrl($fileOrUrl)
            ? $this->checkUrl($fileOrUrl, $messageStore)
            : $this->checkFile($fileOrUrl, $messageStore);
        $this->isAsset = false;
        return $result;
    }

    /**
     * Check if a file exists and is readable.
     *
     * @todo Add an option to check media types for assets.
     */
    public function checkFile($filepath, ?ErrorStore $messageStore = null): bool
    {
        $filepath = (string) $filepath;

        // Check if this is a directly uploaded file. They are already checked.
        $uploadedFile = $this->bulkFileUploaded->getFileUploaded($filepath);
        if ($uploadedFile) {
            $realPath = $uploadedFile;
        } elseif (!$this->isFileSideloadActive) {
            if ($messageStore) {
                $messageStore->addError('file', new PsrMessage(
                    'Cannot sideload file: module FileSideload inactive or not installed.' // @translate
                ));
            }
            $this->isAsset = false;
            return false;
        } else {
            $realPath = $this->verifyFile($filepath, $messageStore);
            if (is_null($realPath)) {
                $this->isAsset = false;
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
            $this->isAsset = false;
            return false;
        }

        // TODO Check max size and remaining server size.

        // In all cases, the media type is checked for aliases.
        $mediaType = $this->getMediaType($realPath);
        $extension = pathinfo($realPath, PATHINFO_EXTENSION);

        // Here, use the source filepath because this is only for message.
        return $this->checkMediaTypeAndExtension($filepath, $mediaType, $extension, $messageStore);
    }

    /**
     * Check if a directory exists, is readable and has files.
     *
     * It cannot be used to check a list of assets (extensions and media-types).
     */
    public function checkDirectory($dirpath, ?ErrorStore $messageStore = null): bool
    {
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
    public function checkUrl($url, ?ErrorStore $messageStore = null): bool
    {
        $url = (string) $url;
        if (!strlen($url)) {
            if ($messageStore) {
                $messageStore->addError('url', new PsrMessage(
                    'Cannot fetch url: empty url.' // @translate
                ));
            }
            $this->isAsset = false;
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
            $this->isAsset = false;
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
                $this->isAsset = false;
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
                        'Cannot fetch url "{url}": server does not respond.', // @translate
                        ['url' => $url]
                    ));
                }
                curl_close($curl);
                $this->isAsset = false;
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
                        'Cannot fetch url "{url}": server returned invalid response.', // @translate
                        ['url' => $url]
                    ));
                }
                $this->isAsset = false;
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
            $this->isAsset = false;
            return false;
        }

        if (!$contentLength) {
            if ($messageStore) {
                $messageStore->addError('file', new PsrMessage(
                    'Cannot fetch url "{url}": Content length empty.', // @translate
                    ['url' => $url]
                ));
            }
            $this->isAsset = false;
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
            // The media type may be an invalid or a full one.
            // Example: https://gallica.bnf.fr/ark:/12148/btv1b11337301n.thumbnail
            // is "image/jpeg;charset=UTF-8" (for BnF, an image is like a text).
            $mediaType = strtok($mediaType, ';');
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
     * @param string $filepath The full and real filepath.
     * @param array $file Data of the file info (original name, type). If data
     * are not present, checks may be skipped.
     * @param string|array $mediaType Media types to check against.
     * @return bool
     */
    public function isValidFilepath($filepath, array $file = [], $mediaType = null, &$message = null): bool
    {
        $file += [
            'name' => $filepath ? basename($filepath) : '[unknown]',
            'type' => null,
        ];
        if (empty($filepath)) {
            $message = new PsrMessage(
                'File "{filename}" doesn’t exist.', // @translate
                ['filename' => $file['name']]
            );
            return false;
        }
        // TODO Add check of path first.
        if (!file_exists($filepath)) {
            $message = new PsrMessage(
                'File "{filename}" does not exist.', // @translate
                ['filename' => $file['name']]
            );
            return false;
        }
        if (!filesize($filepath)) {
            $message = new PsrMessage(
                'File "{filename}" is empty.', // @translate
                ['filename' => $file['name']]
            );
            return false;
        }
        if (!is_readable($filepath)) {
            $message = new PsrMessage(
                'File "{filename}" is not readable.', // @translate
                ['filename' => $file['name']]
            );
            return false;
        }

        if ($mediaType && $file['type']) {
            if (empty($file['type'])) {
                $message = new PsrMessage(
                    'File "{filename}" has no media type.', // @translate
                    ['filename' => $file['name']]
                );
                return false;
            } elseif (is_array($mediaType)) {
                if (!in_array($file['type'], $mediaType)) {
                    $message = new PsrMessage(
                        'File "{filename}" has media type "{file_media_type}" and is not managed.', // @translate
                        ['filename' => $file['name'], 'file_media_type' => $file['type']]
                    );
                    return false;
                }
            } elseif ($file['type'] !== $mediaType) {
                $message = new PsrMessage(
                    'File "{filename}" has media type "{file_media_type}", not "{media_type}".', // @translate
                    ['filename' => $file['name'], 'file_media_type' => $file['type'], 'media_type' => $mediaType]
                );
                return false;
            }
        }

        return true;
    }

    public function isValidUrl(string $url, &$message = null): bool
    {
        if (empty($url)) {
            $message = new PsrMessage(
                'Url is empty.' // @translate
            );
            return false;
        }

        // Remove all illegal characters from a url
        $sanitizedUrl = filter_var($url, FILTER_SANITIZE_URL);
        if ($sanitizedUrl !== $url) {
            $message = new PsrMessage(
                'Url should not contain illegal characters.' // @translate
            );
            return false;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            $message = new PsrMessage(
                'Url is not valid.' // @translate
            );
            return false;
        }

        return true;
    }

    public function setFakeFiles(bool $fakeFiles): self
    {
        $this->fakeFiles = $fakeFiles;
        return $this;
    }

    public function setIsAsset(bool $isAsset): self
    {
        $this->isAsset = $isAsset;
        return $this;
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
     * Get the media type of a local file.
     *
     * @see \Omeka\File\TempFile::getMediaType().
     */
    public function getMediaType(?string $filepath): ?string
    {
        if (!$filepath || !file_exists($filepath) || !is_readable($filepath)) {
            return null;
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mediaType = $finfo->file($filepath);
        $mediaType = \Omeka\File\TempFile::MEDIA_TYPE_ALIASES[$mediaType] ?? $mediaType;
        return $mediaType;
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
            if (($this->isAsset && !in_array($mediaType, $this->allowedMediaTypesAssets))
                || (!$this->isAsset && !in_array($mediaType, $this->allowedMediaTypes))
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
            if (($this->isAsset && !in_array($extension, $this->allowedExtensionsAssets))
                || (!$this->isAsset && !in_array($extension, $this->allowedExtensions))
            ) {
                $isValid = false;
                if ($messageStore) {
                    $messageStore->addError('file', new PsrMessage(
                        'Error validating "{file}". Cannot store files with the resolved extension "{extension}".', // @translate
                        ['file' => $filepathOrUrl, 'extension' => $extension]
                    ));
                }
            }
        }

        // Always reset this option for now.
        $this->isAsset = false;

        return $isValid;
    }

    /**
     * @todo Use the upload mechanism / temp file of Omeka.
     *
     * @param Form $form
     * @throws \Omeka\Service\Exception\RuntimeException
     * @return array The file array with the temp filename.
     */
    public function getUploadedFile(Form $form): ?array
    {
        $file = $form->get('file')->getValue();
        if (empty($file)) {
            return null;
        }

        if (!file_exists($file['tmp_name'])) {
            return null;
        }

        $filename = @tempnam($this->tempDir, 'omk_bki_');
        if (!move_uploaded_file($file['tmp_name'], $filename)) {
            throw new \Omeka\Service\Exception\RuntimeException(
                (string) new PsrMessage(
                    'Unable to move uploaded file to {filename}', // @translate
                    ['filename' => $filename]
                )
            );
        }
        $file['filename'] = $filename;
        return $file;
    }

    /**
     * Fetch, check and save a file for an asset or a media.
     *
     * @todo Create derivative files (thumbnails) with the tempfile factory.
     * @fixme Source name is not used, only filename.
     *
     * @param string $type "asset", else it is a resource.
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
    public function fetchAndStore($type, $sourceName, $filename, $storageId, $extension, $fileOrUrl)
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

        if ($this->fakeFiles) {
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
            $tempname = @tempnam($this->tempDir, 'omk_bki_');
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
                if (!in_array($mediaType, $this->allowedMediaTypesAssets)) {
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

    public function fetchUrlToTempFile(string $url): ?string
    {
        $tempname = @tempnam($this->tempDir, 'omk_bki_');

        // @see https://stackoverflow.com/questions/724391/saving-image-from-php-url
        // Curl is faster than copy or file_get_contents/file_put_contents.
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            if (!$curl) {
                return null;
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
            $result = file_put_contents($tempname, (string) file_get_contents($url), \LOCK_EX);
            if ($result === false) {
                return null;
            }
        }

        if (!filesize($tempname)) {
            unlink($tempname);
            return null;
        }

        return $tempname;
    }
}
