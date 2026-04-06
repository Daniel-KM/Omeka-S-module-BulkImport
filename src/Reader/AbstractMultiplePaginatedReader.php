<?php declare(strict_types=1);

namespace BulkImport\Reader;

use Common\Stdlib\PsrMessage;
use Iterator;
use Laminas\Form\Form;
use Laminas\Http\Response as HttpResponse;

/**
 * Recursive iterator reading from a list of files or urls with pagination.
 *
 * Each file or url is paginated, so it has at least one page of results.
 * Each page should be preprocessed by the final reader, for example xslt for
 * xml or json_decode() for json. The total results can be computed from the
 * first fetch of file/url, else the reader iterate until there is no more
 * output. The number of results per page can be established from the first
 * fetch or is set to 100 by default. Each result is converted to an entry by
 * the reader and this is the result returned by current().
 *
 * The solution to prepare a single list of all pages is not used.
 *
 * @fixme @todo Remove the temp files, because a file with the same name but a different content may be badly processed. Or use name with sha of the content.
 */
abstract class AbstractMultiplePaginatedReader extends AbstractReader
{
    /**
     * @var array
     */
    protected $listFiles = [];

    /**
     * Local file to process, from uploaded or server file or fetched from url.
     *
     * @var string
     */
    protected $currentFilepath;

    /**
     * @var \Laminas\Http\Response
     */
    protected $currentResponse;

    /**
     * @var int
     */
    protected $currentFileIndex = 0;

    /**
     * @var int
     */
    protected $currentPage = 1;

    /**
     * @var int
     */
    protected $currentIndexInPage = 0;

    /**
     * @var int
     */
    protected $currentIndexInResults = 0;

    /**
     * @var int
     */
    protected $resultsPerPage = self::BATCH_SIZE;

    /**
     * @var int
     */
    protected $currentPageData = [];

    protected $formatLabels = [
        'application/atom+xml' => 'Atom',
        'application/sru+xml' => 'SRU/SRW',
        // This is the format used by the module, not a standard format.
        'application/vnd.omeka-resources+xml' => 'Omeka resources', // @translate
    ];

    public function handleParamsForm(Form $form): self
    {
        $this->lastErrorMessage = null;
        $values = $form->getData();
        $params = array_intersect_key($values, array_flip($this->paramsKeys));

        // TODO Store fetched url between form step.
        if (array_search('url', $this->paramsKeys) !== false) {
            $url = $form->get('url')->getValue();
            $url = trim($url);
            $isUrl = !empty($url);
        }

        if ($isUrl) {
            $filename = $this->bulkFile->fetchUrlToTempFile($url);
            $params['filename'] = $filename;
            unset($params['file']);
            $params['list_files'] = [];
        } else {
            $file = $this->bulkFile->getUploadedFile($form);
            if ($file === null) {
                unset($params['file']);
                $params['list_files'] = $params['list_files']
                    ? array_unique(array_filter(array_map('trim', $params['list_files'])))
                    : [];
            } else {
                $params['filename'] = $file['filename'];
                // Remove temp names for security purpose.
                unset($file['filename']);
                unset($file['tmp_name']);
                $params['file'] = $file;
                $params['list_files'] = [];
            }
        }

        $this->setParams($params);
        $this->appendInternalParams();
        $this->reset();
        return $this;
    }

    public function isValid(): bool
    {
        $this->lastErrorMessage = null;

        // The file may not be uploaded (or return false directly).
        $url = $this->getParam('url');
        $filepath = $this->getParam('filename');

        $url = trim((string) $url);
        if (!empty($url)) {
            $this->listFiles = [$url];
        } elseif ($filepath) {
            $this->listFiles = [$filepath];
            $file = $this->getParam('file') ?: [];
            // Early check for a single uploaded file.
            $this->currentFilepath = $filepath;
            if (!$this->bulkFile->isValidFilepath($filepath, $file, null, $this->lastErrorMessage)) {
                return parent::isValid();
            }
            if (!$this->isValidMore()) {
                return parent::isValid();
            }
        } else {
            $this->listFiles = $this->getParam('list_files') ?: [];
        }

        foreach ($this->listFiles as $fileUrl) {
            if ($this->bulk->isUrl($fileUrl)) {
                if (!$this->bulkFile->isValidUrl($fileUrl, $this->lastErrorMessage)) {
                    return parent::isValid();
                }
                $filename = $this->bulkFile->fetchUrlToTempFile($fileUrl);
                if (!$filename) {
                    $this->lastErrorMessage = new PsrMessage(
                        'Url "{url}" is invalid, empty or unavailable.', // @translate
                        ['url' => $url]
                    );
                    return parent::isValid();
                }
                $this->params['filename'] = $filename;
                if (!$this->bulkFile->isValidFilepath($filename, [], null, $this->lastErrorMessage)) {
                    return parent::isValid();
                }
            } else {
                $this->params['filename'] = $fileUrl;
                if (!$this->bulkFile->isValidFilepath($fileUrl, [], null, $this->lastErrorMessage)) {
                    return parent::isValid();
                }
            }
            $this->currentFilepath = $this->params['filename'];
            if (!$this->isValidMore()) {
                return parent::isValid();
            }
        }

        return parent::isValid();
    }

