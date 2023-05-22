<?php declare(strict_types=1);

namespace BulkImport\Reader;

use Laminas\Form\Form;
use Log\Stdlib\PsrMessage;

/**
 * @todo Factorize with FileTrait.
 */
trait FileAndUrlTrait
{
    /**
     * @var string
     */
    protected $mediaType;

    /**
     * @todo Use the upload mechanism / temp file of Omeka.
     *
     * @param Form $form
     * @throws \Omeka\Service\Exception\RuntimeException
     * @return array The file array with the temp filename.
     */
    protected function getUploadedFile(Form $form): ?array
    {
        $file = $form->get('file')->getValue();
        if (empty($file)) {
            return null;
        }

        if (!file_exists($file['tmp_name'])) {
            return null;
        }

        $systemConfig = $this->getServiceLocator()->get('Config');
        $tempDir = $systemConfig['temp_dir'] ?? null;
        if (!$tempDir) {
            throw new \Omeka\Service\Exception\RuntimeException(
                'The "temp_dir" is not configured' // @translate
            );
        }

        $filename = @tempnam($tempDir, 'omk_bki_');
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
     * @todo Merge with FileTrait::fetchUrl().
     */
    protected function fetchUrlToTempFile(string $url): ?string
    {
        $tempPath = $this->getServiceLocator()->get('Config')['temp_dir'] ?: sys_get_temp_dir();

        $tempname = @tempnam($tempPath, 'omk_bki_');

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

    /**
     * @param string $filepath The full and real filepath.
     * @param array $file Data of the file info (original name, type). If data
     * are not present, checks may be skipped.
     * @return bool
     */
    protected function isValidFilepath($filepath, array $file = []): bool
    {
        $file += [
            'name' => $filepath ? basename($filepath) : '[unknown]',
            'type' => null,
        ];

        if (empty($filepath)) {
            $this->lastErrorMessage = new PsrMessage(
                'File "{filename}" doesn’t exist.', // @translate
                ['filename' => $file['name']]
            );
            return false;
        }
        if (!file_exists($filepath)) {
            $this->lastErrorMessage = new PsrMessage(
                'File "{filename}" does not exist.', // @translate
                ['filename' => $file['name']]
            );
            return false;
        }
        if (!filesize($filepath)) {
            $this->lastErrorMessage = new PsrMessage(
                'File "{filename}" is empty.', // @translate
                ['filename' => $file['name']]
            );
            return false;
        }
        if (!is_readable($filepath)) {
            $this->lastErrorMessage = new PsrMessage(
                'File "{filename}" is not readable.', // @translate
                ['filename' => $file['name']]
            );
            return false;
        }

        if (is_array($this->mediaType)) {
            if (!in_array($file['type'], $this->mediaType)) {
                $this->lastErrorMessage = new PsrMessage(
                    'File "{filename}" has media type "{file_media_type}" and is not managed.', // @translate
                    ['filename' => $file['name'], 'file_media_type' => $file['type']]
                );
                return false;
            }
        } elseif ($file['type'] && $file['type'] !== $this->mediaType) {
            $this->lastErrorMessage = new PsrMessage(
                'File "{filename}" has media type "{file_media_type}", not "{media_type}".', // @translate
                ['filename' => $file['name'], 'file_media_type' => $file['type'], 'media_type' => $this->mediaType]
            );
            return false;
        }
        return true;
    }

    protected function isValidUrl(string $url): bool
    {
        if (empty($url)) {
            $this->lastErrorMessage = new PsrMessage(
                'Url is empty.' // @translate
            );
            return false;
        }

        // Remove all illegal characters from a url
        $sanitizedUrl = filter_var($url, FILTER_SANITIZE_URL);
        if ($sanitizedUrl !== $url) {
            $this->lastErrorMessage = new PsrMessage(
                'Url should not contain illegal characters.' // @translate
            );
            return false;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    protected function cleanData(array $data): array
    {
        return array_map([$this, 'trimUnicode'], $data);
    }

    /**
     * Trim all whitespace, included the unicode ones.
     *
     * @param string $string
     * @return string
     */
    protected function trimUnicode($string): string
    {
        return preg_replace('/^[\h\v\s[:blank:][:space:]]+|[\h\v\s[:blank:][:space:]]+$/u', '', (string) $string);
    }

    /**
     * Determine if a uri is a remote url or a local path.
     *
     * @param string $uri
     * @return bool
     */
    protected function isRemote($uri)
    {
        return strpos($uri, 'http://') === 0
            || strpos($uri, 'https://') === 0
            || strpos($uri, 'ftp://') === 0
            || strpos($uri, 'sftp://') === 0;
    }
}
