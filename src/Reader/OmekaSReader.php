<?php declare(strict_types=1);
namespace BulkImport\Reader;

use ArrayIterator;
use BulkImport\Form\Reader\OmekaSReaderConfigForm;
use BulkImport\Form\Reader\OmekaSReaderParamsForm;
use Laminas\Http\Client as HttpClient;
use Laminas\Http\Request;
use Laminas\Http\Response;
use Log\Stdlib\PsrMessage;

// A full recursive array iterator is useless; it's mainly a paginator. Use yield? AppendGenerator?
// TODO Implement Caching ? ArrayAccess, Seekable, Limit, Filter, OuterIterator…? Or only Reader interface?
class OmekaSReader extends AbstractReader
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
     * @var array
     */
    protected $pageIterator;

    /**
     * @var \ArrayIterator
     */
    protected $innerIterator;

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
     * The per-page may be different from the Omeka one when there is only one
     * page of result.
     *
     * @var int
     */
    protected $perPage = 0;

    /**
     * @var int
     */
    protected $firstPage = 0;

    /**
     * @var int
     */
    protected $lastPage = 0;

    /**
     * @var int
     */
    protected $currentPage = 0;

    /**
     * @var int
     */
    protected $currentIndex = 0;

    /**
     * @var int|null Null means not computed.
     */
    protected $totalCount = null;

    public function setObjectType($objectType)
    {
        $this->objectType = $objectType;
        $this->prepareIterator();
        return $this;
    }

    /**
     * This method is mainly use outside.
     *
     * @param HttpClient $httpClient
     * @return self
     */
    public function setHttpClient(HttpClient $httpClient)
    {
        $this->httpClient = $httpClient;
        return $this;
    }

    /**
     * @return HttpClient
     */
    public function getHttpClient()
    {
        if (!$this->httpClient) {
            $this->httpClient = new \Laminas\Http\Client(null, [
                'timeout' => 30,
            ]);
        }
        return $this->httpClient;
    }

    public function setEndpoint($endpoint)
    {
        $this->endpoint = $endpoint;
        return $this;
    }

    public function setQueryCredentials(array $credentials)
    {
        $this->queryCredentials = $credentials;
        return $this;
    }

    public function setPath($path)
    {
        $this->path = $path;
        return $this;
    }

    public function setSubPath($subpath)
    {
        $this->subpath = $subpath;
        return $this;
    }

    public function setQueryParams(array $queryParams)
    {
        $this->queryParams = $queryParams;
        return $this;
    }

    protected function setInnerIterator($iterator)
    {
        $this->innerIterator = $iterator;
        return $this;
    }

    public function getInnerIterator()
    {
        return $this->innerIterator;
    }

    public function count(): int
    {
        if (is_null($this->totalCount)) {
            if (!$this->firstPage) {
                $this->prepareIterator();
            }
            if ($this->firstPage === $this->lastPage) {
                $this->totalCount = $this->perPage;
            } else {
                // There are two ways to get the count: read all pages or read
                // first and last page. The pages may be cached, if they are not
                // too big.
                // $this->totalCount = iterator_count($this->getInnerIterator());
                $perPage = $this->perPage;
                $response = $this->fetchData($this->path, $this->subpath, $this->queryParams, $this->lastPage);
                $json = json_decode($response->getBody(), true) ?: [];
                $this->totalCount = ($this->lastPage - 1) * $perPage + count($json);
            }
        }
        return $this->totalCount;
    }

    public function rewind(): void
    {
        $this->prepareIterator();
        $this->getInnerIterator()->rewind();
    }

    /**
     * Check if the current position is valid.
     *
     * This meaning of this method is different in some iterator classes.
     *
     * @return bool
     */
    public function valid()
    {
        if (!$this->isValid) {
            return false;
        }
        $valid = $this->getInnerIterator()->valid();
        if ($this->currentPage < $this->lastPage) {
            return true;
        }
        return $valid;
    }

    public function current()
    {
        return $this->getInnerIterator()->current();
    }

    public function key()
    {
        // The inner iterator key cannot be used, because it should be a unique
        // index for all the pages.
        return $this->currentIndex;
    }

    public function next(): void
    {
        $inner = $this->getInnerIterator();
        if ($inner->key() + 1 >= $inner->count()) {
            $this->nextPage();
        } else {
            $inner->next();
        }
        ++$this->currentIndex;
    }

    public function hasNext()
    {
        $inner = $this->getInnerIterator();
        return $inner->key() + 1 >= $inner->count()
            || $this->currentPage < $this->lastPage;
    }

    public function getArrayCopy()
    {
        $array = [];
        foreach ($this as $value) {
            $array[] = $value;
        }
        return $array;
    }

    public function jsonSerialize()
    {
        return $this->getArrayCopy();
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
            return false;
        }
        $url = $this->getServiceLocator()->get('ViewHelperManager')->get('url');
        if ($this->endpoint === $url('api', [], ['force_canonical' => true])) {
            $this->lastErrorMessage = new PsrMessage(
                'It is useless to import Omeka S itself. Check your endpoint.' // @translate
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

    protected function resetIterator(): void
    {
        $this->perPage = 0;
        $this->firstPage = 0;
        $this->lastPage = 0;
        $this->currentPage = 0;
        $this->currentIndex = 0;
        $this->isValid = false;
        $this->totalCount = null;
        $this->path = $this->objectType;
    }

    protected function prepareIterator()
    {
        $this->initArgs();
        $this->resetIterator();

        $response = $this->fetchData($this->path, $this->subpath, $this->queryParams, $this->currentPage);
        if (!$response->isSuccess()) {
            $this->lastErrorMesage = 'Unable to fetch data for the first page.'; // @translate
            return false;
        }
        $json = json_decode($response->getBody(), true) ?: [];
        $this->setInnerIterator(new ArrayIterator($json));

        $this->preparePageIterator($response);
    }

    protected function nextPage()
    {
        if ($this->currentPage >= $this->lastPage) {
            $this->resetIterator();
            return false;
        }

        ++$this->currentPage;

        $response = $this->fetchData($this->path, $this->subpath, $this->queryParams, $this->currentPage);
        if (!$response->isSuccess()) {
            $this->lastErrorMesage = 'Unable to fetch data for the next page.'; // @translate
            return false;
        }
        $json = json_decode($response->getBody(), true) ?: [];
        $this->setInnerIterator(new ArrayIterator($json));
    }

    protected function preparePageIterator(Response $response): void
    {
        $links = $response->getHeaders()->get('Link');
        if (!$links) {
            $this->lastErrorMesage = 'Header Link not found in response.'; // @translate
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
            $this->lastErrorMesage = 'No links in http header.'; // @translate
            return;
        }

        // Get the per page.
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
            $this->lastErrorMesage = 'First page cannot be greater to last page.'; // @translate
            return;
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
    protected function unparseUrl(array $parts)
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
     */
    protected function fetchData($path, $subpath, array $params, $page = 0)
    {
        return $this->fetch('/' . $path, strlen($subpath) ? '/' . $subpath : '', $params, $page);
    }

    /**
     * @return \Laminas\Http\Response
     * @throws \Laminas\Http\Exception\RuntimeException
     * @throws \Laminas\Http\Client\Exception\RuntimeException
     */
    protected function fetch($path, $subpath, array $params, $page = 0)
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