    /**
     * Allow to process more checks.
     */
    protected function isValidMore(): bool
    {
        return true;
    }

    #[\ReturnTypeWillChange]
    public function current()
    {
        $this->isReady();
        return $this->currentPageData[$this->currentIndexInPage] ?? null;
    }

    #[\ReturnTypeWillChange]
    public function key()
    {
        $this->isReady();
        return sprintf('%d:%d:%d', $this->currentFileIndex, $this->currentPage, $this->currentIndexInPage);
    }

    public function next(): void
    {
        $this->isReady();
        ++$this->currentIndexInPage;
        ++$this->currentIndexInResults;

        // If no more data in current file, try next page.
        if ($this->currentIndexInPage >= count($this->currentPageData)) {
            $this->currentIndexInPage = 0;
            // Now, there is only one page by file anyway.
            // If reenabling multiple pages, check against the number of pages of the current file/url.
            // Or manage pages differently, dividing the same file, in particular for big files.
            /*
            ++$this->currentPage;
            $this->updateCurrentPageData();

            // If no more data in current page, try next file.
            if ($this->currentFileIndex < count($this->listFiles)
                && count($this->currentPageData) === 0
            ) {
            */
            ++$this->currentFileIndex;
            if ($this->currentFileIndex < count($this->listFiles)) {
                $this->currentPage = 1;
                $this->currentIndexInPage = 0;
                $this->updateCurrentPageData();
            } else {
                $this->currentPageData = [];
            }
        }
    }

    public function rewind(): void
    {
        $this->isReady();
        $this->currentFileIndex = 0;
        $this->currentPage = 1;
        $this->currentIndexInPage = 0;
        $this->currentIndexInResults = 0;
        $this->currentPageData = [];
        $this->updateCurrentPageData();
    }

    public function valid(): bool
    {
        $this->isReady();
        return isset($this->listFiles[$this->currentFileIndex])
            && isset($this->currentPageData[$this->currentIndexInPage]);
    }

    public function count(): int
    {
        $this->isReady();
        $total = 0;
        foreach ($this->listFiles as $filePath) {
            $total += $this->countFileResources($filePath);
        }
        return $total;
    }

    /**
     * Reset the iterator to allow to use it with different params.
     *
     * It does not reset the list of files.
     */
    protected function reset(): self
    {
        parent::reset();

        $this->currentFileIndex = 0;
        $this->currentPage = 1;
        $this->currentIndexInPage = 0;
        $this->currentIndexInResults = 0;
        $this->currentPageData = [];

        return $this;
    }

