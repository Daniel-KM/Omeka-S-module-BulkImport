<?php declare(strict_types=1);

namespace BulkImport\Reader;

use ArrayIterator;
use BulkImport\Form\Reader\OmekaSReaderConfigForm;
use BulkImport\Form\Reader\OmekaSReaderParamsForm;
use Laminas\Http\Client as HttpClient;
use Laminas\Http\Request;
use Laminas\Http\Response;
use Log\Stdlib\PsrMessage;

/**
 * A full recursive array iterator is useless; it's mainly a paginator. Use yield? AppendGenerator?
 * @todo Implement Caching ? ArrayAccess, Seekable, Limit, Filter, OuterIterator…? Or only Reader interface?
 * @todo Implement an intermediate (or generic) JsonReader.
 */
class OmekaSReader extends AbstractPaginatedReader
{
    protected $label = 'Omeka S api';
    protected $configFormClass = OmekaSReaderConfigForm::class;
    protected $paramsFormClass = OmekaSReaderParamsForm::class;

    /**
     * var array
     */
    protected $configKeys = [];

    /**
     * var array
     */
    protected $paramsKeys = [
        'endpoint' ,
        'key_identity',
        'key_credential',
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
     * @var array
     */
    protected $queryCredentials;

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

    public function setObjectType($objectType): \BulkImport\Interfaces\Reader
    {
        $this->path = $objectType;
        return parent::setObjectType($objectType);
    }

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

    public function setQueryCredentials(array $credentials): \BulkImport\Interfaces\Reader
    {
        $this->queryCredentials = $credentials;
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

    /**
     * @return bool
     */
    public function isValid(): bool
    {
        $this->initArgs();

        // Check the endpoint.
        $check = ['path' => '-context', 'subpath' => '', 'params' => []];
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
        $url = $this->getServiceLocator()->get('ViewHelperManager')->get('url');
        if ($this->endpoint === $url('api', [], ['force_canonical' => true])) {
            $this->lastErrorMessage = new PsrMessage(
                'It is useless to import Omeka S itself. Check your endpoint.' // @translate
            );
            $this->getServiceLocator()->get('Omeka\Logger')->warn(
                $this->lastErrorMessage->getMessage(),
                $this->lastErrorMessage->getContext()
            );
        }
        return true;
    }

    protected function initArgs(): void
    {
        $this->endpoint = $this->getParam('endpoint');
        $this->queryCredentials = [];
        $keyIdentity = $this->getParam('key_identity');
        $keyCredential = $this->getParam('key_credential');
        if ($keyIdentity && $keyCredential) {
            $this->queryCredentials['key_identity'] = $keyIdentity;
            $this->queryCredentials['key_credential'] = $keyCredential;
        }
    }

    protected function currentPage(): void
    {
        $this->currentResponse = $this->fetchData($this->path, $this->subpath, array_merge($this->query, $this->queryParams), $this->currentPage);
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

        $links = $this->currentResponse->getHeaders()->get('Link');
        if (!$links) {
            $this->lastErrorMessage = 'Header Link not found in response.'; // @translate
            $this->getServiceLocator()->get('Omeka\Logger')->warn($this->lastErrorMessage);
            return;
        }

        $links = array_filter(array_map(function ($v) {
            $matches = [];
            if (preg_match('~<(?<url>[^>]+)>; rel="(?<rel>first|prev|next|last)"$~', trim($v), $matches)) {
                return [
                    'direction' => $matches['rel'],
                    'url' => $matches['url'],
                ];
            }
            return null;
        }, explode(',', $links->toString())));
        $urls = [];
        foreach ($links as $link) {
            $urls[$link['direction']] = $link['url'];
        }
        $links = $urls + ['first' => null, 'prev' => null, 'next' => null, 'last' => null];
        if (!$links['first']) {
            $this->lastErrorMessage = 'No links in http header.'; // @translate
            $this->getServiceLocator()->get('Omeka\Logger')->warn($this->lastErrorMessage);
            return;
        }

        // Get the per page. May be small when there is only one page.
        $this->perPage = $this->getInnerIterator()->count();

        // The pages start at 1.
        $query = [];
        $parts = parse_url($links['first']);
        parse_str(parse_url($links['first'], PHP_URL_QUERY), $query);
        $this->firstPage = empty($query['page']) ? 1 : (int) $query['page'];

        // Get the last page.
        if ($links['last']) {
            $queryLast = [];
            parse_str(parse_url($links['last'], PHP_URL_QUERY), $queryLast);
            $this->lastPage = empty($queryLast['page']) ? 1 : (int) $queryLast['page'];
        } else {
            $this->lastPage = 1;
        }

        if ($this->firstPage > $this->lastPage) {
            $this->lastErrorMessage = 'First page cannot be greater to last page.'; // @translate
            $this->getServiceLocator()->get('Omeka\Logger')->err($this->lastErrorMessage);
            return;
        }

        if ($this->firstPage === $this->lastPage) {
            $this->totalCount = $this->perPage;
        } else {
            // Omeka 3.0 contains the total results in the headers.
            $this->totalCount = $this->currentResponse->getHeaders()->get('Omeka-S-Total-Results');
            if ($this->totalCount) {
                $this->totalCount = (int) $this->totalCount->getFieldValue();
            } else {
                // There are two ways to get the count: read all pages or read
                // first and last page. The pages may be cached, if they are not
                // too big.
                // $this->totalCount = iterator_count($this->getInnerIterator());
                $response = $this->fetchData($this->path, $this->subpath, array_merge($this->query, $this->queryParams), $this->lastPage);
                $json = json_decode($response->getBody(), true) ?: [];
                $this->totalCount = ($this->lastPage - 1) * $this->perPage + count($json);
            }
        }

        // Prepare the base url.
        unset($query['page']);
        $this->baseUrl = $this->unparseUrl($parts);

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
        $args = array_merge(
            $params,
            $this->queryCredentials
        );
        if ($page) {
            $args['page'] = $page;
        }
        return $this->getHttpClient()
            ->resetParameters()
            ->setUri($uri)
            ->setMethod(Request::METHOD_GET)
            ->setParameterGet($args)
            ->send();
    }
}
