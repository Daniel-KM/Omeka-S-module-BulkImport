<?php declare(strict_types=1);

namespace BulkImport\Reader;

use ArrayIterator;
use BulkImport\Entry\Entry;
use BulkImport\Form\Reader\JsonReaderConfigForm;
use BulkImport\Form\Reader\JsonReaderParamsForm;
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

    /**
     * @var string
     */
    protected $path = '';

    /**
     * @var string
     */
    protected $subpath = '';

    /**
     * @var array
     */
    protected $queryParams = [];

    /**
     * @var int
     */
    protected $baseUrl = '';

    /**
     * @var \Laminas\Http\Response
     */
    protected $currentResponse;

    public function setPath($path): \BulkImport\Reader\Reader
    {
        $this->path = $path;
        return $this;
    }

    public function setSubPath($subpath): \BulkImport\Reader\Reader
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

    /**
     * @return bool
     */
    public function isValid(): bool
    {
        $this->initArgs();

        if (!$this->isValidUrl()) {
            return false;
        }
        // Check the endpoint.

        $check = ['path' => '', 'subpath' => '', 'params' => []];
        try {
            $response = $this->fetch($check['path'], $check['subpath'], $check['params']);
        } catch (\Laminas\Http\Exception\RuntimeException $e) {
            $this->lastErrorMessage = $e->getMessage();
            return false;
        } catch (\Laminas\Http\Client\Exception\RuntimeException $e) {
            $this->lastErrorMessage = $e->getMessage();
            return false;
        }
        if (!$response->isSuccess()) {
            $this->lastErrorMessage = $response->renderStatusLine();
            return false;
        }
        $contentType = $response->getHeaders()->get('Content-Type');
        if ($contentType->getMediaType() !== 'application/json'
            || strtolower($contentType->getCharset()) !== 'utf-8'
        ) {
            $this->lastErrorMessage = new PsrMessage(
                'Content-type "{content_type}" is invalid.', // @translate
                ['content_type' => $contentType->toString()]
            );
            $this->getServiceLocator()->get('Omeka\Logger')->err(
                $this->lastErrorMessage->getMessage(),
                $this->lastErrorMessage->getContext()
            );
            return false;
        }

        return true;
    }

    protected function currentPage(): void
    {
        $this->currentResponse = $this->fetchData($this->path, $this->subpath, array_merge($this->filters, $this->queryParams), $this->currentPage);
        $json = json_decode($this->currentResponse->getBody(), true) ?: [];
        if (!$this->currentResponse->isSuccess()) {
            if ($json && isset($json['errors']['error'])) {
                $this->lastErrorMessage = new PsrMessage(
                    'Unable to fetch data for the page {page}: {error}.', // @translate
                    ['page' => $this->currentPage, 'error' => $json['errors']['error']]
                );
            } else {
                $this->lastErrorMessage = new PsrMessage(
                    'Unable to fetch data for the page {page}.', // @translate
                    ['page' => $this->currentPage]
                );
            }
            $this->getServiceLocator()->get('Omeka\Logger')->err(
                $this->lastErrorMessage->getMessage(),
                $this->lastErrorMessage->getContext()
            );
            $this->currentResponse = null;
            $this->setInnerIterator(new ArrayIterator([]));
            return;
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
        // At least one page.
        $this->lastPage = max((int) ceil($this->totalCount / $this->perPage), 1);
        // The page is 1-based, but the index is 0-based, more common in loops.
        $this->currentPage = 1;
        $this->currentIndex = 0;
        $this->isValid = true;
    }
}