    protected function prepareListFiles(): self
    {
        $errors = [];
        $formats = [];
        $newListFiles = [];
        foreach ($this->listFiles as $file) {
            // TODO Manage pagination of local files, but probably useless since local files contains all data. Or use directory.
            if (filter_var($file, FILTER_VALIDATE_URL)) {
                $localPath = sys_get_temp_dir() . '/omk_bi_' . sha1($file) . '.xml';
                if (file_exists($localPath)) {
                    $content = file_get_contents($localPath);
                } else {
                    $content = $this->fetchUrl($file);
                    if ($content !== false) {
                        file_put_contents($localPath, $content);
                    } else {
                        $errors[] = $file;
                        continue;
                    }
                }
                if (!filesize($localPath) || !strlen($content)) {
                    $errors[] = $file;
                    continue;
                }

                // Since the whole list of files is preprocess and stored, there
                // is no need to store the format.
                // TODO Use the http response headers to get formats? It won't be enough or json.
                $format = $this->getFormatFromFirstResponse($localPath);
                $formats[$this->formatLabels[$format] ?? $format][] = $file;

                // Use final reader to get format, total results and per page.
                $totalResults = $this->countTotalResourcesFromFirstResponse($localPath);
                $resultsPerPage = $this->countPerPageFromFirstResponse($localPath);
                $pages = $resultsPerPage ? (int) ceil($totalResults / $resultsPerPage) : 1;

                // Preprocess the file in place, for example apply xslt to xml.
                $localPath = $this->preprocessFile($localPath);

                $newListFiles[] = $localPath;

                // Download remaining pages.
                for ($page = 2; $page <= $pages; $page++) {
                    $pageUrl = $this->getPaginatedPathOrUrl($format, $file, $page, $resultsPerPage, null);
                    $pageLocalPath = sys_get_temp_dir() . '/omk_bi_' . sha1($pageUrl) . '.xml';
                    if (!file_exists($pageLocalPath)) {
                        $pageContent = $this->fetchUrl($pageUrl);
                        if ($pageContent !== false) {
                            file_put_contents($pageLocalPath, $pageContent);
                        } else {
                            continue;
                        }
                    }
                    $newListFiles[] = $this->preprocessFile($pageLocalPath);
                }
            } else {
                if (file_exists($file) && is_readable($file) && filesize($file)) {
                    $format = $this->getFormatFromFirstResponse($file);
                    $formats[$this->formatLabels[$format] ?? $format][] = $file;
                    $newListFiles[] = $this->preprocessFile($file);
                } else {
                    $errors[] = $file;
                }
            }
        }

        $this->listFiles = $newListFiles;

        /* // TODO Do not repeat messages.
        if ($formats) {
            $this->logger->notice(
                'The reader detected the following formats for the files: {json}.', // @translate
                ['json' => json_encode($formats, 448)]
           );
        }
        */

        if ($errors) {
            $this->logger->err(
                'The reader detected some unavailable or empty files or urls: {list}.', // @translate
                ['list' => implode(', ', $errors)]
            );
        }

        if (!$this->listFiles) {
            $this->lastErrorMessage = 'No valid file or url to process.'; // @translate
        }

        return $this;
    }

    /**
     * @todo is it possible to yield with a random access?
     */
    protected function updateCurrentPageData(): self
    {
        if (!isset($this->listFiles[$this->currentFileIndex])) {
            $this->currentPageData = [];
            return $this;
        }
        $entries = $this->getEntries(
            $this->listFiles[$this->currentFileIndex],
            $this->currentPage
        );
        $this->currentPageData = iterator_to_array($entries);
        return $this;
    }

    /**
     * Preprocess a file to make it a normalized file.
     *
     * The format is unknown here, so the final reader should check it.
     * For example apply xslt to xml,
     * It is done early and it avoids to do it multiple times.
     *
     * @return string The new filepath of the preprocessed file. It may be
     * different from the input filepath.
     */
    abstract protected function preprocessFile(string $filePath): string;

    /**
     * Get the total of resources of a normalized file of resources.
     */
    abstract protected function countFileResources(string $filePath): int;

    /**
     * Get the total of resources from the first response of a file or url.
     *
     * The format is unknown here, so the final reader should check it.
     *
     * @todo Pass Http Response in order to use headers.
     */
    abstract protected function countTotalResourcesFromFirstResponse(string $filePath): int;

    /**
     * Get number of results per page from the first response of a file or url.
     *
     * The format is unknown here, so the final reader should check it.
     *
     * @return int The number of results per page or 0 if unknown.
     *
     * @todo Pass Http Response in order to use headers.
     */
    abstract protected function countPerPageFromFirstResponse(string $filePath): int;

    /**
     * Get format (generally a media type) from first response of a file or url.
     *
     * It should be a precise format, not a generic format like text/xml.
     *
     * @todo Pass Http Response in order to use headers.
     */
    abstract protected function getFormatFromFirstResponse(string $filePath): string;

    /**
     * Get the file path or url with a page for a format and a file path or url.
     */
    abstract protected function getPaginatedPathOrUrl(
        string $format,
        string $baseUrl,
        int $page,
        ?int $perPage = null,
        ?HttpResponse $response = null
    ): string;

    /**
     * Get a list of entries from a file or url as iterator.
     *
     * The file is preprocessed, so the format is the omeka one.
     *
     * @return Iterator The output may be yielded with a loop foreach(). Anyway,
     * it is converted into array for now.
     */
    abstract protected function getEntries(string $filePath, int $page): Iterator;
}
