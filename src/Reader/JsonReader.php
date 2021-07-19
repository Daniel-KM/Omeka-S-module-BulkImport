<?php declare(strict_types=1);

namespace BulkImport\Reader;

use ArrayIterator;
use BulkImport\Entry\Entry;
use BulkImport\Form\Reader\JsonReaderConfigForm;
use BulkImport\Form\Reader\JsonReaderParamsForm;
use Laminas\Http\Client as HttpClient;
use Laminas\Http\ClientStatic as HttpClientStatic;
use Laminas\Http\Request;
use Laminas\Http\Response;
use Log\Stdlib\PsrMessage;

class JsonReader extends AbstractPaginatedReader
{
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
     * @var \Laminas\Http\Client
     */
    protected $httpClient;

    /**
     * @var string
     */
    protected $endpoint = '';

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

    /**
     * This method is mainly used outside.
     *
     * @param HttpClient $httpClient
     * @return self
     */
    public function setHttpClient(HttpClient $httpClient): \BulkImport\Interfaces\Reader
    {
        $this->httpClient = $httpClient;
        return $this;
    }

    /**
     * @return HttpClient
     */
    public function getHttpClient(): \Laminas\Http\Client
    {
        if (!$this->httpClient) {
            $this->httpClient = new \Laminas\Http\Client(null, [
                'timeout' => 30,
            ]);
        }
        return $this->httpClient;
    }

    public function setEndpoint($endpoint): \BulkImport\Interfaces\Reader
    {
        $this->endpoint = $endpoint;
        return $this;
    }

    public function setPath($path): \BulkImport\Interfaces\Reader
    {
        $this->path = $path;
        return $this;
    }

    public function setSubPath($subpath): \BulkImport\Interfaces\Reader
    {
        $this->subpath = $subpath;
        return $this;
    }

    public function setQueryParams(array $queryParams): \BulkImport\Interfaces\Reader
    {
        $this->queryParams = $queryParams;
        return $this;
    }

    public function current()
    {
        $this->isReady();
        $current = $this->getInnerIterator()->current();

        $resource = $current;

        return new Entry($resource, [], [
            'is_formatted' => true,
        ]);
    }

    /**
     * @return bool
     */
    public function isValid(): bool
    {
        $this->initArgs();

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

    protected function initArgs(): void
    {
        // FIXME Manage file, not only endpoint. See XmlImport (but with pagination).
        $this->endpoint = $this->getParam('url');
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
        $this->totalCount = empty($body['totalResults']) ? 0 : (int) $body['totalResults'];
        $this->perPage = self::PAGE_LIMIT;
        $this->firstPage = 1;
        $this->lastPage = (int) ceil($this->totalCount / $this->perPage);

        // The page is 1-based, but the index is 0-based, more common in loops.
        $this->currentPage = 1;
        $this->currentIndex = 0;
        $this->isValid = true;
    }

    /**
     * Inverse of parse_url().
     *
     * @link https://stackoverflow.com/questions/4354904/php-parse-url-reverse-parsed-url/35207936#35207936
     *
     * @param array $parts
     * @return string
     */
    protected function unparseUrl(array $parts): string
    {
        return (isset($parts['scheme']) ? "{$parts['scheme']}:" : '')
            . ((isset($parts['user']) || isset($parts['host'])) ? '//' : '')
            . (isset($parts['user']) ? "{$parts['user']}" : '')
            . (isset($parts['pass']) ? ":{$parts['pass']}" : '')
            . (isset($parts['user']) ? '@' : '')
            . (isset($parts['host']) ? "{$parts['host']}" : '')
            . (isset($parts['port']) ? ":{$parts['port']}" : '')
            . (isset($parts['path']) ? "{$parts['path']}" : '')
            . (isset($parts['query']) ? "?{$parts['query']}" : '')
            . (isset($parts['fragment']) ? "#{$parts['fragment']}" : '');
    }

    /**
     * @return \Laminas\Http\Response
     * @throws \Laminas\Http\Exception\RuntimeException
     * @throws \Laminas\Http\Client\Exception\RuntimeException
     * @return \Laminas\Http\Response
     */
    protected function fetchData($path, $subpath, array $params, $page = 0): Response
    {
        return $this->fetch('/' . $path, strlen($subpath) ? '/' . $subpath : '', $params, $page);
    }

    /**
     * @param string $path To append to the endpoint, for example "-context" to
     * get the api-context in Omeka..
     * @param string $subpath
     * @param array $params
     * @param number $page
     * @return \Laminas\Http\Response
     * @throws \Laminas\Http\Exception\RuntimeException
     * @throws \Laminas\Http\Client\Exception\RuntimeException
     */
    protected function fetch($path, $subpath, array $params, $page = 0): Response
    {
        $uri = $this->endpoint . $path . $subpath;

        return $this->getHttpClient()
            ->resetParameters()
            ->setUri($uri)
            ->setMethod(Request::METHOD_GET)
            ->setParameterGet($params)
            ->send();
    }
}
