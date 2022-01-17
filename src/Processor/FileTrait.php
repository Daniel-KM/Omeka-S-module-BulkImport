<?php declare(strict_types=1);

namespace BulkImport\Processor;

use finfo;
use Log\Stdlib\PsrMessage;
use Omeka\Stdlib\ErrorStore;
use SplFileInfo;

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

    protected function initFileTrait()
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $this->disableFileValidation = (bool) $settings->get('disable_file_validation');
        $this->allowedMediaTypes = $settings->get('media_type_whitelist') ?: [];
        $this->allowedExtensions = $settings->get('extension_whitelist') ?: [];
        $this->sideloadPath = (string) $settings->get('file_sideload_directory');
        $this->sideloadDeleteFile = $settings->get('file_sideload_delete_file') === 'yes';
        $this->streamContextHeadersOnly = stream_context_create(['http' => ['method' => 'HEAD']]);
        // Required for strict types.
        if (PHP_MAJOR_VERSION < 8) {
            $this->asAssociative = 1;
        }
    }

    /**
     * Check if a file exists and is readable.
     */
    protected function checkFile($filepath, ?ErrorStore $messageStore = null): bool
    {
        if (!$this->isInitFileTrait) {
            $this->initFileTrait();
        }

        $filepath = (string) $filepath;

        $realPath = $this->verifyFile($filepath, $messageStore);
        if (is_null($realPath)) {
            return false;
        }

        if (!filesize($realPath)) {
            $messageStore->addError('file', new PsrMessage(
                'Cannot sideload file "{filepath}": File empty.', // @translate
                ['filepath' => $filepath]
            ));
            return false;
        }

        // TODO Check max size and remaining server size.

        // In all cases, the media type is checked for aliases.
        // @see \Omeka\File\TempFile::getMediaType().
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mediaType = $finfo->file($realPath);
        $mediaType = \Omeka\File\TempFile::MEDIA_TYPE_ALIASES[$mediaType] ?? $mediaType;
        $extension = pathinfo($realPath, PATHINFO_EXTENSION);

        return $this->checkMediaTypeAndExtension($filepath, $mediaType, $extension, $messageStore);
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
            curl_setopt_array($curl, [
                CURLOPT_HEADER => true,
                CURLOPT_NOBODY => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 10,
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
     * @see \FileSideload\Media\Ingester::verifyFile()
     */
    protected function verifyFile($filepath, ?ErrorStore $messageStore = null): ?string
    {
        if (!$this->sideloadPath || strlen($this->sideloadPath) <= 1) {
            if ($messageStore) {
                $messageStore->addError('file', new PsrMessage(
                    'Cannot sideload file: configuration of module File Sideload not ready.', // @translate
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
                    'Cannot sideload file "{filepath}": filepath invalid or no file.', // @translate
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

        if (!$fileinfo->isFile()) {
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
            if (!in_array($mediaType, $this->allowedMediaTypes)) {
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
        return $isValid;
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
     * @param string $url
     * @return array
     *
     * @todo Use \Omeka\File\Downloader
     */
    protected function fetchUrl($type, $sourceName, $filename, $storageId, $extension, $url)
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
                    ['url' => $url, 'source' => $sourceName, 'extension' => $extension]
                ),
            ];
        }

        $destFile = $type . '/' . $storageId . '.' . $extension;
        $destPath = $this->basePath . '/' . $destFile;

        if ($this->getParam('fake_files')) {
            $fakeFile = OMEKA_PATH . '/application/asset/thumbnails/image.png';
            $this->store->put($fakeFile, 'original/' . $destFile);
            $this->store->put($fakeFile, 'large/' . $destFile);
            $this->store->put($fakeFile, 'medium/' . $destFile);
            $this->store->put($fakeFile, 'square/' . $destFile);
            return [
                'status' => 'success',
                'data' => [
                    'fullpath' => $destPath,
                    'media_type' => 'image/png',
                    'sha256' => 'fa4ef17efa4ef17efa4ef17efa4ef17efa4ef17efa4ef17efa4ef17efa4ef17e',
                    'has_thumbnails' => true,
                    'size' => 894,
                    'is_fake_file' => true,
                ],
            ];
        }

        $tempname = tempnam($this->tempPath, 'omkbulk_');

        // @see https://stackoverflow.com/questions/724391/saving-image-from-php-url
        // Curl is faster than copy or file_get_contents/file_put_contents.
        // $result = copy($url, $tempname);
        // $result = file_put_contents($tempname, file_get_contents($url), \LOCK_EX);
        $ch = curl_init($url);
        $fp = fopen($tempname, 'wb');
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_exec($ch);
        curl_close($ch);
        fclose($fp);

        if (!filesize($tempname)) {
            return [
                'status' => 'error',
                'message' => new PsrMessage(
                    'Unable to download asset {url}.', // @translate
                    ['url' => $url]
                ),
            ];
        }



        // In all cases, the media type is checked for aliases.
        // @see \Omeka\File\TempFile::getMediaType().
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mediaType = $finfo->file($tempname);
        $mediaType = \Omeka\File\TempFile::MEDIA_TYPE_ALIASES[$mediaType] ?? $mediaType;

        // Check the media type for security.
        if (!$this->disableFileValidation) {
            if ($type === 'asset') {
                if (!in_array($mediaType, \Omeka\Api\Adapter\AssetAdapter::ALLOWED_MEDIA_TYPES)) {
                    unlink($tempname);
                    return [
                        'status' => 'error',
                        'message' => new PsrMessage(
                            'Asset {url} is not an image.', // @translate
                            ['url' => $url]
                        ),
                    ];
                }
            } elseif (!in_array($mediaType, $this->allowedMediaTypes)) {
                unlink($tempname);
                return [
                    'status' => 'error',
                    'message' => new PsrMessage(
                        'File {url} is not an allowed file.', // @translate
                        ['url' => $url]
                    ),
                ];
            }
        }

        /** @var \Omeka\File\TempFile $tempFile */
        $tempFile = $this->tempFileFactory->build();
        $tempFile->setTempPath($tempname);
        $tempFile->setStorageId($storageId);
        $tempFile->setSourceName($filename);

        $tempFile->store($type, $extension, $tempname);
        /*
        $result = rename($tempname, $destPath);
        if (!$result) {
            unlink($tempname);
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

        return [
            'status' => 'success',
            'data' => [
                'fullpath' => $destPath,
                'media_type' => $tempFile->getMediaType(),
                'sha256' => $tempFile->getSha256(),
                'has_thumbnails' => $hasThumbnails,
                'size' => $tempFile->getSize(),
            ],
        ];
    }
}
