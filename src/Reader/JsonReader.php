<?php declare(strict_types=1);

namespace BulkImport\Reader;

use ArrayIterator;
use BulkImport\Entry\Entry;
use BulkImport\Form\Reader\JsonReaderConfigForm;
use BulkImport\Form\Reader\JsonReaderParamsForm;
use Laminas\Http\Response;
use Log\Stdlib\PsrMessage;

class JsonReader extends AbstractPaginatedReader
{
    use HttpClientTrait;

    protected $label = 'Json';
    protected $configFormClass = JsonReaderConfigForm::class;
    protected $paramsFormClass = JsonReaderParamsForm::class;

    protected $configKeys = [
        'mapping_file',
    ];

    protected $paramsKeys = [
        'mapping_file',
        'filename',
        'url',
    ];

    protected $contentType = 'application/json';

    protected $charset = 'utf-8';

    /**
     * @var ?string
     */
    protected $path = null;

    /**
     * @var ?string
     */
    protected $subpath = null;

    /**
     * @var array
     */
    protected $queryParams = [];

    /**
     * @var int
     */
    protected $baseUrl = '';

    /**
     * @var array
     */
    protected $importParams = [];

    /**
     * @var \Laminas\Http\Response
     */
    protected $currentResponse;

    // TODO Remove path and sub-path, mainly used for Omeka?

    public function setPath(?string $path): \BulkImport\Reader\Reader
    {
        $this->path = $path;
        return $this;
    }

    public function setSubPath(?string $subpath): \BulkImport\Reader\Reader
    {
        $this->subpath = $subpath;
        return $this;
    }

    public function setQueryParams(array $queryParams): \BulkImport\Reader\Reader
    {
        $this->queryParams = $queryParams;
        return $this;
    }

    public function current()
    {
        $this->isReady();
        $current = $this->getInnerIterator()->current();

        $resource = $current;

        return new BaseEntry($resource, [], [
            'is_formatted' => true,
        ]);
    }

    public function isValid(): bool
    {
        $this->initArgs();

        if (empty($this->params['url'])) {
            if (!$this->isValidUrl('', '', [], $this->contentType, $this->charset)) {
                return false;
            }
        } else {
            if (!$this->isValidDirectUrl($this->params['url'])) {
                return false;
            }
        }

        return true;
    }

    protected function currentPage(): void
    {
        $this->currentResponse = $this->fetchData($this->path, $this->subpath, array_merge($this->filters, $this->queryParams), $this->currentPage);

        // Sometime, the url returns a html page in case of error, but this is
        // not an error page…
        $currentContentType = $this->currentResponse->getHeaders()->get('Content-Type');
        $currentMediaType = $currentContentType->getMediaType();
        $currentCharset = (string) $currentContentType->getCharset();
        if ($currentMediaType !== 'application/json'
            // Some servers don't send charset (see sub-queries for Content-DM).
            || ($currentCharset && strtolower($currentCharset) !== 'utf-8')
        ) {
            $this->lastErrorMessage = new PsrMessage(
                'Content-type "{content_type}" or charset "{charset}" is invalid for url {"url"}.', // @translate
                ['content_type' => $currentContentType->getMediaType(), 'charset' => $currentContentType->getCharset(), 'url' => $this->lastRequestUrl]
            );
            $this->getServiceLocator()->get('Omeka\Logger')->err(
                $this->lastErrorMessage->getMessage(),
                $this->lastErrorMessage->getContext()
            );
            $this->currentResponse = null;
            $this->setInnerIterator(new ArrayIterator([]));
            return;
        }

        if (!$this->currentResponse->isSuccess()) {
            $this->lastErrorMessage = new PsrMessage(
                'Unable to fetch data for the page {page}, url "{url}".', // @translate
                ['page' => $this->currentPage, 'url' => $this->lastRequestUrl]
            );
            $this->getServiceLocator()->get('Omeka\Logger')->err(
                $this->lastErrorMessage->getMessage(),
                $this->lastErrorMessage->getContext()
            );
            $this->currentResponse = null;
            $this->setInnerIterator(new ArrayIterator([]));
            return;
        }

        // This is the check for Omeka S. To be moved.
        $json = json_decode($this->currentResponse->getBody(), true) ?: [];
        if ($json && isset($json['errors']['error'])) {
            $this->lastErrorMessage = new PsrMessage(
                'Unable to fetch data for the page {page} (url "{url}"): {error}.', // @translate
                ['page' => $this->currentPage, 'url' => $this->lastRequestUrl, 'error' => $json['errors']['error']]
            );
            $this->getServiceLocator()->get('Omeka\Logger')->err(
                $this->lastErrorMessage->getMessage(),
                $this->lastErrorMessage->getContext()
            );
            $this->currentResponse = null;
            $this->setInnerIterator(new ArrayIterator([]));
            return;
        }

        if (!is_array($json)) {
            $json = [];
        }
        $this->setInnerIterator(new ArrayIterator($json));
    }

    protected function preparePageIterator(): void
    {
        $this->currentPage();
        if (is_null($this->currentResponse)) {
            return;
        }

        $this->baseUrl = $this->endpoint;

        $body = json_decode($this->currentResponse->getBody(), true) ?: [];

        $this->perPage = self::PAGE_LIMIT;
        $this->totalCount = empty($body['totalResults']) ? 0 : (int) $body['totalResults'];
        $this->firstPage = 1;
        // At least the first page.
        $this->lastPage = max((int) ceil($this->totalCount / $this->perPage), 1);
        // The page is 1-based, but the index is 0-based, more common in loops.
        $this->currentPage = 1;
        $this->currentIndex = 0;
        $this->isValid = true;
    }
}
